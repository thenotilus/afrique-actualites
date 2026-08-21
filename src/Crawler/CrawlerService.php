<?php

namespace App\Crawler;

use App\Crawler\Entity\CrawlAttempt;
use App\Crawler\Exception\CrawlRateLimitedException;
use App\Crawler\ValueObject\BotProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrateur du crawling de repli (§9.4) : essaie successivement les profils du pool jusqu'à
 * obtenir des métadonnées exploitables, ou épuisement de celui-ci. N'intervient qu'en complément
 * du flux RSS — c'est à l'appelant (`CrawlArticleMetaMessageHandler`) de ne déclencher un crawl
 * que si le flux n'a pas déjà fourni titre/image/description ("déclenchement conditionnel", §9.4).
 */
final class CrawlerService
{
    public function __construct(
        private readonly BotProfileRegistry $botProfileRegistry,
        private readonly RobotsTxtChecker $robotsTxtChecker,
        private readonly RateLimiterFactory $crawlerPerDomainLimiter,
        private readonly HttpClientInterface $httpClient,
        private readonly MetaExtractorInterface $metaExtractor,
        private readonly CacheItemPoolInterface $crawlerCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtlSeconds,
    ) {
    }

    /**
     * @throws CrawlRateLimitedException si le quota de requêtes du domaine est épuisé : aucune
     *                                   requête HTTP n'est tentée, à charge de l'appelant de
     *                                   reprogrammer le crawl plutôt que d'attendre en place
     */
    public function crawl(string $url): CrawlResult
    {
        $cacheItem = $this->crawlerCache->getItem($this->cacheKey($url));
        if ($cacheItem->isHit()) {
            /** @var CrawlResult $cached */
            $cached = $cacheItem->get();

            return $cached;
        }

        $domain = $this->extractDomain($url);
        $attempts = [];

        foreach ($this->botProfileRegistry->all() as $botProfile) {
            if (!$this->robotsTxtChecker->isAllowed($url, $botProfile->userAgent)) {
                continue;
            }

            $rateLimit = $this->crawlerPerDomainLimiter->create($domain)->consume(1);
            if (!$rateLimit->isAccepted()) {
                $this->flushAttempts($attempts);
                throw new CrawlRateLimitedException($domain, $rateLimit->getRetryAfter());
            }

            [$statusCode, $content] = $this->fetch($url, $botProfile);
            $success = null !== $statusCode && $statusCode >= 200 && $statusCode < 300 && null !== $content;
            $meta = $success ? $this->metaExtractor->extract($content, $url) : null;
            $success = $success && !$meta->isEmpty();

            $attempts[] = new CrawlAttempt($domain, $url, $botProfile->name, $success, $statusCode);

            if ($success) {
                $this->flushAttempts($attempts);

                $result = CrawlResult::success($meta, $botProfile->name);
                $this->cacheResult($cacheItem, $result);

                return $result;
            }
        }

        $this->flushAttempts($attempts);

        return CrawlResult::failure();
    }

    /** @return array{0: ?int, 1: ?string} [code HTTP, contenu] */
    private function fetch(string $url, BotProfile $botProfile): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [...$botProfile->additionalHeaders, 'User-Agent' => $botProfile->userAgent],
                'timeout' => $botProfile->timeoutSeconds,
            ]);
            $statusCode = $response->getStatusCode();

            return [$statusCode, $statusCode < 400 ? $response->getContent(false) : null];
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('Échec de crawl.', ['url' => $url, 'bot' => $botProfile->name, 'exception' => $e->getMessage()]);

            return [null, null];
        }
    }

    /** @param list<CrawlAttempt> $attempts */
    private function flushAttempts(array $attempts): void
    {
        if ([] === $attempts) {
            return;
        }

        foreach ($attempts as $attempt) {
            $this->entityManager->persist($attempt);
        }
        $this->entityManager->flush();
    }

    private function cacheResult(CacheItemInterface $cacheItem, CrawlResult $result): void
    {
        $cacheItem->set($result)->expiresAfter($this->cacheTtlSeconds);
        $this->crawlerCache->save($cacheItem);
    }

    private function cacheKey(string $url): string
    {
        return 'crawler_result_'.hash('sha256', $url);
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : 'unknown';
    }
}

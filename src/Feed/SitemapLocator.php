<?php

namespace App\Feed;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Découverte des sitemaps d'un site source, pour le backfill historique (§6bis,
 * {@see FeedBackfiller}). Un `Feed` ne porte que l'URL de son flux RSS/Atom, pas celle d'un
 * sitemap : il faut la déduire du site.
 *
 * Deux stratégies, dans l'ordre (la première qui aboutit l'emporte, on ne les cumule pas — un
 * `robots.txt` qui déclare explicitement ses sitemaps fait autorité) :
 * 1. les directives `Sitemap:` de `/robots.txt` (norme de fait, la plus fiable) ;
 * 2. à défaut, une poignée d'emplacements conventionnels (`/sitemap.xml`, etc.) — de simples
 *    candidats, pas une garantie : c'est à l'appelant de tolérer un 404 sur chacun.
 */
final class SitemapLocator
{
    /** @var list<string> */
    private const DEFAULT_PATHS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/news-sitemap.xml',
        '/sitemap-news.xml',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
        private readonly float $requestTimeout,
    ) {
    }

    /**
     * @param string $siteBaseUrl racine du site (schéma + hôte, sans chemin), ex. `https://exemple.com`
     *
     * @return list<string> URLs de sitemaps candidates, sans doublon — non garanties valides
     */
    public function locate(string $siteBaseUrl): array
    {
        $base = rtrim($siteBaseUrl, '/');

        $fromRobots = $this->fromRobotsTxt($base);
        if ([] !== $fromRobots) {
            return $fromRobots;
        }

        return array_map(static fn (string $path): string => $base.$path, self::DEFAULT_PATHS);
    }

    /** @return list<string> */
    private function fromRobotsTxt(string $base): array
    {
        try {
            $response = $this->httpClient->request('GET', $base.'/robots.txt', [
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => $this->requestTimeout,
            ]);
            if ($response->getStatusCode() >= 300) {
                return [];
            }
            $body = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('robots.txt injoignable pour la découverte de sitemap.', ['site' => $base, 'exception' => $e->getMessage()]);

            return [];
        }

        $sitemaps = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^sitemap:\s*(\S+)/i', trim($line), $matches)) {
                $sitemaps[] = $matches[1];
            }
        }

        return array_values(array_unique($sitemaps));
    }
}

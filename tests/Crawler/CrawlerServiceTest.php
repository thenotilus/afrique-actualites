<?php

namespace App\Tests\Crawler;

use App\Crawler\BotProfileRegistry;
use App\Crawler\CrawlerService;
use App\Crawler\Entity\CrawlAttempt;
use App\Crawler\Exception\CrawlRateLimitedException;
use App\Crawler\OpenGraphMetaExtractor;
use App\Crawler\RobotsTxtChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Test d'intégration de l'orchestrateur de crawling (§9.4, §11.2 phase 5) : repli en cascade entre
 * profils de bots, respect de `robots.txt`, mise en cache par URL, et abandon (sans requête HTTP)
 * lorsque le quota par domaine est épuisé plutôt que de bloquer le worker.
 */
final class CrawlerServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testCascadesToTheNextBotProfileAfterAnHttpFailure(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            // Seul le user-agent distingue les profils : l'URL de la page crawlée est identique
            // à chaque tentative (§9.4, "repli en cascade entre profils de bots"). Les en-têtes
            // normalisés par le client HTTP sont une liste de chaînes "Nom: valeur", pas un
            // tableau associatif.
            $headers = implode(' ', $options['headers'] ?? []);

            return str_contains($headers, 'bot-1')
                ? new MockResponse('', ['http_code' => 403])
                : new MockResponse('<html><head><title>Article</title></head></html>');
        });

        $service = $this->makeService($httpClient, limit: 10);

        $result = $service->crawl('https://exemple.com/article');

        self::assertTrue($result->success);
        self::assertSame('bot-2', $result->botProfileName);
        self::assertSame('Article', $result->meta->title);

        $attempts = $this->entityManager->getRepository(CrawlAttempt::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $attempts);
        self::assertFalse($attempts[0]->isSuccess());
        self::assertSame(403, $attempts[0]->getHttpStatusCode());
        self::assertTrue($attempts[1]->isSuccess());
    }

    public function testReturnsFailureWhenAllProfilesAreExhausted(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse('', ['http_code' => 500]);
        });

        $service = $this->makeService($httpClient, limit: 10);

        $result = $service->crawl('https://exemple.com/article');

        self::assertFalse($result->success);
        self::assertNull($result->meta);
        self::assertCount(2, $this->entityManager->getRepository(CrawlAttempt::class)->findAll());
    }

    public function testReturnsFailureWhenThePageHasNoUsableMetadata(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse('<html><head></head><body>Rien d\'exploitable</body></html>');
        });

        $service = $this->makeService($httpClient, limit: 10);

        $result = $service->crawl('https://exemple.com/article');

        self::assertFalse($result->success);
        foreach ($this->entityManager->getRepository(CrawlAttempt::class)->findAll() as $attempt) {
            self::assertFalse($attempt->isSuccess());
        }
    }

    public function testRobotsTxtDisallowSkipsAllProfilesWithoutConsumingTheRateLimit(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse("User-agent: *\nDisallow: /\n");
            }

            self::fail('Aucune requête ne devrait être envoyée à une page interdite par robots.txt.');
        });

        // Quota nul : si le moindre appel HTTP était tenté (donc le moindre jeton consommé),
        // il déclencherait une CrawlRateLimitedException plutôt qu'un simple échec.
        $service = $this->makeService($httpClient, limit: 0);

        $result = $service->crawl('https://exemple.com/article');

        self::assertFalse($result->success);
        self::assertCount(0, $this->entityManager->getRepository(CrawlAttempt::class)->findAll());
    }

    public function testCachesASuccessfulResultByUrl(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse('<html><head><title>Article</title></head></html>');
        });

        $service = $this->makeService($httpClient, limit: 10);

        $first = $service->crawl('https://exemple.com/article');
        $requestsAfterFirstCrawl = $httpClient->getRequestsCount();
        $second = $service->crawl('https://exemple.com/article');

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame($requestsAfterFirstCrawl, $httpClient->getRequestsCount());
        self::assertCount(1, $this->entityManager->getRepository(CrawlAttempt::class)->findAll());
    }

    public function testThrowsWhenTheDomainRateLimitIsExhausted(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse('<html><head><title>Article</title></head></html>');
        });

        // Un seul profil de bot pour ne consommer qu'un jeton par crawl, et un quota d'un seul
        // jeton : le deuxième article du même domaine doit être rejeté sans requête HTTP.
        $service = $this->makeService($httpClient, limit: 1, profileCount: 1);

        $service->crawl('https://exemple.com/article-1');

        $this->expectException(CrawlRateLimitedException::class);
        $service->crawl('https://exemple.com/article-2');
    }

    private function makeService(MockHttpClient $httpClient, int $limit, int $profileCount = 2): CrawlerService
    {
        $profiles = [];
        for ($i = 1; $i <= $profileCount; ++$i) {
            $profiles[] = ['name' => 'bot-'.$i, 'user_agent' => \sprintf('AfriqueActualitesBot/1.0 (bot-%d; +https://afrique-actualites.com/bot)', $i)];
        }

        return new CrawlerService(
            new BotProfileRegistry($profiles),
            new RobotsTxtChecker($httpClient, new NullLogger()),
            new RateLimiterFactory(
                ['id' => 'test_crawler_'.spl_object_id($httpClient), 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
            $httpClient,
            new OpenGraphMetaExtractor(),
            new ArrayAdapter(),
            $this->entityManager,
            new NullLogger(),
            cacheTtlSeconds: 3600,
        );
    }
}

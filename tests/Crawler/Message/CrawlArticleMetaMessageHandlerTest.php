<?php

namespace App\Tests\Crawler\Message;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Crawler\BotProfileRegistry;
use App\Crawler\CrawlerService;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Crawler\Message\CrawlArticleMetaMessageHandler;
use App\Crawler\OpenGraphMetaExtractor;
use App\Crawler\RobotsTxtChecker;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Consommateur du crawl de repli (§9.4) : ne complète que les métadonnées manquantes, sans
 * jamais écraser ce que le flux RSS a déjà fourni, reprogramme le message (sans bloquer le
 * worker) lorsque le quota de crawl du domaine est épuisé, et traite les messages par lots
 * (`BatchHandlerInterface`, cf. docblock de la classe) plutôt qu'un par un.
 *
 * Les trois premiers tests appellent le handler sans `Acknowledger` (`$ack = null`) : ce chemin de
 * `BatchHandlerTrait::handle()` traite le message immédiatement, en lot d'un seul — le comportement
 * observable reste donc celui d'avant le passage au traitement par lots, quelle que soit
 * `batchSize`. {@see testAccumulatesMessagesAndProcessesThemTogetherOnceTheBatchIsFull} exerce le
 * vrai regroupement, avec de véritables `Acknowledger`.
 */
final class CrawlArticleMetaMessageHandlerTest extends KernelTestCase
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

    public function testDoesNothingWhenMetadataIsAlreadyComplete(): void
    {
        $article = $this->makeArticle();
        $article->setDescription('Déjà fournie par le flux');
        $article->setImage('https://exemple.com/deja.jpg');
        $this->entityManager->flush();

        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('Aucune requête ne devrait être envoyée : les métadonnées sont déjà complètes.');
        });
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->makeHandler($httpClient, limit: 10, messageBus: $messageBus);
        $handler(new CrawlArticleMetaMessage($article->getId()));

        self::assertSame('Déjà fournie par le flux', $article->getDescription());
    }

    public function testFillsOnlyTheMissingFieldsWithoutOverwritingRssValues(): void
    {
        $article = $this->makeArticle(); // titre déjà fourni par le constructeur ; description/image absentes
        $this->entityManager->flush();
        $originalTitle = $article->getTitle();

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse(
                '<html><head>'
                .'<meta property="og:title" content="Titre issu du crawl (ne doit pas remplacer le titre RSS)">'
                .'<meta property="og:description" content="Description issue du crawl">'
                .'<meta property="og:image" content="https://exemple.com/une.jpg">'
                .'</head></html>',
            );
        });
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->makeHandler($httpClient, limit: 10, messageBus: $messageBus);
        $handler(new CrawlArticleMetaMessage($article->getId()));

        self::assertSame($originalTitle, $article->getTitle());
        self::assertSame('Description issue du crawl', $article->getDescription());
        self::assertSame('https://exemple.com/une.jpg', $article->getImage());
        // Le crawl a complété titre + description + image : l'article est désormais publié par défaut.
        self::assertTrue($article->isPublish(), 'Un article complété par le crawl est publié par défaut.');
    }

    public function testReschedulesInsteadOfBlockingWhenTheDomainRateLimitIsExhausted(): void
    {
        $article = $this->makeArticle();
        $this->entityManager->flush();
        $articleId = $article->getId();

        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => str_ends_with($url, '/robots.txt')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse('<html><head><title>Ne devrait jamais être lu</title></head></html>'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CrawlArticleMetaMessage $m) => $articleId === $m->articleId))
            ->willReturnCallback(static function (CrawlArticleMetaMessage $message, array $stamps = []) {
                self::assertNotEmpty(array_filter($stamps, static fn ($s) => $s instanceof DelayStamp));

                return new Envelope($message, $stamps);
            });

        // Quota d'un seul jeton, déjà consommé avant l'appel du handler : la tentative de
        // requête (donc la consommation de jeton suivante) est rejetée immédiatement, sans
        // jamais lire la page factice ci-dessus.
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test_handler_exhausted', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
        $limiterFactory->create('exemple.com')->consume(1);

        $handler = $this->makeHandler($httpClient, limit: 1, messageBus: $messageBus, limiterFactory: $limiterFactory);
        $handler(new CrawlArticleMetaMessage($articleId));

        self::assertSame('', $article->getDescription());
        self::assertNull($article->getImage());
    }

    public function testAccumulatesMessagesAndProcessesThemTogetherOnceTheBatchIsFull(): void
    {
        $feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $first = new Article('Premier', 'https://exemple.com/premier', $feed, new \DateTimeImmutable());
        $second = new Article('Second', 'https://exemple.com/second', $feed, new \DateTimeImmutable());
        $this->entityManager->persist($feed);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();
        $firstId = $first->getId();
        $secondId = $second->getId();

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse(
                '<html><head>'
                .'<meta property="og:description" content="Description issue du crawl">'
                .'<meta property="og:image" content="https://exemple.com/une.jpg">'
                .'</head></html>',
            );
        });
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->makeHandler($httpClient, limit: 10, messageBus: $messageBus, batchSize: 2);

        $firstAck = new Acknowledger(CrawlArticleMetaMessageHandler::class);
        $handler(new CrawlArticleMetaMessage($firstId), $firstAck);

        // Un seul message accumulé sur un lot de 2 : pas encore flushé, l'article reste inchangé.
        self::assertFalse($firstAck->isAcknowledged(), 'Le premier message ne doit pas être traité avant que le lot soit complet.');
        self::assertSame('', $first->getDescription());

        $secondAck = new Acknowledger(CrawlArticleMetaMessageHandler::class);
        $handler(new CrawlArticleMetaMessage($secondId), $secondAck);

        // Le lot est complet (2/2) : les deux messages sont traités et flushés ensemble.
        self::assertTrue($firstAck->isAcknowledged(), 'Le lot complet doit acquitter tous les messages accumulés, pas seulement le dernier.');
        self::assertTrue($secondAck->isAcknowledged());
        self::assertSame('Description issue du crawl', $first->getDescription());
        self::assertSame('Description issue du crawl', $second->getDescription());
        self::assertTrue($first->isPublish());
        self::assertTrue($second->isPublish());
    }

    private function makeArticle(string $url = 'https://exemple.com/article'): Article
    {
        $feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $article = new Article('Titre fourni par le flux RSS', $url, $feed, new \DateTimeImmutable());
        $this->entityManager->persist($feed);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    private function makeHandler(
        MockHttpClient $httpClient,
        int $limit,
        MessageBusInterface $messageBus,
        ?RateLimiterFactory $limiterFactory = null,
        int $batchSize = 10,
    ): CrawlArticleMetaMessageHandler {
        $crawlerService = new CrawlerService(
            new BotProfileRegistry([['name' => 'bot-1', 'user_agent' => 'AfriqueActualitesBot/1.0 (+https://afrique-actualites.com/bot)']]),
            new RobotsTxtChecker($httpClient, new NullLogger()),
            $limiterFactory ?? new RateLimiterFactory(
                ['id' => 'test_handler_'.spl_object_id($httpClient), 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
            $httpClient,
            new OpenGraphMetaExtractor(),
            new ArrayAdapter(),
            $this->entityManager,
            new NullLogger(),
            cacheTtlSeconds: 3600,
        );

        return new CrawlArticleMetaMessageHandler(
            self::getContainer()->get(ArticleRepository::class),
            $crawlerService,
            $this->entityManager,
            $messageBus,
            new NullLogger(),
            $batchSize,
        );
    }
}

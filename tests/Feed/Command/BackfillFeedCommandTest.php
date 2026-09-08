<?php

namespace App\Tests\Feed\Command;

use App\Article\Repository\ArticleRepository;
use App\Classification\ClassificationService;
use App\Classification\Pipeline\BatchFrequencyScorer;
use App\Classification\Pipeline\CountryNamedEntityRecognizer;
use App\Classification\Pipeline\LightSuffixStemmer;
use App\Classification\Pipeline\RegexTokenizer;
use App\Classification\Pipeline\StopWordFilter;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Feed\Command\BackfillFeedCommand;
use App\Feed\Entity\Feed;
use App\Feed\FeedBackfiller;
use App\Feed\Repository\FeedRepository;
use App\Feed\SitemapLocator;
use App\Feed\SitemapParser;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Commande `app:feed:backfill` (§6bis) : ne traite que les médias actifs (ou celui ciblé par
 * `--feed`), respecte `--years` et `--limit`, gère `--dry-run`.
 */
final class BackfillFeedCommandTest extends KernelTestCase
{
    private const SITEMAP = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <url><loc>https://exemple.com/a</loc></url>
          <url><loc>https://exemple.com/b</loc></url>
        </urlset>
        XML;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        parent::tearDown();
    }

    public function testBackfillsActiveFeedsOnly(): void
    {
        $this->persistFeed('https://exemple.com/actif.xml', active: true);
        $this->persistFeed('https://exemple.com/inactif.xml', active: false);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, self::getContainer()->get(ArticleRepository::class)->findAll(), 'Le média inactif ne doit pas être backfillé.');
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 article(s) créé(s)', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->persistFeed('https://exemple.com/actif.xml', active: true);

        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(0, self::getContainer()->get(ArticleRepository::class)->findAll());
        self::assertStringContainsString('Simulation', $tester->getDisplay());
    }

    public function testLimitOptionIsForwarded(): void
    {
        $this->persistFeed('https://exemple.com/actif.xml', active: true);

        $tester = $this->makeTester();
        $tester->execute(['--limit' => '1']);

        self::assertCount(1, self::getContainer()->get(ArticleRepository::class)->findAll());
    }

    public function testRejectsNonNumericFeedOption(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::INVALID, $tester->execute(['--feed' => 'abc']));
    }

    public function testReportsUnknownFeed(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::INVALID, $tester->execute(['--feed' => '99999']));
    }

    public function testRejectsInvalidYearsOption(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::INVALID, $tester->execute(['--years' => '0']));
    }

    private function persistFeed(string $url, bool $active): Feed
    {
        $feed = new Feed($url, Language::FRENCH);
        $feed->setActive($active);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function makeTester(): CommandTester
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            return str_ends_with($url, 'sitemap.xml')
                ? new MockResponse(self::SITEMAP, ['http_code' => 200])
                : new MockResponse('', ['http_code' => 404]);
        });

        $container = self::getContainer();
        $normalizer = new UnicodeNormalizer();
        $classificationService = new ClassificationService(
            $normalizer,
            new RegexTokenizer($container->getParameter('classification.min_word_length')),
            new StopWordFilter(\dirname(__DIR__, 3).'/src/Classification/Resources/stopwords'),
            new LightSuffixStemmer(),
            new CountryNamedEntityRecognizer($container->get(CountryRepository::class), $normalizer),
            new BatchFrequencyScorer(),
            $container->get(TaxonomyRepository::class),
            $this->entityManager,
            $container->getParameter('classification.min_document_frequency'),
            $container->getParameter('classification.max_document_frequency_ratio'),
        );

        $backfiller = new FeedBackfiller(
            $httpClient,
            new SitemapParser(),
            new SitemapLocator($httpClient, new NullLogger(), 'AfriqueActualitesBot/1.0 (test)', 5.0),
            self::getContainer()->get(ArticleRepository::class),
            $classificationService,
            $this->entityManager,
            $bus,
            new NullLogger(),
            'AfriqueActualitesBot/1.0 (test)',
            5.0,
            200,
        );

        $command = new BackfillFeedCommand(
            self::getContainer()->get(FeedRepository::class),
            $backfiller,
        );

        return new CommandTester($command);
    }
}

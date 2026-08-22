<?php

namespace App\Tests\Feed\Command;

use App\Article\Repository\ArticleRepository;
use App\Feed\Command\IngestFeedsCommand;
use App\Feed\Entity\Feed;
use App\Feed\FeedIngester;
use App\Feed\FeedParser;
use App\Feed\Repository\FeedRepository;
use App\Shared\ValueObject\Language;
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
 * Commande `app:feed:ingest` (§6) : n'ingère que les flux actifs (ou celui ciblé par `--feed`),
 * gère `--dry-run` et signale les flux en échec par un code de sortie. La classification est
 * découplée (cf. {@see \App\Tests\Classification\Command\RunClassificationCommandTest}).
 */
final class IngestFeedsCommandTest extends KernelTestCase
{
    private const RSS = <<<'XML'
        <rss version="2.0"><channel><title>Média</title>
          <item><title>Article ivoirien à Abidjan</title><link>https://exemple.com/1</link><description>Actualité de Côte d'Ivoire et du Sénégal.</description></item>
        </channel></rss>
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

    public function testIngestsActiveFeedsOnly(): void
    {
        $this->persistFeed('https://exemple.com/actif.xml', active: true);
        $this->persistFeed('https://exemple.com/inactif.xml', active: false);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, self::getContainer()->get(ArticleRepository::class)->findAll(), 'Le flux inactif ne doit pas être ingéré.');
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 article(s) créé(s)', $tester->getDisplay());
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

    public function testFailingFeedYieldsFailureExitCode(): void
    {
        $feed = $this->persistFeed('https://exemple.com/casse.xml', active: true);

        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--feed' => (string) $feed->getId()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('en échec', $tester->getDisplay());
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

        // Renvoie un flux valide, sauf pour l'URL "casse.xml" qui renvoie un corps non-flux
        // (déclenche une FeedParseException, pour exercer la gestion d'échec de la commande).
        $httpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $body = str_contains($url, 'casse') ? '<html><body>Erreur</body></html>' : self::RSS;

            return new MockResponse($body, ['http_code' => 200]);
        });

        $ingester = new FeedIngester(
            $httpClient,
            new FeedParser(),
            self::getContainer()->get(ArticleRepository::class),
            $this->entityManager,
            $bus,
            new NullLogger(),
            'AfriqueActualitesBot/1.0 (test)',
            5.0,
        );

        $command = new IngestFeedsCommand(
            self::getContainer()->get(FeedRepository::class),
            $ingester,
        );

        return new CommandTester($command);
    }
}

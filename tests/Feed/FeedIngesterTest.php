<?php

namespace App\Tests\Feed;

use App\Article\Repository\ArticleRepository;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Feed\Entity\Feed;
use App\Feed\FeedIngester;
use App\Feed\FeedParser;
use App\Shared\ValueObject\Language;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Récupération des articles (§6) : création depuis un flux, renseignement du libellé, extraction
 * des métadonnées, déduplication rejouable et déclenchement du crawl de repli (§9.4) uniquement
 * pour les entrées à métadonnées incomplètes.
 */
final class FeedIngesterTest extends KernelTestCase
{
    private const RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
          <channel>
            <title>Média Partenaire</title>
            <item>
              <title>Avec image</title>
              <link>https://exemple.com/1</link>
              <description>Résumé complet</description>
              <media:content url="https://exemple.com/1.jpg" medium="image" />
            </item>
            <item>
              <title>Sans image</title>
              <link>https://exemple.com/2</link>
              <description>Résumé sans image</description>
            </item>
            <item>
              <title>Sans description</title>
              <link>https://exemple.com/3</link>
            </item>
          </channel>
        </rss>
        XML;

    private EntityManagerInterface $entityManager;
    private ArticleRepository $articleRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->articleRepository = self::getContainer()->get(ArticleRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        parent::tearDown();
    }

    public function testCreatesArticlesAndSetsFeedLabel(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $result = $this->makeIngester($dispatched)->ingest($feed);

        self::assertSame(3, $result->createdCount());
        self::assertSame(0, $result->skipped);
        self::assertSame('Média Partenaire', $feed->getLabel(), 'Le libellé anonyme doit être renseigné depuis le flux.');
        self::assertCount(3, $this->articleRepository->findAll());

        $withImage = $this->articleRepository->findOneBy(['title' => 'Avec image']);
        self::assertNotNull($withImage);
        self::assertSame('https://exemple.com/1.jpg', $withImage->getImage());
        self::assertSame('Résumé complet', $withImage->getDescription());
        self::assertSame(Language::FRENCH, $withImage->getLanguage(), 'La langue est héritée du flux.');

        // Article complet (titre + description + image) → publié par défaut ; les incomplets non.
        self::assertTrue($withImage->isPublish(), 'Un article complet est publié par défaut.');
        self::assertFalse($this->articleRepository->findOneBy(['title' => 'Sans image'])->isPublish());
        self::assertFalse($this->articleRepository->findOneBy(['title' => 'Sans description'])->isPublish());
    }

    public function testDispatchesCrawlForItemsMissingMetadataIncludingImage(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $this->makeIngester($dispatched)->ingest($feed);

        $avecImage = $this->articleRepository->findOneBy(['title' => 'Avec image']);
        $sansImage = $this->articleRepository->findOneBy(['title' => 'Sans image']);
        $sansDescription = $this->articleRepository->findOneBy(['title' => 'Sans description']);
        self::assertNotNull($sansImage);
        self::assertNotNull($sansDescription);

        sort($dispatched);
        $expected = [$sansImage->getId(), $sansDescription->getId()];
        sort($expected);
        self::assertSame(
            $expected,
            $dispatched,
            'Une image absente comme une description absente doivent déclencher un crawl.',
        );
        self::assertNotContains($avecImage->getId(), $dispatched, 'L\'article complet ne doit pas être crawlé.');
    }

    public function testIsIdempotentAcrossRuns(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];
        $ingester = $this->makeIngester($dispatched);

        $ingester->ingest($feed);
        $second = $ingester->ingest($feed);

        self::assertSame(0, $second->createdCount(), 'Un second passage ne doit rien recréer.');
        self::assertSame(3, $second->skipped);
        self::assertCount(3, $this->articleRepository->findAll());
    }

    public function testDryRunPersistsNothing(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $result = $this->makeIngester($dispatched)->ingest($feed, dryRun: true);

        self::assertSame(3, $result->createdCount());
        self::assertCount(0, $this->articleRepository->findAll(), 'Aucune écriture en simulation.');
        self::assertSame([], $dispatched, 'Aucun crawl dispatché en simulation.');
        self::assertNull($feed->getLabel(), 'Le libellé ne doit pas être modifié en simulation.');
    }

    private function persistFeed(): Feed
    {
        $feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /** @param list<int> $dispatched capture, par référence, l'identifiant de chaque article crawlé */
    private function makeIngester(array &$dispatched): FeedIngester
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (CrawlArticleMetaMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message->articleId;

            return new Envelope($message);
        });

        return new FeedIngester(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(self::RSS, ['http_code' => 200])),
            new FeedParser(),
            $this->articleRepository,
            $this->entityManager,
            $messageBus,
            new NullLogger(),
            'AfriqueActualitesBot/1.0 (test)',
            5.0,
        );
    }
}

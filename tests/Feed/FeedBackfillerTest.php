<?php

namespace App\Tests\Feed;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Classification\ClassificationService;
use App\Classification\Pipeline\BatchFrequencyScorer;
use App\Classification\Pipeline\CountryNamedEntityRecognizer;
use App\Classification\Pipeline\LightSuffixStemmer;
use App\Classification\Pipeline\RegexTokenizer;
use App\Classification\Pipeline\StopWordFilter;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Feed\Entity\Feed;
use App\Feed\FeedBackfiller;
use App\Feed\SitemapLocator;
use App\Feed\SitemapParser;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Backfill historique (§6bis) : découverte du sitemap via robots.txt, suivi d'un `sitemapindex`
 * jusqu'à ses feuilles, filtrage par fenêtre temporelle (une `lastmod` absente reste dans la
 * fenêtre, cf. {@see FeedBackfiller}), déduplication, déclenchement du crawl de repli (§9.4) pour
 * chaque article créé — toujours incomplet, un sitemap ne fournissant qu'une URL, jamais un repli
 * sur celle-ci en guise de titre — et classification (§10) des articles déjà exploitables du
 * média, jour par jour.
 */
final class FeedBackfillerTest extends KernelTestCase
{
    private const ROBOTS_TXT = "User-agent: *\nDisallow:\nSitemap: https://exemple.com/sitemap_index.xml\n";

    private const SITEMAP_INDEX = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <sitemap><loc>https://exemple.com/sitemap-leaf.xml</loc></sitemap>
        </sitemapindex>
        XML;

    private EntityManagerInterface $entityManager;
    private ArticleRepository $articleRepository;
    private ClassificationService $classificationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->articleRepository = $container->get(ArticleRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        // Service assemblé avec ses implémentations réelles, comme dans ClassificationServiceTest /
        // RunClassificationCommandTest : on évite ainsi de dépendre de sa présence dans le
        // conteneur de test.
        $normalizer = new UnicodeNormalizer();
        $this->classificationService = new ClassificationService(
            $normalizer,
            new RegexTokenizer($container->getParameter('classification.min_word_length')),
            new StopWordFilter(\dirname(__DIR__, 2).'/src/Classification/Resources/stopwords'),
            new LightSuffixStemmer(),
            new CountryNamedEntityRecognizer($container->get(CountryRepository::class), $normalizer),
            new BatchFrequencyScorer(),
            $container->get(TaxonomyRepository::class),
            $this->entityManager,
            $container->getParameter('classification.min_document_frequency'),
            $container->getParameter('classification.max_document_frequency_ratio'),
        );
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        parent::tearDown();
    }

    public function testKeepsEntriesInWindowOrWithUnknownDateAndDropsOlderOnes(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];
        $since = new \DateTimeImmutable('-5 years');

        $result = $this->makeBackfiller($dispatched)->backfill($feed, $since);

        self::assertSame(3, $result->entriesSeen);
        self::assertSame(2, $result->entriesInWindow, "L'entrée trop ancienne doit être écartée.");
        self::assertSame(2, $result->created);
        self::assertCount(2, $this->articleRepository->findAll());

        $recent = $this->articleRepository->findOneBy(['url' => 'https://exemple.com/recent']);
        $undated = $this->articleRepository->findOneBy(['url' => 'https://exemple.com/undated']);
        self::assertNotNull($recent);
        self::assertNotNull($undated, 'Une lastmod absente doit rester dans la fenêtre (date inconnue, pas hors fenêtre).');
        self::assertNull($this->articleRepository->findOneBy(['url' => 'https://exemple.com/old']), 'Une entrée trop ancienne ne doit pas créer d\'article.');
    }

    public function testCreatedArticlesAreIncompleteAndDispatchCrawl(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $this->makeBackfiller($dispatched)->backfill($feed, new \DateTimeImmutable('-5 years'));

        $recent = $this->articleRepository->findOneBy(['url' => 'https://exemple.com/recent']);
        self::assertNotNull($recent);
        self::assertSame('', $recent->getTitle(), 'Le titre ne doit pas retomber sur l\'URL : cela empêcherait le crawl de repli de le corriger (celui-ci ne remplace jamais un champ non vide).');
        self::assertFalse($recent->isComplete(), 'Un article issu du sitemap ne connaît que son URL.');
        self::assertFalse($recent->isPublish());

        sort($dispatched);
        $ids = array_map(static fn (Article $a): int => (int) $a->getId(), $this->articleRepository->findAll());
        sort($ids);
        self::assertSame($ids, $dispatched, 'Chaque article créé doit déclencher un crawl de repli.');
    }

    public function testIsIdempotentAcrossRuns(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];
        $backfiller = $this->makeBackfiller($dispatched);
        $since = new \DateTimeImmutable('-5 years');

        $backfiller->backfill($feed, $since);
        $second = $backfiller->backfill($feed, $since);

        self::assertSame(0, $second->created, 'Un second passage ne doit rien recréer.');
        self::assertCount(2, $this->articleRepository->findAll());
    }

    public function testDryRunPersistsNothing(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $result = $this->makeBackfiller($dispatched)->backfill($feed, new \DateTimeImmutable('-5 years'), dryRun: true);

        self::assertSame(2, $result->created);
        self::assertCount(0, $this->articleRepository->findAll(), 'Aucune écriture en simulation.');
        self::assertSame([], $dispatched, 'Aucun crawl dispatché en simulation.');
    }

    public function testLimitCapsCreatedArticles(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $result = $this->makeBackfiller($dispatched)->backfill($feed, new \DateTimeImmutable('-5 years'), limit: 1);

        self::assertSame(1, $result->created);
        self::assertCount(1, $this->articleRepository->findAll());
    }

    /**
     * Un `sitemapindex` à plusieurs feuilles doit voir ses entrées agrégées correctement — chaque
     * feuille est traitée en flux dès sa récupération (cf. docblock "Mémoire" de
     * {@see FeedBackfiller}), jamais en accumulant l'arborescence entière avant de commencer, donc
     * les compteurs doivent malgré tout refléter l'ensemble des feuilles, pas seulement la dernière.
     */
    public function testAggregatesEntriesAcrossMultipleSitemapLeaves(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];

        $sitemapIndex = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <sitemap><loc>https://exemple.com/leaf-a.xml</loc></sitemap>
              <sitemap><loc>https://exemple.com/leaf-b.xml</loc></sitemap>
            </sitemapindex>
            XML;
        $leafA = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://exemple.com/a-1</loc></url>
              <url><loc>https://exemple.com/a-2</loc></url>
            </urlset>
            XML;
        $leafB = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://exemple.com/b-1</loc></url>
              <url><loc>https://exemple.com/b-2</loc></url>
              <url><loc>https://exemple.com/b-3</loc></url>
            </urlset>
            XML;

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (CrawlArticleMetaMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message->articleId;

            return new Envelope($message);
        });

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($sitemapIndex, $leafA, $leafB): MockResponse {
            return match (true) {
                str_ends_with($url, '/robots.txt') => new MockResponse(self::ROBOTS_TXT, ['http_code' => 200]),
                str_ends_with($url, 'sitemap_index.xml') => new MockResponse($sitemapIndex, ['http_code' => 200]),
                str_ends_with($url, 'leaf-a.xml') => new MockResponse($leafA, ['http_code' => 200]),
                str_ends_with($url, 'leaf-b.xml') => new MockResponse($leafB, ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        $backfiller = new FeedBackfiller(
            $httpClient,
            new SitemapParser(),
            new SitemapLocator($httpClient, new NullLogger(), 'AfriqueActualitesBot/1.0 (test)', 5.0),
            $this->articleRepository,
            $this->classificationService,
            $this->entityManager,
            $messageBus,
            new NullLogger(),
            'AfriqueActualitesBot/1.0 (test)',
            5.0,
            200,
        );

        $result = $backfiller->backfill($feed, new \DateTimeImmutable('-5 years'));

        self::assertSame(5, $result->entriesSeen, "Les entrées des deux feuilles doivent s'additionner.");
        self::assertSame(5, $result->entriesInWindow);
        self::assertSame(5, $result->created);
        self::assertCount(5, $this->articleRepository->findAll());
        self::assertCount(5, $dispatched);
    }

    /**
     * Traverse au moins une frontière de lot (`FeedBackfiller::PERSIST_BATCH_SIZE` = 200) pour
     * vérifier que le `persist()`/`flush()`/`clear()` par lots — introduit après un épuisement
     * mémoire observé en production sur un média à sitemap volumineux — ne perd, ne duplique et ne
     * corrompt aucune entrée, et que les identifiants dispatchés au crawl de repli restent exacts
     * malgré le détachement des entités en cours de route.
     */
    public function testSurvivesAPersistBatchBoundary(): void
    {
        $feed = $this->persistFeed();
        $dispatched = [];
        $entryCount = 250; // > PERSIST_BATCH_SIZE (200) : au moins deux lots.

        $urls = [];
        $sitemapUrls = '';
        for ($i = 0; $i < $entryCount; ++$i) {
            $urls[] = "https://exemple.com/lot-{$i}";
            $sitemapUrls .= "<url><loc>https://exemple.com/lot-{$i}</loc></url>\n";
        }
        $sitemap = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            {$sitemapUrls}</urlset>
            XML;

        $result = $this->makeBackfiller($dispatched, $sitemap)->backfill($feed, new \DateTimeImmutable('-5 years'));

        self::assertSame($entryCount, $result->created);
        self::assertCount($entryCount, $this->articleRepository->findAll(), 'Aucune entrée ne doit être perdue ou dupliquée au passage d\'une frontière de lot.');

        foreach ($urls as $url) {
            self::assertNotNull($this->articleRepository->findOneBy(['url' => $url]), "Entrée manquante : {$url}");
        }

        sort($dispatched);
        $ids = array_map(static fn (Article $a): int => (int) $a->getId(), $this->articleRepository->findAll());
        sort($ids);
        self::assertSame($ids, $dispatched, 'Chaque article, quel que soit son lot, doit déclencher exactement un crawl de repli.');

        // Idempotence même après les clear() intermédiaires : un second passage sur le même
        // sitemap ne doit rien recréer.
        $secondDispatched = [];
        $second = $this->makeBackfiller($secondDispatched, $sitemap)->backfill($feed, new \DateTimeImmutable('-5 years'));

        self::assertSame(0, $second->created, 'Un second passage après un lot ne doit rien recréer.');
        self::assertCount($entryCount, $this->articleRepository->findAll());
    }

    /**
     * Deux jours consécutifs de la même semaine ISO, chacun avec son propre terme récurrent : si
     * la classification regroupait par semaine plutôt que par jour, ce cas serait traité en un
     * seul lot et on ne pourrait pas le distinguer d'une classification correcte au seul regard
     * des mots-clés créés — c'est le compte de jours traités (`daysClassified`) qui vérifie
     * directement le découpage.
     *
     * Les articles ne viennent pas du sitemap mocké (celui-ci ne fournit qu'une URL, jamais de
     * texte) : ils simulent des articles déjà crawlés lors d'un run précédent, comme
     * {@see FeedBackfiller} le documente.
     */
    public function testClassifiesAlreadyCrawledArticlesDayByDay(): void
    {
        $feed = $this->persistFeed();

        // Jour 1 (lundi) : cinq articles, « réforme » dans deux d'entre eux (ratio 2/5 = 0.4, sous
        // le plafond de 0.6 de classification.max_document_frequency_ratio).
        $day1 = new \DateTimeImmutable('2024-01-08');
        $this->persistCrawledArticle($feed, 'd1-a', 'Le Nigeria annonce une réforme économique', 'Plan de réforme détaillé', $day1);
        $this->persistCrawledArticle($feed, 'd1-b', 'Réforme économique : le plan confirmé', 'Une réforme jugée ambitieuse', $day1);
        $this->persistCrawledArticle($feed, 'd1-c', 'Match de football à Dakar', 'Les supporters remplissent le stade', $day1);
        $this->persistCrawledArticle($feed, 'd1-d', 'Sommet sur la culture à Addis-Abeba', 'Les dirigeants débattent des enjeux', $day1);
        $this->persistCrawledArticle($feed, 'd1-e', 'Concert de musique à Abidjan', 'Une soirée très attendue par le public', $day1);

        // Jour 2 (mardi, même semaine ISO) : cinq autres articles, « climat » dans deux d'entre eux.
        $day2 = $day1->modify('+1 day');
        $this->persistCrawledArticle($feed, 'd2-a', 'Sommet mondial sur le climat à Nairobi', 'Accord climatique historique signé', $day2);
        $this->persistCrawledArticle($feed, 'd2-b', 'Le climat africain se réchauffe vite', 'Rapport alarmant sur le climat', $day2);
        $this->persistCrawledArticle($feed, 'd2-c', 'Élection présidentielle au Ghana', 'Les candidats en campagne', $day2);
        $this->persistCrawledArticle($feed, 'd2-d', 'Festival de cinéma à Ouagadougou', 'Une édition très suivie', $day2);
        $this->persistCrawledArticle($feed, 'd2-e', 'Nouvelle ligne ferroviaire au Kenya', "Un projet d'infrastructure majeur", $day2);

        $dispatched = [];
        $result = $this->makeBackfiller($dispatched)->backfill($feed, new \DateTimeImmutable('-5 years'));

        self::assertSame(2, $result->daysClassified, 'Deux jours distincts (même semaine ISO) doivent être traités séparément.');
        self::assertSame(10, $result->articlesClassified, 'Les dix articles déjà exploitables doivent tous être classés (pas seulement ceux créés à ce passage).');
        self::assertGreaterThanOrEqual(2, $result->keywordsCreated, '« réforme » (jour 1) et « climat » (jour 2) doivent devenir mots-clés.');

        $d1a = $this->articleRepository->findOneBy(['url' => 'https://exemple.com/d1-a']);
        self::assertNotNull($d1a);
        self::assertGreaterThan(0, $d1a->getKeywordsCount(), 'Un article contenant un terme éligible doit se voir attacher un mot-clé.');
    }

    private function persistFeed(): Feed
    {
        $feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function persistCrawledArticle(Feed $feed, string $urlSlug, string $title, string $description, \DateTimeImmutable $publicationDate): Article
    {
        $article = new Article($title, 'https://exemple.com/'.$urlSlug, $feed, $publicationDate);
        $article->setDescription($description);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    private function sitemapLeaf(): string
    {
        $old = (new \DateTimeImmutable('-10 years'))->format('Y-m-d');
        $recent = (new \DateTimeImmutable('-6 months'))->format('Y-m-d');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://exemple.com/recent</loc><lastmod>{$recent}</lastmod></url>
              <url><loc>https://exemple.com/old</loc><lastmod>{$old}</lastmod></url>
              <url><loc>https://exemple.com/undated</loc></url>
            </urlset>
            XML;
    }

    /** @param list<int> $dispatched capture, par référence, l'identifiant de chaque article crawlé */
    private function makeBackfiller(array &$dispatched, ?string $sitemapLeaf = null): FeedBackfiller
    {
        $sitemapLeaf ??= $this->sitemapLeaf();

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (CrawlArticleMetaMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message->articleId;

            return new Envelope($message);
        });

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($sitemapLeaf): MockResponse {
            return match (true) {
                str_ends_with($url, '/robots.txt') => new MockResponse(self::ROBOTS_TXT, ['http_code' => 200]),
                str_ends_with($url, 'sitemap_index.xml') => new MockResponse(self::SITEMAP_INDEX, ['http_code' => 200]),
                str_ends_with($url, 'sitemap-leaf.xml') => new MockResponse($sitemapLeaf, ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        return new FeedBackfiller(
            $httpClient,
            new SitemapParser(),
            new SitemapLocator($httpClient, new NullLogger(), 'AfriqueActualitesBot/1.0 (test)', 5.0),
            $this->articleRepository,
            $this->classificationService,
            $this->entityManager,
            $messageBus,
            new NullLogger(),
            'AfriqueActualitesBot/1.0 (test)',
            5.0,
            200,
        );
    }
}

<?php

namespace App\Tests\Synthesis;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Synthesis\ArticleClusterer;
use App\Synthesis\ClusterSummarizer;
use App\Synthesis\Entity\Synthesis;
use App\Synthesis\Enum\SynthesisStatus;
use App\Synthesis\Pipeline\KeywordDictionaryThemeClassifier;
use App\Synthesis\Repository\SynthesisRepository;
use App\Synthesis\SynthesisAssembler;
use App\Synthesis\SynthesisGenerator;
use App\Synthesis\WeeklySelector;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration bout en bout du pipeline (sélection → clustering → map → reduce → persistance,
 * §11.2), avec un `FakeLlmClient` (aucun appel réseau réel) — même méthodologie que
 * `ClassificationServiceTest` : services construits avec leurs implémentations réelles, sur un jeu
 * d'articles réalistes.
 */
final class SynthesisGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SynthesisRepository $synthesisRepository;
    private FakeLlmClient $llmClient;
    private Feed $feed;
    private \DateTimeImmutable $weekStart;
    private \DateTimeImmutable $weekEnd;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->synthesisRepository = $container->get(SynthesisRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        $this->llmClient = new FakeLlmClient();
        $this->feed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $this->entityManager->persist($this->feed);

        $this->weekStart = new \DateTimeImmutable('2026-08-24 00:00:00');
        $this->weekEnd = $this->weekStart->modify('+7 days');
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testGeneratesADraftSynthesisWithTraceableSourceArticles(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addElectionAndEconomyArticles($senegal, 6);
        $this->entityManager->flush();

        $summary = $this->makeGenerator(autoPublish: false)->generateForWeek($this->weekStart, $this->weekEnd);

        self::assertSame(1, $summary->created);
        self::assertSame(0, $summary->skipped);
        self::assertSame(0, $summary->failed);

        $synthesis = $this->synthesisRepository->findOneBy(['country' => $senegal]);
        self::assertNotNull($synthesis);
        self::assertSame(SynthesisStatus::DRAFT, $synthesis->getStatus());
        self::assertSame('Titre généré', $synthesis->getTitle());
        self::assertSame('Chapô généré.', $synthesis->getLead());
        self::assertStringContainsString('<h3>', $synthesis->getBody());
        self::assertCount(6, $synthesis->getSourceArticles());
    }

    public function testAutoPublishFlagPublishesImmediatelyWithoutAnAdmin(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addElectionAndEconomyArticles($senegal, 6);
        $this->entityManager->flush();

        $this->makeGenerator(autoPublish: true)->generateForWeek($this->weekStart, $this->weekEnd);

        $synthesis = $this->synthesisRepository->findOneBy(['country' => $senegal]);
        self::assertSame(SynthesisStatus::PUBLISHED, $synthesis->getStatus());
        self::assertNull($synthesis->getReviewedBy());
        self::assertNotNull($synthesis->getPublishedAt());
    }

    public function testRunningTheSameWeekTwiceDoesNotDuplicateSyntheses(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addElectionAndEconomyArticles($senegal, 6);
        $this->entityManager->flush();

        $generator = $this->makeGenerator(autoPublish: false);
        $first = $generator->generateForWeek($this->weekStart, $this->weekEnd);
        $second = $generator->generateForWeek($this->weekStart, $this->weekEnd);

        self::assertSame(1, $first->created);
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->skipped);
        self::assertCount(1, $this->entityManager->getRepository(Synthesis::class)->findAll());
    }

    public function testGeneratesARegionalSynthesisForCountriesUnderTheThreshold(): void
    {
        $mali = $this->makeCountry('ML', Region::WEST_AFRICA);
        $ivoryCoast = $this->makeCountry('CI', Region::WEST_AFRICA);
        $this->addElectionAndEconomyArticles($mali, 3);
        $this->addElectionAndEconomyArticles($ivoryCoast, 3);
        $this->entityManager->flush();

        $summary = $this->makeGenerator(autoPublish: false)->generateForWeek($this->weekStart, $this->weekEnd);

        self::assertSame(1, $summary->created);
        $synthesis = $this->synthesisRepository->findOneBy(['region' => Region::WEST_AFRICA]);
        self::assertNotNull($synthesis);
        self::assertNull($synthesis->getCountry());
        self::assertCount(6, $synthesis->getSourceArticles());
    }

    private function makeGenerator(bool $autoPublish): SynthesisGenerator
    {
        $container = self::getContainer();
        $normalizer = new UnicodeNormalizer();
        $countryRepository = $container->get(CountryRepository::class);
        $articleRepository = $container->get(ArticleRepository::class);
        $logger = new NullLogger();

        $themeClassifier = new KeywordDictionaryThemeClassifier($normalizer, dirname(__DIR__, 2).'/src/Synthesis/Resources/themes');

        return new SynthesisGenerator(
            new WeeklySelector($countryRepository, $articleRepository, $logger, 5),
            new ArticleClusterer($themeClassifier),
            new ClusterSummarizer($this->llmClient, $logger, 'fake-map-model'),
            new SynthesisAssembler($this->llmClient, $logger, 'fake-reduce-model'),
            $this->synthesisRepository,
            $this->entityManager,
            $logger,
            $autoPublish,
        );
    }

    private function makeCountry(string $code, Region $region): Country
    {
        $country = new Country($code, $code, $code);
        $country->setActive(true);
        $country->setRegion($region);
        $this->entityManager->persist($country);

        return $country;
    }

    /** Moitié d'articles électoraux, moitié économiques : deux clusters distincts pour exercer le map sur plusieurs thèmes. */
    private function addElectionAndEconomyArticles(Country $country, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $isElection = 0 === $i % 2;
            $title = $isElection
                ? sprintf('%s : élection présidentielle, %s', $country->getCode(), uniqid())
                : sprintf('%s : croissance économique et budget, %s', $country->getCode(), uniqid());
            $description = $isElection
                ? 'Les électeurs se rendent aux urnes pour ce scrutin très suivi.'
                : "Le gouvernement annonce des mesures pour soutenir l'investissement et l'emploi.";

            $article = new Article($title, 'https://example.com/'.uniqid(), $this->feed, $this->weekStart->modify('+1 day'));
            $article->setDescription($description);
            $article->setPublish(true);
            $article->addCountry($country);
            $this->entityManager->persist($article);
        }
    }
}

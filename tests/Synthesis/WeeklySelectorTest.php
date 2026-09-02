<?php

namespace App\Tests\Synthesis;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Synthesis\WeeklySelector;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration de l'étape 1 du pipeline (§ "Sélection et regroupement") : seuil minimal par
 * pays, repli régional des pays sous le seuil, et fenêtre de dates/langue — sur un jeu d'articles
 * réalistes plutôt que des doubles synthétiques, comme les autres tests d'intégration du dépôt
 * (`ClassificationServiceTest`).
 */
final class WeeklySelectorTest extends KernelTestCase
{
    private const MIN_ARTICLES_PER_COUNTRY = 5;

    private EntityManagerInterface $entityManager;
    private WeeklySelector $weeklySelector;
    private Feed $frenchFeed;
    private \DateTimeImmutable $weekStart;
    private \DateTimeImmutable $weekEnd;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        $this->weeklySelector = new WeeklySelector(
            $container->get(CountryRepository::class),
            $container->get(ArticleRepository::class),
            new NullLogger(),
            self::MIN_ARTICLES_PER_COUNTRY,
        );

        $this->frenchFeed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $this->entityManager->persist($this->frenchFeed);

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

    public function testCountryReachingTheThresholdGetsADedicatedSelection(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addArticles($senegal, 6, $this->weekStart->modify('+1 day'));
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        self::assertCount(1, $result->countrySelections);
        self::assertSame($senegal, $result->countrySelections[0]->country);
        self::assertCount(6, $result->countrySelections[0]->articles);
        self::assertSame([], $result->regionSelections);
    }

    public function testCountriesUnderTheThresholdAreOmittedWhenTheirRegionAlsoStaysUnderIt(): void
    {
        $mali = $this->makeCountry('ML', Region::WEST_AFRICA);
        $ivoryCoast = $this->makeCountry('CI', Region::WEST_AFRICA);
        $this->addArticles($mali, 2, $this->weekStart->modify('+1 day'));
        $this->addArticles($ivoryCoast, 2, $this->weekStart->modify('+2 days'));
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        // 2 + 2 = 4 articles pour la région : toujours sous le seuil de 5, donc aucune synthèse
        // (ni pays dédié, ni repli régional) cette semaine-là — pas de synthèse creuse.
        self::assertSame([], $result->countrySelections);
        self::assertSame([], $result->regionSelections);
    }

    public function testCountriesUnderTheThresholdFallBackToARegionalSelectionOnceCombinedTheyReachIt(): void
    {
        $mali = $this->makeCountry('ML', Region::WEST_AFRICA);
        $ivoryCoast = $this->makeCountry('CI', Region::WEST_AFRICA);
        $ghana = $this->makeCountry('GH', Region::WEST_AFRICA);
        $this->addArticles($mali, 2, $this->weekStart->modify('+1 day'));
        $this->addArticles($ivoryCoast, 2, $this->weekStart->modify('+2 days'));
        $this->addArticles($ghana, 1, $this->weekStart->modify('+3 days'));
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        self::assertSame([], $result->countrySelections);
        self::assertCount(1, $result->regionSelections);
        self::assertSame(Region::WEST_AFRICA, $result->regionSelections[0]->region);
        self::assertCount(5, $result->regionSelections[0]->articles);
    }

    public function testArticlesOutsideTheWeekWindowAreExcluded(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addArticles($senegal, 5, $this->weekStart->modify('+1 day'));
        // En dehors de la fenêtre : ne doit jamais faire basculer le pays au-dessus du seuil.
        $this->addArticles($senegal, 5, $this->weekStart->modify('-3 days'));
        $this->addArticles($senegal, 5, $this->weekEnd->modify('+3 days'));
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        self::assertCount(1, $result->countrySelections);
        self::assertCount(5, $result->countrySelections[0]->articles);
    }

    public function testUnpublishedArticlesAreExcluded(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $this->addArticles($senegal, 5, $this->weekStart->modify('+1 day'));
        $this->addArticles($senegal, 5, $this->weekStart->modify('+1 day'), publish: false);
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        self::assertCount(1, $result->countrySelections);
        self::assertCount(5, $result->countrySelections[0]->articles);
    }

    public function testInactiveCountriesAreNeverSelected(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA, active: false);
        $this->addArticles($senegal, 6, $this->weekStart->modify('+1 day'));
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::FRENCH);

        self::assertSame([], $result->countrySelections);
        self::assertSame([], $result->regionSelections);
    }

    public function testOnlyArticlesOfTheRequestedLanguageAreSelected(): void
    {
        $senegal = $this->makeCountry('SN', Region::WEST_AFRICA);
        $englishFeed = new Feed('https://example.com/en/rss.xml', Language::ENGLISH);
        $this->entityManager->persist($englishFeed);
        $this->addArticles($senegal, 5, $this->weekStart->modify('+1 day'));
        for ($i = 0; $i < 5; ++$i) {
            $article = new Article('EN '.$i, 'https://example.com/en-'.$i, $englishFeed, $this->weekStart->modify('+1 day'));
            $article->setPublish(true);
            $article->addCountry($senegal);
            $this->entityManager->persist($article);
        }
        $this->entityManager->flush();

        $result = $this->weeklySelector->selectForWeek($this->weekStart, $this->weekEnd, Language::ENGLISH);

        self::assertCount(1, $result->countrySelections);
        self::assertCount(5, $result->countrySelections[0]->articles);
    }

    private function makeCountry(string $code, Region $region, bool $active = true): Country
    {
        $country = new Country($code, $code, $code);
        $country->setActive($active);
        $country->setRegion($region);
        $this->entityManager->persist($country);

        return $country;
    }

    private function addArticles(Country $country, int $count, \DateTimeImmutable $publicationDate, bool $publish = true): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $article = new Article(
                sprintf('%s article %d %s', $country->getCode(), $i, uniqid()),
                sprintf('https://example.com/%s-%s', $country->getCode(), uniqid()),
                $this->frenchFeed,
                $publicationDate,
            );
            $article->setPublish($publish);
            $article->addCountry($country);
            $this->entityManager->persist($article);
        }
    }
}

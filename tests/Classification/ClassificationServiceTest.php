<?php

namespace App\Tests\Classification;

use App\Article\Entity\Article;
use App\Classification\ClassificationService;
use App\Classification\Pipeline\BatchFrequencyScorer;
use App\Classification\Pipeline\CountryNamedEntityRecognizer;
use App\Classification\Pipeline\LightSuffixStemmer;
use App\Classification\Pipeline\RegexTokenizer;
use App\Classification\Pipeline\StopWordFilter;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\Taxonomy\Repository\TaxonomyRepository;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration du moteur de classification bilingue (§10, §11.2 phase 4), sur un jeu de
 * titres/descriptions d'articles réalistes plutôt que des chaînes synthétiques, en français et en
 * anglais. Vérifie l'ensemble de la chaîne bout en bout : normalisation → tokenisation → filtrage
 * des mots vides → racinisation → scoring → création de suggestions, espaces de taxonomies
 * distincts par langue (§3bis), reconnaissance des pays (§3.13), et promotion automatique d'un
 * mot-clé déjà validé sur de nouveaux articles (§4.4).
 */
final class ClassificationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ClassificationService $classificationService;
    private TaxonomyRepository $taxonomyRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->taxonomyRepository = $container->get(TaxonomyRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        // Construit le service avec ses implémentations réelles, dans la même configuration que
        // config/services.yaml (§10.1.8) : ClassificationService n'a encore aucun consommateur
        // (aucune commande/worker ne l'injecte avant la phase 5, §11.2), donc le compilateur de
        // conteneur l'élague comme service inutilisé en environnement de test — le récupérer
        // directement depuis le conteneur n'est pas encore possible.
        $normalizer = new UnicodeNormalizer();
        $countryRepository = $container->get(CountryRepository::class);
        $this->classificationService = new ClassificationService(
            $normalizer,
            new RegexTokenizer($container->getParameter('classification.min_word_length')),
            new StopWordFilter(dirname(__DIR__, 2).'/src/Classification/Resources/stopwords'),
            new LightSuffixStemmer(),
            new CountryNamedEntityRecognizer($countryRepository, $normalizer),
            new BatchFrequencyScorer(),
            $this->taxonomyRepository,
            $this->entityManager,
            $container->getParameter('classification.min_document_frequency'),
            $container->getParameter('classification.max_document_frequency_ratio'),
        );

        $senegal = new Country('SN', 'Sénégal', 'Senegal');
        $senegal->setActive(true);
        $nigeria = new Country('NG', 'Nigeria', 'Nigeria');
        $nigeria->setActive(true);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($nigeria);

        // Une taxonomie française déjà validée lors d'un passage précédent : ce test vérifie
        // qu'elle est automatiquement promue mot-clé sur les nouveaux articles qui la contiennent
        // (§4.4), sans repasser par le circuit de suggestion.
        $admin = new User('admin@afrique-actualites.com');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->entityManager->persist($admin);

        $preValidatedNigeria = new Taxonomy('nigeria', Language::FRENCH);
        $preValidatedNigeria->validate($admin);
        $this->entityManager->persist($preValidatedNigeria);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testClassifiesABilingualBatchOfRealisticArticleTitles(): void
    {
        $frenchFeed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $englishFeed = new Feed('https://example.com/en/rss.xml', Language::ENGLISH);
        $this->entityManager->persist($frenchFeed);
        $this->entityManager->persist($englishFeed);

        $frenchArticles = [
            $this->makeArticle(
                $frenchFeed,
                'Élection présidentielle au Sénégal',
                'Les électeurs sénégalais votent dimanche pour désigner leur président',
            ),
            $this->makeArticle(
                $frenchFeed,
                "Sénégal : la campagne électorale s'achève",
                "Les candidats à l'élection ont tenu leurs derniers meetings à Dakar",
            ),
            $this->makeArticle(
                $frenchFeed,
                'Le Nigeria annonce une réforme économique',
                'Le gouvernement nigérian détaille son plan de réforme',
            ),
            $this->makeArticle(
                $frenchFeed,
                'Réforme économique : le Nigeria confirme son plan',
                'Une réforme jugée ambitieuse par plusieurs analystes',
            ),
            $this->makeArticle(
                $frenchFeed,
                'Sommet international sur le climat à Addis-Abeba',
                'Les dirigeants africains débattent des enjeux climatiques',
            ),
        ];

        $englishArticles = [
            $this->makeArticle(
                $englishFeed,
                'Presidential election held in Senegal',
                'Voters lined up early to cast their ballots',
            ),
            $this->makeArticle(
                $englishFeed,
                'Senegal election campaign draws to a close',
                'Candidates held final rallies across the country',
            ),
            $this->makeArticle(
                $englishFeed,
                'Nigeria announces major economic reform',
                'The government detailed its reform plan',
            ),
            $this->makeArticle(
                $englishFeed,
                'Economic reform: Nigeria confirms new plan',
                'Analysts reacted cautiously to the announcement',
            ),
            $this->makeArticle(
                $englishFeed,
                'World leaders gather for climate summit',
                'African leaders discussed key climate priorities',
            ),
        ];

        foreach ([...$frenchArticles, ...$englishArticles] as $article) {
            $this->entityManager->persist($article);
        }
        $this->entityManager->flush();

        $summary = $this->classificationService->classifyArticles([...$frenchArticles, ...$englishArticles]);

        self::assertSame(10, $summary->articlesProcessed);

        // Un terme présent dans au moins deux articles distincts du lot, sans dépasser 60 % de
        // celui-ci, devient une suggestion (§10.1.7) — jamais directement un mot-clé (§4.4).
        $frenchElection = $this->taxonomyRepository->findOneByLabelAndLanguage('election', Language::FRENCH);
        self::assertNotNull($frenchElection);
        self::assertSame(TaxonomyStatus::SUGGESTED, $frenchElection->getStatus());

        $frenchSenegal = $this->taxonomyRepository->findOneByLabelAndLanguage('senegal', Language::FRENCH);
        self::assertNotNull($frenchSenegal);
        self::assertSame(TaxonomyStatus::SUGGESTED, $frenchSenegal->getStatus());

        // Espaces de taxonomies distincts par langue (§3bis) : même orthographe, deux entités.
        $englishElection = $this->taxonomyRepository->findOneByLabelAndLanguage('election', Language::ENGLISH);
        self::assertNotNull($englishElection);
        self::assertNotSame($frenchElection->getId(), $englishElection->getId());

        // Un terme mentionné dans un seul article du lot n'atteint pas le seuil minimal de
        // fréquence documentaire (2) et ne devient pas une suggestion.
        self::assertNull($this->taxonomyRepository->findOneByLabelAndLanguage('climatiques', Language::FRENCH));
        self::assertNull($this->taxonomyRepository->findOneByLabelAndLanguage('climate', Language::ENGLISH));

        // Le mot-clé français déjà validé avant le passage de classification reste validé (le
        // pipeline ne le fait jamais régresser) et se retrouve promu mot-clé sur les deux
        // nouveaux articles qui le contiennent, sans passer par une nouvelle validation (§4.4).
        $nigeria = $this->taxonomyRepository->findOneByLabelAndLanguage('nigeria', Language::FRENCH);
        self::assertNotNull($nigeria);
        self::assertSame(TaxonomyStatus::VALIDATED, $nigeria->getStatus());
        self::assertTrue($frenchArticles[2]->getKeywords()->contains($nigeria));
        self::assertTrue($frenchArticles[3]->getKeywords()->contains($nigeria));

        // Reconnaissance des pays (§3.13) : rattachement natif Article ↔ Country, indépendant du
        // circuit de suggestion des mots-clés.
        self::assertSame('SN', $frenchArticles[0]->getCountries()->first()->getCode());
        self::assertSame('NG', $frenchArticles[2]->getCountries()->first()->getCode());
        self::assertSame('SN', $englishArticles[0]->getCountries()->first()->getCode());
        self::assertSame('NG', $englishArticles[2]->getCountries()->first()->getCode());
    }

    private function makeArticle(Feed $feed, string $title, string $description): Article
    {
        $article = new Article($title, 'https://example.com/'.bin2hex(random_bytes(8)), $feed, new \DateTimeImmutable());
        $article->setDescription($description);

        return $article;
    }
}

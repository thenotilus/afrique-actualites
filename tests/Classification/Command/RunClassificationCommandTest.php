<?php

namespace App\Tests\Classification\Command;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Classification\ClassificationService;
use App\Classification\Command\RunClassificationCommand;
use App\Classification\Pipeline\BatchFrequencyScorer;
use App\Classification\Pipeline\CountryNamedEntityRecognizer;
use App\Classification\Pipeline\LightSuffixStemmer;
use App\Classification\Pipeline\RegexTokenizer;
use App\Classification\Pipeline\StopWordFilter;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Feed\Entity\Feed;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commande `app:classification:run` (§6, §10) : la classification découplée de l'ingestion. Vérifie
 * qu'elle rescore les articles de la fenêtre glissante (et leur attache des mots-clés), qu'elle
 * borne bien cette fenêtre sur la date d'ingestion (`createdAt`) et valide l'option `--window`.
 */
final class RunClassificationCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ArticleRepository $articleRepository;
    private TaxonomyRepository $taxonomyRepository;
    private ClassificationService $classificationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->articleRepository = $container->get(ArticleRepository::class);
        $this->taxonomyRepository = $container->get(TaxonomyRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        // Service assemblé avec ses implémentations réelles, comme dans ClassificationServiceTest :
        // on évite ainsi de dépendre de la présence du service dans le conteneur de test.
        $normalizer = new UnicodeNormalizer();
        $this->classificationService = new ClassificationService(
            $normalizer,
            new RegexTokenizer($container->getParameter('classification.min_word_length')),
            new StopWordFilter(\dirname(__DIR__, 3).'/src/Classification/Resources/stopwords'),
            new LightSuffixStemmer(),
            new CountryNamedEntityRecognizer($container->get(CountryRepository::class), $normalizer),
            new BatchFrequencyScorer(),
            $this->taxonomyRepository,
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

    public function testClassifiesArticlesWithinTheWindow(): void
    {
        $feed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $this->entityManager->persist($feed);

        // Deux articles partagent le terme « réforme » (fréquence documentaire = 2). Trois articles
        // de remplissage au vocabulaire distinct portent le lot à cinq, pour que « réforme » reste
        // sous le plafond de 60 % (2/5 = 40 %) et devienne donc éligible.
        $this->persistArticle($feed, 'Le Nigeria annonce une réforme économique', 'Plan de réforme détaillé');
        $this->persistArticle($feed, 'Réforme économique : le plan confirmé', 'Une réforme jugée ambitieuse');
        $this->persistArticle($feed, 'Match de football à Dakar', 'Les supporters remplissent le stade');
        $this->persistArticle($feed, 'Sommet sur le climat à Addis-Abeba', 'Les dirigeants débattent des enjeux');
        $this->persistArticle($feed, 'Concert de musique à Abidjan', 'Une soirée très attendue par le public');
        $this->entityManager->flush();

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('5 article(s) traités', $tester->getDisplay());

        $reforme = $this->taxonomyRepository->findOneByLabelAndLanguage('reforme', Language::FRENCH);
        self::assertNotNull($reforme, 'Un terme récurrent de la fenêtre devient une taxonomie.');
        self::assertSame(TaxonomyStatus::VALIDATED, $reforme->getStatus());
    }

    public function testIgnoresArticlesIngestedBeforeTheWindow(): void
    {
        $feed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $this->entityManager->persist($feed);

        // Deux articles au vocabulaire récurrent : ils seraient classés s'ils étaient dans la
        // fenêtre. On les ré-horodate dix heures avant maintenant, soit hors de la fenêtre de 6h.
        $tenHoursAgo = (new \DateTimeImmutable())->modify('-10 hours');
        $this->forceCreatedAt(
            $this->persistArticle($feed, 'Le Nigeria annonce une réforme économique', 'Plan de réforme'),
            $tenHoursAgo,
        );
        $this->forceCreatedAt(
            $this->persistArticle($feed, 'Réforme économique : le plan confirmé', 'Réforme ambitieuse'),
            $tenHoursAgo,
        );
        $this->entityManager->flush();

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('rien à classer', $tester->getDisplay());
        self::assertNull($this->taxonomyRepository->findOneByLabelAndLanguage('reforme', Language::FRENCH));
    }

    public function testRejectsNonNumericWindow(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::INVALID, $tester->execute(['--window' => 'abc']));
        self::assertStringContainsString('--window', $tester->getDisplay());
    }

    private function persistArticle(Feed $feed, string $title, string $description): Article
    {
        $article = new Article($title, 'https://example.com/'.bin2hex(random_bytes(8)), $feed, new \DateTimeImmutable());
        $article->setDescription($description);
        $this->entityManager->persist($article);

        return $article;
    }

    private function forceCreatedAt(Article $article, \DateTimeImmutable $when): void
    {
        $property = new \ReflectionProperty($article, 'createdAt');
        $property->setValue($article, $when);
    }

    private function makeTester(): CommandTester
    {
        $command = new RunClassificationCommand($this->articleRepository, $this->classificationService, 6);

        return new CommandTester($command);
    }
}

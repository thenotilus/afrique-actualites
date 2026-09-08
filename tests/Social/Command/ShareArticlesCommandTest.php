<?php

namespace App\Tests\Social\Command;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use App\Social\Command\ShareArticlesCommand;
use App\Social\FacebookPublisher;
use App\Taxonomy\Entity\Taxonomy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Commande `app:articles:share` (§3.8) : sélectionne et publie un seul article par exécution, en
 * respectant la fenêtre anti-répétition sur les mots-clés ({@see ArticleRepository::findNextToShare()}).
 */
final class ShareArticlesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ArticleRepository $articleRepository;
    private Feed $feed;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->articleRepository = $container->get(ArticleRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        parent::tearDown();
    }

    public function testPublishesTheMostRecentEligibleArticleAndMarksItShared(): void
    {
        $older = $this->persistPublishedArticle('Plus ancien', new \DateTimeImmutable('-2 days'));
        $recent = $this->persistPublishedArticle('Plus récent', new \DateTimeImmutable('-1 hour'));

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($recent->isShared());
        self::assertNotNull($recent->getSharedAt());
        self::assertFalse($older->isShared(), 'Un seul article doit être partagé par exécution.');
    }

    public function testSkipsArticleWhoseOnlyKeywordWasSharedWithinTheAntiRepetitionWindow(): void
    {
        $keyword = new Taxonomy('Élection', Language::FRENCH);
        $keyword->validateAutomatically();
        $this->entityManager->persist($keyword);

        $alreadyShared = $this->persistPublishedArticle('Déjà partagé', new \DateTimeImmutable('-3 hours'));
        $alreadyShared->addKeyword($keyword);
        $alreadyShared->markShared(new \DateTimeImmutable('-2 hours'));

        $sameTopic = $this->persistPublishedArticle('Même sujet', new \DateTimeImmutable('-1 hour'));
        $sameTopic->addKeyword($keyword);

        $this->entityManager->flush();

        $tester = $this->makeTester();
        $tester->execute([]);

        self::assertFalse($sameTopic->isShared(), 'Le mot-clé est encore en cooldown (moins de 6h).');
        self::assertStringContainsString('Aucun article éligible', $tester->getDisplay());
    }

    public function testDoesNotMarkArticleSharedWhenTheGraphApiFails(): void
    {
        $article = $this->persistPublishedArticle('Erreur API', new \DateTimeImmutable());

        $tester = $this->makeTester(failing: true);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($article->isShared(), 'Un échec de publication ne doit pas marquer l\'article comme partagé.');
    }

    private function persistPublishedArticle(string $title, \DateTimeImmutable $publicationDate): Article
    {
        $article = new Article($title, 'https://exemple.com/'.md5($title), $this->feed, $publicationDate);
        $article->setDescription('Résumé.');
        $article->setImage('https://exemple.com/image.jpg');
        $article->setPublish(true);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    private function makeTester(bool $failing = false): CommandTester
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => $failing
            ? new MockResponse('{"error":{"message":"Invalid OAuth access token."}}', ['http_code' => 400])
            : new MockResponse('{"id":"123_456"}', ['http_code' => 200]));

        $publisher = new FacebookPublisher($httpClient, '123456789', 'page-token', 'v21.0', 5.0);

        $command = new ShareArticlesCommand(
            $this->articleRepository,
            $publisher,
            $this->entityManager,
            new NullLogger(),
        );

        return new CommandTester($command);
    }
}

<?php

namespace App\Tests\Crawler\Command;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Crawler\Command\CrawlArticlesCommand;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Commande `app:crawler:run` : ne dispatche un {@see CrawlArticleMetaMessage} que pour les articles
 * dont le flux RSS n'a pas fourni toutes les métadonnées, et respecte `--limit` / `--dry-run`.
 */
final class CrawlArticlesCommandTest extends KernelTestCase
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

    public function testDispatchesForArticlesMissingMetadataIncludingImage(): void
    {
        $complet = $this->makeArticle('Complet', 'https://exemple.com/complet', complete: true);
        $missingImage = $this->makeArticle('Sans image', 'https://exemple.com/sans-image');
        $missingImage->setDescription('Description fournie'); // titre + description, mais pas d'image
        $missingDescription = $this->makeArticle('Sans description', 'https://exemple.com/sans-description');
        $missingDescription->setImage('https://exemple.com/image.jpg'); // image, mais description vide
        $this->entityManager->flush();

        $dispatched = [];
        $tester = $this->makeCommandTester($dispatched);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $expected = [$missingImage->getId(), $missingDescription->getId()];
        sort($expected);
        sort($dispatched);
        self::assertSame(
            $expected,
            $dispatched,
            'Une image absente comme une description absente doivent déclencher un crawl.',
        );
        self::assertNotContains($complet->getId(), $dispatched);
    }

    public function testDryRunDispatchesNothing(): void
    {
        $this->makeArticle('Incomplet', 'https://exemple.com/incomplet'); // description vide par défaut
        $this->entityManager->flush();

        $dispatched = [];
        $tester = $this->makeCommandTester($dispatched);
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $dispatched);
    }

    public function testLimitCapsTheNumberOfDispatches(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->makeArticle('Incomplet '.$i, 'https://exemple.com/incomplet-'.$i); // description vide par défaut
        }
        $this->entityManager->flush();

        $dispatched = [];
        $tester = $this->makeCommandTester($dispatched);
        $exitCode = $tester->execute(['--limit' => '2']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $dispatched);
    }

    public function testRejectsInvalidLimit(): void
    {
        $dispatched = [];
        $tester = $this->makeCommandTester($dispatched);
        $exitCode = $tester->execute(['--limit' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $dispatched);
    }

    /** @param list<int> $dispatched capture, par référence, l'identifiant de chaque article crawlé */
    private function makeCommandTester(array &$dispatched): CommandTester
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (CrawlArticleMetaMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message->articleId;

            return new Envelope($message);
        });

        $command = new CrawlArticlesCommand(
            self::getContainer()->get(ArticleRepository::class),
            $messageBus,
        );

        return new CommandTester($command);
    }

    private function makeArticle(string $title, string $url, bool $complete = false): Article
    {
        $feed = new Feed($url.'/rss.xml', Language::FRENCH);
        $article = new Article($title, $url, $feed, new \DateTimeImmutable());
        if ($complete) {
            $article->setDescription('Description complète');
            $article->setImage('https://exemple.com/image.jpg');
        }
        $this->entityManager->persist($feed);
        $this->entityManager->persist($article);

        return $article;
    }
}

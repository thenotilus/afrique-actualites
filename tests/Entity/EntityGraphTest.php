<?php

namespace App\Tests\Entity;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\News\Entity\UserNews;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Vérifie que le graphe d'entités de la phase 2 (modèle de données cible, §11.2) se comporte
 * comme documenté : une taxonomie n'est un mot-clé utilisable qu'une fois validée (§4.4/§10.4),
 * et les articles se rattachent nativement à un pays (§3.13).
 */
final class EntityGraphTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testSuggestedTaxonomyCannotBecomeAKeywordBeforeValidation(): void
    {
        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $article = new Article('Titre de test', 'https://example.com/article-1', $feed, new \DateTimeImmutable());
        $taxonomy = new Taxonomy('economie', Language::FRENCH);

        $this->expectException(\LogicException::class);
        $article->addKeyword($taxonomy);
    }

    public function testValidatedTaxonomyBecomesAUsableKeyword(): void
    {
        $admin = new User('admin@afrique-actualites.com');
        $admin->setRoles([User::ROLE_ADMIN]);

        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $article = new Article('Titre de test', 'https://example.com/article-2', $feed, new \DateTimeImmutable());
        $taxonomy = new Taxonomy('economie', Language::FRENCH);

        $taxonomy->validate($admin);
        $article->addKeyword($taxonomy);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($article);
        $this->entityManager->persist($taxonomy);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(Article::class)->find($article->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getKeywordsCount());
        self::assertSame('economie', $reloaded->getKeywords()->first()->getLabel());
    }

    public function testArticleAttachesNativelyToACountry(): void
    {
        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $article = new Article('Titre de test', 'https://example.com/article-3', $feed, new \DateTimeImmutable());
        $senegal = new Country('SN', 'Sénégal', 'Senegal');
        $senegal->setActive(true);

        $article->addCountry($senegal);

        $this->entityManager->persist($feed);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(Country::class)->findOneByCode('SN');
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getArticles());
    }

    public function testUserNewsRejectsUnvalidatedTaxonomies(): void
    {
        $userNews = new UserNews('Sénégal — Économie');
        $taxonomy = new Taxonomy('economie', Language::FRENCH);

        $this->expectException(\LogicException::class);
        $userNews->addTaxonomy($taxonomy);
    }
}

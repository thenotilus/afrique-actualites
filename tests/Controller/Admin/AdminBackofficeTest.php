<?php

namespace App\Tests\Controller\Admin;

use App\Article\Entity\Article;
use App\Crawler\Entity\CrawlAttempt;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\News\Entity\Publication;
use App\News\Entity\UserNews;
use App\Newsletter\Entity\NewsletterSubscriber;
use App\Newsletter\Entity\WeeklyNewsletter;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test de fumée du back-office (phase 3, §11.2) : vérifie que chaque écran EasyAdmin s'affiche
 * réellement (200, pas d'exception de configuration de champ/filtre) et que le circuit de
 * validation des mots-clés (§4.4) fonctionne de bout en bout via l'écran dédié.
 */
final class AdminBackofficeTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function tearDown(): void
    {
        if (null !== $this->entityManager) {
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool->dropSchema($metadata);
        }

        parent::tearDown();
    }

    public function testDashboardIsAccessibleToAdmins(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testDashboardShowsCrawlSuccessRateByDomain(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());

        $this->entityManager->persist(new CrawlAttempt('exemple.com', 'https://exemple.com/a', 'afrique-actualites-bot-1', true, 200));
        $this->entityManager->persist(new CrawlAttempt('exemple.com', 'https://exemple.com/b', 'afrique-actualites-bot-1', false, 403));
        $this->entityManager->persist(new CrawlAttempt('bloque.com', 'https://bloque.com/a', 'afrique-actualites-bot-2', false, 403));
        $this->entityManager->flush();

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $bodyText = $crawler->filter('body')->text();
        self::assertStringContainsString('exemple.com', $bodyText);
        self::assertStringContainsString('bloque.com', $bodyText);
    }

    public function testDashboardRejectsAnonymousVisitors(): void
    {
        $client = $this->bootClientWithSchema();

        $client->request('GET', '/admin');

        // Le formulaire de connexion public (avec redirection vers /login) est prévu en phase 6
        // (§11.2) avec le reste des pages publiques ; en son absence, Symfony renvoie 401 plutôt
        // que de rediriger vers un point d'entrée d'authentification qui n'existe pas encore.
        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('crudControllerProvider')]
    public function testCrudIndexPageRenders(string $controllerFqcn): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());
        $this->seedSampleData();

        $client->request('GET', '/admin?crudControllerFqcn='.urlencode($controllerFqcn));

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{0: string}> */
    public static function crudControllerProvider(): iterable
    {
        yield 'Feed' => [\App\Controller\Admin\FeedCrudController::class];
        yield 'Article' => [\App\Controller\Admin\ArticleCrudController::class];
        yield 'Taxonomy' => [\App\Controller\Admin\TaxonomyCrudController::class];
        yield 'Country' => [\App\Controller\Admin\CountryCrudController::class];
        yield 'UserNews' => [\App\Controller\Admin\UserNewsCrudController::class];
        yield 'Publication' => [\App\Controller\Admin\PublicationCrudController::class];
        yield 'User' => [\App\Controller\Admin\UserCrudController::class];
        yield 'NewsletterSubscriber' => [\App\Controller\Admin\NewsletterSubscriberCrudController::class];
        yield 'WeeklyNewsletter' => [\App\Controller\Admin\WeeklyNewsletterCrudController::class];
        yield 'CrawlAttempt' => [\App\Controller\Admin\CrawlAttemptCrudController::class];
    }

    public function testValidatingASuggestionFromTheAdminScreenMakesItAKeyword(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());

        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $taxonomy = new Taxonomy('gouvernance', Language::FRENCH);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($taxonomy);
        $this->entityManager->flush();
        $taxonomyId = $taxonomy->getId();

        $crawler = $client->request('GET', '/admin?crudControllerFqcn='.urlencode(\App\Controller\Admin\TaxonomyCrudController::class));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('gouvernance', $crawler->filter('body')->text());

        // Simule le clic sur l'action "Valider" (formulaire POST généré par EasyAdmin) : on
        // reproduit l'URL exacte qu'EasyAdmin génère plutôt que de la deviner.
        $validateUrl = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\TaxonomyCrudController::class)
            ->setAction('validate')
            ->setEntityId($taxonomyId)
            ->generateUrl();

        $client->request('POST', $validateUrl);

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Taxonomy::class)->find($taxonomyId);
        self::assertSame(TaxonomyStatus::VALIDATED, $reloaded->getStatus());
    }

    private function bootClientWithSchema(): KernelBrowser
    {
        $client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        return $client;
    }

    private function createAdmin(): User
    {
        $admin = new User('admin@afrique-actualites.com');
        $admin->setRoles([User::ROLE_ADMIN]);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    private function seedSampleData(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy([]);

        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $feed->setLabel('Exemple');
        $this->entityManager->persist($feed);

        $article = new Article('Titre', 'https://example.com/article', $feed, new \DateTimeImmutable());
        $this->entityManager->persist($article);

        $taxonomy = new Taxonomy('economie', Language::FRENCH);
        $taxonomy->validate($admin);
        $article->addKeyword($taxonomy);
        $this->entityManager->persist($taxonomy);

        $country = new Country('SN', 'Sénégal', 'Senegal');
        $country->setActive(true);
        $article->addCountry($country);
        $this->entityManager->persist($country);

        $userNews = new UserNews('Sénégal — Économie');
        $userNews->addTaxonomy($taxonomy);
        $this->entityManager->persist($userNews);

        $publication = new Publication('Encart', '<p>Contenu</p>');
        $this->entityManager->persist($publication);

        $subscriber = new NewsletterSubscriber('lecteur@example.com');
        $this->entityManager->persist($subscriber);

        $newsletter = new WeeklyNewsletter('Actu de la semaine');
        $newsletter->addKeyword($taxonomy);
        $newsletter->addArticle($article);
        $this->entityManager->persist($newsletter);

        $crawlAttempt = new CrawlAttempt('example.com', 'https://example.com/article', 'afrique-actualites-bot-1', true, 200);
        $this->entityManager->persist($crawlAttempt);

        $this->entityManager->flush();
    }
}

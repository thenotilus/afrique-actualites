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

        // Le formulaire de connexion public existe depuis la phase 6 (§11.2) : un visiteur
        // anonyme est redirigé vers lui plutôt que de recevoir un 401 brut (comportement de la
        // phase 3, tant qu'aucun point d'entrée d'authentification n'existait encore).
        self::assertResponseRedirects('/fr/connexion');
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

    public function testArticleEditAndDetailPagesRender(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());
        $this->seedSampleData();

        $article = $this->entityManager->getRepository(Article::class)->findOneBy([]);
        $article->setImage('https://exemple.com/une.jpg');
        $this->entityManager->flush();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);

        // Page d'édition : c'est ici que le champ `image` mal configuré (ImageField sur un
        // formulaire, sans setUploadDir) levait une exception — le champ image n'apparaît pas
        // sur l'index, donc le smoke-test d'index ne l'attrapait pas.
        $client->request('GET', $urlGenerator
            ->setController(\App\Controller\Admin\ArticleCrudController::class)
            ->setAction('edit')
            ->setEntityId($article->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // Page de détail : l'image (URL externe) doit s'afficher sans exigence de répertoire d'upload.
        $crawler = $client->request('GET', $urlGenerator
            ->setController(\App\Controller\Admin\ArticleCrudController::class)
            ->setAction('detail')
            ->setEntityId($article->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('src="https://exemple.com/une.jpg"', $crawler->filter('body')->html(), "L'URL absolue doit être rendue telle quelle dans la balise <img>, sans préfixe.");
    }

    public function testTaxonomyDetailPageRendersStatusBadge(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());

        // Statut SUGGESTED par défaut : la fiche détail rend le champ `status` en badge — c'est là
        // que le mauvais callback de renderAsBadges (getValue() sur la valeur-chaîne) plantait ;
        // l'index ne déclenche pas ce rendu, d'où le trou dans le smoke-test.
        $taxonomy = new Taxonomy('petrole', Language::FRENCH);
        $this->entityManager->persist($taxonomy);
        $this->entityManager->flush();

        $crawler = $client->request('GET', static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\TaxonomyCrudController::class)
            ->setAction('detail')
            ->setEntityId($taxonomy->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('badge', $crawler->filter('body')->html(), 'Le statut doit être rendu comme un badge.');
    }

    public function testValidatedTaxonomyCanStillBeRejected(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());

        // Taxonomie validée par défaut (comme celles issues du pipeline) : l'action "Rejeter" doit
        // rester proposée pour un rejet a posteriori.
        $taxonomy = (new Taxonomy('petrole', Language::FRENCH))->validateAutomatically();
        $this->entityManager->persist($taxonomy);
        $this->entityManager->flush();
        $taxonomyId = $taxonomy->getId();

        $indexUrl = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\TaxonomyCrudController::class)
            ->setAction('index')
            ->generateUrl();
        $crawler = $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();

        $rejectForm = $crawler->filter('form[action*="/reject"]')->first();
        self::assertGreaterThan(0, $rejectForm->count(), 'Une taxonomie validée doit rester rejetable.');
        $client->submit($rejectForm->form());

        $this->entityManager->clear();
        self::assertSame(TaxonomyStatus::REJECTED, $this->entityManager->getRepository(Taxonomy::class)->find($taxonomyId)->getStatus());
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

        // URL d'index « propre » (/admin/taxonomy) et non l'URL legacy ?crudControllerFqcn= :
        // seule la première rend les actions de ligne (Valider/Rejeter).
        $indexUrl = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\TaxonomyCrudController::class)
            ->setAction('index')
            ->generateUrl();
        $crawler = $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('gouvernance', $crawler->filter('body')->text());

        // On soumet le <form> réellement rendu par EasyAdmin pour l'action Valider, au lieu de
        // POSTer une URL devinée : sa présence (et sa méthode POST) garantit que l'action est bien
        // rendue en formulaire et non en lien GET — sinon la route, restreinte à POST, renverrait
        // un 405 au clic (régression corrigée par renderAsForm()).
        $validateForm = $crawler->filter('form[action*="/validate"]')->first();
        self::assertGreaterThan(0, $validateForm->count(), "L'action Valider doit être rendue comme un formulaire POST.");
        self::assertSame('POST', strtoupper($validateForm->attr('method') ?? ''));
        $client->submit($validateForm->form());

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

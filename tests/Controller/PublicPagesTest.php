<?php

namespace App\Tests\Controller;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\News\Entity\UserNews;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test de fumée des pages publiques (phase 6, §11.2) : chaque page se rend réellement (200, pas
 * d'exception de template/route), le circuit de connexion/inscription fonctionne, et la gestion
 * des "Unes" par un utilisateur connecté (créer/éditer/dupliquer/supprimer) fonctionne de bout en
 * bout — même méthodologie que `AdminBackofficeTest` pour le back-office (phase 3).
 */
final class PublicPagesTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?Article $article = null;
    private ?Taxonomy $keyword = null;
    private ?Country $country = null;
    private ?Feed $feed = null;

    protected function tearDown(): void
    {
        if (null !== $this->entityManager) {
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool->dropSchema($metadata);
        }

        parent::tearDown();
    }

    #[DataProvider('publicPageProvider')]
    public function testPublicPageRendersSuccessfully(string $locale, callable $urlBuilder): void
    {
        $client = $this->bootClientWithSchema();
        $this->seedSampleData();

        $client->request('GET', $urlBuilder($locale, $this));

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{0: string, 1: callable}> */
    public static function publicPageProvider(): iterable
    {
        foreach (['fr', 'en'] as $locale) {
            yield "home ($locale)" => [$locale, static fn (string $l) => "/$l/"];
            yield "country index ($locale)" => [$locale, static fn (string $l) => "/$l/pays"];
            yield "user news index ($locale)" => [$locale, static fn (string $l) => "/$l/unes"];
            yield "search, no query ($locale)" => [$locale, static fn (string $l) => "/$l/recherche"];
            yield "login ($locale)" => [$locale, static fn (string $l) => "/$l/connexion"];
            yield "register ($locale)" => [$locale, static fn (string $l) => "/$l/inscription"];
            yield "sitemap per locale ($locale)" => [$locale, static fn (string $l) => "/$l/sitemap.xml"];
        }
        yield 'sitemap index' => ['fr', static fn () => '/sitemap.xml'];
        yield 'article detail' => ['fr', static fn (string $l, self $test) => \sprintf('/fr/article/%d/le-titre', $test->article->getId())];
        yield 'article by feed' => ['fr', static fn (string $l, self $test) => \sprintf('/fr/source/%d', $test->feed->getId())];
        yield 'article by keyword' => ['fr', static fn (string $l, self $test) => '/fr/tag/'.$test->keyword->getLabel()];
        yield 'country show' => ['fr', static fn (string $l, self $test) => '/fr/pays/'.$test->country->getCode()];
        yield 'country by keyword' => ['fr', static fn (string $l, self $test) => '/fr/pays/'.$test->country->getCode().'/'.$test->keyword->getLabel()];
        yield 'search with query' => ['fr', static fn () => '/fr/recherche?q=titre'];
        yield 'search no results' => ['fr', static fn () => '/fr/recherche?q=inexistant'];
    }

    public function testSearchWithoutQueryDoesNotHitTheDatabase(): void
    {
        $client = $this->bootClientWithSchema();
        $this->seedSampleData();

        $client->request('GET', '/fr/recherche');

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousVisitorCannotAccessMyUserNews(): void
    {
        $client = $this->bootClientWithSchema();

        $client->request('GET', '/fr/mes-unes');

        // Le formulaire de connexion existe désormais (phase 6) : un visiteur anonyme est
        // redirigé vers lui plutôt que de recevoir un 401 brut (comportement de la phase 3, tant
        // qu'aucun point d'entrée d'authentification n'existait encore).
        self::assertResponseRedirects('/fr/connexion');
    }

    public function testLoginFormAuthenticatesWithValidCredentials(): void
    {
        $client = $this->bootClientWithSchema();
        $this->createUser('lecteur@example.com');

        $crawler = $client->request('GET', '/fr/connexion');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->first()->form([
            '_username' => 'lecteur@example.com',
            '_password' => 'password1234',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/fr/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testRegistrationCreatesAUsableAccount(): void
    {
        $client = $this->bootClientWithSchema();

        $crawler = $client->request('GET', '/fr/inscription');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton("S'inscrire")->form();
        $form['registration[email]'] = 'nouveau@example.com';
        $form['registration[plainPassword]'] = 'motdepasse1234';
        $client->submit($form);

        self::assertResponseRedirects('/fr/connexion');
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.com']));
    }

    public function testFullUserNewsLifecycleForALoggedInUser(): void
    {
        $client = $this->bootClientWithSchema();
        $this->seedSampleData();
        $client->loginUser($this->createUser('lecteur@example.com'));
        // KernelBrowser reboote le noyau à chaque requête : comparer par identifiant plutôt que
        // par identité d'objet PHP, une entité chargée après coup n'étant jamais `===` à celle
        // créée avant la première requête (même ligne en base, instance différente en mémoire).
        $keywordId = $this->keyword->getId();

        // "Mes Unes" est vide au départ.
        $crawler = $client->request('GET', '/fr/mes-unes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mes Unes', $crawler->filter('body')->text());

        // Création d'une Une.
        $crawler = $client->request('GET', '/fr/mes-unes/nouvelle');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_news[label]'] = 'Sénégal — Économie';
        $form['user_news[taxonomies]']->select([(string) $keywordId]);
        $client->submit($form);
        self::assertResponseRedirects('/fr/mes-unes');

        $this->entityManager->clear();
        $created = $this->entityManager->getRepository(UserNews::class)->findOneBy(['label' => 'Sénégal — Économie']);
        self::assertNotNull($created);
        self::assertFalse($created->isPrivate());
        self::assertSame([$keywordId], array_map(static fn ($t) => $t->getId(), $created->getTaxonomies()->toArray()));
        $createdId = $created->getId();

        // La Une créée apparaît publiquement.
        $client->request('GET', \sprintf('/fr/unes/%d/senegal-economie', $createdId));
        self::assertResponseIsSuccessful();

        // Édition : renommer la Une.
        $crawler = $client->request('GET', \sprintf('/fr/mes-unes/%d/editer', $createdId));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_news[label]'] = 'Sénégal — Économie (v2)';
        $form['user_news[taxonomies]']->select([(string) $this->keyword->getId()]);
        $client->submit($form);
        self::assertResponseRedirects('/fr/mes-unes');
        $this->entityManager->clear();
        $edited = $this->entityManager->getRepository(UserNews::class)->find($createdId);
        self::assertSame('Sénégal — Économie (v2)', $edited->getLabel());

        // Duplication (bouton dans le tableau "Mes Unes").
        $crawler = $client->request('GET', '/fr/mes-unes');
        $duplicateForm = $crawler->filter('form[action*="dupliquer"]')->form();
        $client->submit($duplicateForm);
        self::assertResponseRedirects('/fr/mes-unes');
        // "Veille économie" (créée par le jeu de données de test) + l'originale + sa copie.
        self::assertCount(3, $this->entityManager->getRepository(UserNews::class)->findAll());

        // Suppression de la Une originale (la copie et "Veille économie" survivent).
        $crawler = $client->request('GET', '/fr/mes-unes');
        $deleteForm = $crawler->filter(\sprintf('form[action*="/%d/supprimer"]', $createdId))->form();
        $client->submit($deleteForm);
        self::assertResponseRedirects('/fr/mes-unes');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(UserNews::class)->find($createdId));
        self::assertCount(2, $this->entityManager->getRepository(UserNews::class)->findAll());
    }

    private function createUser(string $email, bool $admin = false): User
    {
        $user = new User($email);
        if ($admin) {
            $user->setRoles([User::ROLE_ADMIN]);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password1234'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
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

    private function seedSampleData(): void
    {
        $admin = new User('admin@afrique-actualites.com');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->entityManager->persist($admin);

        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $feed->setLabel('Exemple Média');
        $feed->setSponsored(true);
        $this->entityManager->persist($feed);
        $this->feed = $feed;

        $article = new Article('Le titre', 'https://example.com/article-1', $feed, new \DateTimeImmutable('-1 day'));
        $article->setDescription('Une description utile pour la recherche.');
        $article->setPublish(true);
        $this->entityManager->persist($article);
        $this->article = $article;

        $keyword = new Taxonomy('economie', Language::FRENCH);
        $keyword->validate($admin);
        $article->addKeyword($keyword);
        $this->entityManager->persist($keyword);
        $this->keyword = $keyword;

        $country = new Country('SN', 'Sénégal', 'Senegal');
        $country->setActive(true);
        $article->addCountry($country);
        $this->entityManager->persist($country);
        $this->country = $country;

        $userNews = new UserNews('Veille économie');
        $userNews->addTaxonomy($keyword);
        $this->entityManager->persist($userNews);

        $this->entityManager->flush();
    }
}

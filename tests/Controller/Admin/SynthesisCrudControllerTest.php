<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\SynthesisCrudController;
use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;
use App\Synthesis\Entity\Synthesis;
use App\Synthesis\Enum\SynthesisStatus;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran de relecture des synthèses hebdomadaires (§ "Workflow de validation") — même méthodologie
 * que `AdminBackofficeTest` (index rendu, actions Publier/Rejeter soumises via le vrai `<form>`
 * rendu par EasyAdmin, pas une URL devinée).
 */
final class SynthesisCrudControllerTest extends WebTestCase
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

    public function testIndexPageRendersWithADraftSynthesis(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());
        $this->makeDraftSynthesis();

        // Réchauffe le cache de routage d'EasyAdmin (AdminControllerRegistry) : sans une première
        // requête HTTP vers le back-office, AdminUrlGenerator échoue à résoudre le dashboard
        // unique de l'application quand on l'appelle directement en test.
        $client->request('GET', '/admin');

        $crawler = $client->request('GET', static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SynthesisCrudController::class)
            ->setAction('index')
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Titre brouillon', $crawler->filter('body')->text());
    }

    public function testPublishingADraftFromTheAdminScreenChangesItsStatus(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());
        $synthesisId = $this->makeDraftSynthesis()->getId();

        $client->request('GET', '/admin');
        $indexUrl = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SynthesisCrudController::class)
            ->setAction('index')
            ->generateUrl();
        $crawler = $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();

        $publishForm = $crawler->filter('form[action*="/publish"]')->first();
        self::assertGreaterThan(0, $publishForm->count(), 'L\'action Publier doit être rendue comme un formulaire POST.');
        self::assertSame('POST', strtoupper($publishForm->attr('method') ?? ''));
        $client->submit($publishForm->form());

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Synthesis::class)->find($synthesisId);
        self::assertSame(SynthesisStatus::PUBLISHED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getReviewedBy());
    }

    public function testRejectingADraftFromTheAdminScreenChangesItsStatus(): void
    {
        $client = $this->bootClientWithSchema();
        $client->loginUser($this->createAdmin());
        $synthesisId = $this->makeDraftSynthesis()->getId();

        $client->request('GET', '/admin');
        $indexUrl = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SynthesisCrudController::class)
            ->setAction('index')
            ->generateUrl();
        $crawler = $client->request('GET', $indexUrl);

        $rejectForm = $crawler->filter('form[action*="/reject"]')->first();
        self::assertGreaterThan(0, $rejectForm->count(), 'L\'action Rejeter doit être rendue comme un formulaire POST.');
        $client->submit($rejectForm->form());

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Synthesis::class)->find($synthesisId);
        self::assertSame(SynthesisStatus::REJECTED, $reloaded->getStatus());
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

    private function makeDraftSynthesis(): Synthesis
    {
        $country = new Country('SN', 'Sénégal', 'Senegal');
        $country->setActive(true);
        $this->entityManager->persist($country);

        $weekStart = new \DateTimeImmutable('2026-08-24');
        $synthesis = Synthesis::forCountry($country, Language::FRENCH, $weekStart, $weekStart->modify('+7 days'));
        $synthesis->setContent('Titre brouillon', 'Chapô brouillon.', '<h3>Économie</h3><p>Corps.</p>');
        $this->entityManager->persist($synthesis);
        $this->entityManager->flush();

        return $synthesis;
    }
}

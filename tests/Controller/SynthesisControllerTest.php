<?php

namespace App\Tests\Controller;

use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Shared\ValueObject\Language;
use App\Synthesis\Entity\Synthesis;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique d'une synthèse hebdomadaire publiée (§ "Route(s) et vue(s) publiques") — même
 * méthodologie que `PublicPagesTest` : rendu réel (200), et garde-fous sur ce qui ne doit pas être
 * accessible (brouillon, semaine inexistante, région non repliée).
 */
final class SynthesisControllerTest extends WebTestCase
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

    public function testPublishedCountrySynthesisRenders(): void
    {
        $client = $this->bootClientWithSchema();
        $country = $this->makeCountry('SN', 'Sénégal', 'Senegal');
        $synthesis = $this->makePublishedSynthesis($country, null);

        $crawler = $client->request('GET', sprintf('/fr/synthese/sn/%s', $synthesis->getWeekStart()->format('Y-m-d')));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Titre de synthèse', $crawler->filter('body')->text());
    }

    public function testPublishedRegionalSynthesisRenders(): void
    {
        $client = $this->bootClientWithSchema();
        $synthesis = $this->makePublishedSynthesis(null, Region::WEST_AFRICA);

        $client->request('GET', sprintf('/fr/synthese/afrique-ouest/%s', $synthesis->getWeekStart()->format('Y-m-d')));

        self::assertResponseIsSuccessful();
    }

    public function testDraftSynthesisIsNotPubliclyAccessible(): void
    {
        $client = $this->bootClientWithSchema();
        $country = $this->makeCountry('SN', 'Sénégal', 'Senegal');
        $weekStart = new \DateTimeImmutable('2026-08-24');
        $synthesis = Synthesis::forCountry($country, Language::FRENCH, $weekStart, $weekStart->modify('+7 days'));
        $synthesis->setContent('Titre', 'Chapô', '<p>Corps</p>');
        $this->entityManager->persist($synthesis);
        $this->entityManager->flush();

        $client->request('GET', sprintf('/fr/synthese/sn/%s', $weekStart->format('Y-m-d')));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownScopeReturnsNotFound(): void
    {
        $client = $this->bootClientWithSchema();

        $client->request('GET', '/fr/synthese/xx/2026-08-24');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCountryPageLinksToItsPublishedSyntheses(): void
    {
        $client = $this->bootClientWithSchema();
        $country = $this->makeCountry('SN', 'Sénégal', 'Senegal');
        $synthesis = $this->makePublishedSynthesis($country, null);

        $crawler = $client->request('GET', '/fr/pays/SN');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter(sprintf('a[href="/fr/synthese/sn/%s"]', $synthesis->getWeekStart()->format('Y-m-d')));
        self::assertGreaterThan(0, $link->count(), 'La page pays doit renvoyer vers la synthèse publiée.');
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

    private function makeCountry(string $code, string $nameFr, string $nameEn): Country
    {
        $country = new Country($code, $nameFr, $nameEn);
        $country->setActive(true);
        $country->setRegion(Region::WEST_AFRICA);
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        return $country;
    }

    private function makePublishedSynthesis(?Country $country, ?Region $region): Synthesis
    {
        $admin = new User('admin@afrique-actualites.com');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->entityManager->persist($admin);

        $weekStart = new \DateTimeImmutable('2026-08-24');
        $weekEnd = $weekStart->modify('+7 days');
        $synthesis = null !== $country
            ? Synthesis::forCountry($country, Language::FRENCH, $weekStart, $weekEnd)
            : Synthesis::forRegion($region, Language::FRENCH, $weekStart, $weekEnd);
        $synthesis->setContent('Titre de synthèse', 'Chapô de synthèse.', '<h3>Économie</h3><p>Corps.</p>');
        $synthesis->publish($admin);

        $this->entityManager->persist($synthesis);
        $this->entityManager->flush();

        return $synthesis;
    }
}

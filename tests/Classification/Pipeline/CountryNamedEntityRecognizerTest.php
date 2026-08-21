<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\CountryNamedEntityRecognizer;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le référentiel de pays vit en base (contrairement au reste du pipeline, purement en mémoire),
 * d'où un test noyau plutôt qu'un test unitaire isolé — voir aussi EntityGraphTest.
 */
final class CountryNamedEntityRecognizerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CountryNamedEntityRecognizer $recognizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        $senegal = new Country('SN', 'Sénégal', 'Senegal');
        $senegal->setActive(true);
        $nigeria = new Country('NG', 'Nigeria', 'Nigeria');
        $nigeria->setActive(true);
        $mali = new Country('ML', 'Mali', 'Mali');
        $mali->setActive(false);

        $this->entityManager->persist($senegal);
        $this->entityManager->persist($nigeria);
        $this->entityManager->persist($mali);
        $this->entityManager->flush();

        $this->recognizer = new CountryNamedEntityRecognizer(
            $this->entityManager->getRepository(Country::class),
            new UnicodeNormalizer(),
        );
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testRecognizesAnActiveCountryMentionedInFrench(): void
    {
        $normalizer = new UnicodeNormalizer();
        $text = $normalizer->normalize("L'élection présidentielle au Sénégal a mobilisé les foules");

        $countries = $this->recognizer->recognizeCountries($text, Language::FRENCH);

        self::assertCount(1, $countries);
        self::assertSame('SN', $countries[0]->getCode());
    }

    public function testRecognizesAnActiveCountryMentionedInEnglish(): void
    {
        $normalizer = new UnicodeNormalizer();
        $text = $normalizer->normalize('Nigeria announces new economic reforms');

        $countries = $this->recognizer->recognizeCountries($text, Language::ENGLISH);

        self::assertCount(1, $countries);
        self::assertSame('NG', $countries[0]->getCode());
    }

    public function testDoesNotRecognizeAnInactiveCountry(): void
    {
        $normalizer = new UnicodeNormalizer();
        $text = $normalizer->normalize('Le Mali traverse une periode de transition');

        self::assertSame([], $this->recognizer->recognizeCountries($text, Language::FRENCH));
    }

    public function testReturnsNoMatchWhenNoCountryIsMentioned(): void
    {
        $normalizer = new UnicodeNormalizer();
        $text = $normalizer->normalize('Le sommet international se tiendra la semaine prochaine');

        self::assertSame([], $this->recognizer->recognizeCountries($text, Language::FRENCH));
    }
}

<?php

namespace App\Classification\Pipeline;

use App\Geography\Entity\Country;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;

/**
 * Détecte les pays mentionnés en cherchant leur nom officiel (normalisé) comme sous-chaîne du
 * texte normalisé de l'article.
 *
 * Limite connue et assumée : seul le nom officiel est reconnu ("Sénégal"), pas les adjectifs ou
 * gentilés ("sénégalais"), très fréquents dans les titres de presse. Un référentiel de gentilés
 * par pays serait un enrichissement naturel de ce référentiel, maintenu depuis le back-office
 * comme évoqué en §10.1.6, mais nécessite des données supplémentaires non disponibles à ce stade.
 */
final class CountryNamedEntityRecognizer implements NamedEntityRecognizerInterface
{
    /** @var list<Country>|null */
    private ?array $activeCountries = null;

    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    public function recognizeCountries(string $normalizedText, Language $language): array
    {
        $paddedText = ' '.$normalizedText.' ';
        $matches = [];

        foreach ($this->getActiveCountries() as $country) {
            $name = Language::ENGLISH === $language ? $country->getNameEn() : $country->getNameFr();
            $normalizedName = ' '.$this->normalizer->normalize($name).' ';

            if ('' !== trim($normalizedName) && str_contains($paddedText, $normalizedName)) {
                $matches[] = $country;
            }
        }

        return $matches;
    }

    /** @return list<Country> */
    private function getActiveCountries(): array
    {
        if (null === $this->activeCountries) {
            $this->activeCountries = $this->countryRepository->findBy(['active' => true]);
        }

        return $this->activeCountries;
    }
}

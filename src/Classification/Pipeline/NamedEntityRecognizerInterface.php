<?php

namespace App\Classification\Pipeline;

use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;

/**
 * Reconnaissance d'entités nommées fortes (§10.1.6) — au premier chef les pays, qui alimentent
 * directement le rattachement natif `Article ↔ Country` (§3.13), sans passer par le circuit de
 * suggestion/validation des mots-clés puisqu'il s'agit d'un référentiel fermé, pas de texte libre.
 */
interface NamedEntityRecognizerInterface
{
    /**
     * @return list<Country> Pays mentionnés dans le texte normalisé, sans doublon
     */
    public function recognizeCountries(string $normalizedText, Language $language): array;
}

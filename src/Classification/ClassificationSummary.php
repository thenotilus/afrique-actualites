<?php

namespace App\Classification;

/**
 * Résultat d'un passage de classification sur un lot d'articles — utile pour le suivi/logging
 * d'un futur worker continu (§6, §9.1) sans avoir à ré-interroger la base.
 */
final readonly class ClassificationSummary
{
    public function __construct(
        public int $articlesProcessed,
        public int $suggestionsCreated,
        public int $suggestionsReinforced,
        public int $countriesAttached,
    ) {
    }
}

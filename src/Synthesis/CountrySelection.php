<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Geography\Entity\Country;

/** Articles d'un pays retenus pour une synthèse hebdomadaire dédiée (§ "Sélection et regroupement"). */
final readonly class CountrySelection
{
    /** @param list<Article> $articles */
    public function __construct(
        public Country $country,
        public array $articles,
    ) {
    }
}

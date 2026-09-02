<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Geography\Enum\Region;

/**
 * Articles agrégés d'une région pour une synthèse hebdomadaire de repli (§ "Sélection et
 * regroupement") : les articles des pays de cette région n'ayant pas, chacun, atteint le seuil
 * minimal d'articles pour une synthèse dédiée.
 */
final readonly class RegionSelection
{
    /** @param list<Article> $articles */
    public function __construct(
        public Region $region,
        public array $articles,
    ) {
    }
}

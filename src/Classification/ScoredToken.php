<?php

namespace App\Classification;

/**
 * Résultat du scoring d'un token sur un lot d'articles (§10.1.7) : combien d'articles distincts
 * le contiennent (`documentFrequency`) et son poids cumulé (`totalWeight`, qui reflète sa
 * présence en titre — poids fort — ou en description — poids faible, §10.1.1).
 */
final readonly class ScoredToken
{
    public function __construct(
        public string $token,
        public int $documentFrequency,
        public int $totalWeight,
    ) {
    }
}

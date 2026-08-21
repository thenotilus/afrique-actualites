<?php

namespace App\Classification\Pipeline;

use App\Classification\ScoredToken;

/**
 * Calcule un score par token sur un lot d'articles, plutôt que le seuil binaire de l'ancienne
 * application (§4.3 L6/L7, §10.1.7).
 */
interface ScorerInterface
{
    /**
     * @param array<int, array<string, int>> $tokenWeightsByArticleId Poids par token, par identifiant d'article
     *
     * @return array<string, ScoredToken> Indexé par token
     */
    public function score(array $tokenWeightsByArticleId): array;
}

<?php

namespace App\Classification\Pipeline;

use App\Classification\ScoredToken;

/**
 * Scoring par fréquence documentaire pondérée (§10.1.7) : combien d'articles distincts du lot
 * contiennent le token (`documentFrequency`), et son poids cumulé (`totalWeight`, plus élevé si
 * le token apparaît en titre plutôt qu'en description, §10.1.1).
 *
 * Remplace le simple comptage brut d'occurrences de l'ancienne application (`array_count_values`
 * sur les identifiants de taxonomie, §4.2 étape 6) par un score exploitable pour alimenter le
 * champ `Taxonomy::$mark`, jusqu'ici jamais lu (§4.3 L11).
 */
final class BatchFrequencyScorer implements ScorerInterface
{
    public function score(array $tokenWeightsByArticleId): array
    {
        $documentFrequency = [];
        $totalWeight = [];

        foreach ($tokenWeightsByArticleId as $tokenWeights) {
            foreach ($tokenWeights as $token => $weight) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
                $totalWeight[$token] = ($totalWeight[$token] ?? 0) + $weight;
            }
        }

        $scores = [];
        foreach ($documentFrequency as $token => $count) {
            $scores[$token] = new ScoredToken($token, $count, $totalWeight[$token]);
        }

        return $scores;
    }
}

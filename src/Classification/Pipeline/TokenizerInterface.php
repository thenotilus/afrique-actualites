<?php

namespace App\Classification\Pipeline;

/**
 * Découpage d'un texte normalisé en tokens (§10.1.3).
 *
 * Remplace `str_word_count()` de l'ancienne application, conçue pour l'ASCII et peu fiable en
 * UTF-8 (cf. §4.3, étape 2 du pipeline analysé).
 */
interface TokenizerInterface
{
    /** @return list<string> */
    public function tokenize(string $normalizedText): array;
}

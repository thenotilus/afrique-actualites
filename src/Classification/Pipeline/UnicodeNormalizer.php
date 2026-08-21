<?php

namespace App\Classification\Pipeline;

/**
 * Normalisation Unicode générique (forme NFKD + suppression des diacritiques), compatible tout
 * alphabet latin étendu — pas seulement l'Europe de l'Ouest comme l'ancienne table de
 * translittération codée en dur (§4.3 L4, §10.1.2).
 */
final class UnicodeNormalizer implements NormalizerInterface
{
    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Décompose "é" en "e" + accent combinant, puis supprime les marques combinantes
        // (catégorie Unicode Mn). Fonctionne pour tout alphabet latin étendu, contrairement à la
        // table de correspondance Latin-1 codée en dur de l'ancienne application.
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        $withoutDiacritics = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        // Réduit toute ponctuation/symbole à un espace (préserve lettres, chiffres, espaces).
        $withoutPunctuation = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $withoutDiacritics) ?? $withoutDiacritics;

        return trim(preg_replace('/\s+/u', ' ', $withoutPunctuation) ?? $withoutPunctuation);
    }
}

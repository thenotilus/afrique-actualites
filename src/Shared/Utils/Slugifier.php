<?php

namespace App\Shared\Utils;

/**
 * Génère un fragment d'URL lisible à partir d'un titre (§3.10, extension Twig `slugify` de
 * l'ancienne application). Purement cosmétique côté SEO : les routes qui l'utilisent
 * (`/article/{id}/{slug}`) identifient réellement la ressource par son identifiant, pas par ce
 * fragment — pas de canonicalisation/redirection à gérer si le titre change après coup.
 */
final class Slugifier
{
    public static function slugify(string $text): string
    {
        $normalized = \Normalizer::normalize(mb_strtolower(trim($text)), \Normalizer::FORM_D) ?: $text;
        $withoutDiacritics = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $withoutDiacritics) ?? $withoutDiacritics;

        return trim($slug, '-');
    }
}

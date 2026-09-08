<?php

namespace App\Feed\ValueObject;

/**
 * Résultat du parsing d'un document sitemap : soit un `<urlset>` (feuille, `entries` peuplé), soit
 * un `<sitemapindex>` (nœud, `childSitemaps` peuplé des URLs à suivre) — jamais les deux, un
 * document sitemap n'étant que l'un ou l'autre (§ sitemaps.org).
 */
final readonly class ParsedSitemap
{
    /**
     * @param list<SitemapEntry> $entries
     * @param list<string>       $childSitemaps
     */
    public function __construct(
        public array $entries,
        public array $childSitemaps,
    ) {
    }
}

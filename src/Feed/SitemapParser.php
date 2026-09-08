<?php

namespace App\Feed;

use App\Feed\Exception\SitemapParseException;
use App\Feed\ValueObject\ParsedSitemap;
use App\Feed\ValueObject\SitemapEntry;

/**
 * Parseur de documents sitemap (protocole sitemaps.org) : `<sitemapindex>` (nœud, référence
 * d'autres sitemaps) et `<urlset>` (feuille, liste d'URLs). Sert le backfill historique (§6bis,
 * {@see FeedBackfiller}) : à la différence du flux RSS/Atom qui n'expose que les entrées
 * récentes, un sitemap complet est la seule source générique permettant de retrouver les articles
 * plus anciens d'un média, sans scraper spécifiquement chaque site.
 *
 * Même tolérance de principe que {@see FeedParser} : une entrée `<url>` sans `<loc>` exploitable
 * est ignorée, une `<lastmod>` illisible retombe sur une date inconnue (`null`, jamais l'instant
 * courant — contrairement au flux RSS, se tromper ici fausserait le filtrage par fenêtre
 * temporelle du backfill), mais un document ni sitemapindex ni urlset lève
 * {@see SitemapParseException}.
 */
final class SitemapParser
{
    public function parse(string $xml): ParsedSitemap
    {
        $xml = trim($xml);
        if ('' === $xml) {
            throw new SitemapParseException('Sitemap vide.');
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (false === $root) {
            throw new SitemapParseException('Sitemap XML invalide.');
        }

        return match ($root->getName()) {
            'sitemapindex' => new ParsedSitemap([], $this->extractLocs($root->sitemap)),
            'urlset' => new ParsedSitemap($this->extractEntries($root->url), []),
            default => throw new SitemapParseException(sprintf('Format de sitemap non reconnu (racine "%s").', $root->getName())),
        };
    }

    /** @return list<string> */
    private function extractLocs(\SimpleXMLElement $nodes): array
    {
        $locs = [];
        foreach ($nodes as $node) {
            $loc = $this->normalize((string) $node->loc);
            if (null !== $loc) {
                $locs[] = $loc;
            }
        }

        return $locs;
    }

    /** @return list<SitemapEntry> */
    private function extractEntries(\SimpleXMLElement $nodes): array
    {
        $entries = [];
        foreach ($nodes as $node) {
            $loc = $this->normalize((string) $node->loc);
            if (null === $loc) {
                continue;
            }
            $entries[] = new SitemapEntry($loc, $this->parseLastmod((string) $node->lastmod));
        }

        return $entries;
    }

    private function parseLastmod(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function normalize(string $value): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $trimmed ? null : $trimmed;
    }
}

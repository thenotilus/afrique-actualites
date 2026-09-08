<?php

namespace App\Feed\ValueObject;

/**
 * Une entrée `<url>` d'un sitemap (`sitemap.xml`), telle que produite par
 * {@see \App\Feed\SitemapParser}. `lastmod` est absent d'une part significative des sitemaps en
 * pratique : `null` signifie « date inconnue », pas « hors fenêtre » — c'est à l'appelant
 * ({@see \App\Feed\FeedBackfiller}) de décider comment traiter l'absence de date.
 */
final readonly class SitemapEntry
{
    public function __construct(
        public string $url,
        public ?\DateTimeImmutable $lastmod,
    ) {
    }
}

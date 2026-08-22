<?php

namespace App\Feed\ValueObject;

/**
 * Une entrée normalisée d'un flux RSS/Atom, telle que produite par {@see \App\Feed\FeedParser}
 * avant sa transformation en {@see \App\Article\Entity\Article}. Les métadonnées absentes du flux
 * (`description` vide, `imageUrl` nul) seront éventuellement complétées par le crawl de repli
 * (§9.4) après création de l'article.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
        public ?string $imageUrl,
        public \DateTimeImmutable $publishedAt,
    ) {
    }
}

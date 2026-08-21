<?php

namespace App\Crawler;

/**
 * Métadonnées extraites d'une page HTML par un `MetaExtractorInterface` (§9.4). Chaque champ est
 * indépendamment nullable : une page peut fournir une image sans description, par exemple.
 */
final readonly class CrawledMeta
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $image = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->title && null === $this->description && null === $this->image;
    }
}

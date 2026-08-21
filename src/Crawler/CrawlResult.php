<?php

namespace App\Crawler;

/**
 * Résultat d'un crawl de repli sur une URL d'article (§9.4) : soit des métadonnées exploitables
 * obtenues par l'un des profils du pool, soit un échec après épuisement de celui-ci.
 */
final readonly class CrawlResult
{
    private function __construct(
        public bool $success,
        public ?CrawledMeta $meta,
        public ?string $botProfileName,
    ) {
    }

    public static function success(CrawledMeta $meta, string $botProfileName): self
    {
        return new self(true, $meta, $botProfileName);
    }

    public static function failure(): self
    {
        return new self(false, null, null);
    }
}

<?php

namespace App\Feed\ValueObject;

/**
 * Résultat du parsing d'un flux : son titre de canal (utilisé pour renseigner le libellé d'un
 * `Feed` encore anonyme, cf. {@see \App\Feed\Entity\Feed::$label}) et ses entrées.
 */
final readonly class ParsedFeed
{
    /** @param list<FeedItem> $items */
    public function __construct(
        public ?string $title,
        public array $items,
    ) {
    }
}

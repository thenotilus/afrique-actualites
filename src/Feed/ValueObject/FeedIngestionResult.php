<?php

namespace App\Feed\ValueObject;

use App\Article\Entity\Article;

/**
 * Bilan de l'ingestion d'un flux : ce qui a été créé, ignoré (déjà en base) ou écarté (entrée
 * inexploitable). {@see \App\Feed\Command\IngestFeedsCommand} agrège ces bilans et réutilise
 * `createdArticles` pour lancer la classification sur l'ensemble du lot.
 */
final readonly class FeedIngestionResult
{
    /** @param list<Article> $createdArticles */
    public function __construct(
        public array $createdArticles,
        public int $itemsSeen,
        public int $skipped,
    ) {
    }

    public function createdCount(): int
    {
        return \count($this->createdArticles);
    }
}

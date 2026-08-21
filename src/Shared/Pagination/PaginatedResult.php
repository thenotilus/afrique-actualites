<?php

namespace App\Shared\Pagination;

/**
 * Résultat paginé générique (§3.13 "archives paginées", §3.5 recherche, §3.4 "Unes") : évite de
 * répéter le calcul du nombre de pages/navigation précédent-suivant dans chaque contrôleur ou
 * template public.
 */
final readonly class PaginatedResult
{
    /** @param list<mixed> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $totalItems,
    ) {
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->totalItems / $this->limit));
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }
}

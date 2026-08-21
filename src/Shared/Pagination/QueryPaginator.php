<?php

namespace App\Shared\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Pagination générique par-dessus le composant Doctrine ORM (pas de bundle de pagination
 * supplémentaire, cf. §9.1) : suffisant pour les listes publiques (§3.13, §3.5, §3.4) sans
 * ajouter de dépendance dédiée.
 */
final class QueryPaginator
{
    public const DEFAULT_LIMIT = 20;

    public function paginate(QueryBuilder $queryBuilder, int $page, int $limit = self::DEFAULT_LIMIT): PaginatedResult
    {
        $page = max(1, $page);

        $query = $queryBuilder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        // false : nos requêtes filtrent des collections en DQL via MEMBER OF plutôt que par
        // JOIN + addSelect, donc aucune ligne dupliquée à dé-doublonner pour le comptage.
        $paginator = new DoctrinePaginator($query, fetchJoinCollection: false);

        return new PaginatedResult(
            iterator_to_array($paginator->getIterator()),
            $page,
            $limit,
            \count($paginator),
        );
    }
}

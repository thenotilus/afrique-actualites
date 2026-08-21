<?php

namespace App\Crawler\Repository;

use App\Crawler\Entity\CrawlAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrawlAttempt>
 */
class CrawlAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrawlAttempt::class);
    }

    /**
     * Taux de succès par domaine (§9.4, observabilité), pour le tableau de bord du back-office :
     * signale les domaines qui bloquent systématiquement tous les profils du pool.
     *
     * @return list<array{domain: string, total: int, success: int}>
     */
    public function successRateByDomain(): array
    {
        /** @var list<array{domain: string, success: bool, c: int}> $rows */
        $rows = $this->createQueryBuilder('attempt')
            ->select('attempt.domain AS domain', 'attempt.success AS success', 'COUNT(attempt.id) AS c')
            ->groupBy('attempt.domain', 'attempt.success')
            ->getQuery()
            ->getArrayResult();

        $byDomain = [];
        foreach ($rows as $row) {
            $domain = $row['domain'];
            $byDomain[$domain] ??= ['domain' => $domain, 'total' => 0, 'success' => 0];
            $byDomain[$domain]['total'] += (int) $row['c'];
            if ($row['success']) {
                $byDomain[$domain]['success'] += (int) $row['c'];
            }
        }

        usort($byDomain, static fn (array $a, array $b) => $a['domain'] <=> $b['domain']);

        return array_values($byDomain);
    }
}

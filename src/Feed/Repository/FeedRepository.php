<?php

namespace App\Feed\Repository;

use App\Feed\Entity\Feed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feed>
 */
class FeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feed::class);
    }

    /** @return list<Feed> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true]);
    }
}

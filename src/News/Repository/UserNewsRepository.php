<?php

namespace App\News\Repository;

use App\News\Entity\UserNews;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserNews>
 */
class UserNewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserNews::class);
    }

    /** @return list<UserNews> */
    public function findPublic(): array
    {
        return $this->findBy(['private' => false], ['label' => 'ASC']);
    }
}

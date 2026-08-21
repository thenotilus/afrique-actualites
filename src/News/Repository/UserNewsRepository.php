<?php

namespace App\News\Repository;

use App\News\Entity\UserNews;
use App\User\Entity\User;
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

    /** Vue "mes Unes" de l'espace personnel (prompt de conception parcours 1.G). @return list<UserNews> */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['modifiedAt' => 'DESC']);
    }
}

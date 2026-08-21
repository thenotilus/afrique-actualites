<?php

namespace App\Newsletter\Repository;

use App\Newsletter\Entity\WeeklyNewsletter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeeklyNewsletter>
 */
class WeeklyNewsletterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyNewsletter::class);
    }
}

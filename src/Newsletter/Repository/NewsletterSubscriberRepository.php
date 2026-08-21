<?php

namespace App\Newsletter\Repository;

use App\Newsletter\Entity\NewsletterSubscriber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterSubscriber>
 */
class NewsletterSubscriberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSubscriber::class);
    }

    /** @return list<NewsletterSubscriber> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true]);
    }
}

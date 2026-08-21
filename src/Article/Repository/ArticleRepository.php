<?php

namespace App\Article\Repository;

use App\Article\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /** @return list<Article> */
    public function findPublishedSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.publish = true')
            ->andWhere('a.publicationDate >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.publicationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

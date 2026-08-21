<?php

namespace App\Article\Repository;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * Requête de base des pages publiques (§6/§11.2 phase 6) : articles publiés dans la langue
     * demandée, du plus récent au plus ancien. Les pages accueil/flux/mot-clé/pays/recherche s'en
     * servent toutes comme point de départ commun, en y ajoutant leur propre filtre — c'est ce qui
     * permet de partager un seul gabarit de liste (§ "prompt Claude Design", parcours 1.B).
     */
    public function publishedQueryBuilder(Language $language): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->setParameter('language', $language)
            ->orderBy('a.publicationDate', 'DESC');
    }

    /** Nombre d'articles publiés dans une langue (§3.10, découpage du sitemap en sous-fichiers). */
    public function countPublished(Language $language): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->setParameter('language', $language)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function byFeed(QueryBuilder $queryBuilder, Feed $feed): QueryBuilder
    {
        return $queryBuilder
            ->andWhere('a.feed = :feed')
            ->setParameter('feed', $feed);
    }

    /** Un seul mot-clé (page `/tag/{label}/`) : la relation MEMBER OF évite tout doublon de ligne. */
    public function byKeyword(QueryBuilder $queryBuilder, Taxonomy $keyword): QueryBuilder
    {
        return $queryBuilder
            ->andWhere(':keyword MEMBER OF a.keywords')
            ->setParameter('keyword', $keyword);
    }

    public function byCountry(QueryBuilder $queryBuilder, Country $country): QueryBuilder
    {
        return $queryBuilder
            ->andWhere(':country MEMBER OF a.countries')
            ->setParameter('country', $country);
    }

    /**
     * Un article rattaché à *au moins un* des mots-clés fournis (§3.4, "Unes" thématiques) : une
     * jointure classique est nécessaire ici (pas de MEMBER OF pour un ensemble de valeurs), d'où
     * le DISTINCT pour ne pas compter/afficher un article deux fois s'il correspond à plusieurs
     * mots-clés de l'ensemble.
     *
     * @param list<int> $keywordIds
     */
    public function byAnyKeyword(QueryBuilder $queryBuilder, array $keywordIds): QueryBuilder
    {
        return $queryBuilder
            ->distinct()
            ->join('a.keywords', 'k')
            ->andWhere('k.id IN (:keywordIds)')
            ->setParameter('keywordIds', $keywordIds);
    }

    /**
     * Recherche simplifiée (§3.5) : titre, description ou libellé d'un mot-clé validé. Remplace
     * la recherche par taxonomies uniquement de l'ancienne application ; un moteur d'indexation
     * dédié reste une amélioration future recommandée (§9.1), pas un prérequis de cette phase.
     */
    public function bySearchQuery(QueryBuilder $queryBuilder, string $query): QueryBuilder
    {
        return $queryBuilder
            ->distinct()
            ->leftJoin('a.keywords', 'k')
            ->andWhere('LOWER(a.title) LIKE :needle OR LOWER(a.description) LIKE :needle OR LOWER(k.label) LIKE :needle')
            ->setParameter('needle', '%'.mb_strtolower($query).'%');
    }

    /**
     * Le plus récent article publié issu d'un flux sponsorisé (§3.12, mise en avant "Sponsorisé"
     * en accueil, cf. le prompt de conception UX). `null` si aucun flux sponsorisé n'a encore
     * produit d'article publié dans cette langue — l'accueil doit rester fonctionnelle sans.
     */
    public function findLatestSponsored(Language $language): ?Article
    {
        return $this->createQueryBuilder('a')
            ->join('a.feed', 'f')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->andWhere('f.sponsored = true')
            ->setParameter('language', $language)
            ->orderBy('a.publicationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Articles "à lire aussi" (fiche article, prompt de conception parcours 1.C) : d'autres
     * articles publiés de la même langue partageant au moins un mot-clé validé, hors l'article
     * courant.
     *
     * @return list<Article>
     */
    public function findSimilar(Article $article, int $limit = 4): array
    {
        $keywordIds = array_map(static fn (Taxonomy $t) => $t->getId(), $article->getKeywords()->toArray());
        if ([] === $keywordIds) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->distinct()
            ->join('a.keywords', 'k')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->andWhere('a.id != :excludedId')
            ->andWhere('k.id IN (:keywordIds)')
            ->setParameter('language', $article->getLanguage())
            ->setParameter('excludedId', $article->getId())
            ->setParameter('keywordIds', $keywordIds)
            ->orderBy('a.publicationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

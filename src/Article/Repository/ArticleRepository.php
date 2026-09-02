<?php

namespace App\Article\Repository;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
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

    /**
     * Identifiants des articles dont un champ complétable par le crawl de repli (§9.4) manque
     * encore : titre ou description vide, ou image absente. Une image manquante déclenche donc un
     * crawl de la page source pour en récupérer l'URL (`og:image`) — on ne stocke jamais que
     * l'URL, aucun fichier n'est téléchargé. Sert de pré-filtre à la commande `app:crawler:run` ;
     * la garde fine (avec `trim()`) reste celle de
     * {@see \App\Crawler\Message\CrawlArticleMetaMessageHandler}, qui revérifie chaque article avant
     * de crawler. On ne remonte que les identifiants : c'est tout ce que porte le message de crawl.
     *
     * @return list<int>
     */
    public function findIdsMissingMetadata(?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere("a.title = '' OR a.description = '' OR a.image IS NULL")
            ->orderBy('a.publicationDate', 'DESC');

        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $queryBuilder->getQuery()->getScalarResult());
    }

    /**
     * Parmi les empreintes fournies, celles déjà présentes en base. Sert à dédupliquer un lot
     * d'entrées de flux avant insertion (§9.4, `FeedIngester`) en une seule requête, plutôt qu'un
     * `findOneBy` par entrée. La déduplication s'appuie sur `urlHash` (md5 de l'URL), unique.
     *
     * @param list<string> $urlHashes
     *
     * @return list<string>
     */
    public function findExistingUrlHashes(array $urlHashes): array
    {
        if ([] === $urlHashes) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['urlHash'],
            $this->createQueryBuilder('a')
                ->select('a.urlHash')
                ->andWhere('a.urlHash IN (:hashes)')
                ->setParameter('hashes', $urlHashes)
                ->getQuery()
                ->getScalarResult(),
        );
    }

    /**
     * Articles *ingérés* depuis l'instant donné (fenêtre glissante de classification, §6), du plus
     * récemment ingéré au plus ancien. On borne sur `createdAt` (date d'ingestion) et non sur
     * `publicationDate` : ainsi tout article reste dans la fenêtre le temps voulu après sa création,
     * quelle que soit la date — parfois ancienne ou absente — fournie par le flux, et il est donc
     * garanti d'être scoré au moins une fois par `app:classification:run`. Aucun filtre `publish` :
     * on classe aussi les articles encore incomplets (leur mot-clé n'est de toute façon rattaché
     * que si la taxonomie est validée), pour qu'ils soient déjà classés une fois publiés.
     *
     * @return list<Article>
     */
    public function findCreatedSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
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
     * Restreint une requête à un mois calendaire (archives par pays, §3.13 / parcours 1.E). On
     * borne sur l'intervalle [1er du mois, 1er du mois suivant[ plutôt que sur des fonctions SQL
     * de date, pour rester portable et laisser l'index sur `publicationDate` faire son travail.
     */
    public function byMonth(QueryBuilder $queryBuilder, int $year, int $month): QueryBuilder
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return $queryBuilder
            ->andWhere('a.publicationDate >= :monthStart')
            ->andWhere('a.publicationDate < :monthEnd')
            ->setParameter('monthStart', $start)
            ->setParameter('monthEnd', $start->modify('+1 month'));
    }

    /**
     * Restreint une requête à une fenêtre [début, fin[ (synthèses hebdomadaires, §
     * "Sélection et regroupement" — `App\Synthesis\WeeklySelector`). Même logique d'intervalle
     * demi-ouvert que {@see byMonth()}, pour la même raison de portabilité.
     */
    public function betweenDates(QueryBuilder $queryBuilder, \DateTimeImmutable $start, \DateTimeImmutable $end): QueryBuilder
    {
        return $queryBuilder
            ->andWhere('a.publicationDate >= :weekStart')
            ->andWhere('a.publicationDate < :weekEnd')
            ->setParameter('weekStart', $start)
            ->setParameter('weekEnd', $end);
    }

    /**
     * Mois pour lesquels le pays a au moins un article publié dans la langue donnée, du plus
     * récent au plus ancien (grille d'archives, parcours 1.E). `SUBSTRING` sur la date-heure
     * renvoie « YYYY-MM » côté MySQL ; on regroupe dessus pour éviter de charger toutes les lignes.
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    public function findPublishedMonthsForCountry(Country $country, Language $language): array
    {
        /** @var list<array{ym: string, cnt: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('SUBSTRING(a.publicationDate, 1, 7) AS ym, COUNT(a.id) AS cnt')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->andWhere(':country MEMBER OF a.countries')
            ->setParameter('language', $language)
            ->setParameter('country', $country)
            ->groupBy('ym')
            ->orderBy('ym', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            [$year, $month] = array_map('intval', explode('-', (string) $row['ym']));

            return ['year' => $year, 'month' => $month, 'count' => (int) $row['cnt']];
        }, $rows);
    }

    /**
     * Mots-clés validés (langue courante) dont le libellé contient la requête, avec le nombre
     * d'articles publiés associés — pour l'autocomplétion de recherche (parcours 1.F). Les plus
     * fournis d'abord ; seuls les mots-clés portant au moins un article publié remontent.
     *
     * @return list<array{label: string, count: int}>
     */
    public function suggestKeywords(string $query, Language $language, int $limit = 6): array
    {
        /** @var list<array{label: string, cnt: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('k.label AS label, COUNT(DISTINCT a.id) AS cnt')
            ->join('a.keywords', 'k')
            ->andWhere('a.publish = true')
            ->andWhere('a.language = :language')
            ->andWhere('k.status = :validated')
            ->andWhere('LOWER(k.label) LIKE :needle')
            ->setParameter('language', $language)
            ->setParameter('validated', TaxonomyStatus::VALIDATED)
            ->setParameter('needle', '%'.mb_strtolower($query).'%')
            ->groupBy('k.id')
            ->addGroupBy('k.label')
            ->orderBy('cnt', 'DESC')
            ->addOrderBy('k.label', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['label' => (string) $row['label'], 'count' => (int) $row['cnt']],
            $rows,
        );
    }

    /**
     * Articles publiés (langue courante) correspondant à la requête, pour l'autocomplétion (§1.F).
     *
     * @return list<Article>
     */
    public function suggestArticles(string $query, Language $language, int $limit = 4): array
    {
        return $this->bySearchQuery($this->publishedQueryBuilder($language), $query)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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

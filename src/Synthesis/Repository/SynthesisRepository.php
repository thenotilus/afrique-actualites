<?php

namespace App\Synthesis\Repository;

use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Shared\ValueObject\Language;
use App\Synthesis\Entity\Synthesis;
use App\Synthesis\Enum\SynthesisStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Synthesis>
 */
class SynthesisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Synthesis::class);
    }

    /**
     * Une synthèse déjà générée pour ce pays/région, cette semaine et cette langue — sert de garde
     * d'idempotence à `SynthesisGenerator` : on ne régénère jamais une synthèse déjà produite pour
     * la même semaine (une exécution manuelle répétée de `app:synthesis:generate` reste sans
     * effet), qu'un administrateur peut toujours régénérer explicitement en la rejetant d'abord.
     */
    public function findExisting(?Country $country, ?Region $region, \DateTimeImmutable $weekStart, Language $language): ?Synthesis
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->andWhere('s.weekStart = :weekStart')
            ->andWhere('s.language = :language')
            ->setParameter('weekStart', $weekStart)
            ->setParameter('language', $language);

        if (null !== $country) {
            $queryBuilder->andWhere('s.country = :country')->setParameter('country', $country);
        } else {
            $queryBuilder->andWhere('s.country IS NULL');
        }

        if (null !== $region) {
            $queryBuilder->andWhere('s.region = :region')->setParameter('region', $region);
        } else {
            $queryBuilder->andWhere('s.region IS NULL');
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }

    /**
     * Brouillons en attente de relecture (§ "Workflow de validation"), les plus récemment générés
     * d'abord — file d'attente de l'écran d'administration.
     *
     * @return list<Synthesis>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->setParameter('status', SynthesisStatus::DRAFT)
            ->orderBy('s.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Synthèses publiées pour un pays, les plus récentes d'abord — alimente le bloc "Synthèses
     * hebdomadaires" affiché sur la page pays (`CountryController::show`).
     *
     * @return list<Synthesis>
     */
    public function findPublishedForCountry(Country $country, Language $language, int $limit = 6): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.country = :country')
            ->andWhere('s.language = :language')
            ->andWhere('s.status = :status')
            ->setParameter('country', $country)
            ->setParameter('language', $language)
            ->setParameter('status', SynthesisStatus::PUBLISHED)
            ->orderBy('s.weekStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Synthèses publiées pour une région, les plus récentes d'abord — même usage que
     * {@see findPublishedForCountry()} pour les pays repliés en synthèse régionale.
     *
     * @return list<Synthesis>
     */
    public function findPublishedForRegion(Region $region, Language $language, int $limit = 6): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.region = :region')
            ->andWhere('s.language = :language')
            ->andWhere('s.status = :status')
            ->setParameter('region', $region)
            ->setParameter('language', $language)
            ->setParameter('status', SynthesisStatus::PUBLISHED)
            ->orderBy('s.weekStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Synthèse publiée d'un pays pour une semaine donnée (route publique `app_synthesis_show`). */
    public function findOnePublishedForCountryAndWeek(Country $country, Language $language, \DateTimeImmutable $weekStart): ?Synthesis
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.country = :country')
            ->andWhere('s.language = :language')
            ->andWhere('s.weekStart = :weekStart')
            ->andWhere('s.status = :status')
            ->setParameter('country', $country)
            ->setParameter('language', $language)
            ->setParameter('weekStart', $weekStart)
            ->setParameter('status', SynthesisStatus::PUBLISHED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Synthèse publiée d'une région pour une semaine donnée (route publique `app_synthesis_show`). */
    public function findOnePublishedForRegionAndWeek(Region $region, Language $language, \DateTimeImmutable $weekStart): ?Synthesis
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.region = :region')
            ->andWhere('s.language = :language')
            ->andWhere('s.weekStart = :weekStart')
            ->andWhere('s.status = :status')
            ->setParameter('region', $region)
            ->setParameter('language', $language)
            ->setParameter('weekStart', $weekStart)
            ->setParameter('status', SynthesisStatus::PUBLISHED)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

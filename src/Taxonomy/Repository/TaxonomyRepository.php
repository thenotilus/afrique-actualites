<?php

namespace App\Taxonomy\Repository;

use App\Geography\Entity\Country;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Taxonomy>
 */
class TaxonomyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Taxonomy::class);
    }

    public function findOneByLabelAndLanguage(string $label, Language $language): ?Taxonomy
    {
        return $this->findOneBy(['label' => $label, 'language' => $language]);
    }

    /**
     * File d'attente des suggestions en attente de validation par un administrateur (§4.4).
     *
     * @return list<Taxonomy>
     */
    public function findPendingSuggestions(?Language $language = null): array
    {
        $criteria = ['status' => TaxonomyStatus::SUGGESTED];
        if ($language) {
            $criteria['language'] = $language;
        }

        return $this->findBy($criteria, ['createdAt' => 'ASC']);
    }

    /**
     * Mots-clés validés disponibles pour le croisement pays × mot-clé (§3.13, écran prioritaire) :
     * uniquement ceux réellement présents sur au moins un article publié de ce pays — proposer un
     * mot-clé qui ne renverrait aucun résultat une fois sélectionné n'aurait aucun intérêt.
     *
     * @return list<Taxonomy>
     */
    public function findValidatedForCountry(Country $country, Language $language, int $limit = 15): array
    {
        return $this->createQueryBuilder('t')
            ->distinct()
            ->join('t.keywordArticles', 'a')
            ->join('a.countries', 'c')
            ->andWhere('t.status = :status')
            ->andWhere('t.language = :language')
            ->andWhere('a.publish = true')
            ->andWhere('c = :country')
            ->setParameter('status', TaxonomyStatus::VALIDATED)
            ->setParameter('language', $language)
            ->setParameter('country', $country)
            ->orderBy('t.label', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

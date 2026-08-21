<?php

namespace App\Taxonomy\Repository;

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
}

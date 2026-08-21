<?php

namespace App\Geography\Repository;

use App\Geography\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    public function findOneByCode(string $code): ?Country
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Annuaire des pays publics (§3.13, prompt de conception parcours 1.E). @return list<Country> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['nameFr' => 'ASC']);
    }
}

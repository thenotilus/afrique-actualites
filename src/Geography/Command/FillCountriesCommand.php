<?php

namespace App\Geography\Command;

use App\Geography\Entity\Country;
use App\Geography\Repository\CountryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Peuple le référentiel des pays depuis `Resources/countries.yaml` (§3.13, §10.1.6).
 *
 * Contrairement à l'ancienne application, où la commande équivalente insérait aveuglément une
 * nouvelle ligne à chaque exécution (dette listée en §4.3, aucune contrainte d'unicité exploitée),
 * celle-ci fait un upsert par code ISO : rejouable sans dupliquer, et les pays déjà activés
 * manuellement depuis le back-office (`Country::$active`) le restent.
 */
#[AsCommand(name: 'app:country:fill', description: 'Peuple ou met à jour le référentiel des pays depuis countries.yaml')]
final class FillCountriesCommand extends Command
{
    private const RESOURCE_PATH = __DIR__.'/../Resources/countries.yaml';

    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{code: string, name_fr: string, name_en: string}> $countries */
        $countries = Yaml::parseFile(self::RESOURCE_PATH) ?? [];

        $created = 0;
        $updated = 0;

        foreach ($countries as $data) {
            $country = $this->countryRepository->findOneByCode($data['code']);
            if (null === $country) {
                $country = new Country($data['code'], $data['name_fr'], $data['name_en']);
                $this->entityManager->persist($country);
                ++$created;

                continue;
            }

            $country->setNameFr($data['name_fr']);
            $country->setNameEn($data['name_en']);
            ++$updated;
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d pays créé(s), %d pays mis à jour.', $created, $updated));

        return Command::SUCCESS;
    }
}

<?php

namespace App\Synthesis\Command;

use App\Synthesis\SynthesisGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Déclenche le pipeline de synthèses hebdomadaires (§ "Scheduling") : indépendante du pipeline de
 * déploiement Deployer (qui sert au déploiement de code, pas à l'exécution récurrente de tâches
 * applicatives) — à cadencer par le crontab de production, comme les autres tâches périodiques du
 * dépôt (voir `crontab`, tôt le lundi matin, une fois la semaine Lun-Dim écoulée).
 *
 * `--week-start` permet de régénérer une semaine passée à la main pendant la phase de fiabilisation
 * du pipeline (§ "Objectif : fiabiliser le pipeline sur plusieurs semaines") ; sans l'option, la
 * commande cible la semaine ISO qui vient de s'achever (lundi 00:00 → lundi 00:00 suivant).
 */
#[AsCommand(name: 'app:synthesis:generate', description: 'Génère les synthèses hebdomadaires par pays/région')]
final class GenerateWeeklySynthesesCommand extends Command
{
    public function __construct(private readonly SynthesisGenerator $synthesisGenerator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'week-start',
            null,
            InputOption::VALUE_REQUIRED,
            'Lundi de la semaine à traiter (YYYY-MM-DD). Défaut : le lundi de la semaine ISO qui vient de s\'achever.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $weekStart = $this->resolveWeekStart($input->getOption('week-start'), $io);
        if (false === $weekStart) {
            return Command::INVALID;
        }
        $weekEnd = $weekStart->modify('+7 days');

        $io->section(sprintf('Synthèses hebdomadaires : semaine du %s au %s', $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')));

        $summary = $this->synthesisGenerator->generateForWeek($weekStart, $weekEnd);

        $io->success(sprintf(
            '%d synthèse(s) créée(s), %d déjà existante(s) ignorée(s), %d échec(s).',
            $summary->created,
            $summary->skipped,
            $summary->failed,
        ));

        return $summary->failed > 0 && 0 === $summary->created ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveWeekStart(mixed $weekStartOption, SymfonyStyle $io): \DateTimeImmutable|false
    {
        if (null === $weekStartOption) {
            // Lundi de la semaine ISO qui vient de s'achever. `date('N')` donne le jour ISO
            // (1 = lundi ... 7 = dimanche) : on recule d'abord au lundi de la semaine en cours
            // (jour 1, quel que soit le jour d'aujourd'hui), puis d'une semaine de plus — sans
            // ce second recul, exécutée un lundi matin, la commande couvrirait la semaine en
            // cours (à peine commencée) plutôt que la semaine Lun-Dim qui vient de s'achever.
            $today = new \DateTimeImmutable('today');
            $mondayThisWeek = $today->modify(sprintf('-%d days', ((int) $today->format('N')) - 1));

            return $mondayThisWeek->modify('-7 days');
        }

        $weekStart = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $weekStartOption);
        if (false === $weekStart) {
            $io->error(sprintf('L\'option --week-start attend une date au format YYYY-MM-DD, "%s" reçu.', $weekStartOption));

            return false;
        }

        return $weekStart;
    }
}

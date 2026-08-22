<?php

namespace App\Classification\Command;

use App\Article\Repository\ArticleRepository;
use App\Classification\ClassificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Classification des articles sur une fenêtre glissante (§6, §10).
 *
 * Découplée de la récupération des articles ({@see \App\Feed\Command\IngestFeedsCommand}, qui ne
 * fait plus qu'ingérer) : au lieu de scorer le seul lot d'un flux — où un article au sujet unique
 * restait sans terme faute d'atteindre `min_document_frequency` dans un lot minuscule — cette
 * commande rescore périodiquement *tous* les articles ingérés dans les dernières `--window` heures.
 * Le lot est ainsi bien plus fourni, un token a plus de chances d'y récidiver et de devenir
 * mot-clé, et les termes d'un article se mettent à jour au fil des ingestions voisines.
 *
 * À cadencer par l'ordonnanceur (toutes les 5 minutes, cf. .dev/docker-compose.yml). Idempotente :
 * {@see \App\Article\Entity\Article::addTaxonomy()}/{@see \App\Article\Entity\Article::addKeyword()}
 * dédoublonnent, donc repasser sur les mêmes articles à chaque tick n'ajoute que les nouveaux
 * termes devenus éligibles — jamais de doublon, et un terme rejeté par un administrateur n'est
 * jamais ressuscité (cf. {@see ClassificationService}).
 */
#[AsCommand(name: 'app:classification:run', description: 'Classe les articles ingérés sur une fenêtre glissante')]
final class RunClassificationCommand extends Command
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly ClassificationService $classificationService,
        private readonly int $defaultWindowHours,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'window',
                'w',
                InputOption::VALUE_REQUIRED,
                'Largeur de la fenêtre glissante en heures (défaut : valeur de classification.window_hours)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $windowHours = $this->parseWindow($input->getOption('window'), $io);
        if (false === $windowHours) {
            return Command::INVALID;
        }

        $since = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $windowHours));
        $articles = $this->articleRepository->findCreatedSince($since);

        if ([] === $articles) {
            $io->success(sprintf('Aucun article ingéré sur les %d dernières heures : rien à classer.', $windowHours));

            return Command::SUCCESS;
        }

        $summary = $this->classificationService->classifyArticles($articles);

        $io->success(sprintf(
            'Fenêtre de %dh : %d article(s) traités, %d suggestion(s) créées, %d renforcée(s), %d rattachement(s) pays.',
            $windowHours,
            $summary->articlesProcessed,
            $summary->suggestionsCreated,
            $summary->suggestionsReinforced,
            $summary->countriesAttached,
        ));

        return Command::SUCCESS;
    }

    /** @return int|false le nombre d'heures, ou `false` si l'option `--window` est invalide */
    private function parseWindow(mixed $windowOption, SymfonyStyle $io): int|false
    {
        if (null === $windowOption) {
            return $this->defaultWindowHours;
        }

        if (!ctype_digit((string) $windowOption) || 0 === (int) $windowOption) {
            $io->error(sprintf('L\'option --window attend un entier positif d\'heures, "%s" reçu.', $windowOption));

            return false;
        }

        return (int) $windowOption;
    }
}

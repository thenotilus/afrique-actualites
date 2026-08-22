<?php

namespace App\Feed\Command;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Feed\FeedIngester;
use App\Feed\Repository\FeedRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Récupération des articles (§6) : lit les flux RSS/Atom actifs et crée les `Article` manquants
 * (via {@see FeedIngester}). Cette commande ne fait *que* récupérer : la classification est
 * découplée et confiée à {@see \App\Classification\Command\RunClassificationCommand}
 * (`app:classification:run`), qui rescore les articles sur une fenêtre glissante. Scorer ici le
 * seul lot d'un flux laissait sans terme tout article au sujet unique dans son lot.
 *
 * C'est le « worker d'extraction continu » esquissé dans docs/architecture.md, dans sa forme
 * ponctuelle rejouable — à cadencer par un ordonnanceur (cron/timer) tant qu'un vrai worker
 * persistant n'est pas branché. Idempotente : les entrées déjà en base sont ignorées.
 *
 * S'appuie sur le crawl de repli (§9.4) que `FeedIngester` déclenche pour compléter les
 * métadonnées absentes des flux ; `app:crawler:run` reste utile pour rattraper séparément un
 * arriéré d'articles incomplets.
 */
#[AsCommand(name: 'app:feed:ingest', description: 'Récupère les articles des flux RSS/Atom actifs')]
final class IngestFeedsCommand extends Command
{
    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly FeedIngester $feedIngester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'N\'ingérer qu\'un seul flux, par son identifiant')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lit et analyse les flux sans rien enregistrer ni classer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $feeds = $this->resolveFeeds($input->getOption('feed'), $io);
        if (null === $feeds) {
            return Command::INVALID;
        }
        if ([] === $feeds) {
            $io->warning('Aucun flux actif à ingérer.');

            return Command::SUCCESS;
        }

        /** @var list<Article> $createdArticles */
        $createdArticles = [];
        $rows = [];
        $failures = 0;

        foreach ($feeds as $feed) {
            try {
                $result = $this->feedIngester->ingest($feed, $dryRun);
            } catch (\Throwable $e) {
                ++$failures;
                $io->warning(sprintf('Flux #%d (%s) ignoré : %s', $feed->getId(), $feed->getUrl(), $e->getMessage()));

                continue;
            }

            $createdArticles = [...$createdArticles, ...$result->createdArticles];
            $rows[] = [
                (string) $feed->getId(),
                $feed->getLabel() ?? $feed->getUrl(),
                (string) $result->itemsSeen,
                (string) $result->createdCount(),
                (string) $result->skipped,
            ];
        }

        if ([] !== $rows) {
            $io->table(['#', 'Flux', 'Entrées', 'Créés', 'Ignorés'], $rows);
        }

        if ($dryRun) {
            $io->note(sprintf('Simulation : %d article(s) seraient créés (aucune écriture).', \count($createdArticles)));

            return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $message = sprintf('%d article(s) créé(s) sur %d flux.', \count($createdArticles), \count($rows));
        if ($failures > 0) {
            $io->warning($message.sprintf(' %d flux en échec.', $failures));

            return Command::FAILURE;
        }

        $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * @return list<Feed>|null la liste des flux à traiter, ou `null` si l'option `--feed` est invalide
     */
    private function resolveFeeds(mixed $feedOption, SymfonyStyle $io): ?array
    {
        if (null === $feedOption) {
            return $this->feedRepository->findActive();
        }

        if (!ctype_digit((string) $feedOption)) {
            $io->error(sprintf('L\'option --feed attend un identifiant entier, "%s" reçu.', $feedOption));

            return null;
        }

        $feed = $this->feedRepository->find((int) $feedOption);
        if (null === $feed) {
            $io->error(sprintf('Aucun flux d\'identifiant %s.', $feedOption));

            return null;
        }

        return [$feed];
    }
}

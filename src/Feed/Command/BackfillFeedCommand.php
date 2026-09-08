<?php

namespace App\Feed\Command;

use App\Feed\Entity\Feed;
use App\Feed\FeedBackfiller;
use App\Feed\Repository\FeedRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill historique (§6bis) : tente de récupérer, via les sitemaps des médias enregistrés
 * (flux `Feed` actifs), les articles publiés au cours des `--years` dernières années (5 par
 * défaut) — au-delà de ce que `app:feed:ingest` peut voir, celui-ci n'ingérant que les entrées
 * *courantes* d'un flux RSS/Atom, qui n'expose en pratique jamais plusieurs années d'historique.
 *
 * "Tente" : un sitemap peut être absent, incomplet, ou sans date sur chaque entrée — voir le
 * docblock de {@see FeedBackfiller}, qui documente comment l'absence de date est traitée. Chaque
 * article créé est délibérément incomplet (seule l'URL est connue) : c'est le crawl de repli
 * (§9.4), déjà dispatché par `FeedBackfiller` comme par `FeedIngester`, qui en récupère ensuite
 * titre, description et image.
 *
 * Termine par une classification (§10) des articles du média déjà exploitables dans la fenêtre,
 * jour par jour plutôt qu'en un seul lot de plusieurs années — voir le docblock de
 * {@see FeedBackfiller} pour le raisonnement complet.
 *
 * Idempotente et rejouable comme les autres commandes du projet : relancer ne recrée que les
 * entrées de sitemap pas encore en base (déduplication par `urlHash`), et ne fait que renforcer
 * (jamais dupliquer) les termes déjà attachés à un article déjà classé.
 */
#[AsCommand(name: 'app:feed:backfill', description: 'Tente de récupérer, via les sitemaps des médias enregistrés, les articles des N dernières années')]
final class BackfillFeedCommand extends Command
{
    private const DEFAULT_YEARS = 5;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly FeedBackfiller $feedBackfiller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Ne traiter qu\'un seul média, par l\'identifiant de son flux')
            ->addOption('years', 'y', InputOption::VALUE_REQUIRED, sprintf('Profondeur de récupération en années (%d par défaut)', self::DEFAULT_YEARS))
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre maximum d\'articles à créer par média (illimité par défaut)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse les sitemaps sans rien enregistrer ni dispatcher de crawl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $years = $this->parsePositiveInt($input->getOption('years'), '--years', $io);
        if (false === $years) {
            return Command::INVALID;
        }
        $years ??= self::DEFAULT_YEARS;

        $limit = $this->parsePositiveInt($input->getOption('limit'), '--limit', $io);
        if (false === $limit) {
            return Command::INVALID;
        }

        $feeds = $this->resolveFeeds($input->getOption('feed'), $io);
        if (null === $feeds) {
            return Command::INVALID;
        }
        if ([] === $feeds) {
            $io->warning('Aucun média actif à traiter.');

            return Command::SUCCESS;
        }

        $since = (new \DateTimeImmutable())->modify(sprintf('-%d years', $years));

        $rows = [];
        $totalCreated = 0;
        $totalDaysClassified = 0;
        $totalKeywordsCreated = 0;
        foreach ($feeds as $feed) {
            // Lus avant l'appel : FeedBackfiller vide périodiquement l'identity map Doctrine pour
            // rester sous la limite mémoire sur un média volumineux (cf. son docblock "Mémoire"),
            // ce qui détache $feed — inoffensif pour ces simples lectures de champs, mais autant
            // ne pas en dépendre.
            $feedId = $feed->getId();
            $feedLabel = $feed->getLabel() ?? $feed->getUrl();

            $result = $this->feedBackfiller->backfill($feed, $since, $dryRun, $limit);
            $totalCreated += $result->created;
            $totalDaysClassified += $result->daysClassified;
            $totalKeywordsCreated += $result->keywordsCreated;
            $rows[] = [
                (string) $feedId,
                $feedLabel,
                (string) $result->entriesSeen,
                (string) $result->entriesInWindow,
                (string) $result->created,
                (string) $result->skipped,
                (string) $result->daysClassified,
                (string) $result->articlesClassified,
            ];
        }

        $io->table(
            ['#', 'Média', 'URLs de sitemap vues', sprintf('Dans les %d an(s)', $years), 'Articles créés', 'Déjà en base', 'Jours classés', 'Articles classés'],
            $rows,
        );

        if ($dryRun) {
            $io->note(sprintf('Simulation : %d article(s) seraient créés (aucune écriture, aucun crawl ni classification dispatchés).', $totalCreated));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d article(s) créé(s) sur %d média(s) ; le crawl de repli va compléter leurs métadonnées. '
            .'%d jour(s) classé(s), %d nouveau(x) mot(s)-clé(s).',
            $totalCreated,
            \count($rows),
            $totalDaysClassified,
            $totalKeywordsCreated,
        ));

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
            $io->error(sprintf('Aucun média d\'identifiant %s.', $feedOption));

            return null;
        }

        return [$feed];
    }

    /** @return int|false|null l'entier validé, `null` si l'option est absente, `false` si invalide */
    private function parsePositiveInt(mixed $raw, string $optionName, SymfonyStyle $io): int|false|null
    {
        if (null === $raw) {
            return null;
        }

        if (!ctype_digit((string) $raw) || (int) $raw < 1) {
            $io->error(sprintf('L\'option %s doit être un entier positif, "%s" reçu.', $optionName, $raw));

            return false;
        }

        return (int) $raw;
    }
}

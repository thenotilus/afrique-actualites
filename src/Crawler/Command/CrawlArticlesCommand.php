<?php

namespace App\Crawler\Command;

use App\Article\Repository\ArticleRepository;
use App\Crawler\Message\CrawlArticleMetaMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lance le crawl de repli (§9.4) sur les articles dont le flux RSS n'a pas fourni toutes les
 * métadonnées (titre, description ou image). Pour chaque article concerné, dispatche un
 * {@see CrawlArticleMetaMessage} : c'est {@see \App\Crawler\Message\CrawlArticleMetaMessageHandler}
 * qui réalise le crawl et n'écrase jamais une valeur déjà présente.
 *
 * Comble le manque relevé dans docs/architecture.md (phase 5) : jusqu'ici aucun appelant réel ne
 * dispatchait `CrawlArticleMetaMessage`. Ce message est routé vers le transport `async`
 * (config/packages/messenger.yaml) : la commande met les crawls en file et rend la main aussitôt,
 * un worker `php bin/console messenger:consume async` les traite ensuite (le `DelayStamp` de
 * reprogrammation au quota épuisé exige un transport asynchrone, cf. la note dans la doc).
 *
 * Idempotente et rejouable, sur le modèle des autres commandes du projet : relancer ne re-crawle
 * que ce qui manque toujours (le handler revérifie chaque article et le cache par URL évite de
 * retraiter une page déjà résolue récemment).
 */
#[AsCommand(name: 'app:crawler:run', description: 'Lance le crawl de repli sur les articles à métadonnées incomplètes')]
final class CrawlArticlesCommand extends Command
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre maximum d\'articles à traiter (tous par défaut)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les articles concernés sans dispatcher de crawl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $this->parseLimit($input->getOption('limit'), $io);
        if (false === $limit) {
            return Command::INVALID;
        }

        $articleIds = $this->articleRepository->findIdsMissingMetadata($limit);
        if ([] === $articleIds) {
            $io->success('Aucun article à métadonnées incomplètes : rien à crawler.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('%d article(s) seraient crawlés : %s', \count($articleIds), implode(', ', $articleIds)));

            return Command::SUCCESS;
        }

        $io->progressStart(\count($articleIds));
        foreach ($articleIds as $articleId) {
            $this->messageBus->dispatch(new CrawlArticleMetaMessage($articleId));
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(sprintf('%d crawl(s) de repli lancé(s).', \count($articleIds)));

        return Command::SUCCESS;
    }

    /** @return int|false|null le plafond validé, `null` pour illimité, `false` si l'option est invalide */
    private function parseLimit(mixed $rawLimit, SymfonyStyle $io): int|false|null
    {
        if (null === $rawLimit) {
            return null;
        }

        if (!ctype_digit((string) $rawLimit) || (int) $rawLimit < 1) {
            $io->error(sprintf('L\'option --limit doit être un entier positif, "%s" reçu.', $rawLimit));

            return false;
        }

        return (int) $rawLimit;
    }
}

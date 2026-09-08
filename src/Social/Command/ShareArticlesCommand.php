<?php

namespace App\Social\Command;

use App\Article\Repository\ArticleRepository;
use App\Social\FacebookPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Partage sur les réseaux sociaux (§3.8, `ShareCommand` de l'ancienne application). Reproduit le
 * comportement d'origine : un seul article partagé par exécution
 * ({@see ArticleRepository::findNextToShare()}, logique anti-répétition sur les mots-clés), à
 * cadencer par cron plutôt que de tout partager d'un coup — ce qui viderait le backlog en une
 * rafale de publications sur la Page, au lieu d'un flux régulier.
 *
 * Un échec de publication (API Graph en erreur, réseau) laisse l'article `shared = false` : il
 * reste candidat au prochain passage, sans intervention manuelle.
 */
#[AsCommand(name: 'app:articles:share', description: 'Partage le prochain article éligible sur la Page Facebook')]
final class ShareArticlesCommand extends Command
{
    /** Fenêtre anti-répétition (§3.8) : n'exclut que les mots-clés partagés dans les 6 dernières heures. */
    private const ANTI_REPETITION_WINDOW = '-6 hours';

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly FacebookPublisher $facebookPublisher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $article = $this->articleRepository->findNextToShare(new \DateTimeImmutable(self::ANTI_REPETITION_WINDOW));
        if (null === $article) {
            $io->note('Aucun article éligible au partage (backlog vide ou tous les mots-clés en cooldown).');

            return Command::SUCCESS;
        }

        try {
            $postId = $this->facebookPublisher->publish($article);
        } catch (\Throwable $e) {
            $this->logger->warning('Échec de publication Facebook.', ['articleId' => $article->getId(), 'exception' => $e->getMessage()]);
            $io->error(sprintf('Échec de publication de l\'article #%d : %s', $article->getId(), $e->getMessage()));

            return Command::FAILURE;
        }

        $article->markShared(new \DateTimeImmutable());
        $this->entityManager->flush();

        $io->success(sprintf('Article #%d partagé sur Facebook (post %s).', $article->getId(), $postId));

        return Command::SUCCESS;
    }
}

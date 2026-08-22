<?php

namespace App\Crawler\Message;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Crawler\CrawlerService;
use App\Crawler\Exception\CrawlRateLimitedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Consommateur asynchrone du crawl de repli (§9.4). Ne complète que les champs que le flux RSS
 * n'a pas déjà fournis ("déclenchement conditionnel") : un crawl réussi n'écrase jamais une
 * valeur déjà présente, même si elle diffère de ce que la page source retourne aujourd'hui.
 */
#[AsMessageHandler]
final readonly class CrawlArticleMetaMessageHandler
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private CrawlerService $crawlerService,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CrawlArticleMetaMessage $message): void
    {
        $article = $this->articleRepository->find($message->articleId);
        if (null === $article || !$this->isMissingMetadata($article)) {
            return;
        }

        try {
            $result = $this->crawlerService->crawl($article->getUrl());
        } catch (CrawlRateLimitedException $e) {
            $delayMilliseconds = max(0, ($e->retryAfter->getTimestamp() - time()) * 1000);
            $this->messageBus->dispatch($message, [new DelayStamp($delayMilliseconds)]);

            return;
        }

        if (!$result->success) {
            $this->logger->info('Crawl de repli infructueux, aucune métadonnée disponible.', ['articleId' => $article->getId()]);

            return;
        }

        if ('' === trim($article->getTitle()) && null !== $result->meta->title) {
            $article->setTitle($result->meta->title);
        }
        if ('' === trim($article->getDescription()) && null !== $result->meta->description) {
            $article->setDescription($result->meta->description);
        }
        if (null === $article->getImage() && null !== $result->meta->image) {
            $article->setImage($result->meta->image);
        }

        // Le crawl vient peut-être de compléter l'article : s'il porte désormais titre, description
        // et image, il est publié par défaut (décision produit, même règle qu'à l'ingestion).
        if ($article->isComplete()) {
            $article->setPublish(true);
        }

        $this->entityManager->flush();
    }

    /**
     * Un crawl de repli a lieu dès qu'un champ complétable manque : titre, description ou image.
     * Une image absente déclenche donc un crawl pour récupérer l'URL de l'`og:image` de la page
     * source — jamais le fichier lui-même, seulement l'URL.
     */
    private function isMissingMetadata(Article $article): bool
    {
        return !$article->isComplete();
    }
}

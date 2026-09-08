<?php

namespace App\Crawler\Message;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Crawler\CrawlerService;
use App\Crawler\CrawlResult;
use App\Crawler\Exception\CrawlRateLimitedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Consommateur asynchrone du crawl de repli (§9.4). Ne complète que les champs que le flux RSS
 * n'a pas déjà fournis ("déclenchement conditionnel") : un crawl réussi n'écrase jamais une
 * valeur déjà présente, même si elle diffère de ce que la page source retourne aujourd'hui.
 *
 * Traite les messages **par lots** (`BatchHandlerInterface`) plutôt qu'un par un : le `Worker`
 * Symfony qui exécute `messenger:consume async` accumule jusqu'à `crawler.meta_batch_size`
 * messages — ou flushe plus tôt dès qu'il n'y a (momentanément) plus rien à consommer, ou que le
 * worker s'arrête (`--time-limit`) — avant d'appeler {@see process()}. Objectif : limiter les
 * allers-retours base de données lors d'un rattrapage massif, typiquement après le backfill
 * historique (§6bis, {@see \App\Feed\FeedBackfiller}) qui peut dispatcher plusieurs milliers de
 * messages d'un coup. Un `find()` par article devient un seul `findBy(['id' => ...])` pour tout le
 * lot, et les mises à jour sont flushées ensemble plutôt qu'une fois par message. Le crawl HTTP
 * lui-même reste un par un (rien à gagner à le grouper : {@see CrawlerService} respecte déjà un
 * quota par domaine, §9.4) — seul le travail base de données est mutualisé.
 *
 * `final class`, pas `final readonly class` : {@see BatchHandlerTrait} porte un tableau tampon
 * mutable (`$jobs`), incompatible avec une classe déclarée `readonly` (qui l'exigerait de toutes
 * ses propriétés, y compris celles apportées par un trait).
 */
#[AsMessageHandler]
final class CrawlArticleMetaMessageHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly CrawlerService $crawlerService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize,
    ) {
    }

    public function __invoke(CrawlArticleMetaMessage $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /**
     * Complète les `process()` du lot : une requête pour retrouver tous les articles concernés,
     * puis un crawl par article encore incomplet (jamais groupé, cf. docblock de la classe), et un
     * unique `flush()` final pour l'ensemble du lot.
     *
     * @param list<array{0: CrawlArticleMetaMessage, 1: Acknowledger}> $jobs
     */
    private function process(array $jobs): void
    {
        $articleIds = array_map(static fn (array $job): int => $job[0]->articleId, $jobs);

        /** @var array<int, Article> $articlesById */
        $articlesById = [];
        foreach ($this->articleRepository->findBy(['id' => $articleIds]) as $article) {
            $articlesById[$article->getId()] = $article;
        }

        foreach ($jobs as [$message, $ack]) {
            $article = $articlesById[$message->articleId] ?? null;
            if (null === $article || !$this->isMissingMetadata($article)) {
                $ack->ack();

                continue;
            }

            try {
                $result = $this->crawlerService->crawl($article->getUrl());
            } catch (CrawlRateLimitedException $e) {
                $delayMilliseconds = max(0, ($e->retryAfter->getTimestamp() - time()) * 1000);
                $this->messageBus->dispatch($message, [new DelayStamp($delayMilliseconds)]);
                $ack->ack();

                continue;
            }

            if (!$result->success) {
                $this->logger->info('Crawl de repli infructueux, aucune métadonnée disponible.', ['articleId' => $article->getId()]);
                $ack->ack();

                continue;
            }

            $this->applyResult($article, $result);
            $ack->ack();
        }

        $this->entityManager->flush();
    }

    private function applyResult(Article $article, CrawlResult $result): void
    {
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
    }

    private function getBatchSize(): int
    {
        return $this->batchSize;
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

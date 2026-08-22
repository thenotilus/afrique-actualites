<?php

namespace App\Feed;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Feed\Entity\Feed;
use App\Feed\ValueObject\FeedIngestionResult;
use App\Feed\ValueObject\FeedItem;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Récupération des articles (§6) : lit un flux RSS/Atom, crée les `Article` correspondants et
 * amorce leur enrichissement. C'est la brique d'ingestion qui manquait — jusqu'ici aucun code ne
 * transformait un `Feed` en `Article` (voir docs/architecture.md, note du worker continu §6).
 *
 * Pour chaque nouvelle entrée :
 * - déduplication par `urlHash` (une entrée déjà en base est ignorée, rejouable sans doublon) ;
 * - création de l'article avec les métadonnées du flux (langue héritée du `Feed`) ;
 * - dispatch d'un {@see CrawlArticleMetaMessage} pour les entrées dont le flux n'a pas fourni
 *   titre, description ou image. Une image absente déclenche donc un crawl pour récupérer l'URL de
 *   l'`og:image` de la page source (jamais le fichier, seulement l'URL).
 *
 * La classification (§10) n'est volontairement pas déclenchée ici : le scorer travaille par lot
 * (fréquence documentaire), donc c'est {@see Command\IngestFeedsCommand} qui classe en
 * une passe l'ensemble des articles créés sur tous les flux.
 */
final class FeedIngester
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FeedParser $feedParser,
        private readonly ArticleRepository $articleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
        private readonly float $requestTimeout,
    ) {
    }

    /**
     * @throws HttpClientExceptionInterface si le flux est injoignable
     * @throws Exception\FeedParseException si le contenu n'est pas un flux exploitable
     */
    public function ingest(Feed $feed, bool $dryRun = false): FeedIngestionResult
    {
        $parsed = $this->feedParser->parse($this->fetch($feed->getUrl()));

        if (null !== $parsed->title && null === $feed->getLabel() && !$dryRun) {
            // Renseigne le libellé resté anonyme à la création du flux (§ Feed::$label).
            $feed->setLabel($parsed->title);
        }

        $items = $this->deduplicate($parsed->items);

        $created = [];
        foreach ($items as $item) {
            $article = new Article($item->title, $item->url, $feed, $item->publishedAt);
            $article->setDescription($item->description);
            $article->setImage($item->imageUrl);
            // Un article complet dès le flux (titre + description + image) est publié par défaut ;
            // sinon il attend que le crawl de repli comble les métadonnées manquantes (§9.4).
            if ($article->isComplete()) {
                $article->setPublish(true);
            }

            if (!$dryRun) {
                $this->entityManager->persist($article);
            }
            $created[] = $article;
        }

        if ($dryRun) {
            return new FeedIngestionResult($created, \count($parsed->items), \count($parsed->items) - \count($created));
        }

        $this->entityManager->flush();

        foreach ($created as $article) {
            if ($this->isMissingMetadata($article)) {
                $this->messageBus->dispatch(new CrawlArticleMetaMessage((int) $article->getId()));
            }
        }

        return new FeedIngestionResult($created, \count($parsed->items), \count($parsed->items) - \count($created));
    }

    private function fetch(string $url): string
    {
        return $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml'],
            'timeout' => $this->requestTimeout,
        ])->getContent();
    }

    /**
     * Écarte les entrées déjà en base (par lot, une requête) et les doublons internes au flux.
     *
     * @param list<FeedItem> $items
     *
     * @return list<FeedItem>
     */
    private function deduplicate(array $items): array
    {
        $hashByUrl = [];
        foreach ($items as $item) {
            $hashByUrl[$item->url] = md5($item->url);
        }

        $existing = array_flip($this->articleRepository->findExistingUrlHashes(array_values($hashByUrl)));

        $fresh = [];
        $seen = [];
        foreach ($items as $item) {
            $hash = $hashByUrl[$item->url];
            if (isset($existing[$hash]) || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $fresh[] = $item;
        }

        return $fresh;
    }

    private function isMissingMetadata(Article $article): bool
    {
        return !$article->isComplete();
    }
}

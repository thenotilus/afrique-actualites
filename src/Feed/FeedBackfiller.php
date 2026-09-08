<?php

namespace App\Feed;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Classification\ClassificationService;
use App\Crawler\Message\CrawlArticleMetaMessage;
use App\Feed\Entity\Feed;
use App\Feed\Exception\SitemapParseException;
use App\Feed\ValueObject\FeedBackfillResult;
use App\Feed\ValueObject\ParsedSitemap;
use App\Feed\ValueObject\SitemapEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Backfill historique d'un média (§6bis) : contrairement à `app:feed:ingest`, qui ne voit que les
 * entrées courantes du flux RSS/Atom d'un `Feed`, cette classe tente de retrouver ses articles
 * plus anciens en parcourant son (ou ses) sitemap(s) — découverts par {@see SitemapLocator} — sur
 * une fenêtre de temps donnée.
 *
 * Un sitemap ne fournit qu'une URL et, souvent, une date de dernière modification : jamais de
 * titre, description ou image. Chaque `Article` créé ici est donc systématiquement incomplet — le
 * titre est laissé vide, comme toute métadonnée encore inconnue dans le reste du projet (même
 * convention que `Article::$description` par défaut), surtout *pas* rempli d'un repli sur l'URL :
 * {@see \App\Crawler\Message\CrawlArticleMetaMessageHandler} ne remplace un champ que s'il est vide
 * (« un crawl réussi n'écrase jamais une valeur déjà présente »), donc un faux titre non vide
 * empêcherait justement le crawl de repli (§9.4) de jamais le corriger. Ce crawl est déclenché par
 * le même mécanisme de dispatch conditionnel que {@see FeedIngester::ingest()}, réutilisé à
 * l'identique plutôt que dupliqué.
 *
 * Tolérant par construction, comme {@see CrawlerService} : un sitemap injoignable ou
 * ininterprétable est simplement ignoré (journalisé), jamais fatal pour le média entier — la
 * commande appelante n'a donc rien à rattraper.
 *
 * Termine par une passe de classification (§10) sur les articles du média déjà exploitables
 * (texte non vide) dans la fenêtre demandée, **jour par jour** plutôt qu'en un seul lot :
 * {@see ClassificationService} calcule des statistiques de fréquence documentaire *par lot*, donc
 * lui donner d'un coup cinq années d'articles ferait rivaliser pour les mêmes seuils des sujets
 * d'actualité sans aucun rapport, publiés à des années d'écart — le même défaut, à plus grande
 * échelle, que celui qui avait fait découpler la classification de l'ingestion par flux (cf.
 * docblock de {@see \App\Classification\Command\RunClassificationCommand}). Un article backfillé
 * lors d'un run précédent mais crawlé entre-temps (le crawl de repli est asynchrone, §9.4) n'est
 * donc classé qu'à ce passage-ci — pas de perte, juste un décalage. `ArticleRepository::findClassifiableByFeedSince()`
 * n'y ramène qu'un article jamais encore classé (§4.4) : un article déjà scoré ne repasse pas ici.
 *
 * **Mémoire** : un sitemap peut exposer des dizaines de milliers d'URLs sur cinq ans (un
 * `sitemapindex` renvoyant à son tour des dizaines de feuilles), là où le flux RSS
 * d'`app:feed:ingest` n'en voit que quelques dizaines — un média un peu ancien/actif suffit à
 * épuiser la mémoire allouée au process, vu en production à deux reprises :
 * - une première fois côté Doctrine, en persistant puis flushant tout d'un coup en fin de
 *   traitement (`UnitOfWork` ne libère jamais de lui-même les entités qu'il gère) ;
 * - une seconde fois côté PHP pur, en accumulant dans un tableau *toutes* les entrées de *tous*
 *   les sitemaps du média avant même de commencer à les traiter — un média à l'historique
 *   suffisamment volumineux (ou un `--years` très large, qui désactive de fait le filtrage par
 *   date) fait déborder la mémoire avant qu'une seule ligne SQL ne soit exécutée.
 *
 * D'où un traitement **entièrement en flux** : {@see processSitemaps()} traite chaque sitemap dès
 * qu'il est récupéré, en découpant ses entrées en lots de {@see self::PERSIST_BATCH_SIZE} — chaque
 * lot est filtré, dédupliqué (une requête `IN` bornée à sa taille, jamais sur l'ensemble du média)
 * et persisté indépendamment via {@see persistBatch()}, qui flushe et vide l'identity map
 * (`EntityManager::clear()`) aussitôt. À aucun moment le process ne retient donc plus qu'un lot
 * d'entrées et de sitemaps déjà visités (`array<string, true>`, un fanion par URL). `backfill()`
 * vide une dernière fois l'identity map après la classification, avant de rendre la main à la
 * commande appelante qui enchaîne sur le média suivant. Ce `clear()` répété détache immanquablement
 * l'entité `Feed` reçue en argument : le reste de cette classe ne s'appuie donc jamais sur cet
 * objet une fois son identifiant lu, mais sur `EntityManager::getReference()` — une référence
 * légère, sans hydratation, toujours valide même après un `clear()` puisqu'elle n'est réévaluée
 * qu'à l'usage.
 */
final class FeedBackfiller
{
    /**
     * Taille de lot pour le filtrage/dédoublonnement/persistance des entrées de sitemap (cf.
     * docblock "Mémoire") : aucune étape ne traite jamais plus de ce nombre d'entrées à la fois.
     */
    private const PERSIST_BATCH_SIZE = 200;

    /** Plafond d'articles jamais-classés traités par passage (cf. docblock de {@see ArticleRepository::findClassifiableByFeedSince()}). */
    private const CLASSIFY_MAX_ARTICLES_PER_RUN = 5000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SitemapParser $sitemapParser,
        private readonly SitemapLocator $sitemapLocator,
        private readonly ArticleRepository $articleRepository,
        private readonly ClassificationService $classificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
        private readonly float $requestTimeout,
        private readonly int $maxSitemapsPerFeed,
    ) {
    }

    public function backfill(Feed $feed, \DateTimeImmutable $since, bool $dryRun = false, ?int $limit = null): FeedBackfillResult
    {
        $feedId = $feed->getId();
        $siteBaseUrl = $this->resolveSiteBaseUrl($feed->getUrl());
        if (null === $siteBaseUrl || null === $feedId) {
            $this->logger->warning('Impossible de déduire le site du flux, backfill ignoré.', ['feed' => $feedId, 'url' => $feed->getUrl()]);

            return new FeedBackfillResult(0, 0, 0, 0, 0, 0, 0);
        }

        $stats = $this->processSitemaps($siteBaseUrl, $feedId, $since, $dryRun, $limit);

        if ($dryRun) {
            return new FeedBackfillResult($stats['seen'], $stats['inWindow'], $stats['skipped'], $stats['created'], 0, 0, 0);
        }

        [$daysClassified, $articlesClassified, $keywordsCreated] = $this->classifyByDay($feedId, $since);

        // Dernier nettoyage de l'identity map avant de rendre la main à la commande appelante, qui
        // va enchaîner sur le média suivant (cf. docblock "Mémoire").
        $this->entityManager->clear();

        return new FeedBackfillResult($stats['seen'], $stats['inWindow'], $stats['skipped'], $stats['created'], $daysClassified, $articlesClassified, $keywordsCreated);
    }

    /**
     * Parcourt en largeur l'arborescence de sitemaps du site, `sitemapindex` compris, jusqu'à
     * épuisement, `maxSitemapsPerFeed` documents suivis (garde-fou contre une arborescence
     * démesurée ou une référence circulaire) ou `$limit` articles créés — et traite chaque feuille
     * en flux, par lots, sans jamais accumuler l'historique complet du média en mémoire (cf.
     * docblock "Mémoire" de la classe).
     *
     * @return array{seen: int, inWindow: int, skipped: int, created: int}
     */
    private function processSitemaps(string $siteBaseUrl, int $feedId, \DateTimeImmutable $since, bool $dryRun, ?int $limit): array
    {
        $queue = $this->sitemapLocator->locate($siteBaseUrl);
        $visited = [];
        $documentsProcessed = 0;

        $seen = 0;
        $inWindow = 0;
        $skipped = 0;
        $created = 0;

        while ([] !== $queue && $documentsProcessed < $this->maxSitemapsPerFeed) {
            $sitemapUrl = array_shift($queue);
            if (isset($visited[$sitemapUrl])) {
                continue;
            }
            $visited[$sitemapUrl] = true;
            ++$documentsProcessed;

            $parsed = $this->fetchAndParse($sitemapUrl);
            if (null === $parsed) {
                continue;
            }

            foreach ($parsed->childSitemaps as $child) {
                if (!isset($visited[$child])) {
                    $queue[] = $child;
                }
            }

            if ([] === $parsed->entries) {
                continue;
            }
            $seen += \count($parsed->entries);

            foreach (array_chunk($parsed->entries, self::PERSIST_BATCH_SIZE) as $chunk) {
                if (null !== $limit && $created >= $limit) {
                    break 2;
                }

                $remainingQuota = null !== $limit ? $limit - $created : null;
                $chunkResult = $this->processChunk($chunk, $feedId, $since, $dryRun, $remainingQuota);

                $inWindow += $chunkResult['inWindow'];
                $skipped += $chunkResult['skipped'];
                $created += $chunkResult['created'];
            }
        }

        return ['seen' => $seen, 'inWindow' => $inWindow, 'skipped' => $skipped, 'created' => $created];
    }

    /**
     * Filtre, déduplique et (sauf simulation) persiste un unique lot d'entrées — jamais plus de
     * {@see self::PERSIST_BATCH_SIZE} à la fois, garanti par l'appelant.
     *
     * @param list<SitemapEntry> $chunk
     *
     * @return array{inWindow: int, skipped: int, created: int}
     */
    private function processChunk(array $chunk, int $feedId, \DateTimeImmutable $since, bool $dryRun, ?int $remainingQuota): array
    {
        // `lastmod` absente : date inconnue, pas hors fenêtre (cf. docblock de SitemapEntry) — on
        // la garde par défaut, c'est le principe du "essaie de récupérer" plutôt qu'une garantie
        // stricte des N années faute de date fiable sur nombre de sitemaps en pratique.
        $entriesInWindow = array_values(array_filter(
            $chunk,
            static fn (SitemapEntry $entry): bool => null === $entry->lastmod || $entry->lastmod >= $since,
        ));
        if ([] === $entriesInWindow) {
            return ['inWindow' => 0, 'skipped' => 0, 'created' => 0];
        }

        $fresh = $this->deduplicate($entriesInWindow);
        $skipped = \count($entriesInWindow) - \count($fresh);

        if (null !== $remainingQuota && \count($fresh) > $remainingQuota) {
            $fresh = \array_slice($fresh, 0, max(0, $remainingQuota));
        }

        if ([] === $fresh) {
            return ['inWindow' => \count($entriesInWindow), 'skipped' => $skipped, 'created' => 0];
        }

        if ($dryRun) {
            return ['inWindow' => \count($entriesInWindow), 'skipped' => $skipped, 'created' => \count($fresh)];
        }

        $createdIds = $this->persistBatch($fresh, $feedId);
        foreach ($createdIds as $articleId) {
            $this->messageBus->dispatch(new CrawlArticleMetaMessage($articleId));
        }

        return ['inWindow' => \count($entriesInWindow), 'skipped' => $skipped, 'created' => \count($createdIds)];
    }

    /**
     * Crée un `Article` par entrée (titre volontairement vide, cf. docblock de la classe), flushe
     * et vide l'identity map — jamais plus de {@see self::PERSIST_BATCH_SIZE} entités gérées à la
     * fois.
     *
     * @param list<SitemapEntry> $entries au plus self::PERSIST_BATCH_SIZE, garanti par l'appelant
     *
     * @return list<int> identifiants des articles créés
     */
    private function persistBatch(array $entries, int $feedId): array
    {
        /** @var list<Article> $batch */
        $batch = [];
        foreach ($entries as $entry) {
            $feedReference = $this->entityManager->getReference(Feed::class, $feedId);
            $article = new Article('', $entry->url, $feedReference, $entry->lastmod ?? new \DateTimeImmutable());
            $this->entityManager->persist($article);
            $batch[] = $article;
        }

        $this->entityManager->flush();
        $createdIds = array_map(static fn (Article $article): int => (int) $article->getId(), $batch);
        $this->entityManager->clear();

        return $createdIds;
    }

    /**
     * Classe, jour par jour et dans l'ordre chronologique, les articles du média jamais encore
     * classés (cf. {@see ArticleRepository::findClassifiableByFeedSince()}) dans la fenêtre
     * demandée — pas seulement ceux créés à ce passage (cf. docblock de la classe).
     *
     * @return array{0: int, 1: int, 2: int} [jours traités, articles classés, mots-clés créés]
     */
    private function classifyByDay(int $feedId, \DateTimeImmutable $since): array
    {
        $feedReference = $this->entityManager->getReference(Feed::class, $feedId);
        $articles = $this->articleRepository->findClassifiableByFeedSince($feedReference, $since, self::CLASSIFY_MAX_ARTICLES_PER_RUN);
        if ([] === $articles) {
            return [0, 0, 0];
        }

        /** @var array<string, list<Article>> $byDay */
        $byDay = [];
        foreach ($articles as $article) {
            $byDay[$article->getPublicationDate()->format('Y-m-d')][] = $article;
        }

        $keywordsCreated = 0;
        foreach ($byDay as $dayArticles) {
            $keywordsCreated += $this->classificationService->classifyArticles($dayArticles)->suggestionsCreated;
        }

        return [\count($byDay), \count($articles), $keywordsCreated];
    }

    private function fetchAndParse(string $url): ?ParsedSitemap
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/xml, text/xml'],
                'timeout' => $this->requestTimeout,
            ]);
            if ($response->getStatusCode() >= 300) {
                return null;
            }
            $body = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('Sitemap injoignable, ignoré.', ['url' => $url, 'exception' => $e->getMessage()]);

            return null;
        }

        // Sitemaps compressés (`.xml.gz`), courants sur les gros sites d'actualité : le serveur ne
        // décompresse pas toujours via Content-Encoding, on tente donc explicitement gzdecode().
        if (str_ends_with(strtolower((string) (parse_url($url, PHP_URL_PATH) ?? '')), '.gz') && \function_exists('gzdecode')) {
            $decoded = @gzdecode($body);
            if (false !== $decoded) {
                $body = $decoded;
            }
        }

        try {
            return $this->sitemapParser->parse($body);
        } catch (SitemapParseException $e) {
            $this->logger->info('Sitemap ininterprétable, ignoré.', ['url' => $url, 'exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Écarte, parmi un lot d'au plus {@see self::PERSIST_BATCH_SIZE} entrées, celles déjà en base
     * (une requête bornée au lot, jamais à l'ensemble du média, cf. docblock "Mémoire" de la
     * classe) et les doublons internes au lot.
     *
     * @param list<SitemapEntry> $entries
     *
     * @return list<SitemapEntry>
     */
    private function deduplicate(array $entries): array
    {
        $hashByUrl = [];
        foreach ($entries as $entry) {
            $hashByUrl[$entry->url] = md5($entry->url);
        }

        $existing = array_flip($this->articleRepository->findExistingUrlHashes(array_values($hashByUrl)));

        $fresh = [];
        $seen = [];
        foreach ($entries as $entry) {
            $hash = $hashByUrl[$entry->url];
            if (isset($existing[$hash]) || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $fresh[] = $entry;
        }

        return $fresh;
    }

    private function resolveSiteBaseUrl(string $feedUrl): ?string
    {
        $scheme = parse_url($feedUrl, PHP_URL_SCHEME);
        $host = parse_url($feedUrl, PHP_URL_HOST);
        if (!\is_string($scheme) || !\is_string($host) || '' === $host) {
            return null;
        }

        return sprintf('%s://%s', $scheme, $host);
    }
}

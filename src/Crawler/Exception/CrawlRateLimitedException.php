<?php

namespace App\Crawler\Exception;

/**
 * Le quota de requêtes agrégé par domaine (§9.4) est épuisé : le crawl est abandonné sans avoir
 * tenté aucune requête HTTP plutôt que de bloquer le worker sur un `usleep()`, qui figerait tout
 * le traitement asynchrone en attendant un seul domaine (§9.4 "traitement asynchrone ... pour ne
 * jamais bloquer"). L'appelant (`CrawlArticleMetaMessageHandler`) reprogramme le message après
 * `retryAfter` plutôt que d'attendre en place.
 */
final class CrawlRateLimitedException extends \RuntimeException
{
    public function __construct(
        public readonly string $domain,
        public readonly \DateTimeImmutable $retryAfter,
    ) {
        parent::__construct(\sprintf('Quota de crawl atteint pour le domaine "%s".', $domain));
    }
}

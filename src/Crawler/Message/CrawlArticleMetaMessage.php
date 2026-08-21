<?php

namespace App\Crawler\Message;

/**
 * Déclenche un crawl de repli pour un article (§9.4), consommé de manière asynchrone via
 * Messenger pour ne jamais bloquer l'extraction RSS principale sur un site source lent.
 */
final readonly class CrawlArticleMetaMessage
{
    public function __construct(public int $articleId)
    {
    }
}

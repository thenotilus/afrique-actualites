<?php

namespace App\Feed\Exception;

/**
 * Levée lorsqu'un document sitemap ne peut pas être interprété (XML invalide, racine ni
 * `<urlset>` ni `<sitemapindex>`). L'appelant ({@see \App\Feed\FeedBackfiller}) l'attrape pour
 * journaliser le sitemap fautif et poursuivre avec les suivants, sans interrompre le backfill.
 */
final class SitemapParseException extends \RuntimeException
{
}

<?php

namespace App\Crawler;

/**
 * Extraction des métadonnées (titre, image, description) d'une page HTML (§9.4), en repli du flux
 * RSS lorsque celui-ci ne les fournit pas déjà.
 */
interface MetaExtractorInterface
{
    /**
     * @param string $html    contenu HTML brut de la page
     * @param string $pageUrl URL de la page, utilisée pour résoudre les images en chemin relatif
     */
    public function extract(string $html, string $pageUrl): CrawledMeta;
}

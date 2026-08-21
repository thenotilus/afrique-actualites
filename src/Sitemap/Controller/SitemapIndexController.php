<?php

namespace App\Sitemap\Controller;

use App\Article\Repository\ArticleRepository;
use App\Controller\SitemapController;
use App\Shared\ValueObject\Language;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Index de sitemaps à la racine (§3.10) : référence un sous-sitemap par langue. C'est la structure
 * "sitemap-index" que l'ancienne application n'avait pas (un unique `sitemap.xml` plat mêlant
 * accueil, pages "Unes" et 100 derniers articles, sans pagination). Voir `SitemapController` pour
 * le contenu de chaque sous-sitemap.
 *
 * Vit délibérément hors de `src/Controller/` (et non sous `src/Controller/Admin/` comme le
 * back-office) : `config/routes.yaml` préfixe tout `src/Controller/` par `/{_locale}`, or un
 * moteur de recherche attend l'index des sitemaps à la racine (`/sitemap.xml`). Le loader Symfony
 * de routes par attributs (`type: attribute` sur un répertoire) n'honore pas l'option `exclude`
 * pour un seul fichier au sein d'un répertoire déjà importé — la seule façon fiable d'obtenir une
 * route non préfixée est de sortir physiquement ce contrôleur du répertoire scanné, avec sa
 * propre ressource de routage dédiée (voir `config/routes.yaml`).
 */
class SitemapIndexController extends AbstractController
{
    public function __construct(private readonly ArticleRepository $articleRepository)
    {
    }

    #[Route('/sitemap.xml', name: 'app_sitemap_index')]
    public function index(): Response
    {
        $chunksByLocale = [];
        foreach (Language::cases() as $language) {
            $count = $this->articleRepository->countPublished($language);
            $chunksByLocale[$language->value] = max(1, (int) ceil($count / SitemapController::CHUNK_SIZE));
        }

        $response = $this->render('sitemap/index.xml.twig', [
            'chunksByLocale' => $chunksByLocale,
        ]);
        $response->headers->set('Content-Type', 'application/xml');

        return $response;
    }
}

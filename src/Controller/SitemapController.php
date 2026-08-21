<?php

namespace App\Controller;

use App\Article\Repository\ArticleRepository;
use App\Geography\Repository\CountryRepository;
use App\News\Repository\UserNewsRepository;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sous-sitemap d'une langue (§3.10), référencé par `SitemapIndexController`. Découpé par lots de
 * `CHUNK_SIZE` articles (`?page=N`) plutôt qu'un fichier plat unique — l'ancienne application se
 * limitait aux "100 derniers articles", sans notion de pagination ni d'archives complètes.
 */
class SitemapController extends AbstractController
{
    public const CHUNK_SIZE = 5000;

    #[Route('/sitemap.xml', name: 'app_sitemap_locale')]
    public function locale(
        Request $request,
        ArticleRepository $articleRepository,
        CountryRepository $countryRepository,
        UserNewsRepository $userNewsRepository,
        QueryPaginator $paginator,
    ): Response {
        $language = Language::from($request->getLocale());
        $page = max(1, $request->query->getInt('page', 1));
        $articles = $paginator->paginate($articleRepository->publishedQueryBuilder($language), $page, self::CHUNK_SIZE);

        $response = $this->render('sitemap/urlset.xml.twig', [
            'articles' => $articles->items,
            // Les pages statiques (accueil, annuaires) n'apparaissent que sur le premier lot :
            // inutile de les répéter dans chaque sous-sitemap découpé.
            'includeStaticPages' => 1 === $page,
            'countries' => 1 === $page ? $countryRepository->findActive() : [],
            'userNewsList' => 1 === $page ? $userNewsRepository->findPublic() : [],
        ]);
        $response->headers->set('Content-Type', 'application/xml');

        return $response;
    }
}

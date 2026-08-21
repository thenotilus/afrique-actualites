<?php

namespace App\Controller;

use App\Article\Repository\ArticleRepository;
use App\Feed\Repository\FeedRepository;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Repository\TaxonomyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques de liste et de détail d'articles (§6, §11.2 phase 6). Les listes (accueil, par
 * flux, par mot-clé) partagent le même gabarit (`public/_article_list.html.twig`) : seule la
 * requête de base change (§ prompt de conception, parcours 1.B).
 */
class ArticleController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly QueryPaginator $paginator,
    ) {
    }

    #[Route('/', name: 'app_home')]
    public function home(Request $request): Response
    {
        $language = Language::from($request->getLocale());
        $queryBuilder = $this->articleRepository->publishedQueryBuilder($language);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/home.html.twig', [
            'featuredArticle' => $this->articleRepository->findLatestSponsored($language),
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
        ]);
    }

    #[Route('/source/{id}', name: 'app_article_by_feed', requirements: ['id' => '\d+'])]
    public function byFeed(Request $request, FeedRepository $feedRepository, int $id): Response
    {
        $feed = $feedRepository->find($id);
        if (null === $feed || !$feed->isActive()) {
            throw $this->createNotFoundException();
        }

        $language = Language::from($request->getLocale());
        $queryBuilder = $this->articleRepository->byFeed($this->articleRepository->publishedQueryBuilder($language), $feed);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/article_list_by_feed.html.twig', [
            'feed' => $feed,
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
        ]);
    }

    #[Route('/tag/{label}', name: 'app_article_by_keyword')]
    public function byKeyword(Request $request, TaxonomyRepository $taxonomyRepository, string $label): Response
    {
        $language = Language::from($request->getLocale());
        $keyword = $taxonomyRepository->findOneByLabelAndLanguage($label, $language);
        if (null === $keyword || !$keyword->isValidated()) {
            throw $this->createNotFoundException();
        }

        $queryBuilder = $this->articleRepository->byKeyword($this->articleRepository->publishedQueryBuilder($language), $keyword);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/article_list_by_keyword.html.twig', [
            'keyword' => $keyword,
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
        ]);
    }

    #[Route('/article/{id}/{slug}', name: 'app_article_show', requirements: ['id' => '\d+'], defaults: ['slug' => ''])]
    public function show(int $id): Response
    {
        $article = $this->articleRepository->find($id);
        if (null === $article || !$article->isPublish()) {
            throw $this->createNotFoundException();
        }

        return $this->render('public/article_show.html.twig', [
            'article' => $article,
            'similarArticles' => $this->articleRepository->findSimilar($article),
        ]);
    }
}

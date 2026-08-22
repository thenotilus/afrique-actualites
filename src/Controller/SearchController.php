<?php

namespace App\Controller;

use App\Article\Repository\ArticleRepository;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\Taxonomy\Repository\TaxonomyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche publique (§3.5) : titre, description ou mot-clé validé, dans la langue courante.
 * Remplace la recherche par taxonomies uniquement de l'ancienne application (voir le docblock de
 * `ArticleRepository::bySearchQuery()`).
 */
class SearchController extends AbstractController
{
    private const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly TaxonomyRepository $taxonomyRepository,
        private readonly QueryPaginator $paginator,
    ) {
    }

    #[Route('/recherche', name: 'app_search')]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $language = Language::from($request->getLocale());

        $pagination = null;
        if (mb_strlen($query) >= self::MIN_QUERY_LENGTH) {
            $queryBuilder = $this->articleRepository->bySearchQuery($this->articleRepository->publishedQueryBuilder($language), $query);
            $page = max(1, $request->query->getInt('page', 1));
            $pagination = $this->paginator->paginate($queryBuilder, $page);
        }

        return $this->render('public/search.html.twig', [
            'query' => $query,
            'pagination' => $pagination,
            // Suggestions de rebond quand la recherche ne donne rien (§ prompt de conception,
            // parcours 1.F) : les mots-clés validés les plus récemment détectés dans la langue
            // courante, faute d'un vrai calcul de "tendance" (hors périmètre de cette phase).
            'trendingKeywords' => $this->taxonomyRepository->findBy(
                ['status' => TaxonomyStatus::VALIDATED, 'language' => $language],
                ['validatedAt' => 'DESC'],
                8,
            ),
        ]);
    }

    /**
     * Autocomplétion de la recherche (parcours 1.F) : renvoie en JSON les mots-clés validés et
     * les titres d'articles correspondant à la requête, dans la langue courante. Consommé par le
     * script du header ; volontairement muet (listes vides) sous le seuil de longueur.
     */
    #[Route('/recherche/suggestions', name: 'app_search_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $language = Language::from($request->getLocale());

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return $this->json(['keywords' => [], 'articles' => []]);
        }

        $keywords = array_map(fn (array $keyword): array => [
            'label' => $keyword['label'],
            'count' => $keyword['count'],
            'url' => $this->generateUrl('app_article_by_keyword', ['label' => $keyword['label']]),
        ], $this->articleRepository->suggestKeywords($query, $language));

        $articles = array_map(fn ($article): array => [
            'title' => $article->getTitle(),
            'url' => $this->generateUrl('app_article_show', ['id' => $article->getId()]),
        ], $this->articleRepository->suggestArticles($query, $language));

        return $this->json(['keywords' => $keywords, 'articles' => $articles]);
    }
}

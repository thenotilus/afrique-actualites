<?php

namespace App\Controller;

use App\Article\Repository\ArticleRepository;
use App\Geography\Repository\CountryRepository;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Repository\TaxonomyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Actualité par pays" (§3.13, écran prioritaire de la refonte) : annuaire des pays actifs, puis
 * pour un pays donné, ses articles avec un croisement optionnel par mot-clé validé et une
 * consultation d'archives paginée. Le mot-clé du croisement est optionnel (§12.2 point 1, resté
 * sans réponse du porteur produit à ce stade : on retient par défaut l'interprétation la plus
 * permissive — un filtre additionnel, pas une contrainte) et les archives ne sont pas bornées
 * dans le temps, seulement paginées (la lecture littérale du livrable de phase 6, "archives
 * paginées", n'impose pas de fenêtre glissante — voir docs/architecture.md).
 */
class CountryController extends AbstractController
{
    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly QueryPaginator $paginator,
    ) {
    }

    #[Route('/pays', name: 'app_country_index')]
    public function index(): Response
    {
        return $this->render('public/country_index.html.twig', [
            'countries' => $this->countryRepository->findActive(),
        ]);
    }

    #[Route('/pays/{code}', name: 'app_country_show')]
    public function show(Request $request, TaxonomyRepository $taxonomyRepository, string $code): Response
    {
        $country = $this->countryRepository->findOneByCode(strtoupper($code));
        if (null === $country || !$country->isActive()) {
            throw $this->createNotFoundException();
        }

        $language = Language::from($request->getLocale());
        $queryBuilder = $this->articleRepository->byCountry($this->articleRepository->publishedQueryBuilder($language), $country);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/country_show.html.twig', [
            'country' => $country,
            'keyword' => null,
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
            'availableKeywords' => $taxonomyRepository->findValidatedForCountry($country, $language),
        ]);
    }

    #[Route('/pays/{code}/{label}', name: 'app_country_by_keyword')]
    public function byKeyword(Request $request, TaxonomyRepository $taxonomyRepository, string $code, string $label): Response
    {
        $country = $this->countryRepository->findOneByCode(strtoupper($code));
        if (null === $country || !$country->isActive()) {
            throw $this->createNotFoundException();
        }

        $language = Language::from($request->getLocale());
        $keyword = $taxonomyRepository->findOneByLabelAndLanguage($label, $language);
        if (null === $keyword || !$keyword->isValidated()) {
            throw $this->createNotFoundException();
        }

        $queryBuilder = $this->articleRepository->byCountry($this->articleRepository->publishedQueryBuilder($language), $country);
        $queryBuilder = $this->articleRepository->byKeyword($queryBuilder, $keyword);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/country_show.html.twig', [
            'country' => $country,
            'keyword' => $keyword,
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
            'availableKeywords' => $taxonomyRepository->findValidatedForCountry($country, $language),
        ]);
    }
}

<?php

namespace App\Controller;

use App\Geography\Enum\Region;
use App\Geography\Repository\CountryRepository;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use App\Synthesis\Repository\SynthesisRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques des synthèses hebdomadaires publiées : l'annuaire paginé (§ deliverable 4) et
 * la page de détail. `{scope}` est soit un code pays ISO (ex. "sn"), soit le slug d'une région de
 * repli (ex. "afrique-ouest", voir `Region::slug()`) — on tente d'abord le pays, qui couvre
 * l'immense majorité des cas.
 */
class SynthesisController extends AbstractController
{
    public function __construct(
        private readonly SynthesisRepository $synthesisRepository,
        private readonly CountryRepository $countryRepository,
        private readonly QueryPaginator $paginator,
    ) {
    }

    #[Route('/syntheses', name: 'app_synthesis_index')]
    public function index(Request $request): Response
    {
        $language = Language::from($request->getLocale());
        $queryBuilder = $this->synthesisRepository->publishedQueryBuilder($language);
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('public/synthesis_index.html.twig', [
            'pagination' => $this->paginator->paginate($queryBuilder, $page),
        ]);
    }

    #[Route('/synthese/{scope}/{semaine}', name: 'app_synthesis_show', requirements: ['semaine' => '\d{4}-\d{2}-\d{2}'])]
    public function show(Request $request, string $scope, string $semaine): Response
    {
        $language = Language::from($request->getLocale());
        $weekStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $semaine);
        if (false === $weekStart) {
            throw $this->createNotFoundException();
        }

        $country = $this->countryRepository->findOneByCode(strtoupper($scope));
        if (null !== $country && $country->isActive()) {
            $synthesis = $this->synthesisRepository->findOnePublishedForCountryAndWeek($country, $language, $weekStart);
        } else {
            $region = Region::fromSlug($scope);
            $synthesis = null !== $region
                ? $this->synthesisRepository->findOnePublishedForRegionAndWeek($region, $language, $weekStart)
                : null;
        }

        if (null === $synthesis) {
            throw $this->createNotFoundException();
        }

        $weekOptions = null !== $synthesis->getCountry()
            ? $this->synthesisRepository->findPublishedForCountry($synthesis->getCountry(), $language, 52)
            : $this->synthesisRepository->findPublishedForRegion($synthesis->getRegion(), $language, 52);

        return $this->render('public/synthesis_show.html.twig', [
            'synthesis' => $synthesis,
            'weekOptions' => $weekOptions,
            'scopeOptions' => $this->synthesisRepository->findLatestPerScope($language),
        ]);
    }
}

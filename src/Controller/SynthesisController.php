<?php

namespace App\Controller;

use App\Geography\Enum\Region;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use App\Synthesis\Repository\SynthesisRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique d'une synthèse hebdomadaire publiée (§ "Route(s) et vue(s) publiques"). `{scope}`
 * est soit un code pays ISO (ex. "sn"), soit le slug d'une région de repli (ex. "afrique-ouest",
 * voir `Region::slug()`) — on tente d'abord le pays, qui couvre l'immense majorité des cas.
 *
 * Volontairement pas d'annuaire dédié à ce stade (deliverable 4 : "liées depuis les pages /pays")
 * — seule la page pays (`CountryController::show`) liste les synthèses publiées d'un pays, via
 * `SynthesisRepository::findPublishedForCountry()`.
 */
class SynthesisController extends AbstractController
{
    public function __construct(
        private readonly SynthesisRepository $synthesisRepository,
        private readonly CountryRepository $countryRepository,
    ) {
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

        return $this->render('public/synthesis_show.html.twig', ['synthesis' => $synthesis]);
    }
}

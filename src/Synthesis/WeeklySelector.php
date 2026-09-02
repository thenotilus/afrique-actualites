<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Article\Repository\ArticleRepository;
use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Geography\Repository\CountryRepository;
use App\Shared\ValueObject\Language;
use Psr\Log\LoggerInterface;

/**
 * Étape 1 du pipeline (§ "Sélection et regroupement") : pour chaque pays actif, récupère ses
 * articles publiés de la semaine ; s'il n'en a pas assez pour une synthèse dédiée, ses articles
 * rejoignent le repli régional de `Country::$region` plutôt que d'être ignorés.
 *
 * Le même seuil minimal (`$minArticlesPerCountry`, § "Seuil minimum") s'applique aux regroupements
 * régionaux : la spec ne fixe pas de seuil dédié pour ces derniers, et générer une synthèse
 * "régionale" à partir d'un seul article resterait aussi peu représentatif qu'une synthèse pays
 * sous le seuil — une région qui n'atteint pas non plus le seuil est donc simplement omise cette
 * semaine-là plutôt que de produire une synthèse creuse.
 */
final class WeeklySelector
{
    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly LoggerInterface $logger,
        private readonly int $minArticlesPerCountry,
    ) {
    }

    public function selectForWeek(\DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd, Language $language): WeeklySelectionResult
    {
        $countrySelections = [];
        /** @var array<string, list<Article>> $leftoverByRegion */
        $leftoverByRegion = [];
        /** @var array<string, Region> $regionsByValue */
        $regionsByValue = [];

        foreach ($this->countryRepository->findActive() as $country) {
            $articles = $this->fetchArticles($country, $weekStart, $weekEnd, $language);

            if (count($articles) >= $this->minArticlesPerCountry) {
                $this->logger->info('Synthèse : pays sélectionné pour une synthèse dédiée.', [
                    'country' => $country->getCode(),
                    'language' => $language->value,
                    'articles' => count($articles),
                ]);
                $countrySelections[] = new CountrySelection($country, $articles);

                continue;
            }

            if ([] === $articles) {
                continue;
            }

            $region = $country->getRegion();
            if (null === $region) {
                $this->logger->warning('Synthèse : pays sous le seuil sans région de repli, articles ignorés cette semaine.', [
                    'country' => $country->getCode(),
                    'language' => $language->value,
                    'articles' => count($articles),
                ]);

                continue;
            }

            $regionsByValue[$region->value] = $region;
            $leftoverByRegion[$region->value] = array_merge($leftoverByRegion[$region->value] ?? [], $articles);

            $this->logger->info('Synthèse : pays sous le seuil, articles reportés sur le repli régional.', [
                'country' => $country->getCode(),
                'region' => $region->value,
                'language' => $language->value,
                'articles' => count($articles),
            ]);
        }

        $regionSelections = [];
        foreach ($leftoverByRegion as $regionValue => $articles) {
            if (count($articles) < $this->minArticlesPerCountry) {
                $this->logger->info('Synthèse : région sous le seuil malgré le repli, aucune synthèse régionale cette semaine.', [
                    'region' => $regionValue,
                    'language' => $language->value,
                    'articles' => count($articles),
                ]);

                continue;
            }

            $this->logger->info('Synthèse : région sélectionnée pour une synthèse de repli.', [
                'region' => $regionValue,
                'language' => $language->value,
                'articles' => count($articles),
            ]);
            $regionSelections[] = new RegionSelection($regionsByValue[$regionValue], $articles);
        }

        return new WeeklySelectionResult($countrySelections, $regionSelections);
    }

    /** @return list<Article> */
    private function fetchArticles(Country $country, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd, Language $language): array
    {
        $queryBuilder = $this->articleRepository->publishedQueryBuilder($language);
        $queryBuilder = $this->articleRepository->byCountry($queryBuilder, $country);
        $queryBuilder = $this->articleRepository->betweenDates($queryBuilder, $weekStart, $weekEnd);

        return $queryBuilder->getQuery()->getResult();
    }
}

<?php

namespace App\Synthesis;

/** Résultat de {@see WeeklySelector::selectForWeek()}. */
final readonly class WeeklySelectionResult
{
    /**
     * @param list<CountrySelection> $countrySelections
     * @param list<RegionSelection>  $regionSelections
     */
    public function __construct(
        public array $countrySelections,
        public array $regionSelections,
    ) {
    }
}

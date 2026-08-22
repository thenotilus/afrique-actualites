<?php

namespace App\Shared\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Convertit un code pays ISO 3166-1 alpha-2 (ex. « SN ») en emoji drapeau (« 🇸🇳 »).
 *
 * La maquette du site public (parcours 1, écrans A/C/E) affiche systématiquement le drapeau
 * à côté du nom du pays. Plutôt que de stocker une image par pays, on s'appuie sur les
 * « regional indicator symbols » Unicode : chaque lettre du code est décalée vers le bloc
 * U+1F1E6..U+1F1FF, et la paire forme le drapeau. Un code invalide renvoie un globe neutre.
 */
final class CountryFlagExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('country_flag', $this->countryFlag(...)),
        ];
    }

    public function countryFlag(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '🌍';
        }

        $base = 0x1F1E6; // Regional Indicator Symbol Letter A
        $offsetA = \ord('A');

        return mb_chr($base + (\ord($code[0]) - $offsetA))
            .mb_chr($base + (\ord($code[1]) - $offsetA));
    }
}

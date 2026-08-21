<?php

namespace App\Classification\Pipeline;

use App\Shared\ValueObject\Language;

/**
 * Racinisation / regroupement de variantes grammaticales d'un même terme (§10.1.5).
 *
 * L'ancienne application n'en avait aucune (§4.3 L3) : "élection", "élections", "électorale"
 * étaient trois taxonomies distinctes, ce qui diluait la fréquence réelle d'un même sujet.
 */
interface StemmerInterface
{
    public function stem(string $token, Language $language): string;
}

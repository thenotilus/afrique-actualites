<?php

namespace App\Classification\Pipeline;

use App\Shared\ValueObject\Language;

/**
 * Filtrage des mots vides ("stopwords"), par langue (§10.1.4).
 *
 * L'ancienne application n'avait aucune liste de mots vides (§4.3 L2) : des mots grammaticaux
 * comme "dans", "pour", "avec" étaient traités comme des candidats mots-clés au même titre que
 * des noms propres.
 */
interface StopWordFilterInterface
{
    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    public function filter(array $tokens, Language $language): array;
}

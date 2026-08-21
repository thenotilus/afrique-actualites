<?php

namespace App\Classification\Pipeline;

use App\Shared\ValueObject\Language;

/**
 * Racinisation heuristique légère par découpe de suffixes courants — **pas** un stemmer
 * linguistique complet de type Snowball/Porter (§10.1.5). Objectif : regrouper les variantes
 * grammaticales les plus fréquentes (pluriels, quelques suffixes verbaux) sans dépendance externe
 * ni dictionnaire.
 *
 * Limite connue et assumée : quelques mots invariants ou irréguliers sont mal traités (ex. "pays"
 * → "pay"). Remplaçable par un stemmer linguistique dédié (ex. `wamania/php-stemmer`, évoqué en
 * §10.1.3) derrière la même interface sans changer le reste du pipeline.
 */
final class LightSuffixStemmer implements StemmerInterface
{
    private const MIN_STEM_LENGTH = 4;

    public function stem(string $token, Language $language): string
    {
        return match ($language) {
            Language::FRENCH => $this->stemFrench($token),
            Language::ENGLISH => $this->stemEnglish($token),
        };
    }

    private function stemFrench(string $token): string
    {
        if (str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
            $stripped = substr($token, 0, -1);
            if (mb_strlen($stripped) >= self::MIN_STEM_LENGTH) {
                return $stripped;
            }
        }

        return $token;
    }

    private function stemEnglish(string $token): string
    {
        $suffixes = ['ies' => 'y', 'ing' => '', 'ed' => '', 'es' => ''];

        foreach ($suffixes as $suffix => $replacement) {
            if (str_ends_with($token, $suffix)) {
                $stripped = substr($token, 0, -mb_strlen($suffix)).$replacement;
                if (mb_strlen($stripped) >= self::MIN_STEM_LENGTH) {
                    return $stripped;
                }
            }
        }

        if (str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
            $stripped = substr($token, 0, -1);
            if (mb_strlen($stripped) >= self::MIN_STEM_LENGTH) {
                return $stripped;
            }
        }

        return $token;
    }
}

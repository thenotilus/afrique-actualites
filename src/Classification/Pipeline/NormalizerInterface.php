<?php

namespace App\Classification\Pipeline;

/**
 * Nettoyage et normalisation Unicode d'un texte avant tokenisation (§10.1.2).
 *
 * Remplace la translittération accent-par-accent codée en dur de l'ancienne application
 * (`Utils::cleanText`, table de 54 caractères Latin-1 via `utf8_decode` — fonction dépréciée et
 * supprimée en PHP 9, qui cassait sur tout caractère hors Europe de l'Ouest, cf. §4.3 L4).
 */
interface NormalizerInterface
{
    /**
     * @return string Texte en minuscules, sans diacritiques, ponctuation réduite à des espaces
     */
    public function normalize(string $text): string;
}

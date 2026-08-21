<?php

namespace App\Shared\ValueObject;

/**
 * Langue supportée par un flux, un article ou une taxonomie.
 *
 * Voir §3bis de la documentation fonctionnelle : le site agrège aujourd'hui uniquement des flux
 * francophones, mais la refonte doit gérer des flux anglophones de bout en bout. La liste est
 * volontairement un enum fermé (et non une simple chaîne libre) pour que chaque nouvelle langue
 * supportée soit une décision explicite (ajout d'un jeu de stopwords/stemming/entités nommées
 * dédié dans le pipeline de classification, §10.1.9), pas un accident de saisie.
 */
enum Language: string
{
    case FRENCH = 'fr';
    case ENGLISH = 'en';

    public function label(): string
    {
        return match ($this) {
            self::FRENCH => 'Français',
            self::ENGLISH => 'English',
        };
    }
}

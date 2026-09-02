<?php

namespace App\Synthesis\Enum;

/**
 * Sous-thème utilisé pour regrouper les articles d'un pays/région avant le résumé par cluster
 * (§ "Clustering par sous-thème"). Liste fermée volontairement courte : assez de sous-thèmes pour
 * qu'une synthèse hebdomadaire structure vraiment l'actualité, pas assez pour fragmenter un lot
 * d'une semaine (5 à quelques dizaines d'articles pour un pays) en clusters de un ou deux articles.
 *
 * GENERAL est le repli explicite d'`ArticleThemeClassifier` quand aucun mot-clé du dictionnaire
 * thématique ne correspond — jamais un thème qu'un article obtiendrait "par défaut" au sens d'un
 * choix arbitraire, seulement l'absence documentée de correspondance.
 */
enum Theme: string
{
    case ELECTIONS = 'elections';
    case DIPLOMACY = 'diplomatie';
    case ECONOMY = 'economie';
    case SECURITY = 'securite';
    case JUSTICE = 'justice';
    case SOCIETY = 'societe';
    case HEALTH = 'sante';
    case ENVIRONMENT = 'environnement';
    case CULTURE_SPORT = 'culture_sport';
    case GENERAL = 'actualite_generale';

    public function labelFr(): string
    {
        return match ($this) {
            self::ELECTIONS => 'Élections',
            self::DIPLOMACY => 'Diplomatie',
            self::ECONOMY => 'Économie',
            self::SECURITY => 'Sécurité',
            self::JUSTICE => 'Justice',
            self::SOCIETY => 'Société',
            self::HEALTH => 'Santé',
            self::ENVIRONMENT => 'Environnement',
            self::CULTURE_SPORT => 'Culture & sport',
            self::GENERAL => 'Actualité générale',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::ELECTIONS => 'Elections',
            self::DIPLOMACY => 'Diplomacy',
            self::ECONOMY => 'Economy',
            self::SECURITY => 'Security',
            self::JUSTICE => 'Justice',
            self::SOCIETY => 'Society',
            self::HEALTH => 'Health',
            self::ENVIRONMENT => 'Environment',
            self::CULTURE_SPORT => 'Culture & sport',
            self::GENERAL => 'General news',
        };
    }
}

<?php

namespace App\Synthesis\Enum;

/**
 * Circuit de validation éditoriale d'une synthèse hebdomadaire générée automatiquement (§ "Workflow
 * de validation"), calqué sur `App\Taxonomy\Enum\TaxonomyStatus`.
 *
 * Une synthèse générée par `SynthesisGenerator` est toujours créée au statut DRAFT — sauf si le
 * flag de configuration `synthesis.auto_publish` (env `AUTO_PUBLISH`) est activé, auquel cas elle
 * passe directement à PUBLISHED. Tant que ce flag reste désactivé (comportement par défaut, le
 * temps de fiabiliser le pipeline sur plusieurs semaines), un administrateur doit relire chaque
 * brouillon dans le back-office et le publier ou le rejeter explicitement.
 */
enum SynthesisStatus: string
{
    case DRAFT = 'brouillon';
    case PUBLISHED = 'publie';
    case REJECTED = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::PUBLISHED => 'Publiée',
            self::REJECTED => 'Rejetée',
        };
    }
}

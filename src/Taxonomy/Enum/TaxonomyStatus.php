<?php

namespace App\Taxonomy\Enum;

/**
 * Statut du circuit de validation éditoriale des mots-clés (§4.4 et §10.4 de la documentation
 * fonctionnelle).
 *
 * Une taxonomie nouvellement détectée par le pipeline de classification est toujours créée au
 * statut SUGGESTED. Elle ne devient un mot-clé utilisable pour classer/taguer les articles
 * (Article::addKeyword) qu'une fois passée à VALIDATED par un administrateur, dans le back-office
 * (§3.12). REJECTED mémorise les faux positifs pour ne pas les re-proposer indéfiniment.
 */
enum TaxonomyStatus: string
{
    case SUGGESTED = 'suggested';
    case VALIDATED = 'validated';
    case REJECTED = 'rejected';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::SUGGESTED => 'Suggéré',
            self::VALIDATED => 'Validé',
            self::REJECTED => 'Rejeté',
            self::ARCHIVED => 'Archivé',
        };
    }
}

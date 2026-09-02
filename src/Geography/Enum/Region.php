<?php

namespace App\Geography\Enum;

use App\Shared\ValueObject\Language;

/**
 * Regroupement régional des pays, utilisé comme repli par les synthèses hebdomadaires
 * (`App\Synthesis`) quand un pays actif n'a pas assez d'articles sur la semaine pour une synthèse
 * dédiée (voir `WeeklySelector::MIN_ARTICLES_PER_COUNTRY`). Vit dans `Geography` — pas dans
 * `Synthesis`, qui n'en est qu'un consommateur — au même titre que `Country`, dont il est un
 * attribut (`Country::$region`).
 *
 * Découpage aligné sur l'usage courant des médias francophones (proche des communautés
 * économiques régionales de l'Union africaine — CEDEAO, CEEAC, EAC/IGAD, SADC, UMA — plutôt que
 * sur le découpage UN M49, moins parlant pour un lectorat grand public).
 */
enum Region: string
{
    case WEST_AFRICA = 'west_africa';
    case CENTRAL_AFRICA = 'central_africa';
    case EAST_AFRICA = 'east_africa';
    case SOUTHERN_AFRICA = 'southern_africa';
    case NORTH_AFRICA = 'north_africa';

    public function labelFr(): string
    {
        return match ($this) {
            self::WEST_AFRICA => 'Afrique de l\'Ouest',
            self::CENTRAL_AFRICA => 'Afrique centrale',
            self::EAST_AFRICA => 'Afrique de l\'Est',
            self::SOUTHERN_AFRICA => 'Afrique australe',
            self::NORTH_AFRICA => 'Afrique du Nord',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::WEST_AFRICA => 'West Africa',
            self::CENTRAL_AFRICA => 'Central Africa',
            self::EAST_AFRICA => 'East Africa',
            self::SOUTHERN_AFRICA => 'Southern Africa',
            self::NORTH_AFRICA => 'North Africa',
        };
    }

    public function label(Language $language): string
    {
        return Language::ENGLISH === $language ? $this->labelEn() : $this->labelFr();
    }

    /** Identifiant lisible utilisé dans les URL publiques (route `app_synthesis_show`). */
    public function slug(): string
    {
        return match ($this) {
            self::WEST_AFRICA => 'afrique-ouest',
            self::CENTRAL_AFRICA => 'afrique-centrale',
            self::EAST_AFRICA => 'afrique-est',
            self::SOUTHERN_AFRICA => 'afrique-australe',
            self::NORTH_AFRICA => 'afrique-nord',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $region) {
            if ($region->slug() === $slug) {
                return $region;
            }
        }

        return null;
    }
}

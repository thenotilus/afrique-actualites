<?php

namespace App\Crawler\ValueObject;

/**
 * Un profil de bot du pool de crawling (§9.4 de la documentation fonctionnelle) : un user-agent
 * distinct, des en-têtes HTTP additionnels et un délai d'attente propres.
 *
 * Le user-agent doit toujours s'identifier explicitement comme un bot d'Afrique Actualités (nom,
 * URL de contact) — jamais usurper un navigateur ou un autre robot pour contourner un blocage,
 * ce qui violerait les conditions d'usage des sites sources (§9.4 "respect du web source").
 */
final readonly class BotProfile
{
    /** @param array<string, string> $additionalHeaders */
    public function __construct(
        public string $name,
        public string $userAgent,
        public array $additionalHeaders = [],
        public float $timeoutSeconds = 8.0,
    ) {
    }
}

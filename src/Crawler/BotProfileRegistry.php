<?php

namespace App\Crawler;

use App\Crawler\ValueObject\BotProfile;

/**
 * Pool configurable de profils de bots (§9.4), chargé depuis `config/packages/crawler.yaml`.
 * `CrawlerService` les essaie dans l'ordre déclaré jusqu'à obtenir un résultat exploitable.
 */
final class BotProfileRegistry
{
    /** @var list<BotProfile> */
    private array $profiles;

    /**
     * @param list<array{name: string, user_agent: string, additional_headers?: array<string, string>, timeout?: float}> $profileConfigs
     */
    public function __construct(array $profileConfigs)
    {
        if ([] === $profileConfigs) {
            throw new \LogicException('Le pool de profils de bots (crawler.bot_profiles) ne peut pas être vide.');
        }

        $this->profiles = array_map(
            static fn (array $config): BotProfile => new BotProfile(
                $config['name'],
                $config['user_agent'],
                $config['additional_headers'] ?? [],
                $config['timeout'] ?? 8.0,
            ),
            $profileConfigs,
        );
    }

    /** @return list<BotProfile> */
    public function all(): array
    {
        return $this->profiles;
    }
}

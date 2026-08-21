<?php

namespace App\Crawler;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Respect des règles d'exclusion `robots.txt` du site source (§9.4). Implémentation volontairement
 * simplifiée par rapport à la RFC 9309 complète : seul le groupe de règles `User-agent: *` est
 * interprété (cas très majoritaire en pratique), avec la sémantique standard "la règle
 * `Disallow`/`Allow` au préfixe le plus long l'emporte". Un `robots.txt` absent, vide ou en erreur
 * est traité comme une autorisation totale, conformément à la pratique courante.
 *
 * Les règles analysées sont mises en cache en mémoire pour la durée du process (un worker de
 * crawling long-vivant ne refait donc pas la requête à chaque article), mais pas au-delà : ce
 * n'est pas le cache PSR-6 par URL utilisé pour les résultats de crawl eux-mêmes (§9.4).
 */
final class RobotsTxtChecker
{
    /** @var array<string, list<array{prefix: string, allow: bool}>> */
    private array $rulesByHost = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $userAgent est accepté pour la symétrie de l'API appelante (`CrawlerService` transmet le
     * user-agent du profil en cours) mais n'est pas encore exploité : seul le groupe générique
     * `User-agent: *` est interprété (voir le docblock de la classe). Un site ciblant
     * spécifiquement notre bot par son user-agent resterait donc mal couvert — limite assumée.
     */
    public function isAllowed(string $url, string $userAgent): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if (!\is_string($host) || '' === $host) {
            return true;
        }

        $rules = $this->getRules($host, parse_url($url, PHP_URL_SCHEME) ?: 'https');

        $matchedRule = null;
        foreach ($rules as $rule) {
            if (!str_starts_with($path, $rule['prefix'])) {
                continue;
            }
            if (null === $matchedRule || \strlen($rule['prefix']) > \strlen($matchedRule['prefix'])) {
                $matchedRule = $rule;
            }
        }

        return $matchedRule['allow'] ?? true;
    }

    /** @return list<array{prefix: string, allow: bool}> */
    private function getRules(string $host, string $scheme): array
    {
        if (isset($this->rulesByHost[$host])) {
            return $this->rulesByHost[$host];
        }

        $rules = $this->fetchAndParseRules($host, $scheme);
        $this->rulesByHost[$host] = $rules;

        return $rules;
    }

    /** @return list<array{prefix: string, allow: bool}> */
    private function fetchAndParseRules(string $host, string $scheme): array
    {
        try {
            $response = $this->httpClient->request('GET', \sprintf('%s://%s/robots.txt', $scheme, $host), ['timeout' => 5]);
            if ($response->getStatusCode() >= 300) {
                return [];
            }
            $body = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('robots.txt ininterprétable, autorisation par défaut.', ['host' => $host, 'exception' => $e->getMessage()]);

            return [];
        }

        return $this->parse($body);
    }

    /** @return list<array{prefix: string, allow: bool}> */
    private function parse(string $body): array
    {
        $rules = [];
        $inWildcardGroup = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = preg_replace('/#.*$/', '', $line) ?? $line;
            $line = trim($line);
            if ('' === $line || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));

            if ('user-agent' === strtolower($field)) {
                $inWildcardGroup = '*' === $value;
                continue;
            }

            if (!$inWildcardGroup || '' === $value) {
                continue;
            }

            if ('disallow' === strtolower($field)) {
                $rules[] = ['prefix' => $value, 'allow' => false];
            } elseif ('allow' === strtolower($field)) {
                $rules[] = ['prefix' => $value, 'allow' => true];
            }
        }

        return $rules;
    }
}

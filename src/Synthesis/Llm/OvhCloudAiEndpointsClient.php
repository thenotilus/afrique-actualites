<?php

namespace App\Synthesis\Llm;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API OVHcloud AI Endpoints (https://endpoints.ai.cloud.ovh.net/), fournisseur LLM
 * retenu pour le pipeline de synthèses hebdomadaires. Compatible OpenAI (`/v1/chat/completions`,
 * `Authorization: Bearer <token>`, corps `{model, messages, temperature, max_tokens}`) : seuls
 * `$baseUrl` et le modèle passé à `complete()` sont spécifiques à OVHcloud (`synthesis.yaml`), le
 * reste de l'implémentation vaudrait pour toute passerelle compatible OpenAI.
 *
 * `$baseUrl` et `$apiToken` viennent respectivement de `synthesis.llm.base_url` (paramètre) et de
 * l'env `OVH_AI_ENDPOINTS_TOKEN` (secret — jamais committé, cf. `.env` local ou secrets de
 * production). Les identifiants de modèle disponibles dépendent du catalogue AI Endpoints du
 * compte OVHcloud utilisé ; ils sont fournis par l'appelant (`synthesis.llm.map_model` /
 * `synthesis.llm.reduce_model`), pas devinés ici.
 */
final class OvhCloudAiEndpointsClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiToken,
    ) {
    }

    public function complete(string $systemPrompt, string $userPrompt, string $model, float $temperature = 0.3, int $maxTokens = 1024): string
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/chat/completions', [
                'auth_bearer' => $this->apiToken,
                'timeout' => 60.0,
                'json' => [
                    'model' => $model,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
            ]);

            $data = $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Synthèse : appel OVHcloud AI Endpoints échoué.', [
                'model' => $model,
                'exception' => $e->getMessage(),
            ]);

            throw new LlmGenerationException(sprintf('Appel à OVHcloud AI Endpoints (modèle "%s") échoué.', $model), previous: $e);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || '' === trim($content)) {
            $this->logger->error('Synthèse : réponse OVHcloud AI Endpoints vide ou invalide.', ['model' => $model]);

            throw new LlmGenerationException(sprintf('Réponse vide ou invalide d\'OVHcloud AI Endpoints (modèle "%s").', $model));
        }

        return $content;
    }
}

<?php

namespace App\Synthesis\Llm;

/**
 * Abstraction du fournisseur LLM utilisé par le pipeline de synthèses (§ "Résumé par cluster" et
 * "Assemblage final"). `ClusterSummarizer` (map) et `SynthesisAssembler` (reduce) n'en dépendent
 * que via cette interface — le fournisseur (aujourd'hui l'API OVHcloud AI Endpoints, voir
 * `OvhCloudAiEndpointsClient`) reste remplaçable sans toucher au reste du pipeline, à l'image des
 * autres étapes interchangeables du dépôt (`Classification\Pipeline\*Interface`).
 *
 * Le modèle est un paramètre de l'appel, pas de l'implémentation : le map (beaucoup de petits
 * appels, un par cluster) et le reduce (un appel plus exigeant par synthèse) peuvent ainsi utiliser
 * des modèles différents sans deux implémentations de client.
 */
interface LlmClientInterface
{
    /**
     * @throws LlmGenerationException si l'appel échoue ou si la réponse est vide/invalide
     */
    public function complete(string $systemPrompt, string $userPrompt, string $model, float $temperature = 0.3, int $maxTokens = 1024): string;
}

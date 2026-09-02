<?php

namespace App\Tests\Synthesis;

use App\Synthesis\Llm\LlmClientInterface;

/**
 * Double de test pour `LlmClientInterface` : pas d'appel réseau, réponses déterministes. Le
 * "reduce" (`SynthesisAssembler`) attend un JSON `{"title", "lead"}`, reconnu ici au prompt système
 * qui l'exige explicitement (voir `SynthesisAssembler::buildSystemPrompt()`) ; tout autre appel
 * (le "map", `ClusterSummarizer`) reçoit un paragraphe simple.
 */
final class FakeLlmClient implements LlmClientInterface
{
    /** @var list<array{system: string, user: string, model: string}> */
    public array $calls = [];

    public function complete(string $systemPrompt, string $userPrompt, string $model, float $temperature = 0.3, int $maxTokens = 1024): string
    {
        $this->calls[] = ['system' => $systemPrompt, 'user' => $userPrompt, 'model' => $model];

        if (str_contains($systemPrompt, 'JSON')) {
            return '{"title": "Titre généré", "lead": "Chapô généré."}';
        }

        return 'Paragraphe factuel généré pour ce cluster.';
    }
}

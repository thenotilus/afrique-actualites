<?php

namespace App\Synthesis;

use App\Shared\ValueObject\Language;
use App\Synthesis\Enum\Theme;
use App\Synthesis\Llm\LlmClientInterface;
use App\Synthesis\Llm\LlmGenerationException;
use Psr\Log\LoggerInterface;

/**
 * Étape 4 du pipeline, "reduce" (§ "Assemblage final") : à partir des paragraphes déjà produits
 * par cluster (`ClusterSummarizer`), produit un titre et un chapô de synthèse hebdomadaire.
 *
 * Simplification assumée par rapport à la lettre de la spec ("un appel LLM qui... produit un
 * titre, un chapô, et le corps structuré en sections") : le corps est assemblé ici de façon
 * déterministe (une section HTML par cluster, dans l'ordre déjà produit par `ArticleClusterer`),
 * pas régénéré par le LLM. Faire réécrire l'intégralité des paragraphes du map dans un unique
 * appel reduce ajouterait un aller-retour JSON fragile (sections mal réordonnées, thème omis ou
 * dupliqué par le modèle) pour un gain éditorial marginal — les paragraphes du map sont déjà
 * factuels et neutres par construction. Le seul appel LLM de cette étape porte donc sur ce qui a
 * vraiment besoin d'une vue d'ensemble : un titre et un chapô qui résument l'ensemble des clusters.
 *
 * Sécurité : le LLM ne renvoie que du texte (JSON `{"title", "lead"}"`), jamais du HTML. Le corps
 * (`body`) que persiste `Synthesis` est construit ici en échappant nous-mêmes chaque paragraphe
 * avant de l'insérer dans les balises `<h3>`/`<p>` — le LLM n'a donc jamais la main sur le HTML
 * réellement stocké/affiché.
 */
final class SynthesisAssembler
{
    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /**
     * @param list<array{theme: Theme, paragraph: string}> $clusterParagraphs ordre déjà décidé
     *                                                                        par `ArticleClusterer` (clusters les plus fournis d'abord)
     */
    public function assemble(
        string $scopeLabel,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd,
        array $clusterParagraphs,
        Language $language,
    ): AssembledSynthesis {
        $this->logger->info('Synthèse : assemblage final (reduce).', [
            'scope' => $scopeLabel,
            'clusters' => count($clusterParagraphs),
            'language' => $language->value,
            'model' => $this->model,
        ]);

        $response = $this->llmClient->complete(
            $this->buildSystemPrompt($language),
            $this->buildUserPrompt($scopeLabel, $weekStart, $weekEnd, $clusterParagraphs, $language),
            $this->model,
            0.4,
            300,
        );

        [$title, $lead] = $this->parseResponse($response);
        $body = $this->buildBody($clusterParagraphs, $language);

        $this->logger->info('Synthèse : assemblage terminé.', ['scope' => $scopeLabel, 'title' => $title]);

        return new AssembledSynthesis($title, $lead, $body);
    }

    private function buildSystemPrompt(Language $language): string
    {
        if (Language::ENGLISH === $language) {
            return 'You are a journalist for Afrique Actualités. Given paragraphs already summarizing '
                .'this week\'s news on several sub-topics for a country or region, write a headline '
                .'and a short lead (2 to 3 sentences). Stay strictly factual and neutral; do not '
                .'invent facts beyond what the paragraphs say. Reply with nothing but a JSON object '
                .'of the exact form {"title": "...", "lead": "..."}, no markdown, no commentary.';
        }

        return 'Tu es journaliste pour Afrique Actualités. À partir de paragraphes résumant déjà '
            .'l\'actualité de la semaine sur plusieurs sous-thèmes pour un pays ou une région, '
            .'rédige un titre et un chapô court (2 à 3 phrases). Reste strictement factuel et '
            .'neutre ; n\'invente aucun fait au-delà de ce que disent les paragraphes. Réponds '
            .'uniquement avec un objet JSON de la forme exacte {"title": "...", "lead": "..."}, '
            .'sans markdown, sans commentaire.';
    }

    /** @param list<array{theme: Theme, paragraph: string}> $clusterParagraphs */
    private function buildUserPrompt(
        string $scopeLabel,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd,
        array $clusterParagraphs,
        Language $language,
    ): string {
        $sections = '';
        foreach ($clusterParagraphs as $entry) {
            $themeLabel = Language::ENGLISH === $language ? $entry['theme']->labelEn() : $entry['theme']->labelFr();
            $sections .= sprintf("[%s]\n%s\n\n", $themeLabel, $entry['paragraph']);
        }

        $period = sprintf('%s – %s', $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));

        return sprintf("%s — week of %s\n\n%s", $scopeLabel, $period, $sections);
    }

    /** @return array{0: string, 1: string} [titre, chapô] */
    private function parseResponse(string $response): array
    {
        $cleaned = trim($response);
        // Certains modèles enveloppent la réponse dans un bloc markdown malgré la consigne : on
        // le retire avant de tenter le décodage plutôt que d'échouer inutilement.
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $cleaned) ?? $cleaned;

        $data = json_decode($cleaned, true);
        if (!is_array($data) || !isset($data['title'], $data['lead']) || !is_string($data['title']) || !is_string($data['lead'])) {
            $this->logger->error('Synthèse : réponse d\'assemblage invalide, JSON attendu.', ['response' => $cleaned]);

            throw new LlmGenerationException('Réponse d\'assemblage invalide : JSON {"title", "lead"} attendu.');
        }

        return [trim($data['title']), trim($data['lead'])];
    }

    /**
     * Corps HTML structuré en sections par sous-thème, assemblé ici (pas par le LLM, voir le
     * docblock de classe) : chaque paragraphe déjà produit par `ClusterSummarizer` est échappé
     * avant insertion, le LLM ne contrôle donc jamais le HTML réellement stocké.
     *
     * @param list<array{theme: Theme, paragraph: string}> $clusterParagraphs
     */
    private function buildBody(array $clusterParagraphs, Language $language): string
    {
        $html = '';
        foreach ($clusterParagraphs as $entry) {
            $themeLabel = Language::ENGLISH === $language ? $entry['theme']->labelEn() : $entry['theme']->labelFr();
            $html .= sprintf(
                "<h3>%s</h3>\n<p>%s</p>\n",
                htmlspecialchars($themeLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($entry['paragraph'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }

        return trim($html);
    }
}

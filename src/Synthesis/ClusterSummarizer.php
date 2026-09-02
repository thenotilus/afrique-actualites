<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Shared\ValueObject\Language;
use App\Synthesis\Enum\Theme;
use App\Synthesis\Llm\LlmClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Étape 3 du pipeline, "map" (§ "Résumé par cluster") : un appel LLM par cluster de sous-thème,
 * qui produit un paragraphe factuel de synthèse à partir des articles du cluster.
 *
 * Le prompt impose les contraintes de la spec : aucune citation verbatim (reformulation complète,
 * respect du droit d'auteur des médias sources), ton neutre et factuel sans prise de position, et
 * mention générique des sources ("plusieurs médias rapportent que...") plutôt que la citation
 * nommée d'un média précis.
 */
final class ClusterSummarizer
{
    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /** @param list<Article> $articles */
    public function summarize(Theme $theme, array $articles, Language $language): string
    {
        $this->logger->info('Synthèse : résumé du cluster (map).', [
            'theme' => $theme->value,
            'articles' => count($articles),
            'language' => $language->value,
            'model' => $this->model,
        ]);

        $paragraph = trim($this->llmClient->complete(
            $this->buildSystemPrompt($language),
            $this->buildUserPrompt($theme, $articles, $language),
            $this->model,
            0.3,
            500,
        ));

        $this->logger->info('Synthèse : cluster résumé.', ['theme' => $theme->value, 'length' => mb_strlen($paragraph)]);

        return $paragraph;
    }

    private function buildSystemPrompt(Language $language): string
    {
        if (Language::ENGLISH === $language) {
            return 'You are a journalist for Afrique Actualités, a French-language African news '
                .'aggregator. You write short, factual, neutral summaries from articles already '
                .'published by other media outlets. Fully rewrite the information in your own '
                .'words: never copy a sentence verbatim from a source article. Never name a '
                .'specific source in the text; use generic phrasing such as "several outlets '
                .'report that...". Stay strictly factual and neutral: no opinion, no editorial '
                .'stance, no speculation beyond what the articles state.';
        }

        return 'Tu es journaliste pour Afrique Actualités, un agrégateur d\'actualités africaines. '
            .'Tu rédiges de courts résumés factuels et neutres à partir d\'articles déjà publiés '
            .'par d\'autres médias. Reformule entièrement l\'information avec tes propres mots : '
            .'ne recopie jamais une phrase telle quelle depuis un article source. Ne cite jamais '
            .'nommément un média précis dans le texte ; utilise des formulations génériques comme '
            .'« plusieurs médias rapportent que... ». Reste strictement factuel et neutre : aucune '
            .'opinion, aucune prise de position éditoriale, aucune spéculation au-delà de ce que '
            .'rapportent les articles.';
    }

    /** @param list<Article> $articles */
    private function buildUserPrompt(Theme $theme, array $articles, Language $language): string
    {
        $themeLabel = Language::ENGLISH === $language ? $theme->labelEn() : $theme->labelFr();

        $list = '';
        foreach ($articles as $index => $article) {
            $list .= sprintf("%d. %s — %s\n", $index + 1, $article->getTitle(), $article->getDescription());
        }

        if (Language::ENGLISH === $language) {
            return sprintf(
                "Sub-topic: %s\n\nSource articles (title — description):\n%s\n"
                .'Write a single factual paragraph (3 to 6 sentences) summarizing what happened '
                .'this week on this sub-topic, based only on the information in these articles. '
                .'Do not invent facts that are not in the articles above.',
                $themeLabel,
                $list,
            );
        }

        return sprintf(
            "Sous-thème : %s\n\nArticles sources (titre — description) :\n%s\n"
            ."Rédige un unique paragraphe factuel (3 à 6 phrases) résumant ce qui s'est passé "
            .'cette semaine sur ce sous-thème, en te basant uniquement sur les informations de '
            ."ces articles. N'invente aucun fait absent des articles ci-dessus.",
            $themeLabel,
            $list,
        );
    }
}

<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Shared\ValueObject\Language;
use App\Synthesis\Entity\Synthesis;
use App\Synthesis\Enum\Theme;
use App\Synthesis\Llm\LlmGenerationException;
use App\Synthesis\Repository\SynthesisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrateur du pipeline de synthèses hebdomadaires (sélection → clustering → résumé par
 * cluster → assemblage), appelé par `app:synthesis:generate` (§ "Scheduling"). Logue chaque étape
 * (§ "Script/job de génération... avec logs clairs à chaque étape").
 *
 * Une synthèse est toujours créée au statut DRAFT, sauf si `$autoPublish` (paramètre
 * `synthesis.auto_publish`, env `AUTO_PUBLISH`) est activé — flag de configuration prévu par la
 * spec pour basculer plus tard vers une publication 100% automatique sans réécrire le pipeline
 * (§ "Workflow de validation").
 *
 * Un échec LLM sur un pays/une région n'interrompt jamais le run entier : il est journalisé et
 * compté comme échec dans le bilan, les autres pays/régions de la semaine continuent d'être
 * traités (§ "logs clairs à chaque étape" implique de pouvoir observer un échec partiel, pas de
 * perdre le travail déjà accompli sur le reste du lot).
 */
final class SynthesisGenerator
{
    public function __construct(
        private readonly WeeklySelector $weeklySelector,
        private readonly ArticleClusterer $articleClusterer,
        private readonly ClusterSummarizer $clusterSummarizer,
        private readonly SynthesisAssembler $synthesisAssembler,
        private readonly SynthesisRepository $synthesisRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly bool $autoPublish,
    ) {
    }

    public function generateForWeek(\DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): SynthesisGenerationSummary
    {
        $this->logger->info('Synthèse : début du run hebdomadaire.', [
            'weekStart' => $weekStart->format('Y-m-d'),
            'weekEnd' => $weekEnd->format('Y-m-d'),
            'autoPublish' => $this->autoPublish,
        ]);

        $summary = new SynthesisGenerationSummary();

        foreach (Language::cases() as $language) {
            $this->logger->info('Synthèse : sélection des articles (étape 1).', ['language' => $language->value]);
            $selection = $this->weeklySelector->selectForWeek($weekStart, $weekEnd, $language);

            foreach ($selection->countrySelections as $countrySelection) {
                $summary = $summary->add($this->generateOne(
                    $countrySelection->country,
                    null,
                    $countrySelection->articles,
                    $weekStart,
                    $weekEnd,
                    $language,
                ));
            }

            foreach ($selection->regionSelections as $regionSelection) {
                $summary = $summary->add($this->generateOne(
                    null,
                    $regionSelection->region,
                    $regionSelection->articles,
                    $weekStart,
                    $weekEnd,
                    $language,
                ));
            }
        }

        $this->logger->info('Synthèse : run hebdomadaire terminé.', [
            'created' => $summary->created,
            'skipped' => $summary->skipped,
            'failed' => $summary->failed,
        ]);

        return $summary;
    }

    /** @param list<Article> $articles */
    private function generateOne(
        ?Country $country,
        ?Region $region,
        array $articles,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd,
        Language $language,
    ): SynthesisGenerationSummary {
        $scopeLabel = $this->scopeLabel($country, $region, $language);

        if (null !== $this->synthesisRepository->findExisting($country, $region, $weekStart, $language)) {
            $this->logger->info('Synthèse : déjà générée pour cette semaine, ignorée.', [
                'scope' => $scopeLabel,
                'language' => $language->value,
            ]);

            return new SynthesisGenerationSummary(skipped: 1);
        }

        try {
            $this->logger->info('Synthèse : clustering par sous-thème (étape 2).', [
                'scope' => $scopeLabel,
                'articles' => count($articles),
            ]);
            $clusters = $this->articleClusterer->cluster($articles);

            $clusterParagraphs = [];
            foreach ($clusters as $themeValue => $clusterArticles) {
                $theme = Theme::from($themeValue);
                $clusterParagraphs[] = [
                    'theme' => $theme,
                    'paragraph' => $this->clusterSummarizer->summarize($theme, $clusterArticles, $language),
                ];
            }

            $assembled = $this->synthesisAssembler->assemble($scopeLabel, $weekStart, $weekEnd, $clusterParagraphs, $language);

            $synthesis = null !== $country
                ? Synthesis::forCountry($country, $language, $weekStart, $weekEnd)
                : Synthesis::forRegion($region, $language, $weekStart, $weekEnd);
            $synthesis->setContent($assembled->title, $assembled->lead, $assembled->body);

            foreach ($articles as $article) {
                $synthesis->addSourceArticle($article);
            }

            if ($this->autoPublish) {
                $synthesis->publishAutomatically();
                $this->logger->info('Synthèse : publiée automatiquement (AUTO_PUBLISH=true).', ['scope' => $scopeLabel]);
            }

            $this->entityManager->persist($synthesis);
            $this->entityManager->flush();

            $this->logger->info('Synthèse : créée.', [
                'scope' => $scopeLabel,
                'language' => $language->value,
                'status' => $synthesis->getStatus()->value,
            ]);

            return new SynthesisGenerationSummary(created: 1);
        } catch (LlmGenerationException $e) {
            $this->logger->error('Synthèse : échec de génération, pays/région ignoré pour cette semaine.', [
                'scope' => $scopeLabel,
                'language' => $language->value,
                'exception' => $e->getMessage(),
            ]);

            return new SynthesisGenerationSummary(failed: 1);
        }
    }

    private function scopeLabel(?Country $country, ?Region $region, Language $language): string
    {
        if (null !== $country) {
            return Language::ENGLISH === $language ? $country->getNameEn() : $country->getNameFr();
        }

        return $region?->label($language) ?? 'inconnu';
    }
}

<?php

namespace App\Synthesis\Pipeline;

use App\Article\Entity\Article;
use App\Classification\Pipeline\NormalizerInterface;
use App\Shared\ValueObject\Language;
use App\Synthesis\Enum\Theme;
use Symfony\Component\Yaml\Yaml;

/**
 * Classe un article dans un sous-thème (§ "Clustering par sous-thème") en recherchant, dans son
 * titre + sa description normalisés, les mots/expressions d'un dictionnaire thématique éditable
 * (`Resources/themes/{langue}.yaml`).
 *
 * Choix documenté (le point demandé par la spec du chantier) : ni (a) les tags/taxonomies
 * existants tels quels, ni (b) un clustering par embeddings.
 *
 * - Les taxonomies existantes (`Article::$taxonomies`) sont écartées : ce sont des tokens
 *   *racinisés* issus d'un pipeline de fréquence documentaire (`ClassificationService`), pas des
 *   catégories thématiques — et elles restent bruitées (adverbes, jours de la semaine, formes
 *   verbales échappées au filtrage de mots vides, ex. "mardi", "environ", "intervient") tant
 *   qu'elles n'ont pas été nettoyées. Les réutiliser aurait demandé de construire *un autre*
 *   pipeline de nettoyage avant de pouvoir clusteriser dessus — pas plus simple qu'un dictionnaire
 *   thématique dédié, et moins direct.
 * - Un clustering par embeddings (titre + chapô) demanderait un modèle d'embedding, une mesure de
 *   similarité et un algorithme de clustering (k-means ou équivalent) : aucune de ces briques
 *   n'existe dans la stack actuelle (pas de base vectorielle, pas de client d'inférence local), et
 *   les ajouter pour ce chantier serait disproportionné par rapport à un dictionnaire d'une
 *   centaine de mots-clés, entièrement déterministe et testable sans dépendance externe.
 *
 * Le dictionnaire par mots-clés réutilise directement `NormalizerInterface` (minuscules, sans
 * diacritiques) déjà employé par `ClassificationService` — mêmes garanties, aucune nouvelle
 * dépendance — et reste éditable sans toucher au code, comme les listes de mots vides.
 */
final class KeywordDictionaryThemeClassifier implements ThemeClassifierInterface
{
    /** @var array<string, array<string, list<string>>> langue => (thème => mots-clés normalisés) */
    private array $dictionariesByLanguage = [];

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly string $resourcesDirectory,
    ) {
    }

    public function classify(Article $article): Theme
    {
        $normalizedText = ' '.$this->normalizer->normalize($article->getTitle().' '.$article->getDescription()).' ';

        $bestTheme = null;
        $bestScore = 0;

        foreach ($this->getDictionary($article->getLanguage()) as $themeValue => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedText, ' '.$keyword.' ')) {
                    ++$score;
                }
            }

            // Premier thème déclaré dans le YAML gagnant à score égal : ordre stable et
            // reproductible, préférable à un tri par nom de thème sans signification éditoriale.
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTheme = $themeValue;
            }
        }

        return null !== $bestTheme ? Theme::from($bestTheme) : Theme::GENERAL;
    }

    /** @return array<string, list<string>> thème => mots-clés normalisés */
    private function getDictionary(Language $language): array
    {
        if (isset($this->dictionariesByLanguage[$language->value])) {
            return $this->dictionariesByLanguage[$language->value];
        }

        $path = sprintf('%s/%s.yaml', rtrim($this->resourcesDirectory, '/'), $language->value);
        /** @var array<string, list<string>> $raw */
        $raw = is_file($path) ? (Yaml::parseFile($path) ?? []) : [];

        $normalized = [];
        foreach ($raw as $themeValue => $keywords) {
            $normalized[$themeValue] = array_map(
                fn (string $keyword): string => $this->normalizer->normalize($keyword),
                $keywords,
            );
        }

        $this->dictionariesByLanguage[$language->value] = $normalized;

        return $normalized;
    }
}

<?php

namespace App\Synthesis;

use App\Article\Entity\Article;
use App\Synthesis\Pipeline\ThemeClassifierInterface;

/**
 * Regroupe une liste d'articles (un pays ou une région, une semaine) par sous-thème
 * (§ "Clustering par sous-thème"), en s'appuyant sur `ThemeClassifierInterface` pour classer
 * chaque article individuellement.
 */
final class ArticleClusterer
{
    public function __construct(private readonly ThemeClassifierInterface $themeClassifier)
    {
    }

    /**
     * @param list<Article> $articles
     *
     * @return array<string, list<Article>> thème (valeur de l'enum) => articles, les clusters les
     *                                      plus fournis d'abord — ordre stable pour un rendu
     *                                      reproductible du corps de la synthèse
     */
    public function cluster(array $articles): array
    {
        /** @var array<string, list<Article>> $clusters */
        $clusters = [];

        foreach ($articles as $article) {
            $theme = $this->themeClassifier->classify($article);
            $clusters[$theme->value][] = $article;
        }

        uasort($clusters, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $clusters;
    }
}

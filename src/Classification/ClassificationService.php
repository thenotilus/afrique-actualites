<?php

namespace App\Classification;

use App\Article\Entity\Article;
use App\Classification\Pipeline\NamedEntityRecognizerInterface;
use App\Classification\Pipeline\NormalizerInterface;
use App\Classification\Pipeline\ScorerInterface;
use App\Classification\Pipeline\StemmerInterface;
use App\Classification\Pipeline\StopWordFilterInterface;
use App\Classification\Pipeline\TokenizerInterface;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de détection de mots-clés bilingue (§10 de la documentation fonctionnelle), successeur
 * de l'ancien `KeywordService` (§4.2). Orchestre le pipeline (normalisation, tokenisation,
 * filtrage des mots vides, racinisation, scoring) et la reconnaissance des pays cités.
 *
 * Les nouveaux termes détectés sont **validés par défaut** (décision produit) : rattachés au "sac
 * de mots" de l'article (`Article::addTaxonomy()`) et, aussitôt, à ses mots-clés retenus
 * (`Article::addKeyword()`) puisqu'ils sont validés. Un administrateur peut toujours rejeter un
 * terme a posteriori dans l'écran de modération. Un terme **déjà connu** conserve son statut : le
 * pipeline ne ressuscite jamais une taxonomie explicitement rejetée, et un terme déjà validé reste
 * validé et promu mot-clé sur tout nouvel article qui le contient.
 */
final class ClassificationService
{
    private const TITLE_WEIGHT = 2;
    private const DESCRIPTION_WEIGHT = 1;

    /** Nombre maximal de termes rattachés à un même article (les plus pertinents d'abord). */
    private const MAX_TERMS_PER_ARTICLE = 6;

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly TokenizerInterface $tokenizer,
        private readonly StopWordFilterInterface $stopWordFilter,
        private readonly StemmerInterface $stemmer,
        private readonly NamedEntityRecognizerInterface $namedEntityRecognizer,
        private readonly ScorerInterface $scorer,
        private readonly TaxonomyRepository $taxonomyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $minDocumentFrequency,
        private readonly float $maxDocumentFrequencyRatio,
    ) {
    }

    /**
     * @param iterable<Article> $articles Lot d'articles à traiter (fenêtre glissante d'une
     *                                    future commande/worker d'extraction, §6)
     */
    public function classifyArticles(iterable $articles): ClassificationSummary
    {
        /** @var array<string, array<int, array{article: Article, tokens: array<string, int>}>> $entriesByLanguage */
        $entriesByLanguage = [];
        $articlesProcessed = 0;
        $countriesAttached = 0;

        foreach ($articles as $article) {
            ++$articlesProcessed;
            $tokenWeights = $this->extractTokenWeights($article);
            $entriesByLanguage[$article->getLanguage()->value][$article->getId()] = [
                'article' => $article,
                'tokens' => $tokenWeights,
            ];
            $countriesAttached += $this->attachCountries($article);
        }

        $suggestionsCreated = 0;
        $suggestionsReinforced = 0;

        foreach ($entriesByLanguage as $languageValue => $entries) {
            [$created, $reinforced] = $this->classifyLanguageBatch(Language::from($languageValue), $entries);
            $suggestionsCreated += $created;
            $suggestionsReinforced += $reinforced;
        }

        $this->entityManager->flush();

        return new ClassificationSummary($articlesProcessed, $suggestionsCreated, $suggestionsReinforced, $countriesAttached);
    }

    /**
     * @param array<int, array{article: Article, tokens: array<string, int>}> $entries
     *
     * @return array{0: int, 1: int} [créées, renforcées]
     */
    private function classifyLanguageBatch(Language $language, array $entries): array
    {
        $batchSize = count($entries);
        if (0 === $batchSize) {
            return [0, 0];
        }

        $tokenWeightsByArticleId = array_map(static fn (array $entry) => $entry['tokens'], $entries);
        $scores = $this->scorer->score($tokenWeightsByArticleId);

        $created = 0;
        $reinforced = 0;

        /** @var array<string, Taxonomy> $eligibleTaxonomies Taxonomies retenues, indexées par token */
        $eligibleTaxonomies = [];

        foreach ($scores as $scoredToken) {
            if ($scoredToken->documentFrequency < $this->minDocumentFrequency) {
                continue;
            }
            // Un token présent dans la quasi-totalité du lot reste probablement un mot commun
            // ayant échappé au filtrage de mots vides, plutôt qu'un sujet d'actualité (§4.3 L6).
            if (($scoredToken->documentFrequency / $batchSize) > $this->maxDocumentFrequencyRatio) {
                continue;
            }

            $taxonomy = $this->taxonomyRepository->findOneByLabelAndLanguage($scoredToken->token, $language);
            if (null === $taxonomy) {
                // Les taxonomies détectées sont validées par défaut (décision produit) : elles
                // deviennent immédiatement des mots-clés utilisables, sans passer par l'écran de
                // modération. Un administrateur peut toujours en rejeter a posteriori.
                $taxonomy = new Taxonomy($scoredToken->token, $language);
                $taxonomy->validateAutomatically();
                $this->entityManager->persist($taxonomy);
                ++$created;
            } else {
                // Une taxonomie déjà connue conserve son statut : on ne ressuscite jamais un terme
                // qu'un administrateur a explicitement rejeté.
                ++$reinforced;
            }
            // Score de pertinence recalculé à chaque passage : reflète l'activité la plus
            // récente du terme plutôt qu'une note figée (§4.3 L11 : champ auparavant jamais lu).
            $taxonomy->setMark($scoredToken->totalWeight);
            $eligibleTaxonomies[$scoredToken->token] = $taxonomy;
        }

        // Rattachement borné : on ne conserve que les MAX_TERMS_PER_ARTICLE termes de plus fort
        // poids dans chaque article, pour éviter de le noyer sous des mots-clés secondaires.
        foreach ($entries as $entry) {
            $tokenWeights = array_filter(
                $entry['tokens'],
                static fn (string $token) => isset($eligibleTaxonomies[$token]),
                ARRAY_FILTER_USE_KEY,
            );
            arsort($tokenWeights);

            foreach (array_slice($tokenWeights, 0, self::MAX_TERMS_PER_ARTICLE, true) as $token => $weight) {
                $taxonomy = $eligibleTaxonomies[$token];
                $entry['article']->addTaxonomy($taxonomy);
                if ($taxonomy->isValidated()) {
                    $entry['article']->addKeyword($taxonomy);
                }
            }
        }

        return [$created, $reinforced];
    }

    /** @return array<string, int> Token racinisé => poids maximal atteint (titre ou description) */
    private function extractTokenWeights(Article $article): array
    {
        $weights = [];
        $this->accumulate($weights, $article->getTitle(), $article->getLanguage(), self::TITLE_WEIGHT);
        $this->accumulate($weights, $article->getDescription(), $article->getLanguage(), self::DESCRIPTION_WEIGHT);

        return $weights;
    }

    /** @param array<string, int> $weights */
    private function accumulate(array &$weights, string $text, Language $language, int $weight): void
    {
        if ('' === trim($text)) {
            return;
        }

        $normalized = $this->normalizer->normalize($text);
        $tokens = $this->stopWordFilter->filter($this->tokenizer->tokenize($normalized), $language);

        foreach ($tokens as $token) {
            $stemmed = $this->stemmer->stem($token, $language);
            $weights[$stemmed] = max($weights[$stemmed] ?? 0, $weight);
        }
    }

    private function attachCountries(Article $article): int
    {
        $normalizedText = $this->normalizer->normalize($article->getTitle().' '.$article->getDescription());
        $countries = $this->namedEntityRecognizer->recognizeCountries($normalizedText, $article->getLanguage());

        $attached = 0;
        foreach ($countries as $country) {
            if (!$article->getCountries()->contains($country)) {
                $article->addCountry($country);
                ++$attached;
            }
        }

        return $attached;
    }
}

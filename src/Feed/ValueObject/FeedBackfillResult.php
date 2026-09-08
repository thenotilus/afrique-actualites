<?php

namespace App\Feed\ValueObject;

/**
 * Bilan du backfill historique d'un média (§6bis) : combien d'URLs les sitemaps parcourus
 * exposaient au total, combien tombaient dans la fenêtre de récupération demandée, et combien ont
 * effectivement donné lieu à un nouvel `Article` (les autres étant déjà en base).
 *
 * Ne porte que des compteurs, jamais les `Article` créés eux-mêmes : {@see \App\Feed\FeedBackfiller}
 * traite potentiellement des dizaines de milliers d'entrées par média (là où `FeedIngestionResult`,
 * son équivalent côté flux RSS/Atom, en voit quelques dizaines) et vide périodiquement l'identity
 * map Doctrine (`EntityManager::clear()`) au fil du traitement pour rester sous la limite mémoire —
 * conserver ici une liste d'entités que le run vient justement de détacher n'aurait aucun sens.
 *
 * Porte aussi le bilan de l'étape de classification qui suit, menée jour par jour plutôt qu'en un
 * seul lot (cf. {@see \App\Feed\FeedBackfiller}) : `daysClassified` compte les jours distincts
 * traités (chacun un appel à `ClassificationService::classifyArticles()`), sur les articles du
 * média encore jamais classés dans la fenêtre — pas seulement ceux créés à ce passage, un article
 * backfillé lors d'un run précédent n'ayant pu être classé qu'une fois son crawl de repli terminé.
 */
final readonly class FeedBackfillResult
{
    public function __construct(
        public int $entriesSeen,
        public int $entriesInWindow,
        public int $skipped,
        public int $created,
        public int $daysClassified,
        public int $articlesClassified,
        public int $keywordsCreated,
    ) {
    }
}

# Afrique Actualités — refonte

Refonte de l'agrégateur d'actualités [Afrique Actualités](https://www.afrique-actualites.com) sur
Symfony 6.4 LTS, avec un moteur de détection de mots-clés bilingue FR/EN à circuit de validation,
un back-office unifié EasyAdmin et un système de crawling multi-bots.

Ce dépôt remplace progressivement l'ancienne application (`thenotilus/afkr`, Symfony 3.3), selon
le plan détaillé dans la documentation fonctionnelle.

## Documentation

- [`docs/documentation-fonctionnelle.md`](docs/documentation-fonctionnelle.md) — **document de
  référence** : analyse de l'ancienne application, décisions produit, plan de refonte détaillé en
  9 phases (§11). Toute décision d'architecture ou de périmètre découle de ce document.
- [`docs/architecture.md`](docs/architecture.md) — conventions de code de ce dépôt (organisation
  par domaine, internationalisation, traitements continus).
- [`docs/prompt-claude-design.md`](docs/prompt-claude-design.md) — prompt prêt à l'emploi pour
  concevoir l'UX/UI du site public et du back-office dans Claude Design.

## État d'avancement

**Phase 1 — Socle technique** (voir §11.2 de la documentation fonctionnelle) :

- [x] Squelette Symfony 6.4 LTS / PHP 8.2+
- [x] Structure de dossiers par domaine (`src/Feed`, `src/Article`, `src/Classification`, `src/Taxonomy`, `src/Geography`, `src/News`, `src/Newsletter`, `src/Social`, `src/User`, `src/Shared`)
- [x] Fondations i18n (Translation, routage localisé `/fr/`, `/en/`)
- [x] Doctrine ORM configuré pour MySQL 8
- [x] Messenger, Lock, HTTP Client, Monolog, EasyAdminBundle installés
- [x] Environnement de développement local (`compose.yaml` : MySQL + Redis)
- [x] Intégration continue (lint, PHPStan, PHP-CS-Fixer, PHPUnit)
- [ ] `phpstan/phpstan` à ajouter en dev (voir note dans `docs/architecture.md`)

**Phase 2 — Modèle de données cible** :

- [x] 9 entités écrites (`Feed`, `Article`, `Taxonomy`, `Country`, `UserNews`, `Publication`,
      `User`, `NewsletterSubscriber`, `WeeklyNewsletter`) — mapping validé, voir
      `docs/architecture.md` pour les décisions de conception
- [x] `Taxonomy` à statut (`SUGGESTED`/`VALIDATED`/`REJECTED`/`ARCHIVED`) et rattachée à une langue
- [x] Relation native `Article ↔ Country`
- [x] Sécurité applicative dans le code : impossible d'attacher une taxonomie non validée comme
      mot-clé ou à une "Une" (`\LogicException`, testé dans `tests/Entity/EntityGraphTest.php`)
- [ ] Migration Doctrine contre une vraie base MySQL (bloqué dans cet environnement, voir
      `docs/architecture.md` — action à faire dès qu'une base est joignable)
- [ ] Script d'import des données de l'ancien dépôt `thenotilus/afkr`

**Phase 3 — Back-office EasyAdmin** :

- [x] Dashboard avec compteur de suggestions en attente (`src/Controller/Admin/`)
- [x] 9 écrans CRUD (Flux, Articles, Taxonomies, Pays, "Unes", Publications, Utilisateurs,
      Abonnés newsletter, Newsletters hebdomadaires)
- [x] Écran de validation des mots-clés : actions "Valider"/"Rejeter" individuelles et en masse,
      filtres par statut/langue — voir `docs/architecture.md` pour les détails d'implémentation
      EasyAdmin 5.x et les pièges rencontrés
- [x] Champs association restreints aux taxonomies validées partout où un mot-clé est sélectionné
      manuellement (Unes, newsletters)
- [x] Test de fumée bout en bout sur les 9 écrans + le circuit de validation
      (`tests/Controller/Admin/AdminBackofficeTest.php`)
- [x] Formulaire de connexion public (livré en phase 6, voir ci-dessous)

**Phase 4 — Moteur de classification bilingue** :

- [x] Pipeline en étapes interchangeables (`src/Classification/Pipeline/`) : normalisation
      Unicode, tokenisation, filtrage de mots vides FR/EN, racinisation heuristique, scoring par
      fréquence documentaire pondérée
- [x] `ClassificationService` : suggestions créées au statut `SUGGESTED`, jamais directement
      utilisables comme mots-clés ; promotion automatique d'un mot-clé déjà validé sur les
      nouveaux articles qui le contiennent
- [x] Reconnaissance des pays cités (`CountryNamedEntityRecognizer`), rattachement natif
      `Article ↔ Country`
- [x] Référentiel des 54 pays d'Afrique (`src/Geography/Resources/countries.yaml`) et commande
      d'import idempotente `app:country:fill`
- [x] Seuils configurables (`config/packages/classification.yaml`)
- [x] Tests unitaires par étape du pipeline + test d'intégration bout en bout sur un lot
      d'articles réalistes FR/EN (`tests/Classification/`)

**Phase 5 — Crawling multi-bots** :

- [x] Pool de profils de bots configurable (`src/Crawler/BotProfileRegistry.php`,
      `config/packages/crawler.yaml`), user-agents toujours explicitement identifiés
- [x] Repli en cascade entre profils (`CrawlerService`) jusqu'à obtenir des métadonnées
      exploitables ou épuisement du pool
- [x] Respect de `robots.txt` (`RobotsTxtChecker`) avant toute requête
- [x] Throttling agrégé **par domaine**, pas par flux (`config/packages/rate_limiter.yaml`)
- [x] Extraction Open Graph/Twitter Cards avec repli sur les balises HTML standard
      (`OpenGraphMetaExtractor`)
- [x] Déclenchement conditionnel et asynchrone (`CrawlArticleMetaMessage`/Handler) : ne complète
      que les champs manquants, n'écrase jamais une valeur déjà fournie par le flux RSS
- [x] Mise en cache par URL des résultats réussis, journal des tentatives (`CrawlAttempt`) et
      tableau de bord par domaine dans le back-office
- [x] Tests unitaires par composant + tests d'intégration (cascade, `robots.txt`, cache, quota)
      (`tests/Crawler/`)
- [ ] Transport Messenger asynchrone réel (reste en `sync` — aucun worker d'extraction RSS
      continu n'existe encore pour dispatcher les messages en conditions réelles, voir §6)

**Phase 6 — Pages publiques (bilingues FR/EN)** :

- [x] Accueil, listes par flux/mot-clé, recherche (titre/description/mot-clé validé), fiche
      article avec suggestions "à lire aussi" — un seul gabarit de liste partagé par tous les
      contextes (`public/_article_list.html.twig`)
- [x] Page pays (§3.13, écran prioritaire) : croisement optionnel par mot-clé validé, archives
      paginées sans limite de temps
- [x] "Unes" : annuaire public, page dédiée, gestion complète par l'utilisateur connecté
      (créer/éditer/dupliquer/supprimer), visibilité privée réservée aux administrateurs
- [x] Connexion/inscription (email + mot de passe, `form_login` natif Symfony) — l'inscription
      reste facultative, réservée à la création de "Unes" personnalisées
- [x] Capture d'email pour la newsletter hebdomadaire (encart pied de page sur tout le site)
- [x] Sitemap-index + sous-sitemaps par langue, découpés par lots de 5000 articles
      (`src/Sitemap/`, `src/Controller/SitemapController.php`)
- [x] Sélecteur de langue persistant, pagination générique (`App\Shared\Pagination`)
- [x] Test de fumée bout en bout : rendu de chaque page (FR/EN), connexion/inscription réelles,
      cycle de vie complet d'une "Une" (`tests/Controller/PublicPagesTest.php`)
- [ ] Réinitialisation de mot de passe oublié
- [ ] Mot-clé optionnel dans le croisement pays × mot-clé et profondeur des archives : réponses
      par défaut retenues faute de réponse du porteur produit (§12.2 point 1)

Les phases suivantes (newsletter hebdomadaire, double-run & bascule...) sont détaillées dans le
tableau de phasage (§11.2 de la documentation fonctionnelle).

## Démarrage local

Prérequis : PHP 8.2+, Composer, Docker (pour la base de données).

```bash
docker compose up -d
composer install
composer require --dev phpstan/phpstan phpstan/phpstan-symfony   # cf. docs/architecture.md
cp .env .env.local   # puis ajuster DATABASE_URL si besoin
php bin/console doctrine:database:create
php bin/console doctrine:migrations:diff     # tant qu'aucune migration n'existe encore, voir docs/architecture.md
php bin/console doctrine:migrations:migrate
symfony server:start   # ou: php -S 127.0.0.1:8000 -t public
```

## Qualité

```bash
vendor/bin/phpunit                              # tests
vendor/bin/php-cs-fixer fix --dry-run --diff     # style de code
vendor/bin/phpstan analyse                       # analyse statique (après ajout, voir ci-dessus)
php bin/console lint:yaml config --parse-tags
php bin/console lint:container
```

La CI (`.github/workflows/ci.yml`) exécute l'ensemble de ces vérifications à chaque push/PR.

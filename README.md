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

Les phases suivantes (back-office, moteur de classification, crawling, pages publiques...) sont
détaillées dans le tableau de phasage (§11.2 de la documentation fonctionnelle).

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

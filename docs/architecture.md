# Architecture du projet

Ce document explique les conventions de code de la refonte. Pour le *pourquoi* (analyse de
l'existant, décisions produit, plan de refonte en phases), voir
[`documentation-fonctionnelle.md`](./documentation-fonctionnelle.md) — c'est le document de
référence, issu de l'analyse de l'ancien dépôt `thenotilus/afkr` et des échanges avec le porteur
produit. Ce fichier `architecture.md` ne fait que documenter comment le code traduit ce plan.

## Organisation par domaine (§9.2 de la documentation fonctionnelle)

Contrairement à l'ancienne application (un seul bundle `AppBundle` avec des dossiers techniques
`Entity/`, `Controller/`, `Service/` mélangeant tous les domaines), le code est organisé **par
domaine métier** sous `src/` :

```
src/
  Feed/            Gestion des flux RSS/Atom (multilingues)
  Article/         Agrégation et publication des articles
  Classification/  Moteur de détection de mots-clés (pipeline bilingue FR/EN, voir §10)
  Taxonomy/        Mots-clés (suggestion → validation, voir §4.4/§10.4)
  Geography/       Pays, croisement pays × mot-clé, archives (voir §3.13)
  News/            "Unes" thématiques utilisateur
  Newsletter/       Newsletter hebdomadaire et gestion des abonnés
  Social/          Partage réseaux sociaux — interfaces posées, implémentation différée (§3.8)
  User/            Compte utilisateur, sécurité
  Shared/          Code transverse : Utils/, ValueObject/, Dto/
  Controller/Admin/ Contrôleurs/Dashboard EasyAdmin (back-office unifié, §3.12)
```

Chaque domaine porte, selon son besoin, ses propres sous-dossiers `Entity/`, `Repository/`,
`Service/` (voir le détail par domaine dans le code). `Classification/` n'a pas d'entité propre :
il consomme/produit des `Taxonomy` (module `Taxonomy/`) au travers d'un pipeline
(`Classification/Pipeline/`, interfaces `NormalizerInterface`, `TokenizerInterface`,
`StopWordFilterInterface`, `StemmerInterface`, `ScorerInterface`, etc., voir §10.2).

Le mapping Doctrine (`config/packages/doctrine.yaml`) scanne l'intégralité de `src/` : une classe
n'est enregistrée comme entité que si elle porte l'attribut `#[ORM\Entity]`, indépendamment de son
dossier.

## Internationalisation

- Locales supportées : `fr` (défaut) et `en` (voir §3bis de la documentation fonctionnelle).
- Routage : toutes les routes de contrôleur public sont préfixées par `/{_locale}`
  (`config/routes.yaml`). Le back-office EasyAdmin n'est pas concerné par ce préfixe.
- Traductions : `translations/messages.fr.yaml` et `translations/messages.en.yaml`.
- Le pipeline de classification (mots-clés) applique un traitement **par langue** (stopwords,
  racinisation, entités nommées) — voir §10.1.9 et §10.2 de la documentation fonctionnelle. Chaque
  `Taxonomy` est rattachée à une langue.

## Traitements continus (extraction / classification)

L'extraction des flux et la classification des mots-clés tournent **en continu** en production
(confirmé par le porteur produit, §6). La cible d'architecture est un **worker Messenger
persistant** (superviseur systemd/Supervisor), pas une commande cron périodique classique. Voir
`config/packages/messenger.yaml` : le transport `sync` est un point de départ de scaffolding : la
mise en place effective des workers/handlers dédiés est prévue en phase 4/5 du plan de refonte
(§11.2).

Un verrou d'exécution (`symfony/lock`, `config/packages/lock.yaml`) doit être utilisé par ces
workers pour éviter tout chevauchement (§8, point 12 de la documentation fonctionnelle). Le store
de lock par défaut (`flock`, local au disque) devra être remplacé par un store partagé (ex. Redis,
déjà présent dans `compose.yaml`) avant la mise en production, si plusieurs instances du worker
tournent en parallèle.

## Back-office

Back-office **unique** sous EasyAdminBundle (§3.12), à construire en phase 3 du plan de refonte.
Aucun autre système d'administration (Sonata, contrôleur "maison") n'est repris.

## Modèle de données (phase 2)

Les entités vivent sous `src/<Domaine>/Entity/` (voir liste ci-dessus). Points de conception à
connaître :

- **`Taxonomy`** porte un statut (`App\Taxonomy\Enum\TaxonomyStatus` : `SUGGESTED` par défaut à la
  création, `VALIDATED`, `REJECTED`, `ARCHIVED`) et une langue (`App\Shared\ValueObject\Language`).
  Une même chaîne peut donc exister comme deux taxonomies distinctes selon la langue.
- **`Article::addKeyword()`** et **`UserNews::addTaxonomy()`** lèvent une `\LogicException` si on
  tente d'y rattacher une taxonomie qui n'est pas `VALIDATED` — c'est la garde-fou de code qui
  traduit le jalon critique du plan de refonte (§11.3) : un mot-clé "suggéré" ne doit jamais fuiter
  côté public. Voir `tests/Entity/EntityGraphTest.php` pour la démonstration.
- **`Article ↔ Country`** est une relation `ManyToMany` native (table `article_country`), en
  remplacement du détour par `UserNews` de l'ancienne application (§3.13). Elle est destinée à être
  alimentée par la reconnaissance d'entités nommées du futur pipeline de classification (§10.1.6),
  pas seulement à la main.
- **`NewsletterSubscriber`** (domaine `Newsletter`) est un modèle **distinct** de `User` : le site
  ne requiert pas d'inscription, et les ~40 destinataires actuels de la newsletter hebdomadaire ne
  sont pas nécessairement des comptes utilisateur (§3.7). C'est la réponse par défaut retenue tant
  que la question posée en §12.2 (point 2) n'est pas tranchée par le porteur produit ; si la
  réponse va dans l'autre sens, cette entité sera fusionnée avec `User`.
- **`User`** implémente directement `UserInterface`/`PasswordAuthenticatedUserInterface` du
  composant Security natif de Symfony, en remplacement de FOSUserBundle (§9.1).
- L'ancienne entité `Cache` (cache applicatif maison, table SQL) **n'est pas reprise** : la cible
  est le composant Cache standard de Symfony (PSR-6), configuré dans
  `config/packages/cache.yaml`, avec un adaptateur Redis en production (§9.1, §8 point 8).

### Migration Doctrine — action requise avant la phase 3

Le mapping des 9 entités a été validé (`doctrine:mapping:info`, `doctrine:schema:validate`) et
testé fonctionnellement (`tests/Entity/EntityGraphTest.php`) via une base **SQLite locale**, le
démon Docker n'étant pas démarrable dans l'environnement d'amorçage (permissions du bac à sable).
**Aucune migration Doctrine n'a donc encore été générée** : une migration produite depuis SQLite
serait dans le mauvais dialecte SQL pour la cible MySQL. Dès qu'une base MySQL est joignable
(`docker compose up -d database` sur une machine où Docker fonctionne, ou toute autre instance
MySQL 8) :

```
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## État d'avancement

- **Phase 1 (socle technique)** : squelette Symfony 6.4 LTS, structure de dossiers par domaine,
  fondations i18n, CI — terminée.
- **Phase 2 (modèle de données cible)** : 9 entités écrites et validées (mapping + tests
  fonctionnels). Reste à faire avant la phase 3 : générer la migration Doctrine contre une vraie
  base MySQL (voir ci-dessus) et écrire le script d'import des données de l'ancien dépôt
  `thenotilus/afkr` (mots-clés existants repris comme pré-validés, §11.4).

Le back-office, le pipeline de classification et les pages publiques sont des phases ultérieures
(voir le tableau de phasage en §11.2 de la documentation fonctionnelle).

## Suivi des dépendances de développement

`phpstan/phpstan` et `phpstan/phpstan-symfony` ne sont volontairement pas encore déclarés dans
`composer.json` : leur installation a été bloquée dans l'environnement d'amorçage par la limite de
taux anonyme de l'API GitHub (téléchargement `dist` uniquement, pas de repli source possible pour
ce paquet). Ce n'est pas une limitation du projet — à ajouter dès que possible avec :

```
composer require --dev phpstan/phpstan phpstan/phpstan-symfony
```

Un fichier `phpstan.dist.neon` de configuration minimale est déjà présent pour ne pas bloquer ce
rattachement.

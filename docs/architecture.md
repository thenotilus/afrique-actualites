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
déjà présent dans `docker-compose.yml`) avant la mise en production, si plusieurs instances du
worker tournent en parallèle.

## Back-office

Back-office **unique** sous EasyAdminBundle (§3.12), à construire en phase 3 du plan de refonte.
Aucun autre système d'administration (Sonata, contrôleur "maison") n'est repris.

## État d'avancement

Ce dépôt correspond à la **phase 1 (socle technique)** du plan de refonte détaillé en §11.2 de la
documentation fonctionnelle : squelette Symfony 6.4 LTS, structure de dossiers par domaine,
fondations i18n, CI. Les entités, le pipeline de classification, le back-office et les pages
publiques sont des phases ultérieures (voir le tableau de phasage en §11.2).

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

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
  Crawler/         Crawling de repli multi-bots, en complément du flux RSS (voir §9.4)
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

**Piège à connaître** : `config/services.yaml` doit exclure `../src/*/Entity/` et `../src/*/Enum/`
de l'autowiring (en plus de `../src/DependencyInjection/` et `../src/Kernel.php`) — le squelette
Symfony par défaut n'exclut que `../src/Entity/`, un chemin qui n'existe pas dans cette structure
par domaine. Sans cette exclusion, chaque entité est enregistrée comme service autowiré, et
Symfony tente de l'instancier avec un constructeur vide dès qu'elle apparaît comme argument de
service ou de contrôleur, ce qui échoue puisque nos entités ont des constructeurs avec paramètres
obligatoires (voir la section Back-office ci-dessous pour l'erreur concrète que ça provoque).

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

Back-office **unique** sous EasyAdminBundle v5 (§3.12) : `src/Controller/Admin/`. Aucun autre
système d'administration (Sonata, contrôleur "maison") n'est repris.

L'écran `TaxonomyCrudController` porte le circuit de validation des mots-clés (§4.4/§10.4) :
actions "Valider"/"Rejeter" par ligne et en masse, filtres par statut/langue. Il n'expose ni
création ni édition libre (`Action::NEW`/`Action::EDIT` retirées) : le statut d'une taxonomie ne
change que via ces actions dédiées, jamais par saisie directe.

Les champs association vers `Taxonomy` (mots-clés d'une "Une", d'une newsletter) restreignent
systématiquement leurs choix aux taxonomies `VALIDATED` via `AssociationField::setQueryBuilder()`.
C'est nécessaire car EasyAdmin édite les collections Doctrine directement (option Symfony Form
`by_reference: true`), sans passer par les méthodes `addKeyword()`/`addTaxonomy()` des entités —
la garde de code posée en phase 2 ne protège donc que l'API programmatique, pas un formulaire
d'association générique ; la restriction des choix côté champ est le complément nécessaire.
`Article::keywords` (mots-clés retenus) est volontairement **en lecture seule** dans le back-office
pour la même raison : ce sont des données dérivées du futur pipeline de classification (phase 4),
pas un champ à éditer à la main.

Notes d'implémentation EasyAdmin 5.x qui ont posé des pièges à la construction (documentées ici
pour ne pas les retomber) :
- Le contrôleur de dashboard doit porter `#[AdminDashboard(routePath: '/admin', routeName: 'admin')]`
  au niveau de la classe ; l'ancien `#[Route('/admin')]` sur `index()` ne suffit plus (v5).
- Les actions CRUD personnalisées (`linkToCrudAction()`) requièrent `#[AdminRoute]` sur la méthode
  cible, avec le placeholder `{entityId}` explicitement présent dans le chemin.
- Le placeholder `{entityId:alias.property}` documenté par EasyAdmin est **cosmétique** (lisibilité
  de l'URL) : il ne déclenche pas d'hydratation automatique de l'entité en argument de contrôleur.
  Le plus simple et le plus fiable est de typer l'argument en `int $entityId` et de charger
  l'entité soi-même dans le corps de la méthode (voir `TaxonomyCrudController::validate()`).
- Le firewall `main` doit déclarer `logout: { path: app_logout }` (avec une route correspondante,
  même si son contrôleur n'est jamais exécuté) : sans elle, le layout EasyAdmin lève une exception
  au rendu du menu utilisateur.
- Toute entité (`Feed`, `Taxonomy`, etc.) doit être exclue de l'autowiring des services
  (`config/services.yaml`) : le squelette Symfony n'exclut que `../src/Entity/` par défaut, ce qui
  ne couvre pas une structure par domaine (`src/<Domaine>/Entity/`) — voir la section suivante.

Un test de fumée (`tests/Controller/Admin/AdminBackofficeTest.php`) fait une requête HTTP réelle
sur chacun des 9 écrans CRUD et sur le circuit de validation complet (soumission du formulaire,
vérification du changement de statut en base). C'est ce test, pas une relecture de code, qui a
permis de détecter et corriger les pièges listés ci-dessus.

## Moteur de classification (phase 4)

`src/Classification/ClassificationService.php` orchestre le pipeline de détection de mots-clés
bilingue (§10 de la documentation fonctionnelle), successeur du `KeywordService` de l'ancienne
application (§4.2/§4.3). Le pipeline est découpé en étapes interchangeables sous
`Classification/Pipeline/`, chacune derrière une interface : `NormalizerInterface` (Unicode NFKD +
suppression des diacritiques, remplace l'ancienne table Latin-1 codée en dur),
`TokenizerInterface` (découpage par classes de caractères Unicode, remplace `str_word_count()`),
`StopWordFilterInterface` (listes de mots vides par langue, `Resources/stopwords/{fr,en}.yaml`,
absentes de l'ancienne application), `StemmerInterface` (racinisation heuristique légère par
suffixes, **pas** un stemmer linguistique complet type Snowball/Porter — limite assumée,
documentée dans `LightSuffixStemmer`), et `ScorerInterface` (fréquence documentaire + poids cumulé
sur le lot, remplace le simple comptage brut d'occurrences).

Le service ne produit **jamais** de mot-clé directement utilisable : chaque nouveau terme détecté
est créé comme `Taxonomy` au statut `SUGGESTED` et rattaché au "sac de mots" de l'article
(`addTaxonomy()`), jamais à ses mots-clés retenus. Deux seuils de configuration
(`config/packages/classification.yaml`) filtrent le bruit avant même la création d'une
suggestion : `classification.min_document_frequency` (un terme isolé à un seul article du lot
n'est pas retenu) et `classification.max_document_frequency_ratio` (un terme présent dans la
quasi-totalité du lot est probablement un mot commun ayant échappé au filtrage de mots vides,
§4.3 L6). En revanche, un terme **déjà validé** par un administrateur lors d'un passage précédent
est automatiquement promu mot-clé (`addKeyword()`) sur tout nouvel article qui le contient, dès
lors qu'il franchit ces mêmes seuils sur le lot en cours : c'est le pipeline qui continue
d'exploiter une décision de validation déjà prise, pas un nouveau contournement du circuit de
validation (§4.4). La réconciliation dans l'autre sens (un mot-clé validé après coup profite
rétroactivement aux articles déjà traités) est assurée côté back-office par
`TaxonomyCrudController::reconcileValidatedTaxonomy()`, appelée depuis les actions
"Valider"/"Valider la sélection".

Chaque langue a son propre espace de taxonomies (`UniqueEntity(['label', 'language'])` sur
`Taxonomy`) : "élection" (FR) et "election" (EN) sont deux entités distinctes, chacune avec son
propre pipeline de stopwords/racinisation (§3bis, §10.1.9).

La reconnaissance des pays cités (`NamedEntityRecognizerInterface`/`CountryNamedEntityRecognizer`)
est un cas à part : elle alimente directement le rattachement natif `Article ↔ Country` (§3.13),
sans passer par le circuit de suggestion/validation, puisqu'il s'agit d'un référentiel fermé (pays
actifs en base) et non de texte libre. Le référentiel des 54 États membres de l'Union africaine
est fourni par `src/Geography/Resources/countries.yaml` et chargé (upsert idempotent par code ISO,
rejouable sans dupliquer) par `php bin/console app:country:fill`. **Limite connue et assumée** :
seul le nom officiel du pays est reconnu ("Sénégal"), pas ses gentilés ("sénégalais"), pourtant
fréquents dans les titres de presse — un enrichissement naturel mais qui nécessite des données
supplémentaires non disponibles à ce stade (voir le docblock de `CountryNamedEntityRecognizer`).

Testé par `tests/Classification/Pipeline/*Test.php` (un test par étape du pipeline, en isolation)
et `tests/Classification/ClassificationServiceTest.php` (intégration bout en bout sur un lot
d'articles français et anglais aux titres réalistes, vérifiant la création de suggestions, la
séparation des espaces de taxonomies par langue, la promotion automatique d'un mot-clé déjà validé
et le rattachement des pays).

## Crawling multi-bots de repli (phase 5)

`src/Crawler/CrawlerService.php` orchestre un crawl HTTP de secours (§9.4 de la documentation
fonctionnelle) : il n'intervient que si le flux RSS n'a pas déjà fourni le titre, l'image ou la
description d'un article ("déclenchement conditionnel") — jamais en remplacement systématique du
flux. C'est `CrawlArticleMetaMessageHandler` (déclenché de façon asynchrone via Messenger,
`App\Crawler\Message\CrawlArticleMetaMessage`) qui applique cette garde, et qui ne complète que les
champs réellement manquants sur l'`Article` : un crawl réussi n'écrase jamais une valeur déjà
fournie par le flux.

`CrawlerService` essaie dans l'ordre les profils du pool (`BotProfileRegistry`, configuré dans
`config/packages/crawler.yaml`) jusqu'à obtenir des métadonnées exploitables (repli en cascade) :
- chaque profil (`BotProfile`) porte son propre user-agent et ses en-têtes HTTP, mais s'identifie
  toujours explicitement comme le bot d'Afrique Actualités — jamais d'usurpation d'un navigateur
  ou d'un autre robot pour contourner un blocage ;
- `RobotsTxtChecker` respecte les règles d'exclusion du site source avant toute requête (implémen-
  tation volontairement simplifiée : seul le groupe `User-agent: *` est interprété — voir son
  docblock) ;
- `OpenGraphMetaExtractor` (`MetaExtractorInterface`) extrait les balises Open Graph/Twitter Cards
  en priorité, avec repli sur `<title>`/`<meta name="description">` ;
- le throttling est **agrégé par domaine** et non par flux (`RateLimiterFactory` nommé
  `crawler_per_domain`, `config/packages/rate_limiter.yaml`) : un média qui expose plusieurs flux
  RSS sur le même domaine ne reçoit donc pas mécaniquement plus de requêtes de crawl qu'un média
  mono-flux (§11.4) ;
- si le quota d'un domaine est épuisé, `CrawlerService` lève `CrawlRateLimitedException` **sans
  tenter aucune requête HTTP**, plutôt que de bloquer le worker sur une attente (`usleep`) : c'est
  `CrawlArticleMetaMessageHandler` qui reprogramme le message via un `DelayStamp` ;
- un résultat de crawl réussi est mis en cache par URL (pool PSR-6 dédié `cache.crawler`,
  `config/packages/cache.yaml`) pour éviter de re-crawler une page déjà traitée récemment ;
- chaque requête HTTP réellement envoyée est journalisée (`CrawlAttempt`, domaine, profil de bot,
  succès, code HTTP) — un refus par `robots.txt` n'en crée pas, faute de requête émise. Ce journal
  alimente le tableau de bord du back-office (taux de succès par domaine, ligne mise en évidence
  si un domaine bloque systématiquement tous les profils) et l'écran `CrawlAttemptCrudController`
  (lecture seule).

Le transport Messenger reste `sync` à ce stade (même limitation de scaffolding que documentée plus
haut pour les traitements continus) : un vrai transport en file d'attente est à brancher avant la
mise en production.

Testé par `tests/Crawler/RobotsTxtCheckerTest.php`, `tests/Crawler/OpenGraphMetaExtractorTest.php`
et `tests/Crawler/BotProfileRegistryTest.php` (chaque composant en isolation, via `MockHttpClient`
pour les deux premiers) ; `tests/Crawler/CrawlerServiceTest.php` (intégration : repli en cascade,
respect de `robots.txt`, mise en cache, abandon sans requête HTTP au quota épuisé) ;
`tests/Crawler/Message/CrawlArticleMetaMessageHandlerTest.php` (garde de non-écrasement, reprogram-
mation au lieu de bloquer) ; `tests/Crawler/Repository/CrawlAttemptRepositoryTest.php` (agrégation
par domaine).

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

### Migration Doctrine — action requise avant la mise en production

Le mapping des 9 entités a été validé (`doctrine:mapping:info`, `doctrine:schema:validate`) et
testé fonctionnellement (`tests/Entity/EntityGraphTest.php`, `tests/Controller/Admin/AdminBackofficeTest.php`)
via une base **SQLite locale**, le démon Docker n'étant pas démarrable dans l'environnement
d'amorçage (permissions du bac à sable). **Aucune migration Doctrine n'a donc encore été
générée** : une migration produite depuis SQLite serait dans le mauvais dialecte SQL pour la cible
MySQL. Dès qu'une base MySQL est joignable (`docker compose up -d database` sur une machine où
Docker fonctionne, ou toute autre instance MySQL 8) :

```
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## État d'avancement

- **Phase 1 (socle technique)** : squelette Symfony 6.4 LTS, structure de dossiers par domaine,
  fondations i18n, CI — terminée.
- **Phase 2 (modèle de données cible)** : 9 entités écrites et validées (mapping + tests
  fonctionnels) — terminée. Reste en dette : générer la migration Doctrine contre une vraie base
  MySQL (voir ci-dessus) et écrire le script d'import des données de l'ancien dépôt
  `thenotilus/afkr` (mots-clés existants repris comme pré-validés, §11.4).
- **Phase 3 (back-office EasyAdmin)** : 9 écrans CRUD + dashboard, écran de validation des
  mots-clés avec actions individuelles et en masse — terminée. Testée par
  `tests/Controller/Admin/AdminBackofficeTest.php`. Reste en dette : formulaire de connexion
  public (actuellement 401 pour un visiteur anonyme, faute de point d'entrée d'authentification —
  prévu en phase 6 avec les autres pages publiques).
- **Phase 4 (moteur de classification bilingue)** : pipeline complet (normalisation, tokenisation,
  filtrage de mots vides, racinisation, scoring, reconnaissance des pays), `ClassificationService`,
  référentiel des 54 pays d'Afrique + commande `app:country:fill` — terminée. Testée par
  `tests/Classification/Pipeline/*Test.php` et `tests/Classification/ClassificationServiceTest.php`
  (intégration bout en bout, titres réels FR/EN). **Point d'attention pour la phase 5** :
  `ClassificationService` n'a encore aucun appelant (aucune commande/worker ne l'injecte), donc le
  compilateur de conteneur Symfony l'élague comme service inutilisé en environnement de test — les
  tests d'intégration le construisent directement avec ses dépendances réelles plutôt que de le
  récupérer depuis le conteneur. Toujours vrai après la phase 5 (crawling) : celle-ci ne branche
  pas `ClassificationService`, qui reste sans appelant tant que le futur worker d'extraction/
  classification continu (§6) n'existe pas.
- **Phase 5 (crawling multi-bots)** : `CrawlerService`, `BotProfileRegistry`, repli en cascade
  entre profils de bots, respect de `robots.txt`, throttling agrégé par domaine, mise en cache par
  URL, journal `CrawlAttempt` et tableau de bord par domaine — terminée. Testée par
  `tests/Crawler/**/*Test.php`. `CrawlArticleMetaMessageHandler` a désormais un appelant réel
  (`CrawlerService` n'est donc plus élagué du conteneur, à la différence de `ClassificationService`
  ci-dessus), mais le transport Messenger reste `sync` : aucun worker d'extraction RSS n'existe
  encore pour dispatcher `CrawlArticleMetaMessage` en conditions réelles — ce sera le rôle du
  futur worker continu (§6).

Les pages publiques (phase 6) sont l'étape suivante (voir le tableau de phasage en §11.2 de la
documentation fonctionnelle).

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

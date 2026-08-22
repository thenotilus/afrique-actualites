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
  Feed/            Flux RSS/Atom (multilingues) + récupération des articles (voir §6)
  Article/         Agrégation et publication des articles
  Classification/  Moteur de détection de mots-clés (pipeline bilingue FR/EN, voir §10)
  Crawler/         Crawling de repli multi-bots, en complément du flux RSS (voir §9.4)
  Taxonomy/        Mots-clés (suggestion → validation, voir §4.4/§10.4)
  Geography/       Pays, croisement pays × mot-clé, archives (voir §3.13)
  News/            "Unes" thématiques utilisateur
  Newsletter/       Newsletter hebdomadaire et gestion des abonnés
  Social/          Partage réseaux sociaux — interfaces posées, implémentation différée (§3.8)
  User/            Compte utilisateur, sécurité
  Shared/          Code transverse : Utils/, ValueObject/, Dto/, Pagination/, Twig/
  Sitemap/         Index de sitemaps racine (hors préfixe /{_locale}, voir plus bas)
  Controller/Admin/ Contrôleurs/Dashboard EasyAdmin (back-office unifié, §3.12)
  Controller/      Contrôleurs publics (accueil, pays, recherche, "Unes", compte...), phase 6
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
  (`config/routes.yaml`). Le back-office EasyAdmin n'est pas concerné par ce préfixe — et le
  sitemap-index racine non plus (voir plus bas, "Pages publiques").
- Traductions : `translations/messages.fr.yaml` et `translations/messages.en.yaml`.
- Sélecteur de langue (`templates/public/base.html.twig`) : régénère l'URL courante avec la même
  route et les mêmes paramètres, seule la `_locale` change — fonctionne donc sans code spécifique
  sur une page de détail (article, "Une", pays × mot-clé).
- Le pipeline de classification (mots-clés) applique un traitement **par langue** (stopwords,
  racinisation, entités nommées) — voir §10.1.9 et §10.2 de la documentation fonctionnelle. Chaque
  `Taxonomy` est rattachée à une langue.

## Traitements continus (extraction / classification)

L'extraction des flux et la classification des mots-clés tournent **en continu** en production
(confirmé par le porteur produit, §6). La cible d'architecture est un **worker Messenger
persistant** (superviseur systemd/Supervisor), pas une commande cron périodique classique. La
récupération des flux et la classification sont aujourd'hui pilotées ponctuellement par la commande
`app:feed:ingest` (à cadencer, cf. « Récupération des articles »), tandis que le crawl de repli est
déjà pleinement asynchrone (transport `async` Doctrine dans `config/packages/messenger.yaml`,
worker `messenger:consume async`). Un worker d'extraction/classification persistant reste l'étape
suivante pour passer du déclenchement ponctuel au fil de l'eau.

Un verrou d'exécution (`symfony/lock`, `config/packages/lock.yaml`) doit être utilisé par ces
workers pour éviter tout chevauchement (§8, point 12 de la documentation fonctionnelle). Le store
de lock est basé sur la base de données (`LOCK_DSN=${DATABASE_URL}`, `DoctrineDbalStore`, table
`lock_keys` auto-créée) : un store partagé entre instances, adapté dès qu'un worker tourne en
plusieurs exemplaires en production.

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

Chaque nouveau terme détecté est créé comme `Taxonomy` **validée par défaut** (décision produit :
`validateAutomatically()`), rattachée au "sac de mots" de l'article (`addTaxonomy()`) **et** aussitôt
à ses mots-clés retenus (`addKeyword()`), puisqu'elle est validée. L'écran de modération reste
disponible pour rejeter a posteriori un terme indésirable. Deux seuils de configuration
(`config/packages/classification.yaml`) filtrent le bruit avant même la création : 
`classification.min_document_frequency` (un terme isolé à un seul article du lot n'est pas retenu)
et `classification.max_document_frequency_ratio` (un terme présent dans la quasi-totalité du lot
est probablement un mot commun ayant échappé au filtrage de mots vides, §4.3 L6). Un terme **déjà
connu** conserve son statut : le pipeline ne ressuscite jamais une taxonomie qu'un administrateur a
explicitement **rejetée**, et un terme déjà validé reste validé et promu mot-clé sur tout nouvel
article qui le contient. La réconciliation dans l'autre sens (un mot-clé validé après coup profite
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

## Récupération des articles (phase 6)

`src/Feed/FeedIngester.php` transforme un `Feed` en `Article` : c'est la brique d'ingestion qui
manquait jusqu'ici (aucun code ne créait d'`Article` — voir le point d'attention historique en
phase 5 plus bas). Il récupère le flux via `symfony/http-client` (user-agent dédié
`feed.user_agent`, `config/packages/feed.yaml`), le parse avec `src/Feed/FeedParser.php` — un
parseur RSS 2.0 / Atom 1.0 **sans dépendance externe** (ext-simplexml), pour rester dans la
contrainte de dépendances du projet — puis, pour chaque entrée :

- **déduplique par `urlHash`** en une seule requête (`ArticleRepository::findExistingUrlHashes()`)
  et écarte les doublons internes au flux : l'ingestion est rejouable sans créer de doublon ;
- **crée l'`Article`** avec les métadonnées du flux (langue héritée du `Feed`) et renseigne au
  passage le libellé du flux resté anonyme (`Feed::$label`) depuis le titre de canal ;
- **publie par défaut** l'article s'il est **complet** (`Article::isComplete()` : titre +
  description + image — décision produit) ; sinon il reste non publié jusqu'à ce que le crawl comble
  les métadonnées manquantes, moment où il est publié à son tour (même règle dans le handler de
  crawl) ;
- **déclenche le crawl de repli** (`CrawlArticleMetaMessage`, §9.4) uniquement pour les entrées
  dont le flux n'a pas fourni titre, description ou image.

La commande `app:feed:ingest` (`src/Feed/Command/IngestFeedsCommand.php`) est le point d'entrée :
elle ingère tous les flux actifs (ou un seul via `--feed`), puis lance la classification (§10) en
**une seule passe** sur l'ensemble des articles créés — le scorer travaillant par fréquence
documentaire, un lot global est plus pertinent qu'un passage par flux. C'est ce qui donne enfin un
appelant réel à `ClassificationService`. `--dry-run` lit et analyse les flux sans rien écrire.
C'est la forme ponctuelle et rejouable du « worker d'extraction continu » (§6), à cadencer par un
ordonnanceur tant que le worker persistant n'est pas branché.

**Le crawl est traité en asynchrone** (`CrawlArticleMetaMessage` routé vers le transport `async`,
file Doctrine `messenger_messages`, `config/packages/messenger.yaml`). L'ingestion (comme
`app:crawler:run`) se contente donc de **mettre les crawls en file** et rend la main aussitôt ; un
worker les traite ensuite :

```
php bin/console messenger:consume async
```

Ce choix n'est pas qu'une optimisation : le crawl est plafonné à 12 requêtes/minute/domaine
(`config/packages/rate_limiter.yaml`) et, quota épuisé, le handler reprogramme le message via un
`DelayStamp`. Sur l'ancien transport `sync`, ce délai était ignoré et le handler se re-dispatchait
**en boucle récursive synchrone** jusqu'à épuisement mémoire dès qu'un flux apportait plus de 12
articles à compléter sur un même domaine. En asynchrone, le `DelayStamp` est réellement différé et
le worker respire. La table de file d'attente se crée par
`php bin/console messenger:setup-transports` (transport Doctrine `symfony/doctrine-messenger`).

Testé par `tests/Feed/FeedParserTest.php` (RSS/Atom, extraction d'image, tolérance, formats
rejetés), `tests/Feed/FeedIngesterTest.php` (création, libellé, crawl conditionnel, idempotence,
dry-run) et `tests/Feed/Command/IngestFeedsCommandTest.php` (flux actifs uniquement, `--feed`,
`--dry-run`, gestion d'échec) — tous via `MockHttpClient`, sans réseau.

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

La commande `app:crawler:run` (`src/Crawler/Command/CrawlArticlesCommand.php`) dispatche un
`CrawlArticleMetaMessage` pour chaque article dont le flux RSS n'a pas fourni toutes les métadonnées
(titre, description ou image — pré-filtre `ArticleRepository::findIdsMissingMetadata()`). Elle
comble ainsi le manque d'appelant réel relevé plus bas : en attendant le worker d'extraction RSS
continu (§6), c'est le point d'entrée pour lancer le crawl de repli. Idempotente et rejouable
(`--limit` pour plafonner le lot, `--dry-run` pour lister sans crawler) : le handler revérifie
chaque article et le cache par URL évite de retraiter une page déjà résolue.

`CrawlArticleMetaMessage` est routé vers le transport `async` (file Doctrine) : `app:crawler:run`
se contente de mettre les crawls en file, traités par un worker `php bin/console messenger:consume
async`. Voir la section « Récupération des articles » ci-dessus pour le détail (et pourquoi
l'asynchrone est nécessaire, pas seulement préférable, face à la reprogrammation par `DelayStamp`).

Testé par `tests/Crawler/RobotsTxtCheckerTest.php`, `tests/Crawler/OpenGraphMetaExtractorTest.php`
et `tests/Crawler/BotProfileRegistryTest.php` (chaque composant en isolation, via `MockHttpClient`
pour les deux premiers) ; `tests/Crawler/CrawlerServiceTest.php` (intégration : repli en cascade,
respect de `robots.txt`, mise en cache, abandon sans requête HTTP au quota épuisé) ;
`tests/Crawler/Message/CrawlArticleMetaMessageHandlerTest.php` (garde de non-écrasement, reprogram-
mation au lieu de bloquer) ; `tests/Crawler/Repository/CrawlAttemptRepositoryTest.php` (agrégation
par domaine) ; `tests/Crawler/Command/CrawlArticlesCommandTest.php` (dispatch limité aux articles
incomplets, `--limit`, `--dry-run`, rejet d'un `--limit` invalide).

## Pages publiques (phase 6)

Contrôleurs sous `src/Controller/` (préfixés `/{_locale}`, voir "Internationalisation" plus haut).
Les listes d'articles (accueil, par flux, par mot-clé, par pays, résultats de recherche, page d'une
"Une") partagent toutes le même partiel `templates/public/_article_list.html.twig` : seule la
requête de base change (`ArticleRepository::publishedQueryBuilder()` + un filtre additionnel —
`byFeed()`, `byKeyword()`, `byCountry()`, `byAnyKeyword()`, `bySearchQuery()`), pagination fournie
par `App\Shared\Pagination\QueryPaginator` (au-dessus de `Doctrine\ORM\Tools\Pagination\Paginator`,
sans bundle de pagination supplémentaire).

- **Page pays** (`CountryController`, §3.13, écran prioritaire) : `/pays/{code}` puis
  `/pays/{code}/{mot-clé}` pour le croisement. Le mot-clé du croisement est **optionnel** et les
  archives sont **paginées sans borne de temps** — la question posée en §12.2 point 1 (mot-clé
  obligatoire ? archives bornées à 12/24 mois ?) est restée sans réponse du porteur produit ; ce
  sont les interprétations par défaut retenues, à ajuster si la réponse va dans l'autre sens. Les
  mots-clés proposés dans le croisement (`TaxonomyRepository::findValidatedForCountry()`) sont
  limités à ceux réellement présents sur au moins un article publié de ce pays.
- **"Unes"** (`UserNewsController`, `UserNewsType`, §3.4) : annuaire public, page d'une "Une"
  (articles réunissant l'un quelconque de ses mots-clés, `ArticleRepository::byAnyKeyword()`), et
  gestion par l'utilisateur connecté (créer/éditer/dupliquer/supprimer, "mes Unes"). Le champ
  `taxonomies` du formulaire est en `by_reference: false` : Symfony Form appelle alors
  `UserNews::addTaxonomy()`/`removeTaxonomy()` plutôt que de remplacer la collection en bloc, ce
  qui fait rejouer la garde de l'entité (taxonomie non validée refusée) — contrairement au
  back-office EasyAdmin (§ section Back-office) où seule la restriction de `query_builder` protège,
  la double garde est possible ici. La visibilité privée est réservée aux administrateurs : le
  contrôleur la force à `false` après validation du formulaire si l'utilisateur courant n'est pas
  admin, quoi que le formulaire ait transmis.
- **Connexion/inscription** (`SecurityController`, `RegistrationController`, §3.4) : formulaire
  natif Symfony `form_login` (email/mot de passe uniquement — la connexion Facebook évoquée dans le
  prompt de conception UX reste liée au partage réseaux sociaux, différé en phase 9). L'inscription
  reste facultative pour consulter le site : elle ne sert qu'à créer des "Unes" personnalisées. Pas
  de réinitialisation de mot de passe oublié dans ce lot — reste en dette.
- **Recherche** (`SearchController`, §3.5) : titre, description ou libellé d'un mot-clé validé
  (`ArticleRepository::bySearchQuery()`), remplace la recherche par taxonomies uniquement de
  l'ancienne application. Un moteur d'indexation dédié reste une amélioration future recommandée
  (§9.1), pas un prérequis de cette phase.
- **Newsletter** (`NewsletterController`) : simple capture d'email en pied de page
  (`templates/public/base.html.twig`), cible d'un formulaire HTML brut plutôt qu'un Symfony Form —
  un seul champ ne justifie pas cette machinerie. `NewsletterSubscriber` n'expose volontairement
  aucun mutateur pour son email (identifiant fixé à la construction), d'où l'absence de
  `data_class` sur ce point précis.
- **Sitemap** (`SitemapIndexController` sous `src/Sitemap/Controller/`, `SitemapController` sous
  `src/Controller/`, §3.10) : structure sitemap-index/sous-sitemaps que l'ancienne application
  n'avait pas (un unique fichier plat limité aux 100 derniers articles). L'index racine
  (`/sitemap.xml`, **sans** préfixe `/{_locale}` — un moteur de recherche l'attend à cet
  emplacement précis) référence un sous-sitemap par langue, lui-même découpé par lots de
  `SitemapController::CHUNK_SIZE` (5000) articles. **Piège rencontré** : l'option `exclude` du
  loader de routes par attributs Symfony (`type: attribute` sur un répertoire) ne fonctionne pas
  pour exclure un seul fichier au sein d'un répertoire déjà importé (elle est bien prise en compte
  par le loader PSR-4 utilisé pour `services.yaml`, mais pas par `AttributeDirectoryLoader` pour le
  routage) — la seule façon fiable d'obtenir une route non préfixée est de sortir physiquement ce
  contrôleur du répertoire scanné par la ressource préfixée, avec sa propre ressource de routage
  dédiée (voir `config/routes.yaml` et le docblock de `SitemapIndexController`).
- **Slugs d'URL** (`App\Shared\Utils\Slugifier`, filtre Twig `slugify`) : purement cosmétiques
  (`/article/{id}/{slug}`) — c'est l'identifiant qui identifie réellement la ressource, pas ce
  fragment, donc aucune canonicalisation/redirection à gérer si le titre change après coup.

Testé par `tests/Controller/PublicPagesTest.php` : rendu de chaque page publique (FR et EN),
parcours de connexion (formulaire réel, pas seulement `loginUser()`) et d'inscription, et cycle de
vie complet d'une "Une" par un utilisateur connecté (créer, apparaître publiquement, éditer,
dupliquer, supprimer). **Piège de test rencontré** : `KernelBrowser` reboote le noyau à chaque
`request()`, donc une entité chargée par le test *avant* une requête n'est jamais `===` à
l'instance rechargée par le noyau rebooté *après* — comparer par identifiant (`getId()`) plutôt que
par identité d'objet PHP, et appeler `$entityManager->clear()` avant de relire une entité modifiée
par la requête précédente (même pattern déjà utilisé par `AdminBackofficeTest`, phase 3).

Le formulaire de connexion existant, `AdminBackofficeTest::testDashboardRejectsAnonymousVisitors`
et le test public équivalent attendent désormais une redirection vers `/fr/connexion` plutôt que le
401 brut de la phase 3 (qui n'avait pas encore de point d'entrée d'authentification).

## Modèle de données (phase 2)

Les entités vivent sous `src/<Domaine>/Entity/` (voir liste ci-dessus). Points de conception à
connaître :

- **`Taxonomy`** porte un statut (`App\Taxonomy\Enum\TaxonomyStatus` : `SUGGESTED` par défaut à la
  création de l'entité, `VALIDATED`, `REJECTED`, `ARCHIVED`) et une langue
  (`App\Shared\ValueObject\Language`). Une même chaîne peut donc exister comme deux taxonomies
  distinctes selon la langue. Note : les taxonomies **issues du pipeline** sont validées d'emblée
  (`validateAutomatically()`, décision produit) ; le statut `SUGGESTED` initial ne concerne plus
  que les créations hors pipeline.
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
  `config/packages/cache.yaml` (adaptateur filesystem par défaut ; §9.1, §8 point 8).

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
  `tests/Controller/Admin/AdminBackofficeTest.php`. Le formulaire de connexion public, alors en
  dette, est livré en phase 6.
- **Phase 4 (moteur de classification bilingue)** : pipeline complet (normalisation, tokenisation,
  filtrage de mots vides, racinisation, scoring, reconnaissance des pays), `ClassificationService`,
  référentiel des 54 pays d'Afrique + commande `app:country:fill` — terminée. Testée par
  `tests/Classification/Pipeline/*Test.php` et `tests/Classification/ClassificationServiceTest.php`
  (intégration bout en bout, titres réels FR/EN). **Point d'attention pour la phase 5** :
  `ClassificationService` n'a encore aucun appelant (aucune commande/worker ne l'injecte), donc le
  compilateur de conteneur Symfony l'élague comme service inutilisé en environnement de test — les
  tests d'intégration le construisent directement avec ses dépendances réelles plutôt que de le
  récupérer depuis le conteneur. Ce n'est plus vrai depuis la commande d'ingestion `app:feed:ingest`
  (voir « Récupération des articles » ci-dessus), qui injecte `ClassificationService` : il a
  désormais un appelant réel et n'est plus élagué du conteneur.
- **Phase 5 (crawling multi-bots)** : `CrawlerService`, `BotProfileRegistry`, repli en cascade
  entre profils de bots, respect de `robots.txt`, throttling agrégé par domaine, mise en cache par
  URL, journal `CrawlAttempt` et tableau de bord par domaine — terminée. Testée par
  `tests/Crawler/**/*Test.php`. `CrawlArticleMetaMessageHandler` a désormais un appelant réel
  (`CrawlerService` n'est donc plus élagué du conteneur), et la commande `app:crawler:run` permet
  de dispatcher `CrawlArticleMetaMessage` en conditions réelles pour les articles à métadonnées
  incomplètes. Le crawl est traité en **asynchrone** (transport `async` Doctrine, worker
  `messenger:consume async`) — cf. la note sur la reprogrammation par `DelayStamp`. La
  **récupération des articles** proprement dite (lecture des flux → création des `Article` →
  classification), longtemps absente, est livrée avec la phase 6 : voir la section dédiée ci-dessus
  et la commande `app:feed:ingest`.
- **Phase 6 (pages publiques)** : accueil, listes (flux/mot-clé/pays/recherche), fiche article,
  page pays avec croisement mot-clé et archives paginées, "Unes" (annuaire, page dédiée, gestion
  utilisateur complète), connexion/inscription, capture newsletter, sitemap-index — terminée.
  Testée par `tests/Controller/PublicPagesTest.php`. Reste en dette : réinitialisation de mot de
  passe oublié, et le mot-clé du croisement pays × mot-clé / la profondeur des archives restent
  des interprétations par défaut faute de réponse du porteur produit (§12.2 point 1) — voir la
  section "Pages publiques" ci-dessus.

Les modules restants du plan (newsletter hebdomadaire, double-run & bascule) sont les étapes
suivantes (voir le tableau de phasage en §11.2 de la documentation fonctionnelle).

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

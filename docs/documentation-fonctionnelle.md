# Documentation fonctionnelle — Afrique Actualités (afkr)

> Document de référence produit par l'analyse du code source du dépôt `thenotilus/afkr`.
> Objectif : servir de socle fonctionnel et technique pour préparer une **refonte complète
> sur une version récente de Symfony** et pour cadrer les **améliorations structurelles de
> la détection de mots-clés**.
>
> Date de l'analyse : 21/08/2026 · Périmètre : intégralité du code source (58 fichiers PHP,
> ~6 900 lignes), configuration Symfony, routing, templates de haut niveau.

---

## 1. Présentation générale du produit

**Afrique Actualités** (nom de domaine `afrique-actualites.com`, lien court `actu.me`) est un
**agrégateur d'actualités panafricain** créé en novembre 2016. Le site :

- collecte automatiquement des articles depuis des flux RSS/Atom de médias partenaires ;
- les classe par **mots-clés extraits automatiquement** de leur titre ;
- les republie sous forme de pages thématiques, de "Unes" personnalisées, de newsletters et
  de flux RSS de sortie ;
- diffuse une sélection d'articles sur les réseaux sociaux (Facebook, Twitter) ;
- propose un compte utilisateur permettant de créer des listes de veille thématiques ("Unes").

Le slogan interne (paramètre SEO) est : *« Afrique-actualites.com est le premier agrégateur
d'actualité africaine. Retrouvez toute l'information de l'Afrique en temps réel et par pays. »*

### 1.1 Stack technique actuelle (constat)

| Composant | Version / élément constaté |
|---|---|
| PHP | `>=7.3` (composer.json) |
| Symfony | **3.3** (`symfony/symfony: 3.3.*`) — hors support depuis 2018 |
| ORM | Doctrine ORM ^2.5, mapping par **annotations**, stratégie de nommage *underscore* |
| Base de données | MySQL (pdo_mysql) |
| Moteur de gabarits | Twig (Twig_Extension legacy, pas de `TwigFilter`/`TwigFunction` PHP 8) |
| Authentification | FOSUserBundle ~2.0 + HWIOAuthBundle (connexion Facebook) |
| Back-office | EasyAdminBundle ^1.16 **+** un contrôleur "maison" (`AdminController`) |
| Flux RSS | `debril/feed-io` (lecture) + `debril/rss-atom-bundle` (génération) |
| Emailing | SwiftMailer + `sendinblue/sendinblue-api-bundle` (newsletters transactionnelles) |
| Réseaux sociaux | `martin-georgiev/social-post-bundle` (Facebook), `abraham/twitteroauth` (Twitter) |
| CRON | `cron/cron-bundle` déclaré dans le kernel mais **aucun job n'est configuré dans le dépôt** (voir §8.4) |
| Déploiement | Deployer (`deploy.php`), déploiement SSH sur `afrique-actualites.com` |
| Tests | 1 seul test (`DefaultControllerTest`), qui teste en réalité le squelette Symfony par défaut et **ne correspond pas à l'application réelle** |

### 1.2 Structure du code

```
app/                 Configuration Symfony 3 classique (config.yml, routing.yml, security.yml, services.yml)
src/AppBundle/        Cœur métier (Controller, Entity, Service, Command, Form, EventSubscriber, Utils)
src/UserBundle/       Extension FOSUserBundle (inscription)
tests/                1 test fonctionnel obsolète
web/                  Front controller Symfony 3 (app.php / app_dev.php)
```

Il n'existe **qu'un seul bundle métier** (`AppBundle`), sans découpage par domaine
(Feed/Article/Taxonomy/User/Newsletter cohabitent dans les mêmes dossiers `Entity`,
`Service`, `Controller`). C'est un point clé pour la refonte (voir §9).

---

## 2. Utilisateurs et rôles

| Profil | Description | Accès |
|---|---|---|
| **Visiteur anonyme** | Consulte le site public : accueil, listes d'articles, recherche, pages "Unes" publiques, article détaillé, flux RSS de sortie, sitemap. | Lecture seule |
| **Utilisateur inscrit** (`ROLE_USER`) | Inscription email/mot de passe (FOSUserBundle) ou connexion Facebook (HWIOAuthBundle). Peut créer/éditer/supprimer ses propres **"Unes"** (`UserNews`), des listes de mots-clés personnalisées qui génèrent une page de veille dédiée. | Zone `/r/*` |
| **Administrateur** (`ROLE_ADMIN`, hérite de `ROLE_USER`) | Gère les flux RSS, les articles, les taxonomies/mots-clés, les publicités ("Publications"), le contenu des newsletters hebdomadaires. | Zone `/admin/*` |

L'authentification sociale crée l'utilisateur à la volée (`UserProvider::createUserByOAuthUserResponse`)
et génère un mot de passe aléatoire s'il n'existe pas. Un événement `FOSUserEvents::REGISTRATION_COMPLETED`
déclenche un email de bienvenue (`UserSubscriber` → `EmailService`).

---

## 3. Cartographie fonctionnelle

### 3.1 Gestion des flux RSS (Feed)

- Entité `Feed` : libellé, description, URL, statut `active`, statut `sponsored` (mise en avant),
  hash MD5 de l'URL (déduplication).
- CRUD dans `AdminController` (`/admin/flux/...`), formulaire `FeedType` (libellé + URL).
- Activation/désactivation d'un flux sans le supprimer (`toggleActive`).
- Un flux peut aussi être créé **implicitement** lorsqu'un visiteur soumet un article isolé
  par URL (`ArticleService::extractArticleFromLink`) : le domaine devient un flux `active = false`
  avec la mention `**UPLOADED BY USER**`.

### 3.2 Extraction et agrégation des articles

- **Commande** `app:articles:extract` (`ArticlesCommand`) : parcourt tous les flux `active = true`,
  appelle `FeedService::extractArticlesFromFeed()` qui utilise `feed-io` pour parser le flux XML,
  crée une `Article` par item (titre, description tronquée à 100 mots, URL, image issue des médias
  du flux, date de publication). Une déduplication est faite par hash MD5 de l'URL
  (`ArticleService::createArticle`).
- Recherche d'image de secours (Open Graph / Twitter Card) si le flux n'en fournit pas
  (`Utils::searchImageOnPage`), avec filtrage des images trop petites (< 250 px de large).
- À la fin de l'extraction, la commande enchaîne **automatiquement** sur la commande de
  classification (`app:articles:classify`) avec une fenêtre de 12 heures.
- Un article peut aussi être ajouté manuellement par un visiteur via `/r/article/ajout`
  (extraction des métadonnées OpenGraph d'une simple URL).
- **Récupération des métadonnées manquantes (état actuel)** : lorsque le flux RSS ne fournit pas
  un titre, une image ou une description exploitable, le code retombe sur un scraping simple de
  la page source (`ArticleService::extractMainMeta`, `Utils::searchImageOnPage`,
  `Utils::getUrlData`) via `Utils::file_get_contents`, qui utilise un **unique user-agent codé en
  dur** (`Mozilla/5.0 ... Firefox/2.0.0.13`, une signature de navigateur obsolète depuis 2008),
  sans gestion de blocage, de nouvelle tentative, ni de rotation. De nombreux sites sources
  bloquent ou renvoient un contenu dégradé à ce user-agent unique, ce qui se traduit par des
  articles sans image ni description exploitable.
- **Cible pour la refonte** : mettre en place un système de crawling plus sophistiqué, avec un
  pool de bots utilisant des user-agents différents, déclenché **uniquement en complément** du
  flux RSS pour récupérer les métadonnées manquantes (titre, image, description). Voir le détail
  fonctionnel et l'architecture cible en §9.4.

### 3.3 Taxonomies et mots-clés — cœur du système de classification

C'est le module central visé par la demande d'amélioration. Voir la **section 4** dédiée,
qui en fait une analyse détaillée.

### 3.4 "Unes" thématiques utilisateur (UserNews)

- Une `UserNews` est une liste nommée de `Taxonomy` (mots-clés), publique ou privée
  (`private` réservé aux admins à la création, cf. `UserNewsController::createOrUpdateNewsAction`).
- Génère automatiquement une page de veille (`show_news`) listant les articles associés à
  au moins un des mots-clés de la liste.
- Fonctions : créer, éditer, dupliquer (`copy_news`), supprimer une "Une" ; aperçu personnel
  (`news_preview`) ; widget "dernières Unes" en façade.
- Sert également de **filtre géographique** : `Country` référence aujourd'hui une `UserNews`
  (`getNews()`), un mécanisme de contournement utilisé pour l'actualité par pays — remplacé dans
  la cible par un rattachement natif `Article ↔ Country` (voir §3.13, confirmé comme fonctionnalité
  à part entière pour la refonte).

### 3.5 Recherche

- `SearchController` : recherche plein texte simplifiée, basée uniquement sur les
  **taxonomies** (mots-clés extraits), pas sur le titre/contenu brut de l'article.
- `Utils::prepareStringForSearch` découpe la requête sur les espaces et ne garde que les mots
  de plus de 3 caractères (aucune gestion des accents, ponctuation, opérateurs, tri par pertinence).
- Requête Doctrine en `LIKE %mot%` chaînés en `AND` sur chaque terme → coûteux et peu pertinent
  au-delà de quelques milliers d'articles.

### 3.6 Publications ("pubs" / bandeaux)

- Entité `Publication` : titre, contenu (probablement HTML/CKEditor), lien, position d'affichage,
  statut actif, indicateur "général" (affiché sur toutes les pages génériques).
- CRUD admin (`/admin/pubs/...`). Vraisemblablement des encarts publicitaires ou éditoriaux
  insérés dans les listes d'articles.

### 3.7 Newsletters

**Réponses du porteur produit :** la newsletter quotidienne est **abandonnée** (au moins pour le
lot initial de la refonte) ; la newsletter hebdomadaire reste en usage et compte aujourd'hui une
**quarantaine de destinataires**, alors même que le site ne requiert pas d'inscription des visiteurs
— cette liste de diffusion est donc probablement **distincte des comptes `User`** du site (à
confirmer précisément, voir §12).

- **Newsletter quotidienne** (`app:articles:daily`, `DailyNewsCommand`) : sélectionne un article
  par mot-clé récent (fenêtre de la veille), complète jusqu'à une limite (8) avec les articles
  les plus récents, envoie un email via l'API SendinBlue. Le destinataire est actuellement
  **codé en dur** (adresse email personnelle du développeur), confirmant qu'il s'agissait d'un
  brouillon de test jamais généralisé. **Ne fait pas partie du périmètre de la refonte** : la
  commande `DailyNewsCommand` et les routes associées (`daily_news`, `day_news`,
  `yesterday_news`) ne sont pas à reprendre (voir §11, §14).
- **Newsletter hebdomadaire** (`WeeklyNewsletter`) : contenu géré manuellement via le back-office
  (`AdminController::createOrEditNewsLetterContent`, formulaire `WeeklyNewsLetterContentType`),
  cible soit tous les utilisateurs, soit une liste (`targetedUsers`), avec objet, texte, mots-clés
  et articles sélectionnés à la main. La consultation `getBestArticlesOfTheWeek` calcule un
  "meilleur article du jour" par jour glissant sur 7 jours. **Conservée dans la refonte**, avec une
  gestion de la liste d'abonnés à clarifier (§12) : soit un modèle d'abonné dédié, indépendant du
  compte `User` (plus cohérent avec un site sans inscription obligatoire), soit une migration vers
  des comptes légers.
- Chaque `User` stocke un résumé de ses mots-clés vus sur 7 jours (`weeklyKeywords`, JSON).

### 3.8 Partage sur les réseaux sociaux

**Réponse du porteur produit :** cette fonctionnalité **sera implémentée ultérieurement**, dans une
phase postérieure au lot initial de la refonte (voir §11.2, phase 9). Elle n'est donc pas à
développer dans un premier temps, mais les interfaces du module `Social/` (§9.2) doivent rester
prévues pour ne pas bloquer son branchement futur.

- **Commande** `app:articles:share` (`ShareCommand`) : sélectionne un article "à partager"
  (`ArticleService::getArticleToShare`) selon une logique d'anti-répétition (exclut les mots-clés
  déjà partagés dans les 6 dernières heures) et marque l'article `shared = true`.
- Note (état du code actuel) : la commande **marque l'article comme partagé mais n'appelle plus
  explicitement** `FacebookService`/`TwitterService` dans son corps actuel — ces services existent
  (`shareArticle`, `tweetArticle`) mais leur point d'appel effectif n'a pas été identifié dans le
  flux observé, ce qui est cohérent avec le choix produit de différer cette fonctionnalité.

### 3.9 Flux RSS de sortie (syndication)

- `FeedController::getHighlightedArticlesRssFeedAction` (`/feed-afkr/type/{filter}`) génère un
  flux RSS (via `feed-io`) des articles partagés, filtré par pays ou par nombre de mots-clés,
  avec hashtags générés à partir des mots-clés de chaque article.

### 3.10 SEO / Sitemap

- `SitemapsController` génère un `sitemap.xml` dynamique (accueil, pages "Unes", 100 derniers
  articles), sans pagination ni sitemap-index — non adapté à un volume important d'articles.
- Extension Twig `slugify` pour générer les URLs des articles (`Article::getSlug()`).

### 3.11 Cache applicatif

- `CachedDataService` + entité `Cache` : cache générique clé (hash MD5) / valeur sérialisée PHP
  avec expiration, utilisé pour (a) mettre en cache le rendu de la page d'accueil et (b) mettre
  en cache des appels HTTP externes (cURL maison, sans librairie HTTP standard).
- Ce n'est **pas** un cache Symfony standard (pas de PSR-6/PSR-16), mais une implémentation
  ad hoc stockée en base de données.

### 3.12 Back-office administrateur

**Précision apportée par le porteur produit (fait foi sur le fonctionnement cible) :** la gestion
des flux RSS, des mots-clés (y compris leur validation, voir §4.4), des utilisateurs et des autres
éléments de configuration (publications, newsletters) est opérée via **un back-office unique basé
sur EasyAdminBundle**. C'est le point d'entrée de référence pour les administrateurs au quotidien.

Ce que montre le code du dépôt à ce jour, à réconcilier avec cette pratique cible :

1. **EasyAdminBundle** (`/admin` via `easy_admin_bundle` routing, config `easy_admin` dans
   `config.yml`) — le paramétrage actuellement versionné n'expose un CRUD générique que sur
   `Article`, `User`, `Feed` : la couverture (Taxonomy/mots-clés, Publication, WeeklyNewsletter)
   est **à compléter** pour correspondre pleinement à l'usage cible décrit ci-dessus.
2. **`AdminController` "maison"** (`/admin/flux`, `/admin/mot`, `/admin/pubs`, `/admin/newsletter`)
   — CRUD métier spécifique avec formulaires Symfony dédiés, qui couvre aujourd'hui dans le code
   des besoins non (encore) présents dans la configuration EasyAdmin (ex. bascule d'activation
   d'un flux, extraction manuelle d'articles, édition du contenu de newsletter). À la refonte, ces
   actions doivent être **absorbées dans EasyAdmin** (actions personnalisées EasyAdmin) plutôt que
   maintenues en parallèle dans un contrôleur distinct.
3. **SonataAdminBundle / SonataDoctrineORMAdminBundle** — présents dans `composer.json`
   **mais non enregistrés dans `AppKernel`** : dépendance morte, à retirer.

**Cible pour la refonte** : un back-office **unique** sous EasyAdminBundle (version 4, compatible
Symfony récent), couvrant l'intégralité des entités administrables (`Feed`, `Taxonomy` avec l'écran
de validation des suggestions, `User`, `Publication`, `WeeklyNewsletter`), avec suppression de
l'`AdminController` maison et de la dépendance Sonata. Voir le plan détaillé en §11 (phase 3).

### 3.13 Pays (Country) — fonctionnalité confirmée pour la refonte

**Réponse du porteur produit :** la fonctionnalité "actualité par pays" doit être **reprise et
complétée** dans la refonte, avec deux capacités supplémentaires par rapport à l'existant :
un **croisement par mot-clé** (filtrer les articles d'un pays par un mot-clé validé donné) et une
**consultation d'archives** (remonter dans le temps au-delà des seuls articles récents).

État actuel du code :

- Entité `Country` (code, nom FR/EN, statut actif, `UserNews` associée) peuplée par la commande
  `app:country:fill` depuis un fichier YAML statique (`resources/countries.yml`).
- Le lien `Country → UserNews` existe mais **aucun contrôleur n'exploite** cette association pour
  une page "actualité par pays" dédiée : c'est un détour (chaque pays devrait avoir sa `UserNews`
  de mots-clés associés) plutôt qu'un rattachement direct des articles à un pays.

Conception cible pour la refonte :

- **Rattachement natif `Article ↔ Country`** (relation N—N ou N—1 selon qu'un article peut concerner
  plusieurs pays), plutôt que le détour actuel par `UserNews`. Ce rattachement peut être **alimenté
  automatiquement** par la reconnaissance d'entités nommées du nouveau pipeline de classification
  (§10.1.6) : un pays cité/concerné dans un article est déjà un candidat naturel de cette détection,
  ce qui évite un travail de tag manuel systématique.
- **Croisement pays × mot-clé** : une page pays (`/pays/{code}/`) doit permettre d'affiner par un
  mot-clé validé (`/pays/{code}/{mot-cle}/`), en réutilisant l'infrastructure de filtrage déjà
  prévue pour les pages `/tag/{label}/` (§10.4 : seules les taxonomies `VALIDATED` doivent
  apparaître dans ce croisement).
- **Consultation d'archives** : contrairement aux pages actuelles (limitées aux articles récents,
  cache de rendu à courte durée de vie, cf. §3.11), la page pays doit permettre une **navigation
  paginée dans le temps** (par mois/année, ou défilement infini avec bornage de dates). Cela
  suppose un accès performant à l'historique complet des articles, cohérent avec la recommandation
  d'un moteur de recherche/index dédié en §9.1.
- La profondeur exacte des archives (illimitée ou bornée) et le caractère obligatoire ou non du
  mot-clé dans le croisement restent à préciser (voir §12).

---

## 3bis. Internationalisation (multilingue) — nouvelle exigence confirmée pour la refonte

**Réponses du porteur produit :** le site n'agrège aujourd'hui que des flux **100 % francophones**,
mais la refonte doit introduire la gestion de flux en **anglais**, avec un **traitement de
l'anglais de bout en bout** (pas seulement une tolérance technique), et l'**interface du site**
elle-même doit être disponible en français **et** en anglais. Il ne s'agit donc pas d'une
préparation optionnelle mais d'une exigence ferme du périmètre de refonte.

Cela recouvre trois volets distincts, à traiter ensemble :

1. **Ingestion multilingue des flux** : le modèle `Feed` doit porter une **langue** (au minimum
   `fr`/`en`, extensible), déclarée à la création du flux ou détectée automatiquement à la première
   extraction ; `Article` hérite de cette langue (ou la détecte lui-même si un flux agrège plusieurs
   langues).
2. **Traitement de bout en bout du contenu en anglais** : le pipeline de classification (§10) doit
   appliquer, par langue, le bon jeu de normalisation Unicode, de stopwords, de racinisation/
   stemming et de reconnaissance d'entités nommées — voir la mise à jour du pipeline en §10.1.9 et
   §10.2. Chaque `Taxonomy` est rattachée à une langue : un mot-clé anglais et son équivalent
   français sont deux entités distinctes (pas de traduction automatique des taxonomies dans le
   périmètre initial).
3. **Interface bilingue FR/EN** : traduction des gabarits/templates et des messages via le
   composant Symfony Translation, routing localisé (ex. préfixe `/fr/...` / `/en/...` ou négociation
   de langue), et un sélecteur de langue pour le visiteur. Les pages listant des articles doivent
   pouvoir être filtrées par langue de contenu (un visiteur anglophone ne doit pas voir uniquement
   des titres français non traduits).

Ce chantier est transverse : il touche le modèle de données (§5), le moteur de classification
(§10), le back-office de validation (§4.4/§10.4, qui doit permettre de filtrer les suggestions par
langue) et les pages publiques (§11.2, phase 6).

---

## 4. Analyse détaillée du système de détection de mots-clés (état actuel)

C'est le point central de la demande d'amélioration structurelle. Le mécanisme est implémenté
intégralement dans `AppBundle\Service\KeywordService`, avec le support de `AppBundle\Utils\Utils`
et l'entité `AppBundle\Entity\Taxonomy`.

### 4.1 Vocabulaire du domaine

| Terme code | Sens métier |
|---|---|
| **Taxonomy** | Un *mot candidat* extrait du titre d'un article. Toutes les `Taxonomy` d'un article forment son "sac de mots". |
| **Keyword** (`Article::$keywords`) | Sous-ensemble des `Taxonomy` d'un article **retenu comme mot-clé officiel** après le calcul de fréquence inter-articles. C'est ce qui alimente les pages thématiques, la recherche, le fil "à la Une". |
| **classifyArticles** | Le processus batch qui transforme un lot d'articles bruts en articles classés par mots-clés. |

### 4.2 Pipeline actuel, étape par étape

1. **Normalisation du titre** (`Utils::strip_punctuation` + `Utils::cleanText`) :
   - suppression de la ponctuation via une regex POSIX (`[[:punct:]]`) ;
   - passage en minuscules et **translittération accent par accent** codée en dur
     (chaîne de 54 caractères accentués ↔ 54 caractères non accentués), en s'appuyant sur
     `utf8_decode`/`strtr` — **fonction dépréciée et supprimée en PHP 9**, et qui casse silencieusement
     tout caractère hors Latin-1 (ex. certains caractères translittérés de langues locales
     africaines, guillemets typographiques, tirets longs, emoji dans les titres) ;
   - remplacement de quelques signes d'écriture (`?`, `!`, `.`, `,`, `:`, `'`, `&`→`et`, parenthèses,
     crochets) par des espaces — **liste non exhaustive** (tirets, guillemets « », points de
     suspension `…`, apostrophes typographiques `’` ne sont pas couverts).
2. **Découpage en mots** (`str_word_count($content, 1)`) : fonction PHP historique, **peu fiable
   en UTF-8** (elle a été conçue pour l'ASCII ; son comportement sur les caractères multi-octets
   dépend de la locale du serveur).
3. **Filtrage par longueur minimale** : seuls les mots de **4 caractères ou plus** sont conservés
   (`KeywordService::MIN_WORD_LENGTH`). Aucune liste de mots vides ("stopwords") n'existe : des
   mots grammaticaux français comme *"dans"*, *"pour"*, *"avec"*, *"cette"*, *"leur"*, *"sont"*,
   *"plus"* sont traités comme des candidats mots-clés au même titre que des noms propres ou
   entités nommées.
4. **Comptage d'occurrence *intra-titre*** (`countWords`) : nombre de fois qu'un mot apparaît
   dans le titre d'un seul article (généralement 1, les titres étant courts).
5. **Création de `Taxonomy`** (`extractTaxonomiesFromArticles`) : chaque mot dont l'occurrence
   *dans le titre* est ≤ `MAX_TAXONOMY_OCCURRENCE` (= 2) est enregistré/retrouvé comme `Taxonomy`
   et rattaché à l'article. Le commentaire du code indique une intention de *"ne pas enregistrer
   des mots trop communs"*, mais le critère (répétition dans un même titre court) ne mesure en
   réalité **pas** la fréquence générale du mot dans le corpus — un mot très commun ("aujourd'hui",
   "gouvernement"...) n'apparaissant qu'une fois dans un titre passera ce filtre.
6. **Sélection des mots-clés retenus** (`classifyArticles`) : sur le lot d'articles traité
   (typiquement les 12 dernières heures), on compte combien d'articles **distincts** partagent
   chaque `Taxonomy` (`array_count_values` sur les identifiants). Si une taxonomie apparaît dans
   **au moins 5 articles** (`MIN_KEYWORD_OCCURRENCE`) du même lot, elle devient un `keyword` officiel,
   rattaché à chacun de ces articles (`Article::addKeyword`), à condition que la `Taxonomy` soit
   `isActive()` (un administrateur peut désactiver un mot).
7. **Aucune pondération, aucun score** : un mot-clé est binaire (retenu / non retenu) ; il n'existe
   pas de notion de pertinence relative entre deux mots-clés d'un même article (le champ `mark`
   sur `Taxonomy` existe mais **n'est jamais utilisé** dans la logique de sélection — dette identifiée).
8. **Effet de bord ordonnancement** : `getKeywordsCount()` compte les occurrences des mots-clés
   sur les *derniers articles* pour proposer un "trending" de mots-clés (page d'accueil, sélecteurs
   admin), en recomptant à chaque appel plutôt qu'en s'appuyant sur un score stocké.

### 4.3 Limites structurelles constatées

| # | Constat | Impact métier |
|---|---|---|
| L1 | Extraction basée **uniquement sur le titre** de l'article, jamais sur la description/le contenu. | Beaucoup de signal perdu ; un mot-clé pertinent uniquement présent dans le corps de l'article n'est jamais détecté. |
| L2 | Aucune liste de mots vides (stopwords) français. | Bruit important : des mots grammaticaux polluent les taxonomies et peuvent, à volume suffisant, devenir eux-mêmes des "mots-clés" retenus. |
| L3 | Pas de lemmatisation/racinisation (stemming). | *"élection"*, *"élections"*, *"électorale"* sont trois taxonomies distinctes au lieu d'être regroupées, ce qui dilue la fréquence réelle d'un même sujet et fragmente les pages thématiques. |
| L4 | Translittération d'accents artisanale, basée sur `utf8_decode` (Latin-1 uniquement). | Casse sur tout caractère hors Europe de l'Ouest ; fonction **supprimée en PHP 9**, bloquant de fait toute montée de version PHP au-delà de la 8.x sans réécriture. |
| L5 | Pas de reconnaissance d'entités nommées (personnes, lieux, organisations). | Impossible de distinguer un nom de pays/personnalité politique (signal fort) d'un mot commun de même longueur. |
| L6 | Seuils figés en constantes de classe (`MIN_KEYWORD_OCCURRENCE = 5`, `MAX_TAXONOMY_OCCURRENCE = 2`, `MIN_WORD_LENGTH = 4`), non configurables, non ajustables par flux ou par langue. | Pas d'adaptation possible selon le volume d'articles ingérés (un seuil de 5 articles co-occurrents est trop élevé en heures creuses, trop faible en pic d'actualité). |
| L7 | La sélection ne s'opère que sur le **lot d'articles passé en paramètre** (fenêtre glissante des commandes CLI), sans mémoire du corpus global. | Une même actualité qui déborde sur deux fenêtres d'exécution consécutives peut ne jamais atteindre le seuil de 5. |
| L8 | Pas de gestion du **multilinguisme** (le site agrège potentiellement des flux non francophones : anglais, portugais, arabe translittéré) alors que le nettoyage de texte est franco-centré. | Mauvaise classification, voire échec silencieux, des articles issus de médias non francophones. |
| L9 | Pas de tests automatisés sur `KeywordService` (aucun test unitaire dans `tests/`). | Toute modification de l'algorithme est risquée : aucune garantie de non-régression. |
| L10 | Traitement synchrone dans la commande CLI, sans file d'attente ni parallélisation. | Le classement de gros volumes d'articles peut bloquer/ralentir le cron, avec un risque de recouvrement entre deux exécutions rapprochées. |
| L11 | Le champ `Taxonomy::$mark` (note/pondération) est modélisé mais jamais lu par la logique de classement. | Fonctionnalité de pondération manuelle inaboutie — soit à terminer, soit à retirer du modèle. |

### 4.4 Circuit de validation éditoriale des mots-clés (suggestion → validation)

**Précision apportée par le porteur produit :** la détection de mots-clés ne doit plus rendre un
terme immédiatement utilisable pour classer et taguer les articles. Le fonctionnement cible est le
suivant :

1. Le pipeline de détection (§4.2) continue de repérer des mots candidats dans les articles, mais
   ceux-ci sont désormais enregistrés comme de simples **suggestions**, non visibles côté public.
2. Un administrateur passe en revue ces suggestions dans le back-office (EasyAdmin, §3.12) et les
   **valide** ou les **rejette**, individuellement ou en masse.
3. **Ce n'est qu'une fois validé** qu'un mot-clé devient un terme utilisable pour classer et
   étiqueter (*tagger*) les articles : alimentation des pages `/tag/{label}/`, de la recherche,
   des widgets "mots-clés tendances", des "Unes" utilisateur, des flux RSS de sortie et des
   newsletters.
4. Un mot **rejeté** ne doit plus être re-proposé automatiquement s'il est détecté à nouveau
   (liste d'exclusion vivante), afin d'éviter de faire revalider indéfiniment les mêmes faux
   positifs (mots vides résiduels, bruit récurrent).

**Écart avec l'implémentation actuelle du dépôt :** le champ `Taxonomy::$status` existant est
aujourd'hui **binaire** (`STATUS_ACTIVE` / `STATUS_INACTIVE`) et surtout **actif par défaut** à la
création (`protected $status = self::STATUS_ACTIVE;`). `KeywordService::classifyArticles` ne
vérifie que `isActive()` avant de rattacher un mot-clé à un article — il n'existe donc **aucun état
"en attente de validation"** dans le code actuel : un mot nouvellement détecté est immédiatement
utilisable comme mot-clé, sauf désactivation manuelle a posteriori par un administrateur. Le
processus métier cible (suggestion bloquante par défaut) est donc **à implémenter** lors de la
refonte : voir la conception technique proposée en §10.4.

Cette analyse fonde directement les recommandations de la **section 6**.

---

## 5. Modèle de données (vue d'ensemble)

| Entité | Rôle | Relations clés |
|---|---|---|
| `Feed` | Source RSS/Atom (cible : porte un champ **`language`**, voir §3bis) | 1—N `Article` |
| `Article` | Contenu agrégé (cible : hérite/porte une **langue**, relation native vers `Country`) | N—N `Taxonomy` (sac de mots), N—N `Taxonomy` (`keywords`, sous-ensemble retenu), N—1 `Feed`, cible : N—N `Country` (§3.13) |
| `Taxonomy` | Mot candidat / mot-clé (cible : statut `SUGGESTED`/`VALIDATED`/`REJECTED` et **langue**, voir §4.4, §10.4, §3bis) | N—N `Article` |
| `UserNews` | Liste de veille thématique ("Une") | N—1 `User`, N—N `Taxonomy`, N—N `Publication` |
| `Publication` | Encart/bandeau | N—N `UserNews` |
| `User` (étend `FOSUser`) | Compte utilisateur (inscription non obligatoire pour consulter le site) | 1—N `UserNews`, connecteurs sociaux (Facebook) |
| `Country` | Référentiel pays (cible : rattaché nativement à `Article`, fonctionnalité confirmée, cf. §3.13) | N—N `Article` (cible) |
| `WeeklyNewsletter` | Newsletter hebdomadaire (~40 destinataires actuels ; la newsletter quotidienne est abandonnée, §3.7) | N—N `User` **ou** N—N d'un modèle d'abonné dédié (à trancher, §12) |
| `Cache` | Cache applicatif générique | — |

Points de dette sur le modèle : mapping par annotations PHPDoc (à migrer vers les attributs PHP 8
`#[ORM\...]` lors de la refonte), usage du type Doctrine `json_array` (déprécié, à remplacer par
`json`), absence de timestamps (`createdAt`/`updatedAt`) sur `Article`, `Feed`, `Taxonomy`, et
absence d'un véritable statut de validation sur `Taxonomy` (aujourd'hui binaire actif/inactif,
actif par défaut) — voir §4.4 et §10.4 pour le modèle cible à trois états. À ces points s'ajoutent,
suite aux clarifications produit, l'absence de notion de **langue** sur `Feed`/`Article`/`Taxonomy`
(§3bis) et l'absence de rattachement direct `Article ↔ Country` (§3.13).

---

## 6. Traitements automatisés (commandes CLI)

**Réponse du porteur produit :** l'extraction et la classification **tournent en continu** en
production (et non selon une fenêtre cron périodique classique) — voir mise à jour de la table et
recommandation ci-dessous.

| Commande | Rôle | Fréquence réelle |
|---|---|---|
| `app:articles:extract` | Extraction des articles des flux actifs, puis enchaîne sur la classification | **Continue** (boucle continue en production) |
| `app:articles:classify` | Classification/attribution des mots-clés sur les N dernières heures | **Continue** (idem) |
| `app:articles:share` | Sélection d'un article à partager sur les réseaux sociaux | Fonctionnalité différée (§3.8) — fréquence cible à définir lors de sa réactivation |
| `app:articles:daily` | Envoi d'un résumé quotidien par email (SendinBlue) | **Abandonnée** (§3.7), non reprise dans la refonte |
| `app:country:fill` | Peuplement du référentiel pays depuis un YAML statique | Ponctuelle / à l'installation |

⚠️ **Constat technique** : le bundle `cron/cron-bundle` est déclaré dans le kernel, mais **aucune
définition de job cron n'existe dans le dépôt** (ni configuration YAML, ni annotations dédiées
trouvées). Le mécanisme exact du bouclage continu (script shell en boucle, superviseur type
systemd/Supervisor, cron à cadence très rapprochée, etc.) n'est pas versionné et reste à
documenter précisément côté infrastructure. Par ailleurs, l'exécution continue sans verrou
apparent dans le code expose à un **risque de chevauchement** entre deux cycles si l'un dépasse la
durée de l'intervalle (voir §8, point 12).

**Recommandation pour la cible** : modéliser l'extraction et la classification comme des
**workers Messenger persistants** (consommateurs de file d'attente supervisés, ex. via systemd ou
Supervisor), plutôt que comme des commandes périodiques — c'est l'architecture naturelle pour un
traitement conceptuellement continu/quasi temps réel. Réserver `symfony/scheduler` aux tâches
réellement périodiques (peuplement des pays, envoi de la newsletter hebdomadaire, purge de cache).

---

## 7. Intégrations externes

| Service | Usage | Sensibilité |
|---|---|---|
| Facebook (OAuth + Graph via `social-post-bundle`) | Connexion utilisateur + publication automatique d'articles | Clés en paramètres (`facebook_app_id`, etc.) |
| Twitter (API v1.1 via `abraham/twitteroauth`) | Publication automatique d'articles avec image | Clés en paramètres |
| SendinBlue (Brevo) | Envoi de newsletters transactionnelles | Clé API **en clair dans `config.yml`** (`sendin_blue_api.api_key`) — à corriger immédiatement, indépendamment de la refonte (voir §8.5) |
| Flux RSS tiers | Sources de contenu : une trentaine de flux actifs à ce jour, tous francophones ; extension prévue aux flux anglophones (§3bis) | — |

Le partage automatique sur Facebook/Twitter, bien qu'intégré dans le code actuel, est une
fonctionnalité que le porteur produit a choisi de **différer** (§3.8) : elle n'est pas à développer
dans le lot initial de la refonte, seules les interfaces d'extension sont à prévoir.

---

## 8. Constats de dette technique et risques (au-delà des mots-clés)

1. **Symfony 3.3** : fin de support depuis janvier 2018 (aucun correctif de sécurité depuis).
   C'est un facteur de risque de sécurité direct pour une application exposée publiquement.
2. **PHP `>=7.3` sans plafond** dans `composer.json`, mais usage de fonctions dépréciées/supprimées
   (`utf8_decode`, `ContainerAwareCommand`, `Controller` legacy `$this->get()`) qui **empêchent
   de facto** l'exécution sur PHP 8.x récent ou 9 sans réécriture.
3. **Deux à trois systèmes d'administration en parallèle** (AdminController maison, EasyAdmin,
   Sonata déclaré mais non branché) : confusion de maintenance, surface d'attaque inutile.
4. **Sécurité** :
   - clé API SendinBlue en clair dans un fichier versionné (`app/config/config.yml`) ;
   - recherche/filtre construits par concaténation de `LIKE` (pas d'injection SQL grâce à Doctrine
     QueryBuilder, mais aucune limite de complexité de requête, risque de déni de service applicatif
     sur une recherche à très nombreux termes) ;
   - absence de rate-limiting sur les endpoints AJAX publics (`/api/extract/article/`,
     `/_recherche`, etc.).
5. **Un seul test automatisé**, obsolète (teste la page par défaut du squelette Symfony, pas
   l'application réelle) : couverture de test **quasi nulle**, en particulier sur le cœur
   métier (`KeywordService`, `ArticleService`).
6. **Absence de couche "domaine" isolée** : la logique métier est directement dans des services
   qui dépendent de `Doctrine\ORM\EntityManager` et retournent parfois des entités Doctrine
   directement aux contrôleurs/vues (pas de DTO), ce qui complique les tests unitaires et le
   découplage.
7. **Gestion d'erreurs par `echo`** dans les services et les commandes (`FeedService`,
   `ArticleService`, `CachedDataService`) plutôt que par logger (Monolog est pourtant configuré)
   ou exceptions typées — aucune observabilité structurée des échecs d'extraction/classification.
8. **Cache applicatif maison** (table `Cache`, sérialisation PHP native) au lieu du composant
   Cache standard de Symfony (PSR-6/PSR-16), ce qui empêche l'usage de backends performants
   (Redis/Memcached) sans réécriture.
9. **Appels HTTP en cURL brut dupliqués** à plusieurs endroits (`Utils::file_get_contents`,
   `CachedDataService::get/post/put`) plutôt qu'un client HTTP standard (`symfony/http-client`),
   sans gestion de timeout homogène, ni retries, ni tests.
10. **Un seul user-agent codé en dur** pour tous les appels de scraping
    (`Utils::file_get_contents`), sans rotation ni gestion des blocages par les sites sources —
    cf. §3.2 et §9.4 pour la cible.
11. **Absence de circuit de validation** pour les mots-clés détectés automatiquement : le statut
    `Taxonomy::$status` est actif par défaut, ce qui ne correspond pas au processus métier cible
    de suggestion/validation (§4.4) — écart à corriger prioritairement dans la refonte (§10.4).
12. **Risque de chevauchement d'exécutions** : le porteur produit a confirmé que l'extraction et
    la classification tournent **en continu** en production (§6), mais aucun verrou d'exécution
    (`symfony/lock` ou équivalent) n'est visible dans le code — si un cycle dépasse la durée
    séparant deux lancements, un chevauchement pourrait entraîner des traitements en double ou des
    états incohérents. À sécuriser explicitement dans la refonte.

---

## 9. Recommandations pour la refonte Symfony

### 9.1 Cible technique proposée

| Aspect | Recommandation |
|---|---|
| Symfony | Dernière version stable **LTS** au moment du lancement du projet (à ce jour Symfony 6.4 LTS, avec trajectoire vers 7.x dès que l'écosystème de dépendances le permet) |
| PHP | 8.2 minimum (8.3+ recommandé) |
| Mapping Doctrine | Attributs PHP 8 (`#[ORM\Entity]`, etc.) plutôt qu'annotations PHPDoc |
| Configuration | YAML/PHP `config/packages/*.yaml` (structure Symfony Flex), suppression du modèle `app/`/`web/` legacy au profit de `public/` |
| Contrôleurs | Injection de dépendances explicite (constructeur ou `#[Autowire]`) au lieu de `$this->get()` / `ContainerAwareCommand` |
| Cache | Composant Symfony Cache (PSR-6) avec adaptateur Redis en production |
| HTTP client | `symfony/http-client` en remplacement des appels cURL manuels |
| Admin | Consolider sur **EasyAdminBundle v4** comme back-office unique (confirmé par le porteur produit, §3.12) ; retirer Sonata (non utilisé) et l'`AdminController` maison |
| Traitement asynchrone | `symfony/messenger`, avec des **workers persistants** pour l'extraction et la classification (traitements continus confirmés, §6), et des messages ponctuels pour le crawling (§9.4) et les autres traitements |
| Ordonnancement | `symfony/scheduler` réservé aux tâches réellement périodiques (peuplement des pays, newsletter hebdomadaire) ; l'extraction/classification passent par des workers Messenger continus plutôt que par un ordonnanceur classique (§6) |
| Internationalisation | Composant Symfony **Translation** + routage localisé (`/fr/...`, `/en/...` ou négociation de langue) pour l'interface ; champ `language` sur `Feed`/`Article`/`Taxonomy` et pipeline de classification bilingue FR/EN dès le départ (§3bis, §10.1.9) |
| Verrouillage | `symfony/lock` pour sécuriser les workers continus contre tout chevauchement d'exécution (§8, point 12) |
| Tests | Mise en place de `PHPUnit` + `symfony/test-pack`, avec une cible de couverture prioritaire sur `KeywordService`, `ArticleService`, `FeedService` (cœur métier) |
| Recherche | Étudier un moteur de recherche dédié (Meilisearch, Elasticsearch/OpenSearch) en remplacement des `LIKE` Doctrine, en cohérence avec la détection de mots-clés et la consultation d'archives par pays (§3.13) |

### 9.2 Découpage modulaire proposé

Remplacer le bundle unique `AppBundle` par une organisation par domaine (sans nécessairement
recréer des bundles Symfony, la notion de bundle applicatif étant dépréciée dans Symfony
récent) :

```
src/
  Feed/            (Entity, Repository, Service : gestion des flux, multilingues)
  Article/         (Entity, Repository, Service : agrégation, publication)
  Classification/  (Service : nouveau moteur de mots-clés bilingue FR/EN, voir §10)
  Taxonomy/         (Entity, Repository : mots-clés, avec statut et langue)
  Geography/        (Entity, Repository, Service : pays, croisement pays × mot-clé, archives, §3.13)
  News/             (Entity, Repository, Service : "Unes" utilisateur)
  Newsletter/       (Entity, Repository, Service : envoi hebdomadaire, gestion des abonnés)
  Social/           (Service : Facebook, Twitter — implémentation différée, voir §3.8)
  User/             (Entity, sécurité)
  Shared/           (Utils transverses, VO, DTO, gestion des langues/i18n)
```

### 9.3 Stratégie de migration (approche recommandée)

Compte tenu de l'écart de version (3.3 → 6.4/7.x, saut de plusieurs versions majeures) et de la
taille contenue du code (~6 900 lignes), une **réécriture progressive guidée par les domaines
fonctionnels** est plus sûre qu'une migration incrémentale version par version (3.4 → 4 → 5 → 6),
qui imposerait de maintenir un temps des bundles aujourd'hui obsolètes/abandonnés
(FOSUserBundle historique, HWIOAuthBundle, EasyAdmin 1.x, `martin-georgiev/social-post-bundle`...).

Proposition de phases (le détail chiffré est repris et affiné en §11) :

1. **Cadrage & specs** (ce document, incluant les réponses du porteur produit compilées en §12).
2. **Socle technique neuf** : nouveau projet Symfony (LTS), CI/CD, structure de dossiers cible,
   fondations d'internationalisation (§3bis), authentification (remplacement de FOSUser par le
   composant Security natif + un provider social maintenu), squelette de tests.
3. **Migration du modèle de données** : nouvelles entités (dont langue et statut de validation sur
   `Taxonomy`, rattachement natif `Article ↔ Country`), script de migration des données (Doctrine
   Migrations) depuis la base MySQL existante — **sans migration de code**, uniquement les données.
4. **Réécriture du moteur de classification**, bilingue FR/EN dès le départ (voir section 10) — à
   traiter en priorité car c'est la fonctionnalité différenciante et la plus fragile du système
   actuel.
5. **Réécriture des modules par ordre de valeur métier** : Flux/Articles (multilingues) →
   Taxonomies/mots-clés (branché sur le nouveau moteur) → Unes utilisateurs → Pays (croisement
   mot-clé + archives) → Recherche → Admin → Newsletter hebdomadaire → SEO/Sitemap. *Le partage
   réseaux sociaux est traité séparément, en phase ultérieure (§3.8, §11.2 phase 9).*
6. **Bascule** : double-run (ancien + nouveau système) sur l'extraction/classification (qui
   tournent en continu, §6) pendant une période de validation, puis bascule DNS/déploiement.

### 9.4 Architecture cible du crawling multi-bots (nouvelle fonctionnalité)

Pour répondre au besoin d'un crawling plus sophistiqué (§3.2), la refonte doit introduire un
sous-système dédié, découplé de l'extraction RSS elle-même :

```
Crawler/
  BotProfile                (VO : nom, user-agent, en-têtes HTTP additionnels, délai d'attente)
  BotProfileRegistry         (pool configurable de profils de bots, chargé depuis la configuration
                             ou le back-office)
  RobotsTxtChecker           (respect des règles d'exclusion du site source)
  RateLimiter                 (throttling par domaine, via symfony/rate-limiter)
  MetaExtractorInterface      (extraction Open Graph / Twitter Cards / balises <title>,<meta description>)
  CrawlerService              (orchestration : essaie successivement les profils du pool jusqu'à
                             obtenir un résultat exploitable ou épuisement du pool)
  Message/CrawlArticleMetaMessage + Handler   (déclenchement asynchrone via Messenger, uniquement
                             quand le flux RSS n'a pas fourni une métadonnée)
```

Principes de conception :

- **Déclenchement conditionnel** : le crawler n'intervient que si le flux RSS ne fournit pas déjà
  le titre, l'image ou la description — jamais en remplacement systématique du flux.
- **Repli en cascade entre profils de bots** : si un profil échoue (timeout, HTTP 403/429, page
  vide), le système retente automatiquement avec un autre profil (user-agent différent) avant
  d'abandonner et de journaliser l'échec.
- **Respect du web source** : lecture et application de `robots.txt`, throttling par domaine,
  user-agents identifiables (pas d'usurpation trompeuse), pour limiter le risque de blocage IP et
  rester conforme aux conditions d'usage des sites partenaires.
- **Traitement asynchrone** (Messenger) pour ne jamais bloquer la commande d'extraction RSS
  principale sur un site source lent ou indisponible.
- **Mise en cache par URL** des résultats de crawl (composant Cache Symfony, PSR-6), pour éviter de
  re-crawler une page déjà traitée récemment — remplace l'usage actuel, ad hoc, de
  `CachedDataService`.
- **Observabilité** : taux de succès par bot et par domaine, alerte si un domaine bloque
  systématiquement tous les profils (signal pour ajuster la stratégie ou contacter l'éditeur du
  flux).
- **Throttling agrégé par domaine** : le porteur produit confirme qu'en général un flux RSS
  correspond à un domaine, sauf pour certains grands médias qui exposent plusieurs flux distincts
  sur le même domaine. Le `RateLimiter` doit donc throttler **par domaine** et non par flux, pour
  éviter qu'un média multi-flux ne reçoive mécaniquement davantage de requêtes de crawl que prévu.

---

## 10. Recommandations structurelles pour la détection de mots-clés

Objectif : remplacer l'algorithme actuel (comptage brut sur le titre seul, sans stopwords ni
normalisation robuste) par un **pipeline de classification configurable, testable et évolutif**.

### 10.1 Nouveau pipeline proposé

1. **Ingestion de texte élargie** : combiner titre (poids fort) + description (poids plus faible)
   au lieu du titre seul (traite L1).
2. **Normalisation Unicode correcte** : `mb_strtolower` + normalisation `Normalizer::normalize`
   (extension `intl`, forme NFKD) pour la suppression d'accents, en remplacement de la table de
   translittération Latin-1 codée en dur (traite L4). Compatible tout alphabet latin étendu.
3. **Tokenisation robuste** : `preg_split` sur les classes Unicode plutôt que `str_word_count`,
   ou usage d'une librairie de traitement de texte dédiée (ex. `voku/stop-words`, `wamania/php-stemmer`,
   ou une intégration légère d'un service NLP) (traite L1/L4).
4. **Filtrage par liste de mots vides (stopwords) multilingue et configurable**, chargée par
   langue détectée (ex. via `voku/stop-words` ou une table `stopword` en base éditable depuis
   le back-office) (traite L2, prépare L8).
5. **Racinisation / regroupement de variantes** (stemming léger ou lemmatisation français) pour
   regrouper singulier/pluriel et variantes grammaticales d'un même terme avant comptage
   (traite L3).
6. **Détection d'entités nommées** (au minimum un référentiel de noms propres : pays, capitales,
   chefs d'État, organisations panafricaines, alimenté et maintenu depuis le back-office) pour
   **pondérer plus fort** les entités par rapport aux mots communs (traite L5).
7. **Scoring pondéré au lieu d'un seuil binaire** : calculer un score de type **TF-IDF** ou
   fréquence relative sur une fenêtre glissante du corpus complet (pas seulement le lot traité
   par l'exécution CLI en cours), avec exploitation effective du champ `mark`/pondération
   éditoriale existant mais aujourd'hui inutilisé (traite L6, L7, L11).
8. **Seuils et fenêtres configurables** (via configuration ou back-office), avec valeurs par
   défaut ajustées automatiquement selon le volume d'articles ingérés sur la période, plutôt que
   des constantes de classe figées (traite L6).
9. **Détection de langue** en amont du pipeline (ex. `patrickschur/language-detection`), pour
   appliquer le bon jeu de stopwords/racinisation par flux/article plutôt qu'un traitement
   franco-centré unique (traite L8). **Devient un prérequis ferme et non plus une simple option**
   de conception : le porteur produit a confirmé l'ajout prochain de flux en anglais et un
   traitement de l'anglais de bout en bout (§3bis) — le pipeline doit donc être **bilingue FR/EN
   dès la refonte**, avec une architecture permettant d'ajouter d'autres langues par la suite.
10. **Traitement asynchrone via Messenger** : chaque article ingéré déclenche un message de
    classification consommé par un worker dédié, avec réessai en cas d'échec et métriques
    (nombre d'articles classés, temps de traitement, taux de mots-clés retenus) exposées en
    observabilité (traite L10).
11. **Couverture de tests** : suite de tests unitaires sur le pipeline de normalisation/tokenisation
    et sur la logique de scoring, avec un corpus de titres réels (anonymisés) en fixtures, plus des
    tests de non-régression avant/après chaque évolution de seuils (traite L9).

### 10.2 Architecture cible du module de classification

```
Classification/
  Pipeline/
    NormalizerInterface        (nettoyage + translittération Unicode)
    TokenizerInterface         (découpage en tokens)
    StopWordFilterInterface    (filtrage par langue)
    StemmerInterface           (racinisation, optionnel/activable)
    NamedEntityRecognizer      (référentiel entités fortes)
    ScorerInterface            (TF-IDF / fréquence pondérée)
  ClassificationService        (orchestration du pipeline, remplace KeywordService)
  Message/ClassifyArticleMessage + Handler   (traitement asynchrone via Messenger)
  Config/keyword_classification.yaml         (seuils, poids, langues supportées)
```

Cette architecture en pipeline injectable (chaque étape derrière une interface) permet de
**faire évoluer indépendamment** chaque brique (par ex. remplacer le stemmer par un service NLP
externe plus tard, ou ajouter une langue) sans réécrire l'orchestration, et de **tester chaque
étape isolément**.

Chaque `Taxonomy` est **rattachée à une langue** (`fr`/`en` au lancement, extensible) : les
pipelines FR et EN produisent des suggestions indépendantes, avec leurs propres stopwords,
stemming et référentiels d'entités nommées. Les pages publiques, la recherche et le back-office de
validation (§10.4) doivent respecter ce cloisonnement par langue (§3bis).

### 10.3 Compatibilité avec l'existant

- Conserver le concept `Taxonomy` (mot/entité) et `Article::keywords` (sous-ensemble retenu)
  côté modèle de données pour limiter l'impact sur les pages publiques (pages `/tag/{label}/`,
  recherche, "Unes"), en migrant simplement le **calcul** qui les alimente.
- Prévoir un script de **ré-indexation** (re-classification) du corpus existant avec le nouveau
  pipeline lors de la bascule, avec comparaison avant/après pour validation éditoriale.

### 10.4 Intégration du circuit de suggestion/validation dans le pipeline (§4.4)

Le nouveau moteur de classification (§10.1-10.2) doit être conçu pour **ne jamais rendre un
mot-clé utilisable automatiquement** :

- Le modèle de données introduit un statut à plusieurs états sur `Taxonomy` :
  `SUGGESTED` (valeur par défaut à la création par le pipeline) / `VALIDATED` / `REJECTED`
  (et, optionnellement, `ARCHIVED` pour désactiver ultérieurement un mot déjà validé).
- `ClassificationService` (successeur de `KeywordService`) ne produit et ne rattache que des
  taxonomies au statut `SUGGESTED` — jamais directement de `keywords` exploitables publiquement.
- Un nouvel écran d'administration dans EasyAdmin (§3.12, §11 phase 3) liste les suggestions en
  attente (filtrables par volume d'occurrences, date, flux d'origine) avec des actions rapides et
  en masse "Valider" / "Rejeter".
- À la validation d'un mot par un administrateur, un job de **réconciliation** rattache
  rétroactivement ce mot-clé aux articles déjà associés à la suggestion correspondante (pour ne
  pas perdre le travail d'analyse déjà effectué par le pipeline).
- Un mot `REJECTED` est mémorisé pour ne plus être re-suggéré automatiquement (liste d'exclusion
  vivante, complémentaire de la liste de stopwords générique du §10.1.4).
- Traçabilité : historiser qui a validé/rejeté chaque terme et quand (audit), utile en cas de
  contestation éditoriale.
- Seules les taxonomies `VALIDATED` doivent être lues par les pages publiques, la recherche, les
  flux RSS de sortie et les newsletters — voir le jalon critique en §11.3.

---

## 11. Plan de refonte détaillé

Ce plan formalise la stratégie de migration introduite en §9.3 en tenant compte des trois
évolutions apportées à ce document : back-office unifié sous EasyAdmin, circuit de
suggestion/validation des mots-clés, et système de crawling multi-bots pour l'enrichissement des
métadonnées.

### 11.1 Principes directeurs

- Réécriture progressive guidée par les domaines (cf. §9.3), pas de migration version-par-version.
- Chaque phase livre un périmètre fonctionnel testable et démontrable (pas de "big bang" en fin
  de projet).
- Le moteur de classification (phase 4) et le back-office de validation (phase 3) sont livrés
  ensemble : l'un ne sert à rien sans l'autre.
- Le bilinguisme FR/EN est **construit dès la phase 4** (pipeline de classification) et la phase 1
  (fondations i18n), pas ajouté après coup (§3bis).
- Le partage réseaux sociaux et la newsletter quotidienne sont **hors périmètre du lot initial**
  (§3.7, §3.8) : aucune phase ne leur est consacrée avant la bascule.
- Double-run (ancien/nouveau système) sur l'extraction et la classification — qui tournent en
  continu (§6) — avant toute bascule définitive.

### 11.2 Phasage

| Phase | Objectif | Livrables clés | Dépend de |
|---|---|---|---|
| 0. Cadrage | Périmètre figé à partir des réponses du porteur produit (§12) | Spécification validée, backlog priorisé | — |
| 1. Socle technique | Nouveau projet Symfony LTS/PHP 8.2+, CI/CD, sécurité de base, structure par domaine (§9.2), fondations i18n (Translation, routage par locale) | Squelette applicatif déployable, pipeline CI (lint, tests, analyse statique) | Phase 0 |
| 2. Modèle de données cible | Nouvelles entités (attributs PHP 8) : `Taxonomy` à trois états **et** rattachée à une langue (§4.4/§10.4, §3bis), `Feed`/`Article` avec champ `language`, relation native `Article ↔ Country` (§3.13), modèle d'abonné newsletter (§3.7) ; script de migration des données existantes | Schéma + migrations Doctrine, script d'import des données historiques (mots-clés déjà actifs repris comme pré-validés, §11.4) | Phase 1 |
| 3. Back-office unifié (EasyAdmin) | Back-office EasyAdmin v4 couvrant flux (multilingues), mots-clés (écran de validation filtrable par langue), utilisateurs, publications, newsletter, pays — suppression de l'`AdminController` maison et de la dépendance Sonata (§3.12) | CRUD EasyAdmin complet, écran "Suggestions de mots-clés" avec validation/rejet en masse **en temps réel** (§12) | Phase 2 |
| 4. Moteur de classification bilingue FR/EN | Pipeline de normalisation/stopwords/stemming/scoring (§10.1-10.2), **bilingue dès le départ** (détection de langue, stopwords/stemming/entités nommées par langue), produisant des suggestions consommées par la phase 3 ; les pays sont détectés comme entités nommées et rattachés nativement aux articles (§3.13) | `ClassificationService`, tests unitaires, fixtures FR **et** EN | Phases 2 et 3 |
| 5. Système de crawling multi-bots | Extraction de métadonnées en repli du flux RSS, pool de bots/user-agents, throttling **agrégé par domaine** (§9.4) | `CrawlerService`, `BotProfileRegistry`, file d'attente asynchrone, tableau de bord par domaine | Phase 1 |
| 6. Modules front (bilingues) | Réécriture des "Unes" utilisateur, de la page **"actualité par pays"** (croisement pays × mot-clé + archives paginées, §3.13), de la recherche et du SEO/sitemap ; interface publique bilingue FR/EN avec sélecteur de langue | Pages publiques fonctionnelles sur le nouveau socle | Phases 3, 4 |
| 7. Newsletter hebdomadaire | Réécriture de l'envoi hebdomadaire (SendinBlue) sur le corpus de mots-clés validés, avec gestion d'une liste d'abonnés dédiée (~40 destinataires actuels, §3.7) — la newsletter quotidienne **n'est pas reprise** | Commande/handler Messenger dédié | Phase 6 |
| 8. Double-run & bascule | Exécution en parallèle ancien/nouveau système sur l'extraction/classification continues, comparaison des résultats, puis bascule DNS/déploiement | Rapport de comparaison, plan de bascule, rollback documenté | Phases 4 à 7 |
| 9. *(hors périmètre initial)* Partage réseaux sociaux | Réactivation du partage automatique Facebook/Twitter (§3.8), en phase **ultérieure au lancement** de la refonte | Uniquement l'interface d'extension `Social/` posée en phase 1-2 ; l'intégration effective est traitée plus tard | Phase 8 (post-lancement) |

### 11.3 Jalon critique : le circuit de validation avant tout branchement front

**Recommandation** : ne brancher aucune page publique ni aucun export (recherche, pages tag, page
pays, flux RSS de sortie, newsletter) sur le nouveau système de mots-clés tant que l'écran de
validation (phase 3) et le moteur de suggestion (phase 4) ne sont pas **tous les deux** livrés —
un mot-clé "suggéré" ne doit jamais fuiter côté public, dans aucune des deux langues.

### 11.4 Points de vigilance transverses

- **Reprise des mots-clés existants** : à l'ouverture du nouveau système, considérer les
  `Taxonomy` déjà actives aujourd'hui comme **pré-validées** (statut `VALIDATED` par migration),
  pour ne pas devoir tout revalider manuellement à la bascule ; seuls les nouveaux mots détectés
  après la mise en service repassent par le circuit de suggestion.
- **Formation des administrateurs** à la nouvelle interface de validation avant la bascule ; le
  traitement en temps réel des suggestions (confirmé §12) suppose une interface réactive et,
  potentiellement, une notification des nouvelles suggestions en attente.
- **Dimensionnement du pool de bots** de crawling : une trentaine de flux actifs aujourd'hui, en
  général un flux = un domaine sauf pour quelques grands médias multi-flux (agréger le throttling
  par domaine, §9.4) — et rédaction d'une charte d'usage (respect des CGU des sites sources).
- **Verrouillage des workers continus** (`symfony/lock`) pour l'extraction/classification, afin
  d'éviter tout chevauchement d'exécution (§8, point 12).
- **Nettoyage du périmètre abandonné** : ne pas migrer `DailyNewsCommand` ni les routes associées
  (§3.7, §14) ; ne pas développer l'intégration réseaux sociaux dans le lot initial (§3.8).

---

## 12. Zones à clarifier avec le porteur produit avant de chiffrer la refonte

### 12.1 Questions initiales — réponses obtenues

Les 8 questions initialement posées ont été tranchées par le porteur produit ; les réponses sont
intégrées dans le corps du document (renvois ci-dessous) :

| # | Question | Réponse | Intégrée en |
|---|---|---|---|
| 1 | Fonctionnalité "actualité par pays" : terminer ou retirer ? | **À reprendre**, avec croisement par mot-clé et consultation d'archives | §3.13 |
| 2 | Partage automatique Facebook/Twitter actif ou manuel ? | **Implémentation différée**, phase ultérieure | §3.8, §11.2 phase 9 |
| 3 | Newsletter quotidienne : brouillon ou à généraliser ? | **Abandonnée** pour l'instant | §3.7, §14 |
| 4 | Configuration crontab réelle ? | Extraction et classification **tournent en continu** | §6 |
| 5 | Volumétrie actuelle ? | ~30 flux RSS ; ajout prévu de flux anglophones ; pas d'inscription requise ; ~40 destinataires newsletter | §3bis, §7, §3.7 |
| 6 | Langues des flux ? | 100 % français aujourd'hui ; **gestion multilingue à construire** (flux + traitement + interface) pour la refonte | §3bis |
| 7 | Gouvernance de la validation des mots-clés ? | Tous les administrateurs, **traitement en temps réel** | §4.4, §11.4 |
| 8 | Domaines sources distincts ? | En général un flux = un domaine, sauf quelques grands médias multi-flux | §9.4, §11.4 |

### 12.2 Nouvelles questions ouvertes (issues de ces réponses)

1. **Croisement pays × mot-clé et profondeur d'archives** (§3.13) : le mot-clé est-il optionnel ou
   obligatoire dans le croisement ? Les archives doivent-elles être consultables sans limite de
   temps, ou bornées (ex. 12 ou 24 derniers mois) ?
2. **Nature de la liste de diffusion de la newsletter hebdomadaire** (§3.7) : les ~40 destinataires
   actuels sont-ils des comptes `User` du site, ou une liste d'abonnés à part, à importer/gérer
   indépendamment (cohérent avec un site sans inscription obligatoire) ?
3. **Feuille de route des flux anglophones** (§3bis) : y a-t-il déjà des flux anglais identifiés et
   un volume prévisionnel, pour dimensionner le pipeline bilingue et la volumétrie de contenu EN ?
4. **Portée de la préparation du partage réseaux sociaux** (§3.8) : faut-il, dans le lot initial,
   poser uniquement une interface d'extension (`Social/`), ou également conserver telles quelles
   les intégrations Facebook/Twitter existantes en veille (sans les activer) ?
5. **Mécanisme technique exact du fonctionnement continu actuel** (§6, §8 point 12) : boucle shell,
   superviseur (systemd/Supervisor), ou cron à cadence très rapprochée ? Utile pour dimensionner
   les futurs workers Messenger et sécuriser l'absence de chevauchement d'exécutions.

---

## 13. Annexe — Inventaire des routes principales

| Route (préfixe) | Contrôleur | Fonction |
|---|---|---|
| `/` , `/articles/` | `ArticleController` | Accueil / liste principale |
| `/actu/` | `ArticleController::allArticlesAction` | Liste sans filtre |
| `/source/{feed}/{feed_slug}/` | `ArticleController::articlesByFeedAction` | Liste par flux |
| `/tag/{label}/` | `ArticleController::articlesByKeywordsAction` | Liste par mot-clé |
| `/article/{article}/{article_slug}/` | `ArticleController::singleArticleAction` | Article détaillé |
| `/explore/{article}/` | `ArticleController::singleArticleAction` | Rebond aléatoire sur mots-clés communs |
| `/recherche/{q}`, `/_recherche` | `SearchController` | Recherche |
| `/news/{slug}/{news}/` | `UserNewsController::newsAction` | Page d'une "Une" |
| `/r/create/news/`, `/r/edit/news/{news}/` | `UserNewsController` | Gestion des "Unes" par l'utilisateur |
| `/daily/news/...`, `/global/newsletter/` | `UserNewsController` | Rendus newsletter — les routes liées à la newsletter *quotidienne* (`daily_news`, `day_news`, `yesterday_news`) ne sont **pas reprises** dans la refonte (§3.7) |
| `/feed-afkr/type/{filter}/` | `FeedController` | Flux RSS de sortie |
| `/sitemap.xml` | `SitemapsController` | Sitemap |
| `/admin/flux/...`, `/admin/mot/...`, `/admin/pubs/...`, `/admin/newsletter/...` | `AdminController` | Back-office métier |
| `/admin` (EasyAdmin) | — | Back-office générique (Article/User/Feed) |
| `/api/extract/article/` | `ApiController` | Extraction d'un article par URL (API) |
| `/socialnetworks/...` | HWIOAuthBundle | Connexion Facebook |

## 14. Annexe — Commandes CLI

| Commande | Classe | Statut pour la refonte |
|---|---|---|
| `app:articles:extract` | `ArticlesCommand` | À reprendre — traitement continu, cible : worker Messenger (§6) |
| `app:articles:classify` | `ClassifyArticlesCommand` | À reprendre — traitement continu, remplacé par le pipeline bilingue (§10) |
| `app:articles:share` | `ShareCommand` | Différée — partage réseaux sociaux implémenté ultérieurement (§3.8) |
| `app:articles:daily` | `DailyNewsCommand` | **Abandonnée** — newsletter quotidienne retirée du périmètre (§3.7) |
| `app:country:fill` | `CountryCommand` | À reprendre — alimente aussi le croisement pays × mot-clé (§3.13) |

## 15. Annexe — Glossaire

- **Édito / Une** : dans le code, terme parfois utilisé pour désigner un article ou une page
  thématique (`UserNews`) ; à harmoniser lors de la refonte (le vocabulaire "Une" est utilisé de
  façon interchangeable pour l'entité `UserNews` et pour un article vedette selon les contextes).
- **Taxonomy** : voir §4.1.
- **Keyword** : voir §4.1.
- **Flux (Feed)** : source RSS/Atom d'un média partenaire.
- **Suggestion (mot-clé)** : mot candidat détecté automatiquement par le pipeline de
  classification, en attente de revue par un administrateur (statut cible `SUGGESTED`), non
  utilisable publiquement tant qu'il n'est pas validé (voir §4.4, §10.4).
- **Validation (mot-clé)** : action d'un administrateur qui rend un mot-clé suggéré utilisable
  pour classer et taguer les articles (statut cible `VALIDATED`).
- **Bot / profil de bot (crawling)** : configuration d'identité HTTP (user-agent, en-têtes)
  utilisée par le crawler de secours pour récupérer les métadonnées (titre, image, description)
  d'un article quand le flux RSS ne les fournit pas (voir §3.2, §9.4).
- **Croisement pays × mot-clé** : filtrage combiné des articles d'une page pays par un mot-clé
  validé, avec consultation d'archives (voir §3.13).
- **Traitement continu** : mode de fonctionnement confirmé de l'extraction et de la classification
  en production, par opposition à une exécution périodique classique (voir §6).

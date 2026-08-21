# Prompt — Conception UX/UI dans Claude Design

> Ce document contient un **prompt prêt à l'emploi** pour lancer la conception visuelle (UX + UI)
> du site public et du back-office d'Afrique Actualités dans Claude Design (skill `design`).
> Il synthétise, du point de vue des fonctionnalités *exposées aux utilisateurs*, ce qui est
> documenté en détail dans [`documentation-fonctionnelle.md`](./documentation-fonctionnelle.md)
> (§2 Utilisateurs et rôles, §3 Cartographie fonctionnelle, §3bis Internationalisation, §4.4
> Circuit de validation). Il ne couvre volontairement pas l'architecture technique : c'est un
> brief produit/UX, pas un cahier des charges de développement.
>
> **Usage** : copier la section « Prompt » ci-dessous dans Claude Design, l'adapter si besoin
> (priorités d'écrans, charte graphique), puis lancer la génération.

---

## Prompt

Conçois l'UX et l'UI d'**Afrique Actualités**, un agrégateur d'actualités panafricain qui collecte
des articles depuis des flux RSS de médias partenaires, les classe automatiquement par mots-clés
et les republie sous forme de pages thématiques, de pages pays, de "Unes" personnalisées et d'une
newsletter hebdomadaire. Le produit est en cours de refonte complète ; il n'existe pas de charte
graphique héritée à respecter — propose une identité visuelle éditoriale, moderne, pensée pour un
lectorat africain et sa diaspora, adaptée à un usage majoritairement mobile et à des connexions
parfois lentes (mise en page sobre, poids d'image maîtrisé, hiérarchie de lecture claire sur des
listes denses d'articles).

**Contrainte transverse à respecter sur tous les écrans publics** : l'interface doit être
**bilingue français / anglais**, avec un sélecteur de langue visible et persistant. Le contenu
éditorial (articles, mots-clés) est lui-même par langue — un visiteur anglophone ne doit jamais
voir une liste d'articles uniquement en français sans indication claire de la langue de chaque
contenu.

Conçois les artboards suivants, organisés en deux parcours distincts : le **site public**
(visiteurs et utilisateurs inscrits) et le **back-office** (administrateurs).

### Parcours 1 — Site public

**A. Accueil**
Page d'atterrissage qui doit donner en un coup d'œil : un article vedette/sponsorisé mis en avant
(avec mention "Sponsorisé"), une sélection d'articles récents organisée par mots-clés tendance,
un bloc "actualité par pays" (mosaïque de pays avec un article représentatif chacun), un bandeau
des mots-clés du moment (cliquables), et un accès à la recherche. Prévoir un encart d'inscription
à la newsletter hebdomadaire (simple capture d'email, sans création de compte).

**B. Liste d'articles**
Grille/liste d'articles avec image, titre, source, date relative ("il y a 2 h"), badges de
mots-clés. Doit supporter plusieurs contextes de filtrage sans changer de gabarit : tous les
articles, articles d'une source (flux) donnée, articles d'un mot-clé donné. Prévoir un état de
chargement pour le "charger plus" (défilement infini ou pagination).

**C. Article détaillé**
Titre, image, source, date, corps/description, liste des mots-clés validés de l'article (tags
cliquables vers la page mot-clé), articles similaires ("à lire aussi", basés sur les mots-clés
communs), boutons de partage, et un lien vers la page pays si l'article est rattaché à un pays.

**D. Page mot-clé** (`/tag/{mot-clé}`)
Variante de la liste d'articles filtrée par mot-clé, avec un en-tête qui nomme le mot-clé et
indique le nombre d'articles.

**E. Page pays** — écran prioritaire, nouvelle fonctionnalité de la refonte
Sélecteur de pays (liste des pays actifs, avec drapeau), puis pour le pays choisi : flux
d'articles récents concernant ce pays, avec un filtre additionnel optionnel par mot-clé validé
("croisement pays × mot-clé", ex. Sénégal + Économie), et un mode **archives** permettant de
remonter dans le temps au-delà des derniers jours (navigation par mois/année plutôt qu'un simple
défilement). Réfléchis à une UI de sélection de période qui reste légère sur mobile.

**F. Recherche**
Champ de recherche avec suggestions, page de résultats reprenant le gabarit de liste d'articles,
état "aucun résultat" avec suggestions de mots-clés tendance.

**G. "Unes" — listes de veille thématiques**
- Page annuaire des "Unes" publiques (cartes nommées, ex. "Sénégal — Économie").
- Page d'une "Une" : reprend le gabarit de liste d'articles, filtré par l'ensemble des mots-clés
  de la "Une", avec le nom/auteur de la "Une" en en-tête.
- Formulaire de création/édition d'une "Une" (utilisateur connecté) : nom, sélecteur multi-mots-
  clés avec recherche/autocomplétion (uniquement des mots-clés validés), bascule publique/privée.
- Vue "mes Unes" dans l'espace personnel : liste, dupliquer, éditer, supprimer.

**H. Compte utilisateur**
Connexion (email/mot de passe ou bouton "Continuer avec Facebook"), inscription minimale,
mot de passe oublié. L'inscription doit être présentée comme optionnelle et réservée à ceux qui
veulent créer des "Unes" personnalisées — pas un mur d'inscription pour lire le site.

**I. Inscription à la newsletter**
Un simple champ email (capture rapide, sans mot de passe), utilisable en encart sur plusieurs
pages (accueil, pied de page, fin d'article) — ce n'est pas un compte utilisateur, juste un email
enregistré pour la newsletter hebdomadaire. Prévoir la confirmation visuelle post-inscription.

### Parcours 2 — Back-office administrateur (EasyAdmin)

Conçois ces écrans comme des interfaces d'administration denses et efficaces (priorité à la
rapidité de traitement plutôt qu'à l'esthétique éditoriale), mais cohérentes avec l'identité
visuelle globale.

**J. Tableau de bord**
Vue d'ensemble à l'ouverture : nombre de suggestions de mots-clés en attente (mis en avant, c'est
l'action la plus fréquente), nombre d'articles extraits récemment, état des flux actifs/inactifs.

**K. File de validation des mots-clés — écran le plus important du back-office**
Nouvelle fonctionnalité centrale de la refonte : liste des taxonomies au statut "Suggéré",
filtrable par langue (FR/EN) et triable par nombre d'occurrences/date de détection. Pour chaque
suggestion : le libellé, sa langue, son nombre d'occurrences, et idéalement un aperçu des articles
où elle apparaît (pour permettre une décision rapide sans changer d'écran). Actions "Valider" /
"Rejeter" disponibles ligne par ligne **et** en sélection multiple (traitement en masse, puisque
la validation doit pouvoir se faire en temps réel sur un volume soutenu). Le statut de chaque
taxonomie (Suggéré / Validé / Rejeté / Archivé) doit être visuellement non ambigu partout où il
apparaît dans le back-office (couleur/badge dédié).

**L. Gestion des flux (Feeds)**
Liste des flux avec statut actif/inactif, sponsorisé ou non, langue, dernière extraction. Formulaire
d'ajout/édition (libellé, URL, langue). Action rapide "Extraire maintenant".

**M. Gestion des articles**
Liste des articles récents avec statut publié/non publié, source, mots-clés validés associés,
action de bascule publication.

**N. Gestion des pays**
Liste des pays (actif/inactif), noms FR/EN, code.

**O. Gestion des publications (bandeaux)**
Liste et formulaire d'édition d'un encart (titre, contenu riche, lien, position, portée générale
ou limitée à certaines "Unes").

**P. Newsletter hebdomadaire**
Éditeur de contenu (objet, texte principal, texte additionnel), sélecteur de mots-clés validés et
d'articles à inclure, ciblage (tous les abonnés actifs ou une sélection), et statut d'envoi.

**Q. Utilisateurs et abonnés**
Deux listes distinctes à ne pas confondre visuellement : les **comptes utilisateurs** du site
(ceux qui créent des "Unes") et les **abonnés de la newsletter** (une simple liste d'emails, sans
lien avec un compte).

### Éléments hors périmètre de cette conception

Ne pas concevoir d'écran pour : la newsletter quotidienne (abandonnée), le partage automatique
vers les réseaux sociaux (fonctionnalité différée à une phase ultérieure — pas d'écran de
configuration Facebook/Twitter à prévoir pour l'instant).

### Livrables attendus

Pour chaque artboard : le layout desktop **et** son adaptation mobile (le trafic est
majoritairement mobile). Indique les états importants quand ils changent significativement le
layout : chargement, liste vide, erreur de recherche sans résultat, badge "Sponsorisé".

---

## Notes pour qui adapte ce prompt

- **Priorisation suggérée** si Claude Design ne doit produire qu'un sous-ensemble d'écrans en
  premier jet : Accueil (A), Article détaillé (C), Page pays (E) et File de validation des
  mots-clés (K) sont les quatre écrans qui concentrent le plus de valeur différenciante de la
  refonte — à traiter avant les écrans plus génériques (listes standards, formulaires CRUD).
- Aucune charte graphique n'a été retrouvée dans l'ancien dépôt (`thenotilus/afkr`) au-delà de
  templates Twig sans documentation de marque : le prompt part donc d'une page blanche assumée.
  Si une charte existe par ailleurs (logo, couleurs, typographie), fournis-la à Claude Design en
  complément de ce prompt avant génération.
- Ce prompt est un point de départ produit/UX : il ne prescrit pas de composants techniques
  (React, Twig, etc.) ni de librairie de design — c'est délibéré, Claude Design travaille en HTML/
  CSS autonome pour la phase de conception visuelle.

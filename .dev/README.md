# Environnement de développement Docker — `.dev/`

Stack complète et autonome pour développer afrique-actualites sans rien installer en local
(hormis Docker) : **Nginx + PHP-FPM 8.3 + MySQL 8 + Adminer + Mailpit**.

C'est une alternative au `compose.yaml` racine : celui-ci ne démarre que MySQL et suppose
que vous lancez PHP via `symfony server:start`. Ici, **tout** tourne dans Docker.
> N'exécutez pas les deux en même temps : ils se disputeraient le port 3306.

## Services et ports

| Service    | URL / port hôte            | Rôle                                              |
|------------|----------------------------|---------------------------------------------------|
| `nginx`    | http://localhost:8080      | Serveur web (racine `public/`)                    |
| `php`      | —                          | PHP-FPM 8.3 (intl, pdo_mysql, mbstring, zip…)     |
| `database` | `127.0.0.1:3306`           | MySQL 8 (base `afkr`) — aussi store `symfony/lock`|
| `adminer`  | http://localhost:8081      | Client web base de données                        |
| `mailer`   | http://localhost:8025      | Mailpit (capture SMTP, port SMTP `1025`)          |
| `messenger`| —                          | Worker `messenger:consume async` (crawl continu)  |
| `scheduler`| —                          | Ingestion des flux `app:feed:ingest` (toutes les 5 min) |

## Démarrage

```bash
cd .dev
docker compose up -d --build
```

Puis, **une seule fois**, configurez l'application pour qu'elle vise les conteneurs. Depuis
`php`, l'hôte de la base est `database` (le nom du service), **pas** `127.0.0.1`.
Copiez le bloc suivant dans un `.env.local` à la racine du projet :

```dotenv
DATABASE_URL="mysql://afkr:afkr@database:3306/afkr?serverVersion=8.0.36&charset=utf8mb4"
# Verrou symfony/lock stocké en base : réutilise la connexion ci-dessus. Doit être
# redéclaré ici (après DATABASE_URL) pour que ${...} pointe vers l'hôte `database`.
LOCK_DSN=${DATABASE_URL}
MAILER_DSN=smtp://mailer:1025
```

> `.env.local` est déjà ignoré par git.

Installez les dépendances et préparez la base (commandes lancées **dans** le conteneur `php`) :

```bash
docker compose exec php composer install
docker compose exec php php bin/console doctrine:database:create --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

L'application est alors disponible sur http://localhost:8080.

## Commandes utiles

```bash
# Un shell dans le conteneur PHP
docker compose exec php bash

# Console Symfony / Composer
docker compose exec php php bin/console <commande>
docker compose exec php composer <commande>

# Tests et qualité (voir README racine)
docker compose exec php vendor/bin/phpunit
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

# Logs / arrêt / reset complet de la base
docker compose logs -f php
docker compose down
docker compose down -v        # supprime aussi le volume MySQL (repart de zéro)
```

## Notes

- **Version PHP** : 8.3, alignée sur la CI (`.github/workflows/ci.yml`).
- **Données MySQL** : stockées dans le volume Docker nommé `database_data` (pas dans le dépôt).
  `docker compose down -v` les efface.
- **Verrou `symfony/lock`** : stocké en base (`LOCK_DSN=${DATABASE_URL}`). La table `lock_keys`
  est créée automatiquement au premier verrou ; pour la matérialiser dans une migration,
  `php bin/console make:migration` la détectera (`DoctrineDbalStore::configureSchema`).
- **Worker Messenger** : le service `messenger` consomme en continu le transport Doctrine
  `async` (`messenger:consume async`), sur lequel est routé `CrawlArticleMetaMessage`. Il
  redémarre toutes les heures (`--time-limit=3600` + `restart: unless-stopped`) pour repartir
  sur un process neuf. Prérequis : la table `messenger_messages` doit exister — elle est créée
  par les migrations, sinon `docker compose exec php php bin/console messenger:setup-transports`.
  Suivre le worker : `docker compose logs -f messenger`. Voir la section « Traitements continus »
  de [`docs/architecture.md`](../docs/architecture.md) (§6).
- **Planificateur d'ingestion** : le service `scheduler` lance `app:feed:ingest` **toutes les
  5 minutes** (boucle shell `while true; … sleep 300`) — c'est lui qui récupère les nouveaux
  articles des flux RSS/Atom et les classe. L'ingestion met en file les crawls de repli que le
  service `messenger` consomme ensuite. Pour changer la cadence, ajuster la valeur `sleep` dans
  `command:` (service `scheduler` du `docker-compose.yml`), puis `docker compose up -d scheduler`.
  Suivre l'ingestion : `docker compose logs -f scheduler`.

# Déploiement (Deployer)

Déploiement de production d'Afrique Actualités avec [Deployer](https://deployer.org)
(`deployer/deployer`, dépendance de dev). Le script est [`deploy.php`](../deploy.php) à la
racine ; il s'appuie sur la recette officielle `recipe/symfony.php`.

Le déploiement est **atomique par releases** : chaque déploiement crée un dossier horodaté
dans `releases/`, y installe le code et les dépendances, joue les migrations Doctrine, puis
bascule le symlink `current/`. En cas d'échec à n'importe quelle étape, la version en ligne
n'est jamais touchée, et un `rollback` revient à la release précédente en une commande.

```
{{deploy_path}}/
├── current -> releases/23        # symlink vers la release active (racine web = current/public)
├── releases/                     # 5 dernières releases (keep_releases)
├── shared/
│   ├── .env.local                # secrets prod (NON versionné, persistant entre releases)
│   ├── var/log/                  # logs (partagés → conservés entre déploiements)
│   └── var/sessions/
└── .dep/                         # métadonnées Deployer
```

## Prérequis sur le serveur cible

- SSH avec **agent forwarding** actif (le serveur clone le dépôt GitHub via la clé locale) —
  ou une deploy key sur le serveur ayant accès à `git@github.com:thenotilus/afrique-actualites.git`.
- PHP 8.3+ CLI + FPM avec les extensions du projet : `intl`, `pdo_mysql`, `mbstring`, `zip`,
  `opcache`, `ctype`, `iconv` (cf. [`.dev/php/Dockerfile`](../.dev/php/Dockerfile)).
- `git`, `unzip`, et **Composer** disponibles dans le `PATH` de l'utilisateur SSH.
- MySQL 8 accessible, et un serveur web (Nginx/Caddy) dont la racine pointe vers
  `{{deploy_path}}/current/public`.

## Configuration à faire une fois

### 1. Compléter `deploy.php`

Remplacer les placeholders `{{À_COMPLÉTER}}` du bloc `host('production')` :

- `setHostname(...)` — hostname ou IP du serveur ;
- `setRemoteUser(...)` — utilisateur SSH (ex. `deploy`) ;
- `deploy_path` — déjà à `/var/www/afrique-actualites`, à ajuster si besoin ;
- décommenter `identity_file` si une clé SSH dédiée est utilisée.

### 2. Créer le fichier de secrets partagé

`.env.local` est un **fichier partagé** : il vit dans `shared/` et survit aux déploiements.
Le créer **avant le premier déploiement** (Deployer refuse de démarrer sinon), à
`{{deploy_path}}/shared/.env.local` :

```dotenv
APP_ENV=prod
APP_SECRET=<32 caractères hexadécimaux aléatoires>
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/afkr?serverVersion=8.0.36&charset=utf8mb4"
# Verrou symfony/lock stocké en base (réutilise DATABASE_URL) — voir docs/architecture.md §6.
# À redéclarer APRÈS DATABASE_URL pour que ${...} pointe vers le bon hôte.
LOCK_DSN=${DATABASE_URL}
# File d'attente des crawls (transport Doctrine, table messenger_messages).
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
# SMTP réel de production (newsletter / e-mails transactionnels).
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

> Générer `APP_SECRET` : `php -r 'echo bin2hex(random_bytes(16)), "\n";'`

### 3. Installer les tâches cron (worker + ingestion)

L'extraction des flux et le crawl de repli tournent **en continu** (cf.
[`docs/architecture.md`](./architecture.md) §6). En production on les lance **toutes les
5 minutes via cron, avec une durée de vie de 5 minutes** (`--time-limit=300`) : chaque process
repart neuf (pas de fuite mémoire d'un worker de longue durée) et prend automatiquement le code
de la dernière release, puisque cron passe par le symlink `current/`.

Un fichier `crontab` à la racine du projet contient les définitions prêtes à l'emploi.
Vous pouvez générer la version adaptée à votre chemin de déploiement via :

```bash
vendor/bin/dep cron:show production
```

Ou l'installer directement sur le serveur :

```bash
vendor/bin/dep cron:install production
```

Ou copier manuellement en adaptant `{{deploy_path}}` (voir `crontab` à la racine) :

```cron
# Worker Messenger : consomme la file `async` (crawls de repli). --time-limit=300 = 5 min.
# flock -n évite tout chevauchement si un cycle déborde (double garde avec symfony/lock).
*/5 * * * * cd {{deploy_path}}/current && flock -n var/cron-messenger.lock php bin/console messenger:consume async --time-limit=300 --no-interaction >> var/log/messenger.cron.log 2>&1

# Ingestion des flux RSS/Atom : crée + classe les nouveaux articles, met en file les crawls.
*/5 * * * * cd {{deploy_path}}/current && flock -n var/cron-ingest.lock php bin/console app:feed:ingest --no-interaction >> var/log/ingest.cron.log 2>&1
```

> `flock` fait partie de `util-linux` (présent par défaut sur Debian/Ubuntu). Les logs
> atterrissent dans `var/log/`, qui est un dossier **partagé** : ils sont donc conservés d'une
> release à l'autre.

À chaque déploiement, la tâche `messenger:stop-workers` (jouée après la bascule) signale à un
worker éventuellement en cours de terminer son message puis de s'arrêter, pour que le prochain
tick cron reparte immédiatement sur le nouveau code plutôt que d'attendre la fin des 5 minutes.

## Déployer

```bash
# Déploiement standard (branche main)
vendor/bin/dep deploy production

# Déployer une autre branche
vendor/bin/dep deploy production --branch=ma-branche

# Sortie détaillée (utile au premier déploiement / en cas de souci)
vendor/bin/dep deploy production -vvv
```

Pipeline exécuté (`vendor/bin/dep tree deploy` pour le détail) :

1. `deploy:prepare` — nouvelle release, clone du code, symlinks partagés, dossiers writable ;
2. `deploy:vendors` — `composer install --no-dev --optimize-autoloader` (chauffe le cache prod
   et génère `public/bundles/` via les scripts Flex) ;
3. **`database:migrate`** — `doctrine:migrations:migrate --allow-no-migration` ;
4. `deploy:cache:clear` ;
5. `deploy:publish` — bascule du symlink `current/` (mise en ligne atomique) ;
6. **`messenger:stop-workers`** — arrêt gracieux des workers en cours.

## Rollback

```bash
# Revenir instantanément à la release précédente (re-bascule le symlink)
vendor/bin/dep rollback production

# Lister les releases disponibles sur le serveur
vendor/bin/dep releases production
```

> Un rollback ne « dé-joue » pas les migrations Doctrine. Si une release fautive a migré le
> schéma de façon incompatible avec le code précédent, prévoir une migration `down` ou un
> correctif base à la main.

## Commandes utiles

```bash
vendor/bin/dep ssh production                 # shell sur le serveur, dans current/
vendor/bin/dep run 'uptime' production        # commande arbitraire
vendor/bin/dep database:migrate production    # rejouer les migrations seules
vendor/bin/dep config production deploy_path  # inspecter une variable résolue
vendor/bin/dep logs:app production            # tail des logs applicatifs
```

## Dépannage

- **`.env.local` manquant** — le déploiement échoue au partage des fichiers. Créer
  `{{deploy_path}}/shared/.env.local` (voir §2).
- **`deploy locked`** après un échec — un verrou résiduel : `vendor/bin/dep deploy:unlock production`
  (le script déverrouille déjà automatiquement via le hook `after('deploy:failed', ...)`).
- **Permissions sur `var/`** — le bloc `host()` utilise `writable_mode: chmod` (les ACL ne sont
  pas disponibles chez tous les hébergeurs). Si le serveur web tourne sous un autre utilisateur,
  ajuster `http_user` dans `deploy.php`.
- **Première migration** — le schéma n'a encore jamais été migré contre MySQL (dette notée dans
  [`docs/architecture.md`](./architecture.md)). Générer la migration initiale contre une base
  MySQL (`doctrine:migrations:diff`) et la committer **avant** le premier déploiement, sinon
  `database:migrate` n'aura rien à jouer et le schéma restera vide.
```

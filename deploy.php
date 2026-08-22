<?php

/**
 * Déploiement Afrique Actualités (Symfony 6.4 LTS) avec Deployer.
 *
 * Déploiement « atomique » par releases : chaque déploiement crée un nouveau dossier
 * horodaté dans `releases/`, y installe le code + les dépendances, joue les migrations,
 * puis bascule le symlink `current/` dessus. Un échec ne casse jamais la version en ligne.
 *
 * ┌──────────────────────────────────────────────────────────────────────────────┐
 * │ AVANT LE PREMIER DÉPLOIEMENT — à faire une seule fois, à la main :             │
 * │                                                                                │
 * │  1. Renseigner les placeholders {{À_COMPLÉTER}} de la section `host()` ci-bas. │
 * │  2. Créer le fichier partagé des secrets sur le serveur :                      │
 * │        {{deploy_path}}/shared/.env.local                                        │
 * │     avec au minimum (voir .env / .dev/README.md pour le format) :              │
 * │        APP_ENV=prod                                                            │
 * │        APP_SECRET=<32 hex aléatoires>                                          │
 * │        DATABASE_URL="mysql://user:pass@host:3306/afkr?serverVersion=8.0.36&charset=utf8mb4" │
 * │        LOCK_DSN=${DATABASE_URL}                                                 │
 * │        MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0                  │
 * │        MAILER_DSN=...                                                           │
 * │  3. Installer les tâches cron (worker + ingestion) : voir docs/deployment.md.  │
 * └──────────────────────────────────────────────────────────────────────────────┘
 *
 * Usage :
 *   vendor/bin/dep deploy production          # déploie la branche `main`
 *   vendor/bin/dep deploy production --branch=xxx
 *   vendor/bin/dep rollback production         # revient à la release précédente
 *   vendor/bin/dep database:migrate production # rejoue les migrations seules
 *   vendor/bin/dep ssh production              # ouvre un shell sur le serveur
 *
 * Voir docs/deployment.md pour le détail (première mise en route, cron, dépannage).
 */

namespace Deployer;

require 'recipe/symfony.php';

// ─── Métadonnées du projet ──────────────────────────────────────────────────
set('application', 'afrique-actualites');
set('repository', 'git@github.com:thenotilus/afrique-actualites.git');

// Branche déployée par défaut (surchargeable avec --branch=...).
set('branch', 'main');

// Nombre de releases conservées sur le serveur (rollback possible sur les N-1 précédentes).
set('keep_releases', 5);

// Les migrations et `composer install` peuvent être longs sur un gros dépôt.
set('default_timeout', 900);

// ─── Fichiers / dossiers partagés entre releases ────────────────────────────
// (`.env.local` et `var/log` sont déjà déclarés par la recette symfony.php ;
//  on complète avec le dossier des sessions.)
add('shared_dirs', ['var/sessions', 'var/log', 'var/cache']);
add('writable_dirs', ['var/sessions', 'var/log', 'var/cache']);

// ─── Installation des dépendances (production) ──────────────────────────────
// --no-dev : n'installe pas PHPUnit, php-cs-fixer, Deployer, MakerBundle...
// --optimize-autoloader : autoloader de classe optimisé (classmap).
// Les scripts Flex (`cache:clear`, `assets:install`) restent actifs (pas de --no-scripts),
// donc le cache prod est chauffé et `public/bundles/` généré pendant `composer install`.
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

// ─── Cible(s) de déploiement ────────────────────────────────────────────────
// Renseignez les placeholders {{À_COMPLÉTER}}. Dupliquez ce bloc pour un environnement
// de préproduction (`host('staging')...`) si besoin.
host('production')
    ->setHostname('afrique-actualites.com')
    ->setRemoteUser('thenotilus')
    // Chemin racine des déploiements sur le serveur (contiendra releases/, shared/, current/).
    ->set('deploy_path', '/var/www/app/afrique-actualites.com')
    // Décommentez si vous utilisez une clé SSH dédiée :
    // ->set('identity_file', '~/.ssh/id_afkr_deploy')
    ->set('http_user', 'www-data')       // utilisateur du serveur web (droits sur var/)
    ->set('writable_mode', 'chmod');     // ACL indisponibles chez beaucoup d'hébergeurs → chmod

// ─── Tâches Cron ─────────────────────────────────────────────────────────────
desc('Installe les tâches cron sur le serveur');
task('cron:install', function () {
    $deployPath = get('deploy_path');
    $template = file_get_contents(__DIR__ . '/crontab');
    $crontab = str_replace('{{deploy_path}}', $deployPath, $template);

    // On utilise un marqueur pour pouvoir mettre à jour la section sans toucher au reste de la crontab
    $startMarker = "### AFKR-START ###";
    $endMarker = "### AFKR-END ###";

    $newBlock = "$startMarker\n$crontab\n$endMarker";

    // Récupérer la crontab actuelle, supprimer l'ancien bloc si présent, et ajouter le nouveau
    $currentCrontab = run('crontab -l 2>/dev/null || true');

    if (str_contains($currentCrontab, $startMarker)) {
        $pattern = "/".preg_quote($startMarker).".*?".preg_quote($endMarker)."/s";
        $updatedCrontab = preg_replace($pattern, $newBlock, $currentCrontab);
    } else {
        $updatedCrontab = $currentCrontab . "\n\n" . $newBlock . "\n";
    }

    $tmpFile = '/tmp/afkr_crontab';
    run("echo " . escapeshellarg(trim($updatedCrontab)) . " > $tmpFile");
    run("crontab $tmpFile");
    run("rm $tmpFile");

    writeln('<info>Crontab mise à jour avec succès.</info>');
});

// ─── Signal de redémarrage des workers Messenger ────────────────────────────
// Le worker tourne en cron avec `--time-limit=300` (voir docs/deployment.md), il n'y a donc
// pas de process persistant à relancer : le prochain tick cron démarrera sur la nouvelle release.
// On envoie tout de même le signal d'arrêt gracieux pour qu'un worker déjà en cours (ancien code)
// termine son message courant puis s'arrête, plutôt que de tourner jusqu'à 5 min sur l'ancien code.
desc('Signale aux workers Messenger de s\'arrêter (reprise du nouveau code au prochain cron)');
task('messenger:stop-workers', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} messenger:stop-workers {{console_options}} || true');
});

// ─── Assemblage du pipeline de déploiement ──────────────────────────────────
// On insère les migrations juste avant la publication (bascule du symlink), et le signal
// d'arrêt des workers juste après.
after('deploy:vendors', 'database:migrate');
after('deploy:publish', 'messenger:stop-workers');

// Déverrouille automatiquement en cas d'échec (évite un « deploy locked » bloquant).
after('deploy:failed', 'deploy:unlock');

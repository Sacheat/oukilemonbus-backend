#!/usr/bin/env bash
# Déploiement (première installation et mises à jour) sans Docker : PHP, Nginx, MariaDB, Supervisor et cron
# installés sur la VM (voir deploy/README.md). À lancer par l'utilisateur propriétaire du code, avec sudo.
# Usage :  deploy/native/deploy.sh [--no-pull]
#   --no-pull : déploie le code tel qu'il est sur la VM, sans git pull.
#
# Réglables par variables d'environnement si la VM diffère :
#   APP_USER (www-data)  PHP_FPM_SERVICE (php8.4-fpm)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

APP_USER="${APP_USER:-www-data}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[1;31mErreur : %s\033[0m\n' "$1" >&2; exit 1; }

# Toutes les commandes artisan tournent en www-data, comme php-fpm, le worker et le cron : un fichier de log ou de
# cache créé par un autre utilisateur bloquerait ensuite l'application (permission refusée).
artisan() { sudo -u "$APP_USER" php artisan "$@"; }

[[ -f .env ]] || fail ".env introuvable à la racine du dépôt. Copier deploy/.env.production.example en .env et le remplir."
grep -q '^APP_KEY=base64:' .env || fail "APP_KEY vide dans .env. Voir « Première installation » dans deploy/README.md."
grep -q '^APP_ENV=production' .env || fail "APP_ENV doit valoir production dans .env."
grep -Eq '^BACKUP_ENCRYPTION_KEY=base64:' .env && grep -q '^BACKUP_ENCRYPTION_ENABLED=true' .env \
    || printf '\033[1;33mAttention : backups non chiffrés (BACKUP_ENCRYPTION_ENABLED / BACKUP_ENCRYPTION_KEY) : le backup de la nuit sera refusé. Voir « Backups » dans deploy/README.md.\033[0m\n' >&2
sudo -u "$APP_USER" test -r .env || fail ".env illisible par $APP_USER : chgrp $APP_USER .env && chmod 640 .env"
sudo -u "$APP_USER" test -w storage/logs || fail "storage/ non modifiable par $APP_USER : sudo chown -R $APP_USER:$APP_USER storage bootstrap/cache"

if [[ "${1:-}" != "--no-pull" ]]; then
    step "Récupération du code"
    git pull --ff-only
fi

step "Mode maintenance"
if [[ -f vendor/autoload.php ]]; then
    artisan down --retry=15
    # Quoi qu'il arrive (migration en échec…), l'API ne reste pas en maintenance.
    trap 'artisan up > /dev/null 2>&1 || true' EXIT
else
    echo "Première installation : rien à mettre en maintenance."
fi

step "Dépendances PHP"
# --no-scripts : package:discover est lancé juste après en www-data (il écrit dans bootstrap/cache).
composer install --no-dev --no-scripts --optimize-autoloader --no-interaction --no-progress
artisan package:discover --ansi

step "Migrations et rôles/permissions"
artisan migrate --force
# Idempotent : remet chaque rôle exactement à sa liste de permissions (nouvelles permissions comprises).
artisan db:seed --class=RolesAndPermissionsSeeder --force

step "Mise en cache (config, routes, vues, événements)"
artisan optimize

step "Rechargement de PHP et du worker"
# OPcache ne relit pas les fichiers (deploy/php/production.ini) : php-fpm doit être rechargé pour le nouveau code.
sudo service "$PHP_FPM_SERVICE" reload
# Le worker termine son job en cours puis s'arrête ; Supervisor le relance avec le nouveau code.
# Le cron (schedule:run) lance un nouveau processus chaque minute : rien à faire.
artisan queue:restart

step "Fin de la maintenance"
artisan up
trap - EXIT

step "Vérification"
if ! artisan app:health; then
    printf '\033[1;33mAttention : app:health signale un problème (voir ci-dessus).\033[0m\n'
fi

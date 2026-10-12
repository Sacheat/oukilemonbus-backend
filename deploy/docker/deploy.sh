#!/usr/bin/env bash
# Déploiement (première installation et mises à jour) de la stack Docker de production.
# Usage, depuis n'importe où :  deploy/docker/deploy.sh [--no-pull]
#   --no-pull : déploie le code tel qu'il est sur la VM, sans git pull.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[1;31mErreur : %s\033[0m\n' "$1" >&2; exit 1; }

[[ -f .env ]] || fail ".env introuvable à la racine du dépôt. Copier deploy/.env.production.example en .env et le remplir."
grep -q '^APP_KEY=base64:' .env || fail "APP_KEY vide dans .env. Voir « Première installation » dans deploy/README.md."
grep -q '^APP_ENV=production' .env || fail "APP_ENV doit valoir production dans .env."

COMPOSE=(docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml)
# Profil HTTPS (Caddy) seulement si un domaine est configuré.
if grep -Eq '^CADDY_DOMAIN=.+' .env; then
    COMPOSE+=(--profile https)
fi

if [[ "${1:-}" != "--no-pull" ]]; then
    step "Récupération du code"
    git pull --ff-only
fi

step "Construction des images"
"${COMPOSE[@]}" build --pull

step "Démarrage de la base de données"
"${COMPOSE[@]}" up -d --wait mariadb

step "Migrations et rôles/permissions"
"${COMPOSE[@]}" run --rm app php artisan migrate --force
# Idempotent : remet chaque rôle exactement à sa liste de permissions (nouvelles permissions comprises).
"${COMPOSE[@]}" run --rm app php artisan db:seed --class=RolesAndPermissionsSeeder --force

step "Démarrage de l'application"
# Les conteneurs dont l'image a changé sont recréés : le worker finit son job en cours (stop_grace_period) puis
# repart avec le nouveau code, sans queue:restart.
"${COMPOSE[@]}" up -d --remove-orphans

step "Nettoyage des anciennes images"
docker image prune -f > /dev/null

step "Vérification"
if ! "${COMPOSE[@]}" exec -T app php artisan app:health; then
    printf '\033[1;33mAttention : app:health signale un problème (voir ci-dessus).\033[0m\n'
fi
"${COMPOSE[@]}" ps

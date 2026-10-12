#!/usr/bin/env bash
# Copie hors de la VM des backups chiffrés (RGPD + perte de la VM). À lancer par cron sur la VM, après backup:run
# (03:30). Voir « Backups » dans deploy/README.md.
#
# Usage :  deploy/backup-offsite.sh <remote-rclone:chemin>
#   ex.    deploy/backup-offsite.sh nas-commune:bus-communal/backups
#
# - Ne copie que les archives chiffrées (*.tar.gz.enc) : une archive en clair ne sort jamais de la VM.
# - copy, pas sync : une archive supprimée sur la VM (rétention 14 j) reste sur la destination.
# - OFFSITE_RETENTION_DAYS (défaut 30, 0 = jamais) : supprime sur la destination les archives plus anciennes.
# - BACKUP_SOURCE : dossier des archives. Par défaut : le volume Docker bus-communal_storage, sinon
#   /opt/bus-communal/storage/app/private/backups (installation sans Docker).
#
# La destination (NAS, S3, autre serveur…) se configure une fois avec `rclone config` ; elle reçoit des archives
# chiffrées, mais reste un sous-traitant à déclarer si elle est hors de la commune (docs/sous-traitants.md).
set -euo pipefail

fail() { printf 'Erreur : %s\n' "$1" >&2; exit 1; }

REMOTE="${1:-}"
[[ -n "$REMOTE" ]] || fail "destination rclone manquante. Usage : $0 <remote:chemin>"
command -v rclone >/dev/null || fail "rclone n'est pas installé (https://rclone.org/install/)."

SOURCE="${BACKUP_SOURCE:-}"
if [[ -z "$SOURCE" ]]; then
    if command -v docker >/dev/null && docker volume inspect bus-communal_storage >/dev/null 2>&1; then
        SOURCE="$(docker volume inspect -f '{{ .Mountpoint }}' bus-communal_storage)/app/private/backups"
    else
        SOURCE="/opt/bus-communal/storage/app/private/backups"
    fi
fi
[[ -d "$SOURCE" ]] || fail "dossier des backups introuvable : $SOURCE (régler BACKUP_SOURCE)."

if find "$SOURCE" -maxdepth 1 -name '*.tar.gz' -newermt '-2 days' | grep -q .; then
    printf 'Attention : archives non chiffrées récentes dans %s, elles ne sont pas copiées (BACKUP_ENCRYPTION_ENABLED).\n' "$SOURCE" >&2
fi

rclone copy "$SOURCE" "$REMOTE" --include '*.tar.gz.enc' --immutable

RETENTION="${OFFSITE_RETENTION_DAYS:-30}"
if [[ "$RETENTION" -gt 0 ]]; then
    rclone delete "$REMOTE" --include '*.tar.gz.enc' --min-age "${RETENTION}d"
fi

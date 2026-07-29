#!/usr/bin/env bash
#
# Copy migrated ticket attachments from staging app public disk → host backup.
#
# Usage:
#   ./scripts/backup-mvas-attachments.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTR="${VASPARTNERS_APP_CONTAINER:-vaspartners-app}"
BACKUP_ROOT="${BACKUP_ROOT:-$ROOT/backups}"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP="$BACKUP_ROOT/mvas-attachments-$STAMP"

if ! docker inspect "$CTR" >/dev/null 2>&1; then
  echo "Container $CTR not running."
  exit 1
fi

mkdir -p "$BACKUP"
echo "==> Copying $CTR:/var/www/html/storage/app/public/tickets → $BACKUP/"
docker cp "$CTR:/var/www/html/storage/app/public/tickets" "$BACKUP/"

COUNT="$(find "$BACKUP/tickets" -type f | wc -l)"
SIZE="$(du -sh "$BACKUP/tickets" | cut -f1)"

cat > "$BACKUP/README.txt" <<EOF
MVAS → VAS Partners attachment backup
Created: $(date -Is)
Source: $CTR:/var/www/html/storage/app/public/tickets
Disk: public (storage/app/public)
Files: $COUNT
Size: $SIZE
EOF

echo "files=$COUNT size=$SIZE"
echo "backup=$BACKUP"

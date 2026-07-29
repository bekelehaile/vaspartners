#!/usr/bin/env bash
#
# Fresh MySQL dump from live MVAS DB → mvasportal/dumps/
# Credentials default from /data-disk/applications/mvasportal/.env
#
# Usage:
#   ./scripts/dump-mvas.sh
#   MVAS_DB_HOST=10.190.149.247 ./scripts/dump-mvas.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MVAS_ROOT="${MVAS_ROOT:-/data-disk/applications/mvasportal}"
ENV_FILE="${MVAS_ENV_FILE:-$MVAS_ROOT/.env}"
OUT_DIR="${MVAS_DUMP_DIR:-$MVAS_ROOT/dumps}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  # Only pull DB_* lines (avoid sourcing full Laravel .env with special chars issues)
  DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  set +a
fi

DB_HOST="${MVAS_DB_HOST:-${DB_HOST:-10.190.149.247}}"
DB_PORT="${MVAS_DB_PORT:-${DB_PORT:-3306}}"
DB_DATABASE="${MVAS_DB_DATABASE:-${DB_DATABASE:-mvas}}"
DB_USERNAME="${MVAS_DB_USERNAME:-${DB_USERNAME:-mvas_user}}"
DB_PASSWORD="${MVAS_DB_PASSWORD:-${DB_PASSWORD:-}}"

if [[ -z "$DB_PASSWORD" ]]; then
  echo "Set DB_PASSWORD in $ENV_FILE or MVAS_DB_PASSWORD"
  exit 1
fi

mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$OUT_DIR/mvas_${STAMP}.dump"

echo "==> Dumping ${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
echo "==> → $OUT"

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  -h "$DB_HOST" \
  -P "$DB_PORT" \
  -u "$DB_USERNAME" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  "$DB_DATABASE" > "$OUT"

ls -lah "$OUT"
echo
echo "Next:"
echo "  export MVAS_DUMP_PATH=/mvas-dumps/$(basename "$OUT")"
echo "  ./scripts/migrate-mvas-staging.sh"
echo "  # or update default DUMP= in scripts/migrate-mvas-staging.sh"

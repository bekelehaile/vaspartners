#!/usr/bin/env bash
#
# Repeatable MVAS dump → VAS Partners staging migration.
#
# Usage:
#   ./scripts/migrate-mvas-staging.sh              # fresh wipe + full migrate
#   ./scripts/migrate-mvas-staging.sh --dry-run    # count only
#   ./scripts/migrate-mvas-staging.sh --no-fresh   # idempotent re-run (no wipe)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CTR="${VASPARTNERS_APP_CONTAINER:-vaspartners-app}"
DUMP="${MVAS_DUMP_PATH:-/mvas-dumps/mvas_20260724_090806.dump}"
STORAGE="${MVAS_STORAGE_PATH:-/mvas-storage}"

FRESH=1
DRY_RUN=0
EXTRA=()

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --no-fresh) FRESH=0 ;;
    *) EXTRA+=("$arg") ;;
  esac
done

if ! docker inspect "$CTR" >/dev/null 2>&1; then
  echo "Container $CTR not running. Start staging first: ./deploy-staging.sh"
  exit 1
fi

CMD=(php artisan vas:migrate-mvas-dump --dump="$DUMP" --storage="$STORAGE")
if [[ "$FRESH" -eq 1 ]]; then
  CMD+=(--fresh --force)
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  CMD+=(--dry-run)
fi
CMD+=("${EXTRA[@]}")

echo "==> ${CMD[*]}"
docker exec "$CTR" "${CMD[@]}"

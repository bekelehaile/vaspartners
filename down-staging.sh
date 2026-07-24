#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

REMOVE_VOLUMES=false
for arg in "$@"; do
  case "$arg" in
    -v|--volumes) REMOVE_VOLUMES=true ;;
    -h|--help)
      echo "Usage: ./down-staging.sh [--volumes|-v]"
      echo "  Stop staging containers. Use -v to wipe staging Postgres/Redis volumes."
      echo "  Does not affect production mvasportal."
      exit 0
      ;;
  esac
done

echo "Stopping VAS Partners staging stack..."
if [[ "$REMOVE_VOLUMES" == "true" ]]; then
  docker compose -f compose.staging.yml down -v --remove-orphans
  echo "Staging down (volumes removed)."
else
  docker compose -f compose.staging.yml down --remove-orphans
  echo "Staging down (data volumes kept). Wipe with: ./down-staging.sh -v"
fi

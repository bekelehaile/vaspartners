#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

REMOVE_VOLUMES=false
for arg in "$@"; do
  case "$arg" in
    -v|--volumes) REMOVE_VOLUMES=true ;;
    -h|--help)
      echo "Usage: ./down-prod.sh [--volumes|-v]"
      echo "  Stop PRODUCTION containers. Use -v to wipe production Postgres/Redis volumes."
      echo "  Does not affect the stage stack (compose.staging.yml)."
      exit 0
      ;;
  esac
done

echo "Stopping VAS Partners PRODUCTION stack..."
if [[ "$REMOVE_VOLUMES" == "true" ]]; then
  docker compose -f compose.prod.yml down -v --remove-orphans
  echo "Production down (volumes removed)."
else
  docker compose -f compose.prod.yml down --remove-orphans
  echo "Production down (data volumes kept). Wipe with: ./down-prod.sh -v"
fi

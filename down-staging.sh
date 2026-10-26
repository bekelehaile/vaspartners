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
      echo "  Stop STAGE containers. Use -v to wipe stage Postgres/Redis volumes."
      echo "  Does not affect production (compose.prod.yml)."
      exit 0
      ;;
  esac
done

echo "Stopping VAS Partners STAGE stack..."
if [[ "$REMOVE_VOLUMES" == "true" ]]; then
  docker compose -f compose.staging.yml down -v --remove-orphans
  echo "Stage down (volumes removed)."
else
  docker compose -f compose.staging.yml down --remove-orphans
  echo "Stage down (data volumes kept). Wipe with: ./down-staging.sh -v"
fi

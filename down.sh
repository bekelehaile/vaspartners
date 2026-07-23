#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

REMOVE_VOLUMES=false
for arg in "$@"; do
  case "$arg" in
    -v|--volumes) REMOVE_VOLUMES=true ;;
    -h|--help)
      echo "Usage: ./down.sh [--volumes|-v]"
      echo "  Stop and remove containers. Use -v to also wipe Postgres/Redis volumes."
      exit 0
      ;;
  esac
done

echo "Stopping VAS Partners stack..."
if [[ "$REMOVE_VOLUMES" == "true" ]]; then
  docker compose down -v --remove-orphans
  echo "Down (volumes removed)."
else
  docker compose down --remove-orphans
  echo "Down (data volumes kept). Wipe DB with: ./down.sh -v"
fi

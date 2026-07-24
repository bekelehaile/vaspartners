#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

APP_DIR="$ROOT/vaspartners"

if [[ ! -f "$APP_DIR/backend/.env" ]]; then
  echo "Missing vaspartners/backend/.env — copy .env.example and set FAYDA_* first."
  exit 1
fi

if [[ ! -f "$APP_DIR/frontend/.env.local" ]]; then
  cp "$APP_DIR/frontend/.env.example" "$APP_DIR/frontend/.env.local"
  echo "Created vaspartners/frontend/.env.local from .env.example"
fi

echo "Starting VAS Partners stack..."
docker compose up --build -d "$@"

echo
echo "Waiting for backend health (migrate/seed may take a minute)..."
for i in $(seq 1 90); do
  if curl -sf "http://localhost:8000/up" >/dev/null 2>&1; then
    break
  fi
  if [[ "$i" -eq 90 ]]; then
    echo "Backend not ready yet — check: docker compose logs -f backend"
    exit 1
  fi
  sleep 2
done

echo
echo "Up."
echo "  Portal:  http://localhost:3000"
echo "  Admin:   http://localhost:8000/admin"
echo "  API:     http://localhost:8000/api/v1"
echo
echo "  Logs:    docker compose logs -f"
echo "  Down:    ./down.sh"

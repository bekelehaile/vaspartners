#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

if [[ ! -f backend/.env ]]; then
  echo "Missing backend/.env — copy backend/.env.example and set FAYDA_* first."
  exit 1
fi

if [[ ! -f frontend/.env.local ]]; then
  cp frontend/.env.example frontend/.env.local
  echo "Created frontend/.env.local from .env.example"
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

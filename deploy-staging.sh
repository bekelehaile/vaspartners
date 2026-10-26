#!/usr/bin/env bash
#
# STAGE deploy for VAS Partners (feature testing).
# Access: https://vaspartnersportal.ethiotelecom.et:8443
# (requires host :8443 free — stop fbb_staging first if it holds the port)
#
# Does NOT touch production (compose.prod.yml / :443).
#
# Usage:
#   ./deploy-staging.sh
#   ./deploy-staging.sh abc1234
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

VERSION="${1:-$(git rev-parse --short HEAD 2>/dev/null || echo stage)}"
COMPOSE=(docker compose -f compose.staging.yml)

if [[ ! -f .env.staging ]]; then
  if [[ -f .env.staging.example ]]; then
    echo "==> Creating .env.staging from .env.staging.example — fill FAYDA_* before use"
    cp .env.staging.example .env.staging
  elif [[ -f vaspartners/backend/.env ]]; then
    echo "==> Creating .env.staging from vaspartners/backend/.env (edit Fayda + URLs for :8443)"
    cp vaspartners/backend/.env .env.staging
  else
    echo "Missing .env.staging — copy .env.staging.example and set FAYDA_* first."
    exit 1
  fi
fi

ensure_kv() {
  local key="$1" val="$2" file=".env.staging"
  if grep -q "^${key}=" "$file"; then
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k{$0=k"="v} {print}' "$file" > "${file}.tmp"
    mv "${file}.tmp" "$file"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}

ensure_kv APP_URL "https://vaspartnersportal.ethiotelecom.et:8443"
ensure_kv FRONTEND_URL "https://vaspartnersportal.ethiotelecom.et:8443"
ensure_kv FAYDA_REDIRECT_URI "https://vaspartnersportal.ethiotelecom.et:8443/callback"
ensure_kv SANCTUM_STATEFUL_DOMAINS "vaspartnersportal.ethiotelecom.et:8443,vaspartnersportal.ethiotelecom.et"
ensure_kv TRUSTED_PROXIES "*"
ensure_kv APP_DEBUG "true"

if [[ ! -r /etc/nginx/ssl/fullchain-wildcard.crt || ! -r /etc/nginx/ssl/ethiotelecom-wildcard.key ]]; then
  echo "Tele SSL certs not readable at /etc/nginx/ssl/fullchain-wildcard.crt (+ key)."
  exit 1
fi

echo "==> Building STAGE images (${VERSION}) — project vaspartners-stage (production untouched)..."
DOCKER_BUILDKIT=1 "${COMPOSE[@]}" build \
  --build-arg NEXT_PUBLIC_API_URL=https://vaspartnersportal.ethiotelecom.et:8443/api/v1 \
  --build-arg NEXT_PUBLIC_SITE_URL=https://vaspartnersportal.ethiotelecom.et:8443

echo "==> Starting STAGE stack..."
"${COMPOSE[@]}" up -d --remove-orphans

echo "==> Reloading nginx upstreams..."
"${COMPOSE[@]}" exec -T nginx nginx -s reload 2>/dev/null \
  || "${COMPOSE[@]}" restart nginx

echo "==> Waiting for backend health..."
for i in $(seq 1 90); do
  if "${COMPOSE[@]}" ps backend 2>/dev/null | grep -q '(healthy)'; then
    break
  fi
  if [[ "$i" -eq 90 ]]; then
    echo "Backend not healthy yet — check: docker compose -f compose.staging.yml logs -f backend"
    exit 1
  fi
  sleep 2
done

echo
echo "VAS Partners STAGE up (${VERSION})."
echo "  Portal:  https://vaspartnersportal.ethiotelecom.et:8443"
echo "  Admin:   https://vaspartnersportal.ethiotelecom.et:8443/admin"
echo "  API:     https://vaspartnersportal.ethiotelecom.et:8443/api/v1"
echo
echo "  Direct TLS :8443 → vaspartners-stage-nginx (production :443 untouched)"
echo "  Logs:    docker compose -f compose.staging.yml logs -f"
echo "  Down:    ./down-staging.sh"

#!/usr/bin/env bash
#
# Build and roll out the VAS Partners stack (containers + Postgres).
# Public URL: https://vaspartnersportal.ethiotelecom.et (host nginx → :30011).
#
# Usage:
#   ./deploy-staging.sh
#   ./deploy-staging.sh abc1234
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

VERSION="${1:-$(git rev-parse --short HEAD 2>/dev/null || echo staging)}"
COMPOSE=(docker compose -f compose.staging.yml)

if [[ ! -f .env.staging ]]; then
  if [[ -f vaspartners/backend/.env ]]; then
    echo "==> Creating .env.staging from vaspartners/backend/.env (edit Fayda + URLs if needed)"
    cp vaspartners/backend/.env .env.staging
  elif [[ -f .env.staging.example ]]; then
    echo "==> Creating .env.staging from .env.staging.example — fill FAYDA_* before use"
    cp .env.staging.example .env.staging
  else
    echo "Missing .env.staging — copy .env.staging.example and set FAYDA_* first."
    exit 1
  fi
fi

# Ensure staging public URLs (do not rely on local localhost values)
ensure_kv() {
  local key="$1" val="$2" file=".env.staging"
  if grep -q "^${key}=" "$file"; then
    # portable in-place replace
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k{$0=k"="v} {print}' "$file" > "${file}.tmp"
    mv "${file}.tmp" "$file"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}

ensure_kv APP_URL "https://vaspartnersportal.ethiotelecom.et"
ensure_kv FRONTEND_URL "https://vaspartnersportal.ethiotelecom.et"
ensure_kv FAYDA_REDIRECT_URI "https://vaspartnersportal.ethiotelecom.et/callback"
ensure_kv SANCTUM_STATEFUL_DOMAINS "vaspartnersportal.ethiotelecom.et"
# Keep APP_ENV from .env.staging (production or staging) — do not force overwrite.
ensure_kv TRUSTED_PROXIES "*"
ensure_kv APP_DEBUG "false"

if [[ ! -r /etc/nginx/ssl/fullchain-wildcard.crt || ! -r /etc/nginx/ssl/ethiotelecom-wildcard.key ]]; then
  echo "Tele SSL certs not readable at /etc/nginx/ssl/fullchain-wildcard.crt (+ key)."
  echo "Nginx mounts these for TLS on :8443 (host :443 uses system nginx)."
  exit 1
fi

echo "==> Building staging images (${VERSION})..."
DOCKER_BUILDKIT=1 "${COMPOSE[@]}" build \
  --build-arg NEXT_PUBLIC_API_URL=https://vaspartnersportal.ethiotelecom.et/api/v1 \
  --build-arg NEXT_PUBLIC_SITE_URL=https://vaspartnersportal.ethiotelecom.et

echo "==> Starting staging stack (Postgres + PgBouncer + Redis + app + otp/import/export/sms/default workers + web + nginx)..."
"${COMPOSE[@]}" up -d --remove-orphans

# Nginx caches upstream IPs unless config uses Docker DNS variables; always
# reload after app/frontend recreate so a stale IP cannot cause 502s.
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
echo "VAS Partners up (${VERSION})."
echo "  Portal:  https://vaspartnersportal.ethiotelecom.et"
echo "  Admin:   https://vaspartnersportal.ethiotelecom.et/admin"
echo "  API:     https://vaspartnersportal.ethiotelecom.et/api/v1"
echo
echo "  Host nginx :443 → 127.0.0.1:30011 → vaspartners-nginx"
echo "  Logs:    docker compose -f compose.staging.yml logs -f"
echo "  Down:    ./down-staging.sh"

# VAS Partners

Rebuild of Ethio Telecom **MVAS Partners Portal**.

Layout (same as `mvasportal`):

```
/data-disk/applications/vaspartners/     # parent (compose, deploy scripts, .env.staging)
└── vaspartners/                         # app (backend, frontend, docker)
    ├── backend/                         # Laravel API + Filament
    ├── frontend/                        # Next.js portal
    └── docker/
```

| Layer | Stack | Path |
|-------|--------|------|
| Customer portal | Next.js 15 | `vaspartners/frontend/` |
| API + admin | Laravel 12 + Filament 5 + Sanctum | `vaspartners/backend/` |
| Partner auth | Fayda (eSignet) — same pattern as fixedservices; creates `customers` on sign-in | `vaspartners/backend/app/Services/EsignetService.php` |
| Staff auth | Filament login (`/admin`) | Filament Shield RBAC |

## Workflow (unchanged)

```
Customer creates ticket (open)
  → Supervisor assigns AM (in_progress)
  → AM verifies documents
  → Approver chain via manager_id until final approver
  → completed → closed
  ↘ rejected → fix docs → re-verify
```

## Database

See [`docs/SCHEMA.md`](docs/SCHEMA.md). Highlights: configurable request types, per-type document matrix, subscriptions with yearly/bi-yearly renewal, append-only ticket history.

## Quick start (Docker — recommended)

Brings up **Postgres 18**, **PgBouncer**, **Redis**, **Laravel (PHP 8.4)**, and **Next.js**.

```bash
# From parent: /data-disk/applications/vaspartners

# 1) Fayda secrets (never commit the private key)
cp vaspartners/backend/.env.example vaspartners/backend/.env
# edit FAYDA_* + ensure APP_KEY is set (or let container generate it)

cp vaspartners/frontend/.env.example vaspartners/frontend/.env.local

# 2) Start / stop
./up.sh
./down.sh          # keep DB volumes
./down.sh -v       # wipe Postgres/Redis volumes
```

| Service | URL / port |
|---------|------------|
| Portal | http://localhost:3000 |
| API / Filament | http://localhost:8000 — admin `/admin` |
| Postgres (direct) | localhost:5433 |
| PgBouncer | localhost:6432 |
| Redis | localhost:6380 |

Seeded admin: `admin@demo.com` / `password`  
Fayda sandbox FIN/FAN: `3126894653473958` or `6230247319356120` — OTP: `111111`

Useful commands:

```bash
docker compose exec backend php artisan migrate:fresh --seed
docker compose exec backend php artisan queue:work
docker compose logs -f backend frontend
```

Compose overrides DB/Redis hosts inside containers (`pgbouncer`, `redis`). Your `vaspartners/backend/.env` Fayda values are still loaded.

## Staging (server — Tele SSL on :8443)

| | Production (old) | Staging (new) |
|--|------------------|---------------|
| Parent dir | `/data-disk/applications/mvasportal` | `/data-disk/applications/vaspartners` |
| App dir | `mvasportal/` | `vaspartners/` |
| Containers | `mvasportal-app`, `mvasportal-nginx` | `vaspartners-app`, `vaspartners-nginx`, … |
| Public | `https://vaspartnersportal.ethiotelecom.et` (:443) | `https://vaspartnersportal.ethiotelecom.et:8443` |
| Proxy | host nginx → `:30011` | Docker `vaspartners-nginx` (Tele wildcard cert) |
| DB | external MySQL | **Postgres in Docker** (`vaspartners-postgres`) — no SQLite |

Production `mvasportal` on :443 is left alone. Staging runs **everything** in containers (Postgres, PgBouncer, Redis, Laravel, queue, Next.js, nginx). Use this Postgres volume to rehearse migrating old portal data.

```bash
# From /data-disk/applications/vaspartners
cp .env.staging.example .env.staging   # pgsql + redis; no sqlite
# set FAYDA_* in .env.staging

./deploy-staging.sh
./down-staging.sh          # keep volumes
./down-staging.sh -v       # wipe staging Postgres volumes

# Host DB access for dump/restore / migration scripts
#   postgres:  127.0.0.1:35432  (user vas / secret, db vaspartners)
#   pgbouncer: 127.0.0.1:36432
```

| URL | |
|-----|--|
| Portal | https://vaspartnersportal.ethiotelecom.et:8443 |
| Admin | https://vaspartnersportal.ethiotelecom.et:8443/admin |
| API | https://vaspartnersportal.ethiotelecom.et:8443/api/v1 |

Containers: `vaspartners-postgres`, `vaspartners-pgbouncer`, `vaspartners-redis`, `vaspartners-app`, `vaspartners-queue`, `vaspartners-frontend`, `vaspartners-nginx`.  
Network / volumes: `vaspartners-network`, `vaspartners-pg`, `vaspartners-storage`, `vaspartners-logs`.

## Fayda local login

1. `FAYDA_REDIRECT_URI=http://localhost:3000/callback` (registered client redirect)
2. Next.js `/callback` forwards `code`/`state` to the API for PKCE + token exchange
3. After login, complete **company details**, then submit a service request

## Env (names)

**Fayda:** `FAYDA_CLIENT_ID`, `FAYDA_REDIRECT_URI`, `FAYDA_AUTH_URL`, `FAYDA_TOKEN_URL`, `FAYDA_USERINFO_URL`, `FAYDA_PRIVATE_KEY`, `FAYDA_ALG`, `FAYDA_ASSERTION_TYPE`, `FAYDA_EXPIRATION_TIME`

**App:** `FRONTEND_URL`, `MAX_OPEN_TICKETS`, `NEXT_PUBLIC_API_URL`, `DB_EMULATE_PREPARES` (for PgBouncer)

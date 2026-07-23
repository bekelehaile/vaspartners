# VAS Partners

Rebuild of Ethio Telecom **MVAS Partners Portal**.

| Layer | Stack | Path |
|-------|--------|------|
| Customer portal | Next.js 15 | `frontend/` |
| API + admin | Laravel 12 + Filament 5 + Sanctum | `backend/` |
| Partner auth | Fayda (eSignet) — same pattern as fixedservices; creates `customers` on sign-in | `backend/app/Services/EsignetService.php` |
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
# 1) Fayda secrets in backend/.env (never commit the private key)
cp backend/.env.example backend/.env
# edit FAYDA_* + ensure APP_KEY is set (or let container generate it)

cp frontend/.env.example frontend/.env.local

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

Seeded admin: `admin@vaspartners.local` / `password`  
Fayda sandbox FIN/FAN: `3126894653473958` or `6230247319356120` — OTP: `111111`

Useful commands:

```bash
docker compose exec backend php artisan migrate:fresh --seed
docker compose exec backend php artisan queue:work
docker compose logs -f backend frontend
```

Compose overrides DB/Redis hosts inside containers (`pgbouncer`, `redis`). Your `backend/.env` Fayda values are still loaded.

## Fayda local login

1. `FAYDA_REDIRECT_URI=http://localhost:3000/callback` (registered client redirect)
2. Next.js `/callback` forwards `code`/`state` to the API for PKCE + token exchange
3. After login, complete **company details**, then submit a service request

## Env (names)

**Fayda:** `FAYDA_CLIENT_ID`, `FAYDA_REDIRECT_URI`, `FAYDA_AUTH_URL`, `FAYDA_TOKEN_URL`, `FAYDA_USERINFO_URL`, `FAYDA_PRIVATE_KEY`, `FAYDA_ALG`, `FAYDA_ASSERTION_TYPE`, `FAYDA_EXPIRATION_TIME`

**App:** `FRONTEND_URL`, `MAX_OPEN_TICKETS`, `NEXT_PUBLIC_API_URL`, `DB_EMULATE_PREPARES` (for PgBouncer)

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
  → Supervisor assigns Account Manager (in_progress)
  → AM verifies documents
  → Approver chain via manager_id until final approver
  → completed → closed
  ↘ rejected → fix docs → re-verify
```

## Database

**New scalable design** (not a copy of legacy schema). See [`docs/SCHEMA.md`](docs/SCHEMA.md).

Highlights:
- Configurable **request types** (`new`, `move`, `upgrade`, `downgrade`, `terminate`, `relocate`, `maintenance`, `renew`, `other`, …)
- Per service × request type **document** and **final approver** matrix
- **Subscriptions** with yearly / bi-yearly renewal until terminate
- Append-only history: assignments, document reviews, approval steps, status transitions
- String enums + ULID public IDs + queue indexes

Seed catalog locally:

```bash
cd backend
php artisan migrate
php artisan db:seed
```

## Quick start

### Backend

```bash
cd backend
cp .env.example .env   # set DB + FAYDA_* + FRONTEND_URL
composer install
php artisan migrate
php artisan make:filament-user
php artisan serve
```

Admin: `http://localhost:8000/admin`

### Frontend

```bash
cd frontend
cp .env.example .env.local
npm install
npm run dev
```

Portal: `http://localhost:3000`

### Fayda local login

1. Backend `.env` must include `FAYDA_*` (see `.env.example`). Private key stays on the API only.
2. Registered redirect for the local client is `http://localhost:3000/callback` — the Next.js `/callback` page forwards `code`/`state` to the API.
3. Start API + portal, then open the site and use **Continue with Fayda**.
4. Test FIN/FAN (sandbox): `3126894653473958` or `6230247319356120` — OTP: `111111`

Fayda login hits `GET /api/v1/auth/fayda/redirect` then returns to `/auth/callback` with a Sanctum token. There is **no signup** — Fayda userinfo is stored in `customers` on first sign-in; the partner then completes **company details** before submitting requests.

## Env (names)

**Fayda:** `FAYDA_CLIENT_ID`, `FAYDA_REDIRECT_URI`, `FAYDA_AUTH_URL`, `FAYDA_TOKEN_URL`, `FAYDA_USERINFO_URL`, `FAYDA_PRIVATE_KEY`, `FAYDA_ALG`, `FAYDA_ASSERTION_TYPE`, `FAYDA_EXPIRATION_TIME`

**App:** `FRONTEND_URL`, `MAX_OPEN_TICKETS`, `NEXT_PUBLIC_API_URL`

Set `FAYDA_REDIRECT_URI` to `{API_URL}/api/v1/auth/fayda/callback`.

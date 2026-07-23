# VAS Partners

Rebuild of Ethio Telecom **MVAS Partners Portal**.

| Layer | Stack | Path |
|-------|--------|------|
| Client portal | Next.js 15 | `frontend/` |
| API + admin | Laravel 12 + Filament 5 + Sanctum | `backend/` |
| Partner auth | Fayda (eSignet) — same pattern as fixedservices | `backend/app/Services/EsignetService.php` |
| Staff auth | Filament login (`/admin`) | Filament Shield RBAC |

## Workflow (unchanged)

```
Client creates ticket (open)
  → Supervisor assigns Account Manager (in_progress)
  → AM verifies documents
  → Approver chain via manager_id until final approver
  → completed → closed
  ↘ rejected → fix docs → re-verify
```

## Database

**New scalable design** (not a copy of legacy schema). See [`docs/SCHEMA.md`](docs/SCHEMA.md).

Highlights:
- Normalized requirement matrix & final approvers (no JSON blobs)
- Append-only history: assignments, document reviews, approval steps, status transitions
- Dedicated `ticket_documents`
- String enums + ULID public IDs + queue indexes

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

Fayda login hits `GET /api/v1/auth/fayda/redirect` then returns to `/auth/callback` with a Sanctum token.

## Env (names)

**Fayda:** `FAYDA_CLIENT_ID`, `FAYDA_REDIRECT_URI`, `FAYDA_AUTH_URL`, `FAYDA_TOKEN_URL`, `FAYDA_USERINFO_URL`, `FAYDA_PRIVATE_KEY`, `FAYDA_ALG`, `FAYDA_ASSERTION_TYPE`, `FAYDA_EXPIRATION_TIME`

**App:** `FRONTEND_URL`, `MAX_OPEN_TICKETS`, `NEXT_PUBLIC_API_URL`

Set `FAYDA_REDIRECT_URI` to `{API_URL}/api/v1/auth/fayda/callback`.

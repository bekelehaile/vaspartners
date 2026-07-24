# MVAS dump migration commands

Repeatable migration from the old **mvasportal** MySQL `.dump` (+ attachment files) into **VAS Partners** staging.

## Prerequisites

Staging stack running (`./deploy-staging.sh`). Compose mounts:

| Host path | Container path |
|---|---|
| `/data-disk/applications/mvasportal/dumps` | `/mvas-dumps` (ro) |
| `/data-disk/applications/mvasportal/mvasportal/storage/app` | `/mvas-storage` (ro) |

Default dump: `/mvas-dumps/mvas_20260724_090806.dump`

Schema migrations (once per environment):

```bash
docker exec vaspartners-app php artisan migrate --force
```

Catalog + staff users must already be seeded (`CatalogSeeder`, `MvasStaffUsersSeeder`).

---

## Quick start (recommended)

Wipe previous import, then full migrate (companies → customers → tickets → subscriptions → approvers → attachments):

```bash
# From repo root
./scripts/migrate-mvas-staging.sh

# Or explicitly
docker exec vaspartners-app php artisan vas:migrate-mvas-dump \
  --fresh --force \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --storage=/mvas-storage
```

Dry-run (no writes):

```bash
./scripts/migrate-mvas-staging.sh --dry-run
```

Re-run **without** wipe (idempotent skips via `legacy_mvas_*` columns):

```bash
./scripts/migrate-mvas-staging.sh --no-fresh
```

---

## Commands

### `vas:migrate-mvas-dump`

Full pipeline: optional clear → seed → enrich.

| Option | Meaning |
|---|---|
| `--dump=` | Path to `.dump` (default env `MVAS_DUMP_PATH` or `/mvas-dumps/…`) |
| `--storage=` | Old `storage/app` for attachments (default `MVAS_STORAGE_PATH` or `/mvas-storage`) |
| `--fresh` | Clear previous migrated data first |
| `--force` | Required with `--fresh` |
| `--keep-approvers` | When clearing, do not wipe `service_final_approvers` |
| `--company-limit=` / `--ticket-limit=` / `--attachment-limit=` | Caps for partial runs |
| `--ids=` | Comma-separated legacy `clients.id` allowlist |
| `--only-verified` | Only `is_verified_client=1` |
| `--include-ethio-telecom` | Include company_name ethio telecom |
| `--skip-attachments` / `--skip-approvers` / `--skip-subscriptions` | Skip enrich steps |
| `--link-memberships` | Link customer as owner during seed (**off** by default) |
| `--dry-run` | Report only |

### `vas:clear-mvas-migration`

Delete previously migrated rows so import can be repeated.

```bash
docker exec vaspartners-app php artisan vas:clear-mvas-migration --dry-run
docker exec vaspartners-app php artisan vas:clear-mvas-migration --force
```

| Option | Meaning |
|---|---|
| `--force` | Required to delete |
| `--dry-run` | Count only |
| `--keep-approvers` | Keep `service_final_approvers` |
| `--keep-files` | Keep attachment files on disk |

**Clears (legacy-tagged only):** ticket documents (+ files), tickets, subscriptions, memberships, customers, companies, and (by default) final approvers.

### `vas:seed-mvas-dump`

Lower-level seed + enrich (same as migrate without the clear step). Useful when data is already empty.

```bash
docker exec vaspartners-app php artisan vas:seed-mvas-dump \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --storage=/mvas-storage
```

| Option | Meaning |
|---|---|
| `--only-enrich` | Skip companies/customers/tickets; only subs/approvers/attachments |
| `--skip-companies` / `--skip-customers` / `--skip-tickets` | Partial seed |
| `--skip-subscriptions` / `--skip-approvers` / `--skip-attachments` | Partial enrich |
| other | Same limits / filters as `vas:migrate-mvas-dump` |

### `vas:pilot-import-mvas-companies`

Small pilot import of **ownerless companies only** (no tickets/customers). Prefer the full migrate commands above for staging demos.

```bash
docker exec vaspartners-app php artisan vas:pilot-import-mvas-companies \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --limit=25
```

---

## What gets imported

| Source (MVAS dump / storage) | Target (VAS Partners) | Notes |
|---|---|---|
| `clients` | `companies` | Approved, **ownerless**; provisional TIN `MVAS-{id}` |
| `clients` | `customers` | Provisional Fayda `sub` = `mvas-client-{id}`; **no** company profile fields |
| `tickets` | `tickets` | Status mapped; catalog service/requisition by legacy id |
| Completed “New subscription” tickets | `subscriptions` | Active, company-scoped; manage tickets get `subscription_id` |
| `service_approvers` | `service_final_approvers` | Staff matched by email (`MvasStaffUsersSeeder`) |
| `fileables` + `files` + `/mvas-storage` | `ticket_documents` | Copied under `storage/app/private/tickets/{public_id}/` |

Companies stay ownerless until:

1. **Fayda login** — phone (or legacy client id) matches one ownerless company → auto-claim owner, or  
2. **Admin** — Companies → **Orphan (no owner)** → **Assign owner** after verification.

---

## Typical repeat cycle

```bash
# 1) Preview wipe
docker exec vaspartners-app php artisan vas:clear-mvas-migration --dry-run

# 2) Wipe + full import
./scripts/migrate-mvas-staging.sh

# 3) Spot-check
docker exec vaspartners-app php artisan tinker --execute='
echo "companies=".App\Models\Company::whereNotNull("legacy_mvas_client_id")->count()."\n";
echo "orphans=".App\Models\Company::whereNotNull("legacy_mvas_client_id")->whereDoesntHave("memberships", fn($q)=>$q->where("role","owner"))->count()."\n";
echo "customers=".App\Models\Customer::whereNotNull("legacy_mvas_client_id")->count()."\n";
echo "tickets=".App\Models\Ticket::whereNotNull("legacy_mvas_ticket_id")->count()."\n";
echo "subs=".App\Models\Subscription::count()."\n";
echo "docs=".App\Models\TicketDocument::whereNotNull("legacy_mvas_file_id")->count()."\n";
echo "approvers=".App\Models\ServiceFinalApprover::count()."\n";
'
```

Admin UI: https://vaspartnersportal.ethiotelecom.et:8443/admin  
(Companies → Orphan / Tickets / Subscriptions / ticket Documents)

---

## Env overrides (optional)

| Variable | Purpose |
|---|---|
| `MVAS_DUMP_PATH` | Default dump path inside container |
| `MVAS_STORAGE_PATH` | Default old `storage/app` path |
| `VASPARTNERS_APP_CONTAINER` | Container name for `./scripts/migrate-mvas-staging.sh` (default `vaspartners-app`) |

---

## Related code

- `app/Console/Commands/MigrateMvasDumpCommand.php`
- `app/Console/Commands/ClearMvasMigrationCommand.php`
- `app/Console/Commands/SeedMvasDumpCommand.php`
- `app/Services/Migration/MvasDumpMigrationService.php`
- `app/Services/Migration/MvasDumpEnrichmentService.php`
- `app/Services/Migration/MvasDumpClearService.php`
- `scripts/migrate-mvas-staging.sh`

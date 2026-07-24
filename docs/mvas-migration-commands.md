# MVAS dump migration commands

Repeatable migration from old **mvasportal** (MySQL `.dump` + attachment files) into **VAS Partners**.

## Commands (only these two)

| Command | Purpose |
|---|---|
| `vas:clear-mvas-migration` | Wipe previous migrated rows (safe to re-run import) |
| `vas:migrate-mvas-dump` | Optional clear → seed companies/customers/tickets → enrich subs/approvers/attachments |

Host helper:

```bash
./scripts/migrate-mvas-staging.sh              # --fresh --force by default
./scripts/migrate-mvas-staging.sh --dry-run
./scripts/migrate-mvas-staging.sh --no-fresh   # idempotent re-run, no wipe
```

## Prerequisites

```bash
./deploy-staging.sh
docker exec vaspartners-app php artisan migrate --force
```

Compose mounts (staging):

| Host | Container |
|---|---|
| `…/mvasportal/dumps` | `/mvas-dumps` |
| `…/mvasportal/mvasportal/storage/app` | `/mvas-storage` |

Default dump: `/mvas-dumps/mvas_20260724_090806.dump`  
Catalog + staff users must already be seeded.

## Full migrate (recommended)

```bash
docker exec vaspartners-app php artisan vas:migrate-mvas-dump \
  --fresh --force \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --storage=/mvas-storage
```

### `vas:migrate-mvas-dump` options

| Option | Meaning |
|---|---|
| `--dump=` | `.dump` path (`MVAS_DUMP_PATH`) |
| `--storage=` | Old `storage/app` (`MVAS_STORAGE_PATH`) |
| `--fresh` | Clear migrated data first |
| `--force` | Required with `--fresh` |
| `--keep-approvers` | When clearing, keep `service_final_approvers` |
| `--company-limit=` / `--ticket-limit=` / `--attachment-limit=` | Caps |
| `--ids=` | Legacy `clients.id` allowlist |
| `--only-verified` | Verified clients only |
| `--include-ethio-telecom` | Include ethio telecom rows |
| `--skip-attachments` / `--skip-approvers` / `--skip-subscriptions` | Skip enrich steps |
| `--link-memberships` | Seed-time owner link (**off** — use Fayda / admin Assign owner) |
| `--dry-run` | Report only |

### `vas:clear-mvas-migration` options

```bash
docker exec vaspartners-app php artisan vas:clear-mvas-migration --dry-run
docker exec vaspartners-app php artisan vas:clear-mvas-migration --force
```

| Option | Meaning |
|---|---|
| `--force` | Required to delete |
| `--dry-run` | Count only |
| `--keep-approvers` | Keep final approvers |
| `--keep-files` | Keep attachment files on disk |

Clears legacy-tagged: documents (+ files), tickets, subscriptions, memberships, customers, companies, approvers (default).

## What is imported

| Source | Target | Notes |
|---|---|---|
| `clients` | `companies` | Approved, **ownerless**; TIN `MVAS-{id}` |
| `clients` | `customers` | `sub` = `mvas-client-{id}`; no company profile until claim |
| `tickets` | `tickets` | Status + catalog mapped |
| Completed new-subscription tickets | `subscriptions` | Manage tickets linked via `subscription_id` |
| `service_approvers` | `service_final_approvers` | Staff by email |
| `fileables` / `files` + storage | `ticket_documents` | Under `tickets/{public_id}/` |

**Ownership**

1. Fayda login — phone / legacy id matches one ownerless company → auto-claim  
2. Else admin — Companies → **Orphan (no owner)** → **Assign owner**

Company view tabs: Members · **Service requests** · Subscriptions · Change requests

## Spot-check

```bash
docker exec vaspartners-app php artisan tinker --execute='
echo "companies=".App\Models\Company::whereNotNull("legacy_mvas_client_id")->count()."\n";
echo "orphans=".App\Models\Company::whereNotNull("legacy_mvas_client_id")->whereDoesntHave("memberships", fn($q)=>$q->where("role","owner"))->count()."\n";
echo "tickets=".App\Models\Ticket::whereNotNull("legacy_mvas_ticket_id")->count()."\n";
echo "subs=".App\Models\Subscription::count()."\n";
echo "docs=".App\Models\TicketDocument::whereNotNull("legacy_mvas_file_id")->count()."\n";
'
```

Admin: https://vaspartnersportal.ethiotelecom.et:8443/admin

## Code map

- `MigrateMvasDumpCommand` / `ClearMvasMigrationCommand`
- `MvasDumpMigrationService` · `MvasDumpEnrichmentService` · `MvasDumpClearService`
- `MvasDumpTableReader` · `MvasDumpClientReader` · `MvasStaffLegacyMap`
- `scripts/migrate-mvas-staging.sh`

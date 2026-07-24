# Pilot data-migration demo (MVAS `.dump` → VAS Partners)

> **Full / repeatable migration:** see [`mvas-migration-commands.md`](./mvas-migration-commands.md)  
> (`vas:migrate-mvas-dump`, `vas:clear-mvas-migration`, `./scripts/migrate-mvas-staging.sh`).

## Goal

Rehearse migrating a **small set of partner companies** from the old portal MySQL dump into staging so Fayda login can **auto-claim** by phone (last 9 digits), or fall back to **admin Assign owner**.

## Source of truth

Use a MySQL **`.dump`** file only (not live DB):

```text
/data-disk/applications/mvasportal/dumps/mvas_20260724_090806.dump
```

On staging the dump is mounted at `/mvas-dumps/` inside `vaspartners-app`.

Pilot reads `INSERT INTO \`clients\`` from that file.

| MVAS `clients` | VAS Partners `companies` |
|---|---|
| `id` | `legacy_mvas_client_id` |
| `company_name` (fallback `name`) | `name` |
| `phone` (last 9) | `phone` |
| `email` | `email` |
| `address` + `city` + `country` | `address` |
| _(no TIN/license in dump)_ | provisional `MVAS-{id}` / `MVAS-LIC-{id}` |
| — | `approval_status=approved`, `is_active=true`, **no owner** |

## Out of scope for this pilot

- Tickets / subscriptions / documents (use full migrate commands instead)
- Creating portal owners at seed time (Fayda claim or admin Assign owner)
- Staff users (already covered by `MvasStaffUsersSeeder`)
- Password hashes from dump

## Demo script (companies only)

```bash
# 1) Ensure schema
docker exec vaspartners-app php artisan migrate --force

# 2) Dry-run
docker exec vaspartners-app php artisan vas:pilot-import-mvas-companies \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --limit=25 \
  --dry-run

# 3) Import pilot set (skips company_name = "ethio telecom" by default)
docker exec vaspartners-app php artisan vas:pilot-import-mvas-companies \
  --dump=/mvas-dumps/mvas_20260724_090806.dump \
  --limit=25

# 4) Fayda claim demo
#    - Login with Fayda phone matching one imported company.phone (last 9)
#    → auto owner
#    - Unmatched phone → partner submits company → admin approval
#    - Orphan company → Admin → Companies → Orphan → Assign owner
```

## Success criteria

1. Importer accepts **only** a `.dump` path.
2. Pilot companies appear in Admin → Companies as **Approved**, with phone, **no owner**.
3. Matching Fayda phone → ownership claimed automatically.
4. Non-matching Fayda phone → admin approval / Assign owner path.
5. Re-running import is idempotent (`legacy_mvas_client_id` / phone skip).

## Full migration

For companies + customers + tickets + subscriptions + approvers + attachments, use:

```bash
./scripts/migrate-mvas-staging.sh
```

Details: [`mvas-migration-commands.md`](./mvas-migration-commands.md).

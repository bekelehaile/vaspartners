# MVAS → VAS Partners migration

Complete runbook to take a **fresh dump** from the old **mvasportal** MySQL database, migrate companies / contacts / tickets / subscriptions / approvers / **customer service attachments**, and verify they work in **Filament admin** and the **customer portal**.

Use this before shutting down old MVAS so VAS Partners is the system of record.

---

## Overview

| Step | What | Where |
|---|---|---|
| 1 | Fresh `mysqldump` (`./scripts/dump-mvas.sh`) | Host → `mvasportal/dumps/` |
| 2 | Staging mounts dump + old `storage/app` | Docker `/mvas-dumps`, `/mvas-storage` |
| 3 | `vas:migrate-mvas-dump --fresh` (`./scripts/migrate-mvas-staging.sh`) | Wipes prior MVAS rows, seeds + enriches |
| 4 | Attachments copied to **`public` disk** | `storage/app/public/tickets/{public_id}/…` |
| 5 | Host backup (`./scripts/backup-mvas-attachments.sh`) | `vaspartners/backups/mvas-attachments-*` |

**Ownership is not assigned during migrate.** Migrated companies stay orphan (no owner) until:

1. Partner signs in (Fayda / phone OTP) and auto-claims by phone / legacy id, or  
2. Admin: **Companies → Orphan (no owner) → Assign owner**

---

## Prerequisites

```bash
cd /data-disk/applications/vaspartners
./deploy-staging.sh
docker exec vaspartners-app php artisan migrate --force
# Catalog + staff users must exist (CatalogSeeder / MvasStaffUsersSeeder)
```

Compose mounts (staging `compose.staging.yml`):

| Host | Container |
|---|---|
| `/data-disk/applications/mvasportal/dumps` | `/mvas-dumps` |
| `/data-disk/applications/mvasportal/mvasportal/storage/app` | `/mvas-storage` (read-only) |
| Docker volume `vaspartners-storage` | `/var/www/html/storage/app` (app files) |

Default dump path in helper script: `/mvas-dumps/mvas_20260729_080126.dump`  
Override with `MVAS_DUMP_PATH` / `MVAS_STORAGE_PATH`.

---

## 1. Fresh MySQL dump from MVAS

Credentials: `/data-disk/applications/mvasportal/.env` (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).  
Prior dumps used host `10.190.149.247`, database `mvas`.

**Preferred (helper):**

```bash
cd /data-disk/applications/vaspartners
./scripts/dump-mvas.sh
# prints path + next migrate commands
```

**Manual equivalent:**

```bash
STAMP=$(date +%Y%m%d_%H%M%S)
OUT="/data-disk/applications/mvasportal/dumps/mvas_${STAMP}.dump"

MYSQL_PWD='…' mysqldump \
  -h 10.190.149.247 -P 3306 -u mvas_user \
  --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  mvas > "$OUT"

ls -lah "$OUT"
head -n 8 "$OUT"   # expect: Host … Database: mvas
```

Then point the helper at the new file:

```bash
# edit scripts/migrate-mvas-staging.sh default DUMP=…
# or:
export MVAS_DUMP_PATH=/mvas-dumps/mvas_${STAMP}.dump
```

**Do not delete** old `mvasportal/storage/app` until attachment verification and host backup are done — migrate reads files from `/mvas-storage`.

---

## 2. Run migration (staging)

### Helper (recommended)

```bash
./scripts/migrate-mvas-staging.sh              # --fresh --force (wipe + full import)
./scripts/migrate-mvas-staging.sh --dry-run    # counts only
./scripts/migrate-mvas-staging.sh --no-fresh   # idempotent re-run (skip wipe)
```

### Direct artisan

```bash
docker exec vaspartners-app php artisan vas:migrate-mvas-dump \
  --fresh --force \
  --dump=/mvas-dumps/mvas_20260729_080126.dump \
  --storage=/mvas-storage
```

### Pipeline

| Phase | Action |
|---|---|
| `[1/3] Clear` | With `--fresh --force`: delete prior `legacy_mvas_*` companies, contacts, tickets, subscriptions, documents (+ files), approvers |
| `[2/3] Seed` | `clients` → companies + contacts; `tickets` → tickets |
| `[3/3] Enrich` | Subscriptions, final approvers, **attachments** |

### `vas:migrate-mvas-dump` options

| Option | Meaning |
|---|---|
| `--dump=` | Path to `.dump` (`MVAS_DUMP_PATH`) |
| `--storage=` | Old MVAS `storage/app` (`MVAS_STORAGE_PATH`) |
| `--fresh` | Clear previous migrated data first |
| `--force` | Required with `--fresh` |
| `--keep-approvers` | On clear, keep `service_final_approvers` |
| `--company-limit=` / `--ticket-limit=` / `--attachment-limit=` | Caps |
| `--ids=` | Legacy `clients.id` allowlist |
| `--only-verified` | Verified clients only |
| `--include-ethio-telecom` | Include ethio telecom company rows |
| `--skip-attachments` / `--skip-approvers` / `--skip-subscriptions` | Skip enrich steps |
| `--link-memberships` | Link contacts as owners at seed (**off** by default) |
| `--dry-run` | Report without writing |

### Re-import attachments only (no wipe)

```bash
docker exec vaspartners-app php artisan vas:migrate-mvas-dump \
  --dump=/mvas-dumps/mvas_20260729_080126.dump \
  --storage=/mvas-storage \
  --skip-subscriptions \
  --skip-approvers
```

Idempotent: rows with existing `legacy_mvas_file_id` are skipped; new ticket files are added.

### Clear only

```bash
docker exec vaspartners-app php artisan vas:clear-mvas-migration --dry-run
docker exec vaspartners-app php artisan vas:clear-mvas-migration --force
# --keep-approvers  --keep-files
```

---

## 3. What is imported

| Source (MVAS dump / storage) | Target (VAS Partners) | Notes |
|---|---|---|
| `clients` | `companies` | Approved, **ownerless**; TIN `MVAS-{id}`; `legacy_mvas_id` |
| `clients` | `contacts` | `sub` = `mvas-contact-{id}`; `legacy_mvas_id`; **`is_active=true`** (ignore old MVAS flag) |
| `tickets` | `tickets` | Status mapped (MVAS completed/approved → **`closed`**); catalog mapped; `legacy_mvas_ticket_id` |
| Completed new / additional / revenue / merchant tickets | `subscriptions` | One active sub per company+service; see below |
| `service_approvers` | `service_final_approvers` | Staff matched by email |
| `fileables` + `files` + `/mvas-storage` | `ticket_documents` | Ticket morph only; see attachments below |

Soft-deleted / partnerless tickets are skipped (attachments for those tickets count as orphaned).

### Subscriptions derived from tickets

Old MVAS had no reliable `client_service` data (pivot empty in dumps). VAS Partners builds subscriptions from **completed/closed** tickets whose requisition has `creates_subscription`:

| Requisition | Creates subscription |
|---|---|
| `new` | yes |
| `additional-services` | yes |
| `revenue-request` | yes (API / MT often onboarded this way) |
| `merchant-acoount` | yes |
| maintenance, whitelist, terminate, … | no (manage / end only) |

Rule: **one active subscription per company + service** (earliest activation ticket wins; later tickets link to it).  
Example: phone `943177956` had 4 tickets but only 3 services (API ×2, MT, Voice Premium) → 3 subscriptions.

### Portal sign-in readiness (important)

Old MVAS `clients.is_active` usually stays `0` even for verified partners who signed in there. VAS Partners portal (phone OTP + Fayda) rejects inactive contacts.

Guarantees so this does not block partners again:

1. **Seed** always creates migrated contacts with `is_active=true` (banned clients are not imported).  
2. **Re-run / skip** repairs any leftover inactive non-banned migrated contacts (`portal_activated` in the migrate summary).  
3. **Post-migrate check** fails the command if any non-banned migrated contact is still inactive.  
4. **Phone OTP verify** activates non-banned contacts on successful code check (same as Fayda).  
5. Use **Ban** in admin for permanent portal blocks — not Deactivate alone (migrate / OTP may re-activate).

---

## 4. Attachments (customer service documents)

### Storage layout (Filament + portal compatible)

| Field | Value |
|---|---|
| `disk` | **`public`** |
| `path` | `tickets/{ticket.public_id}/{uuid}.{ext}` |
| Physical path | `storage/app/public/tickets/…` |
| Symlink | `php artisan storage:link` → `public/storage` |

Same layout as new portal uploads (`TicketDocumentService` also uses `public`).

| Consumer | How files are opened |
|---|---|
| **Filament admin** | Ticket → Attachments → Open / Download (`TicketDocumentController`, `Storage::disk($doc->disk)`) |
| **Customer portal** | Request detail → Download (`GET /api/v1/tickets/{tt}/documents/{id}/download`) |

Migration keeps **every** historical ticket file (not one-per-document-type). Portal UI lists multiple files per document type when present.

### Copy behaviour

1. Stream `files` + ticket `fileables` from the dump.  
2. Resolve path under `/mvas-storage` (strips Unicode bidi marks in filenames when needed).  
3. `Storage::disk('public')->put(...)` and create `ticket_documents` with `legacy_mvas_file_id`.  
4. Skip if `legacy_mvas_file_id` already exists.  
5. Skip if source file missing on disk or ticket was not migrated.

### Host backup (recommended before MVAS shutdown)

**Preferred (helper):**

```bash
./scripts/backup-mvas-attachments.sh
# → vaspartners/backups/mvas-attachments-YYYYMMDD_HHMMSS/
```

**Manual equivalent:**

```bash
BACKUP="/data-disk/applications/vaspartners/backups/mvas-attachments-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP"
docker cp vaspartners-app:/var/www/html/storage/app/public/tickets "$BACKUP/"
find "$BACKUP/tickets" -type f | wc -l
du -sh "$BACKUP/tickets"
```

Example taken on staging: `backups/mvas-attachments-20260729_082040` (~10,880 files, ~6.1G).

### Typical gap reasons

| Gap | Meaning |
|---|---|
| `missing_file` | Path in dump not present under old `storage/app` |
| `orphaned` | `fileables` for a ticket that was not imported (deleted / no partner) |
| `skipped` | Already imported (`legacy_mvas_file_id`) |

---

## 5. Last successful staging run (reference)

**Dump:** `mvas_20260729_080126.dump` (from `10.190.149.247` / `mvas`, ~62M)

| Entity | Imported (approx.) |
|---|---|
| companies | 5025 (all orphan) |
| contacts | 5025 |
| tickets | 2044 |
| subscriptions | 434 |
| approvers | 92 |
| attachments | 10880 on `public` disk |

After migrate, if admin shows **502**, reload nginx (stale upstream after container recreate):

```bash
docker compose -f compose.staging.yml restart nginx
# or: docker compose -f compose.staging.yml exec -T nginx nginx -s reload
```

---

## 6. Spot-check

```bash
docker exec vaspartners-app php artisan tinker --execute='
echo "companies=".App\Models\Company::whereNotNull("legacy_mvas_id")->count()."\n";
echo "orphans=".App\Models\Company::whereNotNull("legacy_mvas_id")->whereDoesntHave("memberships", fn ($q) => $q->where("role", "owner"))->count()."\n";
echo "contacts=".App\Models\Contact::whereNotNull("legacy_mvas_id")->count()."\n";
echo "tickets=".App\Models\Ticket::whereNotNull("legacy_mvas_ticket_id")->count()."\n";
echo "subs=".App\Models\Subscription::whereNotNull("legacy_mvas_id")->count()."\n";
echo "docs=".App\Models\TicketDocument::whereNotNull("legacy_mvas_file_id")->count()."\n";

$ok = 0; $bad = 0;
foreach (App\Models\TicketDocument::whereNotNull("legacy_mvas_file_id")->cursor() as $d) {
    if ($d->disk === "public" && Illuminate\Support\Facades\Storage::disk("public")->exists($d->path)) {
        $ok++;
    } else {
        $bad++;
    }
}
echo "docs_on_public_disk=$ok missing_or_wrong_disk=$bad\n";
'
```

Manual UI checks:

1. Admin → open a migrated ticket → **Attachments** → Open / Download.  
2. Portal → sign in as claimed partner → request with docs → Download.  
3. Confirm `APP_MAINTENANCE_DRIVER=file` (not `redis` — Laravel only supports `file` / `cache`).

---

## 7. Shutdown checklist (old MVAS)

- [ ] Fresh dump taken and stored under `mvasportal/dumps/`  
- [ ] `vas:migrate-mvas-dump --fresh` completed on staging (then production when ready)  
- [ ] Spot-check counts + random Open/Download in Filament and portal  
- [ ] Host backup of `storage/app/public/tickets` under `vaspartners/backups/`  
- [ ] Partners can claim companies (Fayda / phone OTP / Assign owner)  
- [ ] Only then decommission old mvasportal app (keep dump + storage archive offline)

---

## 8. Code map

| Piece | Path |
|---|---|
| Fresh dump helper | `scripts/dump-mvas.sh` |
| Migrate helper | `scripts/migrate-mvas-staging.sh` |
| Attachment backup helper | `scripts/backup-mvas-attachments.sh` |
| Migrate command | `app/Console/Commands/MigrateMvasDumpCommand.php` |
| Clear command | `app/Console/Commands/ClearMvasMigrationCommand.php` |
| Seed | `app/Services/Migration/MvasDumpMigrationService.php` |
| Enrich (subs / approvers / attachments) | `app/Services/Migration/MvasDumpEnrichmentService.php` |
| Clear service | `app/Services/Migration/MvasDumpClearService.php` |
| Dump readers | `app/Support/Migration/MvasDumpTableReader.php`, `MvasDumpPartnerReader.php` |
| Staff email map | `app/Support/Migration/MvasStaffLegacyMap.php` |
| Portal upload (same disk) | `app/Services/TicketDocumentService.php` (`public`) |
| Admin open/download | `app/Http/Controllers/Admin/TicketDocumentController.php` |
| Portal download | `ContactPortalController::downloadDocument` |

---

## Related: ERCA TIN consolidation (after partners verify TIN)

Partners who create a **new ERCA company** leave subscriptions / bulk recipients on the old `MVAS-*` shell. Repair:

```bash
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --dry-run
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --force
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --dry-run
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --force
# optional: --tin=0024173476
```

Full runbook: [erca-mvas-consolidation.md](./erca-mvas-consolidation.md).

---

## Related: revenue partner phone sync (not MVAS dump)

Abay Service ID → phone fill for **Revenue partners** (separate from MVAS dump):

```bash
docker exec vaspartners-app php artisan vas:sync-revenue-partner-phones \
  database/data/revenue/abay_api_phones.json \
  --overwrite \
  --account-manager="abayneh mekonnen asfaw"
```

Filament: **Revenue partners → Sync phones** (CSV/Excel: Service ID + Phone).  
Source data: `database/data/revenue/abay_api_phones.csv` / `.json`.

---

Admin: https://vaspartnersportal.ethiotelecom.et:8443/admin  
Portal: https://vaspartnersportal.ethiotelecom.et:8443

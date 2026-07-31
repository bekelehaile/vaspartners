# ERCA TIN consolidation (MVAS placeholders → verified companies)

When partners create a **new ERCA-verified company** with a real 10-digit TIN, subscriptions and other rows often stay on the abandoned **`MVAS-{id}`** placeholder company from migration. These commands move that data onto the live verified company and retire the shell.

**Typical symptom:** closed “new subscription” request exists, but the verified company shows **no subscription** (it is still on `MVAS-*`).

---

## Overview

| Step | Command | What it does |
|---|---|---|
| 1 | `vas:remount-subscriptions-to-verified-tin` | Move **alive** subscriptions (`active` / `pending_renewal` / `grace`) from MVAS/unverified → TIN-verified company |
| 2 | `vas:consolidate-mvas-into-verified-tin` | Move leftover company-scoped rows, copy `legacy_mvas_id`, **soft-delete** the MVAS shell |

Both refuse to write unless `--force` is passed (use `--dry-run` to preview).

After ERCA create, the app also runs remount + consolidate automatically for the new company (`CompanyMembershipService::createApprovedCompanyFromErca`).

---

## Prerequisites

```bash
cd /data-disk/applications/vaspartners
docker exec vaspartners-app php artisan migrate --force
```

Target company must be:

- `tin_validated = true`
- `erca_tin_verified = true`
- TIN matches `^[0-9]{10}$` (real ERCA TIN, not `MVAS-*`)

---

## 1. Remount subscriptions

Moves alive subscriptions whose contact is owner (or activation ticket member) of a verified company, while the subscription still sits on an MVAS / unverified company. Skips if the verified company already has an alive sub for that service.

### Preview

```bash
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --dry-run
```

### Apply

```bash
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --force
```

### Options

| Option | Meaning |
|---|---|
| `--dry-run` | List moves without writing |
| `--force` | Required to write |

### Example (staging, 2026-07-31)

21 subscriptions remounted (e.g. Mella `0024173476` ← `MVAS-509`, sub `#1792` SMS Non-Premium).

---

## 2. Consolidate MVAS shells

Pairs MVAS placeholders to verified companies via:

1. **Legacy:** subscription on verified company has `legacy_mvas_id` matching the MVAS company’s `legacy_mvas_id`
2. **Creator:** MVAS `created_by_contact_id` is now **owner** of a verified company

Then for each pair:

| Data | Action |
|---|---|
| `subscriptions` (incl. soft-deleted) | `company_id` → verified |
| `company_memberships` | Move if contact not already on verified; else drop duplicate |
| `company_change_requests` | Re-point |
| `company_status_histories` | Re-point |
| `feedback` | Re-point |
| `revenue_partners` | Re-point |
| `bulk_message_recipients` | Re-point |
| `legacy_mvas_id` | Cleared on MVAS shell, copied to verified if free (unique index) |
| MVAS company | `is_active=false`, renamed `[Merged] …`, **soft-deleted** |
| History | `company_status_histories.action = mvas_consolidated` on verified |

### Preview (all pairs)

```bash
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --dry-run
```

### Preview / apply one TIN (e.g. Mella)

```bash
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --tin=0024173476 --dry-run
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --tin=0024173476 --force
```

### Apply all

```bash
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --force
```

### Options

| Option | Meaning |
|---|---|
| `--dry-run` | Preview without writing |
| `--force` | Required to write |
| `--tin=` | Only consolidate into this verified TIN |

### Example (staging, 2026-07-31)

22 pairs consolidated (including Mella `0024173476` ← `MVAS-509`): bulk recipients moved, legacy ids copied, shells soft-deleted. Subscriptions were already remounted in step 1.

---

## Recommended order

```bash
# 1) Preview
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --dry-run
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --dry-run

# 2) Apply
docker exec vaspartners-app php artisan vas:remount-subscriptions-to-verified-tin --force
docker exec vaspartners-app php artisan vas:consolidate-mvas-into-verified-tin --force
```

---

## Spot-check

```bash
docker exec vaspartners-app php artisan tinker --execute='
$tin = "0024173476"; // Mella example
$c = App\Models\Company::where("tin", $tin)->first();
echo "company={$c?->name} tin={$c?->tin} legacy={$c?->legacy_mvas_id}\n";
echo "subs=".$c?->subscriptions()->count()." members=".$c?->memberships()->count()."\n";
$old = App\Models\Company::withTrashed()->where("tin", "MVAS-509")->first();
echo "old_mvas deleted_at=".$old?->deleted_at." active=".($old?->is_active ? "1" : "0")."\n";
echo "remaining_pairs=".count(app(App\Services\ConsolidateMvasIntoVerifiedTinService::class)->discoverPairs())."\n";
'
```

Portal: sign in as owner → **Subscriptions** should list the service on the verified company.

---

## Subscription status note

Service **requests** use statuses including `closed`.  
**Subscriptions** use `active` … and after partner termination consent + approved terminate request → **`deactive`** (not `closed`).

---

## Code map

| Piece | Path |
|---|---|
| Remount command | `app/Console/Commands/RemountSubscriptionsToVerifiedTinCommand.php` |
| Remount service | `app/Services/RemountSubscriptionsToVerifiedTinService.php` |
| Consolidate command | `app/Console/Commands/ConsolidateMvasIntoVerifiedTinCommand.php` |
| Consolidate service | `app/Services/ConsolidateMvasIntoVerifiedTinService.php` |
| Auto on ERCA create | `app/Services/CompanyMembershipService.php` (`createApprovedCompanyFromErca`) |

Related: [MVAS dump migration](./mvas-migration-commands.md).

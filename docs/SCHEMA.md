# VAS Partners — Scalable Schema Design

Same **business workflow** as legacy MVAS; **new normalized schema** built for growth.

## Design principles

| Old MVAS | New design |
|----------|------------|
| JSON arrays for requirements / final approvers | Normalized pivot tables |
| Magic status IDs (`1..5`) | String-backed enums (`open`, `in_progress`, …) |
| Polymorphic fileables for ticket docs | Dedicated `ticket_documents` |
| Status buried on ticket only | Append-only `ticket_status_histories` |
| Silent assignment overwrite | `ticket_assignments` history |
| Approval flags on ticket row | `ticket_approval_steps` audit trail |
| Integer PKs only in APIs | ULID `public_id` on customers & tickets |
| Separate signup form | Fayda sign-in creates/refreshes `customers` (fixedservices pattern) |
| MySQL-centric soft-delete soup | Clear FKs, indexes for queue queries, soft deletes where needed |
| Hard-coded request types | Configurable `requisitions` + behavior flags |
| One-shot tickets only | `subscriptions` with yearly / bi-yearly renewal until terminate |

## Workflow (unchanged)

```
Customer creates → open
Supervisor assigns AM → in_progress
AM verifies documents → current_approver = AM.manager (if docs required)
Approver approve → escalate via manager_id until final approver → completed
Approver reject / doc-fail → rejected or back to AM
AM closes → closed
No required docs → AM may close without approval chain
```

## Configurable request types (`requisitions`)

Rows (not code enums) so ops can add types like **Other** without deploys:

| Code (seeded) | Typical behavior flags |
|---------------|------------------------|
| `new` | `creates_subscription` |
| `renew` | `requires_active_subscription` + `renews_subscription` |
| `move` / `upgrade` / `downgrade` / `relocate` / `maintenance` | `requires_active_subscription` |
| `terminate` | `requires_active_subscription` + `terminates_subscription` |
| `other` | none (docs still configurable) |

Enabled per service via `service_requisition`.

## Document matrix (per service × request type)

`document_types` + `service_requisition_documents` (`is_required`, `sort_order`).

Different request types require different docs — configured in Filament **Catalog → Services → Required documents**.

Final approvers: `service_final_approvers` (also per service × request type).

## Subscriptions & renewal

`services` (subscription-based):

- `renewal_interval`: `yearly` | `bi_yearly` (configurable per service)
- `renewal_lead_days`: when to open a renewal ticket before period end
- `renewal_requisition_id`: usually the `renew` request type

`subscriptions`:

- Created when a **new** ticket reaches `completed` (or `closed` without prior completion)
- Period extended when a **renew** ticket completes
- Ended when a **terminate** ticket completes — no further renewals
- Scheduler: `php artisan vas:open-due-renewals` (daily) opens renewal tickets in the lead window

Tickets may reference `subscription_id` / `parent_ticket_id`.

## Core tables

### Identity
- `users` — staff (Filament); `manager_id` hierarchy; `is_management` for supervisor notify scope
- `customers` — Fayda `sub` + verified userinfo on sign-in; **company details** completed afterwards (`profile_completed_at`)
- Spatie `roles` / `permissions` (Filament Shield)

### Catalog
- `categories` → `services` (incl. renewal policy)
- `requisitions` + `service_requisition`
- `document_types` + `service_requisition_documents`
- `service_final_approvers`
- `priorities`, `faqs`
- `regions` → `zones` → `woredas`
- `category_user` (staff category scope)

### Lifecycle
- `subscriptions` — active entitlement + renewal window
- `tickets` — current state snapshot (+ optional `subscription_id`)
- `ticket_documents`, `ticket_comments`
- `ticket_assignments`, `ticket_document_reviews`, `ticket_approval_steps`, `ticket_status_histories`

## Queue indexes

- Recent: `(status, assigned_to_user_id)` where status=`open` and assignee null
- My tickets: `(assigned_to_user_id, current_approver_user_id, status)`
- Approval: `(current_approver_user_id, status)`
- Customer list: `(customer_id, status, created_at)`
- Renewals due: `(status, current_period_end)` on `subscriptions`

## Admin configuration (Filament)

- **Catalog → Services** — enable request types, renewal interval, doc matrix, final approvers
- **Catalog → Request types** — behaviors for new / renew / terminate / custom
- **Catalog → Document types** — reusable doc catalog
- **Partners → Customers** — Fayda identity + company profile; relation managers for tickets, subscriptions, services
- **Partners → Subscriptions** — read-only lifecycle view

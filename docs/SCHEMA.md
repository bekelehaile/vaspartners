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
| Integer PKs only in APIs | ULID `public_id` on clients & tickets |
| MySQL-centric soft-delete soup | Clear FKs, indexes for queue queries, soft deletes where needed |

## Workflow (unchanged)

```
Client creates → open
Supervisor assigns AM → in_progress
AM verifies documents → current_approver = AM.manager (if docs required)
Approver approve → escalate via manager_id until final approver → completed
Approver reject / doc-fail → rejected or back to AM
AM closes → closed
No required docs → AM may close without approval chain
```

## Core tables

### Identity
- `users` — staff (Filament); `manager_id` hierarchy; `is_management` for supervisor notify scope
- `clients` — partners; Fayda `sub`; Sanctum tokens
- Spatie `roles` / `permissions` (Filament Shield)

### Catalog
- `categories` → `services`
- `requisitions` + `service_requisition`
- `document_types` + `service_requisition_documents` (required/optional matrix)
- `service_final_approvers` (who can complete for service+requisition)
- `priorities`, `faqs`
- `regions` → `zones` → `woredas`
- `category_user` (staff category scope)

### Tickets & events
- `tickets` — current state snapshot (status, assignee, current_approver, doc_review_status)
- `ticket_documents` — one row per uploaded file + doc type
- `ticket_comments`
- `ticket_assignments` — assign/reassign history
- `ticket_document_reviews` — AM pass/fail events
- `ticket_approval_steps` — each approve/reject in the chain
- `ticket_status_histories` — every status transition

## Queue indexes

- Recent: `(status, assigned_to_user_id)` where status=`open` and assignee null
- My tickets: `(assigned_to_user_id, current_approver_user_id, status)`
- Approval: `(current_approver_user_id, status)`
- Client list: `(client_id, status, created_at)`

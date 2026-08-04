# VAS Partners — Product scope: Done vs Left

Product-owner requirements for the VAS portal, split into **what is delivered in the current phase** and **what belongs to the next-phase project**.

**Framing**

| Current phase | Next phase |
|---|---|
| Partner digital entry + MVAS request ops + monthly revenue notify | Commercial config, disputes, settlement engine, ERP / Oracle payables |

The live product is **not** yet an enterprise settlement / ERP platform.

---

## Done (current phase)

### Partner registration & onboarding

| Capability | Notes |
|---|---|
| OTP SMS verification | Login / phone ownership via SMS OTP |
| Company onboarding via ERCA TIN | TIN search + consent → create/link company |
| Partner profile & membership management | Company workspace, members, ownership, multi-company |
| Service catalog + request wizard | MT SMS, API, CRBT, USSD, and other catalog services |
| Document upload & AM verification | Legal/required docs attached to service requests |

### Portal operational & management

| Capability | Notes |
|---|---|
| Service request tickets | Statuses: Pending → In progress → Completed / Rejected / Closed |
| Approval chain (new subscriptions) | When a final approver is configured for service + request type |
| Subscriptions & renewals | Lifecycle + renewal scheduling |
| Admin AM tools | My Tickets, All tickets, handler workload, handler performance |
| Bulk SMS / partner messaging | Campaigns and ticket contact SMS |
| Company change requests | Ownership / membership workflows |
| Status history (partial audit) | Ticket, company, and approval history |
| Subscription lifecycle & uptime (admin) | Admin: uptime status, provisioning logs, linked service orders. Staff-reported uptime until probes exist. Partner portal not exposed yet. |
| Membership permission audit (admin) | Admin company **Permission audit** tab for permission / access changes. Partner portal not exposed yet. |

### Finance (light)

| Capability | Notes |
|---|---|
| Revenue partner master | Service ID / short code for finance matching |
| Monthly revenue Excel/CSV import | Import, match, SMS notify |
| Partner portal revenue ledger | Read-only view of matched revenue |

---

## Left (next-phase project)

### Onboarding gaps

| Capability | Notes |
|---|---|
| Classic self-service signup with legal docs at registration | Docs today are collected on service requests, not at signup |
| Dedicated Merchant ID / corporate identifier registry | Merchant Account is a request type today, not a registry |
| Automated OSS/BSS provisioning | Today: ticket workflow → subscription record only |

### Operational gaps

| Capability | Notes |
|---|---|
| End-subscriber (consumer) account management | Partner company/contact/subscription only |
| Configurable revenue share & payment channels | No commercial-terms module yet |
| External uptime probes / OSS provisioning telemetry | Admin staff-reported uptime + workflow provisioning logs only |
| Dispute management module | Feedback exists; not billing/service disputes |
| Partner-facing lifecycle / permission audit UI | Admin-only for now |

### Settlement, reconciliation & billing

| Capability | Notes |
|---|---|
| CBS / MA transactional data ingestion | No CBS/MA connectors |
| CDR / charging interfaces | Not built |
| Comparison parameters | e.g. transaction ID, service code, gross value |
| Variance analysis & threshold / exception handling | Not built |
| Automated revenue-share calculation | Amounts today come from Excel import, not calculated splits |
| Multi-level approval (Finance → VAS BO → RA) | Not built |
| ERP vendor invoice generation | Not built |
| Oracle iSupplier / AP payment requisition & disbursement | Not built |

---

## Suggested focus

**Keep building now**

- Harden onboarding and ticket journeys
- AM ops / workload visibility
- Revenue import quality and partner linking
- Partner UX on requests and subscriptions

**Park for next phase**

- Revenue-share and payment-channel config
- Disputes + permission-change audit
- CBS/CDR reconciliation engine
- Finance / VAS BO / RA → ERP → Oracle AP

---

## Related docs

- [SCHEMA.md](./SCHEMA.md) — data model overview
- [erca-mvas-consolidation.md](./erca-mvas-consolidation.md) — ERCA / MVAS consolidation notes
- [mvas-migration-commands.md](./mvas-migration-commands.md) — migration commands

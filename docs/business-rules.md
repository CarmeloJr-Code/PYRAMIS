# PYRAMIS Business Rule Registry

Authoritative rules that affect more than one module. If an implementation detail conflicts with a rule here, the rule wins — stop and clarify rather than resolve it silently. Referenced from `CLAUDE.md`.

- **BR-001** — Customers do not require accounts.
- **BR-002** — Customers select a pickup outlet when ordering.
- **BR-003** — The main branch handles general operations: sales, expenses, inventory, production, and workforce management.
- **BR-004** — Outlets receive finished products from the main branch; they do not independently perform production.
- **BR-005** — Bakers handle production and outlet-restock preparation.
- **BR-006** — Cashiers handle sales, customer orders, and expenses.
- **BR-007** — Administrators manage overall operations, workforce, outlet restocking, reports, and forecasting.
- **BR-008** — AI forecasts are decision-support recommendations, never guaranteed predictions.
- **BR-009** — AI does not independently execute operational decisions: no auto-approving orders, auto-spending, auto-scheduling, or direct writes to inventory/production records.
- **BR-010** — Internal communication is text-only. No voice, video, photo messaging, or external messaging-platform integration.
- **BR-011** — There is no door-to-door delivery.
- **BR-012** — Customer-facing functionality must never expose employee-only operational information.

## Why these exist

BR-001–BR-002 and BR-011 keep the customer ordering flow from silently growing scope (accounts, delivery) that the approved PRD explicitly excludes. BR-003–BR-007 encode the actual Purple Yam Malaybalay operating structure — the system must model the business as it really runs, not a generic multi-outlet retailer. BR-008–BR-010 bound the AI and communication features to decision-support and text-only, respectively. BR-012 is a security/authorization boundary as much as a business rule.

## Source

PYRAMIS PRD (§6, §9, §12, §15, §17, §24) and PYRAMIS Vibe Coding Development Rules (§2, §7, §10). Full text in `docs/reference/`.

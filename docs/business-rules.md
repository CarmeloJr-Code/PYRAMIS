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
- **BR-013** — Online pre-orders are paid by GCash up front. An order is Confirmed only after a cashier checks that the exact total was received; a short or over payment leaves it Pending. (Added at the owner's request, after the PRD.)
- **BR-014** — Texts (SMS, through UniSMS) go out for four events only: an employee is assigned to a shift, a shift they are on changes time or outlet, they are taken off a shift, and a customer's order becomes Ready for pickup. A text only reports a change already made — a failed text never blocks or undoes it — and an employee with no mobile number on file is not texted. This is one-way notification, not the internal chat BR-010 governs. (Added at the owner's request, after the PRD.)
- **BR-015** — Bakers see customer pre-orders once a cashier has confirmed them, and may move one to Preparing and then to Ready for pickup — those two steps only. Checking payment, handing over (Completed) and cancelling stay with the Cashier and Administrator (BR-006), and the Baker is not shown the customer's phone, email or payment. (Added at the owner's request, after the PRD.)
- **BR-016** — A recipe is only baked from once an Administrator has approved it. A recipe a Baker saves — new, or a change to one already approved — waits as Pending, and the Administrator approves it or sends it back with a reason; a recipe sent back waits again once the Baker saves a correction. A recipe an Administrator saves is approved as saved. A size whose recipe is Pending or sent back cannot have a production run logged. Recipes written before this rule stay approved. (Added at the owner's request, after the PRD.)

## Why these exist

BR-001–BR-002 and BR-011 keep the customer ordering flow from silently growing scope (accounts, delivery) that the approved PRD explicitly excludes. BR-003–BR-007 encode the actual Purple Yam Malaybalay operating structure — the system must model the business as it really runs, not a generic multi-outlet retailer. BR-008–BR-010 bound the AI and communication features to decision-support and text-only, respectively. BR-012 is a security/authorization boundary as much as a business rule.

## Source

PYRAMIS PRD (§6, §9, §12, §15, §17, §24) and PYRAMIS Vibe Coding Development Rules (§2, §7, §10). Full text in `docs/reference/`.

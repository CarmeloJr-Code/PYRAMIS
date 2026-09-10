# PYRAMIS Prototype Evaluation

Phase 16. The capstone names two evaluation dimensions — **functionality** and **usability** (PRD §19 NFR-01, §25). This document evaluates the built system against both, and says plainly which claims are evidenced and which are not.

## What this evaluation is, and is not

Every finding below is traced to something checkable: a test that runs, a schema constraint, a measurement taken. Nothing is asserted because it seems true of the screens.

That method settles functionality well and usability only partly. Whether a feature works as specified, whether a business rule holds under a crafted request, whether a report's figures match the transactions behind them — these are questions a test can answer. Whether a cashier finds the order queue on their first shift is not. **No usability testing with real staff has been conducted.** The usability section therefore evaluates the properties NFR-01 actually names — navigation, role-scoped dashboards, consistent components, understandable labels, responsive interfaces, straightforward workflows — each of which is observable, and marks the three aspects that are not.

Evidence base at the time of writing: **519 automated tests** across 58 files — 511 running against the application directly, 8 driving a real Chrome. The suite runs against SQLite locally and Postgres 17 in CI, matching the Supabase engine.

---

## Functionality

The implementation plan sets five criteria (Phase 16). Each is taken in turn.

### 1. Features work as specified

All eleven functional requirements are implemented and covered.

| Requirement | Where it lives | Evidence |
|---|---|---|
| FR-01 Authentication & Access Control | Fortify, passkeys, 2FA, employee records | `Auth/*` (19), `Settings/SecurityTest` (6), `EmployeeRecordsTest` (13), `MakeEmployeeCommandTest` (10) |
| FR-02 Sales Management | Counter sales, sales from collected orders | `SalesTest` (11), `CounterSaleTest` (13), `SaleStockTest` (7) |
| FR-03 Customer Ordering | Storefront, pre-order, tracking, cashier queue | `Storefront/*` (26), `OrderQueueTest` (14) |
| FR-04 Inventory Management | Ingredient ledger, usage, finished goods | `InventoryTest` (16), `IngredientUsageTest` (10), `FinishedGoodsStockTest` (10) |
| FR-05 Production Management | Recipes, production runs | `ProductionRunTest` (12), `RecipeTest` (11) |
| FR-06 Outlet Management | Outlet records, restocking, outlet performance | `OutletManagementTest` (7), `RestockTest` (16) |
| FR-07 Workforce Management | Shifts, assignments, own roster | `ShiftSchedulingTest` (17), `MyScheduleTest` (6) |
| FR-08 Expense Management | Expenses and categories | `ExpenseTest` (13) |
| FR-09 Internal Communication | Text-only employee chat | `InternalMessagingTest` (14) |
| FR-10 Reports and Analytics | Dashboard and six reports | `ReportsTest` (19), `DashboardMetricsTest` (8), `DashboardTest` (3) |
| FR-11 AI-Assisted Forecasting | Deterministic outlook, AI reading | `DemandOutlookTest` (18), `ForecastReadingTest` (22) |

Counts are test methods; several expand into more cases through data providers.

**One deviation from spec, documented rather than hidden.** The PRD named OpenAI GPT-5 Nano for the AI reading. No OpenAI key could be obtained, so the reading runs on Groq's `openai/gpt-oss-20b`, the nearest model that provider serves. The provider and model are named only on `ForecastReadingAgent`, so the substitution is one file wide. Every deterministic figure on the forecast screen is computed in PHP and SQL and is unaffected.

### 2. Business rules are enforced

All twelve rules in the registry hold, and each is enforced server-side rather than by the interface alone.

| Rule | Enforced where | Evidence |
|---|---|---|
| BR-001 Customers need no account | No `customer` role exists in `UserRole`; the storefront has no auth | `UserRoleTest`, `PlaceOrderTest`, `CustomerJourneyTest` |
| BR-002 Pickup outlet chosen when ordering | `outlet_id` required, and must be an active outlet | `PlaceOrderTest` — missing outlet and closed outlet both refused |
| BR-003 Main branch runs operations | Production is refused anywhere else | `ProductionRunTest`, `BusinessWorkflowTest` |
| BR-004 Outlets receive, never produce | Restock is the only path onto an outlet shelf | `ProductionRunTest`, `RestockTest`, `BakerJourneyTest` |
| BR-005 Bakers: production and restock prep | `access-production`, `access-inventory`, `access-restocking` | `RoleAuthorizationTest` ability matrix |
| BR-006 Cashiers: sales, orders, expenses | `access-sales`, `manage-orders`, `access-expenses` | `RoleAuthorizationTest` ability matrix |
| BR-007 Administrators: oversight | `access-workforce`, `access-forecasting`, `manage-*` | `RoleAuthorizationTest` ability matrix |
| BR-008 AI recommends, never predicts | Screen states it in words; confidence falls back to Low | `ForecastReadingTest`, `DemandOutlookTest`, `AdministratorJourneyTest` |
| BR-009 AI writes nothing | Reading is generated from a prepared brief and stored apart | `ForecastReadingTest`, `DemandOutlookTest` — both assert no business record changes |
| BR-010 Chat is text-only | `messages` has one `text` column; the application has no upload path at all | Schema; verified by absence — no `WithFileUploads`, no `store()`, no `UploadedFile` anywhere |
| BR-011 No delivery | No address, status, courier or shipping concept exists | Verified by absence across app, migrations and storefront |
| BR-012 Customers see no employee data | Storefront exposes catalogue and one order by reference | `ProductCatalogTest`, `OrderTrackingTest` |

BR-010 and BR-011 are evidenced by absence rather than by a test. That is the honest form of the claim: there is nothing to disable, because the capability was never built.

### 3. Data is stored correctly

- **Referential integrity is declared, not assumed.** Every foreign key carries an explicit delete rule — `restrictOnDelete` where a record has history worth keeping, `cascadeOnDelete` for lines owned by a parent, `nullOnDelete` for the read marker on a conversation. Natural uniqueness is constrained at the database: one recipe per size, one line per size per order, one participant per conversation, one assignment per employee per shift.
- **Quantities are derived, never overwritten.** Ingredient stock and finished-goods stock are the sum of their movement ledgers, so any figure can be traced to the transactions that produced it. `InventoryTest` proves stock survives editing the ingredient itself.
- **Money and quantities avoid float drift.** Amounts are integer centavos, quantities integer thousandths, because the production image ships no bcmath. `OrderItemTest` and `SaleItemTest` pin the rounding at the values where a bare cast loses a centavo.
- **Multi-write operations are atomic.** Every action that writes more than one row runs in a transaction. The only actions without one are the forecast pair, which write nothing by design.
- **Status transitions are narrow.** Orders and restocks move one step forward or to cancelled, never backwards and never reopened; enforced on the model, so no crafted request can skip ahead.

### 4. Roles have correct permissions

This is the criterion with the most direct evidence. `RoleAuthorizationTest` asserts the capability matrix twice over:

- **Every ability against every role** — 12 abilities × 3 roles, checked against the gates themselves, so a failure names the rule that is wrong rather than the page that refused.
- **Every guarded screen against every role** — all 47 of the 48 employee routes, opened as each role and asserted 200 or 403. The one exception is the conversation thread, which is scoped to its participants and covered by `InternalMessagingTest`.

Guests are redirected from every screen, and a deactivated account is turned away whatever role it holds.

### 5. Reports reflect operational data

`ReportsTest` checks each report against the transactions behind it: takings grouped by day exclude voided sales, stock movement separates receipts from usage, only delivered restocks count toward what an outlet received, and outlet performance combines takings, restocks and spending on one row.

`BusinessWorkflowTest` then walks the whole chain through the real screens — customer pre-order, production run, ingredient draw-down, restock, outlet sale, report, forecast — and asserts the figure a report shows is the one the forecast reads.

---

## Usability

NFR-01 names six properties. Four are observable and evidenced; two are partly so.

| Property | Assessment | Evidence |
|---|---|---|
| Clear navigation | **Met.** The sidebar lists only what the signed-in role may open, so navigation and authorization cannot disagree. | `RoleAuthorizationTest`, `DashboardTest` |
| Role-specific dashboards | **Met.** Each role's dashboard is scoped to their work — a cashier sees the counter and not the kitchen, a baker the reverse, and both see their own next shift. | `DashboardMetricsTest` |
| Consistent interface components | **Met.** One component library (Flux) throughout; the Phase 14 pass aligned four page headers that had drifted from the shared pattern. | Phase 14 change set |
| Understandable labels | **Met, with a caveat.** Roles are shown in the portal-design vocabulary staff use — Manager, Production Staff, Sales Staff — never the system's own words. Whether the rest of the wording reads clearly to staff is not something the system can answer about itself. | `UserRoleTest` |
| Responsive interfaces | **Met, and measured.** 22 screens open at 375×812 in a real browser with no horizontal overflow. | `HardeningTest` |
| Straightforward workflows | **Met for the four critical journeys.** Each completes end to end in a real browser, and the database is asserted afterwards. | The four journey tests |

The plan lists six usability aspects. Three are covered above as navigation, clarity and workflow. The remaining three:

- **Task completion — evidenced.** All four role journeys complete: a customer browses, orders and tracks without an account; a cashier walks a pre-order to collection and the sale appears without being typed twice; a baker logs a bake and sets a restock aside; a manager crosses the workspace from dashboard to forecast.
- **Error recovery — evidenced for the system's half.** Every slice test covers invalid input alongside the happy path: the form comes back with the message rather than losing the work, and nothing partial is written. Whether a user then recovers is not evidenced.
- **Learnability — not evidenced.** This cannot be established without users. It is the clearest gap in this evaluation.

---

## What the evaluation found

An evaluation that finds nothing has usually not looked. Phases 13–15 each found real defects, all fixed:

1. **The forecast disagreed with the catalogue** (Phase 13). `BuildForecastContext` filtered on variant availability alone, so sizes of a product withdrawn from sale still appeared under "what to consider baking" — the storefront and the counter both read the parent's `is_active` and the outlook did not.
2. **The session cookie was not marked `Secure`** (Phase 14). Behind Render's TLS termination, the cookie identifying a signed-in employee could be returned over plain HTTP.
3. **The inventory screen scrolled sideways on a phone** (Phase 14), by 123px, because a three-button header could not wrap. Three further screens shared the header and were one button from the same fault.
4. **The roster's week arrows had no accessible name** (Phase 14) — icon-only buttons announce as "button", and they are the only way off the current week.

Phase 14 also confirmed a performance result worth stating: query counts are flat. Every listing, dashboard and report was measured at two data sizes; the dashboard costs 11 queries with 20 sales behind it and 11 with 80. That is now asserted rather than measured once.

---

## Limitations

Stated so the evaluation is not read as stronger than it is.

1. **No user testing.** Learnability, perceived ease of use and overall satisfaction are unevaluated. Establishing them needs staff at Purple Yam Malaybalay performing task scenarios, most usefully with an instrument scoring each NFR-01 property.
2. **Performance measured at seeded scale, not production volume.** Constant query counts hold up to four times the seeded data; no load or soak testing was performed, and the Render free plan has not been measured under concurrent use.
3. **The AI reading depends on a third-party service.** Groq availability, rate limits and model behaviour are outside the system. The screen degrades to the deterministic outlook when no key is configured, which is tested — but the quality of the readings themselves is not evaluated here, and by BR-008 they are explicitly not predictions.
4. **Postgres behaviour is gated in CI, not locally.** Local runs use SQLite; index and query behaviour specific to Postgres is only exercised on CI against Postgres 17.
5. **Accessibility was checked, not audited.** Icon-only controls, `lang`, form labelling and viewport behaviour were verified. No screen-reader pass or WCAG conformance audit was carried out.

---

## Verdict

Against the functionality criteria the system passes on all five: every functional requirement is implemented and covered, all twelve business rules hold under test, the schema constrains what the code assumes, the capability matrix is enforced on all 47 guarded screens, and the reports agree with the transactions and with the forecast that reads them.

Against usability it meets every property NFR-01 names, on the evidence a system can produce about itself — and the three aspects that need people to judge them remain open. That gap is the honest conclusion of this phase, not an oversight in it.

## Source

PYRAMIS PRD §19 (NFR-01), §25; Modified Vertical Slice Implementation Plan Phase 16. Full text in `docs/reference/`. Business rules: `docs/business-rules.md`. Capability matrix: `docs/roles-and-permissions.md`.

# PYRAMIS Roles and Permissions

## Role vocabulary mapping

The capstone manuscript and the unified portal design use two different vocabularies for the same roles. Use one mapping everywhere — do not let both drift into the codebase separately.

| System role (use in code: `administrator`, `baker`, `cashier`, `customer`) | Portal-design terminology |
|---|---|
| Administrator | Manager |
| Cashier | Sales Staff |
| Baker | Production Staff |
| Customer | Customer |

## Per-role responsibilities

**Administrator**
- Business dashboard and oversight
- Workforce scheduling and assignments
- Outlet restock scheduling and monitoring
- Reports and AI-assisted forecasting
- Full sales, orders, inventory, production, and expense visibility

**Baker (Production Staff)**
- Production planning and logging
- Ingredient/inventory updates and usage recording
- Product stock updates
- Outlet restock preparation

**Cashier (Sales Staff)**
- Sales transactions
- Customer order management (confirm, update status, mark complete/cancelled)
- Expense recording

**Customer**
- No authentication required
- Browse products, place pre-orders, select a pickup outlet, track order status
- No access to any employee-side data or screens (see BR-012 in `docs/business-rules.md`)

## Capability matrix

| Capability | Administrator | Baker | Cashier | Customer |
|---|:---:|:---:|:---:|:---:|
| Business Dashboard | ✓ | Limited | Limited | ✗ |
| Sales Management | ✓ | ✗ | ✓ | ✗ |
| Customer Orders | ✓ | ✗ | ✓ | ✓ (own order only) |
| Inventory | ✓ | ✓ | ✗ | ✗ |
| Production | ✓ | ✓ | ✗ | ✗ |
| Outlet Restock | ✓ | ✓ | ✗ | ✗ |
| Workforce Management | ✓ | ✗ | ✗ | ✗ |
| Expense Management | ✓ | ✗ | ✓ | ✗ |
| Reports | ✓ | Relevant subset | Relevant subset | ✗ |
| AI Forecasting | ✓ | ✗ | ✗ | ✗ |
| Internal Chat | ✓ | ✓ | ✓ | ✗ |
| Product Browsing | ✗ (not the point of their workspace) | ✗ | ✗ | ✓ |

"Limited"/"Relevant subset" means the exact fields and reports shown should be scoped to what that role needs operationally — finalize the precise fields per screen when building that slice, following FR-01's acceptance criteria (a user can only access functions permitted by their role) and BR-012.

## Implementation notes

- Enforce authorization server-side on every protected action, not just in navigation/UI (Development Rules §7).
- Do not expose a record just because a user can manipulate a URL, request parameter, or form field.
- An unauthenticated visitor must never reach an employee-only route.

## Source

PYRAMIS PRD §4, §7, §18; Unified Business Web Portal Design; Vertical Slice Implementation Plan Phase 1. Full text in `docs/reference/`.

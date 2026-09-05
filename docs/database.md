# PYRAMIS Data Model Notes

The exact schema is finalized per vertical slice (design it right before implementing the feature that needs it), not assumed upfront. This file tracks the entity relationships the PRD anticipates, and what actually exists in the repo today, so an agent doesn't design a table that duplicates or conflicts with one already implemented.

## Current state (verified against `database/migrations/`)

Only the stock Laravel starter-kit tables exist: `users` (+ two-factor columns), `cache`, `jobs`, `passkeys`. No PYRAMIS business tables have been created yet — this repo is still Phase 0.

## Anticipated entities (from the PRD's data requirements)

```text
Users / Employees
      │
      ├── Roles
      └── Schedules

Products
      │
      ├── Sales
      └── Customer Orders

Ingredients / Inventory
      │
      └── Production

Production
      │
      └── Outlet Restocking

Outlets
      │
      ├── Sales
      ├── Expenses
      └── Restocking

Expenses

Reports / Analytics

Forecasting Data
      │
      └── AI-Assisted Insights
```

## Design guardrails (from the Development Rules)

- Use appropriate primary keys, foreign keys, indexes, and constraints; preserve referential integrity.
- Validate at the application layer, and use database constraints where they matter (e.g. non-negative stock, required fields).
- Do not duplicate data unnecessarily — e.g. don't store a denormalized product price on every order line if a price-at-time-of-order snapshot pattern is the actual requirement; make that decision explicitly when building the order slice, not by accident.
- Wrap multi-step, must-succeed-or-fail operations (e.g. production consuming ingredients and producing stock) in database transactions.
- Every stock-changing action (restock, production usage, manual adjustment) should leave an auditable transaction record — inventory quantities should be traceable, not just overwritten in place (Vertical Slice Plan, Phase 5).
- Migrations are the only authoritative mechanism for schema changes.
- No casual destructive database changes.

## Source

PYRAMIS PRD §21; Vibe Coding Development Rules §6; Modified Vertical Slice Implementation Plan Phase 5–7. Full text in `docs/reference/`.

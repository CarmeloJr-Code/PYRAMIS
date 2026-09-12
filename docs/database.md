# PYRAMIS Data Model Notes

The exact schema is finalized per vertical slice (design it right before implementing the feature that needs it), not assumed upfront. This file maps what exists in the repo today, so an agent doesn't design a table that duplicates or conflicts with one already implemented.

## As built (verified against `database/migrations/`, 34 migrations, 24 models)

Every business table below is in production on Supabase. Column-level detail lives in the migrations and the `@property` blocks on each model; this is the map.

```text
users ─ role (administrator | baker | cashier), is_active, two-factor, passkeys
  │
  ├── shift_assignments ──▶ shifts ──▶ outlets
  ├── conversation_participants ──▶ conversations ◀── messages
  └── recorded_by / requested_by / prepared_by / delivered_by on every ledger row

categories ──▶ products ──▶ product_variants (name, price, is_available)
                                   │
                  ┌────────────────┼──────────────────┬──────────────────┐
                  ▼                ▼                  ▼                  ▼
            order_items       sale_items        recipes (1:1)     restock_items
                  │                │                  │                  │
                  ▼                ▼                  ▼                  ▼
               orders ─────────▶ sales          recipe_items      restocks ──▶ outlets
          (reference, outlet,  (reference,           │           (Requested → Preparing
           customer, pickup_at, outlet, order_id,     ▼            → Delivered | Cancelled)
           status)             status, sold_at)   ingredients
                                                  (unit, reorder_level)
                                                       │
                                                       ▼
                                             inventory_movements ◀── production_runs
                                             (type, quantity 12,3,     (reference, variant,
                                              production_run_id)        recipe, quantity)
                                                                              │
                                                                              ▼
                                                              product_stock_movements
                                                              (variant × outlet, type,
                                                               integer quantity,
                                                               production_run_id | restock_id | sale_id)

expense_categories ──▶ expenses ──▶ outlets
```

**The two ledgers.** Nothing stores a stock level. Ingredient stock is the sum of `inventory_movements` for the ingredient (decimal 12,3 — weighed and measured); finished-goods stock is the sum of `product_stock_movements` for a variant at an outlet (integer — counted). Each movement names what caused it: a production run, a restock, a sale, or a recorded receipt/usage/adjustment. Only a sale may take an outlet's shelf below zero (`ProductStockMovementType::mayGoNegative`), because a sale is money that changed hands and refusing it would lose the takings rather than fix the count; every other movement is refused when it would overdraw, and the resulting negative is the signal that a bake or delivery went unrecorded.

**Money and references.** Prices are snapshotted onto `order_items.unit_price` and `sale_items.unit_price` at the time of the line, so a later price change does not rewrite history. Orders, sales, production runs and restocks each carry a human-readable `reference`. Quantities on order, sale and restock lines are integers.

**Delete rules.** Every foreign key declares one. Ledger and history rows restrict deletion of what they reference (an ingredient with movements, a category with expenses, a variant with sale lines); ownership links cascade (a recipe's items, a conversation's messages). The audit in `docs/evaluation.md` §3 lists them.

**Outlets.** One row carries `is_main_branch`; it is where production lands and where restocks are drawn from. Others receive finished goods only.

**Framework tables.** `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `passkeys`, `migrations`. Sessions, cache and queue all use the database in production (`render.yaml`).

**Not tables.** Roles are an enum column on `users`, not a table. Reports and the forecast read the ledgers; they store nothing. The AI reading is not persisted (`docs/ai-forecasting.md`).

## Design guardrails (from the Development Rules)

- Use appropriate primary keys, foreign keys, indexes, and constraints; preserve referential integrity.
- Validate at the application layer, and use database constraints where they matter (e.g. non-negative stock, required fields).
- Do not duplicate data unnecessarily — e.g. don't store a denormalized product price on every order line if a price-at-time-of-order snapshot pattern is the actual requirement; make that decision explicitly when building the order slice, not by accident.
- Wrap multi-step, must-succeed-or-fail operations (e.g. production consuming ingredients and producing stock) in database transactions.
- Every stock-changing action (restock, production usage, manual adjustment) should leave an auditable transaction record — inventory quantities should be traceable, not just overwritten in place (Vertical Slice Plan, Phase 5).
- Migrations are the only authoritative mechanism for schema changes.
- No casual destructive database changes.

## Source

PYRAMIS PRD §21; Vibe Coding Development Rules §6; Modified Vertical Slice Implementation Plan Phase 5–7; the migrations themselves. Full text in `docs/reference/`.

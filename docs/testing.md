# PYRAMIS Testing Requirements

Every meaningful vertical slice ships with tests. A slice is not done because the page renders — see the Definition of Done below.

## What every slice must verify

1. The happy path works.
2. Invalid input is rejected correctly.
3. Unauthorized users cannot perform protected actions.
4. Important edge cases are handled (empty state, zero/negative quantities, unavailable products, conflicting schedules, etc.).
5. Database state is correct after the operation.
6. Existing functionality has not regressed.
7. UI behavior matches the approved workflow.
8. AI-assisted features (once Phase 12 exists) handle missing or insufficient data gracefully.

## Test types

- **Unit tests** — isolated business logic: price calculations, inventory calculations, forecast data preparation, permission checks, status transitions.
- **Feature tests** — complete workflows: customer places an order, cashier confirms it, baker records production, inventory changes accordingly, administrator views the report.
- **Authorization tests** — one test per role per protected action, verifying both the allowed and the restricted cases (Administrator → allowed, Cashier/Baker/Customer → restricted, per action).
- **Browser/UI tests** — critical journeys per role (customer: browse → select → order → submit → track; cashier: login → view orders → process → record sale; baker: login → view production → record → prepare restock; administrator: login → dashboard → workforce → restock → reports → forecast).

## Project conventions

- Use `php artisan make:test` (feature by default, `--unit` for unit tests); most tests should be feature tests.
- Use model factories in tests rather than manual model setup; check for existing factory states before adding new ones.
- Run the narrowest test set that covers the change (`php artisan test --compact --filter=...` or a file path) and rerun after each change to that area.
- Run `vendor/bin/pint --dirty --format agent` on touched PHP before finishing.

## When a test fails

1. Identify the root cause — don't patch around the symptom.
2. Fix the underlying issue.
3. Re-run the relevant tests.
4. Check for regressions elsewhere.

## Source

PYRAMIS Vibe Coding Development Rules §12; Modified Vertical Slice Implementation Plan §2 (Rule 3), Phase 15, Phase 18 checklist. Full text in `docs/reference/`.

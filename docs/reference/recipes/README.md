# Product recipes (source)

Recipes for Purple Yam Malaybalay products, as supplied by the business.

**Status: reference only. These are not application data.**

There is no recipes table, model, or migration, and no approved specification for one. Do not read this folder as evidence that a recipes module was scoped. The only mention of recipes anywhere in the specs is the Phase 6 objective in `../vertical-slice-implementation-plan.md`:

> Connect products, recipes/ingredients, production activities, and inventory.

## Why these are kept

Phase 6 (Production) has to model how a product consumes ingredients — production logging, ingredient usage, and finished-product output. These documents are the ground truth for what that schema needs to represent. They inform the design; they are not the design.

If and when recipes become structured data, that schema gets designed in the slice that needs it, per `../../database.md`:

> The exact schema is finalized per vertical slice (design it right before implementing the feature that needs it), not assumed upfront.

## What belongs here

Whatever form the recipes arrive in — scans, photos, `.docx`, spreadsheets, or markdown transcriptions. Transcribing a scan to markdown alongside the original is welcome; keep both.

Filenames: lowercase-kebab, named for the product, e.g. `ube-halaya-tart.md`.

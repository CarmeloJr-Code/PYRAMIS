# Product recipes (source)

Recipes for Purple Yam Malaybalay products, as supplied by the business.

**Status: reference only. These are not application data.**

The application does have recipes as structured data — `recipes` and `recipe_items`, one recipe per product variant, written by a Baker or Administrator under Production → Recipes. That schema was designed in the Phase 6 production slice, whose objective is the only mention of recipes in the specs (`../vertical-slice-implementation-plan.md`):

> Connect products, recipes/ingredients, production activities, and inventory.

This folder is not where those rows come from. Nothing reads it, nothing seeds from it, and it is not evidence that any further recipe feature — costing, scaling, printing, versioning — was scoped.

## Why these are kept

They are the ground truth for what the Phase 6 schema had to represent, and they are what a baker would transcribe into the Recipes screen on the live system. `IngredientSeeder` takes its ingredient names and purchasing units from them. They inform the design; they are not the design.

## What belongs here

Whatever form the recipes arrive in — scans, photos, `.docx`, spreadsheets, or markdown transcriptions. Transcribing a scan to markdown alongside the original is welcome; keep both.

Filenames: lowercase-kebab, named for the product, e.g. `ube-halaya-tart.md`.

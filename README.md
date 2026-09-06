# PYRAMIS

Centralized web-based bakery management and AI-assisted forecasting system for **Purple Yam Malaybalay**. One Laravel application, two branded experiences: the public storefront (**Purple Yam Malaybalay** — browse, pre-order, pick up, track) and the authenticated employee workspace (**PYRAMIS** — Administrator, Baker, Cashier).

## Start here

- **`CLAUDE.md`** — the operating contract for AI-assisted development in this repo (source-of-truth hierarchy, verified tech stack, role model, business-rule highlights, current phase). Read this first, every session.
- **`docs/`** — condensed, task-ready references: `architecture.md`, `business-rules.md`, `roles-and-permissions.md`, `modules.md` (phase roadmap), `database.md`, `testing.md`, `ai-forecasting.md`.
- **`docs/reference/`** — source material kept verbatim, that everything above is derived from: the approved capstone documents (PRD, design system, unified portal design, palette) plus `brand/` (logo sources and working files), `recipes/` (product recipes) and `forms/` (scans of the bakery's existing paperwork). The last three are reference only — not application data, and not specified features. Each has a README explaining its status.

## Stack

Laravel 13 · PHP 8.3+ · Livewire 4 + Flux UI · Tailwind CSS v4 · Laravel Fortify · PostgreSQL via Supabase · Render (Docker) for deployment · Laravel Boost for AI-agent tooling.

## Status

Phase 0 (Foundation) — see `docs/modules.md` for the full roadmap and what's next.

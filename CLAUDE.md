# PYRAMIS

Centralized bakery management + AI-assisted forecasting for **Purple Yam Malaybalay**. One Laravel app, two branded experiences:

- **Purple Yam Malaybalay** — public storefront: browse, pre-order, pick a pickup outlet, track status. No customer accounts, no delivery.
- **PYRAMIS** — authenticated employee workspace: Administrator, Baker, Cashier.

**Status:** Phases 0–13 done — employee auth and RBAC, the product catalogue and storefront, customer pre-orders through to sales, ingredients and production, outlet restocking, workforce scheduling, expenses, internal messaging, the dashboard and its reports, both halves of forecasting (the deterministic outlook and the AI reading on top of it), and the cross-module workflow validated end to end (`tests/Feature/Integration/`). Phases 14 and 15 are done too — the hardening pass (security headers, a secure session cookie, CSRF actually exercised, a responsive sweep, and a query budget no screen may exceed) and the systematic test pass (the ability and guarded-screen matrices, unit cover for the isolated arithmetic, and the four role journeys in a real browser, `tests/Browser/`). Phase 16 is done as well — the evidence-based assessment in `docs/evaluation.md`, which passes on all five functionality criteria and leaves learnability, error recovery and perceived ease of use open for want of user testing. Phase 17 is done — the system is live at https://pyramis.onrender.com, deploying from `main` on every commit, with migrations and the reference seed run at boot (Deployment section of `docs/architecture.md`). The roadmap is complete; what remains is operational — accounts, keys, and whatever user testing turns up.

## Verified stack

Checked against this repo — don't assume otherwise.

- Laravel 13, PHP `^8.4` (21 locked packages require `>=8.4`; both Dockerfile stages are `php8.4-alpine`)
- Livewire 4 + Flux UI (`livewire/flux`), Blade, Tailwind v4
- Auth: Fortify, plus passkeys (`@laravel/passkeys`)
- PostgreSQL via Supabase, through Eloquent
- Deploy: Render (`render.yaml`, `Dockerfile` committed)
- AI: Laravel AI SDK (`laravel/ai`) + Groq `openai/gpt-oss-20b`. The spec named OpenAI GPT-5 Nano; no OpenAI key could be obtained, and this is the nearest model Groq serves. Provider and model are named only by the attributes on `app/Ai/Agents/ForecastReadingAgent.php`; the vendor `config/ai.php` already reads `GROQ_API_KEY`, so nothing is published.
- Laravel Boost + MCP server wired up (`.mcp.json`)

Confirm package APIs against installed versions (`composer show --direct`, `package.json`) — never assume a major version.

## Source of truth, in order

1. Approved specs (`docs/`)
2. Approved design decisions and portal structure (`docs/architecture.md`, `docs/roles-and-permissions.md`)
3. Current implementation — inspect before editing
4. Capstone manuscript
5. Laravel / package docs
6. General technical knowledge

Never invent requirements, roles, permissions, or features outside approved scope. When requirements conflict or are ambiguous, stop and ask rather than guess.

Docs: `docs/architecture.md`, `business-rules.md`, `roles-and-permissions.md`, `modules.md` (phase roadmap), `database.md`, `testing.md`, `ai-forecasting.md`, `evaluation.md` (the Phase 16 assessment). Capstone sources verbatim in `docs/reference/`.

Brand/logo sources, product recipes, and scanned business forms live in `docs/reference/{brand,recipes,forms}/` — reference only, never served, and **not** evidence that a feature was scoped (there is no recipes table and no printable-forms requirement). Read each folder's README before building from it. Web-ready logo assets go in `resources/views/components/app-logo-icon.blade.php` (inline SVG) and `public/favicon.*`, not in `docs/`.

## Non-negotiable business rules

Full registry: `docs/business-rules.md`. The ones most often violated:

- Outlets receive **finished goods from the main branch** — they never run their own production.
- Customers **never** need an account. No delivery.
- AI only recommends — it never writes to inventory, production, orders, or schedules, is never presented as a guaranteed prediction, and deterministic math stays in PHP/SQL, not the LLM (`docs/ai-forecasting.md`).
- Internal chat is text-only.

## Roles — one vocabulary only

| System role | Portal-design term |
|---|---|
| Administrator | Manager |
| Cashier | Sales Staff |
| Baker | Production Staff |
| Customer | Customer |

Full capability matrix: `docs/roles-and-permissions.md`.

## Workflow

Dependency-aware vertical slices. One task = one business capability with its full stack (migration → model → business rules → authorization → Livewire → Blade → validation → tests). Not a whole module, not several unrelated ones.

- Inspect existing routes, controllers, components, models, and migrations for the area first; follow sibling structure and naming; reuse existing components.
- Validate and authorize server-side — never trust the client. Keep Livewire state server-side.
- Use `php artisan make:*` with `--no-interaction`, including `make:class`. New models get factories and seeders.
- Prefer named routes and `route()`; Eloquent API Resources + versioning for APIs.
- Run `vendor/bin/pint --dirty --format agent` on any touched PHP.
- No unrelated refactors, no new base folders, no new dependencies, no architecture changes without approval.
- Only write documentation files when asked. Be concise in replies.

## Testing

- Every code change adds or updates a test: happy path, invalid input, unauthorized access, edge cases, and resulting DB state — and nothing beyond.
- PHPUnit. Create with `php artisan make:test --phpunit SomeFeatureTest` (no suite dir in the name); most tests are feature tests. Use factories and their custom states.
- Run the narrowest set: `php artisan test --compact <path|--filter=testName>`, or `vendor/bin/phpunit` directly. Rerun after each change.
- Browser journeys (`tests/Browser/`) run on Dusk, outside `php artisan test`: build the front end, serve the app, then `composer test:browser`. See `docs/testing.md`.
- Read the `testing-best-practices` skill first. Don't write verification scripts or tinker for what tests already prove.

## Skills

Activate the matching skill in `.claude/skills/` as soon as you enter its domain — `fluxui-development`, `fortify-development`, `livewire-development`, `laravel-best-practices`, `tailwindcss-development`, `testing-best-practices`, `infer-conventions`, `supabase-postgres-best-practices`.

Provenance: `author: laravel` skills come from `php artisan boost:install`; third-party ones are pinned in `skills-lock.json` and updated with `npx skills update`. Install new ones scoped to Claude Code, or the CLI also writes a duplicate tree to `.agents/`:

```
npx -y skills add <repo-url> --skill <name> --agent claude-code --copy -y
```

`supabase-postgres-best-practices` scope guard — Supabase is hosted Postgres, reached over Eloquent through the Supavisor session pooler as a single user:

- No RLS, no Supabase Auth, no `service_role`/anon keys. Authorization is Laravel policies (`docs/roles-and-permissions.md`). Advisor warnings about RLS on the public schema are not approved requirements — ignore them.
- On Eloquent idiom, migrations, and N+1, `laravel-best-practices` wins. Use the Supabase skill for Postgres-level concerns: index type and shape, locking, pooling, `EXPLAIN`.
- Local test runs use SQLite `:memory:` (`phpunit.xml`); CI runs the same suite against Postgres 17 (`.github/workflows/tests.yml`), matching the Supabase engine. CI is the gate for Postgres-specific behaviour — index and query work can pass locally and still fail there, which is intended. Never point a test run at the Supabase project; `RefreshDatabase` would drop production tables.

## PHP style

- Curly braces always, even single-line bodies.
- Constructor property promotion; no empty zero-arg `__construct()` unless private.
- Explicit return types and parameter type hints everywhere.
- TitleCase enum keys. PHPDoc over inline comments; array shapes in PHPDoc.

## Laravel Boost (MCP)

Prefer Boost tools over shell equivalents: `database-schema` before writing migrations/models, `database-query` for read-only queries, `get-absolute-url` before sharing any URL, `browser-logs` for recent browser errors.

`search-docs` before changes that depend on ecosystem APIs, behavior, config, or version-specific syntax; skip for copy-only edits. Scope with `packages`; omit package names from queries. Use several broad topic queries (`['rate limiting', 'routing']`) — multiple queries are OR, words within one are stemmed AND, `"quoted phrases"` match adjacent words.

Record durable team rules with `record-rule` (`glob`, `title`, short `note`) rather than native memory. If `.ai/rules/` appears in the repo, read `.ai/rules/index.md` and every rule file whose globs cover the paths in scope before editing them (it does not exist yet).

## Gotchas

- Artisan: `php artisan route:list` (filter `--method`, `--name`, `--path`, `--except-vendor`), `php artisan config:show app.name`.
- Tinker: single-quote the outer `--execute` string, double quotes inside PHP.
- "Unable to locate file in Vite manifest" or a frontend change not showing → ask the user to run `npm run build` / `npm run dev` / `composer run dev`.
- Re-running `php artisan boost:install` regenerates a verbose `<laravel-boost-guidelines>` block at the top of this file and overwrites what follows. It also drops the `docs/reference/` asset conventions above. If that happens, re-compact rather than keeping the generated version.

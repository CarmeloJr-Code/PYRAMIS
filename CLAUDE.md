# PYRAMIS

Bakery management + AI-forecasting system for Purple Yam Malaybalay. One Laravel app, two brands: **Purple Yam Malaybalay** (public storefront — no accounts, no delivery) and **PYRAMIS** (employee workspace: Administrator, Baker, Cashier).

**Stack:** Laravel 13, PHP 8.3+, Livewire 4 + Flux UI, Tailwind v4, Fortify, Postgres/Supabase, Render deploy, Laravel Boost MCP. Confirm APIs against installed versions (`composer show --direct`, `package.json`) — never assume.

**Status:** Phase 0 done (starter kit + Docker/Render/Supabase/Boost). Next: Phase 1 — app shell, employee auth, RBAC.

## Source of truth

Approved specs (`docs/`) > current implementation > capstone manuscript > package docs. Never invent requirements, roles, or features — stop and ask if ambiguous.

Docs: `docs/architecture.md`, `business-rules.md`, `roles-and-permissions.md`, `modules.md` (phase roadmap), `database.md`, `testing.md`, `ai-forecasting.md`. Source PRDs verbatim in `docs/reference/`.

## Non-negotiable business rules

- Outlets receive finished goods from the main branch; they never produce their own.
- Customers never need accounts. No delivery.
- AI only recommends — it never writes to inventory/production/orders (`docs/ai-forecasting.md`).
- Internal chat is text-only.
- Roles: Administrator=Manager, Cashier=Sales Staff, Baker=Production Staff. Matrix in `docs/roles-and-permissions.md`.

## Workflow

Vertical slices — one capability, full stack per task (migration → model → rules → auth → Livewire → Blade → validation → tests).

- Inspect sibling files first and follow existing structure, naming, and conventions; reuse existing components.
- Don't add base folders, dependencies, or unrelated refactors without approval.
- Validate and authorize server-side (in Livewire actions as in HTTP requests); keep Livewire state server-side.
- Use `php artisan make:*` (with `--no-interaction`) for new files, including `make:class`. New models get factories and seeders.
- Prefer named routes and `route()`; Eloquent API Resources + versioning for APIs.
- Run `vendor/bin/pint --dirty --format agent` before finishing PHP changes.
- Only write documentation files when asked. Be concise in replies.

## Testing

- Every code change adds or updates a test; cover the changed behavior and its important failure modes (happy path, invalid input, unauthorized, edge cases) and nothing beyond.
- PHPUnit. Create with `php artisan make:test --phpunit SomeFeatureTest` (no suite dir in the name); most tests are feature tests. Use factories and their custom states.
- Run the narrowest set: `php artisan test --compact <path|--filter=testName>`, or `vendor/bin/phpunit` directly. Rerun after each change.
- Read the `testing-best-practices` skill before writing tests. Don't write verification scripts or tinker for what tests already prove.

## Skills

Activate the matching skill in `.claude/skills/` as soon as you enter its domain — `fluxui-development`, `fortify-development`, `livewire-development`, `laravel-best-practices`, `tailwindcss-development`, `testing-best-practices`, `infer-conventions`.

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

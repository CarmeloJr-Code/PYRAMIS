# PYRAMIS Architecture

## Product structure

One Laravel application, two branded experiences:

```text
                    PYRAMIS
                       │
        ┌──────────────┴──────────────┐
        │                             │
   CUSTOMER SIDE                 EMPLOYEE SIDE
        │                             │
        ▼                             ▼
 Purple Yam Malaybalay          Secure Login
        │                             │
        ▼                             ▼
 Browse Products                Role-Based Access
        │                             │
        ▼              ┌──────────────┼──────────────┐
 Place Pre-Order        │              │              │
        │            ADMIN          BAKER         CASHIER
        ▼              │              │              │
 Pickup Selection      ▼              ▼              ▼
        │          Management     Production       Sales
        ▼          Forecasting    Inventory        Orders
 Order Status        Reports       Restock          Expenses
                       │
                       └──────────────┐
                                      ▼
                              Centralized Database
                                      │
                                      ▼
                             Reports / Analytics
                                      │
                                      ▼
                              AI-Assisted Forecast
```

Customer journey: Landing → Browse Products → Select Product → Order/Pre-Order → Fill Order Details → Select Pickup Outlet → Submit Order → Track Order Status. No account required.

Employee journey: Sign In → Authentication → Role Identification → Role-Based Dashboard → Authorized Modules.

## Verified tech stack

| Component | Value | Verified from |
|---|---|---|
| Framework | Laravel 13 | `composer.json` |
| Language | PHP `^8.4` (locked deps require `>=8.4`) | `composer.json` |
| UI | Blade + Livewire 4 + Flux UI (`livewire/flux`) | `composer.json` |
| Styling | Tailwind CSS v4 | `package.json` |
| Auth | Laravel Fortify + passkeys | `composer.json` |
| ORM | Laravel Eloquent | Laravel default |
| Database | PostgreSQL via Supabase | PRD §20, `.env` |
| AI | Laravel AI SDK (`laravel/ai`) + Groq `openai/gpt-oss-20b` | `app/Ai/Agents/ForecastReadingAgent.php` — the PRD named OpenAI GPT-5 Nano; no key could be obtained, and this is the nearest model Groq serves |
| Deploy | Render (Docker, FrankenPHP) + Supabase Postgres — https://pyramis.onrender.com | `render.yaml`, `Dockerfile`, `docker/` |
| Dev tooling | Composer, npm/Vite, Git, Laravel Boost (MCP) | repo root, `.mcp.json` |

## Design principles

1. **Centralization first** — one authoritative source per kind of operational data.
2. **Role-based by default** — users see only what's relevant to their responsibilities.
3. **Workflow before features** — every feature maps to an actual bakery workflow (see `docs/modules.md`).
4. **AI as decision support** — never autonomous management (see `docs/ai-forecasting.md`).
5. **Data quality matters** — forecasting is only as good as the operational data feeding it.
6. **Simplicity for an SME** — solve Purple Yam Malaybalay's actual problems; don't reproduce enterprise-ERP complexity.

## Deployment

Live at https://pyramis.onrender.com. Render web service `pyramis` (Singapore, free instance) builds `Dockerfile` from `main` on every push and swaps it in once `/up` answers. The database is the Supabase project's Postgres, reached through the Supavisor session pooler (IPv4, `sslmode=require`) as the single user Laravel connects as — no RLS, no Supabase Auth; authorization stays in Laravel policies.

**What happens on every boot** (`docker/entrypoint.sh`): cache config, routes and views; `migrate --force` (skip with `RUN_MIGRATIONS=false`); `db:seed --class=ProductionSeeder --force` (skip with `RUN_SEEDERS=false`); then FrankenPHP serves on `$PORT`. `ProductionSeeder` fills only what is empty — the catalogue, the ingredient list, the expense headings, the main branch — and never overwrites a row the business has since edited. It creates no accounts.

**Configuration** is declared in `render.yaml`. Four values are secrets set in the Render dashboard rather than the file: `APP_KEY`, `APP_URL`, `DB_PASSWORD`, `GROQ_API_KEY`. Without `GROQ_API_KEY` the forecast screen still renders every deterministic figure and simply offers no AI reading. Changing an env var in the dashboard redeploys the current image.

**Employee accounts** are minted one at a time with `make:employee`, which prints a generated password exactly once and marks the address verified so the account can sign in immediately. The free instance has no shell, so run it from a workstation against the live database: create a gitignored `.env.production` carrying the `DB_*` values from `render.yaml` plus the Supabase password, then

```
php artisan make:employee --env=production --name "…" --email … --role administrator --generate-password
```

(`--role baker` and `--role cashier` likewise). Only a bcrypt hash is written, so the workstation's own `APP_KEY` is fine. Remove the file, or blank `DB_PASSWORD`, when done. `php artisan test` is unaffected either way — `phpunit.xml` pins it to SQLite `:memory:`.

**Free-instance behaviour worth knowing:** the container spins down after about fifteen idle minutes, and the next request pays a cold start of roughly a minute; open the site shortly before a demonstration. Logs live in the Render dashboard (`LOG_CHANNEL=stderr`); the entrypoint's migrate and seed output appears there on each deploy.

## Visual direction

Premium, warm, modern — "a serious operational system without feeling cold or intimidating." Deep violet ("Velvet Orchid") surfaces and brand color, warm neutral backgrounds, medium-radius components, generous whitespace, data-forward dashboards. Full palette and usage guidance in `docs/reference/design-vibe.md`.

## Source

PYRAMIS PRD §5, §6, §26, §27; Unified Business Web Portal Design; Design Vibe. Full text in `docs/reference/`.

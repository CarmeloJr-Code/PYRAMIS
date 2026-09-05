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
| Language | PHP `^8.3` (running 8.4 per Boost) | `composer.json` |
| UI | Blade + Livewire 4 + Flux UI (`livewire/flux`) | `composer.json` |
| Styling | Tailwind CSS v4 | `package.json` |
| Auth | Laravel Fortify + passkeys | `composer.json` |
| ORM | Laravel Eloquent | Laravel default |
| Database | PostgreSQL via Supabase | PRD §20, `.env` |
| AI (Phase 12 only) | Laravel AI SDK + OpenAI GPT-5 Nano | PRD §20 — not yet installed |
| Deploy | Render (Docker) | `render.yaml`, `Dockerfile` |
| Dev tooling | Composer, npm/Vite, Git, Laravel Boost (MCP) | repo root, `.mcp.json` |

## Design principles

1. **Centralization first** — one authoritative source per kind of operational data.
2. **Role-based by default** — users see only what's relevant to their responsibilities.
3. **Workflow before features** — every feature maps to an actual bakery workflow (see `docs/modules.md`).
4. **AI as decision support** — never autonomous management (see `docs/ai-forecasting.md`).
5. **Data quality matters** — forecasting is only as good as the operational data feeding it.
6. **Simplicity for an SME** — solve Purple Yam Malaybalay's actual problems; don't reproduce enterprise-ERP complexity.

## Visual direction

Premium, warm, modern — "a serious operational system without feeling cold or intimidating." Deep violet ("Velvet Orchid") surfaces and brand color, warm neutral backgrounds, medium-radius components, generous whitespace, data-forward dashboards. Full palette and usage guidance in `docs/reference/design-vibe.md`.

## Source

PYRAMIS PRD §5, §6, §26, §27; Unified Business Web Portal Design; Design Vibe. Full text in `docs/reference/`.

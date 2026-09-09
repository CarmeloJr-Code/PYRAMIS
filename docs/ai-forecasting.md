# PYRAMIS AI-Assisted Forecasting (Phase 12 — built)

This is deliberately one of the last vertical slices. It depends on having accurate, centralized operational data from Phases 2–11 already in place — forecasting quality is bounded by data quality, not model choice.

Built in two halves. The deterministic outlook came first (`BuildForecastContext`, the `/forecast` screen), and stands on its own: every figure there is worked out in PHP and SQL and needs no AI at all. The reading on top of it (`ForecastReadingAgent`, `CompactForecastContext`, `GenerateForecastReading`) is asked for only when a Manager presses the button, and the screen is whole without it.

## Architecture

```text
Historical Business Data
          ↓
Statistical Analysis
          ↓
Forecast Context
          ↓
Laravel AI SDK
          ↓
Groq — openai/gpt-oss-20b
          ↓
Structured AI Response
          ↓
PYRAMIS Recommendation
```

Deterministic calculations (totals, trend math, moving averages, etc.) belong in PHP/SQL, not delegated to the LLM. The LLM's job is interpretation and recommendation, not arithmetic.

**Provider.** The approved spec named OpenAI GPT-5 Nano. No OpenAI key could be obtained, so the slice ships against Groq's `openai/gpt-oss-20b` — OpenAI's open-weight 20B, and the nearest thing Groq serves. Groq is a first-class provider in the Laravel AI SDK, so this is a configuration choice rather than a workaround: the provider and model are named only by the attributes on `ForecastReadingAgent`, and the vendor `config/ai.php` already reads `GROQ_API_KEY`. Nothing is published, and the SDK's conversation migrations are deliberately not installed — the reading is one-shot and keeps no history.

The agent implements neither `HasTools` nor `Conversational`. With no tools there is nothing it could call even if it decided to, which makes BR-009 structural rather than merely promised; with no conversation it cannot be talked around its instructions over several turns.

## Inputs

Historical sales, production records, inventory information, expense records, and other relevant operational data already centralized by earlier phases.

## Outputs

- Demand forecasts
- Production recommendations
- Inventory replenishment suggestions
- Operational insights and summaries

Responses use a structured schema rather than free-form text. As shipped, in `app/Ai/Agents/ForecastReadingAgent::schema()`:

| Field | Shape |
|---|---|
| `summary` | string — three or four sentences |
| `confidence` | `low` \| `medium` \| `high` — the model's own hedge, never a statistical interval |
| `key_factors` | list of `{factor, evidence}` — the claim and the figure it was drawn from |
| `production_recommendations` | list of `{item, action, reason, priority}` |
| `inventory_recommendations` | list of `{ingredient, action, reason, priority}` |

`evidence` sits beside every factor so a reading is explainable rather than a black box. Everything is coerced and capped in PHP before it reaches the page: half-built rows are dropped, unknown `priority` and `confidence` values fall back, and a reading whose summary comes back empty is refused rather than cached.

## The token budget

Groq's free allowance is 8,000 tokens a minute and 1,000 requests a day, shared by everyone on the key. `BuildForecastContext` deliberately returns the whole catalogue and every active ingredient, which is far too much to send.

So `CompactForecastContext` cuts it to a brief: the window, horizon, demand, production and weekday figures in full, then only the sizes projected short (at most ten, topped up to five when little is short) and only the ingredients already flagged as needing attention (at most eight). What was left out is stated in a line under each table, so "the rest are covered" is something the model was told rather than something it must infer. Pipe-delimited text, not JSON — no repeated keys.

Takings are deliberately withheld: it is the one money figure in the context, and a model shown revenue gives revenue advice, which BR-009 puts out of bounds. Record ids are withheld too, so nothing the model says can name a row.

Measured against the seeded catalogue (27 sizes, 19 ingredients) with eight weeks of trading: a brief of 1,471 characters, roughly 370 tokens, answered in 2–3 seconds. A test pins the brief under 4,000 characters so the budget cannot quietly erode.

One wording lesson worth keeping: the residual line under the products table must not call the overflow "covered". The table is capped, so when more sizes are short than fit, what was left out is short too — and a model told otherwise will repeat it.

## Hard safety rules (BR-008, BR-009)

AI-generated forecasts are decision support — never represented as guaranteed or absolute predictions. The AI must **not**:

- Directly alter inventory
- Directly create production records
- Automatically approve orders
- Automatically spend money
- Automatically schedule employees

The only allowed path from a recommendation to a system change is:

```text
AI Recommendation → Human Review → Manager Decision → Actual System Action
```

## Quality expectations

- Recommendations should be understandable and reasonably explainable, not a black box.
- The system should give users enough context to interpret an AI-assisted output, not just a bare number.
- Stay within the approved capstone scope — this is decision support for an administrator, not an autonomous operations manager.

## Source

PYRAMIS PRD §17 (FR-11); Vibe Coding Development Rules §10; Modified Vertical Slice Implementation Plan Phase 12. Full text in `docs/reference/`.

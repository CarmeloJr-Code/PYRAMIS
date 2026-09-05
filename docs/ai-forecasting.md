# PYRAMIS AI-Assisted Forecasting (Phase 12 — build last, not now)

This is deliberately one of the last vertical slices. It depends on having accurate, centralized operational data from Phases 2–11 already in place — forecasting quality is bounded by data quality, not model choice.

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
OpenAI GPT-5 Nano
          ↓
Structured AI Response
          ↓
PYRAMIS Recommendation
```

Deterministic calculations (totals, trend math, moving averages, etc.) belong in PHP/SQL, not delegated to the LLM. The LLM's job is interpretation and recommendation, not arithmetic.

## Inputs

Historical sales, production records, inventory information, expense records, and other relevant operational data already centralized by earlier phases.

## Outputs

- Demand forecasts
- Production recommendations
- Inventory replenishment suggestions
- Operational insights and summaries

Use a predictable, structured schema for AI responses rather than free-form text, e.g. conceptually: `forecast`, `confidence/context`, `key_factors`, `production_recommendations`, `inventory_recommendations`, `summary`. Define the exact schema when this slice is actually implemented.

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

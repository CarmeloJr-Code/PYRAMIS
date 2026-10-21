# PYRAMIS Modules and Phase Roadmap

## Functional modules (from the PRD)

| Module | Summary |
|---|---|
| FR-01 Authentication & Access Control | Secure employee sign-in, role identification, role-scoped access. |
| FR-02 Sales Management | Record and report sales transactions per location; feeds analytics/forecasting. |
| FR-03 Customer Ordering | Account-free browsing, pre-order, pickup selection, order-status tracking. No delivery. |
| FR-04 Inventory Management | Ingredient/material records, quantities, usage, restocking status, feeds forecasting. |
| FR-05 Production Management | Production planning/logging, ingredient usage, finished-product prep, restock prep. |
| FR-06 Outlet Management | Outlet records, outlet sales, restocking from the main branch, outlet performance. |
| FR-07 Workforce Management | Employee records, shift scheduling/assignment/monitoring, activity reporting. |
| FR-08 Expense Management | Record and report business expenses, tied to location/activity where relevant. |
| FR-09 Internal Communication | Text-only internal messaging between employees. No voice/video/photo/external integration. |
| FR-10 Reports and Analytics | Dashboards/reports across sales, inventory, production, expenses, workforce, outlets. |
| FR-11 AI-Assisted Forecasting | Demand forecasts, production/inventory recommendations, operational insights — decision support only. |

## Phase / milestone roadmap

Development proceeds as dependency-aware vertical slices (one business capability + its full stack per task), not layer-by-layer and not multiple modules at once.

All seventeen phases are built and milestones **A** through **F** are complete. The system is live at https://pyramis.onrender.com — the Deployment section of `docs/architecture.md` covers how it runs and how it is operated.

| Phase | Slice | Primary outcome |
|---|---|---|
| 0 | Foundation | Runnable Laravel app |
| 1 | Authentication + RBAC | Secure employee workspace |
| 2 | Product Catalog | Shared product foundation for customers and employees |
| 3 | Customer Ordering | End-to-end customer pre-order flow |
| 4 | Sales | Transaction recording tied to orders |
| 5 | Inventory | Central, auditable stock management |
| 6 | Production | Production + inventory integration |
| 7 | Outlet Restocking | Main branch → outlet distribution workflow |
| 8 | Workforce | Scheduling and assignments |
| 9 | Expenses | Operational expense management |
| 10 | Communication | Internal employee text chat |
| 11 | Reporting | Business dashboards and reports |
| 12 | AI Forecasting | Decision-support intelligence (see `docs/ai-forecasting.md`) |
| 13 | Integration | Full cross-module business workflow validated end-to-end — **done** |
| 14 | Hardening | Security, data integrity, UX, performance pass — **done** |
| 15 | Testing | Systematic unit/feature/authorization/browser test pass — **done** |
| 16 | Evaluation | Functionality + usability evaluation (capstone criteria) — **done**, see `docs/evaluation.md` |
| 17 | Deployment | Operational system on Render/Supabase — **done**, live at https://pyramis.onrender.com |

Grouped milestones: **A** Foundation (0–2) · **B** Transaction System (3–4) · **C** Operations System (5–9) · **D** Management System (10–11) · **E** Decision Support (12) · **F** Production Release (13–17).

## Sizing a task

Good: "Implement customer pre-order submission — no auth, required order info, pickup outlet selection, starts Pending, validates product availability, persists order + items, returns an order reference. Inspect existing Product/Outlet/storefront code first. Add tests for: success, invalid info, unavailable product, invalid outlet, empty order."

Too broad: "Build the entire customer ordering system." One task should be one capability with its full stack, not a whole module.

## Source

PYRAMIS PRD §7–§17; Modified Vertical Slice Implementation Plan §1–§7, §21, §24. Full text in `docs/reference/`.

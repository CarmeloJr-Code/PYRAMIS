# PYRAMIS Vibe Coding Development Rules

## Purpose

These rules define how PYRAMIS should be developed using AI-assisted or "vibe coding" workflows. They are intended to keep implementation aligned with the approved capstone requirements, actual bakery operations, Laravel conventions, security practices, and the approved design direction.

## 1. Requirements and Source of Truth

1. Treat the approved PYRAMIS specifications as the primary source of truth.
2. Use this source-of-truth hierarchy:
   - Final System Specifications
   - Approved design decisions and portal structure
   - Current implementation
   - Capstone manuscript
   - Official Laravel and package documentation
   - General technical knowledge
3. Never invent requirements, workflows, roles, permissions, or features that are not supported by the approved project scope.
4. When requirements conflict or are ambiguous, stop and clarify instead of silently guessing.
5. Preserve the terminology and business rules already established for PYRAMIS.

## 2. Business Workflow

1. Preserve the actual operational structure of Purple Yam Malaybalay.
2. The main branch remains responsible for general operations such as sales, expenses, inventory, production, and workforce management.
3. Outlets receive finished products from the main branch and handle their local sales and expenses.
4. Customer ordering remains pre-order based with pickup locations.
5. PYRAMIS does not introduce door-to-door delivery unless the approved requirements are changed.
6. Internal communication remains text-only unless the approved scope changes.

## 3. Development Strategy

1. Build the simplest correct system that satisfies the approved requirements.
2. Develop features using small vertical slices.
3. Each slice should move through the relevant layers needed to make the feature actually work.
4. Avoid building large batches of unrelated functionality before testing.
5. Keep changes small, understandable, and reversible.
6. Do not refactor unrelated code while implementing a feature.
7. Prefer root-cause fixes over patches that hide underlying problems.
8. Maintain a working application throughout development.

## 4. Inspect Before Editing

Before changing existing functionality:

1. Inspect the relevant routes.
2. Inspect controllers, Livewire components, models, services, policies, migrations, views, and tests as applicable.
3. Identify existing relationships and business logic.
4. Check how similar features are already implemented.
5. Verify the installed framework and package versions.
6. Only then make the smallest appropriate change.

## 5. Laravel Development Standards

1. Follow idiomatic Laravel conventions.
2. Prefer Laravel-native functionality before adding dependencies.
3. Verify framework and package APIs against the installed version and official documentation.
4. Never assume an API exists because it appears in an example, tutorial, or older version.
5. Use Laravel MVC appropriately.
6. Use Blade and Livewire consistently with the approved architecture.
7. Use Eloquent relationships explicitly and correctly.
8. Keep business logic out of presentation components whenever practical.
9. Use migrations as the authoritative mechanism for database schema changes.
10. Keep the application as one centralized Laravel web application unless the approved architecture changes.

## 6. Database and Data Integrity

1. Design the database structure before implementing data-heavy features.
2. Use appropriate primary keys, foreign keys, indexes, constraints, and relationships.
3. Preserve referential integrity.
4. Validate data at the application layer and use database constraints where appropriate.
5. Do not duplicate data unnecessarily.
6. Use transactions for operations that must succeed or fail as a unit.
7. Treat production, inventory, sales, ordering, expenses, and workforce records as operational data that must remain consistent.
8. Never perform destructive database changes casually.

## 7. Validation and Authorization

1. Validate all user input on the server.
2. Never rely only on client-side validation.
3. Enforce authorization server-side.
4. Every protected action must verify that the current user has permission to perform it.
5. Do not expose records merely because a user can manipulate a URL, request parameter, or browser request.
6. Apply role-based access consistently across Administrator, Baker, Cashier, and Customer workflows.
7. Customer-facing functionality must not expose employee-only operational information.

## 8. Security

1. Never expose secrets, API keys, passwords, tokens, or credentials in source code.
2. Use environment configuration for secrets.
3. Follow Laravel's built-in security mechanisms whenever possible.
4. Protect forms and state-changing requests against common web attacks.
5. Avoid mass-assignment vulnerabilities.
6. Sanitize and validate user-controlled data appropriately.
7. Do not weaken authentication or authorization merely to make development easier.
8. Prefer secure defaults over convenient shortcuts.

## 9. Unified Portal and User Experience

1. PYRAMIS remains a unified web portal with two distinct experiences:
   - Customer-facing storefront: **Purple Yam Malaybalay**
   - Employee workspace: **PYRAMIS**
2. Customers should experience a simple storefront focused on browsing products and placing pre-orders.
3. Employees should enter a secure, role-based workspace.
4. Do not blur customer and employee workflows unnecessarily.
5. Maintain the approved visual language and design system.
6. Use the approved Velvet Orchid palette and related design decisions consistently.
7. Prioritize hierarchy, readability, accessibility, and responsive behavior.
8. Avoid unnecessary visual complexity.

## 10. AI-Assisted Forecasting

1. AI is decision support, not autonomous management.
2. AI must never independently make operational decisions on behalf of the business.
3. Never present AI forecasts as guaranteed predictions or facts.
4. Clearly distinguish historical data, deterministic calculations, forecasts, and AI-generated recommendations.
5. Deterministic calculations must be handled by PHP/database logic rather than delegated to an LLM.
6. Use AI for appropriate tasks such as:
   - Demand forecasting
   - Production recommendations
   - Inventory replenishment suggestions
   - Operational summaries and insights
7. Prefer structured AI outputs when practical.
8. AI-generated recommendations should be understandable and reasonably explainable.
9. The system should provide enough context for users to interpret AI-assisted outputs.
10. AI functionality must remain within the approved capstone scope.

## 11. Dependencies and Architecture

1. Avoid unnecessary packages and third-party dependencies.
2. Every new dependency should solve a real project requirement.
3. Do not introduce microservices, separate frontend/backend applications, or other architectural complexity without an approved requirement.
4. Prefer the existing Laravel ecosystem and project conventions.
5. Keep infrastructure appropriate for the capstone's actual needs.

## 12. Testing

Every meaningful vertical slice should be tested.

At minimum, verify:

1. The expected happy path works.
2. Invalid input is rejected correctly.
3. Unauthorized users cannot perform protected actions.
4. Important edge cases are handled.
5. Database state is correct after the operation.
6. Existing functionality has not been unintentionally broken.
7. UI behavior matches the approved workflow.
8. AI-assisted features handle missing or insufficient data appropriately.

When a test fails:

1. Identify the root cause.
2. Fix the underlying issue.
3. Re-run the relevant tests.
4. Check for regressions.

## 13. Scope Control

1. Align implementation with the approved capstone objectives and scope.
2. Do not add features simply because they are technically interesting.
3. Do not turn a capstone project into an unnecessarily large production platform.
4. New major features require explicit approval.
5. Architecture changes require explicit approval.
6. Changes that alter business workflows require explicit approval.

## 14. Documentation

1. Document significant technical and architectural decisions.
2. Keep implementation documentation consistent with the actual system.
3. Do not claim that a feature exists if it has not been implemented.
4. Keep terminology consistent across the application, technical documentation, and capstone manuscript.
5. Update relevant documentation when significant behavior changes.

## 15. AI Agent Operating Procedure

For every development task, follow this sequence:

1. Understand the requirement.
2. Identify the affected module or modules.
3. Check the approved specifications.
4. Inspect the existing implementation.
5. Check relevant Laravel or package documentation.
6. Identify database, authorization, validation, UI, and testing dependencies.
7. Plan the smallest viable implementation.
8. Implement one vertical slice.
9. Test the slice.
10. Verify authorization and security.
11. Verify UI and design consistency.
12. Check for regressions.
13. Document significant decisions.
14. Commit the change as a focused unit.

## 16. Stop Conditions

Stop and request clarification or approval when:

- Requirements conflict.
- The intended business workflow is unclear.
- A permission model is ambiguous.
- A destructive database change is required.
- A new major feature is being proposed.
- The requested change requires a significant architecture change.
- AI behavior could create autonomous or high-impact decisions.
- Existing documentation and implementation disagree in a way that cannot be resolved from the approved sources.
- A proposed dependency is not clearly justified.
- The change would materially expand the approved capstone scope.

## 17. Definition of Done

A feature is not considered complete merely because the page renders.

A feature is done only when:

- The approved requirement is satisfied.
- The actual business workflow is preserved.
- Database changes are correct.
- Validation is implemented.
- Authorization is enforced.
- Security considerations are addressed.
- The UI follows the approved design.
- Relevant business logic is tested.
- Important edge cases are handled.
- Existing functionality still works.
- AI behavior, if applicable, remains assistive and explainable.
- Documentation is updated when necessary.
- The implementation follows Laravel and project conventions.

## Canonical Short Version

When speed matters, use these rules as the compact operating contract:

1. Treat approved PYRAMIS specifications as the source of truth.
2. Never invent requirements or workflows.
3. Preserve the actual bakery operating structure.
4. Build small vertical slices.
5. Inspect existing code before editing.
6. Follow idiomatic Laravel.
7. Verify APIs against installed versions and official documentation.
8. Prefer Laravel-native functionality before adding dependencies.
9. Keep business logic out of the UI.
10. Validate all user input server-side.
11. Enforce authorization server-side.
12. Never expose secrets.
13. Keep PYRAMIS as one centralized Laravel web application.
14. Keep the customer storefront and employee workspace distinct.
15. AI is decision support, never autonomous management.
16. Never present AI forecasts as guaranteed predictions.
17. Use deterministic application/database logic for deterministic calculations.
18. Prefer structured and explainable AI outputs.
19. Test every meaningful vertical slice.
20. Fix root causes instead of hiding symptoms.
21. Avoid unrelated refactoring.
22. Keep dependencies and architecture simple.
23. Stay within the approved capstone scope.
24. Document significant decisions.
25. Clarify ambiguity instead of guessing.
26. Done means working, tested, secured, consistent, and aligned with the approved design.

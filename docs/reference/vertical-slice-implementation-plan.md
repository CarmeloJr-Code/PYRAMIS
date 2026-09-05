# PYRAMIS: Modified Vertical Slice Implementation Plan

## 1. Development Strategy

PYRAMIS will be developed using a **modified vertical slice development method** optimized for LLM-assisted software development.

Instead of implementing the system layer-by-layer, development will proceed through a sequence of functional slices. Each slice crosses the necessary application layers and produces a working, testable portion of the system.

A typical slice follows:

```text
Requirement
    ↓
Database Schema
    ↓
Model / Relationships
    ↓
Business Rules
    ↓
Authorization
    ↓
Livewire Component
    ↓
Blade UI
    ↓
Validation
    ↓
Automated Tests
    ↓
Manual Verification
```

The modification is that the slices are **dependency-aware**. Foundational infrastructure and shared business entities are implemented first, while larger business capabilities are then built as progressively complete vertical slices.

The objective is not merely to "finish modules." The objective is to repeatedly produce a system that is more complete and usable after every phase.

---

# 2. Core Development Rules

Every implementation phase should follow these rules.

### Rule 1: One slice at a time

Do not ask an LLM to implement the entire application or several unrelated modules in one request.

A task should normally represent:

> One business capability + its complete supporting layers.

For example:

```text
"Implement customer pre-order placement"
```

is appropriate.

```text
"Build the entire customer module"
```

is too broad.

---

### Rule 2: The application must remain runnable

At the end of every phase:

- Application boots successfully.
- Database migrations run successfully.
- Existing tests pass.
- New functionality can be demonstrated.
- No known critical regression remains.

---

### Rule 3: Tests are part of the slice

A slice is not considered complete when the UI works.

It is complete when:

1. The database structure works.
2. Business rules work.
3. Authorization works.
4. Validation works.
5. UI behavior works.
6. Automated tests cover important behavior.

---

### Rule 4: LLMs work from bounded context

Every LLM-assisted task should provide:

- Current architecture
- Relevant models
- Relevant migrations
- Existing conventions
- Related components
- Business rules
- Expected behavior
- Testing requirements

The agent should inspect the existing code before modifying it.

Laravel's conventions are particularly useful here because predictable locations for controllers, migrations, models, requests, middleware, and other components reduce ambiguity for coding agents. The supplied Laravel documentation also identifies Laravel Boost as an MCP-based tool for exposing application structure, database schema, routes, logs, Artisan commands, and version-specific Laravel documentation to AI agents.

---

### Rule 5: Never allow silent architectural changes

An LLM must not independently:

- Replace the database technology.
- Introduce a new frontend framework.
- Replace Livewire.
- Change authentication architecture.
- Add major packages without justification.
- Change established business rules.
- Add unapproved features.

If a requirement appears to conflict with the current architecture, the agent should stop and identify the conflict.

---

# 3. Phase 0: Project Foundation

## Objective

Create the stable development foundation before implementing business functionality.

## Tasks

### 0.1 Initialize Laravel

Set up:

- Laravel 13
- PHP 8.3+
- Composer
- Node/NPM
- Git
- PostgreSQL
- Supabase connection

### 0.2 Configure frontend

Set up:

- Blade
- Livewire
- Tailwind CSS
- Vite

### 0.3 Configure development environment

Create:

```text
.env
.env.example
```

Configure:

- Database connection
- Application URL
- Mail configuration if required
- AI provider configuration
- Storage configuration

Secrets must never be committed.

### 0.4 Establish repository structure

Create the initial Git repository.

Recommended initial commits:

```text
chore: initialize Laravel application
chore: configure database and frontend tooling
chore: establish development conventions
```

### 0.5 Install Laravel Boost

Because PYRAMIS will be developed with substantial LLM assistance, Laravel Boost should be installed as a development dependency and configured for the selected AI development environment.

The supplied documentation specifies:

```text
composer require laravel/boost --dev
php artisan boost:install
```

Boost provides application introspection, database inspection, route inspection, Artisan access, log analysis, browser logs, Tinker integration, and version-specific documentation.

## Exit Criteria

- Laravel application loads.
- Database connection succeeds.
- Tailwind renders correctly.
- Livewire renders correctly.
- Tests execute.
- Git repository is clean.
- Boost is operational.

---

# 4. Phase 1: Application Shell and Authentication

## Objective

Create the basic structure shared by every subsequent vertical slice.

## Implement

### Public experience

Create:

```text
/
├── Home
├── Products
├── Product Details
├── Order
└── Order Status
```

### Employee experience

Create:

```text
/employee/login
/employee/dashboard
```

### Roles

Use the four roles established in the capstone:

```text
Administrator
Baker
Cashier
Customer
```

The unified portal design uses slightly different terminology:

```text
Managers
Sales Staff
Production Staff
Customers
```

For implementation, these should map consistently as:

| System Role | Design Terminology |
|---|---|
| Administrator | Manager |
| Cashier | Sales Staff |
| Baker | Production Staff |
| Customer | Customer |

This avoids maintaining two competing role vocabularies.

### RBAC

Implement role-based authorization.

The administrator manages:

- Overall operations
- Workforce
- Outlet restocking
- Reports
- Forecasting

The baker manages:

- Production
- Ingredient usage
- Outlet restock preparation

The cashier manages:

- Sales
- Customer orders
- Expenses

Customers interact through the storefront and do not require employee authentication. The current manuscript explicitly specifies these role responsibilities.

## Exit Criteria

A test user can:

- Log in.
- Be identified by role.
- Access the correct dashboard.
- Be prevented from accessing unauthorized functionality.

---

# 5. Phase 2: Product and Catalog Slice

## Objective

Establish the product data foundation used by both customers and employees.

## Implement

### Database

Create product-related structures for:

- Products
- Product categories
- Product availability
- Product pricing
- Product images if included in the approved design

### Employee functionality

Administrator/cashier can:

- Create products.
- Edit products.
- Set prices.
- Activate/deactivate products.

### Customer functionality

Customers can:

- Browse products.
- View product details.
- See availability.
- Select products for ordering.

## Vertical Slice

```text
Product Migration
      ↓
Product Model
      ↓
Product Validation
      ↓
Product Management UI
      ↓
Customer Product UI
      ↓
Authorization
      ↓
Tests
```

## Exit Criteria

A product created by an employee becomes visible to customers when available.

---

# 6. Phase 3: Customer Ordering Slice

## Objective

Deliver the first complete customer transaction workflow.

The current system scope specifies customer browsing, order placement, pickup-location selection, and order-status monitoring without requiring customer account registration.

## Implement

### Customer flow

```text
Browse Products
      ↓
Select Product
      ↓
Add Order
      ↓
Fill Customer Information
      ↓
Select Pickup Outlet
      ↓
Submit Pre-order
      ↓
Receive Order Reference
      ↓
Track Order Status
```

### Order states

Establish explicit states such as:

```text
Pending
Confirmed
Preparing
Ready
Completed
Cancelled
```

The exact final state names should follow the approved UI/business specification.

### Employee flow

Cashier can:

- View incoming orders.
- Confirm orders.
- Update order status.
- Review pickup location.
- Mark completed/cancelled orders.

## Important Rule

Do not add:

- Door-to-door delivery
- Customer account registration
- External messaging integration

These are outside the current scope.

## Exit Criteria

A real end-to-end order can travel from:

```text
Customer
→ Order
→ Cashier
→ Status Update
→ Customer
```

and persist correctly in the database.

---

# 7. Phase 4: Sales Transaction Slice

## Objective

Connect operational sales with the existing product and order structures.

## Implement

### Sales

Support:

- Sale creation
- Sale items
- Quantities
- Prices
- Total calculation
- Transaction status
- Transaction timestamps
- Sales records

### Relationship with orders

Where appropriate:

```text
Customer Order
      ↓
Confirmed Order
      ↓
Sale Transaction
```

Avoid duplicating information unnecessarily.

## Employee UI

Cashier receives:

- Sales screen
- Transaction history
- Daily sales
- Order-to-sale workflow

## Reporting foundation

Create the first operational metric:

```text
Total Sales
```

## Exit Criteria

The system can record sales and calculate totals reliably.

---

# 8. Phase 5: Inventory Slice

## Objective

Create centralized inventory management around ingredients and stock.

The current capstone scope explicitly includes inventory monitoring and ingredient usage.

## Implement

### Inventory entities

Potential structures:

```text
Ingredients
Inventory Items
Inventory Transactions
Units
Stock Adjustments
```

### Operations

Support:

- Adding ingredients.
- Updating stock.
- Recording stock movement.
- Viewing current stock.
- Recording adjustments.
- Tracking ingredient usage.

### Stock movement

Every stock-changing action should produce an auditable record.

Conceptually:

```text
Purchase/Restock
       ↓
Inventory Increase

Production Usage
       ↓
Inventory Decrease

Manual Adjustment
       ↓
Inventory Increase/Decrease
```

## Exit Criteria

Inventory quantities can be traced to actual transactions rather than simply being overwritten.

---

# 9. Phase 6: Production Slice

## Objective

Connect products, recipes/ingredients, production activities, and inventory.

## Implement

### Production workflow

```text
Production Plan
      ↓
Ingredients Required
      ↓
Production Activity
      ↓
Ingredient Consumption
      ↓
Finished Product Quantity
```

### Baker interface

Bakers can:

- View production tasks.
- Record production.
- Record ingredient usage.
- Update finished-product stock.
- Prepare outlet restock jobs.

### Inventory integration

Production must automatically or transactionally record ingredient consumption.

For example:

```text
Produce 20 Ube Bread
        ↓
Required Ingredients
        ↓
Inventory deductions
        ↓
Finished Product +20
```

## Exit Criteria

A production activity changes inventory correctly and records finished-product output.

---

# 10. Phase 7: Outlet and Restocking Slice

## Objective

Implement PYRAMIS's centralized main-branch-to-outlet workflow.

The capstone specifically states that the main branch handles general operations while outlets receive finished products from the main branch for their respective sales activities.

## Implement

### Outlet management

Administrator can:

- Create outlets.
- Edit outlets.
- Activate/deactivate outlets.
- View outlet information.

### Restocking

```text
Main Branch Finished Stock
          ↓
Restock Request / Schedule
          ↓
Restock Preparation
          ↓
Outlet Delivery
          ↓
Outlet Stock
```

### Baker

Handles:

- Restock preparation
- Prepared quantity
- Production/restock status

### Administrator

Handles:

- Restock schedules
- Outlet monitoring
- Restock oversight

### Cashier

Handles:

- Outlet sales
- Outlet expenses

## Exit Criteria

Finished products can move from the main branch into an outlet inventory context and become available for outlet sales.

---

# 11. Phase 8: Workforce Management Slice

## Objective

Implement employee assignments and shift management.

## Implement

### Employee records

Store:

- Employee information
- Role
- Status

### Scheduling

Administrator can:

- Create shifts.
- Assign employees.
- View schedules.
- Modify assignments.

### Validation

Prevent obvious scheduling conflicts.

Example:

```text
Employee A
09:00–17:00

Attempted assignment:
13:00–21:00

→ Conflict
```

## Employee experience

Employees can view:

- Their schedule
- Their assignments
- Relevant operational tasks

## Exit Criteria

An administrator can assign workforce schedules and employees can view their assigned work.

---

# 12. Phase 9: Expense Management Slice

## Objective

Centralize operational expense recording.

## Implement

Support:

- Expense creation
- Expense categories
- Amount
- Date
- Outlet/main-branch association
- Description
- Recorded-by information

### Cashier workflow

```text
Record Expense
      ↓
Validate
      ↓
Save
      ↓
Report
```

### Reporting integration

Expenses should become available to:

- Expense reports
- Business performance reporting
- AI forecasting/analysis

## Exit Criteria

Operational expenses can be recorded, filtered, summarized, and associated with the correct operational location.

---

# 13. Phase 10: Internal Communication Slice

## Objective

Implement basic workforce communication.

The scope explicitly limits this functionality to internal text communication. Photo, video, voice, and external messaging integration are excluded.

## Implement

- Conversation/thread
- Participants
- Messages
- Read/unread status if required
- Timestamps

### UI

Simple:

```text
Conversation List
        ↓
Conversation
        ↓
Message Input
```

Do not overengineer this into a miniature social network. Humanity has enough of those.

## Exit Criteria

Authorized employees can exchange internal text messages.

---

# 14. Phase 11: Reporting and Dashboard Slice

## Objective

Turn the operational data collected by previous slices into useful management information.

## Implement

### Sales

- Daily sales
- Sales by product
- Sales by outlet
- Sales trends

### Inventory

- Current stock
- Low-stock items
- Inventory movement

### Production

- Production quantity
- Production history
- Product output

### Expenses

- Expense totals
- Expenses by category
- Expenses by location

### Workforce

- Schedules
- Assignments
- Workforce activity

### Outlet performance

- Outlet sales
- Restock activity
- Outlet-related expenses

## Dashboard

Administrator dashboard should aggregate important metrics.

Conceptually:

```text
                 PYRAMIS DASHBOARD

Sales        Inventory       Production
₱XX,XXX      XX Items        XXX Units

Expenses     Outlets         Orders
₱XX,XXX      X Active        XX Pending
```

## Exit Criteria

Management can obtain meaningful operational information without manually combining spreadsheets.

---

# 15. Phase 12: AI-Assisted Forecasting Slice

## Objective

Implement AI-assisted decision support only after sufficient operational data structures exist.

This should deliberately be one of the **later slices**.

The capstone defines forecasting as decision support rather than absolute prediction, using statistical trend analysis and AI-generated recommendations.

The supplied Laravel documentation supports using the Laravel AI SDK for provider integration, agents, structured output, tools, conversation context, and related AI functionality.

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

## AI capabilities

### Demand forecasting

Estimate expected product demand from historical data.

### Production recommendation

Suggest production quantities based on:

- Historical demand
- Recent trends
- Existing stock
- Operational context

### Inventory replenishment

Identify inventory items that may require replenishment.

### Operational summary

Generate concise management insights from business data.

---

## AI Safety/Quality Rules

The AI must not:

- Directly alter inventory.
- Directly create production records.
- Automatically approve orders.
- Automatically spend money.
- Automatically schedule employees.

Instead:

```text
AI Recommendation
       ↓
Human Review
       ↓
Manager Decision
       ↓
Actual System Action
```

This preserves the project's intended decision-support role.

## Structured Output

AI responses should use a predictable schema rather than returning uncontrolled text.

Example conceptual structure:

```text
forecast
confidence/context
key_factors
production_recommendations
inventory_recommendations
summary
```

The exact schema should be defined during implementation.

## Exit Criteria

The administrator can request a forecast and receive a structured, explainable decision-support result based on actual PYRAMIS data.

---

# 16. Phase 13: Cross-Module Integration

At this point, all major vertical slices exist independently.

Now perform deliberate integration.

## Validate the complete business flow

```text
Customer Order
       ↓
Cashier
       ↓
Sales
       ↓
Production
       ↓
Inventory
       ↓
Outlet Restock
       ↓
Outlet Sales
       ↓
Reports
       ↓
Forecasting
```

## Validate data propagation

A business event should produce the expected downstream effects.

Example:

```text
Sale recorded
    ↓
Sales increases
    ↓
Product stock decreases
    ↓
Reports update
    ↓
Historical dataset changes
    ↓
Future forecast can incorporate data
```

This phase is particularly important because vertical slices can individually work while their boundaries quietly disagree.

---

# 17. Phase 14: Hardening and Quality Assurance

## Security

Verify:

- Authentication
- Authorization
- CSRF protection
- Validation
- Mass-assignment protection
- Secure file handling where applicable
- Environment secrets
- Database permissions
- Route protection

## Data integrity

Verify:

- Foreign keys
- Transactions
- Unique constraints
- Required fields
- Quantity validation
- Negative-stock rules
- Status transitions

## UI/UX

Verify:

- Responsive layouts
- Navigation
- Loading states
- Validation messages
- Empty states
- Error states
- Accessibility
- Consistent design system

## Performance

Check:

- N+1 queries
- Excessive database queries
- Large dashboard queries
- Pagination
- Expensive reports
- AI request latency

---

# 18. Phase 15: Testing

Testing should happen throughout development, but this phase performs the final systematic evaluation.

## Unit Tests

Test isolated business logic:

```text
Price calculations
Inventory calculations
Forecast data preparation
Permission checks
Status transitions
```

## Feature Tests

Test complete workflows:

```text
Customer places order
Cashier confirms order
Baker records production
Inventory changes
Administrator views report
```

## Authorization Tests

Verify every role.

```text
Administrator → allowed
Cashier       → restricted
Baker         → restricted
Customer      → restricted
```

## Browser/UI Tests

Test critical user journeys.

### Customer

```text
Browse
→ Select
→ Order
→ Submit
→ Track
```

### Cashier

```text
Login
→ View orders
→ Process order
→ Record sale
```

### Baker

```text
Login
→ View production
→ Record production
→ Prepare restock
```

### Administrator

```text
Login
→ Dashboard
→ Workforce
→ Restock
→ Reports
→ Forecast
```

---

# 19. Phase 16: Prototype Evaluation

Evaluate the system according to the capstone requirements for functionality and usability.

The manuscript identifies functionality and usability as evaluation dimensions.

Evaluation should verify:

### Functionality

- Features work as specified.
- Business rules are enforced.
- Data is stored correctly.
- Roles have correct permissions.
- Reports reflect operational data.

### Usability

Evaluate:

- Ease of navigation
- Clarity of interface
- Learnability
- Task completion
- Error recovery
- Overall user experience

---

# 20. Phase 17: Deployment and Implementation

## Deployment preparation

Prepare:

- Production environment
- PostgreSQL/Supabase
- Environment variables
- Application configuration
- Storage
- Queue configuration if required
- Scheduled tasks if required
- AI API configuration
- Database migrations

## Deployment sequence

```text
Backup / Prepare Database
        ↓
Configure Environment
        ↓
Deploy Application
        ↓
Run Migrations
        ↓
Seed Required Data
        ↓
Verify Authentication
        ↓
Verify Customer Portal
        ↓
Verify Employee Portal
        ↓
Verify Database
        ↓
Verify AI Integration
        ↓
Smoke Test
```

## Final smoke test

Verify the most important workflow from beginning to end before declaring the system operational.

---

# 21. Recommended Phase Order

The complete roadmap is:

| Phase | Slice | Primary Outcome |
|---|---|---|
| 0 | Foundation | Runnable Laravel application |
| 1 | Authentication + RBAC | Secure employee workspace |
| 2 | Product Catalog | Shared product foundation |
| 3 | Customer Ordering | End-to-end customer order |
| 4 | Sales | Transaction management |
| 5 | Inventory | Central stock management |
| 6 | Production | Production + inventory integration |
| 7 | Outlet Restocking | Main branch → outlet workflow |
| 8 | Workforce | Scheduling and assignments |
| 9 | Expenses | Operational expense management |
| 10 | Communication | Internal employee chat |
| 11 | Reporting | Business dashboards and reports |
| 12 | AI Forecasting | Decision-support intelligence |
| 13 | Integration | Complete business workflow |
| 14 | Hardening | Security, performance, UX |
| 15 | Testing | Systematic verification |
| 16 | Evaluation | Functionality + usability evaluation |
| 17 | Deployment | Operational system |

---

# 22. Definition of Done for Every Slice

Every slice must satisfy the following checklist.

```text
[ ] Requirement is clearly defined
[ ] Existing architecture inspected
[ ] Database migration implemented
[ ] Models implemented
[ ] Relationships implemented
[ ] Business rules implemented
[ ] Validation implemented
[ ] Authorization implemented
[ ] Livewire component implemented
[ ] Blade UI implemented
[ ] Error states implemented
[ ] Empty states implemented
[ ] Automated tests implemented
[ ] Manual workflow tested
[ ] Existing tests still pass
[ ] No debug code remains
[ ] Documentation updated
[ ] Git commit created
```

A slice that fails one of the critical items should remain incomplete.

---

# 23. LLM-Assisted Development Workflow

Each coding task should follow this sequence.

## Step 1: Give the agent context

Provide:

```text
Project purpose
Current architecture
Relevant requirements
Current phase
Existing related code
Business rules
Constraints
Expected result
```

## Step 2: Ask the agent to inspect before modifying

The agent should first identify:

- Relevant files
- Existing models
- Existing routes
- Existing components
- Existing tests
- Existing migrations

No blind code generation.

## Step 3: Request a short implementation plan

The agent should describe:

```text
Files to create
Files to modify
Database changes
Business logic
Tests
Potential risks
```

## Step 4: Implement

Only after the plan is understood.

## Step 5: Run tests

The agent should run relevant tests and inspect failures.

## Step 6: Review the diff

Check:

- Unnecessary files
- Duplicate logic
- Security issues
- Naming
- Business-rule violations
- Unrelated modifications

## Step 7: Commit

Use a focused commit.

Example:

```text
feat: implement customer pre-order workflow
```

---

# 24. Recommended LLM Task Size

### Good task

```text
Implement the customer pre-order submission flow.

Requirements:
- Customers do not authenticate.
- Customer provides required order information.
- Customer selects a pickup outlet.
- Order starts as Pending.
- Validate product availability.
- Persist order and order items.
- Display an order reference after successful submission.

Inspect the existing Product, Outlet, and customer storefront implementation before modifying anything.

Add feature tests covering:
1. successful order
2. invalid customer information
3. unavailable product
4. invalid outlet
5. empty order
```

### Bad task

```text
Build the entire customer ordering system and make it look nice.
```

The second prompt is essentially asking the LLM to become four developers, a product manager, a QA engineer, and a clairvoyant simultaneously.

---

# 25. Git Strategy

Use small, meaningful commits.

Example:

```text
chore: initialize Laravel project

feat: add employee authentication

feat: implement role-based access

feat: implement product catalog

feat: implement customer storefront

feat: implement customer pre-orders

feat: implement sales transactions

feat: implement inventory tracking

feat: implement production records

feat: implement outlet restocking

feat: implement workforce scheduling

feat: implement expense tracking

feat: implement internal messaging

feat: implement business reporting

feat: implement AI forecasting

test: add cross-module integration tests

chore: harden application for deployment
```

Avoid commits such as:

```text
final changes
updates
fix stuff
almost done
pls work
```

Those messages are not version control. They are archaeological evidence.

---

# 26. Branch Strategy

For a small student development team, keep branching simple.

```text
main
  │
  ├── feature/authentication
  ├── feature/products
  ├── feature/orders
  ├── feature/sales
  ├── feature/inventory
  ├── feature/production
  ├── feature/outlets
  ├── feature/workforce
  ├── feature/expenses
  ├── feature/reports
  └── feature/forecasting
```

Merge a feature only after:

```text
Implementation
      ↓
Tests
      ↓
Review
      ↓
Integration
      ↓
Merge
```

---

# 27. Practical LLM Context Files

Create a small set of project-level documentation specifically for AI-assisted development.

Recommended:

```text
/
├── AGENTS.md
├── README.md
├── docs/
│   ├── architecture.md
│   ├── business-rules.md
│   ├── database.md
│   ├── modules.md
│   ├── roles-and-permissions.md
│   ├── testing.md
│   └── ai-forecasting.md
```

## `AGENTS.md`

This should explain:

- Project purpose
- Technology stack
- Architecture
- Naming conventions
- Coding conventions
- Testing requirements
- RBAC rules
- Important business rules
- Forbidden changes
- Definition of done

This becomes the stable context an LLM receives before working on the application.

---

# 28. Business Rule Registry

Maintain one authoritative document for rules that affect multiple modules.

Examples:

```text
BR-001
Customers do not require accounts.

BR-002
Customers select a pickup outlet when ordering.

BR-003
The main branch handles general production operations.

BR-004
Outlets receive finished products from the main branch.

BR-005
Bakers handle production and restock preparation.

BR-006
Cashiers handle sales, orders, and expenses.

BR-007
Administrators manage overall operations.

BR-008
AI forecasts are decision-support recommendations, not guaranteed predictions.

BR-009
AI does not independently execute operational decisions.

BR-010
Internal communication is text-only.
```

The existing manuscript already establishes several of these constraints.

This registry is particularly important for LLM development because it prevents one agent from implementing a rule differently from another.

---

# 29. Milestone Structure

Instead of thinking only in terms of "Phase 1, Phase 2," organize the project into larger milestones.

## Milestone A: Foundation

```text
Phase 0
Phase 1
Phase 2
```

Result:

> Secure application with functioning product catalog.

---

## Milestone B: Transaction System

```text
Phase 3
Phase 4
```

Result:

> Customers can order and employees can process sales.

---

## Milestone C: Operations System

```text
Phase 5
Phase 6
Phase 7
Phase 8
Phase 9
```

Result:

> Core bakery operations are digitally managed.

---

## Milestone D: Management System

```text
Phase 10
Phase 11
```

Result:

> Employees communicate internally and management can monitor operations.

---

## Milestone E: Decision Support

```text
Phase 12
```

Result:

> PYRAMIS provides AI-assisted operational recommendations.

---

## Milestone F: Production Release

```text
Phase 13
Phase 14
Phase 15
Phase 16
Phase 17
```

Result:

> Integrated, tested, evaluated, and deployable PYRAMIS system.

---

# 30. The Critical Development Principle

The most important rule for the project is:

> **Never build a feature that cannot be demonstrated end-to-end.**

For example, do not spend two weeks creating an elaborate inventory database without allowing a user to perform an actual inventory workflow.

Instead:

```text
Ingredient
   ↓
Inventory Record
   ↓
Inventory UI
   ↓
Stock Movement
   ↓
Production Usage
   ↓
Updated Stock
   ↓
Test
```

Then move forward.

The same principle applies to every major capability.

```text
Customer
   ↓
Order
   ↓
Cashier
   ↓
Sale
   ↓
Inventory
   ↓
Production
   ↓
Outlet
   ↓
Reports
   ↓
AI Forecast
```

By the time the project reaches the final phases, the system should not consist of a collection of individually completed modules. It should be a **single connected operational system** representing the actual workflow of Purple Yam Malaybalay.

That distinction is the entire reason for using the modified vertical slice method.

# Product Requirements Document

## PYRAMIS: A Centralized Web-Based Bakery Management and AI-Assisted Forecasting System for Purple Yam Malaybalay

**Document Version:** 1.0
**Product:** PYRAMIS
**Business:** Purple Yam Malaybalay
**Product Type:** Unified Business Web Portal
**Primary Platform:** Web Application
**Status:** Capstone 2 Product Requirements Draft

---

## 1. Product Overview

### 1.1 Product Vision

PYRAMIS is a centralized web-based bakery management and AI-assisted forecasting system designed specifically for the operational structure of Purple Yam Malaybalay.

The product brings the bakery's major business activities into one centralized platform, replacing fragmented paper-based records and separate spreadsheet processes with integrated digital workflows.

The system combines two primary experiences within one web application:

**Customers → Purple Yam Malaybalay → Online Storefront → Order**

**Employees → PYRAMIS → Secure Login → Role-Based Workspace**

This unified portal structure is established in the current system design.

### 1.2 Product Purpose

The primary purpose of PYRAMIS is to improve:

* Operational efficiency
* Data accuracy
* Record centralization
* Production coordination
* Inventory monitoring
* Outlet coordination
* Workforce management
* Reporting
* Business decision-making

The manuscript identifies fragmented records, scheduling conflicts, delayed updates, inaccurate monitoring, overproduction, underproduction, stock shortages, and inefficient coordination as major problems the system is intended to address.

---

# 2. Problem Statement

Purple Yam Malaybalay currently relies on manual paper-based and partially digital processes for activities such as sales recording, inventory monitoring, production logging, employee scheduling, outlet restocking, expenses, and customer orders.

Because these activities are maintained across separate records and spreadsheets, information can become inconsistent, delayed, or difficult to consolidate. This makes it harder for management to monitor the overall business, coordinate the main branch and outlets, plan production, and make data-informed decisions.

PYRAMIS addresses this problem by centralizing the relevant operational data and providing role-specific workflows together with reporting and AI-assisted decision support.

---

# 3. Product Goals

### Primary Goals

1. **Centralize bakery operations** into a single web-based platform.
2. **Reduce dependence on manual records** and disconnected spreadsheets.
3. **Improve operational data accuracy and accessibility.**
4. **Improve coordination** between the main branch, outlets, and workforce.
5. **Provide integrated business reporting and analytics.**
6. **Support production and inventory planning.**
7. **Provide customers with a convenient online ordering experience.**
8. **Provide AI-assisted forecasts and recommendations** to support management decisions.

### Success Direction

The system should allow authorized personnel to obtain relevant operational information from one centralized platform rather than manually consolidating information from multiple sources.

The AI component should assist management rather than replace managerial judgment. The existing project explicitly defines forecasts as decision-support insights rather than absolute predictions.

---

# 4. Target Users

| User                         | Primary Needs                                                                       |
| ---------------------------- | ------------------------------------------------------------------------------------ |
| **Administrator / Manager**  | Business oversight, reports, forecasting, workforce management, outlet coordination |
| **Baker / Production Staff** | Production tasks, ingredient inventory, product stock, outlet restocking            |
| **Cashier / Sales Staff**    | Sales transactions, customer orders, expenses                                       |
| **Customer**                 | Product browsing, ordering, pickup selection, order tracking                        |

The current manuscript establishes administrator, baker, cashier, and customer roles.

The unified portal design further describes the employee workspace as role-based, with managers, sales staff, and production staff receiving different operational interfaces.

---

# 5. Product Structure

## 5.1 Public Customer Portal

The public-facing side is branded as **Purple Yam Malaybalay**.

Customers can:

* Browse available bakery products
* View product information
* Place pre-orders
* Provide required order information
* Select a pickup location
* Monitor order status

Customer registration is not required.

### Customer Journey

```text
Landing Page
     ↓
Browse Products
     ↓
Select Product
     ↓
Order / Pre-Order
     ↓
Fill Order Details
     ↓
Select Pickup Outlet
     ↓
Submit Order
     ↓
Track Order Status
```

---

# 6. Employee Portal

The internal employee portal is branded as **PYRAMIS**.

Employees access the system through secure authentication.

```text
Employee Sign In
       ↓
Authentication
       ↓
Role Identification
       ↓
Role-Based Dashboard
       ↓
Authorized Modules
```

Role-based access control ensures that users only receive access to functions appropriate to their responsibilities.

---

# 7. Functional Requirements

## FR-01: Authentication and Access Control

The system shall:

* Provide secure employee sign-in.
* Authenticate authorized employee accounts.
* Identify the employee's assigned role.
* Restrict access to unauthorized modules.
* Provide role-specific interfaces.
* Allow administrators to manage employee accounts where applicable.

### Acceptance Criteria

* An authorized employee can log in.
* An unauthorized user cannot access protected employee pages.
* Users can only access functions permitted by their assigned role.

---

# 8. FR-02: Sales Management

The system shall:

* Record sales transactions.
* Associate transactions with the appropriate sales location.
* Maintain sales records.
* Allow authorized users to monitor sales.
* Generate sales reports.
* Provide sales information for analytics and forecasting.

### Acceptance Criteria

A completed transaction must be stored in the centralized database and reflected in applicable sales reports and analytics.

---

# 9. FR-03: Customer Ordering

The system shall allow customers to:

* Browse products.
* Select products.
* Enter order information.
* Submit pre-orders.
* Select a pickup location.
* View order status.

The system shall not require customer account registration.

The customer portal does **not** include door-to-door delivery.

### Acceptance Criteria

A customer must be able to complete an order from product selection through order submission without creating an account.

---

# 10. FR-04: Inventory Management

The system shall:

* Maintain ingredient/material records.
* Monitor inventory quantities.
* Record ingredient usage.
* Monitor product stock where applicable.
* Provide inventory status.
* Support restocking monitoring.
* Include inventory information in reports.
* Provide inventory data for forecasting and recommendations.

### Acceptance Criteria

Authorized production personnel must be able to update inventory-related records, and authorized management users must be able to view the resulting inventory status.

---

# 11. FR-05: Production Management

The system shall support:

* Production planning.
* Production logging.
* Ingredient usage recording.
* Production tracking.
* Finished-product preparation.
* Outlet restock preparation.
* Product stock updates.

The production workflow follows the existing business structure, where the main branch prepares products before distributing finished products to outlets.

---

# 12. FR-06: Outlet Management

The system shall:

* Maintain outlet information.
* Monitor outlet-related operations.
* Record outlet sales.
* Support finished-product restocking.
* Track outlet restock activities.
* Provide outlet performance information.

### Business Rule

Outlets receive finished products from the main branch rather than independently performing the general production process.

This distinction matters. Otherwise the system starts modeling a business that does not actually exist, which is generally considered bad requirements engineering.

---

# 13. FR-07: Workforce Management

The system shall support:

* Employee records.
* Workforce assignments.
* Shift scheduling.
* Shift monitoring.
* Workforce activity reporting.

Managers/administrators are responsible for workforce scheduling and assignments.

---

# 14. FR-08: Expense Management

The system shall:

* Record business expenses.
* Associate expenses with relevant operational activities or locations where applicable.
* Monitor expenses.
* Generate expense reports.
* Include expense information in business analytics.

The existing system scope specifically includes expenses as part of the centralized business operations.

---

# 15. FR-09: Internal Communication

The system shall provide an internal text-based communication facility for employees.

It shall support:

* Sending text messages.
* Receiving text messages.
* Internal workforce communication.

It shall **not** support:

* Voice communication
* Video communication
* Photo messaging
* External messaging-platform integration

These limitations are explicitly established in the current scope.

---

# 16. FR-10: Reports and Analytics

The system shall provide reports and/or dashboards covering:

* Sales
* Inventory
* Production
* Expenses
* Workforce activities
* Outlet performance
* Overall business performance

The system should present relevant information using appropriate tables, charts, dashboards, and summaries.

---

# 17. FR-11: AI-Assisted Forecasting

The system shall provide AI-assisted decision support using available operational data.

### Inputs

Potential forecasting inputs include:

* Historical sales
* Production records
* Inventory information
* Expense records
* Other relevant operational data

The current project describes the forecasting approach as statistical trend analysis combined with AI-generated insights.

### Outputs

The system shall provide:

* Demand forecasts
* Production recommendations
* Inventory replenishment suggestions
* Operational insights
* Operational summaries

### AI Business Rule

AI-generated forecasts are **recommendations for decision support**.

They must not be represented as guaranteed or absolute predictions.

---

# 18. Role and Permission Matrix

| Capability           | Administrator |   Baker  |  Cashier | Customer |
| --------------------- | :-----------: | :------: | :------: | :------: |
| Business Dashboard   |       ✓       |  Limited |  Limited |     ✗    |
| Sales Management     |       ✓       |     ✗    |     ✓    |     ✗    |
| Customer Orders      |       ✓       |     ✗    |     ✓    |     ✓    |
| Inventory            |       ✓       |     ✓    |     ✗    |     ✗    |
| Production           |       ✓       |     ✓    |     ✗    |     ✗    |
| Outlet Restock       |       ✓       |     ✓    |     ✗    |     ✗    |
| Workforce Management |       ✓       |     ✗    |     ✗    |     ✗    |
| Expense Management   |       ✓       |     ✗    |     ✓    |     ✗    |
| Reports              |       ✓       | Relevant | Relevant |     ✗    |
| AI Forecasting       |       ✓       |     ✗    |     ✗    |     ✗    |
| Internal Chat        |       ✓       |     ✓    |     ✓    |     ✗    |
| Product Browsing     |       ✗       |     ✗    |     ✗    |     ✓    |

The precise permission granularity should ultimately follow the implemented RBAC rules and approved requirements. The broad responsibilities are supported by the current manuscript and portal design.

---

# 19. Non-Functional Requirements

## NFR-01: Usability

The system should provide:

* Clear navigation
* Role-specific dashboards
* Consistent interface components
* Understandable labels
* Responsive web interfaces
* Straightforward workflows

The project evaluates the system in terms of functionality and usability.

## NFR-02: Security

The system should:

* Authenticate employees.
* Enforce role-based authorization.
* Protect business records.
* Restrict access to protected functions.
* Avoid exposing administrative functions to customers.

## NFR-03: Data Integrity

The system should maintain consistent records across:

* Sales
* Inventory
* Production
* Expenses
* Orders
* Workforce
* Outlets

## NFR-04: Availability

The system requires electricity and stable Internet connectivity for proper operation, synchronization, and forecasting functionality.

## NFR-05: Maintainability

The application should follow the Laravel framework's established structure and conventions.

The supplied Laravel documentation describes Laravel's structured MVC-oriented conventions and predictable organization as beneficial for consistent development.

---

# 20. Technical Requirements

The current technical specification establishes a Laravel-centered architecture.

| Component               | Requirement        |
| ------------------------ | ------------------- |
| Application Framework   | Laravel 13          |
| Backend Language        | PHP 8.3+            |
| Architecture            | Laravel MVC         |
| Presentation            | Blade + Livewire    |
| Styling                 | Tailwind CSS        |
| ORM                     | Laravel Eloquent    |
| Database                | PostgreSQL          |
| Database Hosting        | Supabase            |
| AI Integration          | Laravel AI SDK      |
| AI Provider/Model       | OpenAI GPT-5 Nano   |
| Dependency Management   | Composer            |
| Frontend Tooling        | Node.js / NPM       |
| Version Control         | Git                 |
| Repository              | GitHub              |
| Development Environment | Visual Studio Code  |

The supplied Laravel documentation confirms that the Laravel AI SDK provides a unified interface for AI providers and supports agents, structured output, conversation storage, tools, embeddings, and related capabilities.

---

# 21. Data Requirements

The system should maintain centralized records for at least the following business entities:

```text
Users / Employees
      │
      ├── Roles
      └── Schedules

Products
      │
      ├── Sales
      └── Customer Orders

Ingredients / Inventory
      │
      └── Production

Production
      │
      └── Outlet Restocking

Outlets
      │
      ├── Sales
      ├── Expenses
      └── Restocking

Expenses

Reports / Analytics

Forecasting Data
      │
      └── AI-Assisted Insights
```

The exact database schema should be finalized during the system design stage rather than assumed by the PRD.

---

# 22. Key User Stories

### Administrator

> As an administrator, I want to view centralized business information so that I can monitor overall bakery performance.

> As an administrator, I want to view AI-assisted forecasts so that I can make better production and inventory decisions.

> As an administrator, I want to manage workforce schedules and assignments so that employee activities are coordinated.

> As an administrator, I want to monitor outlet restocking so that outlets receive the necessary finished products.

### Baker

> As a baker, I want to record production activities so that production records remain accurate.

> As a baker, I want to update ingredient and product stock information so that inventory remains current.

> As a baker, I want to prepare outlet restocking activities so that finished products can be distributed to outlets.

### Cashier

> As a cashier, I want to record sales transactions so that sales records are centralized.

> As a cashier, I want to manage customer orders so that pre-orders can be processed properly.

> As a cashier, I want to record expenses so that operational costs are documented.

### Customer

> As a customer, I want to browse bakery products online so that I can decide what to order.

> As a customer, I want to place a pre-order without creating an account so that ordering is convenient.

> As a customer, I want to select a pickup location so that I know where to collect my order.

> As a customer, I want to track my order status so that I know whether my order is ready.

---

# 23. MVP Definition

The **Minimum Viable Product** should prioritize the workflows necessary for PYRAMIS to function as a usable bakery management system.

### MVP Tier 1: Essential

1. Employee authentication
2. Role-based access control
3. Product management
4. Sales management
5. Customer ordering
6. Inventory management
7. Production management
8. Outlet management/restocking
9. Expense recording
10. Basic dashboards and reports

### MVP Tier 2: Decision Support

11. Workforce scheduling
12. Internal text communication
13. AI-assisted forecasting
14. Production recommendations
15. Inventory replenishment recommendations

### MVP principle

The system should first establish **accurate centralized operational data** before depending heavily on AI.

In other words:

```text
Accurate Data
     ↓
Centralized Records
     ↓
Reports & Analytics
     ↓
Historical Operational Data
     ↓
AI-Assisted Forecasting
     ↓
Management Decision Support
```

This ordering is particularly important because the project itself recognizes that incorrect, incomplete, or delayed user input affects reports, inventory records, and forecasting results.

---

# 24. Out of Scope

The following are explicitly outside the current product scope:

* Door-to-door delivery
* Customer account registration
* Voice communication
* Video communication
* Photo messaging
* External messaging-platform integration
* Treating AI forecasts as guaranteed predictions
* Fully autonomous business decision-making
* Independent outlet production where the existing business model does not support it
* Unrestricted scaling to unrelated business structures

The system is specifically designed around Purple Yam Malaybalay's current operational structure, and expansion to substantially different business structures may require modification.

---

# 25. Success Criteria

PYRAMIS can be considered successful when the implemented system demonstrates that it can:

1. Centralize relevant bakery operational records.
2. Provide appropriate role-based access.
3. Record and retrieve sales information.
4. Process customer pre-orders.
5. Monitor inventory.
6. Record and monitor production.
7. Coordinate outlet restocking.
8. Manage workforce schedules and assignments.
9. Record expenses.
10. Generate operational reports.
11. Provide usable dashboards and analytics.
12. Generate AI-assisted forecasting insights.
13. Support management decision-making.
14. Provide a usable customer ordering experience.
15. Reduce dependence on fragmented manual records.

The capstone's stated evaluation focus is functionality and usability, making these two dimensions especially important for validation.

---

# 26. Product Workflow

The overall product can be represented as:

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

---

# 27. Product Principles

The PRD should follow these principles throughout development:

### 1. Centralization First

Operational information should have a single authoritative source.

### 2. Role-Based by Default

Users should see and access only what is relevant to their responsibilities.

### 3. Workflow Before Features

Every feature should correspond to an actual bakery workflow.

### 4. AI as Decision Support

AI should assist human decision-making rather than pretend to possess prophetic bakery powers.

### 5. Data Quality Matters

Forecasting is only as useful as the operational data feeding it.

### 6. Simplicity for SMEs

The system should solve Purple Yam Malaybalay's problems without reproducing the complexity of enterprise ERP software.

---

# 28. Requirements Traceability

| Business Problem                 | Product Response                  |
| ---------------------------------- | ----------------------------------- |
| Fragmented records               | Centralized database              |
| Manual sales recording           | Sales management                  |
| Poor inventory monitoring        | Inventory module                  |
| Production coordination problems | Production management             |
| Outlet coordination issues       | Outlet/restocking module          |
| Workforce scheduling problems    | Workforce management              |
| Expense tracking issues          | Expense module                    |
| Customer ordering limitations    | Online storefront                 |
| Delayed reporting                | Centralized reports/dashboard     |
| Limited decision support         | AI-assisted forecasting           |
| Internal coordination issues     | Text-based internal communication |

The relationship between these problems and the proposed system is directly consistent with the project's stated context and purpose.

---

## 29. Final Product Definition

**PYRAMIS is a centralized web-based bakery management platform for Purple Yam Malaybalay that combines customer ordering and internal business operations within one unified web application. It provides role-based management of sales, inventory, production, outlets, workforce, expenses, orders, reporting, and internal communication, while incorporating AI-assisted forecasting to provide demand forecasts, production recommendations, inventory replenishment suggestions, and operational insights.**

The **customer-facing experience is branded Purple Yam Malaybalay**, while the **employee-facing management workspace is branded PYRAMIS**.

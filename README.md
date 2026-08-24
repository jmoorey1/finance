# Home Finances System

A self-hosted household finance application for recording transactions, reconciling accounts, managing budgets, forecasting cash flow, monitoring funding risk, tracking projects and employment expenses, and maintaining payroll history.

The application is built primarily in PHP and MySQL. Python is used for transaction-file parsing and forecast generation.

The **database is the source of truth**. Uploaded files, logs and generated runtime state support the application but are not intended to become parallel financial records.

---

## Contents

- [Core operating model](#core-operating-model)
- [Financial periods](#financial-periods)
- [Features](#features)
  - [Dashboard](#dashboard)
  - [Accounts](#accounts)
  - [Transaction import](#transaction-import)
  - [Review and approval](#review-and-approval)
  - [Ledger and transactions](#ledger-and-transactions)
  - [Categories](#categories)
  - [Payees and matching](#payees-and-matching)
  - [Transfers](#transfers)
  - [Statements and reconciliation](#statements-and-reconciliation)
  - [Predicted transactions and forecasting](#predicted-transactions-and-forecasting)
  - [Flexible planned income](#flexible-planned-income)
  - [Budgets](#budgets)
  - [Funding Health](#funding-health)
  - [Solvency Reserve](#solvency-reserve)
  - [Project Fund](#project-fund)
  - [Projects, trips and funds](#projects-trips-and-funds)
  - [Watcher](#watcher)
  - [Payroll](#payroll)
  - [Reporting and analytics](#reporting-and-analytics)
  - [Email reporting](#email-reporting)
- [Security](#security)
- [Application architecture](#application-architecture)
- [Important data-model concepts](#important-data-model-concepts)
- [Configuration](#configuration)
- [Database migrations](#database-migrations)
- [Testing and regression checks](#testing-and-regression-checks)
- [Healthcheck](#healthcheck)
- [Repository structure](#repository-structure)
- [Change implementation process](#change-implementation-process)
- [Current limitations and deliberate boundaries](#current-limitations-and-deliberate-boundaries)

---

# Core operating model

The system is designed around several principles.

## MySQL is authoritative

Financial data belongs in the database wherever practical.

This includes:

- accounts
- transactions
- transaction splits
- transfer groups
- categories
- payees and matching rules
- statements
- budgets
- predicted transactions and generated instances
- flexible planned income
- projects and earmarks
- watcher alerts
- import history
- payroll
- payroll-to-finance links

Uploaded files and logs are operational artefacts rather than alternative sources of financial truth.

## Imported transactions are reviewed before becoming ledger transactions

Bank and card files are first parsed into `staging_transactions`.

The Review workflow is then used to:

- approve ordinary transactions
- categorise transactions
- split transactions across categories
- identify payees
- review probable duplicates
- confirm or reject predicted-transaction matches
- create or complete transfers

Only approved transactions are written into the main ledger.

## Reporting uses ledger lines rather than assuming one category per transaction

The `ledger_lines` database view provides the canonical reporting representation of:

- ordinary actual transactions
- split transaction lines
- open predicted income and expenditure
- both sides of predicted transfers

This allows reporting code to reason about a financial line rather than having every report separately reproduce split/prediction logic.

## Transfers are first-class objects

Transfers are represented by `transfer_groups`.

The underlying transaction rows:

- belong to the relevant accounts
- have `type = 'transfer'`
- carry no income/expense category
- are grouped by `transfer_group_id`

A complete transfer has two real sides.

A transfer for which only one bank-side transaction has arrived can temporarily contain a `PLACEHOLDER` counterparty. The placeholder is replaced when the corresponding transaction is later imported.

## Predictions retain their history

A predicted instance is not deleted when it is fulfilled.

Instead, fulfilment state and the actual transaction or transfer group responsible for fulfilment are recorded against the prediction.

This preserves an audit trail between what was expected and what actually happened.

---

# Financial periods

There are two separate period models in the application.

## Household finance period

Household budgeting and most finance reporting use a custom financial month:

**13th of one calendar month through 12th of the next.**

For example:

- 13 January – 12 February
- 13 February – 12 March

Household financial YTD starts on **13 January**.

This period model is used by areas such as:

- dashboard insights
- budgets
- budget performance
- weekly finance reporting
- solvency planning

## Payroll tax period

Payroll uses UK tax-year semantics based on the **6 April** tax-year boundary.

Payslips and employment expenses can therefore be analysed by:

- tax year
- tax month
- calendar month

The household financial-month model and payroll tax-period model should not be treated as interchangeable.

---

# Features

# Dashboard

`public/index.php`

The dashboard provides the main operational overview.

It includes:

- short-term Funding Health
- current account balances
- last transaction dates
- last successful transaction-file imports
- import freshness indicators
- open Watcher alerts
- forecast-engine status
- monthly finance insights
- upcoming predicted transactions
- missed predicted transactions
- statement/reconciliation warnings

The dashboard can also trigger the forecast engine.

Automatic UI-triggered forecasting is throttled and locked so normal page requests do not repeatedly run overlapping forecast jobs.

---

# Accounts

Accounts support the following types:

- current
- credit
- savings
- house
- investment
- loan

Account configuration includes:

- account name
- institution
- active/inactive state
- starting balance
- statement day

Credit-card accounts additionally support:

- payment day
- account from which the card is paid
- full-balance repayment
- minimum repayment
- fixed repayment amount
- minimum-payment floor
- minimum-payment percentage
- minimum-payment calculation method
- promotional APR
- promotional APR end date
- standard APR

Starting balances form part of account-balance calculations and should therefore be maintained carefully.

---

# Transaction import

`public/upload.php`

The system supports manual file import using:

- OFX
- CSV

## OFX

OFX accounts are resolved using database-backed mappings in `ofx_account_map`.

The parser can identify the corresponding finance account from the OFX account/bank identifiers.

Unresolved OFX accounts are skipped and reported rather than silently assigned to an arbitrary account.

## CSV

CSV imports require the destination account to be selected in the UI.

The current card-oriented parser:

- considers `BILLED` rows
- ignores non-billed rows
- derives debit/credit sign correctly
- records merchant information
- preserves additional merchant/card detail in the original memo
- repairs the known case where an unquoted comma in the merchant field has produced too many CSV columns
- reports malformed rows rather than silently loading bad data

## Import-run history

Every upload creates an import-run record.

The Upload page shows recent runs including:

- start time
- source filename
- file type
- parser
- account or accounts touched
- status
- parser summary

Successful import activity is also used to determine account-import freshness on the dashboard and by Watcher.

## Duplicate handling during import

There are currently two different duplicate paths.

### Exact duplicates

An exact repeat is detected using:

- account
- date
- amount
- canonicalised description

Existing committed transactions and already-staged transactions are both considered.

The parser also accounts for multiple genuinely identical rows within the same source file.

**Exact duplicates are currently suppressed during import and are not inserted into Review.**

### Potential duplicates

A transaction may instead be staged as `potential_duplicate` when:

- account matches
- amount matches
- dates are within the configured three-day comparison window
- descriptions are considered sufficiently similar

These rows are deliberately sent to Review for a user decision.

---

# Review and approval

`public/review.php`

Imported staging transactions are grouped into:

- New
- Fulfils Prediction
- Potential Duplicate
- All

## New transactions

For ordinary new transactions, Review can:

- resolve a likely payee
- show the pattern responsible for the payee match
- suggest categories from previous behaviour
- preselect the strongest category suggestion
- approve as a normal income/expense transaction
- split across multiple categories
- treat the transaction as a transfer
- delete the staging row

Category suggestions are built from:

1. matched-payee history
2. exact-description history
3. broader description history

Suggestions are de-duplicated and the highest-ranked suggestion is preselected.

## Potential duplicates

Potential duplicates show the existing transaction believed to match.

The user can:

- confirm the duplicate
- reject the duplicate

Confirmation revalidates the match before making any change. Account, amount, date proximity and description similarity are checked again so stale Review data cannot blindly modify another transaction.

Rejecting the candidate returns the staging row to the normal Review workflow.

## Predicted matches

Where an imported transaction appears to fulfil an open predicted instance, Review presents that relationship separately.

The user can:

- confirm the predicted match
- reject the predicted match

A rejected match returns to ordinary categorisation.

A confirmed match records fulfilment against the existing predicted instance rather than deleting the prediction.

---

# Ledger and transactions

The Ledger is the principal detailed financial view.

It uses `ledger_lines` so that ordinary transactions and split transactions are represented consistently.

The canonical ledger representation includes:

- source
- line role
- date
- account
- counterparty account where relevant
- amount
- resolved display description
- raw description
- category and parent category
- project
- earmark/fund
- transaction/split identifiers
- prediction identifier
- actual/predicted flag
- editability

Ordinary transaction descriptions can be replaced for display purposes by their resolved payee name while the original transaction description remains available separately.

The system also supports:

- manual transaction entry
- transaction editing
- project attribution
- fund/earmark attribution
- split editing through the transaction model

---

# Categories

Categories are hierarchical.

Supported financial category types are:

- income
- expense

Transfers are no longer represented by a normal category.

Top-level expense categories can carry planning metadata including:

- fixed or variable
- essential or discretionary
- budget order
- Watcher budget treatment
- Watcher timing treatment

Watcher budget treatment supports:

- normal
- reimbursable
- ignore

Watcher timing treatment supports:

- operational
- flexible
- ignore

Subcategories inherit the relevant Watcher behaviour from their parent category.

The old synthetic `Split/Multiple Categories` category is not used as the canonical representation of a split transaction. Split parents instead have no category and their `transaction_splits` carry the individual category allocations.

---

# Payees and matching

`public/payees.php`

Payees and description patterns are database-managed.

The UI supports:

- creating payees
- renaming payees
- deleting unused payees
- creating match patterns
- editing patterns
- deleting patterns
- assigning explicit pattern priority
- testing a sample transaction description against the live matching rules

A payee already referenced by transactions cannot be deleted.

## Deterministic best-match selection

When several patterns match the same description, the result is selected deterministically.

Matching precedence considers:

1. explicit priority
2. exactness
3. anchored pattern specificity
4. literal content length
5. wildcard count
6. total pattern length
7. stable ID ordering

Payee matching is used by:

- Review
- approved transactions
- predicted display descriptions
- duplicate reconciliation
- ledger reporting

---

# Transfers

Transfers are represented independently of income/expense categories.

## Two-sided transfer

If both sides are available in staging, Review can pair them and create:

- one `transfer_group`
- one transaction on the source account
- one transaction on the destination account

The sides must:

- use different accounts
- carry opposite signed amounts
- balance to zero

## One-sided transfer

If the counterparty transaction has not yet been imported, the system can create:

- the real transaction
- a `PLACEHOLDER` transaction on the other account
- a partial `transfer_group`

The transfer metadata records the expected:

- source account
- destination account
- amount
- transfer date
- completion status

## Completing a partial transfer

When the missing bank transaction later arrives, Review can match it to the existing placeholder.

The real transaction replaces the placeholder and the existing transfer group becomes complete.

Guardrails prevent creation of a second partial transfer when a suitable placeholder already exists.

## Predicted transfers

Predicted transfers use the same transfer-group model.

If both sides arrive together, the prediction can be fully fulfilled immediately.

If only one side has arrived:

- the transfer becomes partial
- a placeholder represents the missing side
- the predicted instance records partial fulfilment

When the second side arrives:

- the placeholder is removed
- the transfer group becomes complete
- the predicted instance becomes fully fulfilled

---

# Statements and reconciliation

`public/statements.php`

The application can record account statements and reconcile ledger activity against them.

A statement stores:

- account
- starting reconciled position
- statement date
- ending balance
- reconciliation status

For configured credit-card accounts it can also derive:

- payment due date
- minimum payment due

Credit-card payment dates are moved from weekends to the next weekday.

The reconciliation workflow marks the transactions belonging to the statement and allows completed statements to be reviewed later.

Account and statement data are also used by forecasting and dashboard warnings.

---

# Predicted transactions and forecasting

`public/predicted.php`

The prediction system has two levels:

- recurring prediction rules
- generated or manually-created prediction instances

## Recurring rules

Recurring rules can be:

- created
- edited
- activated
- deactivated

Rules define the recurrence and amount behaviour used to generate future instances.

Changing a rule refreshes its future open instances and causes the forecast to be regenerated.

## Prediction instances

The UI shows recent and future instances with statuses including:

- Planned
- Fulfilled
- Partial
- Missed
- Skipped

Manual one-off instances can be:

- created
- edited
- deleted

One-offs may be treated by planning logic as either:

- additional to budget
- budget-backed

This prevents deliberately scheduled one-off activity from necessarily being counted on top of a budget that already included it.

## Missed predictions

An unresolved prediction whose scheduled date has passed is surfaced as missed.

A missed prediction can be:

- matched to an actual transaction
- skipped
- reopened after being skipped

Reconciliation records the actual source of fulfilment rather than removing the predicted history.

## Forecast generation

Forecast generation:

- produces future `predicted_instances`
- is protected by a lock
- is throttled for normal UI-triggered runs
- records job status
- records run timing
- exposes failure output on the dashboard
- can be manually forced from the dashboard

Open predictions feed downstream:

- ledger projections
- budget forecasting
- Funding Health
- solvency
- Project Fund
- Watcher
- reporting

---

# Flexible planned income

Flexible planned income is separate from recurring predictions.

It is intended for income where:

- the amount is known or reasonably estimated
- the receiving account is known
- the exact date is uncertain
- a date window is more realistic than a fixed prediction

An event stores:

- description
- account
- income category
- amount
- date window
- timing strategy
- budget month
- active state

The planning engine resolves an assumed date within the configured window.

Flexible planned income is an **account-level timing tool**. It does not replace the corresponding monthly budget.

For solvency purposes it acts as a budget-backed timing adjustment: expected income already present in a budget can be moved from its budget month to the month/date at which it is now expected to land, preventing double counting.

---

# Budgets

`public/budgets.php`

Budgets are maintained against top-level income and expense categories.

The annual budget screen allows monthly values to be entered across the year and displays:

- monthly income
- monthly expenditure
- monthly net
- annual totals
- running net position

Budget periods follow the household **13th–12th** financial-month convention.

Budget data is reused throughout the system rather than maintained independently by individual reports.

It contributes to:

- budget performance
- insights
- weekly reporting
- Watcher
- solvency
- Project Fund
- planning adjustments

---

# Funding Health

`public/funding_health.php`

Funding Health is the **primary short-term operational cash-funding view**.

Its purpose is to answer:

> Do current accounts need money moved into them soon, and can the designated savings reserve safely provide it?

Selectable action windows include:

- 14 days
- 21 days
- 31 days
- 45 days
- 60 days

The engine considers:

- cleared balances as of the previous night
- dated predicted transactions
- flexible planned income
- projected current-account shortfalls
- required support transfers
- reserve-account events

The view shows:

- current reserve balance
- projected balance after today's uncleared events
- total required support
- lowest projected reserve balance
- actual funding gap
- required transfer dates
- affected current accounts
- amount that can safely be funded
- reserve balance before and after support
- dated reserve event stream

## Earmarks in Funding Health

Soft earmarks are shown for context but **do not reduce transferable cash in the primary Funding Health calculation**.

This is deliberate.

Long-range solvency and Project Fund calculations treat earmarks differently.

---

# Solvency Reserve

`public/solvency.php`

Solvency is a secondary, longer-range planning diagnostic.

Its purpose is to determine how much of a savings reserve must remain protected to fund future household commitments.

The model combines:

- past actual household net
- current-month actuals to date
- remaining current-month budget
- future monthly budgets
- manual one-off planning adjustments
- budget-backed one-offs
- flexible planned-income rephasing
- earmarks

The output includes:

- current reserve balance
- total earmarks
- required reserve from today
- amount available above reserve
- peak reserve requirement
- lowest projected amount above reserve
- month-by-month reserve timeline

Unlike the primary short-term Funding Health view, earmarks are deducted when calculating long-range reserve capacity.

## Within-month timing overlay

Solvency also contains a shorter dated timing overlay.

This detects cases where a month may be solvent overall but a current account still runs short before expected income arrives.

It shows:

- date a deficit begins
- worst projected day
- required top-up
- amount safely supportable from reserve
- reserve breach, if any
- likely positive events during the deficit window

---

# Project Fund

`public/project_fund.php`

Project Fund uses the solvency reserve engine to estimate how much money is available for discretionary major spending without compromising future household solvency.

Conceptually:

**Project Fund = projected reserve balance - earmarks - required future reserve**

The page shows:

- Project Fund available now
- expected Project Fund by month
- peak reserve requirement
- lowest Project Fund during the year
- within-month funding risks

A target-spend scenario can also be entered.

The system then reports:

- whether the spend is supportable now
- residual Project Fund after spending
- the earliest future month in which the target becomes supportable
- whether the target remains unsupported throughout the current planning horizon

---

# Projects, trips and funds

## Projects and trips

`public/projects.php`

Projects provide an analytical tag for expenditure associated with a specific activity, for example:

- a home project
- a holiday
- a one-off event

Projects can be created from the UI.

Project reporting uses `ledger_lines`, so split-level project attribution is included in the totals.

The summary shows:

- project name
- description
- first spend
- last spend
- total spend

Each project links back into the detailed Ledger.

## Earmarks / funds

`public/earmarks.php`

Earmarks represent money associated with named funds or purposes.

The fund summary exposes:

- fund name
- description
- first spend
- last spend
- remaining/recorded position

Earmarks also feed the longer-range solvency and Project Fund calculations.

---

# Watcher

`public/watcher_alerts.php`

Watcher is the finance-quality and risk-monitoring layer.

It generates persistent alerts with:

- severity
- status
- alert type
- title
- related account where relevant
- first/last detection
- evidence
- recommended action

Alerts can be viewed as:

- open
- resolved
- all

Current Watcher coverage includes:

- funding-health problems
- stale account imports
- recurring prediction-rule drift
- likely missing recurring patterns
- accumulating missed predictions
- unresolved Review backlog
- budget burn risk
- unrealistic monthly budgets
- budget timing mismatch

Recommended actions can contain:

- an explanatory headline
- supporting detail
- a direct link to the relevant part of the application
- suggested values where the engine can provide them

The dashboard shows a reduced high-priority subset to avoid flooding the main page with duplicate alerts.

---

# Payroll

The Payroll module stores employment and payslip history independently from the transaction ledger, while allowing explicit reconciliation between the two.

Main pages include:

- `public/payroll.php`
- `public/payroll_payslips.php`
- `public/payroll_payslip.php`
- `public/payroll_payslip_edit.php`
- `public/payroll_finance_settings.php`

## People and employments

Payroll separates a person from an employment.

This allows employment-specific data such as:

- employer
- employee number
- tax reference
- employment dates
- employment status

to remain distinct from the person.

## Payslips

Payslips can be:

- viewed
- created
- edited

A payslip records:

- employee/employment
- pay date
- tax year
- tax month
- tax code
- annual salary
- payment method
- source-statement totals
- detailed pay/deduction lines

Payslip header and line changes are saved together transactionally.

## Payslip categories

Payroll lines are classified independently of household finance categories.

Current payroll categories include:

- Basic Pay
- Benefits
- Pre-tax Deductions
- Additional Earnings
- Bonus
- Pension
- Taxes
- Post-tax Deductions

Each category is classified as either Pay or Deduction.

## Statement values versus calculated values

The module deliberately preserves values printed on the source payslip separately from values calculated from detailed lines.

Source statement fields include:

- total earnings
- total deductions
- net pay
- amount paid

This matters because some payslips contain notional or non-cash elements for which summing visible lines does not necessarily describe the actual bank settlement correctly.

Where all three source arithmetic values are supplied, the application validates:

**net pay = total earnings - total deductions**

## Notional payroll lines

Individual payroll lines can be marked as notional.

Notional pay remains visible in payroll analysis but is separated from cash earnings when deriving settlement values.

## Settlement amount

For payroll-to-finance reconciliation, settlement uses the strongest available evidence.

The current precedence is:

1. source `Amount Paid`
2. source `Net Pay`
3. calculated cash lines where appropriate

The source used for settlement is retained and visible.

## Payroll reporting

The Payroll dashboard provides:

- latest payslip
- current salary
- current tax code
- current tax-year YTD figures
- gross pay
- basic pay
- bonus
- tax
- pension
- net pay
- recent payslips
- tax-year history

## Employment expenses

The payroll schema also stores employment-expense data including:

- expense reports
- individual expenses
- expense category
- GBP value
- original currency/value
- merchant
- country
- description
- payment method
- corporate/personal funding classification
- tax period

Payroll reporting can show employment-expense totals and distinguish corporate-funded from personally-funded expenses.

The expense-report data model and reporting are live, but a general write-enabled expense-report management UI has not yet been added.

## Payroll ↔ Finance linkage

Each employment can have Finance matching context defining:

- salary receiving account
- optional expected income category
- optional recurring salary prediction rule
- linkage start date
- transaction candidate window

The linkage start date cannot precede **1 January 2020**.

The receiving account acts as a hard matching constraint.

Category and prediction-rule information provide context/confidence rather than blindly altering ledger transactions.

## Payslip transaction reconciliation

A payslip can be explicitly linked to an existing Finance transaction.

The system can:

- discover candidate transactions
- create a payslip-to-transaction link
- unlink an existing link
- track matched amount
- report settlement status

Possible linkage states include:

- unconfigured
- out of scope
- no settlement
- unlinked
- partial
- settled
- overlinked

A Finance transaction can only be linked to one payslip.

Payroll linking is deliberately non-destructive:

- it does not rewrite the bank transaction
- it does not implicitly fulfil a prediction
- it does not create a new transaction merely because a payslip exists

---

# Reporting and analytics

The application contains a number of reporting views built on the shared finance data model.

These include:

## Monthly summary

Current financial-month actual and planning performance.

## Year to date

Household financial YTD reporting using the 13th–12th period model.

## Spending insights

Generated observations based on current actual and planned activity.

## Budget performance

Comparison of:

- budget
- actual
- forecast
- variance

## Category reports

Drill-down reporting for top-level categories.

## Subcategory reports

Detailed reporting for child categories.

## Monthly analytics

Cross-category and period analysis.

## Year-on-year analytics

Comparison of household performance between years.

## Ledger

Detailed filtering of financial lines including actuals, splits, predictions, accounts, projects and funds.

## Job Expense Report

Job-related spending can be reported:

- by configured person
- combined
- over the last 12 months
- current financial year
- all time
- custom date range

The report calculates:

- outgoings
- incoming offsets/reimbursements
- net position
- transaction count
- running net

The currently displayed report can also be emailed from the UI.

---

# Email reporting

## Weekly Home Finances Digest

`scripts/email_weekly_summary.php`

The weekly digest is a CLI-only job.

It includes:

- Funding Health snapshot
- Watcher snapshot
- finance headlines
- monthly variable-expense performance
- YTD variable-expense performance
- budget
- actual
- remaining forecast
- variance
- essential/discretionary grouping

The job supports:

- configured recipients
- dry-run mode
- effective-date override
- recipient override
- database-backed run logging
- advisory locking to prevent overlapping sends

Example dry run:

```bash
php8.2 scripts/email_weekly_summary.php --dry-run
```

An alternative effective date can be supplied for testing:

```bash
php8.2 scripts/email_weekly_summary.php --dry-run --date=2026-08-24
```

---

# Security

## Authentication

Authentication is enabled through the application feature configuration.

The current implementation uses one configured username/password credential rather than a multi-user role model.

Credentials are read from environment configuration.

Relevant environment variables are:

```text
FINANCE_AUTH_USERNAME
FINANCE_AUTH_PASSWORD_HASH
```

The password is stored as a PHP password hash, not plaintext.

A suitable hash can be generated with:

```bash
php8.2 -r "echo password_hash('replace-this-password', PASSWORD_DEFAULT), PHP_EOL;"
```

The application session:

- uses its own `finance_session` cookie
- is HTTP-only
- uses `SameSite=Lax`
- uses the secure-cookie flag when served over HTTPS
- regenerates the session ID after successful authentication

The following routes are allowlisted from the normal authentication gate:

- login
- logout
- healthcheck

## CSRF protection

CSRF enforcement is enabled through application feature configuration.

POST requests require a valid session token unless explicitly exempted.

The shared layout automatically injects CSRF protection into normal POST forms and exposes the token for AJAX requests.

Invalid requests receive HTTP status `419`.

## Database credentials

Database credentials are read from the environment.

`FINANCE_DB_PASSWORD` is required; the application will fail rather than silently fall back to a password embedded in source code.

PDO uses:

- exception mode
- native prepared statements
- disabled emulated prepares

---

# Application architecture

The application is intentionally lightweight.

## Web layer

PHP pages in `public/` provide:

- user interface
- request handling
- orchestration of domain services

Bootstrap is used for the UI, with jQuery still used by some interactive pages.

## Domain/application logic

Reusable logic lives primarily in:

```text
scripts/
scripts/lib/
```

This includes engines and helpers for:

- forecasting
- funding
- solvency
- payee matching
- Review
- transfer groups
- finance periods
- Watcher
- payroll
- email generation
- reporting

## Import and forecast Python

Python is used where it is already established for:

- OFX parsing
- CSV parsing
- prediction-instance generation

Python database configuration is sourced from the same environment-based finance configuration rather than maintaining independent credentials in the scripts.

## Database

MySQL contains the canonical business data and shared reporting views.

`config/schema.sql` is the exported current-state schema snapshot.

Forward schema changes are governed by `/migrations`.

---

# Important data-model concepts

The principal tables/views include the following.

| Area | Primary objects |
|---|---|
| Accounts | `accounts`, `ofx_account_map` |
| Ledger | `transactions`, `transaction_splits`, `ledger_lines` |
| Transfers | `transfer_groups` |
| Import | `staging_transactions`, import-run tables |
| Classification | `categories`, `payees`, `payee_patterns` |
| Reconciliation | `statements` |
| Budgeting | `budgets` |
| Forecasting | `predicted_transactions`, `predicted_instances` |
| Flexible income | `planned_income_events` |
| Projects/funds | `projects`, `earmarks` |
| Watcher | Watcher alert/state tables |
| Payroll | `payroll_people`, `payroll_employments`, `payroll_payslips`, `payroll_line_items` |
| Payroll expenses | `payroll_expense_reports`, `payroll_expenses` and supporting lookup tables |
| Payroll reconciliation | `payroll_finance_mappings`, `payroll_payslip_transaction_links` |
| Schema management | `schema_migrations` |

---

# Configuration

Application configuration is split between:

```text
config/app.php
.env
```

## `.env`

Start from:

```text
.env.example
```

The database variables are:

```text
FINANCE_DB_HOST
FINANCE_DB_NAME
FINANCE_DB_USER
FINANCE_DB_PASSWORD
FINANCE_DB_CHARSET
```

Authentication additionally uses:

```text
FINANCE_AUTH_USERNAME
FINANCE_AUTH_PASSWORD_HASH
```

Do not commit `.env`.

Environment variables already supplied by the process take precedence over values in the file.

## `config/app.php`

Non-secret operational configuration lives in `config/app.php`.

This includes areas such as:

- application name
- environment
- timezone
- base URL
- maintenance mode
- logging
- weekly email
- Watcher
- feature switches

The configured timezone is:

```text
Europe/London
```

The application is currently deployed under:

```text
/finance
```

Several internal routes assume this base path.

---

# Database migrations

Database schema changes use tracked, forward-only SQL migrations.

Migration files live in:

```text
migrations/
```

## Check migration state

```bash
php8.2 scripts/admin/migrate.php status
```

## Apply pending migrations

```bash
php8.2 scripts/admin/migrate.php migrate
```

## Create a new migration

```bash
php8.2 scripts/admin/new_migration.php descriptive_change_name
```

## Refresh the schema snapshot

After applying a schema change:

```bash
bash scripts/admin/export_schema.sh
```

Both the migration and updated `config/schema.sql` should be committed.

## Do not edit an applied migration

The migration runner records checksums.

Changing a migration that has already been applied is reported as migration drift.

Create a new forward-only migration instead.

## Legacy database history

The application database predates the migration framework.

The migration directory therefore represents tracked changes from the point at which migration governance was introduced; it is not a complete historical reconstruction of every table since the application's creation.

`config/schema.sql` is the current-state schema reference.

See:

```text
docs/MIGRATIONS.md
```

for baseline and legacy-history details.

---

# Testing and regression checks

The project contains targeted source/fixture checks for high-risk workflows.

## Review and import regression suite

Run:

```bash
php8.2 scripts/tests/run_review_import_fixture_checks.php
```

The suite is DB-free and validates important behaviour around:

- duplicate confirmation
- regular categorisation
- split categorisation
- manual transfers
- placeholder reuse
- predicted-transfer fulfilment
- CSV parsing
- CSV repair behaviour
- malformed rows
- non-billed rows

A defect in an already-covered Review/import workflow should add or update a fixture rather than merely relying on the old tests continuing to pass.

See:

```text
docs/REGRESSION_FIXTURES.md
```

## Payroll checks

The repository also contains targeted payroll test scripts covering areas such as:

- payroll read/display behaviour
- payslip write validation
- statement semantics
- Finance linkage

These should be run whenever the associated payroll area is changed.

## PHP syntax checks

Changed PHP files should be linted explicitly, for example:

```bash
php8.2 -l public/example.php
```

## Working-tree checks

Before committing:

```bash
git diff --check
git status --short
```

---

# Healthcheck

`public/healthcheck.php`

The healthcheck is available in HTML and JSON forms.

It checks:

- PHP runtime
- database connectivity
- core table access
- uploads-directory writability
- logs-directory writability
- maintenance-mode state

JSON format:

```text
/finance/public/healthcheck.php?format=json
```

The healthcheck remains accessible during maintenance mode and is excluded from the normal authentication gate.

---

# Repository structure

```text
.
├── config/
│   ├── app.php
│   ├── db.php
│   ├── env.php
│   └── schema.sql
│
├── docs/
│   ├── CHANGE_IMPLEMENTATION.md
│   ├── MIGRATIONS.md
│   └── REGRESSION_FIXTURES.md
│
├── layout/
│   ├── header.php
│   └── footer.php
│
├── migrations/
│   └── *.sql
│
├── public/
│   └── web application pages and action handlers
│
├── scripts/
│   ├── admin/
│   │   └── migrations, schema export, audits and repair utilities
│   │
│   ├── lib/
│   │   └── reusable finance/reporting/domain helpers
│   │
│   ├── tests/
│   │   └── regression and smoke checks
│   │
│   ├── parse_csv.py
│   ├── parse_ofx.py
│   ├── predict_instances.py
│   └── application engines and CLI jobs
│
├── tests/
│   └── fixtures/
│
├── uploads/
│   └── uploaded transaction files
│
└── logs/
    └── runtime/application logs
```

---

# Change implementation process

All non-trivial repository changes should follow:

```text
docs/CHANGE_IMPLEMENTATION.md
```

The expected process is broadly:

1. inspect the current implementation before changing it
2. determine whether schema migration, fixture coverage or direct SQL validation is required
3. protect files before modification where appropriate
4. implement the change deterministically
5. lint changed PHP
6. run relevant regression checks
7. perform focused functional validation
8. inspect the working tree and diff
9. refresh `config/schema.sql` after schema changes
10. commit only once validation is complete

Schema evolution must go through `/migrations` rather than undocumented manual changes to the live database.

The README itself should not be used as a substitute for inspecting current code when implementing a change.

---

# Current limitations and deliberate boundaries

The following are current behaviours and should not be mistaken for missing documentation.

## Exact import duplicates are suppressed

Exact duplicate rows are currently discarded by the parsers before staging.

Potential/near duplicates are reviewable, but exact duplicates do not currently appear in Review.

## File import is manual

The application currently ingests OFX/CSV files.

It does not provide a live Open Banking/bank-feed integration.

Uploaded source files are retained in `uploads/`; the database remains authoritative after processing.

## Credit-card business-day handling is weekend-only

Statement payment dates are moved forward when they fall on Saturday or Sunday.

UK bank holidays are not currently incorporated into that calculation.

## Authentication is single-credential

Authentication protects the application, but it is not currently a multi-user identity/roles system.

## Payroll employment expenses do not yet have a general management UI

Employment-expense data is stored and reportable, but the main Payroll UI does not yet provide full expense-report CRUD.

## Payroll-to-Finance matching is explicit

Payroll mappings and transaction candidates assist reconciliation but do not automatically rewrite Finance data or fulfil salary predictions.

Links are deliberately recorded as explicit reconciliation evidence.

## Migrations do not reconstruct the application's entire historical schema

The database existed before migration governance was introduced.

Use:

- the live database as operational truth
- `config/schema.sql` as the current schema snapshot
- `/migrations` for forward schema evolution

Do not assume applying every file in `/migrations` to an empty database reproduces the whole historical database from first principles.

---

# Technology

The current application stack is:

- PHP 8.2
- MySQL 8
- Python 3
- PDO MySQL
- `mysql.connector`
- `ofxparse`
- Bootstrap 5
- jQuery

The system is currently designed around a conventional Linux/PHP/MySQL deployment rather than a framework, container platform or external finance SaaS.
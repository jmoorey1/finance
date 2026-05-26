# 🏡 Household Finance System

An advanced personal finance manager built in **PHP**, **MySQL**, and **Python**, designed to track income, expenses, budgets, cash flow, credit card repayments, reimbursable spending, and forward-looking liquidity across the year.

The system is built around one core idea: **the database is the source of truth**, and the application should turn transaction history, planned events, and predicted behaviour into an operational view of what happens next.

---

## 🚀 Core Features

### ✅ Accounts Management
- Current, savings, credit card, investment, and house account types supported.
- Starting balances configurable for historical continuity.
- Credit accounts support statement dates, payment dates, and paid-from account relationships.
- Active/inactive accounts supported for cleaner operational views.

### 🔐 Authentication & Request Protection
- Optional login flow for the application.
- Session handling centralised through shared auth helpers.
- CSRF protection applied across POST-based workflows.
- Shared header/bootstrap ensures forms and AJAX requests carry the required request token.

### 💳 Transaction Ingestion & Review
- Manual transaction entry supported.
- CSV and OFX import pipeline with structured staging and review.
- Exact duplicate suppression for repeated imports.
- Potential duplicate detection for near-matches that still need judgement.
- CSV parser repair logic for malformed merchant fields containing commas.
- Import runs are logged, so recent uploads and account freshness are visible.

### 🧾 Review & Reconciliation Workflow
- Staged transactions are separated into new items, duplicates, and predicted matches.
- Review flow supports categorisation, deletion, transfer handling, and split transactions.
- One-sided transfers can create PLACEHOLDER entries for later reconciliation.
- Predicted transactions can be matched retrospectively when the automatic match misses.
- Statement reconciliation supports credit card statement tracking and repayment confirmation.

### 🏷️ Payees & Matching
- Dedicated payee management page.
- Payee patterns can be created, edited, prioritised, and tested.
- Best-match logic supports more reliable payee assignment from imported descriptions.
- Payee matching feeds smarter category suggestions in the review process.

### 🔮 Predicted Transactions & Forecasting
- Recurring predicted transactions supported across fixed and flexible patterns.
- Weekly, monthly, and custom recurrence logic supported.
- Prediction instances generated into the future and protected against accidental duplication.
- Automatic credit card repayment prediction based on statements and spend behaviour.
- Missed predictions can be reviewed, resolved, or matched after the fact.
- Predicted transactions have their own management UI rather than living only in the database.

### 📊 Budgeting & Insights
- Monthly budgets by top-level category.
- Actuals, forecast, and variance views across both current month and YTD reporting.
- Spending insights for overspend, utilisation, discretionary concentration, and vendor trends.
- Income insight surfaces now include both true income and positive offsets such as refunds, repayments, and reimbursements.
- Budget interpretation respects category-level watcher treatment such as reimbursable or timing-flexible spend.

### 📒 Ledger & Reporting
- Canonical ledger-line model underpins ledger and category reporting.
- Ledger view supports filtering by account, date range, parent category, project, earmark, and description.
- Category and subcategory reports show both historic actuals and forward-looking predicted items where relevant.
- Split transactions are represented correctly at line level rather than double-counting the parent entry.
- Dedicated Job Expense reporting supports separate John / India views, combined summaries, running net positions, and on-demand email delivery.

### 💧 Funding Health, Cash Planning & Liquidity
- Funding Health is now the primary operational cash-pressure view.
- Current-account shortfalls are surfaced before they become real problems.
- Cash planner brings actuals, predictions, flexible income, and transfers together into dated account event streams.
- Flexible planned income events support non-monthly compensation such as bonus/share timing.
- Project fund and reserve-aware planning support the reality that some months are funded from savings rather than salary alone.
- Soft earmarks are treated as informational context rather than falsely reducing transferable cash in operational funding views.

### 🧠 Explainers & Operational Analysis
- Funding Explainer page answers the month-level question: **why is this account under pressure?**
- Monthly explanation surfaces highlight opening balance, lowest balance, support required, largest outgoing drivers, largest incoming offsets, and the full dated event stream.
- The system is increasingly built to explain decisions, not just calculate them.

### 👀 Watcher & Recommendation Engine
- Watcher alerts surface operational problems rather than relying on manual monitoring.
- Funding alerts identify current-account shortfalls and required support moves.
- Forecast-quality alerts detect recurring rule drift, missing recurring patterns, prediction miss accumulation, and review backlog.
- Budget watcher identifies burn-rate risk, unrealistic monthly budgets, and timing mismatch where categories are explicitly marked as timing-flexible.
- Reimbursable categories can be excluded from budget noise while remaining visible in dedicated reporting.
- Dashboard alert dedupe reduces repeated warnings for the same underlying category or account.
- Recommendations are attached to alerts so the system can suggest the next sensible action rather than just raising a flag.

### 📬 Weekly Digest & On-Demand Reporting
- Weekly digest has moved beyond a simple budget summary into a broader finance digest.
- Weekly delivery now includes funding health, watcher status, recent alert changes, budget headlines, and income insight.
- On-demand Job Expense reports can be sent by email from the CLI or directly from the UI.
- Scheduled jobs support regular forecasting, payee updates, watcher runs, and reporting.

---

## 🧱 Design Principles

- **Database first** — business state belongs in MySQL, not scattered through files.
- **Review before commit** — imported transactions land in staging before they become part of the ledger.
- **Forecasts must be explainable** — cash planning should be traceable back to real transactions, budgets, and predicted instances.
- **Operational truth over pretty theory** — the system should answer what action is actually needed, not just what a spreadsheet says in isolation.
- **Household reality over accounting purity** — the goal is not just categorisation, but knowing whether the right money will be in the right place at the right time.

---

## 📁 Key Structure

- `public/` — application pages and user-facing workflows
- `scripts/` — importers, forecast jobs, reporting jobs, and support utilities
- `scripts/lib/` — reusable service-style helpers for periods, insights, mail, watchers, and reporting
- `config/` — database/bootstrap/configuration
- `layout/` — shared page chrome
- `migrations/` — schema migrations
- `uploads/` — uploaded source files for ingestion
- `logs/` — PHP and application logs

---

## ⚙️ Technology Stack

- **PHP** 8.x
- **MySQL** 8.x
- **Python** 3.x
- **Bootstrap 5**
- **cron** for scheduled forecasting, watcher execution, updates, and reporting

---

## 🔭 Current Direction

The platform has moved beyond simple transaction tracking and now supports:

- authenticated access and protected request handling
- watcher-style alerting and recommendation logic
- dedicated funding-health and month-explainer views
- richer weekly operational reporting
- reduced dependence on manual monitoring
- category-level financial treatment for budget and watcher logic

Likely future areas include:

- automated bank-feed ingestion, if the integration cost proves worthwhile
- broader delivery options for operational alerts
- richer explainers for forward-looking questions across multiple months
- further reduction in manual intervention during the review and planning cycle

---

## 👨‍👩‍👧‍👦 Family-Centered Design

This system was built to answer practical household questions, not just accounting ones:

- Are we safe this month?
- How much needs moving from savings, and by when?
- Why does this month look tight?
- Are we drifting into a hole later in the quarter?
- Can we afford the next big payment without breaking something else?
- What has gone out on reimbursable work spend, and what has come back?

It is designed to help my wife and me make decisions together, with less spreadsheet archaeology and fewer unpleasant surprises.

---

## 🧠 Author

Built and maintained by **John** as a self-hosted household finance platform.

---

## 📌 License

This is a personal, private project. No public license or distribution is currently intended.

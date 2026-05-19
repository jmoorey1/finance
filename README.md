# 🏡 Household Finance System

An advanced personal finance manager built in **PHP**, **MySQL**, and **Python**, designed to track income, expenses, budgets, cash flow, earmarked savings, credit card repayments, and forward-looking liquidity across the year.

The system is built around one core idea: **the database is the source of truth**, and the application should help turn day-to-day transaction history into a reliable view of what is happening next.

---

## 🚀 Core Features

### ✅ Accounts Management
- Current, savings, credit card, investment, and house account types supported.
- Starting balances configurable for historical continuity.
- Credit accounts support statement dates, payment dates, and paid-from account relationships.
- Active/inactive accounts supported for cleaner operational views.

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
- Payee matching now feeds smarter category suggestions in the review process.

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
- Separate insights page for overspend, utilisation, discretionary concentration, and vendor trends.
- Budget headlines used by the weekly summary and wider reporting surfaces.

### 📒 Ledger & Reporting
- Canonical ledger-line model now underpins ledger and category reporting.
- Ledger view supports filtering by account, date range, parent category, project, earmark, and description.
- Category and subcategory reports show both historic actuals and forward-looking predicted items where relevant.
- Split transactions are represented correctly at line level rather than double-counting the parent entry.

### 💼 Project Fund, Solvency & Cash Planning
- Project fund view models discretionary funds available after protected reserves and earmarks.
- Solvency logic separates “money we can really spend” from “money that must remain available”.
- One-off planned commitments can be entered into the cash-planning horizon.
- Flexible planned income events support non-monthly compensation such as bonus/share timing.
- Cash planner pulls future events together into one forward-looking view of liquidity pressure.

### 🔁 Transfer & Liquidity Management
- Transfer recommendations take account of solvency needs and upcoming pressure.
- Current-account shortfalls can be surfaced before they become real problems.
- Reserve-aware planning supports the reality that some months are funded from savings rather than salary alone.
- Credit card repayment timing is modelled as part of the cash flow, not as an isolated expense view.

### 📬 Weekly Summary & Operational Monitoring
- Weekly email digest summarises variable spending and budget health.
- Import logging shows whether account data is fresh or going stale.
- Scheduled jobs support regular forecasting, payee updates, and reporting.
- The system is increasingly designed to surface issues, not just record transactions.

---

## 🧱 Design Principles

- **Database first** — business state belongs in MySQL, not scattered through files.
- **Review before commit** — imported transactions land in staging before they become part of the ledger.
- **Forecasts must be explainable** — cash planning should be traceable back to real transactions, budgets, and predicted instances.
- **Household reality over accounting purity** — the goal is not just categorisation, but knowing whether the right money will be in the right place at the right time.

---

## 📁 Key Structure

- `public/` — application pages and user-facing workflows
- `scripts/` — importers, forecast jobs, reporting jobs, and support utilities
- `scripts/lib/` — reusable service-style helpers for periods, insights, mail, and reporting
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
- **cron** for scheduled forecasting, updates, and reporting

---

## 🔭 Next Direction

The platform has moved beyond simple transaction tracking and now has the foundations for:

- stronger authentication and request protection
- smarter alerting and watcher-style analysis
- reduced dependence on manual monitoring
- cleaner operational reporting
- possible future automated bank-feed ingestion, if the integration cost proves worthwhile

---

## 👨‍👩‍👧‍👦 Family-Centered Design

This system was built to answer practical household questions, not just accounting ones:

- Are we safe this month?
- How much needs moving from savings?
- Are we drifting into a hole later in the quarter?
- Can we afford the next big payment without breaking something else?

It is designed to help my wife and me make decisions together, with less spreadsheet archaeology and fewer unpleasant surprises.

---

## 🧠 Author

Built and maintained by **John** as a self-hosted household finance platform.

---

## 📌 License

This is a personal, private project. No public license or distribution is currently intended.
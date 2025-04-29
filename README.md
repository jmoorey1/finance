# 🏡 Household Finance System

An advanced personal finance manager built in **PHP**, **MySQL**, and **Python**, designed to track income, expenses, budgets, cash flow, savings earmarks, and credit card repayments, all while supporting detailed reconciliation and forecasting.

---

## 🚀 Core Features

### ✅ Accounts Management
- Current, Savings, Credit Card, Investment, and House account types supported.
- Starting balances, statement dates, and payment days configurable.

### 💳 Transactions
- Manual entry or automatic OFX/CSV file uploads.
- Split transactions across multiple categories.
- Intelligent duplicate detection and transfer matching.
- Support for one-sided transfers and PLACEHOLDER reconciliation.

### 🔮 Predicted Transactions
- Fixed or variable recurring transactions.
- Weekly, monthly, or custom recurrence intervals.
- Auto-calculated average values for variable entries.
- Automatic prediction of credit card repayments.
- Confirmed predictions are frozen from overwrite.

### 📊 Budgeting
- Monthly budgets by top-level category.
- Dashboard with variance tracking (budget vs actual vs forecast).
- Forecast column integrated into budget views.
- YTD tracking included.

### 📒 Ledger Viewer
- Search by account, date, parent category, or subcategory.
- Displays both actual and predicted transactions.
- Linked directly from dashboard actuals for drilldown.

### 💼 Project Fund Forecasting
- Tracks discretionary fund available for non-essential projects.
- Accounts for earmarked savings and solvency fund.
- Forecasts when funds become available across the year.

### 📈 Forecasting & Automation
- Balance forecasting over 90 days.
- Identifies shortfalls and suggests top-ups.
- Automatically reconciles confirmed repayments after statements.
- Python engine runs via cron or manual trigger.

### 🔁 Reconciliation Engine
- Create and manage account statements.
- Match unreconciled transactions to statement balances.
- Confirms predicted repayment entries automatically.
- Finalize reconciliations and updates balances reliably.

### 📬 Weekly Email Summary
- Sends weekly email of insights and spending health.
- Flags overspending, underspending, and prediction mismatches.

---

## 📁 Folder Structure

- .
  - public/
    - manual_entry.php
    - assets/
    - review_actions.php
    - toggle_rule.php
    - reconcile.php
    - dashboard.php
    - budgets.php
    - statements.php
    - index.php
    - upload.php
    - ledger.php
    - project_fund.php
    - insights.php
    - review.php
    - finalize_reconciliation.php
    - predicted.php
    - dashboard_ytd.php
    - view_statement.php
  - config/
    - db.php
    - accounts_schema_only.sql
  - uploads/
  - layout/
    - footer.php
    - header.php
  - README.md
  - scripts/
    - forecast_utils.php
    - parse_csv.py
    - get_accounts.php
    - predict_instances.py
    - email_weekly_summary.php
    - get_account_balances.php
    - get_insights.php
    - get_missed_predictions.php
    - get_upcoming_predictions.php
    - parse_ofx.py
    - forecast_balance_timeline.py


---

## ⚙️ Technology Stack

- **PHP** 8.x
- **MySQL** 8.x
- **Python** 3.x
- **Bootstrap 5**
- **cron** (for automated forecasting and email)

---

## 🔭 Roadmap

- Enhanced graphs and mobile-first UX
- Multi-year planning support
- Smarter alerting engine
- Recategorization and tagging tools
- Role-based access or user-specific views (optional)
- Net worth tracker and investment forecasting (stretch)

---

## 👨‍👩‍👧‍👦 Family-Centered Design

This system was built not just for tracking — but for making sure my wife and I can always stay ahead of our bills, optimize savings, and make financial decisions together. It's fast, clean, and works great from an iPhone.

---

## 🧠 Author

Built and maintained by **John** as a fully self-hosted, personal finance automation suite.

---

## 📌 License

This is a personal, private project. No license or distribution currently intended.

---



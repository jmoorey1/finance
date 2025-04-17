# 💰 Home Finances System

A custom MySQL + PHP/Python-powered household finance tracker designed to replace Microsoft Money and bring full visibility and forecasting control to our family budget.

Built to be robust, mobile-friendly, and fully automated — with a powerful backend and simple interface anyone can use (including on an iPhone).

---

## 🔧 Core Features

### 📊 Budget & Actual Tracking
- Monthly and YTD dashboards with budget vs actual variance
- Supports split transactions and category roll-ups
- Auto-classifies based on category structure

### 🔁 Predicted Transactions Engine
- Supports monthly, weekly, nth weekday, and business-day recurrence
- Estimates variable transactions by averaging the last X actuals
- Generates `predicted_instances` table up to 90 days ahead

### 💳 Credit Card Forecasting
- Predicts credit card payments based on statement/payment cycle
- Handles mid-month and cross-month logic
- Forecasts repayment amounts from actual spend or extrapolated usage

### 🔮 Forecasted Balance Timeline
- Combines predicted and actual transactions
- Detects upcoming cash shortfalls
- Recommends top-up amounts to prevent overdraft
- Lists contributing transactions with running balance view

### 📋 Review & Approval Workflow
- Categorize new transactions or mark as duplicates
- Supports:
  - Manual edits
  - Transfer pairing (matched and one-sided)
  - Split/Multiple category handling

### 📥 Data Ingestion
- Import `.OFX` and `.CSV` via web interface
- Duplicate detection
- Smart account matching

### 📱 Mobile-Friendly Dashboard
- Touch-optimized navigation
- Forecasts, predictions, and balances accessible on iPhone
- Responsive tables and buttons

---

## 📂 Key Scripts

| Script | Description |
|--------|-------------|
| `predict_instances.py` | Generates predicted instances for all active predicted transactions |
| `forecast_balance_timeline.py` | Analyzes cash flow and surfaces shortfalls with top-up advice |
| `parse_csv.py`, `parse_ofx.py` | Ingest `.csv` and `.ofx` files and populate `staging_transactions` |
| `review.php` / `review_actions.php` | UI for transaction categorization, splitting, and approval |
| `dashboard.php` | Budget vs Actual for current month |
| `dashboard_ytd.php` | YTD budget tracking and variance |
| `ledger.php` | Transaction ledger by account and date range |
| `get_upcoming_predictions.php` | Returns next 10 predicted transaction instances |
| `get_account_balances.php` | Uses MySQL view to return current account balances |
| `forecast_utils.php` | Provides forecasting shortfall panel logic |

---

## 🚧 Wishlist / To-Do

- [ ] Add tags for categories: `fixed vs variable`, `compulsory vs discretionary`
- [ ] Add in-browser editing of budgets
- [ ] Filter ledger by transaction source (actual vs predicted)
- [ ] CSV export of dashboard and ledger views
- [ ] Historical trend charts for spending by category
- [ ] Save "last viewed" filters per user/session
- [ ] Email alerts for upcoming shortfalls
- [ ] Archive completed predicted instances (fulfilled = 1)

---

## 🏗️ Stack

- MySQL (InnoDB, Views, Foreign Keys)
- PHP 8 + Bootstrap 5 (UI)
- Python 3.10 (prediction + ingestion engine)
- Dropbox + GitHub for sync and version control

---

## 👨‍👩‍👧‍👦 Family-Centered Design

This system was built not just for tracking — but for making sure my wife and I can always stay ahead of our bills, optimize savings, and make financial decisions together. It's fast, clean, and works great from an iPhone.


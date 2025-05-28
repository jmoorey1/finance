<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Home Finances</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 for responsiveness -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 70px;
            background-color: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .navbar-brand {
            font-weight: bold;
        }
        .container {
            max-width: 960px;
        }
        .forecast-panel {
            background: #fff;
            border-left: 5px solid #dc3545;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
		.forecast-panel.amber {
			border-color: #ffc107;
		}
        .forecast-panel.good {
            border-color: #198754;
        }
        footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ccc;
            color: #777;
            text-align: center;
            font-size: 0.9em;
        }
        @media (max-width: 576px) {
            .nav-item {
                margin-left: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="/finance/public/index.php">💰 Finance</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#financeNavbar" aria-controls="financeNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="financeNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="/finance/public/index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/dashboard.php">Monthly Summary</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/dashboard_ytd.php">Year-to-Date</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/insights.php">Spending Insights</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="manageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Manage</a>
                    <ul class="dropdown-menu" aria-labelledby="manageDropdown">
                        <li><a class="dropdown-item" href="/finance/public/upload.php">Upload</a></li>
                        <li><a class="dropdown-item" href="/finance/public/review.php">Review</a></li>
                        <li><a class="dropdown-item" href="/finance/public/statements.php">Statements</a></li>
                        <li><a class="dropdown-item" href="/finance/public/manual_entry.php">Manual Entry</a></li>
                        <li><a class="dropdown-item" href="/finance/public/ledger.php">Ledger</a></li>
                    </ul>
                </li>
		<li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="settingsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Planning</a>
                    <ul class="dropdown-menu" aria-labelledby="settingsDropdown">
                        <li><a class="dropdown-item" href="/finance/public/budgets.php">Budgets</a></li>
                        <li><a class="dropdown-item" href="/finance/public/predicted.php">Predicted Transactions</a></li>
                        <li><a class="dropdown-item" href="/finance/public/project_fund.php">Project Fund Review</a></li>
                    </ul>
                </li>
		<li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="settingsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Review</a>
                    <ul class="dropdown-menu" aria-labelledby="settingsDropdown">
                        <li><a class="dropdown-item" href="/finance/public/projects.php">Projects and Trips Review</a></li>
                        <li><a class="dropdown-item" href="/finance/public/earmarks.php">Fund Review</a></li>
                        <li><a class="dropdown-item" href="/finance/public/analytics_monthly.php">Monthly Analytics</a></li>
                        <li><a class="dropdown-item" href="/finance/public/analytics_yoy_totals.php">Year-on-Year Analytics</a></li>
                        <li><a class="dropdown-item" href="/finance/public/category_report.php">Category</a></li>
                        <li><a class="dropdown-item" href="/finance/public/subcategory_report.php">Subcategory</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container">

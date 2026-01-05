<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Home Finances</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 for responsiveness -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
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
		/* Use the navbar height consistently */
		:root { --nav-offset: 56px; }

		/* Let sticky see the page/body as the scroller */
		.table-responsive {
		  overflow-y: visible !important;   /* override Bootstrap */
		}

		/* Budget Table Styling */
		.budget-table {
			border-collapse: collapse;
			width: 100%;
		}
		.budget-table th,
		.budget-table td {
			border: 1px solid #ccc;
			padding: 6px;
			text-align: center;
			width: 95px; /* match input width exactly */
			min-width: 95px;
			max-width: 95px;
		}
		.budget-table th.sticky-col,
		.budget-table td.sticky-col {
			position: sticky;
			left: 0;
			background: #fff;
			text-align: left;
			z-index: 1;
		}
		.budget-table input[type='number'] {
			width: 95px;
		}
		.budget-table thead th {
			background: #f8f8f8;
			position: sticky;
			top: 56px; /* navbar offset */
			z-index: 2;
		}
		.budget-table tfoot td {
			font-weight: bold;
			background: #f0f0f0;
		}
				
		/* Sticky header + first column for DASH table */
		table.dash-table {
		  border-collapse: separate;        /* avoid Blink sticky bugs with 'collapse' */
		  border-spacing: 0;
		}

		table.dash-table thead th {
		  position: sticky;
		  top: var(--nav-offset);
		  z-index: 3;                        /* above sticky first column */
		}

		table.dash-table th.sticky-col,
		table.dash-table td.sticky-col {
		  position: sticky;
		  left: 0;
		  z-index: 2;                        /* under header */
		}

		table.dash-table thead th.sticky-col {
		  z-index: 4;                        /* header first col sits on top */
		}
		
		/* Review Page Styles */
		.review-tabs { margin-bottom: 20px; }
		.review-tabs .tab-btn {
			padding: 10px 20px;
			display: inline-block;
			border: 1px solid #ccc;
			background: #eee;
			margin-right: 5px;
			cursor: pointer;
		}
		.review-tabs .tab-btn.active { background: #ddd; font-weight: bold; }

		
		.tab-content { display: none; }
		.tab-content.active { display: block; }

		.review-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
		.review-table th, .review-table td { border: 1px solid #ccc; padding: 8px; vertical-align: top; }
		.review-table th { background-color: #f0f0f0; }
		.review-table tr:nth-child(even) { background-color: #fafafa; }
		.review-table .note { font-size: 0.85em; color: #666; }

		/* Manual Entry Page Styles */
		.form-section {
			border: 1px solid #ccc;
			padding: 20px;
			margin-bottom: 30px;
			border-radius: 8px;
			background: #f9f9f9;
		}

		.form-group {
			margin-bottom: 15px;
		}
		label {
			display: block;
			margin-bottom: 5px;
			font-weight: bold;
		}
		input[type="text"],
		input[type="date"],
		input[type="number"],
		select {
			width: 100%;
			max-width: 400px;
			padding: 6px;
			font-size: 1em;
		}
		.submit-btn {
			margin-top: 10px;
			padding: 10px 16px;
			font-size: 1em;
		}
		.message { padding: 10px; margin-bottom: 10px; }
		.success { background: #e0ffe0; border: 1px solid #4caf50; }
		.error { background: #ffe0e0; border: 1px solid #f44336; }
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
                    </ul>
                </li>
		<li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="settingsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Planning</a>
                    <ul class="dropdown-menu" aria-labelledby="planningDropdown">
                        <li><a class="dropdown-item" href="/finance/public/budgets.php">Budgets</a></li>
                        <li><a class="dropdown-item" href="/finance/public/predicted.php">Predicted Transactions</a></li>
                        <li><a class="dropdown-item" href="/finance/public/project_fund.php">Project Fund Review</a></li>
                    </ul>
                </li>
		<li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="settingsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Review</a>
                    <ul class="dropdown-menu" aria-labelledby="reviewDropdown">
                        <li><a class="dropdown-item" href="/finance/public/ledger.php">Ledger</a></li>
			<li><a class="dropdown-item" href="/finance/public/budget_performance.php">Budget Performance</a></li>
                        <li><a class="dropdown-item" href="/finance/public/category_report.php">Category</a></li>
                        <li><a class="dropdown-item" href="/finance/public/subcategory_report.php">Subcategory</a></li>
                        <li><a class="dropdown-item" href="/finance/public/projects.php">Projects and Trips Review</a></li>
                        <li><a class="dropdown-item" href="/finance/public/earmarks.php">Fund Review</a></li>
                        <li><a class="dropdown-item" href="/finance/public/analytics_monthly.php">Monthly Analytics</a></li>
                        <li><a class="dropdown-item" href="/finance/public/analytics_yoy_totals.php">Year-on-Year Analytics</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="manageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Settings</a>
                    <ul class="dropdown-menu" aria-labelledby="settingsDropdown">
                        <li><a class="dropdown-item" href="/finance/public/accounts.php">Accounts Management</a></li>
                        <li><a class="dropdown-item" href="/finance/public/categories.php">Categories Management</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php
$page = basename($_SERVER['PHP_SELF']);
if ($page !== 'budgets.php') {
    echo '<div class="container">';
}
?>

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

<nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
    <div class="container">
        <a class="navbar-brand" href="/finance/public/index.php">💰 Home Finances</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto text-end">
                <li class="nav-item"><a class="nav-link" href="/finance/public/index.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/dashboard.php">Monthly Summary</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/dashboard_ytd.php">Year-to-Date</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/review.php">Review</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/budgets.php">Budgets</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/manual_entry.php">Manual Entry</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/upload.php">Upload</a></li>
                <li class="nav-item"><a class="nav-link" href="/finance/public/ledger.php">Ledger</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">

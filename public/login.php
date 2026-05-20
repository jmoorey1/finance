<?php
require_once '../config/db.php';
require_once '../scripts/lib/auth.php';

if (!auth_is_enabled()) {
    header('Location: /finance/public/index.php');
    exit;
}

auth_session_start();

$error = '';
$next = auth_safe_next_url($_GET['next'] ?? $_POST['next'] ?? '/finance/public/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    try {
        if (auth_attempt_login($username, $password)) {
            header('Location: ' . $next);
            exit;
        }

        $error = 'Invalid username or password.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Do not auto-redirect away from login on GET.
// It is safer to render the page than to risk a redirect loop.
$alreadyLoggedIn = auth_is_logged_in();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login — Home Finances</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container" style="max-width: 440px; margin-top: 80px;">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-4">🔐 Home Finances Login</h1>

                <?php if ($alreadyLoggedIn): ?>
                    <div class="alert alert-info">
                        You are already signed in as
                        <strong><?= htmlspecialchars(auth_current_username() ?? 'unknown', ENT_QUOTES, 'UTF-8') ?></strong>.
                        Enter credentials again only if you want to replace the current session.
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" type="text" name="username" id="username" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" type="password" name="password" id="password" required>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Log in</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
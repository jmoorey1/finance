<?php
$page = basename($_SERVER['PHP_SELF']);
if ($page !== 'budgets.php') {
    echo '</div>';
}
?>

<footer>
    <p>&copy; <?= date('Y') ?> Household Finances — Built by John</p>
</footer>

<!-- Bootstrap JS for navbar and mobile interactivity -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
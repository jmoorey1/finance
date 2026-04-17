<?php
// Backward-compatible wrapper: old delete action now soft-skips instead.
$_POST['action'] = $_POST['action'] ?? 'skip';
$_POST['redirect'] = $_POST['redirect'] ?? 'index.php';
require __DIR__ . '/prediction_action.php';

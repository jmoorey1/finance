<?php
require_once __DIR__ . '/../../config/db.php';

function fail_check(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pass_check(string $message): void
{
    echo "PASS: {$message}\n";
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetch_rule(PDO $pdo, int $id, string $expectedDescription): array
{
    $stmt = $pdo->prepare("SELECT * FROM predicted_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule) {
        fail_check("Prediction rule #{$id} is missing");
    }

    if (strtoupper(trim((string)$rule['description'])) !== strtoupper($expectedDescription)) {
        fail_check("Prediction rule #{$id} description changed; expected {$expectedDescription}, got {$rule['description']}");
    }

    return $rule;
}

function expected_weekly_phase_dates(array $rule, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $interval = max(1, (int)$rule['recurrence_interval']);
    $cursor = new DateTimeImmutable((string)$rule['anchor_date']);
    $step = new DateInterval('P' . (7 * $interval) . 'D');
    $dates = [];

    while ($cursor < $start) {
        $cursor = $cursor->add($step);
    }

    while ($cursor <= $end) {
        $dates[$cursor->format('Y-m-d')] = true;
        $cursor = $cursor->add($step);
    }

    return $dates;
}

$columns = table_columns($pdo, 'predicted_transactions');
$newColumns = [
    'recurrence_unit',
    'recurrence_interval',
    'anchor_date',
    'schedule_pattern',
    'business_day_adjustment',
];
$legacyColumns = [
    'frequency',
    'repeat_interval',
    'anchor_type',
    'adjust_for_weekend',
    'is_business_day',
];

foreach ($newColumns as $column) {
    if (!in_array($column, $columns, true)) {
        fail_check("Missing canonical recurrence column {$column}");
    }
}
pass_check('canonical recurrence columns are present');

foreach ($legacyColumns as $column) {
    if (in_array($column, $columns, true)) {
        fail_check("Legacy recurrence column still present: {$column}");
    }
}
pass_check('legacy recurrence columns are removed');

$referenceRules = [
    3 => [
        'description' => 'JENNIFER FEGAN',
        'anchor_date' => '2026-06-16',
        'interval' => 2,
    ],
    6 => [
        'description' => 'LISA FERRIER - LOTTERY',
        'anchor_date' => '2026-04-21',
        'interval' => 4,
    ],
    50 => [
        'description' => 'CRANFIELD SF CONNECT',
        'anchor_date' => '2026-06-28',
        'interval' => 4,
    ],
];

$today = new DateTimeImmutable('today');
$horizon = $today->add(new DateInterval('P365D'));

foreach ($referenceRules as $id => $expected) {
    $rule = fetch_rule($pdo, $id, $expected['description']);

    if ((string)$rule['recurrence_unit'] !== 'week') {
        fail_check("Rule #{$id} should use recurrence_unit=week");
    }
    if ((int)$rule['recurrence_interval'] !== $expected['interval']) {
        fail_check("Rule #{$id} interval should be {$expected['interval']}");
    }
    if ((string)$rule['anchor_date'] !== $expected['anchor_date']) {
        fail_check("Rule #{$id} anchor should be {$expected['anchor_date']}");
    }
    if ((string)$rule['schedule_pattern'] !== 'anchor_date') {
        fail_check("Rule #{$id} should use schedule_pattern=anchor_date");
    }

    pass_check("rule #{$id} canonical phase is correct");

    if ((int)$rule['active'] === 1) {
        $allowedDates = expected_weekly_phase_dates($rule, $today, $horizon);

        $stmt = $pdo->prepare("
            SELECT scheduled_date
            FROM predicted_instances
            WHERE predicted_transaction_id = ?
              AND scheduled_date BETWEEN ? AND ?
              AND COALESCE(fulfilled, 0) = 0
              AND confirmed = 0
              AND COALESCE(resolution_status, 'open') = 'open'
            ORDER BY scheduled_date
        ");
        $stmt->execute([$id, $today->format('Y-m-d'), $horizon->format('Y-m-d')]);
        $actualDates = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($actualDates)) {
            fail_check("Rule #{$id} has no future open instances after canonical rebuild");
        }

        foreach ($actualDates as $actualDate) {
            if (!isset($allowedDates[$actualDate])) {
                fail_check("Rule #{$id} has off-phase future instance {$actualDate}");
            }
        }

        pass_check("rule #{$id} future open instances are on the canonical phase");
    }
}

foreach ([3, 50] as $id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM predicted_instances
        WHERE predicted_transaction_id = ?
          AND (
              COALESCE(fulfilled, 0) <> 0
              OR confirmed = 1
              OR COALESCE(resolution_status, 'open') <> 'open'
          )
    ");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() < 1) {
        fail_check("Rule #{$id} no longer has resolved historical audit rows");
    }
    pass_check("rule #{$id} resolved historical audit rows remain present");
}

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM predicted_instances pi
    JOIN predicted_transactions pt
      ON pt.id = pi.predicted_transaction_id
    WHERE pt.active = 1
      AND pi.scheduled_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)
      AND pi.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 365 DAY)
      AND COALESCE(pi.fulfilled, 0) = 0
      AND pi.confirmed = 0
      AND COALESCE(pi.resolution_status, 'open') = 'open'
");
if ((int)$stmt->fetchColumn() < 1) {
    fail_check('No recurring-rule instances exist beyond the old 90-day horizon');
}
pass_check('recurring-rule forecast extends beyond 90 days');

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM predicted_instances
    WHERE predicted_transaction_id IS NOT NULL
      AND scheduled_date > DATE_ADD(CURDATE(), INTERVAL 365 DAY)
      AND COALESCE(fulfilled, 0) = 0
      AND confirmed = 0
      AND COALESCE(resolution_status, 'open') = 'open'
");
if ((int)$stmt->fetchColumn() !== 0) {
    fail_check('Open recurring-rule instances exist beyond the 365-day horizon');
}
pass_check('no open recurring-rule instances exist beyond the 365-day horizon');

echo "All prediction recurrence database checks passed.\n";

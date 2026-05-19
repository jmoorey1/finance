<?php

function pr_load_predicted_instance(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            pi.*,
            c.type AS category_type,
            c.name AS category_name,
            fa.name AS from_account_name,
            fa.type AS from_account_type,
            ta.name AS to_account_name,
            ta.type AS to_account_type
        FROM predicted_instances pi
        JOIN categories c ON c.id = pi.category_id
        JOIN accounts fa ON fa.id = pi.from_account_id
        LEFT JOIN accounts ta ON ta.id = pi.to_account_id
        WHERE pi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pr_validate_reconcilable(array $instance): bool
{
    return (int)($instance['fulfilled'] ?? 0) === 0;
}

function pr_is_regular(array $instance): bool
{
    return ($instance['category_type'] ?? '') !== 'transfer';
}

function pr_is_transfer(array $instance): bool
{
    return ($instance['category_type'] ?? '') === 'transfer';
}

function pr_is_txn_linked_elsewhere(PDO $pdo, int $transactionId, int $currentPredictionId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM predicted_instances
        WHERE fulfilled_by_transaction_id = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$transactionId, $currentPredictionId]);
    return (bool)$stmt->fetchColumn();
}

function pr_is_group_linked_elsewhere(PDO $pdo, int $transferGroupId, int $currentPredictionId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM predicted_instances
        WHERE fulfilled_by_transfer_group_id = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$transferGroupId, $currentPredictionId]);
    return (bool)$stmt->fetchColumn();
}

function pr_find_regular_candidates(PDO $pdo, array $instance, int $windowDays = 21): array
{
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.date,
            t.account_id,
            a.name AS account_name,
            t.description,
            t.amount,
            t.type,
            t.category_id,
            c.name AS category_name,
            t.predicted_transaction_id,
            t.transfer_group_id
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.account_id = ?
          AND ABS(t.amount - ?) < 0.01
          AND ABS(DATEDIFF(t.date, ?)) <= ?
        ORDER BY ABS(DATEDIFF(t.date, ?)) ASC, t.date DESC, t.id DESC
        LIMIT 50
    ");
    $stmt->execute([
        (int)$instance['from_account_id'],
        (float)$instance['amount'],
        $instance['scheduled_date'],
        $windowDays,
        $instance['scheduled_date'],
    ]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['already_linked'] = pr_is_txn_linked_elsewhere($pdo, (int)$row['id'], (int)$instance['id']);
        $rows[] = $row;
    }

    return $rows;
}

function pr_find_transfer_group_candidates(PDO $pdo, array $instance, int $windowDays = 21): array
{
    $predAmount = abs((float)$instance['amount']);

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.transfer_group_id,
            t.account_id,
            t.date,
            t.amount,
            t.description,
            a.name AS account_name
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        WHERE t.transfer_group_id IS NOT NULL
          AND t.type = 'transfer'
          AND t.account_id IN (?, ?)
          AND ABS(ABS(t.amount) - ?) < 0.01
          AND ABS(DATEDIFF(t.date, ?)) <= ?
        ORDER BY t.transfer_group_id ASC, t.date ASC, t.id ASC
    ");
    $stmt->execute([
        (int)$instance['from_account_id'],
        (int)$instance['to_account_id'],
        $predAmount,
        $instance['scheduled_date'],
        $windowDays,
    ]);

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gid = (int)$row['transfer_group_id'];
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'transfer_group_id' => $gid,
                'rows' => [],
                'from_match' => false,
                'to_match' => false,
                'earliest_date' => $row['date'],
                'latest_date' => $row['date'],
            ];
        }

        $groups[$gid]['rows'][] = $row;
        if ($row['date'] < $groups[$gid]['earliest_date']) {
            $groups[$gid]['earliest_date'] = $row['date'];
        }
        if ($row['date'] > $groups[$gid]['latest_date']) {
            $groups[$gid]['latest_date'] = $row['date'];
        }

        $amt = (float)$row['amount'];
        $acct = (int)$row['account_id'];

        if ($acct === (int)$instance['from_account_id'] && abs($amt + $predAmount) < 0.01) {
            $groups[$gid]['from_match'] = true;
        }
        if ($acct === (int)$instance['to_account_id'] && abs($amt - $predAmount) < 0.01) {
            $groups[$gid]['to_match'] = true;
        }
    }

    $out = [];
    foreach ($groups as $group) {
        if (!$group['from_match'] || !$group['to_match']) {
            continue;
        }

        $group['already_linked'] = pr_is_group_linked_elsewhere($pdo, (int)$group['transfer_group_id'], (int)$instance['id']);
        $out[] = $group;
    }

    usort($out, function (array $a, array $b) use ($instance): int {
        $target = new DateTimeImmutable($instance['scheduled_date']);
        $aDate = new DateTimeImmutable($a['earliest_date']);
        $bDate = new DateTimeImmutable($b['earliest_date']);
        $aDiff = abs((int)$target->diff($aDate)->format('%r%a'));
        $bDiff = abs((int)$target->diff($bDate)->format('%r%a'));

        return $aDiff <=> $bDiff ?: ((int)$a['transfer_group_id'] <=> (int)$b['transfer_group_id']);
    });

    return $out;
}

function pr_find_transfer_row_candidates(PDO $pdo, array $instance, string $side, int $windowDays = 21): array
{
    $predAmount = abs((float)$instance['amount']);

    if ($side === 'from') {
        $accountId = (int)$instance['from_account_id'];
        $expectedAmount = -$predAmount;
    } else {
        $accountId = (int)$instance['to_account_id'];
        $expectedAmount = $predAmount;
    }

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.transfer_group_id,
            t.date,
            t.account_id,
            a.name AS account_name,
            t.description,
            t.amount,
            t.predicted_transaction_id
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        WHERE t.type = 'transfer'
          AND t.account_id = ?
          AND ABS(t.amount - ?) < 0.01
          AND ABS(DATEDIFF(t.date, ?)) <= ?
        ORDER BY ABS(DATEDIFF(t.date, ?)) ASC, t.date DESC, t.id DESC
        LIMIT 50
    ");
    $stmt->execute([
        $accountId,
        $expectedAmount,
        $instance['scheduled_date'],
        $windowDays,
        $instance['scheduled_date'],
    ]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alreadyLinked = false;

        if (!empty($row['transfer_group_id'])) {
            $alreadyLinked = pr_is_group_linked_elsewhere($pdo, (int)$row['transfer_group_id'], (int)$instance['id']);
        }

        if (!$alreadyLinked) {
            $alreadyLinked = pr_is_txn_linked_elsewhere($pdo, (int)$row['id'], (int)$instance['id']);
        }

        $row['already_linked'] = $alreadyLinked;
        $rows[] = $row;
    }

    return $rows;
}

function pr_mark_regular_fulfilled(PDO $pdo, array $instance, int $transactionId): void
{
    $pdo->prepare("
        UPDATE predicted_instances
        SET fulfilled = 1,
            confirmed = 1,
            resolution_status = 'open',
            resolved_at = NULL,
            resolution_note = NULL,
            fulfilled_at = NOW(),
            fulfilled_by_transaction_id = ?,
            fulfilled_by_transfer_group_id = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$transactionId, (int)$instance['id']]);

    if (!empty($instance['predicted_transaction_id'])) {
        $pdo->prepare("
            UPDATE transactions
            SET predicted_transaction_id = COALESCE(predicted_transaction_id, ?)
            WHERE id = ?
        ")->execute([(int)$instance['predicted_transaction_id'], $transactionId]);
    }
}

function pr_mark_transfer_fulfilled(PDO $pdo, array $instance, int $transferGroupId): void
{
    $pdo->prepare("
        UPDATE predicted_instances
        SET fulfilled = 1,
            confirmed = 1,
            resolution_status = 'open',
            resolved_at = NULL,
            resolution_note = NULL,
            fulfilled_at = NOW(),
            fulfilled_by_transaction_id = NULL,
            fulfilled_by_transfer_group_id = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$transferGroupId, (int)$instance['id']]);

    if (!empty($instance['predicted_transaction_id'])) {
        $pdo->prepare("
            UPDATE transactions
            SET predicted_transaction_id = COALESCE(predicted_transaction_id, ?)
            WHERE transfer_group_id = ?
        ")->execute([(int)$instance['predicted_transaction_id'], $transferGroupId]);
    }
}

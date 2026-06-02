<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function tg_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function tg_signed_money(float $amount): string
{
    return ($amount >= 0 ? '+' : '') . tg_money($amount);
}

function tg_date_gap_days(string $a, string $b): int
{
    return abs((new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days);
}

function tg_group_rows_by_id(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['transfer_group_id']][] = $row;
    }
    return $grouped;
}

function tg_pair_score(array $negative, array $positive): int
{
    $score = 0;

    $score += tg_date_gap_days((string)$negative['date'], (string)$positive['date']) * 1000;

    if ((string)$negative['description'] !== (string)$positive['description']) {
        $score += 100;
    }

    if ((int)$negative['account_id'] === (int)$positive['account_id']) {
        $score += 10000;
    }

    $score += abs((int)$negative['id'] - (int)$positive['id']);

    return $score;
}

/**
 * Return a minimum-score one-to-one matching between negative and positive rows.
 *
 * The groups involved in this cleanup are small. We deliberately use exhaustive
 * matching rather than greedy matching so that one exact-description pair is not
 * accidentally consumed by an earlier merely-valid row.
 */
function tg_best_matching(array $negativeRows, array $positiveRows): ?array
{
    if (count($negativeRows) !== count($positiveRows)) {
        return null;
    }

    if (count($negativeRows) === 0) {
        return [];
    }

    $best = null;
    $bestScore = PHP_INT_MAX;

    $walk = function (
        array $remainingNegatives,
        array $remainingPositives,
        array $currentPairs,
        int $currentScore
    ) use (&$walk, &$best, &$bestScore): void {
        if ($currentScore >= $bestScore) {
            return;
        }

        if (empty($remainingNegatives)) {
            $best = $currentPairs;
            $bestScore = $currentScore;
            return;
        }

        $negative = array_shift($remainingNegatives);

        foreach ($remainingPositives as $idx => $positive) {
            if (abs(abs((float)$negative['amount']) - abs((float)$positive['amount'])) >= 0.01) {
                continue;
            }

            if ((int)$negative['account_id'] === (int)$positive['account_id']) {
                continue;
            }

            $nextPositives = $remainingPositives;
            array_splice($nextPositives, $idx, 1);

            $pairScore = tg_pair_score($negative, $positive);
            $nextPairs = $currentPairs;
            $nextPairs[] = [
                'negative' => $negative,
                'positive' => $positive,
                'date_gap_days' => tg_date_gap_days((string)$negative['date'], (string)$positive['date']),
                'description_match' => (string)$negative['description'] === (string)$positive['description'],
                'score' => $pairScore,
            ];

            $walk($remainingNegatives, $nextPositives, $nextPairs, $currentScore + $pairScore);
        }
    };

    $walk(array_values($negativeRows), array_values($positiveRows), [], 0);

    return $best;
}

function tg_fetch_bad_group_summary(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            transfer_group_id,
            ROUND(SUM(amount), 2) AS total_amount,
            COUNT(*) AS transaction_count,
            MIN(date) AS min_date,
            MAX(date) AS max_date
        FROM transactions
        WHERE transfer_group_id IS NOT NULL
          AND type = 'transfer'
        GROUP BY transfer_group_id
        HAVING ROUND(SUM(amount), 2) <> 0
        ORDER BY MIN(date), transfer_group_id
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tg_fetch_bad_rows(PDO $pdo): array
{
    $stmt = $pdo->query("
        WITH bad_groups AS (
            SELECT
                transfer_group_id
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND type = 'transfer'
            GROUP BY transfer_group_id
            HAVING ROUND(SUM(amount), 2) <> 0
        )
        SELECT
            t.id,
            t.account_id,
            a.name AS account_name,
            t.date,
            t.description,
            t.amount,
            t.transfer_group_id
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        JOIN bad_groups bg ON bg.transfer_group_id = t.transfer_group_id
        WHERE t.type = 'transfer'
        ORDER BY t.date, ABS(t.amount), t.id
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tg_build_safe_full_group_pair_proposals(array $badGroups, array $badRows): array
{
    $rowsByGroup = tg_group_rows_by_id($badRows);
    $proposals = [];

    $count = count($badGroups);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $groupA = $badGroups[$i];
            $groupB = $badGroups[$j];

            $groupAId = (int)$groupA['transfer_group_id'];
            $groupBId = (int)$groupB['transfer_group_id'];
            $groupATotal = round((float)$groupA['total_amount'], 2);
            $groupBTotal = round((float)$groupB['total_amount'], 2);

            if (abs($groupATotal + $groupBTotal) >= 0.01) {
                continue;
            }

            /*
             * Deliberately conservative:
             * only repair two bad two-row groups into two valid two-row groups.
             * Anything more complex should be reviewed manually first.
             */
            if ((int)$groupA['transaction_count'] !== 2 || (int)$groupB['transaction_count'] !== 2) {
                continue;
            }

            $combined = array_merge($rowsByGroup[$groupAId] ?? [], $rowsByGroup[$groupBId] ?? []);
            if (count($combined) !== 4) {
                continue;
            }

            $negativeRows = array_values(array_filter($combined, static fn(array $row): bool => (float)$row['amount'] < 0));
            $positiveRows = array_values(array_filter($combined, static fn(array $row): bool => (float)$row['amount'] > 0));

            if (count($negativeRows) !== 2 || count($positiveRows) !== 2) {
                continue;
            }

            $pairs = tg_best_matching($negativeRows, $positiveRows);
            if ($pairs === null || count($pairs) !== 2) {
                continue;
            }

            $maxDateGap = max(array_map(static fn(array $pair): int => (int)$pair['date_gap_days'], $pairs));
            if ($maxDateGap > 3) {
                $proposals[] = [
                    'proposal_type' => 'manual_review_required',
                    'reason' => 'Best full-group pairing contains a date gap greater than 3 days.',
                    'source_group_ids' => [$groupAId, $groupBId],
                    'source_group_totals' => [
                        (string)$groupAId => tg_money($groupATotal),
                        (string)$groupBId => tg_money($groupBTotal),
                    ],
                    'pairs' => tg_format_pairs_for_plan($pairs, [$groupAId, $groupBId]),
                    'max_date_gap_days' => $maxDateGap,
                ];
                continue;
            }

            $proposals[] = [
                'proposal_type' => 'safe_full_group_pair',
                'reason' => 'Two non-zero two-row groups can be repartitioned into two balanced two-row groups using only transactions.transfer_group_id updates.',
                'source_group_ids' => [$groupAId, $groupBId],
                'source_group_totals' => [
                    (string)$groupAId => tg_money($groupATotal),
                    (string)$groupBId => tg_money($groupBTotal),
                ],
                'pairs' => tg_format_pairs_for_plan($pairs, [$groupAId, $groupBId]),
                'max_date_gap_days' => $maxDateGap,
            ];
        }
    }

    return $proposals;
}

function tg_format_pairs_for_plan(array $pairs, array $targetGroupIds): array
{
    usort($pairs, static function (array $a, array $b): int {
        $dateCmp = strcmp((string)$a['negative']['date'], (string)$b['negative']['date']);
        if ($dateCmp !== 0) {
            return $dateCmp;
        }

        return ((int)$a['negative']['id']) <=> ((int)$b['negative']['id']);
    });

    $formatted = [];
    foreach ($pairs as $idx => $pair) {
        $targetGroupId = $targetGroupIds[$idx] ?? $targetGroupIds[0];

        $formatted[] = [
            'target_transfer_group_id' => $targetGroupId,
            'negative_transaction_id' => (int)$pair['negative']['id'],
            'negative_current_group_id' => (int)$pair['negative']['transfer_group_id'],
            'negative_account_id' => (int)$pair['negative']['account_id'],
            'negative_account_name' => (string)$pair['negative']['account_name'],
            'negative_date' => (string)$pair['negative']['date'],
            'negative_description' => (string)$pair['negative']['description'],
            'negative_amount' => tg_money((float)$pair['negative']['amount']),
            'positive_transaction_id' => (int)$pair['positive']['id'],
            'positive_current_group_id' => (int)$pair['positive']['transfer_group_id'],
            'positive_account_id' => (int)$pair['positive']['account_id'],
            'positive_account_name' => (string)$pair['positive']['account_name'],
            'positive_date' => (string)$pair['positive']['date'],
            'positive_description' => (string)$pair['positive']['description'],
            'positive_amount' => tg_money((float)$pair['positive']['amount']),
            'date_gap_days' => (int)$pair['date_gap_days'],
            'description_match' => (bool)$pair['description_match'],
        ];
    }

    return $formatted;
}

function tg_fetch_row_level_candidates(PDO $pdo): array
{
    $stmt = $pdo->query("
        WITH bad_groups AS (
            SELECT
                transfer_group_id,
                ROUND(SUM(amount), 2) AS group_total,
                COUNT(*) AS group_count
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND type = 'transfer'
            GROUP BY transfer_group_id
            HAVING ROUND(SUM(amount), 2) <> 0
        ),
        bad_rows AS (
            SELECT
                t.id,
                t.account_id,
                a.name AS account_name,
                t.date,
                t.description,
                t.amount,
                ABS(t.amount) AS abs_amount,
                t.transfer_group_id,
                bg.group_total
            FROM transactions t
            JOIN accounts a ON a.id = t.account_id
            JOIN bad_groups bg ON bg.transfer_group_id = t.transfer_group_id
            WHERE t.type = 'transfer'
        )
        SELECT
            neg.id AS negative_transaction_id,
            neg.account_name AS negative_account,
            neg.date AS negative_date,
            neg.description AS negative_description,
            neg.amount AS negative_amount,
            neg.transfer_group_id AS negative_current_group,

            pos.id AS positive_transaction_id,
            pos.account_name AS positive_account,
            pos.date AS positive_date,
            pos.description AS positive_description,
            pos.amount AS positive_amount,
            pos.transfer_group_id AS positive_current_group,

            ABS(DATEDIFF(neg.date, pos.date)) AS date_gap_days,

            CASE
                WHEN neg.date = pos.date THEN 0
                ELSE 1
            END AS date_rank,

            CASE
                WHEN neg.description = pos.description THEN 0
                ELSE 1
            END AS description_rank
        FROM bad_rows neg
        JOIN bad_rows pos
          ON pos.amount = -neg.amount
         AND pos.id != neg.id
         AND pos.transfer_group_id != neg.transfer_group_id
         AND pos.account_id != neg.account_id
         AND ABS(DATEDIFF(pos.date, neg.date)) <= 3
        WHERE neg.amount < 0
        ORDER BY
            neg.date,
            neg.abs_amount,
            date_rank,
            description_rank,
            neg.id,
            pos.id
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = get_db_connection();

$badGroups = tg_fetch_bad_group_summary($pdo);
$badRows = tg_fetch_bad_rows($pdo);
$proposals = tg_build_safe_full_group_pair_proposals($badGroups, $badRows);
$rowLevelCandidates = tg_fetch_row_level_candidates($pdo);

$safeProposals = array_values(array_filter(
    $proposals,
    static fn(array $proposal): bool => ($proposal['proposal_type'] ?? '') === 'safe_full_group_pair'
));

$manualProposals = array_values(array_filter(
    $proposals,
    static fn(array $proposal): bool => ($proposal['proposal_type'] ?? '') !== 'safe_full_group_pair'
));

$plan = [
    'version' => 1,
    'generated_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    'description' => 'BKL-056A transfer group integrity repair plan. The repair script may only update transactions.transfer_group_id.',
    'rules' => [
        'only_transactions_transfer_group_id_may_be_updated',
        'no_transaction_amount_date_description_account_type_category_fields_may_be_changed',
        'safe_full_group_pair_requires_two_bad_two-row_groups_with_exactly_offsetting totals',
        'safe_full_group_pair_requires_all_row_pairs_to be within 3 days',
    ],
    'bad_group_count' => count($badGroups),
    'bad_groups' => $badGroups,
    'safe_full_group_pair_count' => count($safeProposals),
    'manual_review_proposal_count' => count($manualProposals),
    'proposals' => $proposals,
    'row_level_candidates' => $rowLevelCandidates,
];

$reportDir = __DIR__ . '/../../storage/reports';
if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    throw new RuntimeException("Unable to create report directory: {$reportDir}");
}

$planPath = $reportDir . '/transfer_group_integrity_plan.json';
file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

echo "Transfer group integrity audit\n";
echo "==============================\n";
echo "Bad groups: " . count($badGroups) . "\n";
echo "Safe full-group repair proposals: " . count($safeProposals) . "\n";
echo "Manual-review proposals: " . count($manualProposals) . "\n";
echo "Row-level candidates: " . count($rowLevelCandidates) . "\n";
echo "Plan written to: {$planPath}\n\n";

if (!empty($safeProposals)) {
    echo "Safe proposals:\n";
    foreach ($safeProposals as $idx => $proposal) {
        $proposalNo = $idx + 1;
        $groups = implode(', ', $proposal['source_group_ids']);
        echo "  {$proposalNo}. Groups {$groups}; max date gap {$proposal['max_date_gap_days']} day(s)\n";
        foreach ($proposal['pairs'] as $pair) {
            echo "     - TG {$pair['target_transfer_group_id']}: "
                . "#{$pair['negative_transaction_id']} {$pair['negative_account_name']} {$pair['negative_date']} {$pair['negative_amount']} "
                . "<-> "
                . "#{$pair['positive_transaction_id']} {$pair['positive_account_name']} {$pair['positive_date']} +" . tg_money((float)$pair['positive_amount'])
                . "\n";
        }
    }
}

if (!empty($manualProposals)) {
    echo "\nManual review proposals:\n";
    foreach ($manualProposals as $idx => $proposal) {
        $proposalNo = $idx + 1;
        $groups = implode(', ', $proposal['source_group_ids']);
        echo "  {$proposalNo}. Groups {$groups}: {$proposal['reason']}\n";
    }
}

exit(0);

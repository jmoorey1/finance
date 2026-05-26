<?php
require_once __DIR__ . '/finance_periods.php';
require_once __DIR__ . '/insights_service.php';
require_once __DIR__ . '/weekly_delivery_sections.php';

function wsb_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function wsb_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function wsb_load_variable_categories(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name, priority
        FROM categories
        WHERE type = 'expense'
          AND fixedness = 'variable'
          AND parent_id IS NULL
          AND COALESCE(watcher_budget_mode, 'normal') = 'normal'
        ORDER BY budget_order, name
    ");

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = [
            'name' => (string)$row['name'],
            'priority' => (string)($row['priority'] ?? ''),
        ];
    }

    return $out;
}

function wsb_load_budget_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end): array
{
    $stmt = $pdo->prepare("
        SELECT category_id, SUM(amount) AS total
        FROM budgets
        WHERE month_start BETWEEN ? AND ?
        GROUP BY category_id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['category_id']] = (float)$row['total'];
    }

    return $out;
}

function wsb_load_actual_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end): array
{
    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
            SUM(-ll.amount) AS total
        FROM ledger_lines ll
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.fixedness = 'variable'
          AND topcat.parent_id IS NULL
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND ll.is_prediction = 0
          AND ll.line_date BETWEEN ? AND ?
        GROUP BY topcat.id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = (float)$row['total'];
    }

    return $out;
}

function wsb_load_forecast_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end): array
{
    if ($start > $end) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
            SUM(-ll.amount) AS total
        FROM ledger_lines ll
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.fixedness = 'variable'
          AND topcat.parent_id IS NULL
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND ll.is_prediction = 1
          AND ll.line_date BETWEEN ? AND ?
        GROUP BY topcat.id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = (float)$row['total'];
    }

    return $out;
}

function weekly_summary_build(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $range = get_weekly_digest_reporting_range($today);
    $today = $range['today'];
    $startMonth = $range['start'];
    $endMonth = $range['end'];
    $startYtd = get_financial_ytd_start($startMonth);

    $categories = wsb_load_variable_categories($pdo);

    $monthlyBudget = wsb_load_budget_totals($pdo, $startMonth, $endMonth);
    $ytdBudget = wsb_load_budget_totals($pdo, $startYtd, $endMonth);

    $monthlyActual = wsb_load_actual_totals($pdo, $startMonth, $endMonth);
    $ytdActual = wsb_load_actual_totals($pdo, $startYtd, $endMonth);

    $forecastStart = $today > $startMonth ? $today : $startMonth;
    $monthlyForecast = wsb_load_forecast_totals($pdo, $forecastStart, $endMonth);
    $ytdForecast = $monthlyForecast;

    $headlines = build_budget_headlines($pdo, $today);

    return [
        'today' => $today,
        'start_month' => $startMonth,
        'end_month' => $endMonth,
        'start_ytd' => $startYtd,
        'month_label' => $startMonth->format('j M') . ' – ' . $endMonth->format('j M'),
        'ytd_label' => $startYtd->format('j M') . ' – ' . $endMonth->format('j M Y'),
        'categories' => $categories,
        'funding' => wds_load_funding_snapshot($pdo, $today),
        'watcher' => wds_load_watcher_snapshot($pdo, $today),
        'headlines' => $headlines,
        'monthly_budget' => $monthlyBudget,
        'monthly_actual' => $monthlyActual,
        'monthly_forecast' => $monthlyForecast,
        'ytd_budget' => $ytdBudget,
        'ytd_actual' => $ytdActual,
        'ytd_forecast' => $ytdForecast,
    ];
}

function wsb_render_section_rows_html(array $categories, array $budget, array $actual, array $forecast, string $priority): string
{
    $rows = '';

    foreach ($categories as $id => $meta) {
        if (($meta['priority'] ?? '') !== $priority) {
            continue;
        }

        $name = (string)$meta['name'];
        $b = (float)($budget[$id] ?? 0);
        $a = (float)($actual[$id] ?? 0);
        $f = (float)($forecast[$id] ?? 0);
        $v = $b - $a - $f;

        if ($b == 0.0 && $a == 0.0 && $f == 0.0) {
            continue;
        }

        $varianceColor = $v >= 0 ? 'green' : 'red';

        $rows .= '<tr>';
        $rows .= '<td style="border:1px solid #ccc;">' . wsb_html($name) . '</td>';
        $rows .= '<td align="right" style="border:1px solid #ccc;">' . wsb_money($b) . '</td>';
        $rows .= '<td align="right" style="border:1px solid #ccc;">' . wsb_money($a) . '</td>';
        $rows .= '<td align="right" style="border:1px solid #ccc;">' . wsb_money($f) . '</td>';
        $rows .= '<td align="right" style="border:1px solid #ccc; color:' . $varianceColor . ';">' . wsb_money($v) . '</td>';
        $rows .= '</tr>';
    }

    return $rows;
}

function weekly_summary_render_table_html(string $label, array $categories, array $budget, array $actual, array $forecast): string
{
    $html = '<h3 style="margin-top:30px; font-family:sans-serif;">' . wsb_html($label) . '</h3>';
    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%;">';
    $html .= '<tr style="background:#333; color:#fff;">';
    $html .= '<th align="left">Category</th>';
    $html .= '<th align="right">Budget</th>';
    $html .= '<th align="right">Actual</th>';
    $html .= '<th align="right">Forecast</th>';
    $html .= '<th align="right">Variance</th>';
    $html .= '</tr>';

    foreach (['essential' => 'Essential Expenses', 'discretionary' => 'Discretionary Expenses'] as $priority => $labelText) {
        $rows = wsb_render_section_rows_html($categories, $budget, $actual, $forecast, $priority);

        if ($rows !== '') {
            $html .= '<tr><td colspan="5" style="background:#f5f5f5; font-weight:bold;">' . wsb_html($labelText) . '</td></tr>';
            $html .= $rows;
        }
    }

    $html .= '</table>';

    return $html;
}

function weekly_summary_render_headlines_html(array $headlines): string
{
    if (empty($headlines)) {
        return '';
    }

    $html = '<h3 style="font-family:sans-serif;">Key Finance Headlines</h3>';
    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%;">';
    $html .= '<tr style="background:#333; color:#fff;">';
    $html .= '<th align="left">Considering current and planned activity this financial month</th>';
    $html .= '</tr>';

    foreach ($headlines as $line) {
        $html .= '<tr><td style="border:1px solid #ccc; white-space:pre-line;">' . nl2br(wsb_html((string)$line)) . '</td></tr>';
    }

    $html .= '</table><br>';

    return $html;
}

function weekly_summary_render_html(array $summary): string
{
    $body = '<h2 style="font-family:sans-serif;">Weekly Home Finances Digest</h2>';
    $body .= wds_render_funding_html($summary['funding']);
    $body .= wds_render_watcher_html($summary['watcher']);
    $body .= weekly_summary_render_headlines_html($summary['headlines']);
    $body .= '<p style="font-family:sans-serif;">Budget tables below include <strong>variable expense categories</strong> only.</p>';
    $body .= weekly_summary_render_table_html(
        'This Month: ' . $summary['month_label'],
        $summary['categories'],
        $summary['monthly_budget'],
        $summary['monthly_actual'],
        $summary['monthly_forecast']
    );
    $body .= weekly_summary_render_table_html(
        'Year to Date: ' . $summary['ytd_label'],
        $summary['categories'],
        $summary['ytd_budget'],
        $summary['ytd_actual'],
        $summary['ytd_forecast']
    );

    return $body;
}

function wsb_render_section_rows_text(array $categories, array $budget, array $actual, array $forecast, string $priority): array
{
    $lines = [];

    foreach ($categories as $id => $meta) {
        if (($meta['priority'] ?? '') !== $priority) {
            continue;
        }

        $name = (string)$meta['name'];
        $b = (float)($budget[$id] ?? 0);
        $a = (float)($actual[$id] ?? 0);
        $f = (float)($forecast[$id] ?? 0);
        $v = $b - $a - $f;

        if ($b == 0.0 && $a == 0.0 && $f == 0.0) {
            continue;
        }

        $lines[] = "{$name} | Budget " . wsb_money($b) . " | Actual " . wsb_money($a) . " | Forecast " . wsb_money($f) . " | Variance " . wsb_money($v);
    }

    return $lines;
}

function weekly_summary_render_text(array $summary): string
{
    $lines = [];
    $lines[] = 'Weekly Home Finances Digest';
    $lines[] = '';
    $lines[] = rtrim(wds_render_funding_text($summary['funding']));
    $lines[] = rtrim(wds_render_watcher_text($summary['watcher']));

    if (!empty($summary['headlines'])) {
        $lines[] = 'Key Finance Headlines';
        $lines[] = '--------------------';
        foreach ($summary['headlines'] as $headline) {
            $headlineLines = preg_split('/\R/', (string)$headline);
            foreach ($headlineLines as $headlineLine) {
                $lines[] = '- ' . $headlineLine;
            }
        }
        $lines[] = '';
    }

    $lines[] = 'Budget tables below include variable expense categories only.';
    $lines[] = '';

    foreach ([
        'This Month: ' . $summary['month_label'] => [
            'budget' => $summary['monthly_budget'],
            'actual' => $summary['monthly_actual'],
            'forecast' => $summary['monthly_forecast'],
        ],
        'Year to Date: ' . $summary['ytd_label'] => [
            'budget' => $summary['ytd_budget'],
            'actual' => $summary['ytd_actual'],
            'forecast' => $summary['ytd_forecast'],
        ],
    ] as $sectionLabel => $sectionData) {
        $lines[] = $sectionLabel;
        $lines[] = str_repeat('-', strlen($sectionLabel));

        foreach (['essential' => 'Essential Expenses', 'discretionary' => 'Discretionary Expenses'] as $priority => $labelText) {
            $rows = wsb_render_section_rows_text(
                $summary['categories'],
                $sectionData['budget'],
                $sectionData['actual'],
                $sectionData['forecast'],
                $priority
            );

            if (!empty($rows)) {
                $lines[] = $labelText;
                foreach ($rows as $row) {
                    $lines[] = '  ' . $row;
                }
            }
        }

        $lines[] = '';
    }

    return trim(implode(PHP_EOL, $lines)) . PHP_EOL;
}

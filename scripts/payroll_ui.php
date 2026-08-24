<?php

declare(strict_types=1);

function payroll_ui_get_employments(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            e.id AS employment_id,
            e.person_id,
            p.full_name,
            e.employee_number,
            e.tax_reference,
            e.status,
            COUNT(ps.id) AS payslip_count,
            MIN(ps.pay_date) AS first_pay_date,
            MAX(ps.pay_date) AS latest_pay_date
        FROM payroll_employments e
        JOIN payroll_people p
          ON p.id = e.person_id
        LEFT JOIN payroll_payslips ps
          ON ps.employment_id = e.id
        GROUP BY
            e.id,
            e.person_id,
            p.full_name,
            e.employee_number,
            e.tax_reference,
            e.status
        ORDER BY
            latest_pay_date DESC,
            p.full_name ASC,
            e.id ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_resolve_employment(
    array $employments,
    ?int $requestedEmploymentId
): ?array {
    if ($requestedEmploymentId !== null && $requestedEmploymentId > 0) {
        foreach ($employments as $employment) {
            if (
                (int)$employment['employment_id']
                === $requestedEmploymentId
            ) {
                return $employment;
            }
        }
    }

    return $employments[0] ?? null;
}

function payroll_ui_current_tax_year_start(
    ?DateTimeInterface $date = null
): int {
    $date = $date ?? new DateTimeImmutable('today');

    $adjusted = DateTimeImmutable::createFromInterface($date)
        ->modify('-5 days');

    $year = (int)$adjusted->format('Y');

    return (int)$adjusted->format('n') < 4
        ? $year - 1
        : $year;
}

function payroll_ui_tax_year_label(int $taxYearStart): string
{
    return sprintf(
        '%d/%02d',
        $taxYearStart,
        ($taxYearStart + 1) % 100
    );
}

function payroll_ui_get_latest_payslip(
    PDO $pdo,
    int $employmentId
): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM payroll_payslip_summary
        WHERE employment_id = ?
        ORDER BY pay_date DESC, payslip_id DESC
        LIMIT 1
    ");

    $stmt->execute([$employmentId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function payroll_ui_get_current_ytd(
    PDO $pdo,
    int $employmentId
): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM payroll_ytd_summary
        WHERE employment_id = ?
        LIMIT 1
    ");

    $stmt->execute([$employmentId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function payroll_ui_get_expense_totals(
    PDO $pdo,
    int $employmentId
): array {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN pm.funding_type = 'corporate'
                        THEN x.gbp_amount
                        ELSE 0
                    END
                ),
                0
            ) AS corporate_expenses,

            COALESCE(
                SUM(
                    CASE
                        WHEN pm.funding_type = 'personal'
                        THEN x.gbp_amount
                        ELSE 0
                    END
                ),
                0
            ) AS personal_expenses,

            COALESCE(SUM(x.gbp_amount), 0) AS total_expenses,
            COUNT(x.id) AS expense_count,
            COUNT(DISTINCT r.id) AS report_count

        FROM payroll_expense_reports r

        LEFT JOIN payroll_expenses x
          ON x.report_id = r.id

        LEFT JOIN payroll_expense_payment_methods pm
          ON pm.id = x.payment_method_id

        WHERE r.employment_id = ?
    ");

    $stmt->execute([$employmentId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [
        'corporate_expenses' => 0,
        'personal_expenses' => 0,
        'total_expenses' => 0,
        'expense_count' => 0,
        'report_count' => 0,
    ];
}

function payroll_ui_get_tax_year_summaries(
    PDO $pdo,
    int $employmentId
): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM payroll_tax_year_summary
        WHERE employment_id = ?
        ORDER BY tax_year_start DESC
    ");

    $stmt->execute([$employmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_get_recent_payslips(
    PDO $pdo,
    int $employmentId,
    int $limit = 6
): array {
    $limit = max(1, min($limit, 50));

    $stmt = $pdo->prepare("
        SELECT *
        FROM payroll_payslip_summary
        WHERE employment_id = ?
        ORDER BY pay_date DESC, payslip_id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([$employmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_get_tax_year_options(
    PDO $pdo,
    int $employmentId
): array {
    $stmt = $pdo->prepare("
        SELECT
            tax_year_start,
            tax_year,
            COUNT(*) AS payslip_count,
            MIN(pay_date) AS first_pay_date,
            MAX(pay_date) AS latest_pay_date
        FROM payroll_payslip_summary
        WHERE employment_id = ?
        GROUP BY tax_year_start, tax_year
        ORDER BY tax_year_start DESC
    ");

    $stmt->execute([$employmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_get_payslips(
    PDO $pdo,
    int $employmentId,
    ?int $taxYearStart = null
): array {
    $sql = "
        SELECT *
        FROM payroll_payslip_summary
        WHERE employment_id = ?
    ";

    $params = [$employmentId];

    if ($taxYearStart !== null) {
        $sql .= " AND tax_year_start = ?";
        $params[] = $taxYearStart;
    }

    $sql .= " ORDER BY pay_date DESC, payslip_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_get_payslip(
    PDO $pdo,
    int $payslipId
): ?array {
    $stmt = $pdo->prepare("
        SELECT
            ps.*,
            e.employee_number,
            e.tax_reference,
            e.status AS employment_status
        FROM payroll_payslip_summary ps
        JOIN payroll_employments e
          ON e.id = ps.employment_id
        WHERE ps.payslip_id = ?
        LIMIT 1
    ");

    $stmt->execute([$payslipId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function payroll_ui_get_payslip_lines(
    PDO $pdo,
    int $payslipId
): array {
    $stmt = $pdo->prepare("
        SELECT
            li.id,
            li.code,
            li.description,
            li.amount,
            li.is_notional,
            c.id AS category_id,
            c.name AS category_name,
            c.display_order,
            lt.name AS line_type
        FROM payroll_line_items li
        JOIN payroll_categories c
          ON c.id = li.category_id
        JOIN payroll_line_types lt
          ON lt.id = c.line_type_id
        WHERE li.payslip_id = ?
        ORDER BY
            CASE
                WHEN lt.name = 'Pay' THEN 0
                ELSE 1
            END,
            c.display_order,
            li.id
    ");

    $stmt->execute([$payslipId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payroll_ui_get_adjacent_payslips(
    PDO $pdo,
    int $employmentId,
    string $payDate,
    int $payslipId
): array {
    $previousStmt = $pdo->prepare("
        SELECT id, pay_date
        FROM payroll_payslips
        WHERE employment_id = ?
          AND (
              pay_date < ?
              OR (pay_date = ? AND id < ?)
          )
        ORDER BY pay_date DESC, id DESC
        LIMIT 1
    ");

    $previousStmt->execute([
        $employmentId,
        $payDate,
        $payDate,
        $payslipId,
    ]);

    $previous = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $nextStmt = $pdo->prepare("
        SELECT id, pay_date
        FROM payroll_payslips
        WHERE employment_id = ?
          AND (
              pay_date > ?
              OR (pay_date = ? AND id > ?)
          )
        ORDER BY pay_date ASC, id ASC
        LIMIT 1
    ");

    $nextStmt->execute([
        $employmentId,
        $payDate,
        $payDate,
        $payslipId,
    ]);

    $next = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'previous' => $previous,
        'next' => $next,
    ];
}

function payroll_ui_h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function payroll_ui_money($value): string
{
    return '£' . number_format((float)$value, 2);
}

ALTER TABLE payroll_line_items
    ADD COLUMN reporting_scope
        ENUM('ordinary', 'equity', 'combined')
        NOT NULL
        DEFAULT 'ordinary'
        COMMENT 'Reporting allocation: ordinary payroll, equity/RSU, or combined/unallocated'
        AFTER is_notional;


/*
 * Source-validated reference case:
 * India Moorey, 31-May-2024, payslip #194.
 *
 * This source statement is an RSU-only payroll event. All represented lines
 * belong to the equity event rather than ordinary salary reporting.
 */
UPDATE payroll_line_items li

JOIN payroll_payslips p
  ON p.id = li.payslip_id

JOIN payroll_employments e
  ON e.id = p.employment_id

JOIN payroll_people person
  ON person.id = e.person_id

SET li.reporting_scope = 'equity'

WHERE p.id = 194
  AND p.pay_date = '2024-05-31'
  AND person.full_name = 'India Moorey'
  AND p.statement_total_earnings = 0.00
  AND p.statement_net_pay = 724.32
  AND p.statement_amount_paid = 724.32;


/*
 * Line-level economic reporting buckets.
 *
 * Category/line_type continues to define what a line is.
 * reporting_scope defines which economic reporting bucket it belongs to.
 */
CREATE OR REPLACE VIEW payroll_payslip_reporting_line_summary AS
SELECT
    li.payslip_id,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'ordinary'
            THEN li.amount
            ELSE 0
        END
    ) AS ordinary_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'ordinary'
             AND li.is_notional = 0
            THEN li.amount
            ELSE 0
        END
    ) AS ordinary_cash_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'ordinary'
             AND li.is_notional = 1
            THEN li.amount
            ELSE 0
        END
    ) AS ordinary_notional_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'equity'
            THEN li.amount
            ELSE 0
        END
    ) AS equity_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'equity'
             AND li.is_notional = 0
            THEN li.amount
            ELSE 0
        END
    ) AS equity_cash_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'equity'
             AND li.is_notional = 1
            THEN li.amount
            ELSE 0
        END
    ) AS equity_notional_compensation,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND li.reporting_scope = 'combined'
            THEN li.amount
            ELSE 0
        END
    ) AS combined_compensation,

    SUM(
        CASE
            WHEN c.name = 'TAXES'
             AND li.reporting_scope = 'ordinary'
            THEN li.amount
            ELSE 0
        END
    ) AS ordinary_tax_ni,

    SUM(
        CASE
            WHEN c.name = 'TAXES'
             AND li.reporting_scope = 'equity'
            THEN li.amount
            ELSE 0
        END
    ) AS equity_tax_ni,

    SUM(
        CASE
            WHEN c.name = 'TAXES'
             AND li.reporting_scope = 'combined'
            THEN li.amount
            ELSE 0
        END
    ) AS combined_tax_ni,

    SUM(
        CASE
            WHEN lt.name = 'Deduction'
             AND c.name <> 'TAXES'
             AND li.reporting_scope = 'ordinary'
             AND li.is_notional = 0
            THEN li.amount
            ELSE 0
        END
    ) AS ordinary_other_deductions,

    SUM(
        CASE
            WHEN lt.name = 'Deduction'
             AND c.name <> 'TAXES'
             AND li.reporting_scope = 'equity'
             AND li.is_notional = 0
            THEN li.amount
            ELSE 0
        END
    ) AS equity_other_deductions,

    SUM(
        CASE
            WHEN lt.name = 'Deduction'
             AND c.name <> 'TAXES'
             AND li.reporting_scope = 'combined'
             AND li.is_notional = 0
            THEN li.amount
            ELSE 0
        END
    ) AS combined_other_deductions,

    SUM(
        CASE
            WHEN li.reporting_scope = 'ordinary'
            THEN 1
            ELSE 0
        END
    ) AS ordinary_line_count,

    SUM(
        CASE
            WHEN li.reporting_scope = 'equity'
            THEN 1
            ELSE 0
        END
    ) AS equity_line_count,

    SUM(
        CASE
            WHEN li.reporting_scope = 'combined'
            THEN 1
            ELSE 0
        END
    ) AS combined_line_count

FROM payroll_line_items li

JOIN payroll_categories c
  ON c.id = li.category_id

JOIN payroll_line_types lt
  ON lt.id = c.line_type_id

GROUP BY
    li.payslip_id;


/*
 * Payslip-level reporting semantics.
 *
 * payroll_payslip_summary remains untouched so the existing Finance linkage
 * and source-truth Payroll semantics continue to behave exactly as before.
 */
CREATE OR REPLACE VIEW payroll_payslip_reporting_summary AS
SELECT
    ps.*,

    COALESCE(
        scope.ordinary_compensation,
        0
    ) AS ordinary_compensation,

    COALESCE(
        scope.ordinary_cash_compensation,
        0
    ) AS ordinary_cash_compensation,

    COALESCE(
        scope.ordinary_notional_compensation,
        0
    ) AS ordinary_notional_compensation,

    COALESCE(
        scope.equity_compensation,
        0
    ) AS equity_compensation,

    COALESCE(
        scope.equity_cash_compensation,
        0
    ) AS equity_cash_compensation,

    COALESCE(
        scope.equity_notional_compensation,
        0
    ) AS equity_notional_compensation,

    COALESCE(
        scope.combined_compensation,
        0
    ) AS combined_compensation,

    COALESCE(
        scope.ordinary_tax_ni,
        0
    ) AS ordinary_tax_ni,

    COALESCE(
        scope.equity_tax_ni,
        0
    ) AS equity_tax_ni,

    COALESCE(
        scope.combined_tax_ni,
        0
    ) AS combined_tax_ni,

    COALESCE(
        scope.ordinary_other_deductions,
        0
    ) AS ordinary_other_deductions,

    COALESCE(
        scope.equity_other_deductions,
        0
    ) AS equity_other_deductions,

    COALESCE(
        scope.combined_other_deductions,
        0
    ) AS combined_other_deductions,

    COALESCE(
        scope.ordinary_line_count,
        0
    ) AS ordinary_line_count,

    COALESCE(
        scope.equity_line_count,
        0
    ) AS equity_line_count,

    COALESCE(
        scope.combined_line_count,
        0
    ) AS combined_line_count,

    CASE
        WHEN COALESCE(
            scope.combined_tax_ni,
            0
        ) <> 0
            THEN NULL

        WHEN COALESCE(
            scope.ordinary_compensation,
            0
        ) = 0
            THEN NULL

        ELSE ROUND(
            scope.ordinary_tax_ni
            / scope.ordinary_compensation
            * 100,
            2
        )
    END AS ordinary_tax_percentage,

    CASE
        WHEN COALESCE(
            scope.combined_tax_ni,
            0
        ) <> 0
            THEN NULL

        WHEN COALESCE(
            scope.equity_compensation,
            0
        ) = 0
            THEN NULL

        ELSE ROUND(
            scope.equity_tax_ni
            / scope.equity_compensation
            * 100,
            2
        )
    END AS equity_tax_percentage,

    CASE
        WHEN COALESCE(
            scope.equity_line_count,
            0
        ) = 0
         AND COALESCE(
            scope.combined_line_count,
            0
        ) = 0
            THEN 'ordinary'

        WHEN COALESCE(
            scope.ordinary_line_count,
            0
        ) = 0
         AND COALESCE(
            scope.equity_line_count,
            0
        ) > 0
         AND COALESCE(
            scope.combined_line_count,
            0
        ) = 0
            THEN 'equity_only'

        ELSE 'mixed_equity'
    END AS reporting_mix

FROM payroll_payslip_summary ps

LEFT JOIN payroll_payslip_reporting_line_summary scope
  ON scope.payslip_id = ps.payslip_id;

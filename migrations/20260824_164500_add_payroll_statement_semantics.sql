ALTER TABLE payroll_payslips
    ADD COLUMN statement_total_earnings DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Total Earnings printed on the source payslip'
        AFTER annual_salary,

    ADD COLUMN statement_total_deductions DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Total Deductions printed on the source payslip, preserving its sign'
        AFTER statement_total_earnings,

    ADD COLUMN statement_net_pay DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Net Pay printed on the source payslip'
        AFTER statement_total_deductions,

    ADD COLUMN statement_amount_paid DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Amount Paid printed on the source payslip; authoritative cash settlement when captured'
        AFTER statement_net_pay,

    ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL
        COMMENT 'Payment method printed on the source payslip, e.g. Bacs or Cheque'
        AFTER statement_amount_paid,

    ADD CONSTRAINT chk_payroll_payslips_statement_arithmetic
        CHECK (
            statement_total_earnings IS NULL
            OR statement_total_deductions IS NULL
            OR statement_net_pay IS NULL
            OR statement_net_pay
                = statement_total_earnings
                - statement_total_deductions
        );

ALTER TABLE payroll_line_items
    ADD COLUMN is_notional TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 when the source payslip marks this line as shown but not paid'
        AFTER category_id,

    ADD CONSTRAINT chk_payroll_line_items_is_notional
        CHECK (
            is_notional IN (0, 1)
        );

CREATE OR REPLACE VIEW payroll_payslip_summary AS
SELECT
    p.id AS payslip_id,
    p.employment_id,
    e.person_id,
    person.full_name AS person_name,
    p.pay_date,

    DATE_FORMAT(
        p.pay_date,
        '%Y-%m-01'
    ) AS month_start,

    p.tax_year_start,

    CONCAT(
        p.tax_year_start,
        '/',
        RIGHT(
            p.tax_year_start + 1,
            2
        )
    ) AS tax_year,

    p.tax_month,
    p.tax_code,
    p.annual_salary,

    p.statement_total_earnings,
    p.statement_total_deductions,
    p.statement_net_pay,
    p.statement_amount_paid,
    p.payment_method,

    SUM(
        CASE
            WHEN c.name = 'BASIC PAY'
            THEN li.amount
            ELSE 0
        END
    ) AS basic_pay,

    SUM(
        CASE
            WHEN c.name = 'BENEFITS'
            THEN li.amount
            ELSE 0
        END
    ) AS benefits,

    SUM(
        CASE
            WHEN c.name = 'PRE-TAX DEDUCTIONS'
            THEN li.amount
            ELSE 0
        END
    ) AS pre_tax_deductions,

    SUM(
        CASE
            WHEN c.name = 'ADDITIONAL EARNINGS'
            THEN li.amount
            ELSE 0
        END
    ) AS additional_earnings,

    SUM(
        CASE
            WHEN c.name = 'BONUS'
            THEN li.amount
            ELSE 0
        END
    ) AS bonus,

    SUM(
        CASE
            WHEN c.name = 'PENSION'
            THEN li.amount
            ELSE 0
        END
    ) AS pension,

    SUM(
        CASE
            WHEN c.name = 'TAXES'
            THEN li.amount
            ELSE 0
        END
    ) AS taxes,

    SUM(
        CASE
            WHEN c.name = 'POST-TAX DEDUCTIONS'
            THEN li.amount
            ELSE 0
        END
    ) AS post_tax_deductions,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
            THEN li.amount
            ELSE 0
        END
    ) AS total_gross,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND COALESCE(
                    li.is_notional,
                    0
                 ) = 1
            THEN li.amount
            ELSE 0
        END
    ) AS notional_pay,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND COALESCE(
                    li.is_notional,
                    0
                 ) = 0
            THEN li.amount
            ELSE 0
        END
    ) AS calculated_cash_earnings,

    COALESCE(
        p.statement_total_earnings,

        SUM(
            CASE
                WHEN lt.name = 'Pay'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
    ) AS cash_earnings,

    SUM(
        CASE
            WHEN lt.name = 'Deduction'
             AND COALESCE(
                    li.is_notional,
                    0
                 ) = 0
            THEN li.amount
            ELSE 0
        END
    ) AS calculated_total_deductions,

    COALESCE(
        p.statement_total_deductions,

        SUM(
            CASE
                WHEN lt.name = 'Deduction'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
    ) AS total_deductions,

    SUM(
        CASE
            WHEN lt.name = 'Pay'
             AND COALESCE(
                    li.is_notional,
                    0
                 ) = 0
            THEN li.amount
            ELSE 0
        END
    )
    -
    SUM(
        CASE
            WHEN lt.name = 'Deduction'
             AND COALESCE(
                    li.is_notional,
                    0
                 ) = 0
            THEN li.amount
            ELSE 0
        END
    ) AS calculated_net_pay,

    COALESCE(
        p.statement_net_pay,

        SUM(
            CASE
                WHEN lt.name = 'Pay'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
        -
        SUM(
            CASE
                WHEN lt.name = 'Deduction'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
    ) AS net_pay,

    p.statement_amount_paid AS amount_paid,

    COALESCE(
        p.statement_amount_paid,
        p.statement_net_pay,

        SUM(
            CASE
                WHEN lt.name = 'Pay'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
        -
        SUM(
            CASE
                WHEN lt.name = 'Deduction'
                 AND COALESCE(
                        li.is_notional,
                        0
                     ) = 0
                THEN li.amount
                ELSE 0
            END
        )
    ) AS settlement_amount,

    CASE
        WHEN p.statement_amount_paid IS NOT NULL
            THEN 'statement_amount_paid'

        WHEN p.statement_net_pay IS NOT NULL
            THEN 'statement_net_pay'

        ELSE 'calculated_lines'
    END AS settlement_amount_source,

    ROUND(
        CASE
            WHEN SUM(
                CASE
                    WHEN lt.name = 'Pay'
                    THEN li.amount
                    ELSE 0
                END
            ) = 0
            THEN 0

            ELSE
                SUM(
                    CASE
                        WHEN c.name = 'TAXES'
                        THEN li.amount
                        ELSE 0
                    END
                )
                /
                SUM(
                    CASE
                        WHEN lt.name = 'Pay'
                        THEN li.amount
                        ELSE 0
                    END
                )
                * 100
        END,
        2
    ) AS tax_percentage,

    COUNT(
        li.id
    ) AS line_item_count,

    SUM(
        CASE
            WHEN COALESCE(
                    li.is_notional,
                    0
                 ) = 1
            THEN 1
            ELSE 0
        END
    ) AS notional_line_count

FROM payroll_payslips p

JOIN payroll_employments e
  ON e.id = p.employment_id

JOIN payroll_people person
  ON person.id = e.person_id

LEFT JOIN payroll_line_items li
  ON li.payslip_id = p.id

LEFT JOIN payroll_categories c
  ON c.id = li.category_id

LEFT JOIN payroll_line_types lt
  ON lt.id = c.line_type_id

GROUP BY
    p.id,
    p.employment_id,
    e.person_id,
    person.full_name,
    p.pay_date,
    p.tax_year_start,
    p.tax_month,
    p.tax_code,
    p.annual_salary,
    p.statement_total_earnings,
    p.statement_total_deductions,
    p.statement_net_pay,
    p.statement_amount_paid,
    p.payment_method;

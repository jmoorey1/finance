CREATE TABLE payroll_finance_mappings (
    employment_id INT NOT NULL,
    receiving_account_id INT NOT NULL,
    income_category_id INT DEFAULT NULL,
    prediction_rule_id INT DEFAULT NULL,
    linkage_start_date DATE NOT NULL DEFAULT '2020-01-01',
    candidate_window_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (employment_id),

    KEY idx_payroll_finance_mappings_account (
        receiving_account_id
    ),

    KEY idx_payroll_finance_mappings_category (
        income_category_id
    ),

    KEY idx_payroll_finance_mappings_prediction (
        prediction_rule_id
    ),

    CONSTRAINT fk_payroll_finance_mappings_employment
        FOREIGN KEY (employment_id)
        REFERENCES payroll_employments (id),

    CONSTRAINT fk_payroll_finance_mappings_account
        FOREIGN KEY (receiving_account_id)
        REFERENCES accounts (id),

    CONSTRAINT fk_payroll_finance_mappings_category
        FOREIGN KEY (income_category_id)
        REFERENCES categories (id),

    CONSTRAINT fk_payroll_finance_mappings_prediction
        FOREIGN KEY (prediction_rule_id)
        REFERENCES predicted_transactions (id),

    CONSTRAINT chk_payroll_finance_mappings_start_date
        CHECK (
            linkage_start_date >= '2020-01-01'
        ),

    CONSTRAINT chk_payroll_finance_mappings_window
        CHECK (
            candidate_window_days BETWEEN 0 AND 31
        )
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE payroll_payslip_transaction_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payslip_id INT NOT NULL,
    transaction_id INT NOT NULL,
    matched_amount DECIMAL(12,2) NOT NULL,
    match_method ENUM(
        'manual',
        'exact_same_day',
        'historical_backfill',
        'import_assisted'
    ) NOT NULL DEFAULT 'manual',
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_payroll_payslip_transaction_link (
        payslip_id,
        transaction_id
    ),

    UNIQUE KEY uq_payroll_transaction_single_payslip (
        transaction_id
    ),

    KEY idx_payroll_payslip_transaction_links_payslip (
        payslip_id,
        created_at
    ),

    CONSTRAINT fk_payroll_payslip_transaction_links_payslip
        FOREIGN KEY (payslip_id)
        REFERENCES payroll_payslips (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payroll_payslip_transaction_links_transaction
        FOREIGN KEY (transaction_id)
        REFERENCES transactions (id)
        ON DELETE CASCADE,

    CONSTRAINT chk_payroll_payslip_transaction_links_amount
        CHECK (
            matched_amount > 0
        )
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_0900_ai_ci;


CREATE VIEW payroll_payslip_transaction_link_totals AS
SELECT
    l.payslip_id,
    COUNT(*) AS link_count,
    SUM(l.matched_amount) AS linked_amount,
    MIN(t.date) AS first_transaction_date,
    MAX(t.date) AS last_transaction_date
FROM payroll_payslip_transaction_links l
JOIN transactions t
  ON t.id = l.transaction_id
GROUP BY
    l.payslip_id;


CREATE VIEW payroll_finance_link_status AS
SELECT
    ps.payslip_id,
    ps.employment_id,
    ps.person_id,
    ps.person_name,
    ps.pay_date,

    ps.statement_amount_paid,
    ps.statement_net_pay,
    ps.calculated_net_pay,
    ps.notional_line_count,
    ps.payment_method,

    fm.receiving_account_id,
    account.name AS receiving_account_name,

    fm.income_category_id,

    CASE
        WHEN income_category.id IS NULL
            THEN NULL

        WHEN parent_category.id IS NULL
            THEN income_category.name

        ELSE CONCAT(
            parent_category.name,
            ' : ',
            income_category.name
        )
    END AS income_category_label,

    fm.prediction_rule_id,
    prediction.description AS prediction_rule_description,

    fm.linkage_start_date,
    fm.candidate_window_days,

    CASE
        WHEN ps.statement_amount_paid IS NOT NULL
            THEN ps.statement_amount_paid

        WHEN ps.statement_net_pay IS NOT NULL
         AND ps.notional_line_count = 0
            THEN ps.statement_net_pay

        WHEN ps.notional_line_count = 0
            THEN ps.calculated_net_pay

        ELSE NULL
    END AS expected_settlement_amount,

    CASE
        WHEN ps.statement_amount_paid IS NOT NULL
            THEN 'statement_amount_paid'

        WHEN ps.statement_net_pay IS NOT NULL
         AND ps.notional_line_count = 0
            THEN 'statement_net_pay'

        WHEN ps.notional_line_count = 0
            THEN 'calculated_lines'

        ELSE 'manual_required'
    END AS expected_amount_source,

    COALESCE(
        totals.link_count,
        0
    ) AS link_count,

    COALESCE(
        totals.linked_amount,
        0
    ) AS linked_amount,

    totals.first_transaction_date,
    totals.last_transaction_date,

    CASE
        WHEN fm.employment_id IS NULL
            THEN 'unconfigured'

        WHEN ps.pay_date < fm.linkage_start_date
            THEN 'out_of_scope'

        WHEN (
            CASE
                WHEN ps.statement_amount_paid IS NOT NULL
                    THEN ps.statement_amount_paid

                WHEN ps.statement_net_pay IS NOT NULL
                 AND ps.notional_line_count = 0
                    THEN ps.statement_net_pay

                WHEN ps.notional_line_count = 0
                    THEN ps.calculated_net_pay

                ELSE NULL
            END
        ) IS NULL
            THEN 'no_settlement'

        WHEN (
            CASE
                WHEN ps.statement_amount_paid IS NOT NULL
                    THEN ps.statement_amount_paid

                WHEN ps.statement_net_pay IS NOT NULL
                 AND ps.notional_line_count = 0
                    THEN ps.statement_net_pay

                WHEN ps.notional_line_count = 0
                    THEN ps.calculated_net_pay

                ELSE NULL
            END
        ) <= 0
            THEN 'no_settlement'

        WHEN COALESCE(
            totals.link_count,
            0
        ) = 0
            THEN 'unlinked'

        WHEN ABS(
            COALESCE(
                totals.linked_amount,
                0
            )
            -
            (
                CASE
                    WHEN ps.statement_amount_paid IS NOT NULL
                        THEN ps.statement_amount_paid

                    WHEN ps.statement_net_pay IS NOT NULL
                     AND ps.notional_line_count = 0
                        THEN ps.statement_net_pay

                    WHEN ps.notional_line_count = 0
                        THEN ps.calculated_net_pay

                    ELSE NULL
                END
            )
        ) <= 0.01
            THEN 'settled'

        WHEN COALESCE(
            totals.linked_amount,
            0
        )
        <
        (
            CASE
                WHEN ps.statement_amount_paid IS NOT NULL
                    THEN ps.statement_amount_paid

                WHEN ps.statement_net_pay IS NOT NULL
                 AND ps.notional_line_count = 0
                    THEN ps.statement_net_pay

                WHEN ps.notional_line_count = 0
                    THEN ps.calculated_net_pay

                ELSE NULL
            END
        )
            THEN 'partial'

        ELSE 'overlinked'
    END AS link_status

FROM payroll_payslip_summary ps

LEFT JOIN payroll_finance_mappings fm
  ON fm.employment_id = ps.employment_id

LEFT JOIN accounts account
  ON account.id = fm.receiving_account_id

LEFT JOIN categories income_category
  ON income_category.id = fm.income_category_id

LEFT JOIN categories parent_category
  ON parent_category.id = income_category.parent_id

LEFT JOIN predicted_transactions prediction
  ON prediction.id = fm.prediction_rule_id

LEFT JOIN payroll_payslip_transaction_link_totals totals
  ON totals.payslip_id = ps.payslip_id;

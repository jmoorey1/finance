CREATE TABLE payroll_people (
    id INT NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    national_insurance_number VARCHAR(20) DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    gender_code CHAR(1) DEFAULT NULL,
    legacy_employee_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_people_ni_number (national_insurance_number),
    UNIQUE KEY uq_payroll_people_legacy_employee (legacy_employee_id),
    KEY idx_payroll_people_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_employments (
    id INT NOT NULL AUTO_INCREMENT,
    person_id INT NOT NULL,
    employer_name VARCHAR(100) DEFAULT NULL,
    employee_number VARCHAR(20) DEFAULT NULL,
    tax_reference VARCHAR(20) DEFAULT NULL,
    employment_start_date DATE DEFAULT NULL,
    employment_end_date DATE DEFAULT NULL,
    status ENUM('active','ended','unknown') NOT NULL DEFAULT 'unknown',
    legacy_employee_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employments_legacy_employee (legacy_employee_id),
    KEY idx_payroll_employments_person (person_id),
    KEY idx_payroll_employments_employee_number (employee_number),
    CONSTRAINT fk_payroll_employments_person
        FOREIGN KEY (person_id) REFERENCES payroll_people (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_line_types (
    id TINYINT NOT NULL AUTO_INCREMENT,
    name VARCHAR(20) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_line_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO payroll_line_types (id, name) VALUES
    (1, 'Pay'),
    (2, 'Deduction');

CREATE TABLE payroll_categories (
    id TINYINT NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    line_type_id TINYINT NOT NULL,
    display_order TINYINT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_categories_name (name),
    KEY idx_payroll_categories_line_type (line_type_id),
    CONSTRAINT fk_payroll_categories_line_type
        FOREIGN KEY (line_type_id) REFERENCES payroll_line_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO payroll_categories (id, name, line_type_id, display_order) VALUES
    (1, 'BASIC PAY', 1, 1),
    (2, 'BENEFITS', 1, 2),
    (3, 'PRE-TAX DEDUCTIONS', 1, 3),
    (4, 'ADDITIONAL EARNINGS', 1, 4),
    (5, 'BONUS', 1, 5),
    (6, 'PENSION', 1, 6),
    (7, 'TAXES', 2, 7),
    (8, 'POST-TAX DEDUCTIONS', 2, 8);

CREATE TABLE payroll_payslips (
    id INT NOT NULL AUTO_INCREMENT,
    employment_id INT NOT NULL,
    pay_date DATE NOT NULL,
    tax_code VARCHAR(20) DEFAULT NULL,
    annual_salary DECIMAL(12,2) DEFAULT NULL,
    tax_year_start SMALLINT GENERATED ALWAYS AS (
        YEAR(DATE_SUB(pay_date, INTERVAL 5 DAY))
        - IF(MONTH(DATE_SUB(pay_date, INTERVAL 5 DAY)) < 4, 1, 0)
    ) STORED,
    tax_month TINYINT GENERATED ALWAYS AS (
        MOD(MONTH(DATE_SUB(pay_date, INTERVAL 5 DAY)) + 8, 12) + 1
    ) STORED,
    legacy_payslip_id INT DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_payslips_legacy_id (legacy_payslip_id),
    KEY idx_payroll_payslips_employment_date (employment_id, pay_date, id),
    KEY idx_payroll_payslips_tax_period (employment_id, tax_year_start, tax_month),
    CONSTRAINT fk_payroll_payslips_employment
        FOREIGN KEY (employment_id) REFERENCES payroll_employments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_line_items (
    id BIGINT NOT NULL AUTO_INCREMENT,
    payslip_id INT NOT NULL,
    code VARCHAR(50) NOT NULL,
    description VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    category_id TINYINT NOT NULL,
    legacy_line_item_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_line_items_legacy_id (legacy_line_item_id),
    KEY idx_payroll_line_items_payslip (payslip_id),
    KEY idx_payroll_line_items_category (category_id),
    CONSTRAINT fk_payroll_line_items_payslip
        FOREIGN KEY (payslip_id) REFERENCES payroll_payslips (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_payroll_line_items_category
        FOREIGN KEY (category_id) REFERENCES payroll_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_expense_categories (
    id SMALLINT NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_expense_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_expense_payment_methods (
    id TINYINT NOT NULL AUTO_INCREMENT,
    name VARCHAR(45) NOT NULL,
    funding_type ENUM('corporate','personal','unknown') NOT NULL DEFAULT 'unknown',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_expense_payment_methods_name (name),
    KEY idx_payroll_expense_payment_methods_funding (funding_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_expense_reports (
    id INT NOT NULL AUTO_INCREMENT,
    employment_id INT DEFAULT NULL,
    report_reference VARCHAR(45) NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_expense_reports_reference (report_reference),
    KEY idx_payroll_expense_reports_employment (employment_id),
    CONSTRAINT fk_payroll_expense_reports_employment
        FOREIGN KEY (employment_id) REFERENCES payroll_employments (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payroll_expenses (
    id BIGINT NOT NULL AUTO_INCREMENT,
    report_id INT NOT NULL,
    expense_date DATE NOT NULL,
    expense_category_id SMALLINT NOT NULL,
    gbp_amount DECIMAL(12,2) NOT NULL,
    original_currency CHAR(3) DEFAULT NULL,
    original_amount DECIMAL(12,2) DEFAULT NULL,
    merchant VARCHAR(200) DEFAULT NULL,
    country_code CHAR(2) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    payment_method_id TINYINT DEFAULT NULL,
    tax_year_start SMALLINT GENERATED ALWAYS AS (
        YEAR(DATE_SUB(expense_date, INTERVAL 5 DAY))
        - IF(MONTH(DATE_SUB(expense_date, INTERVAL 5 DAY)) < 4, 1, 0)
    ) STORED,
    tax_month TINYINT GENERATED ALWAYS AS (
        MOD(MONTH(DATE_SUB(expense_date, INTERVAL 5 DAY)) + 8, 12) + 1
    ) STORED,
    legacy_expense_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_expenses_legacy_id (legacy_expense_id),
    KEY idx_payroll_expenses_report_date (report_id, expense_date),
    KEY idx_payroll_expenses_tax_period (tax_year_start, tax_month),
    KEY idx_payroll_expenses_category (expense_category_id),
    KEY idx_payroll_expenses_payment_method (payment_method_id),
    CONSTRAINT fk_payroll_expenses_report
        FOREIGN KEY (report_id) REFERENCES payroll_expense_reports (id),
    CONSTRAINT fk_payroll_expenses_category
        FOREIGN KEY (expense_category_id) REFERENCES payroll_expense_categories (id),
    CONSTRAINT fk_payroll_expenses_payment_method
        FOREIGN KEY (payment_method_id) REFERENCES payroll_expense_payment_methods (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE VIEW payroll_payslip_summary AS
SELECT
    p.id AS payslip_id,
    p.employment_id,
    e.person_id,
    person.full_name AS person_name,
    p.pay_date,
    DATE_FORMAT(p.pay_date, '%Y-%m-01') AS month_start,
    p.tax_year_start,
    CONCAT(p.tax_year_start, '/', RIGHT(p.tax_year_start + 1, 2)) AS tax_year,
    p.tax_month,
    p.tax_code,
    p.annual_salary,
    SUM(CASE WHEN c.name = 'BASIC PAY' THEN li.amount ELSE 0 END) AS basic_pay,
    SUM(CASE WHEN c.name = 'BENEFITS' THEN li.amount ELSE 0 END) AS benefits,
    SUM(CASE WHEN c.name = 'PRE-TAX DEDUCTIONS' THEN li.amount ELSE 0 END) AS pre_tax_deductions,
    SUM(CASE WHEN c.name = 'ADDITIONAL EARNINGS' THEN li.amount ELSE 0 END) AS additional_earnings,
    SUM(CASE WHEN c.name = 'BONUS' THEN li.amount ELSE 0 END) AS bonus,
    SUM(CASE WHEN c.name = 'PENSION' THEN li.amount ELSE 0 END) AS pension,
    SUM(CASE WHEN c.name = 'TAXES' THEN li.amount ELSE 0 END) AS taxes,
    SUM(CASE WHEN c.name = 'POST-TAX DEDUCTIONS' THEN li.amount ELSE 0 END) AS post_tax_deductions,
    SUM(CASE WHEN lt.name = 'Pay' THEN li.amount ELSE 0 END) AS total_gross,
    SUM(CASE WHEN lt.name = 'Deduction' THEN li.amount ELSE 0 END) AS total_deductions,
    SUM(CASE WHEN lt.name = 'Pay' THEN li.amount ELSE 0 END)
        - SUM(CASE WHEN lt.name = 'Deduction' THEN li.amount ELSE 0 END) AS net_pay,
    ROUND(
        CASE
            WHEN SUM(CASE WHEN lt.name = 'Pay' THEN li.amount ELSE 0 END) = 0 THEN 0
            ELSE SUM(CASE WHEN c.name = 'TAXES' THEN li.amount ELSE 0 END)
                / SUM(CASE WHEN lt.name = 'Pay' THEN li.amount ELSE 0 END) * 100
        END,
        2
    ) AS tax_percentage,
    COUNT(li.id) AS line_item_count
FROM payroll_payslips p
JOIN payroll_employments e ON e.id = p.employment_id
JOIN payroll_people person ON person.id = e.person_id
LEFT JOIN payroll_line_items li ON li.payslip_id = p.id
LEFT JOIN payroll_categories c ON c.id = li.category_id
LEFT JOIN payroll_line_types lt ON lt.id = c.line_type_id
GROUP BY
    p.id,
    p.employment_id,
    e.person_id,
    person.full_name,
    p.pay_date,
    p.tax_year_start,
    p.tax_month,
    p.tax_code,
    p.annual_salary;

CREATE VIEW payroll_monthly_summary AS
SELECT
    ps.employment_id,
    ps.person_id,
    ps.person_name,
    ps.month_start,
    SUM(ps.basic_pay) AS basic_pay,
    SUM(ps.benefits) AS benefits,
    SUM(ps.pre_tax_deductions) AS pre_tax_deductions,
    SUM(ps.additional_earnings) AS additional_earnings,
    SUM(ps.bonus) AS bonus,
    SUM(ps.pension) AS pension,
    SUM(ps.taxes) AS taxes,
    SUM(ps.post_tax_deductions) AS post_tax_deductions,
    SUM(ps.total_gross) AS total_gross,
    SUM(ps.total_deductions) AS total_deductions,
    SUM(ps.net_pay) AS net_pay,
    ROUND(
        CASE WHEN SUM(ps.total_gross) = 0 THEN 0
             ELSE SUM(ps.taxes) / SUM(ps.total_gross) * 100
        END,
        2
    ) AS tax_percentage,
    COALESCE(MAX(exp.corporate_expenses), 0) AS corporate_expenses,
    COALESCE(MAX(exp.personal_expenses), 0) AS personal_expenses,
    COUNT(*) AS payslip_count
FROM payroll_payslip_summary ps
LEFT JOIN (
    SELECT
        r.employment_id,
        DATE_FORMAT(x.expense_date, '%Y-%m-01') AS month_start,
        SUM(CASE WHEN pm.funding_type = 'corporate' THEN x.gbp_amount ELSE 0 END) AS corporate_expenses,
        SUM(CASE WHEN pm.funding_type = 'personal' THEN x.gbp_amount ELSE 0 END) AS personal_expenses
    FROM payroll_expenses x
    JOIN payroll_expense_reports r ON r.id = x.report_id
    LEFT JOIN payroll_expense_payment_methods pm ON pm.id = x.payment_method_id
    WHERE r.employment_id IS NOT NULL
    GROUP BY r.employment_id, DATE_FORMAT(x.expense_date, '%Y-%m-01')
) exp
    ON exp.employment_id = ps.employment_id
   AND exp.month_start = ps.month_start
GROUP BY ps.employment_id, ps.person_id, ps.person_name, ps.month_start;

CREATE VIEW payroll_tax_month_summary AS
SELECT
    ps.employment_id,
    ps.person_id,
    ps.person_name,
    ps.tax_year_start,
    CONCAT(ps.tax_year_start, '/', RIGHT(ps.tax_year_start + 1, 2)) AS tax_year,
    ps.tax_month,
    SUM(ps.basic_pay) AS basic_pay,
    SUM(ps.benefits) AS benefits,
    SUM(ps.pre_tax_deductions) AS pre_tax_deductions,
    SUM(ps.additional_earnings) AS additional_earnings,
    SUM(ps.bonus) AS bonus,
    SUM(ps.pension) AS pension,
    SUM(ps.taxes) AS taxes,
    SUM(ps.post_tax_deductions) AS post_tax_deductions,
    SUM(ps.total_gross) AS total_gross,
    SUM(ps.total_deductions) AS total_deductions,
    SUM(ps.net_pay) AS net_pay,
    ROUND(
        CASE WHEN SUM(ps.total_gross) = 0 THEN 0
             ELSE SUM(ps.taxes) / SUM(ps.total_gross) * 100
        END,
        2
    ) AS tax_percentage,
    COALESCE(MAX(exp.corporate_expenses), 0) AS corporate_expenses,
    COALESCE(MAX(exp.personal_expenses), 0) AS personal_expenses,
    COUNT(*) AS payslip_count
FROM payroll_payslip_summary ps
LEFT JOIN (
    SELECT
        r.employment_id,
        x.tax_year_start,
        x.tax_month,
        SUM(CASE WHEN pm.funding_type = 'corporate' THEN x.gbp_amount ELSE 0 END) AS corporate_expenses,
        SUM(CASE WHEN pm.funding_type = 'personal' THEN x.gbp_amount ELSE 0 END) AS personal_expenses
    FROM payroll_expenses x
    JOIN payroll_expense_reports r ON r.id = x.report_id
    LEFT JOIN payroll_expense_payment_methods pm ON pm.id = x.payment_method_id
    WHERE r.employment_id IS NOT NULL
    GROUP BY r.employment_id, x.tax_year_start, x.tax_month
) exp
    ON exp.employment_id = ps.employment_id
   AND exp.tax_year_start = ps.tax_year_start
   AND exp.tax_month = ps.tax_month
GROUP BY ps.employment_id, ps.person_id, ps.person_name, ps.tax_year_start, ps.tax_month;

CREATE VIEW payroll_tax_year_summary AS
SELECT
    tm.employment_id,
    tm.person_id,
    tm.person_name,
    tm.tax_year_start,
    tm.tax_year,
    SUM(tm.basic_pay) AS basic_pay,
    SUM(tm.benefits) AS benefits,
    SUM(tm.pre_tax_deductions) AS pre_tax_deductions,
    SUM(tm.additional_earnings) AS additional_earnings,
    SUM(tm.bonus) AS bonus,
    SUM(tm.pension) AS pension,
    SUM(tm.taxes) AS taxes,
    SUM(tm.post_tax_deductions) AS post_tax_deductions,
    SUM(tm.total_gross) AS total_gross,
    SUM(tm.total_deductions) AS total_deductions,
    SUM(tm.net_pay) AS net_pay,
    ROUND(
        CASE WHEN SUM(tm.total_gross) = 0 THEN 0
             ELSE SUM(tm.taxes) / SUM(tm.total_gross) * 100
        END,
        2
    ) AS effective_tax_rate,
    SUM(tm.corporate_expenses) AS corporate_expenses,
    SUM(tm.personal_expenses) AS personal_expenses,
    SUM(tm.payslip_count) AS payslip_count,
    COUNT(*) AS tax_months_with_payslips
FROM payroll_tax_month_summary tm
GROUP BY tm.employment_id, tm.person_id, tm.person_name, tm.tax_year_start, tm.tax_year;

CREATE VIEW payroll_bonus_overview AS
SELECT
    tm.employment_id,
    tm.person_id,
    tm.person_name,
    tm.tax_year_start,
    tm.tax_year,
    tm.tax_month,
    tm.bonus AS bonus_amount,
    ROUND(
        CASE WHEN tm.basic_pay = 0 THEN 0
             ELSE tm.bonus / (tm.basic_pay * 12) * 100
        END,
        2
    ) AS bonus_pct_of_annualised_basic
FROM payroll_tax_month_summary tm
WHERE tm.bonus > 0;

CREATE VIEW payroll_ytd_summary AS
SELECT
    tm.employment_id,
    tm.person_id,
    tm.person_name,
    tm.tax_year_start,
    tm.tax_year,
    COUNT(*) AS months_processed,
    SUM(tm.basic_pay) AS ytd_basic_pay,
    SUM(tm.benefits) AS ytd_benefits,
    SUM(tm.pre_tax_deductions) AS ytd_pre_tax_deductions,
    SUM(tm.additional_earnings) AS ytd_additional_earnings,
    SUM(tm.bonus) AS ytd_bonus,
    SUM(tm.pension) AS ytd_pension,
    SUM(tm.taxes) AS ytd_taxes,
    SUM(tm.post_tax_deductions) AS ytd_post_tax_deductions,
    SUM(tm.total_gross) AS ytd_gross,
    SUM(tm.total_deductions) AS ytd_total_deductions,
    SUM(tm.net_pay) AS ytd_net_pay,
    ROUND(
        CASE WHEN SUM(tm.total_gross) = 0 THEN 0
             ELSE SUM(tm.taxes) / SUM(tm.total_gross) * 100
        END,
        2
    ) AS effective_tax_rate,
    SUM(tm.corporate_expenses) AS ytd_corporate_expenses,
    SUM(tm.personal_expenses) AS ytd_personal_expenses
FROM payroll_tax_month_summary tm
WHERE tm.tax_year_start = (
    YEAR(DATE_SUB(CURDATE(), INTERVAL 5 DAY))
    - IF(MONTH(DATE_SUB(CURDATE(), INTERVAL 5 DAY)) < 4, 1, 0)
)
  AND tm.tax_month <= (MOD(MONTH(DATE_SUB(CURDATE(), INTERVAL 5 DAY)) + 8, 12) + 1)
GROUP BY tm.employment_id, tm.person_id, tm.person_name, tm.tax_year_start, tm.tax_year;

CREATE VIEW payroll_previous_ytd_summary AS
WITH current_progress AS (
    SELECT
        tm.employment_id,
        MAX(tm.tax_month) AS max_tax_month
    FROM payroll_tax_month_summary tm
    WHERE tm.tax_year_start = (
        YEAR(DATE_SUB(CURDATE(), INTERVAL 5 DAY))
        - IF(MONTH(DATE_SUB(CURDATE(), INTERVAL 5 DAY)) < 4, 1, 0)
    )
      AND tm.tax_month <= (MOD(MONTH(DATE_SUB(CURDATE(), INTERVAL 5 DAY)) + 8, 12) + 1)
    GROUP BY tm.employment_id
)
SELECT
    tm.employment_id,
    tm.person_id,
    tm.person_name,
    tm.tax_year_start,
    tm.tax_year,
    COUNT(*) AS months_processed,
    SUM(tm.basic_pay) AS ytd_basic_pay,
    SUM(tm.benefits) AS ytd_benefits,
    SUM(tm.pre_tax_deductions) AS ytd_pre_tax_deductions,
    SUM(tm.additional_earnings) AS ytd_additional_earnings,
    SUM(tm.bonus) AS ytd_bonus,
    SUM(tm.pension) AS ytd_pension,
    SUM(tm.taxes) AS ytd_taxes,
    SUM(tm.post_tax_deductions) AS ytd_post_tax_deductions,
    SUM(tm.total_gross) AS ytd_gross,
    SUM(tm.total_deductions) AS ytd_total_deductions,
    SUM(tm.net_pay) AS ytd_net_pay,
    ROUND(
        CASE WHEN SUM(tm.total_gross) = 0 THEN 0
             ELSE SUM(tm.taxes) / SUM(tm.total_gross) * 100
        END,
        2
    ) AS effective_tax_rate,
    SUM(tm.corporate_expenses) AS ytd_corporate_expenses,
    SUM(tm.personal_expenses) AS ytd_personal_expenses
FROM payroll_tax_month_summary tm
JOIN current_progress cp
    ON cp.employment_id = tm.employment_id
   AND tm.tax_month <= cp.max_tax_month
WHERE tm.tax_year_start = (
    YEAR(DATE_SUB(CURDATE(), INTERVAL 5 DAY))
    - IF(MONTH(DATE_SUB(CURDATE(), INTERVAL 5 DAY)) < 4, 1, 0)
    - 1
)
GROUP BY tm.employment_id, tm.person_id, tm.person_name, tm.tax_year_start, tm.tax_year;

CREATE VIEW payroll_ytd_comparison AS
SELECT
    curr.employment_id,
    curr.person_id,
    curr.person_name,
    curr.tax_year AS current_tax_year,
    prev.tax_year AS previous_tax_year,
    curr.months_processed AS current_months,
    prev.months_processed AS previous_months,
    curr.ytd_gross AS current_ytd_gross,
    prev.ytd_gross AS previous_ytd_gross,
    curr.ytd_net_pay AS current_ytd_net_pay,
    prev.ytd_net_pay AS previous_ytd_net_pay,
    curr.ytd_bonus AS current_ytd_bonus,
    prev.ytd_bonus AS previous_ytd_bonus,
    curr.effective_tax_rate AS current_effective_tax_rate,
    prev.effective_tax_rate AS previous_effective_tax_rate,
    curr.ytd_basic_pay AS current_ytd_basic_pay,
    prev.ytd_basic_pay AS previous_ytd_basic_pay,
    curr.ytd_pension AS current_ytd_pension,
    prev.ytd_pension AS previous_ytd_pension
FROM payroll_ytd_summary curr
JOIN payroll_previous_ytd_summary prev
    ON prev.employment_id = curr.employment_id;

CREATE VIEW payroll_salary_changes AS
WITH ordered_payslips AS (
    SELECT
        p.id,
        p.employment_id,
        p.pay_date,
        p.annual_salary,
        LAG(p.annual_salary) OVER (
            PARTITION BY p.employment_id
            ORDER BY p.pay_date, p.id
        ) AS previous_annual_salary
    FROM payroll_payslips p
)
SELECT
    op.employment_id,
    e.person_id,
    person.full_name AS person_name,
    op.pay_date AS change_date,
    op.previous_annual_salary,
    op.annual_salary AS new_annual_salary,
    op.annual_salary - op.previous_annual_salary AS value_change,
    ROUND(
        CASE WHEN op.previous_annual_salary > 0
             THEN (op.annual_salary - op.previous_annual_salary) / op.previous_annual_salary * 100
             ELSE NULL
        END,
        2
    ) AS percent_change
FROM ordered_payslips op
JOIN payroll_employments e ON e.id = op.employment_id
JOIN payroll_people person ON person.id = e.person_id
WHERE op.previous_annual_salary IS NOT NULL
  AND op.annual_salary <> op.previous_annual_salary;

CREATE TABLE planned_income_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    category_id INT NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    window_start DATE NOT NULL,
    window_end DATE NOT NULL,
    timing_strategy ENUM('earliest','midpoint','latest','manual') NOT NULL DEFAULT 'latest',
    manual_date DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_planned_income_events_active_window (active, window_start, window_end),
    KEY idx_planned_income_events_account_window (account_id, window_start, window_end),
    KEY idx_planned_income_events_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

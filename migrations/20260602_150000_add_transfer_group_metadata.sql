-- BKL-056B
-- Add first-class transfer metadata to transfer_groups.
--
-- This is an additive model change. It does not remove transfer categories yet,
-- and it does not alter transactions.transfer_group_id, amount, date,
-- description, category_id, account_id, or type.
--
-- New model direction:
--   - transactions remain account-side ledger rows
--   - transfer_groups describe the transfer relationship
--   - later changes can render transfer labels dynamically from this metadata

ALTER TABLE transfer_groups
  ADD COLUMN from_account_id INT DEFAULT NULL AFTER description,
  ADD COLUMN to_account_id INT DEFAULT NULL AFTER from_account_id,
  ADD COLUMN expected_amount DECIMAL(10,2) DEFAULT NULL AFTER to_account_id,
  ADD COLUMN transfer_date DATE DEFAULT NULL AFTER expected_amount,
  ADD COLUMN transfer_status ENUM('complete','partial','needs_review') NOT NULL DEFAULT 'needs_review' AFTER transfer_date,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_transfer_groups_from_account (from_account_id),
  ADD KEY idx_transfer_groups_to_account (to_account_id),
  ADD KEY idx_transfer_groups_status_date (transfer_status, transfer_date),
  ADD CONSTRAINT fk_transfer_groups_from_account
    FOREIGN KEY (from_account_id) REFERENCES accounts(id),
  ADD CONSTRAINT fk_transfer_groups_to_account
    FOREIGN KEY (to_account_id) REFERENCES accounts(id);

UPDATE transfer_groups tg
JOIN (
    SELECT
        t.transfer_group_id,

        COUNT(*) AS row_count,
        ROUND(SUM(t.amount), 2) AS total_amount,

        SUM(CASE WHEN t.amount < 0 THEN 1 ELSE 0 END) AS negative_count,
        SUM(CASE WHEN t.amount > 0 THEN 1 ELSE 0 END) AS positive_count,
        SUM(CASE WHEN t.description = 'PLACEHOLDER' THEN 1 ELSE 0 END) AS placeholder_count,

        MAX(CASE WHEN t.amount < 0 THEN t.account_id ELSE NULL END) AS negative_account_id,
        MAX(CASE WHEN t.amount > 0 THEN t.account_id ELSE NULL END) AS positive_account_id,

        MAX(CASE WHEN t.amount < 0 THEN ABS(t.amount) ELSE NULL END) AS negative_abs_amount,
        MAX(CASE WHEN t.amount > 0 THEN ABS(t.amount) ELSE NULL END) AS positive_abs_amount,

        MAX(CASE WHEN t.amount < 0 THEN t.date ELSE NULL END) AS negative_date,
        MIN(t.date) AS min_date
    FROM transactions t
    WHERE t.transfer_group_id IS NOT NULL
    GROUP BY t.transfer_group_id
) x ON x.transfer_group_id = tg.id
SET
    tg.from_account_id = CASE
        WHEN x.negative_count = 1 THEN x.negative_account_id
        ELSE NULL
    END,
    tg.to_account_id = CASE
        WHEN x.positive_count = 1 THEN x.positive_account_id
        ELSE NULL
    END,
    tg.expected_amount = CASE
        WHEN x.negative_count = 1 AND x.positive_count = 1
             AND ABS(x.negative_abs_amount - x.positive_abs_amount) < 0.01
        THEN x.negative_abs_amount
        ELSE NULL
    END,
    tg.transfer_date = CASE
        WHEN x.negative_count = 1 THEN x.negative_date
        ELSE x.min_date
    END,
    tg.transfer_status = CASE
        WHEN x.row_count = 2
             AND x.negative_count = 1
             AND x.positive_count = 1
             AND ABS(x.total_amount) < 0.01
             AND x.placeholder_count = 0
        THEN 'complete'

        WHEN x.row_count = 2
             AND x.negative_count = 1
             AND x.positive_count = 1
             AND ABS(x.total_amount) < 0.01
             AND x.placeholder_count > 0
        THEN 'partial'

        ELSE 'needs_review'
    END;


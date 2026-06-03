-- BKL-056I
-- Make transfer predictions category-free.
--
-- This removes the remaining structural dependency between transfer predictions
-- and synthetic transfer categories.
--
-- Deliberately narrow:
--   - income/expense predictions keep category_id populated
--   - transfer predictions move to category_id = NULL
--   - prediction_type becomes the source of truth for transfer-vs-income/expense
--   - repayment uniqueness no longer depends on category_id

ALTER TABLE predicted_transactions
  MODIFY category_id INT DEFAULT NULL;

ALTER TABLE predicted_instances
  DROP INDEX unique_repayments,
  MODIFY category_id INT DEFAULT NULL,
  ADD UNIQUE KEY unique_transfer_predictions
    (scheduled_date, from_account_id, to_account_id, prediction_type);

UPDATE predicted_transactions
SET category_id = NULL
WHERE prediction_type = 'transfer';

UPDATE predicted_instances
SET category_id = NULL
WHERE prediction_type = 'transfer';

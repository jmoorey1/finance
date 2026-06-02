-- BKL-056G
-- Add first-class prediction_type to predicted_transactions and predicted_instances.
--
-- Compatibility step:
--   - category_id remains populated and NOT NULL for now
--   - prediction_type is backfilled from current categories.type
--   - application logic can now prefer prediction_type over synthetic transfer categories
--
-- Later BKL-056H/I can change the UI/model so transfer predictions no longer
-- require a transfer category.

ALTER TABLE predicted_transactions
  ADD COLUMN prediction_type ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense' AFTER category_id,
  ADD KEY idx_predicted_transactions_prediction_type (prediction_type);

ALTER TABLE predicted_instances
  ADD COLUMN prediction_type ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense' AFTER category_id,
  ADD KEY idx_predicted_instances_prediction_type_state (prediction_type, fulfilled, resolution_status, scheduled_date);

UPDATE predicted_transactions pt
JOIN categories c ON c.id = pt.category_id
SET pt.prediction_type = c.type
WHERE c.type IN ('income', 'expense', 'transfer');

UPDATE predicted_instances pi
JOIN categories c ON c.id = pi.category_id
SET pi.prediction_type = c.type
WHERE c.type IN ('income', 'expense', 'transfer');

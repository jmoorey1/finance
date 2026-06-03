-- BKL-056M
-- Retire legacy transfer category rows.
--
-- Transfers are now modelled by:
--   - transfer_groups for actual transfer transactions
--   - transactions.type = 'transfer'
--   - predicted_transactions.prediction_type = 'transfer'
--   - predicted_instances.prediction_type = 'transfer'
--
-- Legacy categories.type='transfer' rows should no longer be referenced by
-- transactions, transaction_splits, staging_transactions, budgets,
-- predicted_transactions, predicted_instances, or planned_income_events.
--
-- Preconditions are enforced by:
--   - scripts/admin/validate_transfer_model_guardrails.php
--   - scripts/admin/audit_bkl_056k_transfer_category_references.php --strict
--   - FK constraints on category_id references
--
-- The migration itself only deletes rows where categories.type = 'transfer'.
-- Child transfer categories are deleted before the parent "Transfers" category
-- because categories.parent_id is self-referencing.

DELETE child
FROM categories child
JOIN categories parent ON parent.id = child.parent_id
WHERE child.type = 'transfer'
  AND parent.type = 'transfer';

DELETE FROM categories
WHERE type = 'transfer'
  AND parent_id IS NULL;

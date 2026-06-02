-- BKL-056F
-- Backfill historic transfer transaction category_id values to NULL.
--
-- This completes the data side of the transfer-category decoupling for
-- transaction rows that are already structurally represented by transfer_groups.
--
-- Deliberately narrow:
--   - only transactions.category_id is updated
--   - only grouped transaction rows are touched
--   - only rows whose current category is type='transfer' are touched
--   - no amounts, dates, descriptions, accounts, types, groups, payees,
--     statements, predictions or split rows are changed
--
-- After BKL-056D, ledger_lines renders transfer category labels dynamically from
-- transfer_groups metadata, so historic transfer rows no longer need synthetic
-- transfer category IDs.

UPDATE transactions t
JOIN categories c ON c.id = t.category_id
SET t.category_id = NULL
WHERE t.transfer_group_id IS NOT NULL
  AND c.type = 'transfer';

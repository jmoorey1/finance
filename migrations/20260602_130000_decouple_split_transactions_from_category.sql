-- BKL-055
-- Decouple split transactions from the legacy "Split/Multiple Categories"
-- category marker.
--
-- New model:
--   - A transaction is split if it has rows in transaction_splits.
--   - Split parent transactions store category_id = NULL.
--   - The real categorisation lives on transaction_splits.category_id.
--
-- This makes splitness a structural fact instead of an accidental category ID
-- inherited from the original MS Money import.

UPDATE transactions t
SET t.category_id = NULL
WHERE t.category_id IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM transaction_splits ts
      WHERE ts.transaction_id = t.id
  );

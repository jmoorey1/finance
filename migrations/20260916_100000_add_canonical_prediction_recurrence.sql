-- Canonical recurrence foundation for predicted transaction rules.
--
-- Goals:
--   - recurrence phase is anchored to rule data, never to the latest actual transaction
--   - one representation covers weekly, fortnightly, four-weekly, monthly, quarterly and annual rules
--   - preserve historical fulfilled/skipped/resolved instances
--   - discard only future open rule-derived instances so they can be regenerated canonically

ALTER TABLE predicted_transactions
  ADD COLUMN recurrence_unit ENUM('week','month','year') NULL AFTER active,
  ADD COLUMN recurrence_interval INT NULL AFTER recurrence_unit,
  ADD COLUMN anchor_date DATE NULL AFTER recurrence_interval,
  ADD COLUMN schedule_pattern ENUM('anchor_date','day_of_month','nth_weekday','month_end') NULL AFTER anchor_date,
  ADD COLUMN business_day_adjustment ENUM('none','previous_business_day','next_business_day') NOT NULL DEFAULT 'none' AFTER nth_weekday;

UPDATE predicted_transactions pt
LEFT JOIN (
    SELECT
        predicted_transaction_id,
        MIN(scheduled_date) AS first_scheduled_date
    FROM predicted_instances
    WHERE predicted_transaction_id IS NOT NULL
    GROUP BY predicted_transaction_id
) pi
  ON pi.predicted_transaction_id = pt.id
SET
    pt.recurrence_unit = CASE
        WHEN pt.frequency IN ('weekly', 'fortnightly', 'custom') THEN 'week'
        ELSE 'month'
    END,
    pt.recurrence_interval = CASE
        WHEN pt.frequency = 'weekly' THEN 1
        WHEN pt.frequency = 'fortnightly' THEN 2
        WHEN pt.frequency = 'custom' THEN GREATEST(1, COALESCE(pt.repeat_interval, 1))
        ELSE GREATEST(1, COALESCE(pt.repeat_interval, 1))
    END,
    pt.anchor_date = COALESCE(
        pi.first_scheduled_date,
        DATE(pt.created_at),
        CURDATE()
    ),
    pt.schedule_pattern = CASE
        WHEN pt.frequency IN ('weekly', 'fortnightly', 'custom') THEN 'anchor_date'
        WHEN pt.anchor_type = 'day_of_month' THEN 'day_of_month'
        WHEN pt.anchor_type = 'nth_weekday' THEN 'nth_weekday'
        WHEN pt.anchor_type = 'last_business_day' THEN 'month_end'
        ELSE 'anchor_date'
    END,
    pt.business_day_adjustment = CASE
        WHEN pt.frequency = 'monthly'
         AND pt.anchor_type = 'last_business_day'
         AND COALESCE(pt.is_business_day, 0) = 1
            THEN 'previous_business_day'
        ELSE COALESCE(pt.adjust_for_weekend, 'none')
    END;

-- Production phase anchors confirmed during recurrence defect discovery.
UPDATE predicted_transactions
SET anchor_date = '2026-06-16', recurrence_unit = 'week', recurrence_interval = 2, schedule_pattern = 'anchor_date'
WHERE id = 3
  AND UPPER(TRIM(description)) = 'JENNIFER FEGAN';

UPDATE predicted_transactions
SET anchor_date = '2026-04-21', recurrence_unit = 'week', recurrence_interval = 4, schedule_pattern = 'anchor_date'
WHERE id = 6
  AND UPPER(TRIM(description)) = 'LISA FERRIER - LOTTERY';

UPDATE predicted_transactions
SET anchor_date = '2026-06-28', recurrence_unit = 'week', recurrence_interval = 4, schedule_pattern = 'anchor_date'
WHERE id = 50
  AND UPPER(TRIM(description)) = 'CRANFIELD SF CONNECT';

UPDATE predicted_transactions
SET
    day_of_month = CASE
        WHEN recurrence_unit = 'month' AND schedule_pattern = 'day_of_month' THEN day_of_month
        ELSE NULL
    END,
    weekday = CASE
        WHEN recurrence_unit = 'month' AND schedule_pattern = 'nth_weekday' THEN weekday
        ELSE NULL
    END,
    nth_weekday = CASE
        WHEN recurrence_unit = 'month' AND schedule_pattern = 'nth_weekday' THEN nth_weekday
        ELSE NULL
    END;

ALTER TABLE predicted_transactions
  MODIFY recurrence_unit ENUM('week','month','year') NOT NULL,
  MODIFY recurrence_interval INT NOT NULL DEFAULT 1,
  MODIFY anchor_date DATE NOT NULL,
  MODIFY schedule_pattern ENUM('anchor_date','day_of_month','nth_weekday','month_end') NOT NULL DEFAULT 'anchor_date',
  ADD CONSTRAINT chk_predicted_transactions_recurrence_interval CHECK (recurrence_interval >= 1),
  ADD CONSTRAINT chk_predicted_transactions_day_of_month CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 31),
  ADD CONSTRAINT chk_predicted_transactions_weekday CHECK (weekday IS NULL OR weekday BETWEEN 0 AND 6),
  ADD CONSTRAINT chk_predicted_transactions_nth_weekday CHECK (nth_weekday IS NULL OR nth_weekday BETWEEN 1 AND 5),
  ADD KEY idx_predicted_transactions_recurrence (active, recurrence_unit, anchor_date);

-- Only derived future/open rule instances are rebuilt. Historical fulfilled,
-- partial, confirmed, skipped or otherwise resolved rows remain untouched.
DELETE FROM predicted_instances
WHERE predicted_transaction_id IS NOT NULL
  AND scheduled_date >= CURDATE()
  AND COALESCE(fulfilled, 0) = 0
  AND confirmed = 0
  AND COALESCE(resolution_status, 'open') = 'open';

ALTER TABLE predicted_transactions
  DROP COLUMN frequency,
  DROP COLUMN repeat_interval,
  DROP COLUMN anchor_type,
  DROP COLUMN adjust_for_weekend,
  DROP COLUMN is_business_day;

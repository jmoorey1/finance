-- BKL-052
-- Fix forecast_timeline_view so future predictions only affect cash forecasts when
-- they are still open and unfulfilled.
--
-- Also make prediction handling category-aware so the view matches the newer
-- cash_planner.php event semantics:
--   - income / expense predictions use the stored signed amount on from_account_id
--   - transfer predictions create an outgoing event on from_account_id
--   - transfer predictions create an incoming event on to_account_id
--
-- NULL prediction amounts are skipped because they cannot produce a reliable
-- running-balance event.

SET @saved_cs_client = @@character_set_client;
SET @saved_cs_results = @@character_set_results;
SET @saved_col_connection = @@collation_connection;

SET character_set_client = utf8mb4;
SET character_set_results = utf8mb4;
SET collation_connection = utf8mb4_0900_ai_ci;

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `forecast_timeline_view` AS
SELECT
    `events`.`account_id` AS `account_id`,
    `events`.`event_date` AS `date`,
    ROUND(
        `a`.`starting_balance`
        + SUM(`events`.`amount`) OVER (
            PARTITION BY `events`.`account_id`
            ORDER BY
                `events`.`event_date`,
                `events`.`sort_order`,
                `events`.`source_id`
            ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
        ),
        2
    ) AS `running_balance`
FROM (
    SELECT
        `t`.`account_id` AS `account_id`,
        `t`.`date` AS `event_date`,
        `t`.`amount` AS `amount`,
        0 AS `sort_order`,
        `t`.`id` AS `source_id`
    FROM `transactions` `t`
    WHERE `t`.`date` <= CURDATE()

    UNION ALL

    SELECT
        `pi`.`from_account_id` AS `account_id`,
        `pi`.`scheduled_date` AS `event_date`,
        `pi`.`amount` AS `amount`,
        1 AS `sort_order`,
        `pi`.`id` AS `source_id`
    FROM `predicted_instances` `pi`
    JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
    WHERE `pi`.`scheduled_date` > CURDATE()
      AND `pi`.`amount` IS NOT NULL
      AND `c`.`type` IN ('income', 'expense')
      AND COALESCE(`pi`.`fulfilled`, 0) = 0
      AND COALESCE(`pi`.`resolution_status`, 'open') = 'open'

    UNION ALL

    SELECT
        `pi`.`from_account_id` AS `account_id`,
        `pi`.`scheduled_date` AS `event_date`,
        -ABS(`pi`.`amount`) AS `amount`,
        2 AS `sort_order`,
        `pi`.`id` AS `source_id`
    FROM `predicted_instances` `pi`
    JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
    WHERE `pi`.`scheduled_date` > CURDATE()
      AND `pi`.`amount` IS NOT NULL
      AND `pi`.`from_account_id` IS NOT NULL
      AND `c`.`type` = 'transfer'
      AND COALESCE(`pi`.`fulfilled`, 0) = 0
      AND COALESCE(`pi`.`resolution_status`, 'open') = 'open'

    UNION ALL

    SELECT
        `pi`.`to_account_id` AS `account_id`,
        `pi`.`scheduled_date` AS `event_date`,
        ABS(`pi`.`amount`) AS `amount`,
        3 AS `sort_order`,
        `pi`.`id` AS `source_id`
    FROM `predicted_instances` `pi`
    JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
    WHERE `pi`.`scheduled_date` > CURDATE()
      AND `pi`.`amount` IS NOT NULL
      AND `pi`.`to_account_id` IS NOT NULL
      AND `c`.`type` = 'transfer'
      AND COALESCE(`pi`.`fulfilled`, 0) = 0
      AND COALESCE(`pi`.`resolution_status`, 'open') = 'open'
) `events`
JOIN `accounts` `a` ON `a`.`id` = `events`.`account_id`
WHERE `a`.`active` = 1
  AND `a`.`type` = 'current';

SET character_set_client = @saved_cs_client;
SET character_set_results = @saved_cs_results;
SET collation_connection = @saved_col_connection;

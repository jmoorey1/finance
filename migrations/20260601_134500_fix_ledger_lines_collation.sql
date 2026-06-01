-- Recreate ledger_lines so literal columns use the database collation.
-- The live view was created with collation_connection=utf8mb4_general_ci, which
-- made source and line_role conflict with utf8mb4_0900_ai_ci table columns in
-- ledger/category report filters.

SET @saved_cs_client = @@character_set_client;
SET @saved_cs_results = @@character_set_results;
SET @saved_col_connection = @@collation_connection;
SET character_set_client = utf8mb4;
SET character_set_results = utf8mb4;
SET collation_connection = utf8mb4_0900_ai_ci;

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `ledger_lines` AS
SELECT
    _utf8mb4'Actual' COLLATE utf8mb4_0900_ai_ci AS `source`,
    _utf8mb4'actual' COLLATE utf8mb4_0900_ai_ci AS `line_role`,
    `t`.`id` AS `transaction_id`,
    NULL AS `transaction_split_id`,
    NULL AS `predicted_instance_id`,
    `t`.`date` AS `line_date`,
    `t`.`account_id` AS `account_id`,
    `a`.`name` AS `account_name`,
    NULL AS `other_account_id`,
    NULL AS `other_account_name`,
    `t`.`amount` AS `amount`,
    COALESCE(`p`.`name`, `t`.`description`) AS `description`,
    `t`.`description` AS `raw_description`,
    `t`.`original_ref` AS `original_ref`,
    `t`.`type` AS `transaction_type`,
    `t`.`transfer_group_id` AS `transfer_group_id`,
    `t`.`project_id` AS `project_id`,
    `t`.`earmark_id` AS `earmark_id`,
    `t`.`category_id` AS `category_id`,
    `c`.`name` AS `category_name`,
    `c`.`type` AS `category_type`,
    `c`.`parent_id` AS `parent_category_id`,
    `pc`.`name` AS `parent_category_name`,
    CASE WHEN `c`.`parent_id` IS NULL THEN 0 ELSE 1 END AS `sub_flag`,
    0 AS `is_prediction`,
    1 AS `is_editable`
FROM `transactions` `t`
JOIN `accounts` `a` ON `a`.`id` = `t`.`account_id`
JOIN `categories` `c` ON `c`.`id` = `t`.`category_id`
LEFT JOIN `categories` `pc` ON `pc`.`id` = `c`.`parent_id`
LEFT JOIN `payees` `p` ON `p`.`id` = `t`.`payee_id`
LEFT JOIN `transaction_splits` `ts` ON `ts`.`transaction_id` = `t`.`id`
WHERE `ts`.`transaction_id` IS NULL

UNION ALL

SELECT
    _utf8mb4'Split' COLLATE utf8mb4_0900_ai_ci AS `source`,
    _utf8mb4'split' COLLATE utf8mb4_0900_ai_ci AS `line_role`,
    `t`.`id` AS `transaction_id`,
    `ts`.`id` AS `transaction_split_id`,
    NULL AS `predicted_instance_id`,
    `t`.`date` AS `line_date`,
    `t`.`account_id` AS `account_id`,
    `a`.`name` AS `account_name`,
    NULL AS `other_account_id`,
    NULL AS `other_account_name`,
    `ts`.`amount` AS `amount`,
    COALESCE(`p`.`name`, `t`.`description`) AS `description`,
    `t`.`description` AS `raw_description`,
    `t`.`original_ref` AS `original_ref`,
    `t`.`type` AS `transaction_type`,
    `t`.`transfer_group_id` AS `transfer_group_id`,
    COALESCE(`ts`.`project_id`, `t`.`project_id`) AS `project_id`,
    COALESCE(`ts`.`fund_source_id`, `t`.`earmark_id`) AS `earmark_id`,
    `ts`.`category_id` AS `category_id`,
    `c`.`name` AS `category_name`,
    `c`.`type` AS `category_type`,
    `c`.`parent_id` AS `parent_category_id`,
    `pc`.`name` AS `parent_category_name`,
    CASE WHEN `c`.`parent_id` IS NULL THEN 0 ELSE 1 END AS `sub_flag`,
    0 AS `is_prediction`,
    1 AS `is_editable`
FROM `transaction_splits` `ts`
JOIN `transactions` `t` ON `t`.`id` = `ts`.`transaction_id`
JOIN `accounts` `a` ON `a`.`id` = `t`.`account_id`
JOIN `categories` `c` ON `c`.`id` = `ts`.`category_id`
LEFT JOIN `categories` `pc` ON `pc`.`id` = `c`.`parent_id`
LEFT JOIN `payees` `p` ON `p`.`id` = `t`.`payee_id`

UNION ALL

SELECT
    _utf8mb4'Predicted' COLLATE utf8mb4_0900_ai_ci AS `source`,
    _utf8mb4'predicted' COLLATE utf8mb4_0900_ai_ci AS `line_role`,
    NULL AS `transaction_id`,
    NULL AS `transaction_split_id`,
    `pi`.`id` AS `predicted_instance_id`,
    `pi`.`scheduled_date` AS `line_date`,
    `pi`.`from_account_id` AS `account_id`,
    `fa`.`name` AS `account_name`,
    `pi`.`to_account_id` AS `other_account_id`,
    `ta`.`name` AS `other_account_name`,
    `pi`.`amount` AS `amount`,
    COALESCE((
        SELECT `py`.`name`
        FROM `payee_patterns` `pp`
        JOIN `payees` `py` ON `py`.`id` = `pp`.`payee_id`
        WHERE `pi`.`description` LIKE `pp`.`match_pattern`
        ORDER BY
            `pp`.`priority` DESC,
            CASE WHEN LOCATE('%', `pp`.`match_pattern`) = 0 AND LOCATE('_', `pp`.`match_pattern`) = 0 THEN 1 ELSE 0 END DESC,
            (CASE WHEN LEFT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END
             + CASE WHEN RIGHT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END) DESC,
            CHAR_LENGTH(REPLACE(REPLACE(`pp`.`match_pattern`, '%', ''), '_', '')) DESC,
            ((CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '%', '')))
             + (CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '_', '')))),
            CHAR_LENGTH(`pp`.`match_pattern`) DESC,
            `pp`.`id`
        LIMIT 1
    ), `pi`.`description`) AS `description`,
    `pi`.`description` AS `raw_description`,
    NULL AS `original_ref`,
    NULL AS `transaction_type`,
    NULL AS `transfer_group_id`,
    NULL AS `project_id`,
    NULL AS `earmark_id`,
    `pi`.`category_id` AS `category_id`,
    `c`.`name` AS `category_name`,
    `c`.`type` AS `category_type`,
    `c`.`parent_id` AS `parent_category_id`,
    `pc`.`name` AS `parent_category_name`,
    CASE WHEN `c`.`parent_id` IS NULL THEN 0 ELSE 1 END AS `sub_flag`,
    1 AS `is_prediction`,
    0 AS `is_editable`
FROM `predicted_instances` `pi`
JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
JOIN `accounts` `fa` ON `fa`.`id` = `pi`.`from_account_id`
LEFT JOIN `accounts` `ta` ON `ta`.`id` = `pi`.`to_account_id`
LEFT JOIN `categories` `pc` ON `pc`.`id` = `c`.`parent_id`
WHERE `c`.`type` IN ('income', 'expense')
  AND COALESCE(`pi`.`fulfilled`, 0) = 0
  AND COALESCE(`pi`.`resolution_status`, 'open') = 'open'

UNION ALL

SELECT
    _utf8mb4'Predicted' COLLATE utf8mb4_0900_ai_ci AS `source`,
    _utf8mb4'predicted_transfer_out' COLLATE utf8mb4_0900_ai_ci AS `line_role`,
    NULL AS `transaction_id`,
    NULL AS `transaction_split_id`,
    `pi`.`id` AS `predicted_instance_id`,
    `pi`.`scheduled_date` AS `line_date`,
    `pi`.`from_account_id` AS `account_id`,
    `fa`.`name` AS `account_name`,
    `pi`.`to_account_id` AS `other_account_id`,
    `ta`.`name` AS `other_account_name`,
    -(`pi`.`amount`) AS `amount`,
    COALESCE((
        SELECT `py`.`name`
        FROM `payee_patterns` `pp`
        JOIN `payees` `py` ON `py`.`id` = `pp`.`payee_id`
        WHERE `pi`.`description` LIKE `pp`.`match_pattern`
        ORDER BY
            `pp`.`priority` DESC,
            CASE WHEN LOCATE('%', `pp`.`match_pattern`) = 0 AND LOCATE('_', `pp`.`match_pattern`) = 0 THEN 1 ELSE 0 END DESC,
            (CASE WHEN LEFT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END
             + CASE WHEN RIGHT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END) DESC,
            CHAR_LENGTH(REPLACE(REPLACE(`pp`.`match_pattern`, '%', ''), '_', '')) DESC,
            ((CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '%', '')))
             + (CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '_', '')))),
            CHAR_LENGTH(`pp`.`match_pattern`) DESC,
            `pp`.`id`
        LIMIT 1
    ), `pi`.`description`) AS `description`,
    `pi`.`description` AS `raw_description`,
    NULL AS `original_ref`,
    NULL AS `transaction_type`,
    NULL AS `transfer_group_id`,
    NULL AS `project_id`,
    NULL AS `earmark_id`,
    `pi`.`category_id` AS `category_id`,
    `c`.`name` AS `category_name`,
    `c`.`type` AS `category_type`,
    `c`.`parent_id` AS `parent_category_id`,
    `pc`.`name` AS `parent_category_name`,
    CASE WHEN `c`.`parent_id` IS NULL THEN 0 ELSE 1 END AS `sub_flag`,
    1 AS `is_prediction`,
    0 AS `is_editable`
FROM `predicted_instances` `pi`
JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
JOIN `accounts` `fa` ON `fa`.`id` = `pi`.`from_account_id`
LEFT JOIN `accounts` `ta` ON `ta`.`id` = `pi`.`to_account_id`
LEFT JOIN `categories` `pc` ON `pc`.`id` = `c`.`parent_id`
WHERE `c`.`type` = 'transfer'
  AND COALESCE(`pi`.`fulfilled`, 0) = 0
  AND COALESCE(`pi`.`resolution_status`, 'open') = 'open'

UNION ALL

SELECT
    _utf8mb4'Predicted' COLLATE utf8mb4_0900_ai_ci AS `source`,
    _utf8mb4'predicted_transfer_in' COLLATE utf8mb4_0900_ai_ci AS `line_role`,
    NULL AS `transaction_id`,
    NULL AS `transaction_split_id`,
    `pi`.`id` AS `predicted_instance_id`,
    `pi`.`scheduled_date` AS `line_date`,
    `pi`.`to_account_id` AS `account_id`,
    `ta`.`name` AS `account_name`,
    `pi`.`from_account_id` AS `other_account_id`,
    `fa`.`name` AS `other_account_name`,
    `pi`.`amount` AS `amount`,
    COALESCE((
        SELECT `py`.`name`
        FROM `payee_patterns` `pp`
        JOIN `payees` `py` ON `py`.`id` = `pp`.`payee_id`
        WHERE `pi`.`description` LIKE `pp`.`match_pattern`
        ORDER BY
            `pp`.`priority` DESC,
            CASE WHEN LOCATE('%', `pp`.`match_pattern`) = 0 AND LOCATE('_', `pp`.`match_pattern`) = 0 THEN 1 ELSE 0 END DESC,
            (CASE WHEN LEFT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END
             + CASE WHEN RIGHT(`pp`.`match_pattern`, 1) NOT IN ('%', '_') THEN 1 ELSE 0 END) DESC,
            CHAR_LENGTH(REPLACE(REPLACE(`pp`.`match_pattern`, '%', ''), '_', '')) DESC,
            ((CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '%', '')))
             + (CHAR_LENGTH(`pp`.`match_pattern`) - CHAR_LENGTH(REPLACE(`pp`.`match_pattern`, '_', '')))),
            CHAR_LENGTH(`pp`.`match_pattern`) DESC,
            `pp`.`id`
        LIMIT 1
    ), `pi`.`description`) AS `description`,
    `pi`.`description` AS `raw_description`,
    NULL AS `original_ref`,
    NULL AS `transaction_type`,
    NULL AS `transfer_group_id`,
    NULL AS `project_id`,
    NULL AS `earmark_id`,
    `pi`.`category_id` AS `category_id`,
    `c`.`name` AS `category_name`,
    `c`.`type` AS `category_type`,
    `c`.`parent_id` AS `parent_category_id`,
    `pc`.`name` AS `parent_category_name`,
    CASE WHEN `c`.`parent_id` IS NULL THEN 0 ELSE 1 END AS `sub_flag`,
    1 AS `is_prediction`,
    0 AS `is_editable`
FROM `predicted_instances` `pi`
JOIN `categories` `c` ON `c`.`id` = `pi`.`category_id`
JOIN `accounts` `fa` ON `fa`.`id` = `pi`.`from_account_id`
JOIN `accounts` `ta` ON `ta`.`id` = `pi`.`to_account_id`
LEFT JOIN `categories` `pc` ON `pc`.`id` = `c`.`parent_id`
WHERE `c`.`type` = 'transfer'
  AND COALESCE(`pi`.`fulfilled`, 0) = 0
  AND COALESCE(`pi`.`resolution_status`, 'open') = 'open';

SET character_set_client = @saved_cs_client;
SET character_set_results = @saved_cs_results;
SET collation_connection = @saved_col_connection;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `account_balances_as_of_last_night`;
/*!50001 DROP VIEW IF EXISTS `account_balances_as_of_last_night`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `account_balances_as_of_last_night` AS SELECT
 1 AS `account_id`,
 1 AS `account_name`,
 1 AS `account_type`,
 1 AS `starting_balance`,
 1 AS `transaction_total`,
 1 AS `last_transaction`,
 1 AS `balance_as_of_last_night`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('current','credit','savings','loan','investment','house') NOT NULL,
  `institution` varchar(100) DEFAULT NULL,
  `currency` char(3) DEFAULT 'GBP',
  `active` tinyint(1) DEFAULT '1',
  `starting_balance` decimal(10,2) DEFAULT '0.00',
  `statement_day` tinyint DEFAULT NULL,
  `payment_day` tinyint DEFAULT NULL,
  `paid_from` int DEFAULT NULL,
  `repayment_method` enum('full','minimum','fixed') NOT NULL DEFAULT 'full',
  `fixed_payment_amount` decimal(10,2) DEFAULT NULL,
  `min_payment_floor` decimal(10,2) DEFAULT NULL,
  `min_payment_percent` decimal(6,3) DEFAULT NULL,
  `min_payment_calc` enum('floor_or_percent','floor_or_percent_plus_interest') NOT NULL DEFAULT 'floor_or_percent',
  `promo_apr` decimal(6,3) DEFAULT NULL,
  `promo_end_date` date DEFAULT NULL,
  `standard_apr` decimal(6,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_accounts_paid_from` (`paid_from`),
  CONSTRAINT `fk_accounts_paid_from` FOREIGN KEY (`paid_from`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `month_start` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`,`month_start`),
  KEY `idx_budgets_month_start` (`month_start`),
  CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `parent_id` int DEFAULT NULL,
  `type` enum('income','expense','transfer') DEFAULT NULL,
  `linked_account_id` int DEFAULT NULL,
  `budget_order` int NOT NULL,
  `fixedness` enum('fixed','variable') DEFAULT NULL,
  `priority` enum('essential','discretionary') DEFAULT NULL,
  `watcher_budget_mode` enum('normal','reimbursable','ignore') NOT NULL DEFAULT 'normal',
  `watcher_timing_mode` enum('operational','flexible','ignore') NOT NULL DEFAULT 'operational',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `fk_category_linked_account` (`linked_account_id`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_category_linked_account` FOREIGN KEY (`linked_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `earmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `earmarks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(100) NOT NULL,
  `run_mode` enum('live','dry_run') NOT NULL DEFAULT 'live',
  `status` enum('running','success','failed') NOT NULL DEFAULT 'running',
  `effective_date` date DEFAULT NULL,
  `summary_period_start` date DEFAULT NULL,
  `summary_period_end` date DEFAULT NULL,
  `recipients` text,
  `subject` varchar(255) DEFAULT NULL,
  `error_message` text,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_runs_job_started` (`job_name`,`started_at`),
  KEY `idx_email_runs_status_started` (`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forecast_timeline_view`;
/*!50001 DROP VIEW IF EXISTS `forecast_timeline_view`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `forecast_timeline_view` AS SELECT
 1 AS `account_id`,
 1 AS `date`,
 1 AS `running_balance`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `import_run_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_run_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `import_run_id` bigint unsigned NOT NULL,
  `account_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_run_account` (`import_run_id`,`account_id`),
  KEY `idx_import_run_accounts_account` (`account_id`,`created_at`),
  CONSTRAINT `fk_import_run_accounts_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_import_run_accounts_run` FOREIGN KEY (`import_run_id`) REFERENCES `import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `file_type` enum('csv','ofx') NOT NULL,
  `parser` varchar(50) NOT NULL,
  `requested_account_id` int DEFAULT NULL,
  `status` enum('running','success','failed') NOT NULL DEFAULT 'running',
  `exit_code` int DEFAULT NULL,
  `output_text` mediumtext,
  `rows_parsed` int DEFAULT NULL,
  `rows_new` int DEFAULT NULL,
  `rows_predictions` int DEFAULT NULL,
  `rows_potential_duplicates` int DEFAULT NULL,
  `rows_exact_suppressed` int DEFAULT NULL,
  `rows_repaired` int DEFAULT NULL,
  `rows_malformed` int DEFAULT NULL,
  `rows_non_billed` int DEFAULT NULL,
  `rows_unresolved_accounts` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import_runs_status_created` (`status`,`created_at`),
  KEY `idx_import_runs_requested_account` (`requested_account_id`),
  CONSTRAINT `fk_import_runs_requested_account` FOREIGN KEY (`requested_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ledger_lines`;
/*!50001 DROP VIEW IF EXISTS `ledger_lines`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `ledger_lines` AS SELECT
 1 AS `source`,
 1 AS `line_role`,
 1 AS `transaction_id`,
 1 AS `transaction_split_id`,
 1 AS `predicted_instance_id`,
 1 AS `line_date`,
 1 AS `account_id`,
 1 AS `account_name`,
 1 AS `other_account_id`,
 1 AS `other_account_name`,
 1 AS `amount`,
 1 AS `description`,
 1 AS `raw_description`,
 1 AS `original_ref`,
 1 AS `transaction_type`,
 1 AS `transfer_group_id`,
 1 AS `project_id`,
 1 AS `earmark_id`,
 1 AS `category_id`,
 1 AS `category_name`,
 1 AS `category_type`,
 1 AS `parent_category_id`,
 1 AS `parent_category_name`,
 1 AS `sub_flag`,
 1 AS `is_prediction`,
 1 AS `is_editable`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `ofx_account_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ofx_account_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bank_id` varchar(20) DEFAULT NULL,
  `acct_id` varchar(50) NOT NULL,
  `account_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ofx_account` (`bank_id`,`acct_id`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `ofx_account_map_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payee_patterns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payee_patterns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payee_id` int NOT NULL,
  `match_pattern` varchar(255) NOT NULL,
  `priority` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `payee_id` (`payee_id`,`match_pattern`),
  KEY `idx_payee_patterns_priority` (`priority`,`payee_id`),
  CONSTRAINT `payee_patterns_ibfk_1` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_bonus_overview`;
/*!50001 DROP VIEW IF EXISTS `payroll_bonus_overview`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_bonus_overview` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `tax_month`,
 1 AS `bonus_amount`,
 1 AS `bonus_pct_of_annualised_basic`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_categories` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `line_type_id` tinyint NOT NULL,
  `display_order` tinyint NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_categories_name` (`name`),
  KEY `idx_payroll_categories_line_type` (`line_type_id`),
  CONSTRAINT `fk_payroll_categories_line_type` FOREIGN KEY (`line_type_id`) REFERENCES `payroll_line_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_employments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `employer_name` varchar(100) DEFAULT NULL,
  `employee_number` varchar(20) DEFAULT NULL,
  `tax_reference` varchar(20) DEFAULT NULL,
  `employment_start_date` date DEFAULT NULL,
  `employment_end_date` date DEFAULT NULL,
  `status` enum('active','ended','unknown') NOT NULL DEFAULT 'unknown',
  `legacy_employee_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_employments_legacy_employee` (`legacy_employee_id`),
  KEY `idx_payroll_employments_person` (`person_id`),
  KEY `idx_payroll_employments_employee_number` (`employee_number`),
  CONSTRAINT `fk_payroll_employments_person` FOREIGN KEY (`person_id`) REFERENCES `payroll_people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_expense_categories` (
  `id` smallint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_expense_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_expense_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_expense_payment_methods` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `name` varchar(45) NOT NULL,
  `funding_type` enum('corporate','personal','unknown') NOT NULL DEFAULT 'unknown',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_expense_payment_methods_name` (`name`),
  KEY `idx_payroll_expense_payment_methods_funding` (`funding_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_expense_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_expense_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employment_id` int DEFAULT NULL,
  `report_reference` varchar(45) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_expense_reports_reference` (`report_reference`),
  KEY `idx_payroll_expense_reports_employment` (`employment_id`),
  CONSTRAINT `fk_payroll_expense_reports_employment` FOREIGN KEY (`employment_id`) REFERENCES `payroll_employments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_expenses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `expense_date` date NOT NULL,
  `expense_category_id` smallint NOT NULL,
  `gbp_amount` decimal(12,2) NOT NULL,
  `original_currency` char(3) DEFAULT NULL,
  `original_amount` decimal(12,2) DEFAULT NULL,
  `merchant` varchar(200) DEFAULT NULL,
  `country_code` char(2) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `payment_method_id` tinyint DEFAULT NULL,
  `tax_year_start` smallint GENERATED ALWAYS AS ((year((`expense_date` - interval 5 day)) - if((month((`expense_date` - interval 5 day)) < 4),1,0))) STORED,
  `tax_month` tinyint GENERATED ALWAYS AS ((((month((`expense_date` - interval 5 day)) + 8) % 12) + 1)) STORED,
  `legacy_expense_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_expenses_legacy_id` (`legacy_expense_id`),
  KEY `idx_payroll_expenses_report_date` (`report_id`,`expense_date`),
  KEY `idx_payroll_expenses_tax_period` (`tax_year_start`,`tax_month`),
  KEY `idx_payroll_expenses_category` (`expense_category_id`),
  KEY `idx_payroll_expenses_payment_method` (`payment_method_id`),
  CONSTRAINT `fk_payroll_expenses_category` FOREIGN KEY (`expense_category_id`) REFERENCES `payroll_expense_categories` (`id`),
  CONSTRAINT `fk_payroll_expenses_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payroll_expense_payment_methods` (`id`),
  CONSTRAINT `fk_payroll_expenses_report` FOREIGN KEY (`report_id`) REFERENCES `payroll_expense_reports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_line_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_line_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `payslip_id` int NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `category_id` tinyint NOT NULL,
  `legacy_line_item_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_line_items_legacy_id` (`legacy_line_item_id`),
  KEY `idx_payroll_line_items_payslip` (`payslip_id`),
  KEY `idx_payroll_line_items_category` (`category_id`),
  CONSTRAINT `fk_payroll_line_items_category` FOREIGN KEY (`category_id`) REFERENCES `payroll_categories` (`id`),
  CONSTRAINT `fk_payroll_line_items_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payroll_payslips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_line_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_line_types` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `name` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_line_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_monthly_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_monthly_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_monthly_summary` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `month_start`,
 1 AS `basic_pay`,
 1 AS `benefits`,
 1 AS `pre_tax_deductions`,
 1 AS `additional_earnings`,
 1 AS `bonus`,
 1 AS `pension`,
 1 AS `taxes`,
 1 AS `post_tax_deductions`,
 1 AS `total_gross`,
 1 AS `total_deductions`,
 1 AS `net_pay`,
 1 AS `tax_percentage`,
 1 AS `corporate_expenses`,
 1 AS `personal_expenses`,
 1 AS `payslip_count`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_payslip_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_payslip_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_payslip_summary` AS SELECT
 1 AS `payslip_id`,
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `pay_date`,
 1 AS `month_start`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `tax_month`,
 1 AS `tax_code`,
 1 AS `annual_salary`,
 1 AS `basic_pay`,
 1 AS `benefits`,
 1 AS `pre_tax_deductions`,
 1 AS `additional_earnings`,
 1 AS `bonus`,
 1 AS `pension`,
 1 AS `taxes`,
 1 AS `post_tax_deductions`,
 1 AS `total_gross`,
 1 AS `total_deductions`,
 1 AS `net_pay`,
 1 AS `tax_percentage`,
 1 AS `line_item_count`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_payslips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employment_id` int NOT NULL,
  `pay_date` date NOT NULL,
  `tax_code` varchar(20) DEFAULT NULL,
  `annual_salary` decimal(12,2) DEFAULT NULL,
  `tax_year_start` smallint GENERATED ALWAYS AS ((year((`pay_date` - interval 5 day)) - if((month((`pay_date` - interval 5 day)) < 4),1,0))) STORED,
  `tax_month` tinyint GENERATED ALWAYS AS ((((month((`pay_date` - interval 5 day)) + 8) % 12) + 1)) STORED,
  `legacy_payslip_id` int DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_payslips_legacy_id` (`legacy_payslip_id`),
  KEY `idx_payroll_payslips_employment_date` (`employment_id`,`pay_date`,`id`),
  KEY `idx_payroll_payslips_tax_period` (`employment_id`,`tax_year_start`,`tax_month`),
  CONSTRAINT `fk_payroll_payslips_employment` FOREIGN KEY (`employment_id`) REFERENCES `payroll_employments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_people` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `national_insurance_number` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender_code` char(1) DEFAULT NULL,
  `legacy_employee_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_people_ni_number` (`national_insurance_number`),
  UNIQUE KEY `uq_payroll_people_legacy_employee` (`legacy_employee_id`),
  KEY `idx_payroll_people_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_previous_ytd_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_previous_ytd_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_previous_ytd_summary` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `months_processed`,
 1 AS `ytd_basic_pay`,
 1 AS `ytd_benefits`,
 1 AS `ytd_pre_tax_deductions`,
 1 AS `ytd_additional_earnings`,
 1 AS `ytd_bonus`,
 1 AS `ytd_pension`,
 1 AS `ytd_taxes`,
 1 AS `ytd_post_tax_deductions`,
 1 AS `ytd_gross`,
 1 AS `ytd_total_deductions`,
 1 AS `ytd_net_pay`,
 1 AS `effective_tax_rate`,
 1 AS `ytd_corporate_expenses`,
 1 AS `ytd_personal_expenses`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_salary_changes`;
/*!50001 DROP VIEW IF EXISTS `payroll_salary_changes`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_salary_changes` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `change_date`,
 1 AS `previous_annual_salary`,
 1 AS `new_annual_salary`,
 1 AS `value_change`,
 1 AS `percent_change`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_tax_month_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_tax_month_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_tax_month_summary` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `tax_month`,
 1 AS `basic_pay`,
 1 AS `benefits`,
 1 AS `pre_tax_deductions`,
 1 AS `additional_earnings`,
 1 AS `bonus`,
 1 AS `pension`,
 1 AS `taxes`,
 1 AS `post_tax_deductions`,
 1 AS `total_gross`,
 1 AS `total_deductions`,
 1 AS `net_pay`,
 1 AS `tax_percentage`,
 1 AS `corporate_expenses`,
 1 AS `personal_expenses`,
 1 AS `payslip_count`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_tax_year_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_tax_year_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_tax_year_summary` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `basic_pay`,
 1 AS `benefits`,
 1 AS `pre_tax_deductions`,
 1 AS `additional_earnings`,
 1 AS `bonus`,
 1 AS `pension`,
 1 AS `taxes`,
 1 AS `post_tax_deductions`,
 1 AS `total_gross`,
 1 AS `total_deductions`,
 1 AS `net_pay`,
 1 AS `effective_tax_rate`,
 1 AS `corporate_expenses`,
 1 AS `personal_expenses`,
 1 AS `payslip_count`,
 1 AS `tax_months_with_payslips`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_ytd_comparison`;
/*!50001 DROP VIEW IF EXISTS `payroll_ytd_comparison`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_ytd_comparison` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `current_tax_year`,
 1 AS `previous_tax_year`,
 1 AS `current_months`,
 1 AS `previous_months`,
 1 AS `current_ytd_gross`,
 1 AS `previous_ytd_gross`,
 1 AS `current_ytd_net_pay`,
 1 AS `previous_ytd_net_pay`,
 1 AS `current_ytd_bonus`,
 1 AS `previous_ytd_bonus`,
 1 AS `current_effective_tax_rate`,
 1 AS `previous_effective_tax_rate`,
 1 AS `current_ytd_basic_pay`,
 1 AS `previous_ytd_basic_pay`,
 1 AS `current_ytd_pension`,
 1 AS `previous_ytd_pension`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `payroll_ytd_summary`;
/*!50001 DROP VIEW IF EXISTS `payroll_ytd_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `payroll_ytd_summary` AS SELECT
 1 AS `employment_id`,
 1 AS `person_id`,
 1 AS `person_name`,
 1 AS `tax_year_start`,
 1 AS `tax_year`,
 1 AS `months_processed`,
 1 AS `ytd_basic_pay`,
 1 AS `ytd_benefits`,
 1 AS `ytd_pre_tax_deductions`,
 1 AS `ytd_additional_earnings`,
 1 AS `ytd_bonus`,
 1 AS `ytd_pension`,
 1 AS `ytd_taxes`,
 1 AS `ytd_post_tax_deductions`,
 1 AS `ytd_gross`,
 1 AS `ytd_total_deductions`,
 1 AS `ytd_net_pay`,
 1 AS `effective_tax_rate`,
 1 AS `ytd_corporate_expenses`,
 1 AS `ytd_personal_expenses`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `planned_income_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planned_income_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL,
  `category_id` int NOT NULL,
  `account_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `budget_month_start` date DEFAULT NULL,
  `window_start` date NOT NULL,
  `window_end` date NOT NULL,
  `timing_strategy` enum('earliest','midpoint','latest','manual') NOT NULL DEFAULT 'latest',
  `manual_date` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_planned_income_events_active_window` (`active`,`window_start`,`window_end`),
  KEY `idx_planned_income_events_account_window` (`account_id`,`window_start`,`window_end`),
  KEY `idx_planned_income_events_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predicted_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predicted_instances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `predicted_transaction_id` int DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `from_account_id` int NOT NULL,
  `to_account_id` int DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `prediction_type` enum('income','expense','transfer') NOT NULL DEFAULT 'expense',
  `fulfilled` tinyint(1) DEFAULT '0',
  `fulfilled_at` datetime DEFAULT NULL,
  `fulfilled_by_transaction_id` int DEFAULT NULL,
  `fulfilled_by_transfer_group_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `description` text,
  `budget_treatment` enum('additional','budget_backed') NOT NULL DEFAULT 'additional',
  `budget_month_start` date DEFAULT NULL,
  `budget_amount` decimal(12,2) DEFAULT NULL,
  `confirmed` tinyint(1) DEFAULT '0',
  `resolution_status` enum('open','skipped') NOT NULL DEFAULT 'open',
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` varchar(255) DEFAULT NULL,
  `statement_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `predicted_transaction_id` (`predicted_transaction_id`,`scheduled_date`),
  UNIQUE KEY `unique_transfer_predictions` (`scheduled_date`,`from_account_id`,`to_account_id`,`prediction_type`),
  KEY `idx_predicted_instances_statement_id` (`statement_id`),
  KEY `idx_predicted_instances_fulfilled_at` (`fulfilled_at`),
  KEY `idx_predicted_instances_fulfilled_by_tx` (`fulfilled_by_transaction_id`),
  KEY `idx_predicted_instances_fulfilled_by_tg` (`fulfilled_by_transfer_group_id`),
  KEY `idx_predicted_instances_resolution` (`resolution_status`,`fulfilled`,`scheduled_date`),
  KEY `idx_predicted_instances_from_sched_state` (`from_account_id`,`scheduled_date`,`fulfilled`,`resolution_status`),
  KEY `idx_predicted_instances_to_sched_state` (`to_account_id`,`scheduled_date`,`fulfilled`,`resolution_status`),
  KEY `idx_predicted_instances_prediction_type_state` (`prediction_type`,`fulfilled`,`resolution_status`,`scheduled_date`),
  CONSTRAINT `fk_predicted_instances_statement` FOREIGN KEY (`statement_id`) REFERENCES `statements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `predicted_instances_ibfk_1` FOREIGN KEY (`predicted_transaction_id`) REFERENCES `predicted_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predicted_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predicted_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL,
  `from_account_id` int NOT NULL,
  `to_account_id` int DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `prediction_type` enum('income','expense','transfer') NOT NULL DEFAULT 'expense',
  `amount` decimal(10,2) DEFAULT NULL,
  `variable` tinyint(1) DEFAULT '0',
  `average_over_last` int DEFAULT NULL,
  `day_of_month` int DEFAULT NULL,
  `adjust_for_weekend` enum('none','previous_business_day','next_business_day') DEFAULT 'none',
  `active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `anchor_type` enum('day_of_month','nth_weekday','last_business_day','weekly') DEFAULT 'day_of_month',
  `frequency` enum('weekly','fortnightly','monthly','custom') DEFAULT 'monthly',
  `repeat_interval` int DEFAULT '1',
  `weekday` tinyint DEFAULT NULL,
  `nth_weekday` tinyint DEFAULT NULL,
  `is_business_day` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `to_account_id` (`to_account_id`),
  KEY `category_id` (`category_id`),
  KEY `predicted_transactions_ibfk_1` (`from_account_id`),
  KEY `idx_predicted_transactions_prediction_type` (`prediction_type`),
  CONSTRAINT `predicted_transactions_ibfk_1` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `predicted_transactions_ibfk_2` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `predicted_transactions_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `execution_ms` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staging_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staging_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `date` date NOT NULL,
  `description` text,
  `amount` decimal(10,2) NOT NULL,
  `raw_description` text,
  `category_id` int DEFAULT NULL,
  `status` enum('new','reviewed','inserted','duplicate','potential_duplicate','fulfills_prediction') DEFAULT 'new',
  `matched_transaction_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `original_memo` text,
  `predicted_instance_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `fk_staging_matched_transaction` (`matched_transaction_id`),
  KEY `predicted_instance_id` (`predicted_instance_id`),
  KEY `idx_staging_account_date_amount` (`account_id`,`date`,`amount`),
  KEY `idx_staging_status_date` (`status`,`date`),
  CONSTRAINT `fk_staging_matched_transaction` FOREIGN KEY (`matched_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staging_transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `staging_transactions_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `staging_transactions_ibfk_3` FOREIGN KEY (`matched_transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `staging_transactions_ibfk_4` FOREIGN KEY (`predicted_instance_id`) REFERENCES `predicted_instances` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `statements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `statement_date` date NOT NULL,
  `start_balance` decimal(10,2) NOT NULL,
  `end_balance` decimal(10,2) NOT NULL,
  `reconciled` tinyint(1) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `payment_due_date` date DEFAULT NULL,
  `minimum_payment_due` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_statements_account_statement_date` (`account_id`,`statement_date`),
  CONSTRAINT `statements_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction_splits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_splits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `category_id` int NOT NULL,
  `project_id` int DEFAULT NULL,
  `fund_source_id` int DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `category_id` (`category_id`),
  KEY `project_id` (`project_id`),
  KEY `fund_source_id` (`fund_source_id`),
  CONSTRAINT `transaction_splits_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `transaction_splits_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `transaction_splits_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `transaction_splits_ibfk_4` FOREIGN KEY (`fund_source_id`) REFERENCES `earmarks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `date` date NOT NULL,
  `description` text,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('withdrawal','deposit','charge','credit','transfer') NOT NULL,
  `transfer_group_id` int DEFAULT NULL,
  `cleared` tinyint(1) DEFAULT '1',
  `original_ref` varchar(100) DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `predicted_transaction_id` int DEFAULT NULL,
  `reconciled` tinyint(1) DEFAULT '0',
  `statement_id` int DEFAULT NULL,
  `payee_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `earmark_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_transactions_category` (`category_id`),
  KEY `predicted_transaction_id` (`predicted_transaction_id`),
  KEY `idx_statement_id` (`statement_id`),
  KEY `fk_payee` (`payee_id`),
  KEY `idx_transactions_account_date` (`account_id`,`date`),
  KEY `idx_transactions_account_amount_date` (`account_id`,`amount`,`date`),
  KEY `idx_transactions_transfer_group` (`transfer_group_id`),
  CONSTRAINT `fk_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`predicted_transaction_id`) REFERENCES `predicted_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfer_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfer_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(255) DEFAULT NULL,
  `from_account_id` int DEFAULT NULL,
  `to_account_id` int DEFAULT NULL,
  `expected_amount` decimal(10,2) DEFAULT NULL,
  `transfer_date` date DEFAULT NULL,
  `transfer_status` enum('complete','partial','needs_review') NOT NULL DEFAULT 'needs_review',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transfer_groups_from_account` (`from_account_id`),
  KEY `idx_transfer_groups_to_account` (`to_account_id`),
  KEY `idx_transfer_groups_status_date` (`transfer_status`,`transfer_date`),
  CONSTRAINT `fk_transfer_groups_from_account` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_transfer_groups_to_account` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `watcher_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `watcher_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dedupe_key` varchar(255) NOT NULL,
  `alert_type` varchar(100) NOT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `status` enum('open','acknowledged','dismissed','snoozed','resolved') NOT NULL DEFAULT 'open',
  `title` varchar(255) NOT NULL,
  `summary` text NOT NULL,
  `evidence_json` json DEFAULT NULL,
  `recommended_action_json` json DEFAULT NULL,
  `related_account_id` int DEFAULT NULL,
  `related_category_id` int DEFAULT NULL,
  `related_predicted_transaction_id` int DEFAULT NULL,
  `first_detected_at` datetime NOT NULL,
  `last_detected_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watcher_alerts_dedupe` (`dedupe_key`),
  KEY `idx_watcher_alerts_status_severity` (`status`,`severity`),
  KEY `idx_watcher_alerts_type_status` (`alert_type`,`status`),
  KEY `idx_watcher_alerts_last_detected` (`last_detected_at`),
  KEY `idx_watcher_alerts_related_account` (`related_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `account_balances_as_of_last_night`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `account_balances_as_of_last_night` AS select `a`.`id` AS `account_id`,`a`.`name` AS `account_name`,`a`.`type` AS `account_type`,`a`.`starting_balance` AS `starting_balance`,ifnull(sum(`t`.`amount`),0) AS `transaction_total`,max(`t`.`date`) AS `last_transaction`,round((`a`.`starting_balance` + ifnull(sum(`t`.`amount`),0)),2) AS `balance_as_of_last_night` from (`accounts` `a` left join `transactions` `t` on(((`t`.`account_id` = `a`.`id`) and (`t`.`date` <= (curdate() - interval 1 day))))) where (`a`.`active` = 1) group by `a`.`id`,`a`.`name`,`a`.`starting_balance`,`a`.`type` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `forecast_timeline_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `forecast_timeline_view` AS select `events`.`account_id` AS `account_id`,`events`.`event_date` AS `date`,round((`a`.`starting_balance` + sum(`events`.`amount`) OVER (PARTITION BY `events`.`account_id` ORDER BY `events`.`event_date`,`events`.`sort_order`,`events`.`source_id` ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) ),2) AS `running_balance` from ((select `t`.`account_id` AS `account_id`,`t`.`date` AS `event_date`,`t`.`amount` AS `amount`,0 AS `sort_order`,`t`.`id` AS `source_id` from `transactions` `t` where (`t`.`date` <= curdate()) union all select `pi`.`from_account_id` AS `account_id`,`pi`.`scheduled_date` AS `event_date`,`pi`.`amount` AS `amount`,1 AS `sort_order`,`pi`.`id` AS `source_id` from (`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) where ((`pi`.`scheduled_date` > curdate()) and (`pi`.`amount` is not null) and (`c`.`type` in ('income','expense')) and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open')) union all select `pi`.`from_account_id` AS `account_id`,`pi`.`scheduled_date` AS `event_date`,-(abs(`pi`.`amount`)) AS `amount`,2 AS `sort_order`,`pi`.`id` AS `source_id` from (`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) where ((`pi`.`scheduled_date` > curdate()) and (`pi`.`amount` is not null) and (`pi`.`from_account_id` is not null) and (`c`.`type` = 'transfer') and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open')) union all select `pi`.`to_account_id` AS `account_id`,`pi`.`scheduled_date` AS `event_date`,abs(`pi`.`amount`) AS `amount`,3 AS `sort_order`,`pi`.`id` AS `source_id` from (`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) where ((`pi`.`scheduled_date` > curdate()) and (`pi`.`amount` is not null) and (`pi`.`to_account_id` is not null) and (`c`.`type` = 'transfer') and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open'))) `events` join `accounts` `a` on((`a`.`id` = `events`.`account_id`))) where ((`a`.`active` = 1) and (`a`.`type` = 'current')) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `ledger_lines`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `ledger_lines` AS select (_utf8mb4'Actual' collate utf8mb4_0900_ai_ci) AS `source`,(_utf8mb4'actual' collate utf8mb4_0900_ai_ci) AS `line_role`,`t`.`id` AS `transaction_id`,NULL AS `transaction_split_id`,NULL AS `predicted_instance_id`,`t`.`date` AS `line_date`,`t`.`account_id` AS `account_id`,`a`.`name` AS `account_name`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`from_account_id`)) then `tg`.`to_account_id` when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`to_account_id`)) then `tg`.`from_account_id` else NULL end) AS `other_account_id`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`from_account_id`)) then `ta`.`name` when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`to_account_id`)) then `fa`.`name` else NULL end) AS `other_account_name`,`t`.`amount` AS `amount`,coalesce(`p`.`name`,`t`.`description`) AS `description`,`t`.`description` AS `raw_description`,`t`.`original_ref` AS `original_ref`,`t`.`type` AS `transaction_type`,`t`.`transfer_group_id` AS `transfer_group_id`,`t`.`project_id` AS `project_id`,`t`.`earmark_id` AS `earmark_id`,`t`.`category_id` AS `category_id`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`from_account_id`) and (`ta`.`name` is not null)) then concat((_utf8mb4'Transfer to ' collate utf8mb4_0900_ai_ci),`ta`.`name`) when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null) and (`t`.`account_id` = `tg`.`to_account_id`) and (`fa`.`name` is not null)) then concat((_utf8mb4'Transfer from ' collate utf8mb4_0900_ai_ci),`fa`.`name`) else `c`.`name` end) AS `category_name`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null)) then (_utf8mb4'transfer' collate utf8mb4_0900_ai_ci) else `c`.`type` end) AS `category_type`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null)) then NULL else `c`.`parent_id` end) AS `parent_category_id`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null)) then NULL else `pc`.`name` end) AS `parent_category_name`,(case when ((`t`.`transfer_group_id` is not null) and (`tg`.`id` is not null)) then 0 when (`c`.`parent_id` is null) then 0 else 1 end) AS `sub_flag`,0 AS `is_prediction`,1 AS `is_editable` from ((((((((`transactions` `t` join `accounts` `a` on((`a`.`id` = `t`.`account_id`))) left join `categories` `c` on((`c`.`id` = `t`.`category_id`))) left join `categories` `pc` on((`pc`.`id` = `c`.`parent_id`))) left join `payees` `p` on((`p`.`id` = `t`.`payee_id`))) left join `transaction_splits` `ts` on((`ts`.`transaction_id` = `t`.`id`))) left join `transfer_groups` `tg` on((`tg`.`id` = `t`.`transfer_group_id`))) left join `accounts` `fa` on((`fa`.`id` = `tg`.`from_account_id`))) left join `accounts` `ta` on((`ta`.`id` = `tg`.`to_account_id`))) where (`ts`.`transaction_id` is null) union all select (_utf8mb4'Split' collate utf8mb4_0900_ai_ci) AS `source`,(_utf8mb4'split' collate utf8mb4_0900_ai_ci) AS `line_role`,`t`.`id` AS `transaction_id`,`ts`.`id` AS `transaction_split_id`,NULL AS `predicted_instance_id`,`t`.`date` AS `line_date`,`t`.`account_id` AS `account_id`,`a`.`name` AS `account_name`,NULL AS `other_account_id`,NULL AS `other_account_name`,`ts`.`amount` AS `amount`,coalesce(`p`.`name`,`t`.`description`) AS `description`,`t`.`description` AS `raw_description`,`t`.`original_ref` AS `original_ref`,`t`.`type` AS `transaction_type`,`t`.`transfer_group_id` AS `transfer_group_id`,coalesce(`ts`.`project_id`,`t`.`project_id`) AS `project_id`,coalesce(`ts`.`fund_source_id`,`t`.`earmark_id`) AS `earmark_id`,`ts`.`category_id` AS `category_id`,`c`.`name` AS `category_name`,`c`.`type` AS `category_type`,`c`.`parent_id` AS `parent_category_id`,`pc`.`name` AS `parent_category_name`,(case when (`c`.`parent_id` is null) then 0 else 1 end) AS `sub_flag`,0 AS `is_prediction`,1 AS `is_editable` from (((((`transaction_splits` `ts` join `transactions` `t` on((`t`.`id` = `ts`.`transaction_id`))) join `accounts` `a` on((`a`.`id` = `t`.`account_id`))) join `categories` `c` on((`c`.`id` = `ts`.`category_id`))) left join `categories` `pc` on((`pc`.`id` = `c`.`parent_id`))) left join `payees` `p` on((`p`.`id` = `t`.`payee_id`))) union all select (_utf8mb4'Predicted' collate utf8mb4_0900_ai_ci) AS `source`,(_utf8mb4'predicted' collate utf8mb4_0900_ai_ci) AS `line_role`,NULL AS `transaction_id`,NULL AS `transaction_split_id`,`pi`.`id` AS `predicted_instance_id`,`pi`.`scheduled_date` AS `line_date`,`pi`.`from_account_id` AS `account_id`,`fa`.`name` AS `account_name`,`pi`.`to_account_id` AS `other_account_id`,`ta`.`name` AS `other_account_name`,`pi`.`amount` AS `amount`,coalesce((select `py`.`name` from (`payee_patterns` `pp` join `payees` `py` on((`py`.`id` = `pp`.`payee_id`))) where (`pi`.`description` like `pp`.`match_pattern`) order by `pp`.`priority` desc,(case when ((locate('%',`pp`.`match_pattern`) = 0) and (locate('_',`pp`.`match_pattern`) = 0)) then 1 else 0 end) desc,((case when (left(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end) + (case when (right(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end)) desc,char_length(replace(replace(`pp`.`match_pattern`,'%',''),'_','')) desc,((char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'%',''))) + (char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'_','')))),char_length(`pp`.`match_pattern`) desc,`pp`.`id` limit 1),`pi`.`description`) AS `description`,`pi`.`description` AS `raw_description`,NULL AS `original_ref`,NULL AS `transaction_type`,NULL AS `transfer_group_id`,NULL AS `project_id`,NULL AS `earmark_id`,`pi`.`category_id` AS `category_id`,`c`.`name` AS `category_name`,`c`.`type` AS `category_type`,`c`.`parent_id` AS `parent_category_id`,`pc`.`name` AS `parent_category_name`,(case when (`c`.`parent_id` is null) then 0 else 1 end) AS `sub_flag`,1 AS `is_prediction`,0 AS `is_editable` from ((((`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) join `accounts` `fa` on((`fa`.`id` = `pi`.`from_account_id`))) left join `accounts` `ta` on((`ta`.`id` = `pi`.`to_account_id`))) left join `categories` `pc` on((`pc`.`id` = `c`.`parent_id`))) where ((`c`.`type` in ('income','expense')) and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open')) union all select (_utf8mb4'Predicted' collate utf8mb4_0900_ai_ci) AS `source`,(_utf8mb4'predicted_transfer_out' collate utf8mb4_0900_ai_ci) AS `line_role`,NULL AS `transaction_id`,NULL AS `transaction_split_id`,`pi`.`id` AS `predicted_instance_id`,`pi`.`scheduled_date` AS `line_date`,`pi`.`from_account_id` AS `account_id`,`fa`.`name` AS `account_name`,`pi`.`to_account_id` AS `other_account_id`,`ta`.`name` AS `other_account_name`,-(`pi`.`amount`) AS `amount`,coalesce((select `py`.`name` from (`payee_patterns` `pp` join `payees` `py` on((`py`.`id` = `pp`.`payee_id`))) where (`pi`.`description` like `pp`.`match_pattern`) order by `pp`.`priority` desc,(case when ((locate('%',`pp`.`match_pattern`) = 0) and (locate('_',`pp`.`match_pattern`) = 0)) then 1 else 0 end) desc,((case when (left(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end) + (case when (right(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end)) desc,char_length(replace(replace(`pp`.`match_pattern`,'%',''),'_','')) desc,((char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'%',''))) + (char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'_','')))),char_length(`pp`.`match_pattern`) desc,`pp`.`id` limit 1),`pi`.`description`) AS `description`,`pi`.`description` AS `raw_description`,NULL AS `original_ref`,NULL AS `transaction_type`,NULL AS `transfer_group_id`,NULL AS `project_id`,NULL AS `earmark_id`,`pi`.`category_id` AS `category_id`,`c`.`name` AS `category_name`,`c`.`type` AS `category_type`,`c`.`parent_id` AS `parent_category_id`,`pc`.`name` AS `parent_category_name`,(case when (`c`.`parent_id` is null) then 0 else 1 end) AS `sub_flag`,1 AS `is_prediction`,0 AS `is_editable` from ((((`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) join `accounts` `fa` on((`fa`.`id` = `pi`.`from_account_id`))) left join `accounts` `ta` on((`ta`.`id` = `pi`.`to_account_id`))) left join `categories` `pc` on((`pc`.`id` = `c`.`parent_id`))) where ((`c`.`type` = 'transfer') and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open')) union all select (_utf8mb4'Predicted' collate utf8mb4_0900_ai_ci) AS `source`,(_utf8mb4'predicted_transfer_in' collate utf8mb4_0900_ai_ci) AS `line_role`,NULL AS `transaction_id`,NULL AS `transaction_split_id`,`pi`.`id` AS `predicted_instance_id`,`pi`.`scheduled_date` AS `line_date`,`pi`.`to_account_id` AS `account_id`,`ta`.`name` AS `account_name`,`pi`.`from_account_id` AS `other_account_id`,`fa`.`name` AS `other_account_name`,`pi`.`amount` AS `amount`,coalesce((select `py`.`name` from (`payee_patterns` `pp` join `payees` `py` on((`py`.`id` = `pp`.`payee_id`))) where (`pi`.`description` like `pp`.`match_pattern`) order by `pp`.`priority` desc,(case when ((locate('%',`pp`.`match_pattern`) = 0) and (locate('_',`pp`.`match_pattern`) = 0)) then 1 else 0 end) desc,((case when (left(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end) + (case when (right(`pp`.`match_pattern`,1) not in ('%','_')) then 1 else 0 end)) desc,char_length(replace(replace(`pp`.`match_pattern`,'%',''),'_','')) desc,((char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'%',''))) + (char_length(`pp`.`match_pattern`) - char_length(replace(`pp`.`match_pattern`,'_','')))),char_length(`pp`.`match_pattern`) desc,`pp`.`id` limit 1),`pi`.`description`) AS `description`,`pi`.`description` AS `raw_description`,NULL AS `original_ref`,NULL AS `transaction_type`,NULL AS `transfer_group_id`,NULL AS `project_id`,NULL AS `earmark_id`,`pi`.`category_id` AS `category_id`,`c`.`name` AS `category_name`,`c`.`type` AS `category_type`,`c`.`parent_id` AS `parent_category_id`,`pc`.`name` AS `parent_category_name`,(case when (`c`.`parent_id` is null) then 0 else 1 end) AS `sub_flag`,1 AS `is_prediction`,0 AS `is_editable` from ((((`predicted_instances` `pi` join `categories` `c` on((`c`.`id` = `pi`.`category_id`))) join `accounts` `fa` on((`fa`.`id` = `pi`.`from_account_id`))) join `accounts` `ta` on((`ta`.`id` = `pi`.`to_account_id`))) left join `categories` `pc` on((`pc`.`id` = `c`.`parent_id`))) where ((`c`.`type` = 'transfer') and (coalesce(`pi`.`fulfilled`,0) = 0) and (coalesce(`pi`.`resolution_status`,'open') = 'open')) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_bonus_overview`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_bonus_overview` AS select `tm`.`employment_id` AS `employment_id`,`tm`.`person_id` AS `person_id`,`tm`.`person_name` AS `person_name`,`tm`.`tax_year_start` AS `tax_year_start`,`tm`.`tax_year` AS `tax_year`,`tm`.`tax_month` AS `tax_month`,`tm`.`bonus` AS `bonus_amount`,round((case when (`tm`.`basic_pay` = 0) then 0 else ((`tm`.`bonus` / (`tm`.`basic_pay` * 12)) * 100) end),2) AS `bonus_pct_of_annualised_basic` from `payroll_tax_month_summary` `tm` where (`tm`.`bonus` > 0) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_monthly_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_monthly_summary` AS select `ps`.`employment_id` AS `employment_id`,`ps`.`person_id` AS `person_id`,`ps`.`person_name` AS `person_name`,`ps`.`month_start` AS `month_start`,sum(`ps`.`basic_pay`) AS `basic_pay`,sum(`ps`.`benefits`) AS `benefits`,sum(`ps`.`pre_tax_deductions`) AS `pre_tax_deductions`,sum(`ps`.`additional_earnings`) AS `additional_earnings`,sum(`ps`.`bonus`) AS `bonus`,sum(`ps`.`pension`) AS `pension`,sum(`ps`.`taxes`) AS `taxes`,sum(`ps`.`post_tax_deductions`) AS `post_tax_deductions`,sum(`ps`.`total_gross`) AS `total_gross`,sum(`ps`.`total_deductions`) AS `total_deductions`,sum(`ps`.`net_pay`) AS `net_pay`,round((case when (sum(`ps`.`total_gross`) = 0) then 0 else ((sum(`ps`.`taxes`) / sum(`ps`.`total_gross`)) * 100) end),2) AS `tax_percentage`,coalesce(max(`exp`.`corporate_expenses`),0) AS `corporate_expenses`,coalesce(max(`exp`.`personal_expenses`),0) AS `personal_expenses`,count(0) AS `payslip_count` from (`payroll_payslip_summary` `ps` left join (select `r`.`employment_id` AS `employment_id`,date_format(`x`.`expense_date`,'%Y-%m-01') AS `month_start`,sum((case when (`pm`.`funding_type` = 'corporate') then `x`.`gbp_amount` else 0 end)) AS `corporate_expenses`,sum((case when (`pm`.`funding_type` = 'personal') then `x`.`gbp_amount` else 0 end)) AS `personal_expenses` from ((`payroll_expenses` `x` join `payroll_expense_reports` `r` on((`r`.`id` = `x`.`report_id`))) left join `payroll_expense_payment_methods` `pm` on((`pm`.`id` = `x`.`payment_method_id`))) where (`r`.`employment_id` is not null) group by `r`.`employment_id`,date_format(`x`.`expense_date`,'%Y-%m-01')) `exp` on(((`exp`.`employment_id` = `ps`.`employment_id`) and (`exp`.`month_start` = `ps`.`month_start`)))) group by `ps`.`employment_id`,`ps`.`person_id`,`ps`.`person_name`,`ps`.`month_start` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_payslip_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_payslip_summary` AS select `p`.`id` AS `payslip_id`,`p`.`employment_id` AS `employment_id`,`e`.`person_id` AS `person_id`,`person`.`full_name` AS `person_name`,`p`.`pay_date` AS `pay_date`,date_format(`p`.`pay_date`,'%Y-%m-01') AS `month_start`,`p`.`tax_year_start` AS `tax_year_start`,concat(`p`.`tax_year_start`,'/',right((`p`.`tax_year_start` + 1),2)) AS `tax_year`,`p`.`tax_month` AS `tax_month`,`p`.`tax_code` AS `tax_code`,`p`.`annual_salary` AS `annual_salary`,sum((case when (`c`.`name` = 'BASIC PAY') then `li`.`amount` else 0 end)) AS `basic_pay`,sum((case when (`c`.`name` = 'BENEFITS') then `li`.`amount` else 0 end)) AS `benefits`,sum((case when (`c`.`name` = 'PRE-TAX DEDUCTIONS') then `li`.`amount` else 0 end)) AS `pre_tax_deductions`,sum((case when (`c`.`name` = 'ADDITIONAL EARNINGS') then `li`.`amount` else 0 end)) AS `additional_earnings`,sum((case when (`c`.`name` = 'BONUS') then `li`.`amount` else 0 end)) AS `bonus`,sum((case when (`c`.`name` = 'PENSION') then `li`.`amount` else 0 end)) AS `pension`,sum((case when (`c`.`name` = 'TAXES') then `li`.`amount` else 0 end)) AS `taxes`,sum((case when (`c`.`name` = 'POST-TAX DEDUCTIONS') then `li`.`amount` else 0 end)) AS `post_tax_deductions`,sum((case when (`lt`.`name` = 'Pay') then `li`.`amount` else 0 end)) AS `total_gross`,sum((case when (`lt`.`name` = 'Deduction') then `li`.`amount` else 0 end)) AS `total_deductions`,(sum((case when (`lt`.`name` = 'Pay') then `li`.`amount` else 0 end)) - sum((case when (`lt`.`name` = 'Deduction') then `li`.`amount` else 0 end))) AS `net_pay`,round((case when (sum((case when (`lt`.`name` = 'Pay') then `li`.`amount` else 0 end)) = 0) then 0 else ((sum((case when (`c`.`name` = 'TAXES') then `li`.`amount` else 0 end)) / sum((case when (`lt`.`name` = 'Pay') then `li`.`amount` else 0 end))) * 100) end),2) AS `tax_percentage`,count(`li`.`id`) AS `line_item_count` from (((((`payroll_payslips` `p` join `payroll_employments` `e` on((`e`.`id` = `p`.`employment_id`))) join `payroll_people` `person` on((`person`.`id` = `e`.`person_id`))) left join `payroll_line_items` `li` on((`li`.`payslip_id` = `p`.`id`))) left join `payroll_categories` `c` on((`c`.`id` = `li`.`category_id`))) left join `payroll_line_types` `lt` on((`lt`.`id` = `c`.`line_type_id`))) group by `p`.`id`,`p`.`employment_id`,`e`.`person_id`,`person`.`full_name`,`p`.`pay_date`,`p`.`tax_year_start`,`p`.`tax_month`,`p`.`tax_code`,`p`.`annual_salary` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_previous_ytd_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_previous_ytd_summary` AS with `current_progress` as (select `tm`.`employment_id` AS `employment_id`,max(`tm`.`tax_month`) AS `max_tax_month` from `payroll_tax_month_summary` `tm` where ((`tm`.`tax_year_start` = (year((curdate() - interval 5 day)) - if((month((curdate() - interval 5 day)) < 4),1,0))) and (`tm`.`tax_month` <= (((month((curdate() - interval 5 day)) + 8) % 12) + 1))) group by `tm`.`employment_id`) select `tm`.`employment_id` AS `employment_id`,`tm`.`person_id` AS `person_id`,`tm`.`person_name` AS `person_name`,`tm`.`tax_year_start` AS `tax_year_start`,`tm`.`tax_year` AS `tax_year`,count(0) AS `months_processed`,sum(`tm`.`basic_pay`) AS `ytd_basic_pay`,sum(`tm`.`benefits`) AS `ytd_benefits`,sum(`tm`.`pre_tax_deductions`) AS `ytd_pre_tax_deductions`,sum(`tm`.`additional_earnings`) AS `ytd_additional_earnings`,sum(`tm`.`bonus`) AS `ytd_bonus`,sum(`tm`.`pension`) AS `ytd_pension`,sum(`tm`.`taxes`) AS `ytd_taxes`,sum(`tm`.`post_tax_deductions`) AS `ytd_post_tax_deductions`,sum(`tm`.`total_gross`) AS `ytd_gross`,sum(`tm`.`total_deductions`) AS `ytd_total_deductions`,sum(`tm`.`net_pay`) AS `ytd_net_pay`,round((case when (sum(`tm`.`total_gross`) = 0) then 0 else ((sum(`tm`.`taxes`) / sum(`tm`.`total_gross`)) * 100) end),2) AS `effective_tax_rate`,sum(`tm`.`corporate_expenses`) AS `ytd_corporate_expenses`,sum(`tm`.`personal_expenses`) AS `ytd_personal_expenses` from (`payroll_tax_month_summary` `tm` join `current_progress` `cp` on(((`cp`.`employment_id` = `tm`.`employment_id`) and (`tm`.`tax_month` <= `cp`.`max_tax_month`)))) where (`tm`.`tax_year_start` = ((year((curdate() - interval 5 day)) - if((month((curdate() - interval 5 day)) < 4),1,0)) - 1)) group by `tm`.`employment_id`,`tm`.`person_id`,`tm`.`person_name`,`tm`.`tax_year_start`,`tm`.`tax_year` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_salary_changes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_salary_changes` AS with `ordered_payslips` as (select `p`.`id` AS `id`,`p`.`employment_id` AS `employment_id`,`p`.`pay_date` AS `pay_date`,`p`.`annual_salary` AS `annual_salary`,lag(`p`.`annual_salary`) OVER (PARTITION BY `p`.`employment_id` ORDER BY `p`.`pay_date`,`p`.`id` )  AS `previous_annual_salary` from `payroll_payslips` `p`) select `op`.`employment_id` AS `employment_id`,`e`.`person_id` AS `person_id`,`person`.`full_name` AS `person_name`,`op`.`pay_date` AS `change_date`,`op`.`previous_annual_salary` AS `previous_annual_salary`,`op`.`annual_salary` AS `new_annual_salary`,(`op`.`annual_salary` - `op`.`previous_annual_salary`) AS `value_change`,round((case when (`op`.`previous_annual_salary` > 0) then (((`op`.`annual_salary` - `op`.`previous_annual_salary`) / `op`.`previous_annual_salary`) * 100) else NULL end),2) AS `percent_change` from ((`ordered_payslips` `op` join `payroll_employments` `e` on((`e`.`id` = `op`.`employment_id`))) join `payroll_people` `person` on((`person`.`id` = `e`.`person_id`))) where ((`op`.`previous_annual_salary` is not null) and (`op`.`annual_salary` <> `op`.`previous_annual_salary`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_tax_month_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_tax_month_summary` AS select `ps`.`employment_id` AS `employment_id`,`ps`.`person_id` AS `person_id`,`ps`.`person_name` AS `person_name`,`ps`.`tax_year_start` AS `tax_year_start`,concat(`ps`.`tax_year_start`,'/',right((`ps`.`tax_year_start` + 1),2)) AS `tax_year`,`ps`.`tax_month` AS `tax_month`,sum(`ps`.`basic_pay`) AS `basic_pay`,sum(`ps`.`benefits`) AS `benefits`,sum(`ps`.`pre_tax_deductions`) AS `pre_tax_deductions`,sum(`ps`.`additional_earnings`) AS `additional_earnings`,sum(`ps`.`bonus`) AS `bonus`,sum(`ps`.`pension`) AS `pension`,sum(`ps`.`taxes`) AS `taxes`,sum(`ps`.`post_tax_deductions`) AS `post_tax_deductions`,sum(`ps`.`total_gross`) AS `total_gross`,sum(`ps`.`total_deductions`) AS `total_deductions`,sum(`ps`.`net_pay`) AS `net_pay`,round((case when (sum(`ps`.`total_gross`) = 0) then 0 else ((sum(`ps`.`taxes`) / sum(`ps`.`total_gross`)) * 100) end),2) AS `tax_percentage`,coalesce(max(`exp`.`corporate_expenses`),0) AS `corporate_expenses`,coalesce(max(`exp`.`personal_expenses`),0) AS `personal_expenses`,count(0) AS `payslip_count` from (`payroll_payslip_summary` `ps` left join (select `r`.`employment_id` AS `employment_id`,`x`.`tax_year_start` AS `tax_year_start`,`x`.`tax_month` AS `tax_month`,sum((case when (`pm`.`funding_type` = 'corporate') then `x`.`gbp_amount` else 0 end)) AS `corporate_expenses`,sum((case when (`pm`.`funding_type` = 'personal') then `x`.`gbp_amount` else 0 end)) AS `personal_expenses` from ((`payroll_expenses` `x` join `payroll_expense_reports` `r` on((`r`.`id` = `x`.`report_id`))) left join `payroll_expense_payment_methods` `pm` on((`pm`.`id` = `x`.`payment_method_id`))) where (`r`.`employment_id` is not null) group by `r`.`employment_id`,`x`.`tax_year_start`,`x`.`tax_month`) `exp` on(((`exp`.`employment_id` = `ps`.`employment_id`) and (`exp`.`tax_year_start` = `ps`.`tax_year_start`) and (`exp`.`tax_month` = `ps`.`tax_month`)))) group by `ps`.`employment_id`,`ps`.`person_id`,`ps`.`person_name`,`ps`.`tax_year_start`,`ps`.`tax_month` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_tax_year_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_tax_year_summary` AS select `tm`.`employment_id` AS `employment_id`,`tm`.`person_id` AS `person_id`,`tm`.`person_name` AS `person_name`,`tm`.`tax_year_start` AS `tax_year_start`,`tm`.`tax_year` AS `tax_year`,sum(`tm`.`basic_pay`) AS `basic_pay`,sum(`tm`.`benefits`) AS `benefits`,sum(`tm`.`pre_tax_deductions`) AS `pre_tax_deductions`,sum(`tm`.`additional_earnings`) AS `additional_earnings`,sum(`tm`.`bonus`) AS `bonus`,sum(`tm`.`pension`) AS `pension`,sum(`tm`.`taxes`) AS `taxes`,sum(`tm`.`post_tax_deductions`) AS `post_tax_deductions`,sum(`tm`.`total_gross`) AS `total_gross`,sum(`tm`.`total_deductions`) AS `total_deductions`,sum(`tm`.`net_pay`) AS `net_pay`,round((case when (sum(`tm`.`total_gross`) = 0) then 0 else ((sum(`tm`.`taxes`) / sum(`tm`.`total_gross`)) * 100) end),2) AS `effective_tax_rate`,sum(`tm`.`corporate_expenses`) AS `corporate_expenses`,sum(`tm`.`personal_expenses`) AS `personal_expenses`,sum(`tm`.`payslip_count`) AS `payslip_count`,count(0) AS `tax_months_with_payslips` from `payroll_tax_month_summary` `tm` group by `tm`.`employment_id`,`tm`.`person_id`,`tm`.`person_name`,`tm`.`tax_year_start`,`tm`.`tax_year` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_ytd_comparison`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_ytd_comparison` AS select `curr`.`employment_id` AS `employment_id`,`curr`.`person_id` AS `person_id`,`curr`.`person_name` AS `person_name`,`curr`.`tax_year` AS `current_tax_year`,`prev`.`tax_year` AS `previous_tax_year`,`curr`.`months_processed` AS `current_months`,`prev`.`months_processed` AS `previous_months`,`curr`.`ytd_gross` AS `current_ytd_gross`,`prev`.`ytd_gross` AS `previous_ytd_gross`,`curr`.`ytd_net_pay` AS `current_ytd_net_pay`,`prev`.`ytd_net_pay` AS `previous_ytd_net_pay`,`curr`.`ytd_bonus` AS `current_ytd_bonus`,`prev`.`ytd_bonus` AS `previous_ytd_bonus`,`curr`.`effective_tax_rate` AS `current_effective_tax_rate`,`prev`.`effective_tax_rate` AS `previous_effective_tax_rate`,`curr`.`ytd_basic_pay` AS `current_ytd_basic_pay`,`prev`.`ytd_basic_pay` AS `previous_ytd_basic_pay`,`curr`.`ytd_pension` AS `current_ytd_pension`,`prev`.`ytd_pension` AS `previous_ytd_pension` from (`payroll_ytd_summary` `curr` join `payroll_previous_ytd_summary` `prev` on((`prev`.`employment_id` = `curr`.`employment_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `payroll_ytd_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `payroll_ytd_summary` AS select `tm`.`employment_id` AS `employment_id`,`tm`.`person_id` AS `person_id`,`tm`.`person_name` AS `person_name`,`tm`.`tax_year_start` AS `tax_year_start`,`tm`.`tax_year` AS `tax_year`,count(0) AS `months_processed`,sum(`tm`.`basic_pay`) AS `ytd_basic_pay`,sum(`tm`.`benefits`) AS `ytd_benefits`,sum(`tm`.`pre_tax_deductions`) AS `ytd_pre_tax_deductions`,sum(`tm`.`additional_earnings`) AS `ytd_additional_earnings`,sum(`tm`.`bonus`) AS `ytd_bonus`,sum(`tm`.`pension`) AS `ytd_pension`,sum(`tm`.`taxes`) AS `ytd_taxes`,sum(`tm`.`post_tax_deductions`) AS `ytd_post_tax_deductions`,sum(`tm`.`total_gross`) AS `ytd_gross`,sum(`tm`.`total_deductions`) AS `ytd_total_deductions`,sum(`tm`.`net_pay`) AS `ytd_net_pay`,round((case when (sum(`tm`.`total_gross`) = 0) then 0 else ((sum(`tm`.`taxes`) / sum(`tm`.`total_gross`)) * 100) end),2) AS `effective_tax_rate`,sum(`tm`.`corporate_expenses`) AS `ytd_corporate_expenses`,sum(`tm`.`personal_expenses`) AS `ytd_personal_expenses` from `payroll_tax_month_summary` `tm` where ((`tm`.`tax_year_start` = (year((curdate() - interval 5 day)) - if((month((curdate() - interval 5 day)) < 4),1,0))) and (`tm`.`tax_month` <= (((month((curdate() - interval 5 day)) + 8) % 12) + 1))) group by `tm`.`employment_id`,`tm`.`person_id`,`tm`.`person_name`,`tm`.`tax_year_start`,`tm`.`tax_year` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


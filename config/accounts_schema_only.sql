-- MySQL dump 10.13  Distrib 8.0.41, for Linux (x86_64)
--
-- Host: localhost    Database: accounts
-- ------------------------------------------------------
-- Server version	8.0.41-0ubuntu0.22.04.1

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

--
-- Temporary view structure for view `account_balances_as_of_last_night`
--

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
 1 AS `balance_as_of_last_night`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `accounts`
--

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
  PRIMARY KEY (`id`),
  KEY `fk_accounts_paid_from` (`paid_from`),
  CONSTRAINT `fk_accounts_paid_from` FOREIGN KEY (`paid_from`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `budgets`
--

DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `month_start` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `capex` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`,`month_start`),
  CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3474 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

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
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `fk_category_linked_account` (`linked_account_id`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_category_linked_account` FOREIGN KEY (`linked_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=283 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `forecast_timeline_view`
--

DROP TABLE IF EXISTS `forecast_timeline_view`;
/*!50001 DROP VIEW IF EXISTS `forecast_timeline_view`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `forecast_timeline_view` AS SELECT 
 1 AS `account_id`,
 1 AS `date`,
 1 AS `running_balance`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `fund_sources`
--

DROP TABLE IF EXISTS `fund_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fund_sources` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ofx_account_map`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `predicted_instances`
--

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
  `category_id` int NOT NULL,
  `fulfilled` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `description` text,
  `confirmed` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `predicted_transaction_id` (`predicted_transaction_id`,`scheduled_date`),
  UNIQUE KEY `unique_repayments` (`scheduled_date`,`from_account_id`,`to_account_id`,`category_id`),
  CONSTRAINT `predicted_instances_ibfk_1` FOREIGN KEY (`predicted_transaction_id`) REFERENCES `predicted_transactions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4664 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `predicted_transactions`
--

DROP TABLE IF EXISTS `predicted_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predicted_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL,
  `from_account_id` int NOT NULL,
  `to_account_id` int DEFAULT NULL,
  `category_id` int NOT NULL,
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
  CONSTRAINT `predicted_transactions_ibfk_1` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `predicted_transactions_ibfk_2` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `predicted_transactions_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `notes` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `staging_transactions`
--

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
  KEY `account_id` (`account_id`),
  KEY `category_id` (`category_id`),
  KEY `fk_staging_matched_transaction` (`matched_transaction_id`),
  KEY `predicted_instance_id` (`predicted_instance_id`),
  CONSTRAINT `fk_staging_matched_transaction` FOREIGN KEY (`matched_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staging_transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `staging_transactions_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `staging_transactions_ibfk_3` FOREIGN KEY (`matched_transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `staging_transactions_ibfk_4` FOREIGN KEY (`predicted_instance_id`) REFERENCES `predicted_instances` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `statements`
--

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
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `statements_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_splits`
--

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
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `category_id` (`category_id`),
  KEY `project_id` (`project_id`),
  KEY `fund_source_id` (`fund_source_id`),
  CONSTRAINT `transaction_splits_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `transaction_splits_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `transaction_splits_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `transaction_splits_ibfk_4` FOREIGN KEY (`fund_source_id`) REFERENCES `fund_sources` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=541 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transactions`
--

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
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`),
  KEY `fk_transactions_category` (`category_id`),
  KEY `predicted_transaction_id` (`predicted_transaction_id`),
  KEY `idx_statement_id` (`statement_id`),
  CONSTRAINT `fk_transactions_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`predicted_transaction_id`) REFERENCES `predicted_transactions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14441 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transfer_groups`
--

DROP TABLE IF EXISTS `transfer_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfer_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1037 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Final view structure for view `account_balances_as_of_last_night`
--

/*!50001 DROP VIEW IF EXISTS `account_balances_as_of_last_night`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`john`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `account_balances_as_of_last_night` AS select `a`.`id` AS `account_id`,`a`.`name` AS `account_name`,`a`.`type` AS `account_type`,`a`.`starting_balance` AS `starting_balance`,ifnull(sum(`t`.`amount`),0) AS `transaction_total`,round((`a`.`starting_balance` + ifnull(sum(`t`.`amount`),0)),2) AS `balance_as_of_last_night` from (`accounts` `a` left join `transactions` `t` on(((`t`.`account_id` = `a`.`id`) and (`t`.`date` <= (curdate() - interval 1 day))))) where (`a`.`active` = 1) group by `a`.`id`,`a`.`name`,`a`.`starting_balance`,`a`.`type` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `forecast_timeline_view`
--

/*!50001 DROP VIEW IF EXISTS `forecast_timeline_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`john`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `forecast_timeline_view` AS select `t`.`account_id` AS `account_id`,`t`.`date` AS `date`,(sum(`t`.`amount`) OVER (PARTITION BY `t`.`account_id` ORDER BY `t`.`date` )  + `a`.`starting_balance`) AS `running_balance` from ((select `transactions`.`account_id` AS `account_id`,`transactions`.`date` AS `date`,`transactions`.`amount` AS `amount` from `transactions` where (`transactions`.`date` <= curdate()) union all select `predicted_instances`.`from_account_id` AS `from_account_id`,`predicted_instances`.`scheduled_date` AS `scheduled_date`,-(`predicted_instances`.`amount`) AS `-amount` from `predicted_instances` where (`predicted_instances`.`scheduled_date` > curdate()) union all select `predicted_instances`.`to_account_id` AS `to_account_id`,`predicted_instances`.`scheduled_date` AS `scheduled_date`,`predicted_instances`.`amount` AS `amount` from `predicted_instances` where (`predicted_instances`.`scheduled_date` > curdate())) `t` join `accounts` `a` on((`a`.`id` = `t`.`account_id`))) where ((`a`.`active` = 1) and (`a`.`type` = 'current')) */;
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

-- Dump completed on 2025-04-29 17:21:24

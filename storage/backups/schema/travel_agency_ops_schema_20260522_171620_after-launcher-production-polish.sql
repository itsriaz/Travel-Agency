-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: travel_agency_ops
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(190) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=214 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_name` varchar(190) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_event` (`event_name`),
  KEY `idx_audit_actor` (`actor_user_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11803 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_login_attempts`
--

DROP TABLE IF EXISTS `auth_login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `login_key` varchar(190) NOT NULL,
  `ip_address` varchar(64) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_attempts_lookup` (`login_key`,`ip_address`,`attempted_at`),
  KEY `idx_auth_attempts_user` (`user_id`),
  CONSTRAINT `fk_auth_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=192 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_documents`
--

DROP TABLE IF EXISTS `booking_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `traveler_id` bigint(20) unsigned DEFAULT NULL,
  `booking_service_id` bigint(20) unsigned DEFAULT NULL,
  `customer_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_payment_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_obligation_id` bigint(20) unsigned DEFAULT NULL,
  `document_type` enum('passport_copy','visa_copy','ticket_copy','payment_proof','supplier_invoice','general_attachment') NOT NULL,
  `title` varchar(190) NOT NULL,
  `notes` text DEFAULT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `stored_file_name` varchar(255) NOT NULL,
  `storage_disk` varchar(50) NOT NULL DEFAULT 'local',
  `storage_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_extension` varchar(20) NOT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL,
  `sha256_hash` char(64) NOT NULL,
  `visibility` enum('private') NOT NULL DEFAULT 'private',
  `status` enum('active','superseded','revoked') NOT NULL DEFAULT 'active',
  `replaced_document_id` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_by_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_documents_branch` (`branch_id`),
  KEY `idx_booking_documents_booking` (`booking_id`),
  KEY `idx_booking_documents_traveler` (`traveler_id`),
  KEY `idx_booking_documents_service` (`booking_service_id`),
  KEY `idx_booking_documents_receipt` (`customer_receipt_id`),
  KEY `idx_booking_documents_supplier_payment` (`supplier_payment_id`),
  KEY `idx_booking_documents_supplier_obligation` (`supplier_obligation_id`),
  KEY `idx_booking_documents_type` (`document_type`),
  KEY `idx_booking_documents_status` (`status`),
  KEY `idx_booking_documents_hash` (`sha256_hash`),
  KEY `fk_booking_documents_replaced_document` (`replaced_document_id`),
  KEY `fk_booking_documents_uploaded_by` (`uploaded_by_user_id`),
  KEY `fk_booking_documents_updated_by` (`updated_by_user_id`),
  KEY `fk_booking_documents_revoked_by` (`revoked_by_user_id`),
  CONSTRAINT `fk_booking_documents_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_documents_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_booking_documents_receipt` FOREIGN KEY (`customer_receipt_id`) REFERENCES `customer_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_replaced_document` FOREIGN KEY (`replaced_document_id`) REFERENCES `booking_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_revoked_by` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_supplier_obligation` FOREIGN KEY (`supplier_obligation_id`) REFERENCES `supplier_obligations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_supplier_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_traveler` FOREIGN KEY (`traveler_id`) REFERENCES `travelers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_parties`
--

DROP TABLE IF EXISTS `booking_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_parties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `party_label` varchar(120) NOT NULL DEFAULT 'Lead Traveler / Booking Party',
  `lead_traveler_name` varchar(190) NOT NULL,
  `contact_mobile` varchar(50) DEFAULT NULL,
  `passport_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_booking_party_booking` (`booking_id`),
  KEY `idx_booking_parties_lead_name` (`lead_traveler_name`),
  KEY `idx_booking_parties_mobile` (`contact_mobile`),
  KEY `idx_booking_parties_passport` (`passport_number`),
  CONSTRAINT `fk_booking_parties_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=757 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_reminders`
--

DROP TABLE IF EXISTS `booking_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `traveler_id` bigint(20) unsigned DEFAULT NULL,
  `booking_service_id` bigint(20) unsigned DEFAULT NULL,
  `customer_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_payment_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_obligation_id` bigint(20) unsigned DEFAULT NULL,
  `reminder_type` enum('due_date','passport_expiry','visa_expiry','supplier_payment','document_missing','travel_date','custom_manual') NOT NULL,
  `title` varchar(190) NOT NULL,
  `reminder_note` text DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `channel` varchar(50) DEFAULT NULL,
  `owner_label` varchar(120) DEFAULT NULL,
  `status` enum('open','due','completed','dismissed') NOT NULL DEFAULT 'open',
  `priority` enum('normal','high') NOT NULL DEFAULT 'normal',
  `system_generated` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_key` varchar(190) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `dismissed_at` datetime DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_booking_reminders_key` (`reminder_key`),
  KEY `idx_booking_reminders_branch` (`branch_id`),
  KEY `idx_booking_reminders_booking` (`booking_id`),
  KEY `idx_booking_reminders_traveler` (`traveler_id`),
  KEY `idx_booking_reminders_service` (`booking_service_id`),
  KEY `idx_booking_reminders_receipt` (`customer_receipt_id`),
  KEY `idx_booking_reminders_supplier_payment` (`supplier_payment_id`),
  KEY `idx_booking_reminders_supplier_obligation` (`supplier_obligation_id`),
  KEY `idx_booking_reminders_type` (`reminder_type`),
  KEY `idx_booking_reminders_status` (`status`),
  KEY `idx_booking_reminders_due_at` (`due_at`),
  KEY `fk_booking_reminders_created_by` (`created_by_user_id`),
  KEY `fk_booking_reminders_updated_by` (`updated_by_user_id`),
  CONSTRAINT `fk_booking_reminders_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_reminders_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_booking_reminders_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_receipt` FOREIGN KEY (`customer_receipt_id`) REFERENCES `customer_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_supplier_obligation` FOREIGN KEY (`supplier_obligation_id`) REFERENCES `supplier_obligations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_supplier_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_traveler` FOREIGN KEY (`traveler_id`) REFERENCES `travelers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_reminders_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=231 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_service_events`
--

DROP TABLE IF EXISTS `booking_service_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_service_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `booking_reference` varchar(50) NOT NULL,
  `service_line_reference` varchar(50) NOT NULL,
  `event_type` enum('issue','cancel','refund','reissue') NOT NULL,
  `event_status` enum('draft','posted','voided') NOT NULL DEFAULT 'draft',
  `event_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `original_ticket_number` varchar(50) DEFAULT NULL,
  `new_ticket_number` varchar(50) DEFAULT NULL,
  `original_pnr` varchar(50) DEFAULT NULL,
  `new_pnr` varchar(50) DEFAULT NULL,
  `fare_difference_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `service_fee_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `customer_refund_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `customer_credit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `supplier_refund_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `supplier_credit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `voided_by_user_id` int(10) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_service_events_branch` (`branch_id`),
  KEY `idx_booking_service_events_booking` (`booking_id`),
  KEY `idx_booking_service_events_service` (`booking_service_id`),
  KEY `idx_booking_service_events_reference` (`booking_reference`,`service_line_reference`),
  KEY `idx_booking_service_events_type_status` (`event_type`,`event_status`),
  KEY `idx_booking_service_events_date` (`event_date`),
  KEY `idx_booking_service_events_journal` (`journal_entry_id`),
  KEY `fk_booking_service_events_created_by` (`created_by_user_id`),
  KEY `fk_booking_service_events_voided_by` (`voided_by_user_id`),
  CONSTRAINT `fk_booking_service_events_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_service_events_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_booking_service_events_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_service_events_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_service_events_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_service_events_voided_by` FOREIGN KEY (`voided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_services`
--

DROP TABLE IF EXISTS `booking_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `line_reference` varchar(50) NOT NULL,
  `display_order` int(10) unsigned NOT NULL DEFAULT 0,
  `service_type` enum('air ticket','visa','umrah','hotel','transport','tourism','other') NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `supplier_name_snapshot` varchar(190) DEFAULT NULL,
  `traveler_id` bigint(20) unsigned DEFAULT NULL,
  `passenger_name_snapshot` varchar(190) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `sale_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `purchase_cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `taxes` decimal(18,2) NOT NULL DEFAULT 0.00,
  `other_fare` decimal(14,2) NOT NULL DEFAULT 0.00,
  `soto_fare` decimal(14,2) NOT NULL DEFAULT 0.00,
  `spyi_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `aq_yr_pk_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `yq_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `oth_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_input` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat` decimal(18,2) NOT NULL DEFAULT 0.00,
  `commission` decimal(18,2) NOT NULL DEFAULT 0.00,
  `service_charge` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `final_sale_price` decimal(18,2) DEFAULT NULL,
  `net_profit_loss` decimal(18,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `service_status` varchar(50) NOT NULL DEFAULT 'Open',
  `remarks` text DEFAULT NULL,
  `loss_reason` text DEFAULT NULL,
  `loss_reason_recorded_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_booking_services_booking_line` (`booking_id`,`line_reference`),
  KEY `idx_booking_services_booking` (`booking_id`),
  KEY `idx_booking_services_branch` (`branch_id`),
  KEY `idx_booking_services_type` (`service_type`),
  KEY `idx_booking_services_supplier` (`supplier_id`),
  KEY `idx_booking_services_currency` (`currency`),
  KEY `idx_booking_services_active` (`is_active`),
  KEY `fk_booking_services_created_by` (`created_by_user_id`),
  KEY `fk_booking_services_updated_by` (`updated_by_user_id`),
  CONSTRAINT `fk_booking_services_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_services_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_booking_services_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_services_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_services_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=607 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_travelers`
--

DROP TABLE IF EXISTS `booking_travelers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_travelers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `traveler_id` bigint(20) unsigned NOT NULL,
  `traveler_role` enum('lead','additional') NOT NULL DEFAULT 'additional',
  `display_order` int(10) unsigned NOT NULL DEFAULT 0,
  `attached_by_user_id` int(10) unsigned DEFAULT NULL,
  `attached_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_booking_traveler` (`booking_id`,`traveler_id`),
  KEY `idx_booking_travelers_booking` (`booking_id`),
  KEY `idx_booking_travelers_traveler` (`traveler_id`),
  KEY `idx_booking_travelers_role` (`traveler_role`),
  KEY `fk_booking_travelers_attached_by` (`attached_by_user_id`),
  CONSTRAINT `fk_booking_travelers_attached_by` FOREIGN KEY (`attached_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_travelers_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_travelers_traveler` FOREIGN KEY (`traveler_id`) REFERENCES `travelers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=720 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_reference` varchar(50) NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `lead_traveler_id` bigint(20) unsigned DEFAULT NULL,
  `booking_status` enum('draft','open','confirmed','on_hold','closed') NOT NULL DEFAULT 'draft',
  `booking_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_reference` (`booking_reference`),
  KEY `idx_bookings_branch` (`branch_id`),
  KEY `idx_bookings_status` (`booking_status`),
  KEY `idx_bookings_date` (`booking_date`),
  KEY `fk_bookings_created_by` (`created_by_user_id`),
  KEY `fk_bookings_updated_by` (`updated_by_user_id`),
  KEY `fk_bookings_lead_traveler` (`lead_traveler_id`),
  CONSTRAINT `fk_bookings_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_bookings_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_lead_traveler` FOREIGN KEY (`lead_traveler_id`) REFERENCES `travelers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=559 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(190) NOT NULL,
  `city` varchar(120) DEFAULT NULL,
  `country_code` char(2) NOT NULL,
  `base_currency` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `business_expenses`
--

DROP TABLE IF EXISTS `business_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `business_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `expense_category_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `paid_to_name` varchar(190) DEFAULT NULL,
  `reference_number` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `expense_status` varchar(20) NOT NULL DEFAULT 'posted',
  `entered_by_user_id` int(10) unsigned NOT NULL,
  `updated_by_user_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_business_expenses_date` (`expense_date`),
  KEY `idx_business_expenses_branch` (`branch_id`),
  KEY `idx_business_expenses_category` (`expense_category_id`),
  KEY `idx_business_expenses_currency` (`currency`),
  KEY `idx_business_expenses_status` (`expense_status`),
  KEY `fk_business_expenses_entered_by` (`entered_by_user_id`),
  KEY `fk_business_expenses_updated_by` (`updated_by_user_id`),
  CONSTRAINT `fk_business_expenses_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_business_expenses_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `fk_business_expenses_entered_by` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_business_expenses_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chart_of_accounts`
--

DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chart_of_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(190) NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `currencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) NOT NULL,
  `name` varchar(120) NOT NULL,
  `symbol` varchar(10) DEFAULT NULL,
  `reporting_role` varchar(190) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_currencies_active` (`is_active`),
  KEY `idx_currencies_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_receipt_allocations`
--

DROP TABLE IF EXISTS `customer_receipt_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_receipt_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_receipt_id` bigint(20) unsigned NOT NULL,
  `customer_receivable_item_id` bigint(20) unsigned NOT NULL,
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `receivable_currency` char(3) DEFAULT NULL,
  `receivable_amount_allocated` decimal(18,2) DEFAULT NULL,
  `payment_currency` char(3) DEFAULT NULL,
  `payment_amount_consumed` decimal(18,2) DEFAULT NULL,
  `allocation_note` varchar(190) DEFAULT NULL,
  `exchange_rate_used` decimal(18,8) DEFAULT NULL,
  `rate_from_currency` char(3) DEFAULT NULL,
  `rate_to_currency` char(3) DEFAULT NULL,
  `exchange_rate` decimal(18,8) DEFAULT NULL,
  `exchange_rate_effective_date` date DEFAULT NULL,
  `allocated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_receipt_allocations_receipt` (`customer_receipt_id`),
  KEY `idx_customer_receipt_allocations_receivable` (`customer_receivable_item_id`),
  KEY `fk_customer_receipt_allocations_user` (`created_by_user_id`),
  KEY `idx_customer_receipt_allocations_receivable_currency` (`receivable_currency`),
  KEY `idx_customer_receipt_allocations_payment_currency` (`payment_currency`),
  KEY `idx_customer_receipt_allocations_rate_effective_date` (`exchange_rate_effective_date`),
  CONSTRAINT `fk_customer_receipt_allocations_receipt` FOREIGN KEY (`customer_receipt_id`) REFERENCES `customer_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_receipt_allocations_receivable` FOREIGN KEY (`customer_receivable_item_id`) REFERENCES `customer_receivable_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_receipt_allocations_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=424 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_receipts`
--

DROP TABLE IF EXISTS `customer_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(50) NOT NULL,
  `receipt_no` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `received_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unallocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank_transfer','debit_card','credit_card') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `bank_card_detail` varchar(190) DEFAULT NULL,
  `charges_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('received','partially_allocated','fully_allocated','void') NOT NULL DEFAULT 'received',
  `exchange_rate_to_booking` decimal(18,8) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `void_reason` text DEFAULT NULL,
  `voided_by_user_id` int(10) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `reversal_reference` varchar(100) DEFAULT NULL,
  `reversal_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  KEY `idx_customer_receipts_branch` (`branch_id`),
  KEY `idx_customer_receipts_booking` (`booking_reference`),
  KEY `idx_customer_receipts_date` (`receipt_date`),
  KEY `idx_customer_receipts_status` (`status`),
  KEY `fk_customer_receipts_user` (`created_by_user_id`),
  KEY `idx_customer_receipts_voided_by_user` (`voided_by_user_id`),
  KEY `idx_customer_receipts_voided_at` (`voided_at`),
  KEY `idx_customer_receipts_reversal_reference` (`reversal_reference`),
  KEY `idx_customer_receipts_reversal_journal` (`reversal_journal_entry_id`),
  CONSTRAINT `fk_customer_receipts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_customer_receipts_reversal_journal` FOREIGN KEY (`reversal_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customer_receipts_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customer_receipts_voided_by_user` FOREIGN KEY (`voided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=429 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_receivable_items`
--

DROP TABLE IF EXISTS `customer_receivable_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_receivable_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(50) NOT NULL,
  `service_line_reference` varchar(50) DEFAULT NULL,
  `due_group` varchar(50) NOT NULL DEFAULT 'service_sale',
  `currency` char(3) NOT NULL,
  `due_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `outstanding_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('open','partially_paid','paid','cancelled') NOT NULL DEFAULT 'open',
  `remarks` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer_receivable_items_branch` (`branch_id`),
  KEY `idx_customer_receivable_items_booking` (`booking_reference`),
  KEY `idx_customer_receivable_items_service_line` (`service_line_reference`),
  KEY `idx_customer_receivable_items_status` (`status`),
  KEY `fk_customer_receivable_items_user` (`created_by_user_id`),
  CONSTRAINT `fk_customer_receivable_items_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_customer_receivable_items_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=566 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_types`
--

DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `linked_area` varchar(120) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_document_types_active` (`is_active`),
  KEY `idx_document_types_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange_rates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_currency` varchar(10) NOT NULL,
  `to_currency` varchar(10) NOT NULL,
  `rate_value` decimal(18,8) NOT NULL,
  `effective_date` date NOT NULL,
  `rate_source` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_exchange_rate_effective` (`from_currency`,`to_currency`,`effective_date`),
  KEY `idx_exchange_rates_lookup` (`to_currency`,`from_currency`,`effective_date`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_attachments`
--

DROP TABLE IF EXISTS `expense_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_expense_id` bigint(20) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `stored_file_name` varchar(255) NOT NULL,
  `storage_disk` varchar(20) NOT NULL DEFAULT 'local',
  `storage_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_extension` varchar(20) NOT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `sha256_hash` char(64) NOT NULL,
  `visibility` varchar(20) NOT NULL DEFAULT 'private',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `replaced_attachment_id` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_by_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_expense_attachments_expense` (`business_expense_id`),
  KEY `idx_expense_attachments_branch` (`branch_id`),
  KEY `idx_expense_attachments_status` (`status`),
  KEY `idx_expense_attachments_hash` (`sha256_hash`),
  KEY `fk_expense_attachments_replaced` (`replaced_attachment_id`),
  KEY `fk_expense_attachments_uploaded_by` (`uploaded_by_user_id`),
  KEY `fk_expense_attachments_updated_by` (`updated_by_user_id`),
  KEY `fk_expense_attachments_revoked_by` (`revoked_by_user_id`),
  CONSTRAINT `fk_expense_attachments_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_expense_attachments_expense` FOREIGN KEY (`business_expense_id`) REFERENCES `business_expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expense_attachments_replaced` FOREIGN KEY (`replaced_attachment_id`) REFERENCES `expense_attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expense_attachments_revoked_by` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expense_attachments_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expense_attachments_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(50) DEFAULT NULL,
  `source_type` varchar(100) NOT NULL,
  `source_reference` varchar(100) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `narration` varchar(255) NOT NULL,
  `posted_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_entries_branch` (`branch_id`),
  KEY `idx_journal_entries_booking` (`booking_reference`),
  KEY `idx_journal_entries_source` (`source_type`,`source_reference`),
  KEY `fk_journal_entries_user` (`posted_by_user_id`),
  CONSTRAINT `fk_journal_entries_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_journal_entries_user` FOREIGN KEY (`posted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2437 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entry_lines`
--

DROP TABLE IF EXISTS `journal_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entry_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) unsigned NOT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `service_line_reference` varchar(50) DEFAULT NULL,
  `supplier_obligation_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_payment_id` bigint(20) unsigned DEFAULT NULL,
  `customer_receivable_item_id` bigint(20) unsigned DEFAULT NULL,
  `customer_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `line_description` varchar(255) DEFAULT NULL,
  `debit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_entry_lines_entry` (`journal_entry_id`),
  KEY `idx_journal_entry_lines_account` (`account_id`),
  KEY `idx_journal_entry_lines_service_line` (`service_line_reference`),
  KEY `fk_journal_entry_lines_supplier_obligation` (`supplier_obligation_id`),
  KEY `fk_journal_entry_lines_customer_receivable` (`customer_receivable_item_id`),
  KEY `fk_journal_entry_lines_customer_receipt` (`customer_receipt_id`),
  KEY `idx_journal_entry_lines_supplier_payment` (`supplier_payment_id`),
  CONSTRAINT `fk_journal_entry_lines_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `fk_journal_entry_lines_customer_receipt` FOREIGN KEY (`customer_receipt_id`) REFERENCES `customer_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_entry_lines_customer_receivable` FOREIGN KEY (`customer_receivable_item_id`) REFERENCES `customer_receivable_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_entry_lines_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_journal_entry_lines_supplier_obligation` FOREIGN KEY (`supplier_obligation_id`) REFERENCES `supplier_obligations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_entry_lines_supplier_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4973 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration_name` varchar(190) NOT NULL,
  `executed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_name` (`migration_name`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `offline_draft_syncs`
--

DROP TABLE IF EXISTS `offline_draft_syncs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offline_draft_syncs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `branch_id` int(10) unsigned DEFAULT NULL,
  `client_draft_id` varchar(100) NOT NULL,
  `draft_type` varchar(50) NOT NULL,
  `source_device` varchar(120) DEFAULT NULL,
  `status` enum('synced','rejected') NOT NULL,
  `server_record_type` varchar(50) DEFAULT NULL,
  `server_record_id` bigint(20) unsigned DEFAULT NULL,
  `server_reference` varchar(100) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_offline_draft_user_client` (`user_id`,`client_draft_id`),
  KEY `idx_offline_draft_branch` (`branch_id`),
  KEY `idx_offline_draft_status` (`status`),
  CONSTRAINT `fk_offline_draft_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offline_draft_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(64) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `requested_by_ip` varchar(64) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_password_reset_user` (`user_id`),
  KEY `idx_password_reset_expiry` (`expires_at`),
  CONSTRAINT `fk_password_reset_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `ledger_target` varchar(120) NOT NULL,
  `charges_target` varchar(120) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_payment_methods_active` (`is_active`),
  KEY `idx_payment_methods_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `posting_rules`
--

DROP TABLE IF EXISTS `posting_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `posting_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `event_name` varchar(190) NOT NULL,
  `source_area` varchar(120) NOT NULL,
  `financial_effect` varchar(255) NOT NULL,
  `debit_account_id` int(10) unsigned NOT NULL,
  `credit_account_id` int(10) unsigned NOT NULL,
  `rule_note` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `idx_posting_rules_active` (`is_active`),
  KEY `idx_posting_rules_order` (`sort_order`),
  KEY `fk_posting_rules_debit_account` (`debit_account_id`),
  KEY `fk_posting_rules_credit_account` (`credit_account_id`),
  CONSTRAINT `fk_posting_rules_credit_account` FOREIGN KEY (`credit_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `fk_posting_rules_debit_account` FOREIGN KEY (`debit_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_permission` (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_throttle_events`
--

DROP TABLE IF EXISTS `security_throttle_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_throttle_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action_name` varchar(100) NOT NULL,
  `subject_key` varchar(190) NOT NULL,
  `ip_address` varchar(64) NOT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_security_throttle_lookup` (`action_name`,`subject_key`,`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_air_ticket`
--

DROP TABLE IF EXISTS `service_air_ticket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_air_ticket` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `pnr` varchar(50) DEFAULT NULL,
  `ticket_number` varchar(50) DEFAULT NULL,
  `airline` varchar(120) DEFAULT NULL,
  `sector_from` varchar(120) DEFAULT NULL,
  `sector_to` varchar(120) DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `travel_class` varchar(50) DEFAULT NULL,
  `fare` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ticket_tax` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ticket_vat` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ticket_commission` decimal(18,2) NOT NULL DEFAULT 0.00,
  `supplier_cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `sale_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ticket_remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_air_ticket_service` (`booking_service_id`),
  CONSTRAINT `fk_service_air_ticket_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=523 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_hotel`
--

DROP TABLE IF EXISTS `service_hotel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_hotel` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `hotel_name` varchar(190) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `confirmation_number` varchar(120) DEFAULT NULL,
  `check_in_date` date DEFAULT NULL,
  `check_out_date` date DEFAULT NULL,
  `room_type` varchar(120) DEFAULT NULL,
  `guest_count` int(10) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_hotel_service` (`booking_service_id`),
  CONSTRAINT `fk_service_hotel_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_other`
--

DROP TABLE IF EXISTS `service_other`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_other` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `reference_number` varchar(120) DEFAULT NULL,
  `service_date` date DEFAULT NULL,
  `provider_name` varchar(190) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_other_service` (`booking_service_id`),
  CONSTRAINT `fk_service_other_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_tour`
--

DROP TABLE IF EXISTS `service_tour`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_tour` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `tour_name` varchar(190) DEFAULT NULL,
  `destination` varchar(120) DEFAULT NULL,
  `confirmation_number` varchar(120) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `inclusions` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_tour_service` (`booking_service_id`),
  CONSTRAINT `fk_service_tour_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_transport`
--

DROP TABLE IF EXISTS `service_transport`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_transport` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `transport_mode` varchar(120) DEFAULT NULL,
  `vehicle_type` varchar(120) DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `pickup_location` varchar(190) DEFAULT NULL,
  `dropoff_location` varchar(190) DEFAULT NULL,
  `driver_detail` varchar(190) DEFAULT NULL,
  `route_notes` varchar(190) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_transport_service` (`booking_service_id`),
  CONSTRAINT `fk_service_transport_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_types`
--

DROP TABLE IF EXISTS `service_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `posting_mode` varchar(190) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_service_types_active` (`is_active`),
  KEY `idx_service_types_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_umrah`
--

DROP TABLE IF EXISTS `service_umrah`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_umrah` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `package_name` varchar(190) DEFAULT NULL,
  `mofa_reference` varchar(120) DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `hotel_name` varchar(190) DEFAULT NULL,
  `transport_notes` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_umrah_service` (`booking_service_id`),
  CONSTRAINT `fk_service_umrah_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_visa`
--

DROP TABLE IF EXISTS `service_visa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_visa` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_service_id` bigint(20) unsigned NOT NULL,
  `visa_country` varchar(120) DEFAULT NULL,
  `visa_type` varchar(120) DEFAULT NULL,
  `application_reference` varchar(120) DEFAULT NULL,
  `passport_number` varchar(80) DEFAULT NULL,
  `submission_date` date DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `visa_status` varchar(80) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_visa_service` (`booking_service_id`),
  CONSTRAINT `fk_service_visa_service` FOREIGN KEY (`booking_service_id`) REFERENCES `booking_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_advance_applications`
--

DROP TABLE IF EXISTS `supplier_advance_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_advance_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_advance_id` bigint(20) unsigned NOT NULL,
  `supplier_obligation_id` bigint(20) unsigned NOT NULL,
  `applied_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_advance_obligation` (`supplier_advance_id`,`supplier_obligation_id`),
  KEY `idx_supplier_advance_applications_obligation` (`supplier_obligation_id`),
  KEY `fk_supplier_advance_applications_user` (`created_by_user_id`),
  CONSTRAINT `fk_supplier_advance_applications_advance` FOREIGN KEY (`supplier_advance_id`) REFERENCES `supplier_advances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_advance_applications_obligation` FOREIGN KEY (`supplier_obligation_id`) REFERENCES `supplier_obligations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_advance_applications_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_advances`
--

DROP TABLE IF EXISTS `supplier_advances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_advances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `deposit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `available_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `reference_no` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `received_at` date DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supplier_advances_supplier` (`supplier_id`),
  KEY `idx_supplier_advances_branch` (`branch_id`),
  KEY `idx_supplier_advances_currency` (`currency`),
  KEY `fk_supplier_advances_user` (`created_by_user_id`),
  CONSTRAINT `fk_supplier_advances_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_supplier_advances_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_advances_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_modes`
--

DROP TABLE IF EXISTS `supplier_modes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_modes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `behavior` varchar(190) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_supplier_modes_active` (`is_active`),
  KEY `idx_supplier_modes_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_obligations`
--

DROP TABLE IF EXISTS `supplier_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_obligations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(50) NOT NULL,
  `service_line_reference` varchar(50) DEFAULT NULL,
  `obligation_group` varchar(50) NOT NULL DEFAULT 'service_cost',
  `currency` char(3) NOT NULL,
  `gross_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `advance_applied_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `net_payable_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('open','partially_covered','covered_by_advance','paid','cancelled') NOT NULL DEFAULT 'open',
  `remarks` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supplier_obligations_supplier` (`supplier_id`),
  KEY `idx_supplier_obligations_branch` (`branch_id`),
  KEY `idx_supplier_obligations_booking` (`booking_reference`),
  KEY `idx_supplier_obligations_service_line` (`service_line_reference`),
  KEY `idx_supplier_obligations_status` (`status`),
  KEY `fk_supplier_obligations_user` (`created_by_user_id`),
  CONSTRAINT `fk_supplier_obligations_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_supplier_obligations_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_obligations_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=526 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_payment_allocations`
--

DROP TABLE IF EXISTS `supplier_payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_payment_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_payment_id` bigint(20) unsigned NOT NULL,
  `supplier_obligation_id` bigint(20) unsigned NOT NULL,
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allocation_note` varchar(190) DEFAULT NULL,
  `exchange_rate_used` decimal(18,8) DEFAULT NULL,
  `allocated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_supplier_payment_allocations_payment` (`supplier_payment_id`),
  KEY `idx_supplier_payment_allocations_obligation` (`supplier_obligation_id`),
  KEY `fk_supplier_payment_allocations_user` (`created_by_user_id`),
  CONSTRAINT `fk_supplier_payment_allocations_obligation` FOREIGN KEY (`supplier_obligation_id`) REFERENCES `supplier_obligations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_payment_allocations_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_payment_allocations_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_payments`
--

DROP TABLE IF EXISTS `supplier_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(50) NOT NULL,
  `payment_no` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `paid_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unallocated_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank_transfer','debit_card','credit_card') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `bank_card_detail` varchar(190) DEFAULT NULL,
  `charges_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('paid','partially_allocated','fully_allocated','void') NOT NULL DEFAULT 'paid',
  `exchange_rate_to_booking` decimal(18,8) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `void_reason` text DEFAULT NULL,
  `voided_by_user_id` int(10) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `reversal_reference` varchar(100) DEFAULT NULL,
  `reversal_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_no` (`payment_no`),
  KEY `idx_supplier_payments_supplier` (`supplier_id`),
  KEY `idx_supplier_payments_branch` (`branch_id`),
  KEY `idx_supplier_payments_booking` (`booking_reference`),
  KEY `idx_supplier_payments_status` (`status`),
  KEY `fk_supplier_payments_user` (`created_by_user_id`),
  KEY `idx_supplier_payments_voided_by_user` (`voided_by_user_id`),
  KEY `idx_supplier_payments_voided_at` (`voided_at`),
  KEY `idx_supplier_payments_reversal_reference` (`reversal_reference`),
  KEY `idx_supplier_payments_reversal_journal` (`reversal_journal_entry_id`),
  CONSTRAINT `fk_supplier_payments_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_supplier_payments_reversal_journal` FOREIGN KEY (`reversal_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supplier_payments_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_payments_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supplier_payments_voided_by_user` FOREIGN KEY (`voided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=84 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(190) NOT NULL,
  `supplier_mode` enum('normal_payable','running_balance') NOT NULL DEFAULT 'normal_payable',
  `default_currency` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_suppliers_branch` (`branch_id`),
  KEY `idx_suppliers_mode` (`supplier_mode`),
  CONSTRAINT `fk_suppliers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=307 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `travelers`
--

DROP TABLE IF EXISTS `travelers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `travelers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `full_name` varchar(190) NOT NULL,
  `passport_number` varchar(50) DEFAULT NULL,
  `nationality` varchar(120) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other','unspecified') NOT NULL DEFAULT 'unspecified',
  `passport_expiry` date DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `permanent_residence` varchar(255) DEFAULT NULL,
  `current_residence` varchar(255) DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `village` varchar(120) DEFAULT NULL,
  `district` varchar(120) DEFAULT NULL,
  `family_id` varchar(60) DEFAULT NULL,
  `color_tag` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_travelers_branch` (`branch_id`),
  KEY `idx_travelers_full_name` (`full_name`),
  KEY `idx_travelers_passport` (`passport_number`),
  KEY `idx_travelers_mobile` (`mobile`),
  KEY `fk_travelers_created_by` (`created_by_user_id`),
  KEY `fk_travelers_updated_by` (`updated_by_user_id`),
  CONSTRAINT `fk_travelers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_travelers_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travelers_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=304 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trusted_devices`
--

DROP TABLE IF EXISTS `trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(64) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `device_label` varchar(190) NOT NULL,
  `user_agent_hash` varchar(64) NOT NULL,
  `last_ip_address` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_trusted_devices_user` (`user_id`),
  KEY `idx_trusted_devices_expiry` (`expires_at`),
  CONSTRAINT `fk_trusted_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_branch_access`
--

DROP TABLE IF EXISTS `user_branch_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_branch_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `branch_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_branch` (`user_id`,`branch_id`),
  KEY `fk_uba_branch` (`branch_id`),
  CONSTRAINT `fk_uba_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uba_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_two_factor_recovery_codes`
--

DROP TABLE IF EXISTS `user_two_factor_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_two_factor_recovery_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_two_factor_recovery_codes_user` (`user_id`),
  CONSTRAINT `fk_user_two_factor_recovery_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `default_branch_id` int(10) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `force_password_change_reason` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `password_reset_required_at` datetime DEFAULT NULL,
  `session_version` int(10) unsigned NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_confirmed_at` datetime DEFAULT NULL,
  `two_factor_secret_encrypted` text DEFAULT NULL,
  `two_factor_secret_pending_encrypted` text DEFAULT NULL,
  `two_factor_setup_started_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uniq_users_username` (`username`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_branch` (`default_branch_id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'travel_agency_ops'
--

--
-- Dumping routines for database 'travel_agency_ops'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-22 17:16:20

-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 29, 2026 at 05:20 AM
-- Server version: 11.4.10-MariaDB
-- PHP Version: 8.4.19

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `k0o0jpy_prod`
--

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_invoice`
--

CREATE TABLE `wp_wc_invoice` (
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `suite_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_user_id` bigint(20) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `tenant_name_snapshot` varchar(255) NOT NULL,
  `attn` varchar(255) DEFAULT NULL,
  `square_footage_snapshot` decimal(10,2) DEFAULT NULL,
  `pro_rata` decimal(8,4) DEFAULT NULL,
  `pro_rata_portion` decimal(12,2) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('DRAFT','CONFIRMED','VOIDED','SENT') NOT NULL DEFAULT 'DRAFT',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_invoice_line_item`
--

CREATE TABLE `wp_wc_invoice_line_item` (
  `line_item_id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_type` enum('USAGE','FIXED_FEE','TAX','PRO_RATA','WATER_USAGE','ELECTRIC_USAGE') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_master_utility_bill`
--

CREATE TABLE `wp_wc_master_utility_bill` (
  `bill_id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `utility_account_id` bigint(20) UNSIGNED NOT NULL,
  `master_meter_id` bigint(20) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `status` enum('DRAFT','CONFIRMED','LOCKED') NOT NULL DEFAULT 'DRAFT',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_meter`
--

CREATE TABLE `wp_wc_meter` (
  `meter_id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `utility_account_id` bigint(20) UNSIGNED NOT NULL,
  `meter_number` varchar(100) NOT NULL,
  `meter_type` enum('MASTER','SUBMETER') NOT NULL,
  `utility_type` enum('ELECTRIC','WATER') NOT NULL,
  `meter_size` varchar(50) DEFAULT NULL,
  `meter_name` varchar(255) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_meter_hierarchy`
--

CREATE TABLE `wp_wc_meter_hierarchy` (
  `hierarchy_id` bigint(20) UNSIGNED NOT NULL,
  `upstream_meter_id` bigint(20) UNSIGNED NOT NULL,
  `downstream_meter_id` bigint(20) UNSIGNED NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_meter_responsibility`
--

CREATE TABLE `wp_wc_meter_responsibility` (
  `responsibility_id` bigint(20) UNSIGNED NOT NULL,
  `meter_id` bigint(20) UNSIGNED NOT NULL,
  `utility_account_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `suite_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `responsible_party_type` enum('TENANT','VACANT_WOODCREST','CAM') NOT NULL,
  `allocation_method` enum('ACTUAL','PRO_RATA_SF','FIXED_PERCENT') NOT NULL,
  `fixed_percent` decimal(5,2) DEFAULT NULL,
  `billing_model` enum('PASS_THROUGH','RESALE') NOT NULL,
  `monthly_charge_amount` decimal(12,2) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_payment`
--

CREATE TABLE `wp_wc_payment` (
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('ACH','CHECK','CASH','CARD','WIRE','OTHER') NOT NULL,
  `notes` text DEFAULT NULL,
  `entered_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_property`
--

CREATE TABLE `wp_wc_property` (
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `entity_name` varchar(255) NOT NULL,
  `property_name` varchar(255) NOT NULL,
  `address_line1` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(50) NOT NULL,
  `zip` varchar(20) NOT NULL,
  `timezone` varchar(100) NOT NULL DEFAULT 'America/Chicago',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_rate_schedule`
--

CREATE TABLE `wp_wc_rate_schedule` (
  `rate_schedule_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_user_id` bigint(20) UNSIGNED NOT NULL,
  `utility_type` enum('ELECTRIC','WATER') NOT NULL,
  `base_rate_per_unit` decimal(12,6) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_suite`
--

CREATE TABLE `wp_wc_suite` (
  `suite_id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `suite_label` varchar(100) NOT NULL,
  `square_feet` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('OCCUPIED','VACANT') NOT NULL DEFAULT 'VACANT',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_suite_occupancy`
--

CREATE TABLE `wp_wc_suite_occupancy` (
  `occupancy_id` bigint(20) UNSIGNED NOT NULL,
  `suite_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_user_id` bigint(20) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_user_property_access`
--

CREATE TABLE `wp_wc_user_property_access` (
  `access_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `access_scope` enum('PROPERTY_MANAGER','OWNER') NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_utility_account`
--

CREATE TABLE `wp_wc_utility_account` (
  `utility_account_id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `utility_type` enum('ELECTRIC','WATER') NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `esi_id` varchar(100) DEFAULT NULL,
  `utility_company` varchar(255) NOT NULL,
  `service_address` varchar(255) DEFAULT NULL,
  `account_description` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wp_wc_utility_reading`
--

CREATE TABLE `wp_wc_utility_reading` (
  `reading_id` bigint(20) UNSIGNED NOT NULL,
  `meter_id` bigint(20) UNSIGNED NOT NULL,
  `reading_date` date NOT NULL,
  `current_read` decimal(14,4) NOT NULL,
  `previous_read` decimal(14,4) NOT NULL,
  `usage_amount` decimal(14,4) NOT NULL,
  `usage_unit` varchar(20) NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `entered_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `wp_wc_invoice`
--
ALTER TABLE `wp_wc_invoice`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `uq_wc_invoice_number` (`invoice_number`),
  ADD KEY `idx_wc_invoice_property` (`property_id`),
  ADD KEY `idx_wc_invoice_suite` (`suite_id`),
  ADD KEY `idx_wc_invoice_tenant` (`tenant_user_id`),
  ADD KEY `idx_wc_invoice_period` (`period_start`,`period_end`),
  ADD KEY `idx_wc_invoice_confirmed_by` (`confirmed_by_user_id`);

--
-- Indexes for table `wp_wc_invoice_line_item`
--
ALTER TABLE `wp_wc_invoice_line_item`
  ADD PRIMARY KEY (`line_item_id`),
  ADD KEY `idx_wc_invoice_line_item_invoice` (`invoice_id`);

--
-- Indexes for table `wp_wc_master_utility_bill`
--
ALTER TABLE `wp_wc_master_utility_bill`
  ADD PRIMARY KEY (`bill_id`),
  ADD KEY `idx_wc_master_bill_property` (`property_id`),
  ADD KEY `idx_wc_master_bill_account` (`utility_account_id`),
  ADD KEY `idx_wc_master_bill_meter` (`master_meter_id`),
  ADD KEY `idx_wc_master_bill_period` (`period_start`,`period_end`),
  ADD KEY `idx_wc_master_bill_user` (`created_by_user_id`);

--
-- Indexes for table `wp_wc_meter`
--
ALTER TABLE `wp_wc_meter`
  ADD PRIMARY KEY (`meter_id`),
  ADD UNIQUE KEY `uq_wc_meter_account_number` (`utility_account_id`,`meter_number`),
  ADD KEY `idx_wc_meter_property` (`property_id`),
  ADD KEY `idx_wc_meter_account` (`utility_account_id`),
  ADD KEY `idx_wc_meter_type` (`meter_type`);

--
-- Indexes for table `wp_wc_meter_hierarchy`
--
ALTER TABLE `wp_wc_meter_hierarchy`
  ADD PRIMARY KEY (`hierarchy_id`),
  ADD UNIQUE KEY `uq_wc_meter_hierarchy_pair` (`upstream_meter_id`,`downstream_meter_id`,`start_date`),
  ADD KEY `idx_wc_meter_hierarchy_upstream` (`upstream_meter_id`),
  ADD KEY `idx_wc_meter_hierarchy_downstream` (`downstream_meter_id`);

--
-- Indexes for table `wp_wc_meter_responsibility`
--
ALTER TABLE `wp_wc_meter_responsibility`
  ADD PRIMARY KEY (`responsibility_id`),
  ADD KEY `idx_wc_meter_responsibility_meter` (`meter_id`),
  ADD KEY `idx_wc_meter_responsibility_account` (`utility_account_id`),
  ADD KEY `idx_wc_meter_responsibility_user` (`tenant_user_id`),
  ADD KEY `idx_wc_meter_responsibility_suite` (`suite_id`),
  ADD KEY `idx_wc_meter_responsibility_dates` (`start_date`,`end_date`);

--
-- Indexes for table `wp_wc_payment`
--
ALTER TABLE `wp_wc_payment`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_wc_payment_invoice` (`invoice_id`),
  ADD KEY `idx_wc_payment_date` (`payment_date`),
  ADD KEY `idx_wc_payment_user` (`entered_by_user_id`);

--
-- Indexes for table `wp_wc_property`
--
ALTER TABLE `wp_wc_property`
  ADD PRIMARY KEY (`property_id`),
  ADD KEY `idx_property_name` (`property_name`),
  ADD KEY `idx_property_city_state` (`city`,`state`);

--
-- Indexes for table `wp_wc_rate_schedule`
--
ALTER TABLE `wp_wc_rate_schedule`
  ADD PRIMARY KEY (`rate_schedule_id`),
  ADD KEY `idx_wc_rate_schedule_user` (`tenant_user_id`),
  ADD KEY `idx_wc_rate_schedule_dates` (`start_date`,`end_date`);

--
-- Indexes for table `wp_wc_suite`
--
ALTER TABLE `wp_wc_suite`
  ADD PRIMARY KEY (`suite_id`),
  ADD UNIQUE KEY `uq_suite_property_label` (`property_id`,`suite_label`),
  ADD KEY `idx_suite_property` (`property_id`);

--
-- Indexes for table `wp_wc_suite_occupancy`
--
ALTER TABLE `wp_wc_suite_occupancy`
  ADD PRIMARY KEY (`occupancy_id`),
  ADD KEY `idx_wc_suite_occupancy_suite` (`suite_id`),
  ADD KEY `idx_wc_suite_occupancy_user` (`tenant_user_id`),
  ADD KEY `idx_wc_suite_occupancy_dates` (`start_date`,`end_date`);

--
-- Indexes for table `wp_wc_user_property_access`
--
ALTER TABLE `wp_wc_user_property_access`
  ADD PRIMARY KEY (`access_id`),
  ADD UNIQUE KEY `uq_wc_user_property_access` (`user_id`,`property_id`,`access_scope`),
  ADD KEY `idx_wc_user_property_access_user` (`user_id`),
  ADD KEY `idx_wc_user_property_access_property` (`property_id`);

--
-- Indexes for table `wp_wc_utility_account`
--
ALTER TABLE `wp_wc_utility_account`
  ADD PRIMARY KEY (`utility_account_id`),
  ADD KEY `idx_wc_utility_account_property` (`property_id`),
  ADD KEY `idx_wc_utility_account_number` (`account_number`),
  ADD KEY `idx_wc_utility_account_type` (`utility_type`);

--
-- Indexes for table `wp_wc_utility_reading`
--
ALTER TABLE `wp_wc_utility_reading`
  ADD PRIMARY KEY (`reading_id`),
  ADD UNIQUE KEY `uq_wc_meter_reading_date` (`meter_id`,`reading_date`),
  ADD KEY `idx_wc_utility_reading_meter` (`meter_id`),
  ADD KEY `idx_wc_utility_reading_date` (`reading_date`),
  ADD KEY `idx_wc_utility_reading_user` (`entered_by_user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `wp_wc_invoice`
--
ALTER TABLE `wp_wc_invoice`
  MODIFY `invoice_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_invoice_line_item`
--
ALTER TABLE `wp_wc_invoice_line_item`
  MODIFY `line_item_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_master_utility_bill`
--
ALTER TABLE `wp_wc_master_utility_bill`
  MODIFY `bill_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_meter`
--
ALTER TABLE `wp_wc_meter`
  MODIFY `meter_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_meter_hierarchy`
--
ALTER TABLE `wp_wc_meter_hierarchy`
  MODIFY `hierarchy_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_meter_responsibility`
--
ALTER TABLE `wp_wc_meter_responsibility`
  MODIFY `responsibility_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_payment`
--
ALTER TABLE `wp_wc_payment`
  MODIFY `payment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_property`
--
ALTER TABLE `wp_wc_property`
  MODIFY `property_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_rate_schedule`
--
ALTER TABLE `wp_wc_rate_schedule`
  MODIFY `rate_schedule_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_suite`
--
ALTER TABLE `wp_wc_suite`
  MODIFY `suite_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_suite_occupancy`
--
ALTER TABLE `wp_wc_suite_occupancy`
  MODIFY `occupancy_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_user_property_access`
--
ALTER TABLE `wp_wc_user_property_access`
  MODIFY `access_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_utility_account`
--
ALTER TABLE `wp_wc_utility_account`
  MODIFY `utility_account_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wp_wc_utility_reading`
--
ALTER TABLE `wp_wc_utility_reading`
  MODIFY `reading_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `wp_wc_invoice`
--
ALTER TABLE `wp_wc_invoice`
  ADD CONSTRAINT `fk_wc_invoice_confirmed_by` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_invoice_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_invoice_suite` FOREIGN KEY (`suite_id`) REFERENCES `wp_wc_suite` (`suite_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_invoice_tenant` FOREIGN KEY (`tenant_user_id`) REFERENCES `wp_users` (`ID`) ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_invoice_line_item`
--
ALTER TABLE `wp_wc_invoice_line_item`
  ADD CONSTRAINT `fk_wc_invoice_line_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `wp_wc_invoice` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_master_utility_bill`
--
ALTER TABLE `wp_wc_master_utility_bill`
  ADD CONSTRAINT `fk_wc_master_bill_account` FOREIGN KEY (`utility_account_id`) REFERENCES `wp_wc_utility_account` (`utility_account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_master_bill_meter` FOREIGN KEY (`master_meter_id`) REFERENCES `wp_wc_meter` (`meter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_master_bill_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_master_bill_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `wp_users` (`ID`) ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_meter`
--
ALTER TABLE `wp_wc_meter`
  ADD CONSTRAINT `fk_wc_meter_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_meter_utility_account` FOREIGN KEY (`utility_account_id`) REFERENCES `wp_wc_utility_account` (`utility_account_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_meter_hierarchy`
--
ALTER TABLE `wp_wc_meter_hierarchy`
  ADD CONSTRAINT `fk_wc_meter_hierarchy_downstream` FOREIGN KEY (`downstream_meter_id`) REFERENCES `wp_wc_meter` (`meter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_meter_hierarchy_upstream` FOREIGN KEY (`upstream_meter_id`) REFERENCES `wp_wc_meter` (`meter_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_meter_responsibility`
--
ALTER TABLE `wp_wc_meter_responsibility`
  ADD CONSTRAINT `fk_wc_meter_responsibility_account` FOREIGN KEY (`utility_account_id`) REFERENCES `wp_wc_utility_account` (`utility_account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_meter_responsibility_meter` FOREIGN KEY (`meter_id`) REFERENCES `wp_wc_meter` (`meter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_meter_responsibility_suite` FOREIGN KEY (`suite_id`) REFERENCES `wp_wc_suite` (`suite_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_meter_responsibility_user` FOREIGN KEY (`tenant_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_payment`
--
ALTER TABLE `wp_wc_payment`
  ADD CONSTRAINT `fk_wc_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `wp_wc_invoice` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_payment_user` FOREIGN KEY (`entered_by_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_rate_schedule`
--
ALTER TABLE `wp_wc_rate_schedule`
  ADD CONSTRAINT `fk_wc_rate_schedule_user` FOREIGN KEY (`tenant_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_suite`
--
ALTER TABLE `wp_wc_suite`
  ADD CONSTRAINT `fk_wc_suite_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_suite_occupancy`
--
ALTER TABLE `wp_wc_suite_occupancy`
  ADD CONSTRAINT `fk_wc_suite_occupancy_suite` FOREIGN KEY (`suite_id`) REFERENCES `wp_wc_suite` (`suite_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_suite_occupancy_user` FOREIGN KEY (`tenant_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_user_property_access`
--
ALTER TABLE `wp_wc_user_property_access`
  ADD CONSTRAINT `fk_wc_user_property_access_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_user_property_access_user` FOREIGN KEY (`user_id`) REFERENCES `wp_users` (`ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_utility_account`
--
ALTER TABLE `wp_wc_utility_account`
  ADD CONSTRAINT `fk_wc_utility_account_property` FOREIGN KEY (`property_id`) REFERENCES `wp_wc_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wp_wc_utility_reading`
--
ALTER TABLE `wp_wc_utility_reading`
  ADD CONSTRAINT `fk_wc_utility_reading_meter` FOREIGN KEY (`meter_id`) REFERENCES `wp_wc_meter` (`meter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_wc_utility_reading_user` FOREIGN KEY (`entered_by_user_id`) REFERENCES `wp_users` (`ID`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

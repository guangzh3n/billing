-- BillingPro Database Schema
-- Malaysia e-Invoice (MyInvois) compatible billing system

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `tin` VARCHAR(20) DEFAULT NULL COMMENT 'Tax Identification Number for MyInvois',
  `id_type` ENUM('NRIC','PASSPORT','BRN','ARMY') DEFAULT 'BRN',
  `id_number` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `address` TEXT,
  `city` VARCHAR(100) DEFAULT NULL,
  `postcode` VARCHAR(10) DEFAULT NULL,
  `state` VARCHAR(50) DEFAULT NULL,
  `country` VARCHAR(50) DEFAULT 'Malaysia',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products / Services
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_type` VARCHAR(10) NOT NULL DEFAULT 'E' COMMENT 'E=Exempt, 02=Service Tax, 01=Sales Tax, OE=Out of Scope',
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `classification` VARCHAR(20) NOT NULL DEFAULT '022' COMMENT 'MyInvois classification code',
  `unit` VARCHAR(20) NOT NULL DEFAULT 'UNIT',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `invoice_type` VARCHAR(5) NOT NULL DEFAULT '01' COMMENT '01=Invoice 02=Credit Note 03=Debit Note',
  `issue_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `einvoice_status` ENUM('none','pending','valid','invalid','cancelled','rejected') NOT NULL DEFAULT 'none',
  `einvoice_uuid` VARCHAR(200) DEFAULT NULL,
  `einvoice_long_id` VARCHAR(500) DEFAULT NULL,
  `einvoice_submission_uid` VARCHAR(200) DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'MYR',
  `notes` TEXT,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice line items
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'UNIT',
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_type` VARCHAR(10) NOT NULL DEFAULT 'E',
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `classification` VARCHAR(20) NOT NULL DEFAULT '022',
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` ENUM('cash','bank_transfer','cheque','credit_card','fpx','duitnow','other') NOT NULL DEFAULT 'bank_transfer',
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'My Company Sdn. Bhd.'),
('company_tin', ''),
('company_id_type', 'BRN'),
('company_id_number', ''),
('company_email', ''),
('company_phone', ''),
('company_address', ''),
('company_city', 'Kuala Lumpur'),
('company_postcode', '50000'),
('company_state', 'Kuala Lumpur'),
('company_country', 'Malaysia'),
('company_bank', ''),
('company_bank_account', ''),
('company_bank_holder', ''),
('invoice_prefix', 'INV'),
('invoice_next_number', '1'),
('currency', 'MYR'),
('tax_label', 'SST'),
('service_tax_rate', '8.00'),
('einvoice_env', 'sandbox'),
('einvoice_client_id', ''),
('einvoice_client_secret', ''),
('einvoice_enabled', '0');

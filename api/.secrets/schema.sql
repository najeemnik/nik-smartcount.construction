-- =====================================================================
-- NIK SMART COUNT (NSC) — AUTHORITATIVE MYSQL DATABASE SCHEMA (Phase 11)
-- Compatible with MySQL 5.7+, MySQL 8.x & MariaDB 10.3+ (cPanel / phpMyAdmin)
-- Character Set: utf8mb4 (Full Persian Dari, Pashto & English Support)
-- Safe Non-Destructive Schema: Uses CREATE TABLE IF NOT EXISTS
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- 1. مشخصات شرکت (Company Profiles)
CREATE TABLE IF NOT EXISTS `company_profiles` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `company_name` VARCHAR(255) NOT NULL,
  `registration_no` VARCHAR(100) NULL,
  `tax_tin_number` VARCHAR(100) NULL,
  `primary_currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `secondary_currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `usd_to_afn_rate` DECIMAL(12, 4) NOT NULL DEFAULT 70.0000,
  `office_phone` VARCHAR(50) NULL,
  `contact_email` VARCHAR(150) NULL,
  `official_address` TEXT NULL,
  `logo_url` LONGTEXT NULL,
  `invoice_header_note` TEXT NULL,
  `invoice_footer_terms` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_company_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. کاربران، احراز هویت و دسترسی‌های دفتر هفت (Users & RBAC)
CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `owner_admin_id` VARCHAR(64) NULL COMMENT 'شناسه مالک شرکت برای کارمندان',
  `name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(80) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `hash_salt` VARCHAR(80) NULL COMMENT 'نمک هش رمز عبور جهت سازگاری چنددستگاهی',
  `role` ENUM('master', 'admin', 'project_manager', 'accountant', 'procurement', 'viewer') NOT NULL DEFAULT 'viewer',
  `phone` VARCHAR(40) NULL,
  `company_name` VARCHAR(200) NULL,
  `company_address` TEXT NULL,
  `avatar_url` TEXT NULL,
  `subscription_plan` VARCHAR(32) NOT NULL DEFAULT 'pro',
  `subscription_expires_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_master` TINYINT(1) NOT NULL DEFAULT 0,
  `ai_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `token_version` INT NOT NULL DEFAULT 0,
  `permissions_json` LONGTEXT NULL,
  `allowed_modules_json` LONGTEXT NULL,
  `allowed_tabs_json` LONGTEXT NULL,
  `app_profile_json` LONGTEXT NULL,
  `last_login_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_owner` (`owner_admin_id`),
  INDEX `idx_users_active_locked` (`is_active`, `is_locked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. پیکربندی دفتر هفت (Office 7 Master Control)
CREATE TABLE IF NOT EXISTS `office7_tenant_configs` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_user_id` VARCHAR(64) NOT NULL UNIQUE,
  `gate_key_hash` VARCHAR(255) NULL,
  `ai_assistant_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `cloud_sync_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `max_allowed_projects` INT NOT NULL DEFAULT 100,
  `max_allowed_staff` INT NOT NULL DEFAULT 50,
  `active_modules_json` LONGTEXT NOT NULL,
  `accessible_tabs_json` LONGTEXT NOT NULL,
  `updated_by` VARCHAR(64) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ثبت تلاش‌های ورود ناموفق جهت جلوگیری از Brute-Force
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(150) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_login_attempts_ident_time` (`identifier`, `attempted_at`),
  INDEX `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. لاگ امنیتی و ردپای حسابرسی سیستم (System Audit Logs)
CREATE TABLE IF NOT EXISTS `system_audit_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` VARCHAR(64) NULL,
  `user_name` VARCHAR(150) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `module` VARCHAR(80) NOT NULL,
  `record_id` VARCHAR(100) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `details` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_module_action` (`module`, `action`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. پروژه‌های ساختمانی (Projects)
CREATE TABLE IF NOT EXISTS `projects` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `client_name` VARCHAR(255) NULL,
  `contract_value` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `approved_budget` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `location` VARCHAR(255) NULL,
  `start_date` DATE NULL,
  `expected_finish` DATE NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `project_manager` VARCHAR(150) NULL,
  `total_area_sqm` DECIMAL(12, 2) NULL,
  `floors_count` INT NULL DEFAULT 1,
  `units_count` INT NULL DEFAULT 1,
  `progress_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `created_by` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_projects_tenant` (`tenant_id`),
  INDEX `idx_projects_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. بودجه سرفصل‌های پروژه (Project Budgets)
CREATE TABLE IF NOT EXISTS `project_budgets` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `category_code` VARCHAR(50) NOT NULL,
  `category_name` VARCHAR(150) NOT NULL,
  `budgeted_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `alert_threshold_percent` DECIMAL(5, 2) NOT NULL DEFAULT 80.00,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pb_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. حساب‌های خزانه‌داری، بانک و صندوق (Treasury Accounts)
CREATE TABLE IF NOT EXISTS `treasury_accounts` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_type` ENUM('bank', 'cash', 'petty_cash', 'sarafi') NOT NULL DEFAULT 'cash',
  `bank_name` VARCHAR(150) NULL,
  `account_number` VARCHAR(100) NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `opening_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `custodian_name` VARCHAR(150) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ta_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. اسناد عواید و دریافت‌ها (Revenue Entries)
CREATE TABLE IF NOT EXISTS `revenue_entries` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `receipt_number` VARCHAR(60) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `client_id` VARCHAR(64) NULL,
  `received_from` VARCHAR(200) NOT NULL,
  `income_type` VARCHAR(60) NOT NULL DEFAULT 'client_installment',
  `amount` DECIMAL(18, 2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12, 4) NOT NULL DEFAULT 1.0000,
  `amount_in_base_afn` DECIMAL(18, 2) NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
  `deposit_account_id` VARCHAR(64) NULL,
  `reference_document_no` VARCHAR(100) NULL,
  `received_date` DATE NOT NULL,
  `recorded_by_user_id` VARCHAR(64) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_rev_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_rev_date` (`received_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. اسناد پرداخت و مصارف (Payment Vouchers)
CREATE TABLE IF NOT EXISTS `payment_vouchers` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `voucher_no` VARCHAR(60) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `payee_type` VARCHAR(50) NOT NULL DEFAULT 'supplier',
  `payee_id` VARCHAR(64) NULL,
  `payee_name` VARCHAR(200) NOT NULL,
  `expense_category` VARCHAR(80) NOT NULL DEFAULT 'general',
  `budget_category_id` VARCHAR(64) NULL,
  `amount` DECIMAL(18, 2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12, 4) NOT NULL DEFAULT 1.0000,
  `amount_in_base_afn` DECIMAL(18, 2) NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
  `source_account_id` VARCHAR(64) NULL,
  `invoice_or_bill_no` VARCHAR(100) NULL,
  `payment_date` DATE NOT NULL,
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'paid',
  `approved_by` VARCHAR(150) NULL,
  `recorded_by_user_id` VARCHAR(64) NULL,
  `description` TEXT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pv_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_pv_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. انتقالات بین‌پروژه‌ای (Inter-Project Transfers)
CREATE TABLE IF NOT EXISTS `project_transfers` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `transfer_number` VARCHAR(60) NOT NULL,
  `from_project_id` VARCHAR(64) NOT NULL,
  `to_project_id` VARCHAR(64) NOT NULL,
  `transfer_nature` VARCHAR(50) NOT NULL DEFAULT 'funds',
  `amount_or_value` DECIMAL(18, 2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `from_treasury_account_id` VARCHAR(64) NULL,
  `to_treasury_account_id` VARCHAR(64) NULL,
  `transfer_date` DATE NOT NULL,
  `approved_by` VARCHAR(150) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'approved',
  `description` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pt_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. تأمین‌کنندگان و حافظه نرخ اجناس (Suppliers & Rate Memory)
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `supplier_name` VARCHAR(200) NOT NULL,
  `contact_person` VARCHAR(150) NULL,
  `phone` VARCHAR(50) NULL,
  `address` TEXT NULL,
  `material_category` VARCHAR(100) NULL DEFAULT 'عمومی',
  `tax_id` VARCHAR(80) NULL,
  `opening_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `total_purchases` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `total_paid` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `current_payable_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sup_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_product_rates` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `project_id` VARCHAR(64) NULL,
  `supplier_id` VARCHAR(64) NULL,
  `supplier_name` VARCHAR(200) NOT NULL,
  `product` VARCHAR(200) NOT NULL,
  `unit` VARCHAR(60) NOT NULL,
  `last_agreed_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `effective_date` DATE NOT NULL,
  `rate_key` VARCHAR(512) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_spr_tenant` (`tenant_id`),
  INDEX `idx_spr_supplier` (`supplier_id`, `supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. مقاطعه‌کاران / قراردادی‌ها (Subcontractors)
CREATE TABLE IF NOT EXISTS `subcontractors` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `contractor_name` VARCHAR(200) NOT NULL,
  `trade_specialty` VARCHAR(150) NOT NULL,
  `work_category` VARCHAR(150) NULL,
  `phone` VARCHAR(50) NULL,
  `project_id` VARCHAR(64) NULL,
  `contract_date` DATE NULL,
  `unit` VARCHAR(60) NULL DEFAULT 'm²',
  `custom_unit` VARCHAR(80) NULL,
  `agreed_unit_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `original_agreed_unit_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `contracted_quantity` DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
  `contract_total_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12, 4) NOT NULL DEFAULT 70.0000,
  `contract_value` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `certified_work_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `retention_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `remaining_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `payment_terms` TEXT NULL,
  `rate_history_json` LONGTEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sub_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. تدارکات مواد و سیخ‌گول (Materials & Steel Rebar)
CREATE TABLE IF NOT EXISTS `material_procurements` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `procurement_code` VARCHAR(60) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `supplier_id` VARCHAR(64) NULL,
  `supplier_name` VARCHAR(200) NOT NULL,
  `material_category` VARCHAR(100) NOT NULL,
  `item_description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(18, 4) NOT NULL,
  `unit_of_measure` VARCHAR(50) NOT NULL,
  `unit_price` DECIMAL(18, 2) NOT NULL,
  `total_cost` DECIMAL(18, 2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12, 4) NOT NULL DEFAULT 1.0000,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_voucher_id` VARCHAR(64) NULL,
  `purchase_date` DATE NOT NULL,
  `received_by` VARCHAR(200) NULL,
  `invoice_no` VARCHAR(100) NULL,
  `bill_invoice_no` VARCHAR(100) NULL,
  `estimated_total_kg` DECIMAL(18, 3) NULL,
  `estimated_total_ton` DECIMAL(18, 4) NULL,
  `actual_total_ton` DECIMAL(18, 4) NULL,
  `confirmed_total_ton` DECIMAL(18, 4) NULL,
  `line_items_json` LONGTEXT NULL,
  `edit_history_json` LONGTEXT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_mp_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rebar_spec_memory` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `project_id` VARCHAR(64) NULL,
  `tenant_id` VARCHAR(64) NULL,
  `diameter_mm` DECIMAL(10, 2) NOT NULL,
  `length_m` DECIMAL(10, 2) NOT NULL DEFAULT 12.00,
  `brand_spec` VARCHAR(150) NOT NULL DEFAULT 'عمومی',
  `weight_per_bar_kg` DECIMAL(12, 4) NOT NULL,
  `original_weight_per_bar_kg` DECIMAL(12, 4) NOT NULL,
  `bars_per_bundle` INT NULL,
  `spec_key` VARCHAR(255) NOT NULL UNIQUE,
  `weight_history_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. کارمندان و لیست معاشات (Employees & Labor Payroll)
CREATE TABLE IF NOT EXISTS `employees` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `assigned_project_ids_json` LONGTEXT NULL,
  `full_name` VARCHAR(200) NOT NULL,
  `phone` VARCHAR(50) NULL,
  `secondary_phone` VARCHAR(50) NULL,
  `contact_info` VARCHAR(255) NULL,
  `job_position` VARCHAR(150) NOT NULL,
  `employment_start_date` DATE NOT NULL,
  `employment_end_date` DATE NULL,
  `daily_start_time` VARCHAR(20) NULL,
  `daily_end_time` VARCHAR(20) NULL,
  `pay_type` VARCHAR(40) NOT NULL DEFAULT 'monthly',
  `custom_period_label` VARCHAR(100) NULL,
  `custom_period_days` INT NULL,
  `agreed_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `effective_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_emp_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `labor_payroll` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `payroll_number` VARCHAR(60) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `worker_or_team_leader` VARCHAR(200) NOT NULL,
  `work_type_description` VARCHAR(200) NOT NULL,
  `days_or_work_units` DECIMAL(12, 2) NOT NULL,
  `rate_per_unit` DECIMAL(18, 2) NOT NULL,
  `gross_amount` DECIMAL(18, 2) NOT NULL,
  `advance_deduction` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `net_amount_paid` DECIMAL(18, 2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `payment_voucher_id` VARCHAR(64) NULL,
  `payroll_date` DATE NOT NULL,
  `approved_by` VARCHAR(150) NULL,
  `is_fully_settled` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lp_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. واحدهای اپارتمان، دارایی‌ها و روزنامچه (Apartments, Assets & Journal)
CREATE TABLE IF NOT EXISTS `apartment_units` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `unit_number` VARCHAR(50) NOT NULL,
  `floor_number` INT NOT NULL DEFAULT 1,
  `area_sqm` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `unit_type` VARCHAR(40) NOT NULL DEFAULT 'apartment',
  `client_id` VARCHAR(64) NULL,
  `price_usd` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `price_afn` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `total_paid_afn` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `balance_receivable_afn` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(32) NOT NULL DEFAULT 'available',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_apt_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assets_machinery` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `asset_tag_code` VARCHAR(60) NOT NULL,
  `asset_name` VARCHAR(200) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `assigned_project_id` VARCHAR(64) NULL,
  `purchase_date` DATE NOT NULL,
  `purchase_cost` DECIMAL(18, 2) NOT NULL,
  `current_book_value` DECIMAL(18, 2) NOT NULL,
  `salvage_value` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `useful_life_years` INT NOT NULL DEFAULT 5,
  `serial_or_engine_no` VARCHAR(100) NULL,
  `location_site` VARCHAR(150) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ast_tenant_proj` (`tenant_id`, `assigned_project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `voucher_number` VARCHAR(60) NOT NULL,
  `entry_date` DATE NOT NULL,
  `project_id` VARCHAR(64) NULL,
  `narration` TEXT NOT NULL,
  `total_debit` DECIMAL(18, 2) NOT NULL,
  `total_credit` DECIMAL(18, 2) NOT NULL,
  `is_balanced` TINYINT(1) NOT NULL DEFAULT 1,
  `is_posted` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by_user_id` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_je_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `journal_lines` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `journal_entry_id` VARCHAR(64) NOT NULL,
  `account_id` VARCHAR(64) NOT NULL,
  `debit_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `credit_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `memo` TEXT NULL,
  INDEX `idx_jl_entry` (`journal_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. صرافی و هدایات پرداخت (Exchange / Sarafi)
CREATE TABLE IF NOT EXISTS `exchange_transactions` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `direction` ENUM('out', 'in') NOT NULL DEFAULT 'out',
  `exchange_agent` VARCHAR(200) NOT NULL,
  `sender_name` VARCHAR(200) NOT NULL,
  `recipient_name` VARCHAR(200) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `transaction_time` VARCHAR(20) NULL,
  `reference_number` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(18, 2) NOT NULL,
  `currency` ENUM('AFN', 'USD') NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12, 4) NULL DEFAULT NULL,
  `amount_afn` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `amount_usd` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
  `purpose` VARCHAR(500) NOT NULL,
  `notes` TEXT NULL,
  `status` ENUM('draft', 'pending', 'paid', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
  `linked_obligation_type` VARCHAR(50) NULL DEFAULT NULL,
  `linked_obligation_id` VARCHAR(64) NULL DEFAULT NULL,
  `linked_obligation_label` VARCHAR(255) NULL DEFAULT NULL,
  `prepared_by` VARCHAR(150) NULL DEFAULT NULL,
  `authorized_by` VARCHAR(150) NULL DEFAULT NULL,
  `attachment_name` VARCHAR(255) NULL DEFAULT NULL,
  `attachment_url` LONGTEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(150) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_exc_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. فایل منیجر و بل‌ها (Project Folders, Files & Bills)
CREATE TABLE IF NOT EXISTS `project_folders` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `parent_folder_id` BIGINT NULL,
  `name` VARCHAR(200) NOT NULL,
  `category` VARCHAR(60) NOT NULL DEFAULT 'other',
  `description` TEXT NULL,
  `created_by` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pf_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_files` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `folder_id` BIGINT NULL,
  `safe_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `storage_key` VARCHAR(150) NOT NULL,
  `extension` VARCHAR(20) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size_bytes` BIGINT NOT NULL DEFAULT 0,
  `category` VARCHAR(60) NOT NULL DEFAULT 'other',
  `file_url` LONGTEXT NULL,
  `description` TEXT NULL,
  `uploaded_by` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pfl_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_documents` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(60) NOT NULL DEFAULT 'bill',
  `file_url` LONGTEXT NULL,
  `file_name` VARCHAR(255) NULL,
  `file_type` VARCHAR(100) NULL,
  `file_size` VARCHAR(50) NULL,
  `related_type` VARCHAR(60) NULL,
  `related_id` VARCHAR(64) NULL,
  `related_label` VARCHAR(255) NULL,
  `amount` DECIMAL(18, 2) NULL,
  `doc_date` DATE NULL,
  `notes` TEXT NULL,
  `uploaded_by` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pdoc_tenant_proj` (`tenant_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. پیش‌نویس‌های خودکار ۶۰ ثانیه‌ای (Phase 11: Separated Unfinished Form Drafts)
CREATE TABLE IF NOT EXISTS `form_drafts` (
  `id` VARCHAR(80) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NULL,
  `form_type` VARCHAR(60) NOT NULL,
  `draft_key` VARCHAR(160) NOT NULL UNIQUE,
              `client_draft_key` VARCHAR(255) NULL,
  `draft_json` LONGTEXT NOT NULL,
  `client_updated_at` BIGINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_fd_user_proj` (`user_id`, `project_id`, `form_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. وضعیت همگام‌سازی لحظه‌ای چنددستگاهی (Phase 11: Authoritative Workspace Sync State)
CREATE TABLE IF NOT EXISTS `workspace_sync_states` (
  `tenant_id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `state_version` BIGINT NOT NULL DEFAULT 1,
  `client_updated_at` BIGINT NOT NULL DEFAULT 0,
  `projects_json` LONGTEXT NULL,
  `records_json` LONGTEXT NULL,
  `updated_by` VARCHAR(64) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 21. دفتر حساب‌های استاندارد (Chart of Accounts) — منبع واحد سرور و کلاینت
-- کدها دقیقاً همان کدهای باندل کلاینت هستند تا سند روزنامچه دوپاره نشود.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `account_code` VARCHAR(32) NOT NULL,
  `account_name` VARCHAR(200) NOT NULL,
  `account_name_en` VARCHAR(200) NULL,
  `account_name_ps` VARCHAR(200) NULL,
  `account_type` ENUM('asset','liability','equity','revenue','expense') NOT NULL DEFAULT 'asset',
  `normal_balance` ENUM('debit','credit') NOT NULL DEFAULT 'debit',
  `parent_code` VARCHAR(32) NULL,
  `is_header` TINYINT(1) NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_coa_code` (`account_code`),
  INDEX `idx_coa_type` (`account_type`),
  INDEX `idx_coa_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. صندوق تنخواه و مصارف کوچک روزانه
CREATE TABLE IF NOT EXISTS `petty_cash_expenses` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NULL,
  `petty_cash_account_id` VARCHAR(64) NULL,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(120) NULL,
  `account_code` VARCHAR(32) NULL,
  `description` TEXT NULL,
  `payee_name` VARCHAR(200) NULL,
  `receipt_no` VARCHAR(100) NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12,4) NULL,
  `amount_in_base_afn` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'paid',
  `recorded_by_user_id` VARCHAR(64) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pce_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_pce_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. سفارش‌های خرید (Purchase Orders) برای دفتر معین فروشندگان
CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `project_id` VARCHAR(64) NULL,
  `supplier_id` VARCHAR(64) NULL,
  `po_number` VARCHAR(60) NULL,
  `order_date` DATE NOT NULL,
  `expected_date` DATE NULL,
  `description` TEXT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12,4) NULL,
  `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `amount_in_base_afn` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  `created_by_user_id` VARCHAR(64) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_po_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_po_supplier` (`supplier_id`),
  INDEX `idx_po_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. ثبت خرید و مصرف میلگرد (Steel Records) — گزارش وزنی/ارزی فولاد
CREATE TABLE IF NOT EXISTS `steel_records` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `record_date` DATE NULL,
  `date` DATE NULL,
  `size_mm` VARCHAR(32) NULL,
  `origin_country` VARCHAR(120) NULL,
  `supplier_name` VARCHAR(200) NULL,
  `bundles` INT NULL,
  `branches` INT NULL,
  `kg` DECIMAL(16,3) NULL,
  `tons` DECIMAL(16,4) NULL,
  `confirmed_total_ton` DECIMAL(16,4) NULL,
  `actual_total_ton` DECIMAL(16,4) NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `exchange_rate` DECIMAL(12,4) NULL,
  `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_native_cost` DECIMAL(18,2) NULL,
  `amount_in_afn` DECIMAL(18,2) NULL,
  `amount_in_usd` DECIMAL(18,2) NULL,
  `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `price_per_ton` DECIMAL(18,2) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `doc_status` VARCHAR(32) NULL,
  `supplier_id` VARCHAR(64) NULL,
  `invoice_number` VARCHAR(100) NULL,
  `invoice_url` LONGTEXT NULL,
  `created_by` VARCHAR(64) NULL,
  `created_by_name` VARCHAR(200) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sr_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_sr_date` (`date`),
  INDEX `idx_sr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بذر استاندارد دفتر حساب‌ها (idempotent): ۱۸۶ حساب، همان کدهای کلاینت
INSERT IGNORE INTO `chart_of_accounts` (`id`,`tenant_id`,`account_code`,`account_name`,`account_name_en`,`account_name_ps`,`account_type`,`normal_balance`,`parent_code`,`is_header`,`is_system`,`is_active`) VALUES
('coa-1000',NULL,'1000','دارایی‌ها','Assets','شتوالۍ','asset','debit',NULL,1,1,1),
('coa-1100',NULL,'1100','نقد و بانک','Cash & Bank','نقد او بانک','asset','debit','1000',1,1,1),
('coa-1110',NULL,'1110','صندوق افغانی','Cash (AFN)','د افغانیو صندوق','asset','debit','1100',0,1,1),
('coa-1120',NULL,'1120','صندوق دالر','Cash (USD)','د ډالرو صندوق','asset','debit','1100',0,1,1),
('coa-1130',NULL,'1130','حساب بانکی','Bank Account','بانکي حساب','asset','debit','1100',0,1,1),
('coa-1140',NULL,'1140','کمیون صرافی (حواله)','Hawala / Sarafi Balance','د صرافۍ حساب (حواله)','asset','debit','1100',0,1,1),
('coa-1200',NULL,'1200','دریافتنی از خریداران اپارتمان','Apartment Receivables','د اپارتمانونو له پیرودونکو ترلاسه کیدونکي','asset','debit','1000',0,1,1),
('coa-1300',NULL,'1300','پیش‌پرداخت به فروشنده‌گان','Advances to Suppliers','پلورونکو ته مخکینۍ تادیه','asset','debit','1000',0,1,1),
('coa-1400',NULL,'1400','موجودی مواد','Material Inventory','د موادو زیرمه','asset','debit','1000',0,1,1),
('coa-1500',NULL,'1500','پروژه در جریان ساخت (WIP)','Construction Work in Progress (WIP)','روانه پروژه (WIP)','asset','debit','1000',0,1,1),
('coa-1600',NULL,'1600','ودیعه‌ها و امانت‌های داده‌شده','Deposits','امانتونه','asset','debit','1000',0,1,1),
('coa-1800',NULL,'1800','دارایی‌های ثابت (مکینت، داربست، وسایط)','Fixed Assets','ثابتې شتوالۍ','asset','debit','1000',0,1,1),
('coa-2000',NULL,'2000','بدهی‌ها','Liabilities','پورونه','liability','credit',NULL,1,1,1),
('coa-2100',NULL,'2100','حساب‌های پرداختنی','Accounts Payable','تادیه وړ حسابونه','liability','credit','2000',1,1,1),
('coa-2200',NULL,'2200','بدهی به فروشنده‌گان','Supplier Payables','پلورونکو ته پور','liability','credit','2100',0,1,1),
('coa-2250',NULL,'2250','بدهی به قراردادکاران','Contractor Payables','قراردادکارانو ته پور','liability','credit','2100',0,1,1),
('coa-2300',NULL,'2300','پیش‌دریافت از خریداران (قبل از قرارداد فروش)','Customer Advances','له پیرودونکو څخه مخکینۍ ترلاسه کول','liability','credit','2000',0,1,1),
('coa-2400',NULL,'2400','امانت نگهداشته‌شده نزد قراردادکار','Retention Payable','قراردادکار ته ساتل شوی امانت','liability','credit','2000',0,1,1),
('coa-2500',NULL,'2500','قرض‌ها و وام‌ها','Loans Payable','پورونه او قرضې','liability','credit','2000',0,1,1),
('coa-2600',NULL,'2600','مالیات قابل پرداخت','Taxes Payable','تادیه وړ مالیه','liability','credit','2000',0,1,1),
('coa-2900',NULL,'2900','سایر بدهی‌ها (شامل پرداخت از جیب شخصی)','Other Liabilities','نور پورونه','liability','credit','2000',0,1,1),
('coa-3000',NULL,'3000','سرمایه','Equity','پانګه','equity','credit',NULL,1,1,1),
('coa-3100',NULL,'3100','سرمایه اولی شرکا','Partners'' Capital (Initial)','د شریکانو لومړنۍ پانګه','equity','credit','3000',0,1,1),
('coa-3200',NULL,'3200','سرمایه‌گذاری‌های اضافی شرکا','Additional Investments','د شریکانو اضافي پانګه اچونه','equity','credit','3000',0,1,1),
('coa-3300',NULL,'3300','برداشت شرکا','Partner Drawings','د شریکانو را ایستل','equity','debit','3000',0,1,1),
('coa-3400',NULL,'3400','سود و زیان انباشته','Retained Earnings','ذخیره شوې ګټه او زیان','equity','credit','3000',0,1,1),
('coa-4000',NULL,'4000','درآمد','Revenue','عاید','revenue','credit',NULL,1,1,1),
('coa-4100',NULL,'4100','فروش اپارتمان و واحدها','Apartment & Unit Sales','د اپارتمانونو او واحدونو پلور','revenue','credit','4000',0,1,1),
('coa-4200',NULL,'4200','درآمد کرایه (دوکان‌ها و غیره)','Rental Income','د کرایې عاید','revenue','credit','4000',0,1,1),
('coa-4300',NULL,'4300','سایر درآمدها (فروش مواد مازاد، ضایعات)','Other Project Revenue','د پروژې نور عایدونه','revenue','credit','4000',0,1,1),
('coa-5000',NULL,'5000','هزینه‌های ساخت پروژه','Construction / Project Costs','د پروژې د جوړښت لګښتونه','expense','debit',NULL,1,1,1),
('coa-5050',NULL,'5050','هزینه واحدهای فروخته‌شده','Cost of Units Sold (COGS)','د پلورل شویو واحدونو لګښت','expense','debit','5000',0,1,1),
('coa-5100',NULL,'5100','آهن و میل‌گرد','Steel & Rebar','اوسپنه او سیخ ګول','expense','debit','5000',0,1,1),
('coa-5200',NULL,'5200','کانکریت و پمپ','Concrete & Pumping','کانکریت او پمپ','expense','debit','5000',0,1,1),
('coa-5300',NULL,'5300','سیمنت','Cement','سمنټ','expense','debit','5000',0,1,1),
('coa-5400',NULL,'5400','ریگ و شن','Sand & Gravel','شګه او ریګ','expense','debit','5000',0,1,1),
('coa-5500',NULL,'5500','بلاک، آجر و بنایات','Masonry (Blocks & Bricks)','بلاک، خشتې او بنایات','expense','debit','5000',0,1,1),
('coa-5600',NULL,'5600','مزد و نیروی انسانی','Labour & Wages','مزد او کارګران','expense','debit','5000',0,1,1),
('coa-5700',NULL,'5700','کرایه ماشین‌آلات و تجهیزات','Machinery & Equipment Rental','د ماشینونو او تجهیزاتو کرایه','expense','debit','5000',0,1,1),
('coa-5800',NULL,'5800','ترانسپورت و حمل‌ونقل','Transportation & Logistics','ترانسپورت او لېږد','expense','debit','5000',0,1,1);
INSERT IGNORE INTO `chart_of_accounts` (`id`,`tenant_id`,`account_code`,`account_name`,`account_name_en`,`account_name_ps`,`account_type`,`normal_balance`,`parent_code`,`is_header`,`is_system`,`is_active`) VALUES
('coa-5900',NULL,'5900','آب‌وبرق و تیل ساحه','Site Utilities & Fuel','د ساحې اوبه، برق او تیل','expense','debit','5000',0,1,1),
('coa-5910',NULL,'5910','قالب‌بندی و داربست','Formwork & Scaffolding','قالب بندي او داربست','expense','debit','5000',0,1,1),
('coa-5920',NULL,'5920','برق‌کاری و تأسیسات برقی','Electrical Works','د برېښنا کارونه','expense','debit','5000',0,1,1),
('coa-5930',NULL,'5930','لوله‌کاری و تأسیسات صحی','Plumbing & Sanitary','د لولو او صحي کارونه','expense','debit','5000',0,1,1),
('coa-5940',NULL,'5940','نجاری، دروازه و ورک','Carpentry, Doors & Windows','نجاري، دروازې او ورکونه','expense','debit','5000',0,1,1),
('coa-5950',NULL,'5950','کاشی، نقاشی و تکمیل‌کاری','Tiles, Paint & Finishing','کاشي، رنګ او بشپړونکي کار','expense','debit','5000',0,1,1),
('coa-5960',NULL,'5960','لیفتر، حفاری و تکیه‌گاه','Elevator, Excavation & Shoring','لفټ، کیندنه او تکیه ګاه','expense','debit','5000',0,1,1),
('coa-5970',NULL,'5970','جواز، فیس و انجنیری','Permits, Fees & Engineering','جواز، فیس او انجنیري','expense','debit','5000',0,1,1),
('coa-5980',NULL,'5980','مصارف اداری و نگهبانی ساحه','Project & Site Overhead','د ساحې اداري لګښتونه','expense','debit','5000',0,1,1),
('coa-5990',NULL,'5990','سایر مصارف پروژه','Other Project Expenses','د پروژې نور لګښتونه','expense','debit','5000',0,1,1),
('coa-510001',NULL,'510001','سیخ‌گرد ساختمانی','Rebar steel','ساختماني سیخ','expense','debit','5100',0,1,1),
('coa-510002',NULL,'510002','سیم آرماتوربندی','Binding wire','د سیخ تړلو سیم','expense','debit','5100',0,1,1),
('coa-510003',NULL,'510003','کاور بلاک و فاصله‌گذار','Cover blocks & spacers','کاور بلاک او واټن ساتونکي','expense','debit','5100',0,1,1),
('coa-510004',NULL,'510004','الکترود جوشکاری','Welding electrodes','د وېلډینګ الکترود','expense','debit','5100',0,1,1),
('coa-510005',NULL,'510005','صفحه برش و سنگ فرز','Cutting & grinding discs','د پرې کولو او ګرینډ ډیسکونه','expense','debit','5100',0,1,1),
('coa-510006',NULL,'510006','مزد سیخ‌بند و آرماتوربند','Bar-bending & fixing labor','د سیخ تړونکو مزد','expense','debit','5100',0,1,1),
('coa-510007',NULL,'510007','مصرف ماشین برش سیخ','Steel cutting machine use','د سیخ پرې کولو ماشین','expense','debit','5100',0,1,1),
('coa-510008',NULL,'510008','تست لابراتواری سیخ','Rebar lab testing','د سیخ لابراتواري معاینه','expense','debit','5100',0,1,1),
('coa-520001',NULL,'520001','کانکریت آماده (ریدی‌مکس)','Ready-mix concrete','چمتو کانکریټ','expense','debit','5200',0,1,1),
('coa-520002',NULL,'520002','کرایه پمپ کانکریت','Concrete pump rental','د کانکریټو پمپ کرایه','expense','debit','5200',0,1,1),
('coa-520003',NULL,'520003','مزد تیم پمپ و لوله','Pump operator & pipeline team','د پمپ او پایپ ټیم مزد','expense','debit','5200',0,1,1),
('coa-520004',NULL,'520004','ویبراتور و تراکم','Vibrators & compaction','ویبراتور او ټینګښت','expense','debit','5200',0,1,1),
('coa-520005',NULL,'520005','عمل‌آوری کانکریت (آب، جوټ)','Curing (water, jute, chemical)','د کانکریټو روزنه','expense','debit','5200',0,1,1),
('coa-520006',NULL,'520006','تست مقاومت کانکریت','Cube/cylinder lab testing','د کانکریټو مقاومت معاینه','expense','debit','5200',0,1,1),
('coa-520007',NULL,'520007','مزد تیم کانکریت‌ریزی','Concrete pouring labor gang','د کانکریټو اچولو ټیم مزد','expense','debit','5200',0,1,1),
('coa-520008',NULL,'520008','مواد افزودنی کانکریت','Admixtures & additives','د کانکریټو اضافي مواد','expense','debit','5200',0,1,1),
('coa-530001',NULL,'530001','سیمنت پرتلند','Portland cement','پرټلنډ سمنټ','expense','debit','5300',0,1,1),
('coa-530002',NULL,'530002','سیمنت سفید','White cement','سپین سمنټ','expense','debit','5300',0,1,1),
('coa-530003',NULL,'530003','ترکیبات سیمنت ضدآب','Waterproof cement compounds','د اوبو ضد سمنټي مواد','expense','debit','5300',0,1,1),
('coa-540001',NULL,'540001','ریگ دریایی','River sand','د سیند شګه','expense','debit','5400',0,1,1),
('coa-540002',NULL,'540002','شن شسته','Washed gravel','وینځل شوی ریګ','expense','debit','5400',0,1,1),
('coa-540003',NULL,'540003','سنگ شکسته (کرش)','Crushed stone aggregate','مات شوی تیږه','expense','debit','5400',0,1,1),
('coa-540004',NULL,'540004','مصرف غربیل و شستشو','Sieving & washing charges','د چاڼ او وینځلو لګښت','expense','debit','5400',0,1,1),
('coa-550001',NULL,'550001','بلاک سیمنتی','Concrete blocks','سمنټي بلاک','expense','debit','5500',0,1,1),
('coa-550002',NULL,'550002','آجر','Bricks','خښتې','expense','debit','5500',0,1,1),
('coa-550003',NULL,'550003','مصالح ملات','Mortar materials','د مسالې مواد','expense','debit','5500',0,1,1),
('coa-550004',NULL,'550004','لنتل فلزی','Steel lintels','فلزي لنټل','expense','debit','5500',0,1,1),
('coa-550005',NULL,'550005','بست و انکر دیوار','Wall ties & anchors','د دېوال بست او انکر','expense','debit','5500',0,1,1),
('coa-550006',NULL,'550006','مزد بنّا','Mason wages','د بنایانو مزد','expense','debit','5500',0,1,1),
('coa-550007',NULL,'550007','مزد شاگرد بنّا','Mason helpers','د بنایانو شاګردانو مزد','expense','debit','5500',0,1,1);
INSERT IGNORE INTO `chart_of_accounts` (`id`,`tenant_id`,`account_code`,`account_name`,`account_name_en`,`account_name_ps`,`account_type`,`normal_balance`,`parent_code`,`is_header`,`is_system`,`is_active`) VALUES
('coa-560001',NULL,'560001','کارگران روزمزد','Daily unskilled laborers','ورځني کارګران','expense','debit','5600',0,1,1),
('coa-560002',NULL,'560002','سرکارگر ساحه','Site foreman','د ساحې سرکارګر','expense','debit','5600',0,1,1),
('coa-560003',NULL,'560003','نان و غذای کارگران','Worker meals & food','د کارګرانو ډوډۍ او خواړه','expense','debit','5600',0,1,1),
('coa-560004',NULL,'560004','وسایل ایمنی','Safety gear & PPE','د خوندیتوب وسایل','expense','debit','5600',0,1,1),
('coa-560005',NULL,'560005','آب آشامیدنی و کمک‌های اولیه','Drinking water & first aid','څښاک اوبه او لومړنۍ مرسته','expense','debit','5600',0,1,1),
('coa-570001',NULL,'570001','کرایه اکسکواتور','Excavator rental','د اکسکواتور کرایه','expense','debit','5700',0,1,1),
('coa-570002',NULL,'570002','کرایه کرین و بالابر','Crane & hoist rental','د کرین او لفټ کرایه','expense','debit','5700',0,1,1),
('coa-570003',NULL,'570003','کرایه میکسر','Concrete mixer rental','د میکسر کرایه','expense','debit','5700',0,1,1),
('coa-570004',NULL,'570004','کرایه کمپکتور و غلتک','Compactor & roller rental','د کمپکټور کرایه','expense','debit','5700',0,1,1),
('coa-570005',NULL,'570005','پمپ آب و خشک‌کردن','Water pump & dewatering','د اوبو پمپ','expense','debit','5700',0,1,1),
('coa-570006',NULL,'570006','مزد اوپراتور ماشین‌آلات','Machine operators wages','د ماشین چلوونکو مزد','expense','debit','5700',0,1,1),
('coa-570007',NULL,'570007','ابزار کوچک و مصرفی','Small tools & consumables','واړه وسایل','expense','debit','5700',0,1,1),
('coa-570008',NULL,'570008','ترمیم و نگهداری تجهیزات','Equipment repair & maintenance','د تجهیزاتو ترمیم','expense','debit','5700',0,1,1),
('coa-580001',NULL,'580001','حمل مصالح با موتر','Material haulage (trucks)','د موادو لېږد','expense','debit','5800',0,1,1),
('coa-580002',NULL,'580002','انتقال آوار و خاک اضافه','Debris & soil removal','د کثافاتو او خاورو لېږدول','expense','debit','5800',0,1,1),
('coa-580003',NULL,'580003','مزد بارگیری و تخلیه','Loading & unloading labor','د بارولو او تشولو مزد','expense','debit','5800',0,1,1),
('coa-580004',NULL,'580004','مزد دریور','Driver wages','د ډرېور مزد','expense','debit','5800',0,1,1),
('coa-590001',NULL,'590001','برق ساحه','Site electricity','د ساحې برق','expense','debit','5900',0,1,1),
('coa-590002',NULL,'590002','آب ساحه','Site water','د ساحې اوبه','expense','debit','5900',0,1,1),
('coa-590003',NULL,'590003','دیزل و تیل','Diesel & fuel','ډیزل او تیل','expense','debit','5900',0,1,1),
('coa-590004',NULL,'590004','سرویس جنراتور','Generator service & maintenance','د جنراتور سرویس','expense','debit','5900',0,1,1),
('coa-591001',NULL,'591001','پلای‌وود و تخته قالب','Plywood & shuttering sheets','پلایوډ او قالب تخته','expense','debit','5910',0,1,1),
('coa-591002',NULL,'591002','چوب، جوست و زیرکوب','Timber, joists & battens','لرګي او جوېسټ','expense','debit','5910',0,1,1),
('coa-591003',NULL,'591003','پایه فلزی و جک','Steel props & jacks','فلزي پایې او جک','expense','debit','5910',0,1,1),
('coa-591004',NULL,'591004','میخ، پیچ و بولت','Nails, screws & bolts','مېخونه، پېچ او بولټ','expense','debit','5910',0,1,1),
('coa-591005',NULL,'591005','روغن قالب','Form oil','د قالب غوړي','expense','debit','5910',0,1,1),
('coa-591006',NULL,'591006','کرایه و بستن داربست','Scaffolding rental & fixing','د داربست کرایه او تړل','expense','debit','5910',0,1,1),
('coa-591007',NULL,'591007','مزد نجار قالب‌بند','Formwork carpenter wages','د قالب تړونکو مزد','expense','debit','5910',0,1,1),
('coa-591008',NULL,'591008','مزد بازکردن و پاک‌کاری قالب','Dismantling & cleaning labor','د قالب خلاصولو مزد','expense','debit','5910',0,1,1),
('coa-592001',NULL,'592001','سیم و کیبل','Wires & cables','سیم او کېبل','expense','debit','5920',0,1,1),
('coa-592002',NULL,'592002','لوله برق و خرطومی','Conduits & pipes','د برق پایپونه','expense','debit','5920',0,1,1),
('coa-592003',NULL,'592003','تابلو برق و فیوز','Distribution boards & breakers','د برق تابلو او فیوز','expense','debit','5920',0,1,1),
('coa-592004',NULL,'592004','کلید و ساکت','Switches & sockets','سویچ او ساکټ','expense','debit','5920',0,1,1),
('coa-592005',NULL,'592005','چراغ و گروپ','Light fixtures & bulbs','څراغونه او ګروپونه','expense','debit','5920',0,1,1),
('coa-592006',NULL,'592006','مصالح ارتینگ','Earthing materials','د ارتنګ مواد','expense','debit','5920',0,1,1),
('coa-592007',NULL,'592007','مزد برق‌کار','Electrician wages','د برقکار مزد','expense','debit','5920',0,1,1),
('coa-592008',NULL,'592008','تست و راه‌اندازی برق','Testing & commissioning','د برق ټیسټ','expense','debit','5920',0,1,1),
('coa-593001',NULL,'593001','لوله آب','Water supply pipes','د اوبو پایپونه','expense','debit','5930',0,1,1),
('coa-593002',NULL,'593002','اتصالات، والو و زانو','Fittings, valves & elbows','فټنګ، والو او زنګن','expense','debit','5930',0,1,1),
('coa-593003',NULL,'593003','لوازم صحی (کمود، دستشویی)','Sanitary ware (WC, basins)','صحي سامانونه','expense','debit','5930',0,1,1);
INSERT IGNORE INTO `chart_of_accounts` (`id`,`tenant_id`,`account_code`,`account_name`,`account_name_en`,`account_name_ps`,`account_type`,`normal_balance`,`parent_code`,`is_header`,`is_system`,`is_active`) VALUES
('coa-593004',NULL,'593004','شیرآلات','Faucets & mixers','شېرونه','expense','debit','5930',0,1,1),
('coa-593005',NULL,'593005','تانکر آب','Water tanks','د اوبو ټانکۍ','expense','debit','5930',0,1,1),
('coa-593006',NULL,'593006','لوله فاضلاب','Sewage & drainage pipes','د فاضلاب پایپونه','expense','debit','5930',0,1,1),
('coa-593007',NULL,'593007','مزد لوله‌کش','Plumber wages','د نلدوان مزد','expense','debit','5930',0,1,1),
('coa-593008',NULL,'593008','تست فشار لوله','Pressure testing','د پایپ فشار ټیسټ','expense','debit','5930',0,1,1),
('coa-594001',NULL,'594001','دروازه‌های چوبی','Wooden doors','لرګینې دروازې','expense','debit','5940',0,1,1),
('coa-594002',NULL,'594002','دروازه‌های فلزی و PVC','Steel & PVC doors','فلزي او PVC دروازې','expense','debit','5940',0,1,1),
('coa-594003',NULL,'594003','چوکات ورک','Window frames','د ورکو چوکاټونه','expense','debit','5940',0,1,1),
('coa-594004',NULL,'594004','شیشه و نصب آن','Glass & glazing','ښیښه','expense','debit','5940',0,1,1),
('coa-594005',NULL,'594005','قفل، دستگیره و لولا','Locks, handles & hinges','قلف، لاسني او لوځې','expense','debit','5940',0,1,1),
('coa-594006',NULL,'594006','مزد نجاری و نصب','Carpentry & installation wages','د نجارۍ او نصب مزد','expense','debit','5940',0,1,1),
('coa-594007',NULL,'594007','وارنیش و پولیش چوب','Varnish & wood polish','وارنیش او پولیش','expense','debit','5940',0,1,1),
('coa-595001',NULL,'595001','کاشی کف','Floor tiles','د فرش کاشي','expense','debit','5950',0,1,1),
('coa-595002',NULL,'595002','کاشی دیوار','Wall tiles','د دېوال کاشي','expense','debit','5950',0,1,1),
('coa-595003',NULL,'595003','چسب و ملات کاشی','Tile adhesive & mortar','د کاشۍ چسپ','expense','debit','5950',0,1,1),
('coa-595004',NULL,'595004','بندکشی (گروت)','Grout','ګراوټ','expense','debit','5950',0,1,1),
('coa-595005',NULL,'595005','مزد کاشی‌کار','Tile fixer wages','د کاشۍ کار مزد','expense','debit','5950',0,1,1),
('coa-595006',NULL,'595006','گچ','Gypsum plaster','ګچ','expense','debit','5950',0,1,1),
('coa-595007',NULL,'595007','مزد گچ‌کار','Plasterer wages','د ګچ کار مزد','expense','debit','5950',0,1,1),
('coa-595008',NULL,'595008','رنگ پلاستیک','Emulsion/plastic paint','پلاستیکي رنګ','expense','debit','5950',0,1,1),
('coa-595009',NULL,'595009','رنگ روغنی','Oil & enamel paint','روغني رنګ','expense','debit','5950',0,1,1),
('coa-595010',NULL,'595010','پرایمر و سیلر','Primer & sealer','پرایمر','expense','debit','5950',0,1,1),
('coa-595011',NULL,'595011','بتونه','Wall putty','پوټي','expense','debit','5950',0,1,1),
('coa-595012',NULL,'595012','ریگمال','Sandpaper & abrasives','رېګمال','expense','debit','5950',0,1,1),
('coa-595013',NULL,'595013','برس','Brushes','برسونه','expense','debit','5950',0,1,1),
('coa-595014',NULL,'595014','غلتک و تشت رنگ','Rollers & trays','رولرونه','expense','debit','5950',0,1,1),
('coa-595015',NULL,'595015','چسپ محافظ و نایلون','Masking tape & covers','محافظتي ټېپ','expense','debit','5950',0,1,1),
('coa-595016',NULL,'595016','تینر','Thinner & solvents','تینر','expense','debit','5950',0,1,1),
('coa-595017',NULL,'595017','کرایه ماشین رنگ‌پاشی','Spray machine rental','د رنګ پاشلو ماشین','expense','debit','5950',0,1,1),
('coa-595018',NULL,'595018','مزد رنگمال','Painter wages','د رنګمال مزد','expense','debit','5950',0,1,1),
('coa-595019',NULL,'595019','مزد شاگرد رنگمال','Painter helpers','د رنګمال شاګرد','expense','debit','5950',0,1,1),
('coa-595020',NULL,'595020','پاک‌کاری نهایی تعمیر','Final cleaning','د ودانۍ وروستۍ پاکوالي','expense','debit','5950',0,1,1),
('coa-595021',NULL,'595021','ریزه‌کاری و اصلاحات','Touch-ups & snagging','وړې جوړونې','expense','debit','5950',0,1,1),
('coa-596001',NULL,'596001','خاکبرداری','Excavation work','کېندنه','expense','debit','5960',0,1,1),
('coa-596002',NULL,'596002','تکیه‌گاه و تحکیم خاک','Shoring & soil support','د خاورې ټینګښت','expense','debit','5960',0,1,1),
('coa-596003',NULL,'596003','خاک‌ریزی و تراکم','Backfill & compaction','خاورې اچول او ټینګول','expense','debit','5960',0,1,1),
('coa-596004',NULL,'596004','تست خاک','Soil testing','د خاورې معاینه','expense','debit','5960',0,1,1),
('coa-596005',NULL,'596005','خرید لیفت','Elevator supply','د لفټ پېرل','expense','debit','5960',0,1,1),
('coa-596006',NULL,'596006','نصب لیفت','Elevator installation','د لفټ نصب','expense','debit','5960',0,1,1),
('coa-596007',NULL,'596007','سرویس اولیه لیفت','Elevator initial service','د لفټ لومړنی سرویس','expense','debit','5960',0,1,1);
INSERT IGNORE INTO `chart_of_accounts` (`id`,`tenant_id`,`account_code`,`account_name`,`account_name_en`,`account_name_ps`,`account_type`,`normal_balance`,`parent_code`,`is_header`,`is_system`,`is_active`) VALUES
('coa-597001',NULL,'597001','جواز شاروالی و اعمار','Municipality & construction permit','د ښاروالۍ جواز','expense','debit','5970',0,1,1),
('coa-597002',NULL,'597002','فیس دیزاین','Architectural design fee','د ډیزاین فیس','expense','debit','5970',0,1,1),
('coa-597003',NULL,'597003','فیس انجنیری ساختمان','Structural engineering fee','د ساختمان انجنیرۍ فیس','expense','debit','5970',0,1,1),
('coa-597004',NULL,'597004','فیس نظارت ساحه','Site supervision fee','د ساحې څارنې فیس','expense','debit','5970',0,1,1),
('coa-597005',NULL,'597005','سروی خاک','Soil survey','د خاورې سروې','expense','debit','5970',0,1,1),
('coa-597006',NULL,'597006','اسناد حقوقی و قباله','Legal & documentation','حقوقي سندونه','expense','debit','5970',0,1,1),
('coa-597007',NULL,'597007','چاپ نقشه و کاپی','Printing & copies','د نقشو چاپ','expense','debit','5970',0,1,1),
('coa-598001',NULL,'598001','دیوار موقت و دروازه ساحه','Temporary fencing & gate','لنډمهاله دېوال','expense','debit','5980',0,1,1),
('coa-598002',NULL,'598002','اتاق نگهبان و دفتر ساحه','Guard room & site office','د ساتونکي خونه','expense','debit','5980',0,1,1),
('coa-598003',NULL,'598003','مزد نگهبان','Site guard wages','د ساتونکي مزد','expense','debit','5980',0,1,1),
('coa-598004',NULL,'598004','روشنایی ساحه','Site lighting','د ساحې رڼا','expense','debit','5980',0,1,1),
('coa-598005',NULL,'598005','لوازم دفتر ساحه','Office supplies','د دفتر سامانونه','expense','debit','5980',0,1,1),
('coa-598006',NULL,'598006','تابلوی پروژه','Site signage','د پروژې تابلو','expense','debit','5980',0,1,1),
('coa-599001',NULL,'599001','احتیاط (پیش‌بینی‌نشده)','Contingency reserve','احتیاطي زېرمه','expense','debit','5990',0,1,1),
('coa-599002',NULL,'599002','افزایش نرخ‌ها','Price escalation allowance','د بیو لوړېدل','expense','debit','5990',0,1,1),
('coa-599003',NULL,'599003','متفرقه','Miscellaneous','متفرقه','expense','debit','5990',0,1,1),
('coa-6000',NULL,'6000','هزینه‌های اداری عمومی (غیر از پروژه)','Operating & Admin Expenses','عمومي اداري لګښتونه','expense','debit',NULL,1,1,1),
('coa-6100',NULL,'6100','کرایه و مصارف دفتر مرکزی','Office Rent & Admin','د مرکزي دفتر کرایه او لګښتونه','expense','debit','6000',0,1,1),
('coa-6200',NULL,'6200','معاشات کارمندان اداری','Admin Salaries','د اداري کارکوونکو معاشونه','expense','debit','6000',0,1,1),
('coa-6300',NULL,'6300','بازاریابی و تبلیغات','Marketing & Advertising','بازار موندنه او اعلانونه','expense','debit','6000',0,1,1),
('coa-6900',NULL,'6900','سایر هزینه‌های عملیاتی','Other Operating Expenses','نور عملیاتي لګښتونه','expense','debit','6000',0,1,1),
('coa-7000',NULL,'7000','هزینه‌های مالی','Finance Costs','مالي لګښتونه','expense','debit',NULL,1,1,1),
('coa-7100',NULL,'7100','کارمزد بانک','Bank Charges','د بانک فیس','expense','debit','7000',0,1,1),
('coa-7200',NULL,'7200','هزینه قرض و سود','Loan & Finance Cost','د پور لګښت او ګټه','expense','debit','7000',0,1,1),
('coa-8000',NULL,'8000','سایر حساب‌ها','Other Accounts','نور حسابونه','expense','debit',NULL,1,1,1),
('coa-8100',NULL,'8100','تعدیلات حسابداری','Accounting Adjustments','د محاسبې سمونونه','expense','debit','8000',0,1,1),
-- حساب تعلیقی: باندل کلاینت با SUSPENSE:'8200' سند می‌زند؛ پیش‌تر در seed نبود.
('coa-8200',NULL,'8200','حساب معلق','Suspense Account','د معلق حساب','expense','debit','8000',0,1,1);

-- =====================================================================
-- 25. نسخهٔ ساختار — مرجع ارتقای خودکار (api/db.php → nscSchemaEnsure)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `nsc_schema_meta` (
  `meta_key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `meta_value` VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `nsc_schema_meta` (`meta_key`,`meta_value`) VALUES ('core','2026-10-29.1')
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

-- 26. مشتریان و خریداران اپارتمان (مرجع مطالبات در داشبورد)
-- کد در api/apartments.php (LEFT JOIN clients) و api/reports.php (SUM(outstanding_receivable))
-- به آن کوئری می‌زند، ولی پیش از این هیچ‌جا ساخته نمی‌شد: داشبورد روی نصب تازه خطای ۵۰۰ می‌داد.
CREATE TABLE IF NOT EXISTS `clients` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NOT NULL,
  `client_name` VARCHAR(200) NOT NULL,
  `phone` VARCHAR(50) NULL,
  `email` VARCHAR(150) NULL,
  `national_id` VARCHAR(60) NULL,
  `address` TEXT NULL,
  `outstanding_receivable` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_clients_tenant` (`tenant_id`),
  INDEX `idx_clients_name` (`client_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. ردیف‌های چندسایزی خرید میلگرد (پیش‌تر فقط به‌صورت inline در api/materials.php ساخته می‌شد)
CREATE TABLE IF NOT EXISTS `steel_purchase_lines` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `procurement_id` VARCHAR(36) NOT NULL,
  `project_id` VARCHAR(36) NOT NULL,
  `diameter_mm` DECIMAL(8,2) NOT NULL,
  `length_m` DECIMAL(8,2) NOT NULL DEFAULT 12.00,
  `brand_spec` VARCHAR(180) NOT NULL DEFAULT 'عمومی',
  `use_bundles` TINYINT(1) NOT NULL DEFAULT 0,
  `bundle_count` INT NULL,
  `bars_per_bundle` INT NULL,
  `bar_count` INT NOT NULL DEFAULT 0,
  `weight_per_bar_kg` DECIMAL(12,3) NOT NULL,
  `estimated_weight_kg` DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `estimated_weight_ton` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `actual_weight_ton` DECIMAL(16,4) NULL,
  `confirmed_tonnage` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `discrepancy_kg` DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `discrepancy_ton` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `discrepancy_pct` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_per_ton` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `total_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spl_proc` (`procurement_id`),
  INDEX `idx_spl_proj_size` (`project_id`, `diameter_mm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Server-owned construction workspace persistence; no demo records.
CREATE TABLE IF NOT EXISTS app_workspaces (
 tenant_id VARCHAR(64) NOT NULL PRIMARY KEY,
 state_version BIGINT NOT NULL DEFAULT 0,
 state_json LONGTEXT NOT NULL,
 updated_by VARCHAR(64) NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

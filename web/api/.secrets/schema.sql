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
  `invoice_no` VARCHAR(100) NULL,
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

SET FOREIGN_KEY_CHECKS = 1;

-- Server-owned construction workspace persistence; no demo records.
CREATE TABLE IF NOT EXISTS app_workspaces (
 tenant_id VARCHAR(64) NOT NULL PRIMARY KEY,
 state_version BIGINT NOT NULL DEFAULT 0,
 state_json LONGTEXT NOT NULL,
 updated_by VARCHAR(64) NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

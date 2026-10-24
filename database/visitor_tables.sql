-- =====================================================================
-- CEISA 4.0 - VISITOR & USER ACTIVITY AUDIT TABLES SCHEMA
-- Modul: Visitor, IP Analytics, GeoIP Cache, & Menu Activity Stream
-- Database: ceisa0400db / bc2faktur_inhost
-- Standarisasi Waktu: Waktu Indonesia Barat (WIB, Asia/Jakarta / UTC+7)
-- =====================================================================

-- 1. Tabel Cache Geolokasi IP (GeoIP Cache)
CREATE TABLE IF NOT EXISTS `bc_visitor_geo_cache` (
  `ip_address` VARCHAR(45) NOT NULL,
  `country_code` VARCHAR(10) DEFAULT 'ID',
  `country_name` VARCHAR(100) DEFAULT 'Indonesia',
  `region_name` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `isp` VARCHAR(150) DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Ringkasan Kunjungan per IP & Subdomain (Visitor Log)
CREATE TABLE IF NOT EXISTS `bc_visitor_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `subdomain` VARCHAR(100) NOT NULL DEFAULT 'root',
  `host` VARCHAR(150) DEFAULT NULL,
  `user_id` VARCHAR(100) DEFAULT NULL,
  `user_name` VARCHAR(150) DEFAULT NULL,
  `cmp_id` VARCHAR(50) DEFAULT NULL,
  `country_code` VARCHAR(10) DEFAULT 'ID',
  `country_name` VARCHAR(100) DEFAULT 'Indonesia',
  `region_name` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `isp` VARCHAR(150) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `browser` VARCHAR(100) DEFAULT NULL,
  `os` VARCHAR(100) DEFAULT NULL,
  `device_type` VARCHAR(50) DEFAULT 'Desktop',
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_visit` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_visit` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ip_subdomain` (`ip_address`, `subdomain`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_subdomain` (`subdomain`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_visit` (`last_visit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Stream Riwayat Hits Kunjungan (Visitor Hits Stream)
CREATE TABLE IF NOT EXISTS `bc_visitor_hits` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `subdomain` VARCHAR(100) NOT NULL DEFAULT 'root',
  `user_id` VARCHAR(100) DEFAULT NULL,
  `cmp_id` VARCHAR(50) DEFAULT NULL,
  `action_event` VARCHAR(50) NOT NULL DEFAULT 'visit',
  `page_url` TEXT DEFAULT NULL,
  `referer` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `country_name` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hit_ip` (`ip_address`),
  KEY `idx_hit_subdomain` (`subdomain`),
  KEY `idx_hit_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Riwayat Akses Menu Pengguna (Menu Activity Stream)
CREATE TABLE IF NOT EXISTS `bc_visitor_menu_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `subdomain` VARCHAR(100) NOT NULL DEFAULT 'root',
  `user_id` VARCHAR(100) DEFAULT NULL,
  `user_name` VARCHAR(150) DEFAULT NULL,
  `cmp_id` VARCHAR(50) DEFAULT NULL,
  `menu_id` VARCHAR(100) NOT NULL,
  `menu_title` VARCHAR(150) DEFAULT NULL,
  `accessed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vml_ip` (`ip_address`),
  KEY `idx_vml_sub` (`subdomain`),
  KEY `idx_vml_user` (`user_id`),
  KEY `idx_vml_time` (`accessed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SKRIP ALTER TABLE MIGRATION (JIKA MENGUPDATE DARI DATABASE LAMA)
-- =====================================================================

-- Tambah kolom jika belum ada di bc_visitor_log
-- ALTER TABLE `bc_visitor_log` ADD COLUMN IF NOT EXISTS `user_name` VARCHAR(150) DEFAULT NULL AFTER `user_id`;
-- ALTER TABLE `bc_visitor_log` ADD COLUMN IF NOT EXISTS `cmp_id` VARCHAR(50) DEFAULT NULL AFTER `user_name`;
-- ALTER TABLE `bc_visitor_log` ADD COLUMN IF NOT EXISTS `device_type` VARCHAR(50) DEFAULT 'Desktop' AFTER `os`;

-- Tambah kolom jika belum ada di bc_visitor_hits
-- ALTER TABLE `bc_visitor_hits` ADD COLUMN IF NOT EXISTS `cmp_id` VARCHAR(50) DEFAULT NULL AFTER `user_id`;
-- ALTER TABLE `bc_visitor_hits` ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) DEFAULT NULL AFTER `referer`;
-- ALTER TABLE `bc_visitor_hits` ADD COLUMN IF NOT EXISTS `country_name` VARCHAR(100) DEFAULT NULL AFTER `city`;

-- Pembersihan data non-login (optional maintenance query)
-- DELETE FROM `bc_visitor_log` WHERE user_id IS NULL OR user_id = '' OR user_id = 'guest';
-- DELETE FROM `bc_visitor_hits` WHERE user_id IS NULL OR user_id = '' OR user_id = 'guest';
-- DELETE FROM `bc_visitor_menu_log` WHERE user_id IS NULL OR user_id = '' OR user_id = 'guest';

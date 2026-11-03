-- ============================================================
-- 车辆管理系统 SaaS 多租户升级脚本（老库迁移用）
-- 适用：已在运行的旧版单企业库 → 多租户结构
-- 特点：幂等，可重复执行；所有现有数据自动归属租户 id=1「我的企业」
-- 使用：在 phpMyAdmin 中选中 vehicle_mgr 库，导入执行本文件
-- 注意：若尚未执行过 upgrade_ev.sql（无充电功能表），请先执行 upgrade_ev.sql，
--       本脚本对不存在的表会自动跳过，不会报错。
-- ============================================================

USE `vehicle_mgr`;
SET NAMES utf8mb4;

-- ---------- 1. 租户表 ----------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '企业名称',
  `contact_name` VARCHAR(50) DEFAULT '' COMMENT '联系人',
  `contact_phone` VARCHAR(30) DEFAULT '' COMMENT '联系电话',
  `plan_name` VARCHAR(30) NOT NULL DEFAULT '试用版' COMMENT '套餐名称',
  `max_vehicles` INT NOT NULL DEFAULT 5 COMMENT '车辆数上限（0=不限）',
  `expires_at` DATE DEFAULT NULL COMMENT '到期日（NULL=长期有效）',
  `status` ENUM('正常','停用') NOT NULL DEFAULT '正常',
  `remark` VARCHAR(255) DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户（企业）';

-- 现有全部数据归属租户 1（长期有效的正式版，不影响老库继续使用）
INSERT IGNORE INTO `tenants` (`id`,`name`,`plan_name`,`max_vehicles`,`expires_at`,`status`) VALUES
(1,'我的企业','正式版',0,NULL,'正常');

-- ---------- 2. 用户表：加 tenant_id、角色枚举加 super、插入超管 ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `users` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户（0=平台超级管理员）'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 角色枚举增加 super（重复执行无害）
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('super','admin','boss','finance') NOT NULL DEFAULT 'finance' COMMENT '角色：超管/管理员/老板/财务';

-- 平台超级管理员：superadmin / super123（登录后请立即修改）
INSERT IGNORE INTO `users` (`tenant_id`,`username`,`password`,`name`,`role`,`email`) VALUES
(0,'superadmin','$2y$10$tLyXaL7pyJywvRHLyX1Z3.WP7tTr0Hom/0DYb4Zz6rzLn1G0y8SBu','平台超管','super','');

-- ---------- 3. 车辆表：加 tenant_id，车牌唯一键改为「租户内唯一」 ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `vehicles` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 若 uk_plate 尚未包含 tenant_id，则重建为 (tenant_id, plate_no)
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND INDEX_NAME='uk_plate' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `vehicles` DROP INDEX `uk_plate`, ADD UNIQUE KEY `uk_plate` (`tenant_id`,`plate_no`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 4. 各业务表：加 tenant_id + 索引 ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insurance_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `insurance_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inspection_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `inspection_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `maintenance_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fuel_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `fuel_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='repair_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `repair_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 充电相关表（若未执行过 upgrade_ev.sql 则自动跳过）
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge_records');
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge_records' AND COLUMN_NAME='tenant_id');
SET @s := IF(@t>0 AND @c=0,
  'ALTER TABLE `charge_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge_cycles');
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge_cycles' AND COLUMN_NAME='tenant_id');
SET @s := IF(@t>0 AND @c=0,
  'ALTER TABLE `charge_cycles` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`, ADD KEY `idx_tenant` (`tenant_id`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 5. 设置表：主键改为 (tenant_id, skey)，老设置归租户 1 ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `settings` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' FIRST, DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`skey`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 6. 提醒日志：加 tenant_id，唯一键改为 (tenant_id, rtype, ref_id, due_key) ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reminder_log' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `reminder_log` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''所属租户'' AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reminder_log' AND INDEX_NAME='uk_once' AND COLUMN_NAME='tenant_id');
SET @s := IF(@c=0,
  'ALTER TABLE `reminder_log` DROP INDEX `uk_once`, ADD UNIQUE KEY `uk_once` (`tenant_id`,`rtype`,`ref_id`,`due_key`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 升级完成。请用 superadmin / super123 登录超管后台开通企业，
-- 原有账号 admin 及全部数据已归属租户「我的企业」(id=1)。
-- ============================================================

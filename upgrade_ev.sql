-- ============================================================
-- 增量升级脚本：新能源满电记录功能
-- 适用：已部署过旧版 install.sql 的数据库，在 phpMyAdmin 中对 vehicle_mgr 库执行本文件
-- ============================================================
USE `vehicle_mgr`;
SET NAMES utf8mb4;

-- 1. 车辆表增加百公里电耗预设
ALTER TABLE `vehicles`
  ADD COLUMN `energy_consumption` DECIMAL(6,2) DEFAULT NULL COMMENT '百公里电耗(kWh/100km，新能源车型)' AFTER `fuel_type`;

-- 2. 充电记录表
CREATE TABLE IF NOT EXISTS `charge_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id` INT UNSIGNED NOT NULL,
  `charge_time` DATETIME NOT NULL COMMENT '充电时间',
  `charge_type` ENUM('满电','零散补电','外部快充') NOT NULL COMMENT '充电类型',
  `mileage` INT NOT NULL DEFAULT 0 COMMENT '充电时仪表里程(km)',
  `kwh` DECIMAL(8,2) DEFAULT NULL COMMENT '真实度数(外部快充，按电表)',
  `price` DECIMAL(6,2) DEFAULT NULL COMMENT '单价(元/度)',
  `amount` DECIMAL(10,2) DEFAULT NULL COMMENT '金额(元，外部快充)',
  `station` VARCHAR(100) DEFAULT '' COMMENT '充电站/充电点',
  `receipt_photo` VARCHAR(255) DEFAULT '' COMMENT '凭证照片',
  `mileage_photo` VARCHAR(255) DEFAULT '' COMMENT '仪表里程照片',
  `source` ENUM('admin','driver') NOT NULL DEFAULT 'admin' COMMENT '录入来源',
  `driver_name` VARCHAR(50) DEFAULT '' COMMENT '司机姓名',
  `remark` VARCHAR(255) DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vehicle` (`vehicle_id`),
  KEY `idx_time` (`charge_time`),
  KEY `idx_type` (`charge_type`),
  CONSTRAINT `fk_charge_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充电记录';

-- 3. 满电周期核算表
CREATE TABLE IF NOT EXISTS `charge_cycles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id` INT UNSIGNED NOT NULL,
  `start_record_id` INT UNSIGNED NOT NULL,
  `end_record_id` INT UNSIGNED NOT NULL,
  `start_mileage` INT NOT NULL,
  `end_mileage` INT NOT NULL,
  `distance` INT NOT NULL,
  `est_kwh` DECIMAL(10,2) DEFAULT NULL COMMENT '周期估算总电量(度)',
  `ext_kwh` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '周期内外部快充真实度数',
  `slow_kwh` DECIMAL(10,2) DEFAULT NULL COMMENT '厂区慢充估算度数',
  `slow_price` DECIMAL(6,2) DEFAULT NULL COMMENT '核算时慢充电价(元/度)',
  `slow_cost` DECIMAL(10,2) DEFAULT NULL COMMENT '厂区慢充电费(元)',
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_end_record` (`end_record_id`),
  KEY `idx_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_cycle_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='满电周期核算';

-- 4. 厂区慢充电价设置
INSERT INTO `settings` (`skey`,`svalue`) VALUES ('slow_charge_price','0.80')
ON DUPLICATE KEY UPDATE `svalue` = `svalue`;

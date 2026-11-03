-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-09-19 20:51:11
-- 服务器版本： 5.7.44-log
-- PHP 版本： 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `aiweiquan_cn`
--

-- [已剔除遗留存储过程]

-- --------------------------------------------------------

--
-- 表的结构 `charge_cycles`
--

CREATE TABLE `charge_cycles` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `start_record_id` int(10) UNSIGNED NOT NULL COMMENT '周期起点满电记录',
  `end_record_id` int(10) UNSIGNED NOT NULL COMMENT '周期终点满电记录',
  `start_mileage` int(11) NOT NULL COMMENT '满电里程M1',
  `end_mileage` int(11) NOT NULL COMMENT '满电里程M2',
  `distance` int(11) NOT NULL COMMENT '周期行驶里程 M2-M1',
  `est_kwh` decimal(10,2) DEFAULT NULL COMMENT '周期估算总电量(度)',
  `ext_kwh` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期内外部快充真实度数',
  `slow_kwh` decimal(10,2) DEFAULT NULL COMMENT '厂区慢充估算度数=est-ext',
  `slow_price` decimal(6,2) DEFAULT NULL COMMENT '核算时慢充电价(元/度)',
  `slow_cost` decimal(10,2) DEFAULT NULL COMMENT '厂区慢充电费(元)',
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='满电周期核算';

-- --------------------------------------------------------

--
-- 表的结构 `charge_records`
--

CREATE TABLE `charge_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `charge_time` datetime NOT NULL COMMENT '充电时间',
  `charge_type` enum('满电','零散补电','外部快充') NOT NULL COMMENT '充电类型',
  `mileage` int(11) NOT NULL DEFAULT '0' COMMENT '充电时仪表里程(km)',
  `kwh` decimal(8,2) DEFAULT NULL COMMENT '真实度数(外部快充，按电表)',
  `price` decimal(6,2) DEFAULT NULL COMMENT '单价(元/度)',
  `amount` decimal(10,2) DEFAULT NULL COMMENT '金额(元，外部快充)',
  `station` varchar(100) DEFAULT '' COMMENT '充电站/充电点',
  `receipt_photo` varchar(255) DEFAULT '' COMMENT '凭证照片',
  `mileage_photo` varchar(255) DEFAULT '' COMMENT '仪表里程照片',
  `source` enum('admin','driver') NOT NULL DEFAULT 'admin' COMMENT '录入来源',
  `driver_name` varchar(50) DEFAULT '' COMMENT '司机姓名',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充电记录';

-- --------------------------------------------------------

--
-- 表的结构 `fuel_records`
--

CREATE TABLE `fuel_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `fuel_date` datetime NOT NULL COMMENT '加油时间',
  `mileage` int(11) NOT NULL DEFAULT '0' COMMENT '加油时仪表里程(km)',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '加油金额(元)',
  `price` decimal(6,2) DEFAULT NULL COMMENT '单价(元/升)',
  `liters` decimal(8,2) DEFAULT NULL COMMENT '加油量(升)',
  `station` varchar(100) DEFAULT '' COMMENT '加油站',
  `receipt_photo` varchar(255) DEFAULT '' COMMENT '金额小票照片',
  `mileage_photo` varchar(255) DEFAULT '' COMMENT '仪表里程照片',
  `source` enum('admin','driver') NOT NULL DEFAULT 'admin' COMMENT '录入来源',
  `driver_name` varchar(50) DEFAULT '' COMMENT '司机姓名',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='加油记录';

--
-- 转存表中的数据 `fuel_records`
--

INSERT INTO `fuel_records` (`id`, `tenant_id`, `vehicle_id`, `fuel_date`, `mileage`, `amount`, `price`, `liters`, `station`, `receipt_photo`, `mileage_photo`, `source`, `driver_name`, `remark`, `created_at`) VALUES
(1, 1, 1, '2026-09-19 20:48:00', 5645, 500.00, 8.90, 56.17, '爱微圈加油站', '', '', 'admin', '爱微圈', '', '2026-09-19 20:49:16');

-- --------------------------------------------------------

--
-- 表的结构 `inspection_records`
--

CREATE TABLE `inspection_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `inspect_date` date NOT NULL COMMENT '检车日期',
  `next_due_date` date NOT NULL COMMENT '下次检车到期日',
  `result` enum('合格','不合格') NOT NULL DEFAULT '合格',
  `station` varchar(100) DEFAULT '' COMMENT '检测站',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '费用(元)',
  `remark` varchar(255) DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='检车记录';

-- --------------------------------------------------------

--
-- 表的结构 `insurance_records`
--

CREATE TABLE `insurance_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `ins_type` enum('交强险','商业险','承运人责任险','其他') NOT NULL DEFAULT '交强险',
  `company` varchar(100) DEFAULT '' COMMENT '保险公司',
  `policy_no` varchar(60) DEFAULT '' COMMENT '保单号',
  `start_date` date NOT NULL COMMENT '保险起期',
  `end_date` date NOT NULL COMMENT '保险止期',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '保费(元)',
  `remark` varchar(255) DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='保险记录';

--
-- 转存表中的数据 `insurance_records`
--

INSERT INTO `insurance_records` (`id`, `tenant_id`, `vehicle_id`, `ins_type`, `company`, `policy_no`, `start_date`, `end_date`, `amount`, `remark`, `created_by`, `created_at`) VALUES
(1, 1, 1, '交强险', '中国人民财产保险', '20261656454326356', '2026-09-19', '2027-06-18', 9865.00, '爱微圈  aiweiquan.cn', 2, '2026-09-19 20:48:13');

-- --------------------------------------------------------

--
-- 表的结构 `maintenance_records`
--

CREATE TABLE `maintenance_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `mt_date` date NOT NULL COMMENT '保养日期',
  `mileage` int(11) NOT NULL DEFAULT '0' COMMENT '保养时里程(km)',
  `items` varchar(255) NOT NULL DEFAULT '' COMMENT '保养项目',
  `cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '费用(元)',
  `vendor` varchar(100) DEFAULT '' COMMENT '保养单位',
  `next_date` date DEFAULT NULL COMMENT '下次保养日期',
  `next_mileage` int(11) DEFAULT NULL COMMENT '下次保养里程(km)',
  `remark` varchar(255) DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='保养记录';

-- --------------------------------------------------------

--
-- 表的结构 `op_logs`
--

CREATE TABLE `op_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `ltype` varchar(20) NOT NULL DEFAULT '' COMMENT '日志类型',
  `actor` varchar(50) NOT NULL DEFAULT '' COMMENT '操作人',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '操作',
  `detail` varchar(500) NOT NULL DEFAULT '' COMMENT '详情',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

--
-- 转存表中的数据 `op_logs`
--

INSERT INTO `op_logs` (`id`, `tenant_id`, `ltype`, `actor`, `action`, `detail`, `ip`, `created_at`) VALUES
(1, 1, '后台补录', 'admin', '补录加油记录', '车牌 苏A8K31C，金额 500 元，里程 5645 km，时间 2026-09-19 20:48:00', '192.168.1.3', '2026-09-19 20:49:16');

-- --------------------------------------------------------

--
-- 表的结构 `reminder_log`
--

CREATE TABLE `reminder_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `rtype` varchar(20) NOT NULL COMMENT 'insurance/inspection/maintenance/tenant_expire',
  `ref_id` int(10) UNSIGNED NOT NULL COMMENT '记录ID',
  `due_key` varchar(20) NOT NULL COMMENT '到期日标识',
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提醒发送日志';

-- --------------------------------------------------------

--
-- 表的结构 `repair_records`
--

CREATE TABLE `repair_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `report_date` date NOT NULL COMMENT '报修日期',
  `repair_date` date DEFAULT NULL COMMENT '维修完成日期',
  `mileage` int(11) DEFAULT NULL COMMENT '维修时里程(km)',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '维修项目',
  `description` text COMMENT '故障描述',
  `cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '维修费用(元)',
  `vendor` varchar(100) DEFAULT '' COMMENT '维修厂',
  `status` enum('待处理','维修中','已完成') NOT NULL DEFAULT '待处理',
  `remark` varchar(255) DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='维修记录';

-- --------------------------------------------------------

--
-- 表的结构 `settings`
--

CREATE TABLE `settings` (
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `skey` varchar(50) NOT NULL,
  `svalue` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

--
-- 转存表中的数据 `settings`
--

INSERT INTO `settings` (`tenant_id`, `skey`, `svalue`) VALUES
(1, 'notify_emails', ''),
(1, 'remind_days_inspection', '30'),
(1, 'remind_days_insurance', '30'),
(1, 'remind_days_maintenance', '15'),
(1, 'remind_km_maintenance', '500'),
(1, 'site_name', '车辆管理系统'),
(1, 'slow_charge_price', '0.80');

-- --------------------------------------------------------

--
-- 表的结构 `tenants`
--

CREATE TABLE `tenants` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL COMMENT '企业名称',
  `contact_name` varchar(50) DEFAULT '' COMMENT '联系人',
  `contact_phone` varchar(30) DEFAULT '' COMMENT '联系电话',
  `plan_name` varchar(30) NOT NULL DEFAULT '试用版' COMMENT '套餐名称',
  `max_vehicles` int(11) NOT NULL DEFAULT '5' COMMENT '车辆数上限（0=不限）',
  `expires_at` date DEFAULT NULL COMMENT '到期日（NULL=长期有效）',
  `status` enum('正常','停用') NOT NULL DEFAULT '正常',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户（企业）';

--
-- 转存表中的数据 `tenants`
--

INSERT INTO `tenants` (`id`, `name`, `contact_name`, `contact_phone`, `plan_name`, `max_vehicles`, `expires_at`, `status`, `remark`, `created_at`) VALUES
(1, '我的企业', '', '', '正式版', 0, NULL, '正常', '', '2026-09-19 20:44:11');

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户（0=平台超级管理员）',
  `username` varchar(50) NOT NULL COMMENT '登录名（全平台唯一）',
  `password` varchar(255) NOT NULL COMMENT '密码哈希',
  `name` varchar(50) NOT NULL COMMENT '姓名',
  `role` enum('super','admin','boss','finance') NOT NULL DEFAULT 'finance' COMMENT '角色：超管/管理员/老板/财务',
  `email` varchar(100) DEFAULT '' COMMENT '邮箱（接收提醒）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统用户';

--
-- 转存表中的数据 `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `username`, `password`, `name`, `role`, `email`, `status`, `created_at`) VALUES
(1, 0, 'superadmin', '$2y$10$tLyXaL7pyJywvRHLyX1Z3.WP7tTr0Hom/0DYb4Zz6rzLn1G0y8SBu', '平台超管', 'super', '', 1, '2026-09-19 20:44:11'),
(2, 1, 'admin', '$2y$10$WWYZZpoCWBGGRL6DX9HPrujZCh6rT9DysDLZ6yU9CIDXv9b8mtZ/S', '系统管理员', 'admin', '', 1, '2026-09-19 20:44:11');

-- --------------------------------------------------------

--
-- 表的结构 `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '所属租户',
  `plate_no` varchar(20) NOT NULL COMMENT '车牌号',
  `brand` varchar(50) DEFAULT '' COMMENT '品牌',
  `model` varchar(50) DEFAULT '' COMMENT '型号',
  `color` varchar(20) DEFAULT '' COMMENT '颜色',
  `vin` varchar(30) DEFAULT '' COMMENT '车架号',
  `engine_no` varchar(30) DEFAULT '' COMMENT '发动机号',
  `fuel_type` varchar(20) DEFAULT '汽油' COMMENT '燃油类型',
  `energy_consumption` decimal(6,2) DEFAULT NULL COMMENT '百公里电耗(kWh/100km，新能源车型)',
  `register_date` date DEFAULT NULL COMMENT '注册登记日期',
  `purchase_date` date DEFAULT NULL COMMENT '购置日期',
  `current_mileage` int(11) NOT NULL DEFAULT '0' COMMENT '当前里程(km)',
  `status` enum('在用','停用','维修中') NOT NULL DEFAULT '在用',
  `upload_token` char(32) NOT NULL COMMENT '司机扫码上传令牌',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='车辆档案';

--
-- 转存表中的数据 `vehicles`
--

INSERT INTO `vehicles` (`id`, `tenant_id`, `plate_no`, `brand`, `model`, `color`, `vin`, `engine_no`, `fuel_type`, `energy_consumption`, `register_date`, `purchase_date`, `current_mileage`, `status`, `upload_token`, `remark`, `created_at`, `updated_at`) VALUES
(1, 1, '苏A8K31C', '奔驰', 'E300L', '黑色', 'VD6565G6SD5F65D6DS', 'fd545g4ds64f56', '汽油', 9.00, '2026-09-19', '2026-09-19', 6650, '在用', 'af21b3c517b3c2fbf7672542c4e310d3', '爱微圈 测试 aiweiquan.cn', '2026-09-19 20:46:49', '2026-09-19 20:46:49');

--
-- 转储表的索引
--

--
-- 表的索引 `charge_cycles`
--
ALTER TABLE `charge_cycles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_end_record` (`end_record_id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`);

--
-- 表的索引 `charge_records`
--
ALTER TABLE `charge_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_time` (`charge_time`),
  ADD KEY `idx_type` (`charge_type`);

--
-- 表的索引 `fuel_records`
--
ALTER TABLE `fuel_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_date` (`fuel_date`);

--
-- 表的索引 `inspection_records`
--
ALTER TABLE `inspection_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_due` (`next_due_date`);

--
-- 表的索引 `insurance_records`
--
ALTER TABLE `insurance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_end_date` (`end_date`);

--
-- 表的索引 `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_next_date` (`next_date`);

--
-- 表的索引 `op_logs`
--
ALTER TABLE `op_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`,`created_at`);

--
-- 表的索引 `reminder_log`
--
ALTER TABLE `reminder_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_once` (`tenant_id`,`rtype`,`ref_id`,`due_key`);

--
-- 表的索引 `repair_records`
--
ALTER TABLE `repair_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_status` (`status`);

--
-- 表的索引 `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`tenant_id`,`skey`);

--
-- 表的索引 `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_username` (`username`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- 表的索引 `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_plate` (`tenant_id`,`plate_no`),
  ADD UNIQUE KEY `uk_token` (`upload_token`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `charge_cycles`
--
ALTER TABLE `charge_cycles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `charge_records`
--
ALTER TABLE `charge_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `fuel_records`
--
ALTER TABLE `fuel_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `inspection_records`
--
ALTER TABLE `inspection_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `insurance_records`
--
ALTER TABLE `insurance_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `maintenance_records`
--
ALTER TABLE `maintenance_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `op_logs`
--
ALTER TABLE `op_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `reminder_log`
--
ALTER TABLE `reminder_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `repair_records`
--
ALTER TABLE `repair_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 限制导出的表
--

--
-- 限制表 `charge_cycles`
--
ALTER TABLE `charge_cycles`
  ADD CONSTRAINT `fk_cycle_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `charge_records`
--
ALTER TABLE `charge_records`
  ADD CONSTRAINT `fk_charge_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `fuel_records`
--
ALTER TABLE `fuel_records`
  ADD CONSTRAINT `fk_fuel_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `inspection_records`
--
ALTER TABLE `inspection_records`
  ADD CONSTRAINT `fk_insp_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `insurance_records`
--
ALTER TABLE `insurance_records`
  ADD CONSTRAINT `fk_ins_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD CONSTRAINT `fk_mt_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- 限制表 `repair_records`
--
ALTER TABLE `repair_records`
  ADD CONSTRAINT `fk_repair_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

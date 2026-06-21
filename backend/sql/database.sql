-- 海外仓一件代发系统数据库
CREATE DATABASE IF NOT EXISTS `overseas_warehouse` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `overseas_warehouse`;

-- 仓库表
CREATE TABLE IF NOT EXISTS `warehouses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `warehouse_code` VARCHAR(32) NOT NULL UNIQUE COMMENT '仓库编码',
    `warehouse_name` VARCHAR(100) NOT NULL COMMENT '仓库名称',
    `country` VARCHAR(50) NOT NULL COMMENT '所在国家',
    `state` VARCHAR(100) DEFAULT NULL COMMENT '所在州/省',
    `city` VARCHAR(100) DEFAULT NULL COMMENT '所在城市',
    `address` VARCHAR(255) DEFAULT NULL COMMENT '详细地址',
    `latitude` DECIMAL(10,7) DEFAULT NULL COMMENT '纬度',
    `longitude` DECIMAL(10,7) DEFAULT NULL COMMENT '经度',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1-启用 0-停用',
    `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级 数字越大越优先',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_country` (`country`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='海外仓信息表';

-- 仓库服务区域表（定义每个仓库支持配送的区域及时效）
CREATE TABLE IF NOT EXISTS `warehouse_shipping_zones` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `warehouse_id` BIGINT UNSIGNED NOT NULL,
    `country` VARCHAR(50) NOT NULL COMMENT '配送目标国家',
    `state` VARCHAR(100) DEFAULT NULL COMMENT '目标州/省',
    `shipping_days_min` INT NOT NULL DEFAULT 3 COMMENT '最少配送天数',
    `shipping_days_max` INT NOT NULL DEFAULT 7 COMMENT '最多配送天数',
    `shipping_cost_base` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '基础运费',
    `shipping_cost_per_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每公斤运费',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1-启用 0-停用',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_warehouse_region` (`warehouse_id`, `country`, `state`),
    CONSTRAINT `fk_zone_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库配送区域表';

-- 商品库存表
CREATE TABLE IF NOT EXISTS `products` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(64) NOT NULL UNIQUE COMMENT '商品SKU',
    `name` VARCHAR(200) NOT NULL COMMENT '商品名称',
    `weight` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '重量(kg)',
    `volume` DECIMAL(10,3) DEFAULT NULL COMMENT '体积(m3)',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '销售价格',
    `image_url` VARCHAR(500) DEFAULT NULL COMMENT '商品图片',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1-上架 0-下架',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品信息表';

-- 仓库库存表
CREATE TABLE IF NOT EXISTS `warehouse_inventories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `warehouse_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `sku` VARCHAR(64) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0 COMMENT '可用库存',
    `reserved_quantity` INT NOT NULL DEFAULT 0 COMMENT '锁定库存',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_warehouse_product` (`warehouse_id`, `product_id`),
    KEY `idx_sku` (`sku`),
    CONSTRAINT `fk_inv_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库库存表';

-- 订单表
CREATE TABLE IF NOT EXISTS `orders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(32) NOT NULL UNIQUE COMMENT '订单号',
    `external_order_no` VARCHAR(64) DEFAULT NULL COMMENT '外部订单号(平台订单号)',
    `warehouse_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '路由分配的仓库ID',
    `warehouse_code` VARCHAR(32) DEFAULT NULL COMMENT '仓库编码',
    `warehouse_order_no` VARCHAR(64) DEFAULT NULL COMMENT '仓库系统订单号',
    `customer_name` VARCHAR(100) NOT NULL COMMENT '收件人姓名',
    `customer_phone` VARCHAR(32) NOT NULL COMMENT '收件人电话',
    `customer_email` VARCHAR(100) DEFAULT NULL COMMENT '收件人邮箱',
    `shipping_country` VARCHAR(50) NOT NULL COMMENT '收货国家',
    `shipping_state` VARCHAR(100) DEFAULT NULL COMMENT '收货州/省',
    `shipping_city` VARCHAR(100) DEFAULT NULL COMMENT '收货城市',
    `shipping_address` VARCHAR(255) NOT NULL COMMENT '收货详细地址',
    `shipping_zip` VARCHAR(32) DEFAULT NULL COMMENT '邮编',
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '商品总金额',
    `shipping_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '运费',
    `weight_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '总重量(kg)',
    `order_status` TINYINT NOT NULL DEFAULT 0 COMMENT '订单状态 0-待处理 1-已路由 2-已推送仓库 3-仓库已接单 4-已出库 5-已发货 6-已签收 9-已取消',
    `fulfillment_status` TINYINT NOT NULL DEFAULT 0 COMMENT '履约状态 0-未开始 1-拣货中 2-打包中 3-已发货 4-已签收 9-异常',
    `shipping_carrier` VARCHAR(50) DEFAULT NULL COMMENT '物流商',
    `tracking_no` VARCHAR(64) DEFAULT NULL COMMENT '物流单号',
    `estimated_delivery_date` DATE DEFAULT NULL COMMENT '预计送达日期',
    `actual_delivery_date` DATE DEFAULT NULL COMMENT '实际送达日期',
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_order_no` (`order_no`),
    KEY `idx_warehouse` (`warehouse_id`),
    KEY `idx_status` (`order_status`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_order_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单主表';

-- 订单商品明细表
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `order_no` VARCHAR(32) NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `sku` VARCHAR(64) NOT NULL,
    `product_name` VARCHAR(200) NOT NULL COMMENT '商品名称快照',
    `quantity` INT NOT NULL DEFAULT 1 COMMENT '数量',
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '单价快照',
    `weight` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '重量快照(kg)',
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '小计',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_order_id` (`order_id`),
    KEY `idx_order_no` (`order_no`),
    KEY `idx_sku` (`sku`),
    CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单商品明细表';

-- 履约追踪表（记录履约各环节）
CREATE TABLE IF NOT EXISTS `fulfillment_tracks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `order_no` VARCHAR(32) NOT NULL,
    `track_type` VARCHAR(32) NOT NULL COMMENT '类型: ROUTE_ASSIGNED, WMS_PUSHED, WMS_ACCEPTED, PICKING, PACKING, SHIPPED, DELIVERED, EXCEPTION, CANCELLED',
    `track_status` VARCHAR(32) DEFAULT NULL COMMENT '状态',
    `operator` VARCHAR(100) DEFAULT NULL COMMENT '操作人/系统',
    `description` VARCHAR(500) DEFAULT NULL COMMENT '描述',
    `extra_data` JSON DEFAULT NULL COMMENT '扩展数据',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_order_id` (`order_id`),
    KEY `idx_order_no` (`order_no`),
    KEY `idx_track_type` (`track_type`),
    CONSTRAINT `fk_track_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='履约追踪记录表';

-- 仓库回调日志表
CREATE TABLE IF NOT EXISTS `warehouse_callback_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `callback_type` VARCHAR(32) NOT NULL COMMENT '回调类型: ORDER_ACCEPT, ORDER_SHIP, ORDER_DELIVER, ORDER_EXCEPTION',
    `warehouse_code` VARCHAR(32) DEFAULT NULL,
    `warehouse_order_no` VARCHAR(64) DEFAULT NULL,
    `order_no` VARCHAR(32) DEFAULT NULL,
    `request_body` TEXT COMMENT '请求原文',
    `response_body` TEXT COMMENT '响应原文',
    `is_processed` TINYINT NOT NULL DEFAULT 0 COMMENT '是否处理成功 0-否 1-是',
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_order_no` (`order_no`),
    KEY `idx_warehouse_order` (`warehouse_order_no`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库回调日志表';

-- 初始化测试数据
INSERT INTO `warehouses` (`warehouse_code`, `warehouse_name`, `country`, `state`, `city`, `address`, `latitude`, `longitude`, `status`, `priority`) VALUES
('USCA', '美国加州仓', 'US', 'California', 'Los Angeles', '123 Industrial Blvd, LA, CA', 34.052234, -118.243685, 1, 100),
('USNJ', '美国新泽西仓', 'US', 'New Jersey', 'Newark', '456 Port Ave, Newark, NJ', 40.735657, -74.172367, 1, 90),
('GBLN', '英国伦敦仓', 'GB', 'England', 'London', '789 Logistics Park, London', 51.507351, -0.127758, 1, 80),
('DEBE', '德国柏林仓', 'DE', 'Berlin', 'Berlin', '321 Freight Center, Berlin', 52.520007, 13.404954, 1, 70),
('AU SYD', '澳洲悉尼仓', 'AU', 'NSW', 'Sydney', '555 Distribution Hub, Sydney', -33.868820, 151.209295, 1, 60);

INSERT INTO `warehouse_shipping_zones` (`warehouse_id`, `country`, `state`, `shipping_days_min`, `shipping_days_max`, `shipping_cost_base`, `shipping_cost_per_kg`) VALUES
(1, 'US', NULL, 2, 4, 5.99, 1.50),
(1, 'CA', NULL, 5, 8, 12.99, 2.00),
(2, 'US', 'NY', 1, 3, 4.99, 1.20),
(2, 'US', 'NJ', 1, 2, 3.99, 1.00),
(2, 'US', 'PA', 2, 4, 5.99, 1.30),
(2, 'CA', NULL, 4, 7, 11.99, 1.90),
(3, 'GB', NULL, 1, 3, 4.99, 1.20),
(3, 'FR', NULL, 3, 5, 8.99, 1.60),
(3, 'DE', NULL, 3, 5, 8.99, 1.60),
(4, 'DE', NULL, 1, 2, 3.99, 1.00),
(4, 'FR', NULL, 2, 4, 6.99, 1.40),
(4, 'NL', NULL, 2, 3, 5.99, 1.20),
(5, 'AU', NULL, 2, 5, 6.99, 1.40),
(5, 'NZ', NULL, 4, 7, 12.99, 2.00);

INSERT INTO `products` (`sku`, `name`, `weight`, `volume`, `price`, `image_url`, `status`) VALUES
('SKU001', '无线蓝牙耳机', 0.15, 0.001, 29.99, 'https://example.com/sku001.jpg', 1),
('SKU002', '智能手表', 0.30, 0.002, 89.99, 'https://example.com/sku002.jpg', 1),
('SKU003', '便携充电宝 10000mAh', 0.25, 0.0015, 25.99, 'https://example.com/sku003.jpg', 1),
('SKU004', 'USB-C 快充数据线', 0.05, 0.0002, 9.99, 'https://example.com/sku004.jpg', 1),
('SKU005', '笔记本电脑支架', 0.80, 0.005, 35.99, 'https://example.com/sku005.jpg', 1);

INSERT INTO `warehouse_inventories` (`warehouse_id`, `product_id`, `sku`, `quantity`, `reserved_quantity`) VALUES
(1, 1, 'SKU001', 500, 0),
(1, 2, 'SKU002', 200, 0),
(1, 3, 'SKU003', 800, 0),
(1, 4, 'SKU004', 2000, 0),
(1, 5, 'SKU005', 150, 0),
(2, 1, 'SKU001', 600, 0),
(2, 2, 'SKU002', 250, 0),
(2, 3, 'SKU003', 900, 0),
(2, 4, 'SKU004', 2500, 0),
(3, 1, 'SKU001', 300, 0),
(3, 2, 'SKU002', 150, 0),
(3, 3, 'SKU003', 400, 0),
(3, 4, 'SKU004', 1000, 0),
(4, 1, 'SKU001', 350, 0),
(4, 2, 'SKU002', 180, 0),
(4, 5, 'SKU005', 100, 0),
(5, 1, 'SKU001', 200, 0),
(5, 3, 'SKU003', 300, 0),
(5, 4, 'SKU004', 800, 0);

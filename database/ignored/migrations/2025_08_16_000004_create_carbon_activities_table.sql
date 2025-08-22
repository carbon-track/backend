-- Create carbon_activities table to store carbon reduction activities with UUID identifiers
-- This replaces the hardcoded activity strings with a more maintainable database approach

CREATE TABLE IF NOT EXISTS `carbon_activities` (
  `id` char(36) NOT NULL COMMENT 'UUID primary key',
  `name_zh` varchar(255) NOT NULL COMMENT 'Chinese name of the activity',
  `name_en` varchar(255) NOT NULL COMMENT 'English name of the activity',
  `category` varchar(100) NOT NULL COMMENT 'Activity category (daily, transport, consumption, etc.)',
  `carbon_factor` decimal(10, 4) NOT NULL COMMENT 'Carbon reduction factor per unit',
  `unit` varchar(50) NOT NULL COMMENT 'Unit of measurement (times, hours, kg, etc.)',
  `description_zh` text NULL COMMENT 'Chinese description',
  `description_en` text NULL COMMENT 'English description',
  `icon` varchar(100) NULL COMMENT 'Icon identifier for frontend',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether activity is currently available',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT 'Display order',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_carbon_activities_category` (`category`),
  KEY `idx_carbon_activities_is_active` (`is_active`),
  KEY `idx_carbon_activities_sort_order` (`sort_order`),
  KEY `idx_carbon_activities_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Carbon reduction activities with UUID identifiers';

-- Insert existing activities with UUIDs
INSERT INTO `carbon_activities` (`id`, `name_zh`, `name_en`, `category`, `carbon_factor`, `unit`, `icon`, `sort_order`) VALUES
-- Daily Life Activities
('550e8400-e29b-41d4-a716-446655440001', '购物时自带袋子', 'Bring your own bag when shopping', 'daily', 0.0190, 'times', 'shopping-bag', 1),
('550e8400-e29b-41d4-a716-446655440002', '早睡觉一小时', 'Sleep an hour earlier', 'daily', 0.0950, 'times', 'moon', 2),
('550e8400-e29b-41d4-a716-446655440003', '刷牙时关掉水龙头', 'Turn off the tap while brushing teeth', 'daily', 0.0090, 'times', 'water-drop', 3),
('550e8400-e29b-41d4-a716-446655440004', '出门自带水杯', 'Bring your own water bottle', 'daily', 0.0400, 'times', 'bottle', 4),
('550e8400-e29b-41d4-a716-446655440005', '垃圾分类', 'Sort waste properly', 'daily', 0.0004, 'times', 'recycle', 5),
('550e8400-e29b-41d4-a716-446655440006', '减少打印纸', 'Reduce unnecessary printing paper', 'daily', 0.0040, 'sheets', 'printer', 6),
('550e8400-e29b-41d4-a716-446655440007', '减少使用一次性餐盒', 'Reduce disposable meal boxes', 'daily', 0.1900, 'times', 'takeaway-box', 7),
('550e8400-e29b-41d4-a716-446655440008', '简易包装礼物', 'Use minimal gift wrapping', 'daily', 0.1400, 'times', 'gift', 8),
('550e8400-e29b-41d4-a716-446655440009', '夜跑', 'Night running', 'daily', 0.0950, 'times', 'running', 9),
('550e8400-e29b-41d4-a716-446655440010', '自然风干湿发', 'Air-dry wet hair', 'daily', 0.1520, 'times', 'hair-dryer', 10),
('550e8400-e29b-41d4-a716-446655440011', '点外卖选择"无需餐具"', 'Choose No-Cutlery when ordering delivery', 'daily', 0.0540, 'times', 'cutlery', 11),
('550e8400-e29b-41d4-a716-446655440012', '下班时关电脑和灯', 'Turn off computer and lights when off-duty', 'daily', 0.1660, 'times', 'power-off', 12),
('550e8400-e29b-41d4-a716-446655440013', '晚上睡觉全程关灯', 'Keep lights off at night', 'daily', 0.1100, 'times', 'light-bulb', 13),
('550e8400-e29b-41d4-a716-446655440014', '快速洗澡', 'Take a quick shower', 'daily', 0.1200, 'times', 'shower', 14),
('550e8400-e29b-41d4-a716-446655440015', '阳光晾晒衣服', 'Sun-dry clothes', 'daily', 0.3230, 'times', 'clothes-line', 15),
('550e8400-e29b-41d4-a716-446655440016', '夏天空调调至26°C以上', 'Set AC to above 78°F during Summer', 'daily', 0.2190, 'times', 'air-conditioner', 16),
('550e8400-e29b-41d4-a716-446655440017', '攒够一桶衣服再洗', 'Save and wash a full load of clothes', 'daily', 0.4730, 'times', 'washing-machine', 17),
('550e8400-e29b-41d4-a716-446655440018', '化妆品用完购买替代装', 'Buy refillable cosmetics or toiletries', 'consumption', 0.0850, 'times', 'cosmetics', 18),

-- Food & Consumption
('550e8400-e29b-41d4-a716-446655440019', '购买本地应季水果', 'Buy local seasonal fruits', 'consumption', 2.9800, 'kg', 'fruit', 19),
('550e8400-e29b-41d4-a716-446655440020', '自己做饭', 'Cook at home', 'consumption', 0.1900, 'times', 'cooking', 20),
('550e8400-e29b-41d4-a716-446655440021', '吃一顿轻食', 'Have a light meal', 'consumption', 0.3600, 'times', 'salad', 21),
('550e8400-e29b-41d4-a716-446655440022', '吃完水果蔬菜', 'Finish all fruits and vegetables', 'consumption', 0.0163, 'times', 'vegetables', 22),
('550e8400-e29b-41d4-a716-446655440023', '光盘行动', 'Finish all food on the plate', 'consumption', 0.0163, 'times', 'clean-plate', 23),
('550e8400-e29b-41d4-a716-446655440024', '喝燕麦奶或植物基食品', 'Drink oat milk or plant-based food', 'consumption', 0.6430, 'times', 'plant-milk', 24),

-- Transportation
('550e8400-e29b-41d4-a716-446655440025', '公交地铁通勤', 'Use public transport', 'transport', 0.1005, 'km', 'bus', 25),
('550e8400-e29b-41d4-a716-446655440026', '骑行探索城市', 'Explore the city by bike', 'transport', 0.1490, 'km', 'bicycle', 26),
('550e8400-e29b-41d4-a716-446655440027', '乘坐快轨去机场', 'Take high-speed rail to the airport', 'transport', 3.8700, 'times', 'train', 27),
('550e8400-e29b-41d4-a716-446655440028', '拼车', 'Carpool', 'transport', 0.0450, 'km', 'carpool', 28),
('550e8400-e29b-41d4-a716-446655440029', '自行车出行', 'Travel by bike', 'transport', 0.1490, 'km', 'bike-travel', 29),

-- Environmental & Others
('550e8400-e29b-41d4-a716-446655440030', '种一棵树', 'Plant a tree', 'environmental', 10.0000, 'trees', 'tree', 30),
('550e8400-e29b-41d4-a716-446655440031', '购买二手书', 'Buy a second-hand book', 'consumption', 2.8800, 'books', 'book', 31),
('550e8400-e29b-41d4-a716-446655440032', '旅行时自备洗漱用品', 'Bring your own toiletries when traveling', 'travel', 0.0470, 'times', 'toiletries', 32),
('550e8400-e29b-41d4-a716-446655440033', '旧物改造', 'Repurpose old items', 'consumption', 0.7700, 'items', 'recycle-item', 33),
('550e8400-e29b-41d4-a716-446655440034', '购买一级能效家电', 'Buy an energy-efficient appliance', 'consumption', 2.1500, 'appliances', 'energy-star', 34),
('550e8400-e29b-41d4-a716-446655440035', '购买白色或浅色衣物', 'Buy white or light-colored clothes', 'consumption', 3.4300, 'items', 'white-clothes', 35),
('550e8400-e29b-41d4-a716-446655440036', '花一天享受户外', 'Spend a full day outdoors', 'lifestyle', 0.7570, 'days', 'outdoor', 36),
('550e8400-e29b-41d4-a716-446655440037', '自己种菜并吃', 'Grow and eat your own vegetables', 'environmental', 0.0250, 'kg', 'garden', 37),
('550e8400-e29b-41d4-a716-446655440038', '减少使用手机时间', 'Reduce screen time', 'lifestyle', 0.0003, 'minutes', 'phone-time', 38),

-- Special Activities (from speccal.php)
('550e8400-e29b-41d4-a716-446655440039', '节约用电1度', 'Save 1 kWh electricity', 'energy', 1.0000, 'kWh', 'electricity', 39),
('550e8400-e29b-41d4-a716-446655440040', '节约用水1L', 'Save 1L water', 'water', 1.0000, 'liters', 'water-save', 40),
('550e8400-e29b-41d4-a716-446655440041', '垃圾分类1次', 'Sort waste once', 'waste', 145.0000, 'times', 'waste-sort', 41);


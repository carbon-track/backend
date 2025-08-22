-- Update points_transactions table to use activity_id instead of act string

-- Add activity_id column
ALTER TABLE `points_transactions`
ADD COLUMN `activity_id` CHAR(36) NULL COMMENT 'UUID reference to carbon_activities table' AFTER `uid`;

-- Add foreign key constraint
-- 原始表为 MyISAM，无法添加外键；为兼容旧库跳过外键，仅创建索引。

-- Add index for better performance
CREATE INDEX `idx_points_transactions_activity_id` ON `points_transactions` (`activity_id`);

-- Migrate existing data: map act strings to activity UUIDs
-- This is a one-time migration script to convert existing string-based activities to UUID references

UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440001' WHERE `act` = '购物时自带袋子 / Bring your own bag when shopping';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440002' WHERE `act` = '早睡觉一小时 / Sleep an hour earlier';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440003' WHERE `act` = '刷牙时关掉水龙头 / Turn off the tap while brushing teeth';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440004' WHERE `act` = '出门自带水杯 / Bring your own water bottle';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440005' WHERE `act` = '垃圾分类 / Sort waste properly';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440006' WHERE `act` = '减少打印纸 / Reduce unnecessary printing paper';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440007' WHERE `act` = '减少使用一次性餐盒 / Reduce disposable meal boxes';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440008' WHERE `act` = '简易包装礼物 / Use minimal gift wrapping';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440009' WHERE `act` = '夜跑 / Night running';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440010' WHERE `act` = '自然风干湿发 / Air-dry wet hair';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440011' WHERE `act` = '点外卖选择"无需餐具" / Choose No-Cutlery when ordering delivery';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440012' WHERE `act` = '下班时关电脑和灯 / Turn off computer and lights when off-duty';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440013' WHERE `act` = '晚上睡觉全程关灯 / Keep lights off at night';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440014' WHERE `act` = '快速洗澡 / Take a quick shower';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440015' WHERE `act` = '阳光晾晒衣服 / Sun-dry clothes';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440016' WHERE `act` = '夏天空调调至26°C以上 / Set AC to above 78°F during Summer';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440017' WHERE `act` = '攒够一桶衣服再洗 / Save and wash a full load of clothes';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440018' WHERE `act` = '化妆品用完购买替代装 / Buy refillable cosmetics or toiletries';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440019' WHERE `act` = '购买本地应季水果 / Buy local seasonal fruits';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440020' WHERE `act` = '自己做饭 / Cook at home';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440021' WHERE `act` = '吃一顿轻食 / Have a light meal';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440022' WHERE `act` = '吃完水果蔬菜 / Finish all fruits and vegetables';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440023' WHERE `act` = '光盘行动 / Finish all food on the plate';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440024' WHERE `act` = '喝燕麦奶或植物基食品 / Drink oat milk or plant-based food';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440025' WHERE `act` = '公交地铁通勤 / Use public transport';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440026' WHERE `act` = '骑行探索城市 / Explore the city by bike';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440027' WHERE `act` = '乘坐快轨去机场 / Take high-speed rail to the airport';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440028' WHERE `act` = '拼车 / Carpool';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440029' WHERE `act` = '自行车出行 / Travel by bike';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440030' WHERE `act` = '种一棵树 / Plant a tree';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440031' WHERE `act` = '购买二手书 / Buy a second-hand book';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440032' WHERE `act` = '旅行时自备洗漱用品 / Bring your own toiletries when traveling';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440033' WHERE `act` = '旧物改造 / Repurpose old items';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440034' WHERE `act` = '购买一级能效家电 / Buy an energy-efficient appliance';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440035' WHERE `act` = '购买白色或浅色衣物 / Buy white or light-colored clothes';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440036' WHERE `act` = '花一天享受户外 / Spend a full day outdoors';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440037' WHERE `act` = '自己种菜并吃 / Grow and eat your own vegetables';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440038' WHERE `act` = '减少使用手机时间 / Reduce screen time';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440039' WHERE `act` = '节约用电1度';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440040' WHERE `act` = '节约用水1L';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440041' WHERE `act` = '垃圾分类1次';

-- After migration, the act column can be kept for backward compatibility or removed
-- For now, we'll keep it but make it nullable since activity_id is the new primary reference
ALTER TABLE `points_transactions` MODIFY COLUMN `act` VARCHAR(255) NULL COMMENT 'Legacy activity name (deprecated, use activity_id instead)';


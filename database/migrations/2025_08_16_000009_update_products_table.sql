-- Update products table to match application requirements

-- If legacy products table exists with product_id, rename to id and add new columns
-- This script is compatible with MySQL 5.6 (no IF EXISTS/IF NOT EXISTS on columns)

-- Rename primary key column if needed
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'products' 
   AND table_schema = DATABASE()
   AND column_name = 'product_id') > 0,
  'ALTER TABLE `products` CHANGE `product_id` `id` int(11) NOT NULL AUTO_INCREMENT',
  'SELECT "products.product_id does not exist or already migrated"'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add missing columns
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='category') > 0,
  'SELECT "category exists"',
  'ALTER TABLE `products` ADD COLUMN `category` varchar(100) NULL AFTER `name`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='images') > 0,
  'SELECT "images exists"',
  'ALTER TABLE `products` ADD COLUMN `images` longtext NULL COMMENT "JSON array of image URLs" AFTER `image_path`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='status') > 0,
  'SELECT "status exists"',
  "ALTER TABLE `products` ADD COLUMN `status` enum('active','inactive') NOT NULL DEFAULT 'active' AFTER `stock`"
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='sort_order') > 0,
  'SELECT "sort_order exists"',
  'ALTER TABLE `products` ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Timestamps & soft delete
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='created_at') > 0,
  'SELECT "created_at exists"',
  'ALTER TABLE `products` ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='updated_at') > 0,
  'SELECT "updated_at exists"',
  'ALTER TABLE `products` ADD COLUMN `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='deleted_at') > 0,
  'SELECT "deleted_at exists"',
  'ALTER TABLE `products` ADD COLUMN `deleted_at` datetime NULL'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful indexes
CREATE INDEX `idx_products_status` ON `products` (`status`);
CREATE INDEX `idx_products_category` ON `products` (`category`);
CREATE INDEX `idx_products_sort_order` ON `products` (`sort_order`);



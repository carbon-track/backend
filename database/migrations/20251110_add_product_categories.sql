-- Add product categories table and category slug column
CREATE TABLE IF NOT EXISTS product_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_product_categories_slug (slug),
  KEY idx_product_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS category_slug VARCHAR(160) NULL;

-- Backfill existing category slugs for legacy data
UPDATE products
SET category_slug = LOWER(TRIM(REPLACE(REPLACE(REPLACE(category, ' ', '-'), '\', ''), '/', '-')))
WHERE category IS NOT NULL AND category <> '' AND (category_slug IS NULL OR category_slug = '');

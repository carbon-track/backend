ALTER TABLE point_exchanges
  MODIFY COLUMN status ENUM('pending','processing','shipped','completed','cancelled','rejected')
  NOT NULL DEFAULT 'pending';

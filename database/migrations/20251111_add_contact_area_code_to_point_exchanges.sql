-- Add contact area code field for point exchange records
ALTER TABLE point_exchanges
  ADD COLUMN IF NOT EXISTS contact_area_code VARCHAR(20) NULL AFTER delivery_address;

-- Normalise empty values created prior to this change
UPDATE point_exchanges
SET contact_area_code = NULL
WHERE contact_area_code = '';

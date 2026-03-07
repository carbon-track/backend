ALTER TABLE `multipart_uploads`
  ADD COLUMN `sha256` varchar(64) DEFAULT NULL AFTER `file_path`;
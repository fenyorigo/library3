-- BookCatalog v3 schema 3.3.0
-- Adds optional SHA256 checksum storage for ebook copies.

ALTER TABLE `BookCopies`
  ADD COLUMN `sha256` CHAR(64) DEFAULT NULL AFTER `file_size`;

CREATE INDEX `idx_bookcopies_sha256` ON `BookCopies` (`sha256`);

INSERT INTO `SystemInfo` (`key_name`, `value`)
VALUES ('schema_version', '3.3.0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

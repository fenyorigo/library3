-- BookCatalog v3 schema 3.2.0
-- Adds global ebook library root setting and converts stored ebook paths to /Books-relative values.

CREATE TABLE IF NOT EXISTS `Settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Settings` (`setting_key`, `setting_value`, `description`)
VALUES ('ebook_library_root', '/Volumes/SanDisk 2T', 'Absolute path to the mounted ebook library root')
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `updated_at` = CURRENT_TIMESTAMP;

UPDATE `BookCopies`
SET `file_path` = CONCAT('/Books/', SUBSTRING(`file_path`, CHAR_LENGTH('/Volumes/SanDisk 2T/Books/') + 1))
WHERE `file_path` LIKE '/Volumes/SanDisk 2T/Books/%';

UPDATE `BookCopies`
SET `file_path` = CONCAT('/Books/', SUBSTRING(`file_path`, CHAR_LENGTH('/Volumes/Samsung 2T/Books/') + 1))
WHERE `file_path` LIKE '/Volumes/Samsung 2T/Books/%';

UPDATE `BookCopies`
SET `file_path` = CONCAT('/Books/', SUBSTRING(`file_path`, CHAR_LENGTH('/Volumes/Sanmsung 2T/Books/') + 1))
WHERE `file_path` LIKE '/Volumes/Sanmsung 2T/Books/%';

UPDATE `BookCopies`
SET `file_path` = '/Books'
WHERE `file_path` IN ('/Volumes/SanDisk 2T/Books', '/Volumes/Samsung 2T/Books', '/Volumes/Sanmsung 2T/Books');

INSERT INTO `SystemInfo` (`key_name`, `value`)
VALUES ('schema_version', '3.2.0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

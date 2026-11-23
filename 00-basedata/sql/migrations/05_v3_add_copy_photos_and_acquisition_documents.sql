-- BookCatalog schema 3.4.0; additive migration from 3.3.0.
-- Run with a client that stops on SQL errors (never use --force).
-- DDL commits implicitly. Back up the database before applying.

CREATE TABLE IF NOT EXISTS `BookCopyPhotos` (
  `photo_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `copy_id` bigint(20) unsigned NOT NULL,
  `slot_number` tinyint(3) unsigned NOT NULL,
  `file_path` varchar(1024) NOT NULL COMMENT 'Portable relative path below private attachment root',
  `mime_type` varchar(100) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `photo_type` varchar(32) NOT NULL DEFAULT 'other',
  `caption` text DEFAULT NULL,
  `taken_on` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`photo_id`),
  UNIQUE KEY `uniq_bookcopyphotos_slot` (`copy_id`, `slot_number`),
  CONSTRAINT `chk_bookcopyphotos_slot` CHECK (`slot_number` BETWEEN 1 AND 10),
  CONSTRAINT `chk_bookcopyphotos_path` CHECK (CHAR_LENGTH(TRIM(`file_path`)) > 0),
  CONSTRAINT `fk_bookcopyphotos_copy` FOREIGN KEY (`copy_id`) REFERENCES `BookCopies` (`copy_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AcquisitionDocuments` (
  `document_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_type` varchar(32) NOT NULL DEFAULT 'invoice',
  `title` varchar(512) NOT NULL,
  `file_path` varchar(1024) DEFAULT NULL COMMENT 'Optional portable relative path below private attachment root',
  `mime_type` varchar(100) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `document_number` varchar(190) DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `seller` varchar(512) DEFAULT NULL,
  `original_location` text DEFAULT NULL COMMENT 'Physical location of the paper original',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_acquisitiondocuments_number` (`document_number`),
  CONSTRAINT `chk_acquisitiondocuments_title` CHECK (CHAR_LENGTH(TRIM(`title`)) > 0),
  CONSTRAINT `chk_acquisitiondocuments_path` CHECK (`file_path` IS NULL OR CHAR_LENGTH(TRIM(`file_path`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `BookCopyAcquisitionDocuments` (
  `copy_id` bigint(20) unsigned NOT NULL,
  `document_id` bigint(20) unsigned NOT NULL,
  `item_reference` varchar(190) DEFAULT NULL COMMENT 'Line or lot identifier within the document',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`copy_id`, `document_id`),
  KEY `idx_copyacquisitiondocuments_document` (`document_id`),
  CONSTRAINT `fk_copyacquisitiondocuments_copy` FOREIGN KEY (`copy_id`) REFERENCES `BookCopies` (`copy_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_copyacquisitiondocuments_document` FOREIGN KEY (`document_id`) REFERENCES `AcquisitionDocuments` (`document_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `SystemInfo` (`key_name`, `value`)
VALUES ('schema_version', '3.4.0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

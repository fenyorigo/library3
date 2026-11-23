-- BookCatalog schema 3.5.0, additive migration from 3.4.0.
-- Back up first; run without --force. Does not change Books or BookCopies.
CREATE TABLE IF NOT EXISTS `ValuationImports` (
  `import_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content_sha256` char(64) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `record_count` int unsigned NOT NULL,
  `imported_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`import_id`),
  UNIQUE KEY `uniq_valuationimports_sha256` (`content_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `BookAssessments` (
  `assessment_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `book_id` int unsigned NOT NULL,
  `import_id` bigint unsigned NOT NULL,
  `used_amount` decimal(14,2) DEFAULT NULL,
  `replacement_amount` decimal(14,2) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'HUF',
  `used_confidence` text DEFAULT NULL,
  `replacement_confidence` text DEFAULT NULL,
  `used_method` text DEFAULT NULL,
  `replacement_method` text DEFAULT NULL,
  `is_high_value` tinyint(1) NOT NULL DEFAULT 0,
  `preservation_label` varchar(190) DEFAULT NULL,
  `priority` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `heirs_action` text DEFAULT NULL,
  `research_status` text DEFAULT NULL,
  `group_key` varchar(190) DEFAULT NULL,
  `owner_condition` text DEFAULT NULL,
  `provenance` text DEFAULT NULL,
  `completeness` text DEFAULT NULL,
  `checked_on` date DEFAULT NULL,
  `raw_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assessment_id`),
  UNIQUE KEY `uniq_bookassessments_import_book` (`import_id`,`book_id`),
  KEY `idx_bookassessments_latest` (`book_id`,`assessment_id`),
  KEY `idx_bookassessments_high_value` (`is_high_value`,`book_id`),
  CONSTRAINT `chk_bookassessments_used` CHECK (`used_amount` IS NULL OR `used_amount` >= 0),
  CONSTRAINT `chk_bookassessments_replacement` CHECK (`replacement_amount` IS NULL OR `replacement_amount` >= 0),
  CONSTRAINT `fk_bookassessments_book` FOREIGN KEY (`book_id`) REFERENCES `Books` (`book_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bookassessments_import` FOREIGN KEY (`import_id`) REFERENCES `ValuationImports` (`import_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PriceObservations` (
  `observation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `import_id` bigint unsigned NOT NULL,
  `source_key` varchar(100) NOT NULL,
  `title` text NOT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `shipping_amount` decimal(14,2) DEFAULT NULL,
  `observation_kind` text NOT NULL,
  `edition_match` text DEFAULT NULL,
  `source_url` text DEFAULT NULL,
  `seller` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `event_date_text` text DEFAULT NULL,
  `checked_on` date DEFAULT NULL,
  `record_ids` text DEFAULT NULL COMMENT 'Scope: a shared set price must not be multiplied per volume',
  `raw_json` longtext NOT NULL,
  PRIMARY KEY (`observation_id`),
  UNIQUE KEY `uniq_priceobservations_source` (`import_id`,`source_key`),
  CONSTRAINT `chk_priceobservations_amount` CHECK (`amount` IS NULL OR `amount` >= 0),
  CONSTRAINT `fk_priceobservations_import` FOREIGN KEY (`import_id`) REFERENCES `ValuationImports` (`import_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `BookAssessmentSources` (
  `assessment_id` bigint unsigned NOT NULL,
  `observation_id` bigint unsigned NOT NULL,
  `source_role` varchar(16) NOT NULL DEFAULT 'reference',
  PRIMARY KEY (`assessment_id`,`observation_id`,`source_role`),
  KEY `idx_bookassessmentsources_observation` (`observation_id`),
  CONSTRAINT `chk_bookassessmentsources_role` CHECK (`source_role` IN ('reference','used','replacement')),
  CONSTRAINT `fk_bookassessmentsources_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `BookAssessments` (`assessment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bookassessmentsources_observation` FOREIGN KEY (`observation_id`) REFERENCES `PriceObservations` (`observation_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `SystemInfo` (`key_name`,`value`) VALUES ('schema_version','3.5.0')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

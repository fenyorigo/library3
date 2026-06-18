ALTER TABLE `Books`
    ADD COLUMN `record_status` enum('active','deleted') NOT NULL DEFAULT 'active' AFTER `series`,
    ADD COLUMN `language` varchar(10) NOT NULL DEFAULT 'unknown' AFTER `series`;

ALTER TABLE `UserPreferences`
    ADD COLUMN `show_format` tinyint(1) NOT NULL DEFAULT 0 AFTER `show_language`;

CREATE TABLE IF NOT EXISTS `BookCopies` (
    `copy_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `book_id` int(10) unsigned NOT NULL,
    `format` varchar(20) NOT NULL,
    `quantity` int(11) NOT NULL DEFAULT 1,
    `physical_location` varchar(255) DEFAULT NULL,
    `file_path` varchar(1024) DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`copy_id`),
    KEY `idx_bookcopies_book` (`book_id`),
    KEY `idx_bookcopies_format` (`format`),
    CONSTRAINT `fk_bookcopies_book` FOREIGN KEY (`book_id`) REFERENCES `Books` (`book_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `BookCopies` (`book_id`, `format`, `quantity`, `physical_location`, `file_path`, `notes`, `created_at`, `updated_at`)
SELECT
    b.`book_id`,
    'print',
    CASE
        WHEN b.`copy_count` IS NULL OR b.`copy_count` < 1 THEN 1
        ELSE b.`copy_count`
    END AS `quantity`,
    CASE
        WHEN pl.`bookcase_no` IS NOT NULL AND pl.`shelf_no` IS NOT NULL
            THEN CONCAT('#', pl.`bookcase_no`, '/', pl.`shelf_no`)
        ELSE NULL
    END AS `physical_location`,
    NULL AS `file_path`,
    NULL AS `notes`,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `Books` b
LEFT JOIN `Placement` pl ON pl.`placement_id` = b.`placement_id`
LEFT JOIN `BookCopies` bc ON bc.`book_id` = b.`book_id`
WHERE bc.`book_id` IS NULL;

INSERT INTO `SystemInfo` (`key_name`, `value`)
VALUES ('schema_version', '3.0.0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

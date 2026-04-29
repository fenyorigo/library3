/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/* BookCatalog schema baseline*/;
/*  DB: books */;
/*  version: 3.0.0 */;
/*  generated: 2026-04-28 */;
DROP TABLE IF EXISTS `AuthEvents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `AuthEvents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `username_snapshot` varchar(190) NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_authevents_user` (`user_id`),
  KEY `idx_authevents_type` (`event_type`),
  KEY `idx_authevents_created` (`created_at`),
  CONSTRAINT `fk_authevents_user` FOREIGN KEY (`user_id`) REFERENCES `Users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Authors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Authors` (
  `author_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `sort_name` varchar(255) DEFAULT NULL,
  `is_hungarian` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`author_id`),
  KEY `idx_authors_sort_name` (`sort_name`),
  KEY `idx_authors_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Books`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Books` (
  `book_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(512) NOT NULL,
  `subtitle` varchar(512) DEFAULT NULL,
  `series` varchar(255) DEFAULT NULL,
  `record_status` enum('active','deleted') NOT NULL DEFAULT 'active',
  `language` varchar(10) NOT NULL DEFAULT 'unknown',
  /* deprecated in v3; canonical quantity lives in BookCopies.quantity */
  `copy_count` int(11) NOT NULL DEFAULT 1,
  `publisher_id` int(10) unsigned DEFAULT NULL,
  `year_published` int(11) DEFAULT NULL,
  `isbn` varchar(64) DEFAULT NULL,
  `lccn` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `cover_thumb` varchar(255) DEFAULT NULL,
  `placement_id` int(10) unsigned DEFAULT NULL,
  `loaned_to` varchar(255) DEFAULT NULL,
  `loaned_date` date DEFAULT NULL,
  PRIMARY KEY (`book_id`),
  KEY `idx_books_publisher` (`publisher_id`),
  KEY `idx_books_placement` (`placement_id`),
  KEY `idx_books_year` (`year_published`),
  CONSTRAINT `fk_books_placement` FOREIGN KEY (`placement_id`) REFERENCES `Placement` (`placement_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_books_publisher` FOREIGN KEY (`publisher_id`) REFERENCES `Publishers` (`publisher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `BookCopies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `BookCopies` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Books_Authors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Books_Authors` (
  `book_id` int(10) unsigned NOT NULL,
  `author_id` int(10) unsigned NOT NULL,
  `author_ord` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`book_id`,`author_id`),
  KEY `idx_books_authors_author` (`author_id`),
  CONSTRAINT `fk_books_authors_author` FOREIGN KEY (`author_id`) REFERENCES `Authors` (`author_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_books_authors_book` FOREIGN KEY (`book_id`) REFERENCES `Books` (`book_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Books_Subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Books_Subjects` (
  `book_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`book_id`,`subject_id`),
  KEY `idx_books_subjects_subject` (`subject_id`),
  CONSTRAINT `fk_books_subjects_book` FOREIGN KEY (`book_id`) REFERENCES `Books` (`book_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_books_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `Subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Placement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Placement` (
  `placement_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bookcase_no` int(11) NOT NULL,
  `shelf_no` int(11) NOT NULL,
  PRIMARY KEY (`placement_id`),
  UNIQUE KEY `uniq_placement` (`bookcase_no`,`shelf_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Publishers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Publishers` (
  `publisher_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`publisher_id`),
  UNIQUE KEY `uniq_publishers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Subjects` (
  `subject_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`subject_id`),
  UNIQUE KEY `uniq_subjects_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `SystemInfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `SystemInfo` (
  `key_name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `UserPreferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `UserPreferences` (
  `user_id` int(10) unsigned NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `bg_color` char(7) DEFAULT NULL,
  `fg_color` char(7) DEFAULT NULL,
  `text_size` varchar(16) NOT NULL DEFAULT 'medium',
  `per_page` int(11) NOT NULL DEFAULT 25,
  `show_cover` tinyint(1) NOT NULL DEFAULT 1,
  `show_subtitle` tinyint(1) NOT NULL DEFAULT 1,
  `show_series` tinyint(1) NOT NULL DEFAULT 1,
  `show_is_hungarian` tinyint(1) NOT NULL DEFAULT 1,
  `show_publisher` tinyint(1) NOT NULL DEFAULT 1,
  `show_language` tinyint(1) NOT NULL DEFAULT 0,
  `show_format` tinyint(1) NOT NULL DEFAULT 0,
  `show_year` tinyint(1) NOT NULL DEFAULT 1,
  `show_copy_count` tinyint(1) NOT NULL DEFAULT 0,
  `show_status` tinyint(1) NOT NULL DEFAULT 1,
  `show_placement` tinyint(1) NOT NULL DEFAULT 1,
  `show_isbn` tinyint(1) NOT NULL DEFAULT 0,
  `show_loaned_to` tinyint(1) NOT NULL DEFAULT 0,
  `show_loaned_date` tinyint(1) NOT NULL DEFAULT 0,
  `show_subjects` tinyint(1) NOT NULL DEFAULT 0,
  `show_notes` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_userprefs_user` FOREIGN KEY (`user_id`) REFERENCES `Users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `Users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','reader') NOT NULL DEFAULT 'reader',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uniq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `duplicate_review`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `duplicate_review` (
  `dup_key` varchar(768) NOT NULL,
  `status` enum('NEW','IGNORE','CONFIRMED','MERGED') NOT NULL DEFAULT 'NEW',
  `note` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dup_key`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

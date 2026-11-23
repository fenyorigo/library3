-- Schema 3.6.0: recoverable retirement of copies and their evidence.
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookCopies' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `BookCopies` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookCopyPhotos' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `BookCopyPhotos` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookCopyAcquisitionDocuments' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `BookCopyAcquisitionDocuments` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AcquisitionDocuments' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `AcquisitionDocuments` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookAssessments' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `BookAssessments` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookAssessmentSources' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `BookAssessmentSources` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='PriceObservations' AND COLUMN_NAME='record_status'), 'DO 0', 'ALTER TABLE `PriceObservations` ADD COLUMN `record_status` enum(''active'',''deleted'') NOT NULL DEFAULT ''active''');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
SET @lifecycle_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='BookCopies' AND COLUMN_NAME='deleted_with_book'), 'DO 0', 'ALTER TABLE `BookCopies` ADD COLUMN `deleted_with_book` tinyint(1) NOT NULL DEFAULT 0');
PREPARE lifecycle_stmt FROM @lifecycle_sql;
EXECUTE lifecycle_stmt;
DEALLOCATE PREPARE lifecycle_stmt;
CREATE TABLE IF NOT EXISTS `PortablePackageImports` (
  `package_sha256` char(64) NOT NULL,
  `source_created_at` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`package_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE BookCopies c JOIN Books b ON b.book_id=c.book_id SET c.record_status='deleted',c.deleted_with_book=1 WHERE b.record_status='deleted' AND c.record_status='active';
UPDATE BookCopyPhotos p JOIN BookCopies c ON c.copy_id=p.copy_id JOIN Books b ON b.book_id=c.book_id SET p.record_status=IF(c.record_status='active' AND b.record_status='active','active','deleted');
UPDATE BookCopyAcquisitionDocuments l JOIN BookCopies c ON c.copy_id=l.copy_id JOIN Books b ON b.book_id=c.book_id SET l.record_status=IF(c.record_status='active' AND b.record_status='active','active','deleted');
UPDATE AcquisitionDocuments d SET d.record_status=IF(EXISTS(SELECT 1 FROM BookCopyAcquisitionDocuments l WHERE l.document_id=d.document_id AND l.record_status='active'),'active','deleted');
UPDATE BookAssessments a JOIN Books b ON b.book_id=a.book_id SET a.record_status=IF(b.record_status='active' AND EXISTS(SELECT 1 FROM BookCopies c WHERE c.book_id=b.book_id AND c.format='print' AND c.record_status='active'),'active','deleted');
UPDATE BookAssessmentSources l JOIN BookAssessments a ON a.assessment_id=l.assessment_id SET l.record_status=a.record_status;
UPDATE PriceObservations p SET p.record_status=IF(EXISTS(SELECT 1 FROM BookAssessmentSources l WHERE l.observation_id=p.observation_id AND l.record_status='active'),'active','deleted');
INSERT INTO SystemInfo (key_name,value) VALUES ('schema_version','3.6.0') ON DUPLICATE KEY UPDATE value=VALUES(value);

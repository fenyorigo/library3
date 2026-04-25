-- BookCatalog schema migration to 2.3.5
-- Adds copy_count support and duplicate merge workflow status.

ALTER TABLE Books
  ADD COLUMN IF NOT EXISTS copy_count INT NOT NULL DEFAULT 1 AFTER series;

ALTER TABLE duplicate_review
  MODIFY COLUMN status ENUM('NEW','IGNORE','CONFIRMED','MERGED') NOT NULL DEFAULT 'NEW';

ALTER TABLE UserPreferences
  ADD COLUMN IF NOT EXISTS show_copy_count TINYINT(1) NOT NULL DEFAULT '0' AFTER show_year;

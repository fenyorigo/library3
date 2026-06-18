-- Migration: add file_size to BookCopies
-- Apply manually: mysql -u<user> -p <db> < v3_add_file_size_to_bookcopies.sql

ALTER TABLE BookCopies
  ADD COLUMN file_size BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER file_path;

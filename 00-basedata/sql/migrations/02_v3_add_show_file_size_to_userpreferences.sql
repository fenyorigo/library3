-- Migration: add show_file_size to UserPreferences
-- Apply manually: mysql -u<user> -p <db> < v3_add_show_file_size_to_userpreferences.sql

ALTER TABLE UserPreferences
  ADD COLUMN show_file_size TINYINT(1) NOT NULL DEFAULT 0 AFTER show_copy_count;

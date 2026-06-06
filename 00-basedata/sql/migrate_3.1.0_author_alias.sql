-- BookCatalog schema 3.1.0
-- Adds relation-level author alias / pseudonym metadata for ebook imports.

ALTER TABLE Books_Authors
  ADD COLUMN author_alias varchar(255) DEFAULT NULL AFTER author_ord;

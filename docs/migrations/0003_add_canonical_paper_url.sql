-- Migration 0003: add canonical_paper_url dedup field to headlines (SIG-177)
--
-- Adds a nullable canonical paper / preprint URL so the SSCI Digest Editor
-- can count cluster source diversity by collapsing all articles that
-- syndicate the same underlying preprint or institutional release.
--
-- No backfill: older rows stay NULL and consumers treat NULL as "unknown".
-- Idempotent: safe to re-run; uses INFORMATION_SCHEMA checks so a partial
-- prior run does not break a second apply.
--
--   mysql -u oddimfjz_agent -p oddimfjz_headlinestore < docs/migrations/0003_add_canonical_paper_url.sql

USE oddimfjz_headlinestore;

-- Add the column if it does not already exist.
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND COLUMN_NAME = 'canonical_paper_url'
);

SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE headlines ADD COLUMN canonical_paper_url VARCHAR(512) NULL AFTER published_at',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the index if it does not already exist.
SET @idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND INDEX_NAME = 'idx_canonical_paper_url'
);

SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE headlines ADD INDEX idx_canonical_paper_url (canonical_paper_url)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

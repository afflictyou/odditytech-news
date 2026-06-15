-- Migration 0005: two-date model — add source_published_at, source_published_at_known,
-- oddity_surfaced_at to headlines (SIG-397 / parent SIG-396).
--
-- Why two fields plus a "_known" flag, with NULL backfill for source_published_at:
--   SIG-391 surfaced articles months older than their displayed dates because the
--   pipeline conflates "when the source published it" with "when we surfaced it."
--   The existing `published_at` column is not a real source date — publish.py
--   stamps it with NOW() at assembly time (publish.py:161), and the same value is
--   written to every card in a batch. So no existing row carries an authoritative
--   source date we can recover.
--
--   The parent plan (SIG-396) is explicit: never guess; rows without a real source
--   date stay NULL with known=0. Therefore this migration:
--     * Adds source_published_at NULL — no backfill from the (untrustworthy)
--       existing published_at column.
--     * Defaults source_published_at_known = 0 for all existing rows.
--     * Backfills oddity_surfaced_at from the existing ingested_at column —
--       which IS authoritative (NOT NULL DEFAULT NOW(), set at insert time).
--
--   Phase 2 (pipeline rewrite, separate ticket) starts populating source_published_at
--   and flipping known=1 on new ingests where the source metadata provides a real
--   date. Old rows remain known=0 forever, which is the honest state.
--
-- Idempotent: safe to re-run. Every ALTER and UPDATE is guarded against re-apply
-- via INFORMATION_SCHEMA checks or NULL filters, matching the 0003 pattern so the
-- script runs cleanly in phpMyAdmin / mysql client (no CLI / SSH / CREATE ROUTINE
-- needed on Namecheap Stellar shared hosting).
--
--   mysql -u oddimfjz_agent -p oddimfjz_headlinestore < docs/migrations/0005_two_date_model.sql

USE oddimfjz_headlinestore;

-- ---------------------------------------------------------------------------
-- 1. ADD COLUMN source_published_at DATETIME NULL
--    Real publish date from the source. NULL means we don't know.
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND COLUMN_NAME = 'source_published_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE headlines ADD COLUMN source_published_at DATETIME NULL AFTER published_at',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. ADD COLUMN source_published_at_known TINYINT(1) NOT NULL DEFAULT 0
--    Explicit flag: 1 = the date in source_published_at came from real source
--    metadata; 0 = unknown (don't trust source_published_at — should be NULL).
--    Defaulting to 0 makes existing rows honestly "unknown" without any UPDATE.
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND COLUMN_NAME = 'source_published_at_known'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE headlines ADD COLUMN source_published_at_known TINYINT(1) NOT NULL DEFAULT 0 AFTER source_published_at',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. ADD COLUMN oddity_surfaced_at DATETIME NULL
--    When our pipeline surfaced the card. Backfilled from ingested_at, which
--    is authoritative (the existing NOT NULL DEFAULT NOW() column).
--    Added as NULL first so the ALTER doesn't fail on existing rows; backfilled
--    in step 4; can be tightened to NOT NULL in a follow-up once phase 2 ships
--    and the pipeline writes both columns on every insert.
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND COLUMN_NAME = 'oddity_surfaced_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE headlines ADD COLUMN oddity_surfaced_at DATETIME NULL AFTER source_published_at_known',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Backfill oddity_surfaced_at from ingested_at.
--    NULL-guarded so re-runs after a partial apply are no-ops on rows already
--    backfilled, and so a future row inserted by phase-2 pipeline code that
--    already sets oddity_surfaced_at directly is not overwritten.
-- ---------------------------------------------------------------------------
UPDATE headlines
   SET oddity_surfaced_at = ingested_at
 WHERE oddity_surfaced_at IS NULL;

-- ---------------------------------------------------------------------------
-- 5. Index on oddity_surfaced_at DESC.
--    Phase-2 query patterns ("this week's surfaced cards") will sort/filter by
--    this column. Adding the index now keeps the existing idx_ingested in place
--    for backward-compat until phase 2 / phase 4 finish migrating consumers.
-- ---------------------------------------------------------------------------
SET @idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'headlines'
    AND INDEX_NAME = 'idx_oddity_surfaced'
);
SET @ddl := IF(
  @idx_exists = 0,
  'ALTER TABLE headlines ADD INDEX idx_oddity_surfaced (oddity_surfaced_at DESC)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Verification queries (read-only). Run after the apply and confirm the
--    schema and backfill landed as expected. Paste these into phpMyAdmin once
--    the migration finishes; the row counts go into the SIG-397 evidence
--    comment.
-- ---------------------------------------------------------------------------
-- -- Schema check: the three new columns should appear in this list.
-- SHOW COLUMNS FROM headlines;
--
-- -- Backfill check: should be 0 (every row has oddity_surfaced_at populated).
-- SELECT COUNT(*) AS rows_missing_oddity_surfaced_at
--   FROM headlines
--  WHERE oddity_surfaced_at IS NULL;
--
-- -- Total rows + known-vs-unknown breakdown.
-- SELECT COUNT(*) AS total_rows,
--        SUM(source_published_at_known = 1) AS known_source_dates,
--        SUM(source_published_at_known = 0) AS unknown_source_dates,
--        SUM(source_published_at IS NULL)   AS source_pub_null,
--        SUM(oddity_surfaced_at IS NOT NULL) AS oddity_surfaced_populated
--   FROM headlines;
--
-- -- Sample 5 rows to eyeball the backfill.
-- SELECT id, published_at, source_published_at, source_published_at_known,
--        ingested_at, oddity_surfaced_at
--   FROM headlines
--  ORDER BY id DESC
--  LIMIT 5;

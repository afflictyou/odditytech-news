-- Migration 0002: create `digests` table in oddimfjz_headlinestore
-- Adds the storage backing for the `/digest` publication + REST API (SIG-176).
-- Idempotent: safe to re-run; no destructive operations.
--
-- Run once against the target database before the digests API can serve traffic.
--
--   mysql -u oddimfjz_agent -p oddimfjz_headlinestore < docs/migrations/0002_digests.sql
--
-- Collation: pinned to utf8mb4_unicode_ci so the table aligns with the
-- production `headlines` table (verified via information_schema 2026-06-05).
-- The retro-fix ALTER at the bottom is a no-op on fresh installs and corrects
-- earlier prod environments where the table was created under the server
-- default (utf8mb4_general_ci) and then required explicit COLLATE clauses on
-- every cross-table join. See SIG-181 thread for the diagnosis.

USE oddimfjz_headlinestore;

CREATE TABLE IF NOT EXISTS digests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(255)  NOT NULL,
  title           VARCHAR(500)  NOT NULL,
  summary         TEXT          NULL,
  body_markdown   MEDIUMTEXT    NOT NULL,
  lead_cluster    VARCHAR(255)  NULL,
  published_at    DATETIME      NULL,
  status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_slug (slug),
  KEY idx_status_published_at (status, published_at DESC),
  KEY idx_published_at (published_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retro-fix the collation when an earlier apply picked up the server default
-- (utf8mb4_general_ci). No-op when already aligned.
ALTER TABLE digests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

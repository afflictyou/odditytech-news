-- Migration 0002: create `digests` table in oddimfjz_headlinestore
-- Adds the storage backing for the `/digest` publication + REST API (SIG-176).
-- Idempotent: safe to re-run; no destructive operations.
--
-- Run once against the target database before the digests API can serve traffic.
--
--   mysql -u oddimfjz_agent -p oddimfjz_headlinestore < docs/migrations/0002_digests.sql

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
);

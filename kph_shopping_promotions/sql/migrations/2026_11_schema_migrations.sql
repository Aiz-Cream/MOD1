-- ============================================================
-- KameraPH | Ecommerce migrations | Schema tracking
-- Records which migrations have been applied, with a checksum so
-- an edited, already-applied migration is detected and never silently
-- re-executed. A version is recorded only after its full manifest
-- (tables, columns, indexes, foreign keys, constraints, seed rows)
-- has been verified by run_migrations.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `Version`   VARCHAR(64) NOT NULL,
  `Checksum`  CHAR(64)    NOT NULL,
  `AppliedAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
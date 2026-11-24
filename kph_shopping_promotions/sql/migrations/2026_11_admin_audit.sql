-- ============================================================
-- KameraPH | Ecommerce migrations | Admin audit log
-- Durable record of every admin action. AdminID is a plain id (Module 10
-- convention) with no foreign key, so audit rows survive account changes.
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `AuditID`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `AdminID`    INT UNSIGNED NULL,
  `ActionType` VARCHAR(50)  NOT NULL,
  `EntityType` VARCHAR(30)  NOT NULL,
  `EntityID`   INT UNSIGNED NULL,
  `Details`    VARCHAR(500) NULL,
  `IPAddress`  VARCHAR(45)  NULL,
  `CreatedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`AuditID`),
  KEY `idx_admin_audit_Entity` (`EntityType`, `EntityID`),
  KEY `idx_admin_audit_CreatedAt` (`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
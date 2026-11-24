-- ============================================================
-- KameraPH Module 1 — login history table
-- Run once. Safe to re-run (IF NOT EXISTS).
-- BACK UP the database before applying.
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_history` (
  LoginID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  SessionID INT UNSIGNED NULL,
  LoginAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UserAgent VARCHAR(500) NULL,
  Device VARCHAR(100) NULL,
  Status VARCHAR(30) NOT NULL DEFAULT 'Success',
  PRIMARY KEY (LoginID),
  KEY idx_login_history_User (UserID, LoginAt),
  CONSTRAINT fk_login_history_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ============================================================
-- KameraPH | Module 1 | Ecommerce migration | User activity
-- Server-side behaviour event store. Supersedes the non-existent
-- listing_view / search_history tables that api/activity.php used to
-- query. Run via kph_shopping_promotions/dev/scripts/run_migrations.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_activity` (
  `ActivityID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `UserID`     INT UNSIGNED NOT NULL,
  `EventType`  VARCHAR(40)  NOT NULL,
  `EntityType` VARCHAR(30)  NULL,
  `EntityID`   INT UNSIGNED NULL,
  `Description` VARCHAR(255) NULL,
  `Metadata`   JSON         NULL,
  `CreatedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ActivityID`),
  KEY `idx_user_activity_User_Date` (`UserID`, `CreatedAt`),
  KEY `idx_user_activity_Type` (`EventType`),
  KEY `idx_user_activity_CreatedAt` (`CreatedAt`),
  CONSTRAINT `fk_user_activity_User` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
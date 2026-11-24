-- ============================================================
-- KameraPH | Ecommerce migrations | Sellers
-- seller_application + seller_application_history.
--
-- ActiveFlag is a stored generated column: 1 while the application is
-- Pending or Approved, NULL otherwise. UNIQUE(UserID, ActiveFlag)
-- therefore allows many closed (Rejected/Suspended) applications per
-- user but at most one active application. Because NULLs are allowed,
-- multiple NULL rows do not collide.
-- ============================================================

CREATE TABLE IF NOT EXISTS `seller_application` (
  `ApplicationID`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `UserID`             INT UNSIGNED NOT NULL,
  `StoreName`          VARCHAR(150) NOT NULL,
  `StoreDescription`   TEXT         NULL,
  `BusinessAddress`    VARCHAR(500) NULL,
  `ProductCategory`    VARCHAR(100) NULL,
  `SellerType`         VARCHAR(50)  NULL,
  `ContactNumber`      VARCHAR(20)  NOT NULL,
  `Status`             VARCHAR(20)  NOT NULL DEFAULT 'Pending',
  `RejectionReason`    TEXT         NULL,
  `ReviewedByAdminID`  INT UNSIGNED NULL,
  `SubmittedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ReviewedAt`         DATETIME     NULL,
  `ActiveFlag`         TINYINT      GENERATED ALWAYS AS (CASE WHEN `Status` IN ('Pending','Approved') THEN 1 ELSE NULL END) STORED,
  `CanReapply`         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`ApplicationID`),
  UNIQUE KEY `uq_seller_application_User_Active` (`UserID`, `ActiveFlag`),
  KEY `idx_seller_application_Status` (`Status`),
  CONSTRAINT `fk_seller_application_User` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_application_history` (
  `HistoryID`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ApplicationID`    INT UNSIGNED NOT NULL,
  `FromStatus`       VARCHAR(20)  NULL,
  `ToStatus`         VARCHAR(20)  NOT NULL,
  `ChangedByUserID`  INT UNSIGNED NULL,
  `Note`             VARCHAR(255) NULL,
  `CreatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`HistoryID`),
  KEY `idx_seller_app_history_Application` (`ApplicationID`, `CreatedAt`),
  CONSTRAINT `fk_seller_app_history_Application` FOREIGN KEY (`ApplicationID`) REFERENCES `seller_application` (`ApplicationID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
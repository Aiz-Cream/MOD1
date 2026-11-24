-- ============================================================
-- KameraPH | Ecommerce migrations | Support tickets
-- support_ticket. OrderID is an optional reference: if the order is
-- removed the ticket keeps its history and OrderID becomes NULL.
-- ============================================================

CREATE TABLE IF NOT EXISTS `support_ticket` (
  `TicketID`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `UserID`       INT UNSIGNED NOT NULL,
  `Subject`      VARCHAR(200) NOT NULL,
  `Category`     VARCHAR(50)  NOT NULL,
  `Message`      TEXT         NOT NULL,
  `OrderID`      INT UNSIGNED NULL,
  `ContactEmail` VARCHAR(191) NOT NULL,
  `Status`       VARCHAR(20)  NOT NULL DEFAULT 'Open',
  `CreatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`TicketID`),
  KEY `idx_support_ticket_User` (`UserID`),
  KEY `idx_support_ticket_Status` (`Status`),
  CONSTRAINT `fk_support_ticket_User`  FOREIGN KEY (`UserID`)  REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_Order` FOREIGN KEY (`OrderID`) REFERENCES `orders` (`OrderID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
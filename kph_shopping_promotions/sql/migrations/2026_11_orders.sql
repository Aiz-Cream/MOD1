-- ============================================================
-- KameraPH | Ecommerce migrations | Orders & checkout
-- checkout_session (checkout-level idempotency) then orders,
-- order_items, order_status_history, order_payment_history.
--
-- SellerKey is a stored generated column: IFNULL(SellerID,0) makes the
-- uniqueness NULL-safe so a platform order (SellerID NULL) is unique per
-- (UserID, CheckoutKey) while real sellers keep their own order.
-- orders.SellerID is ON DELETE RESTRICT so a seller with orders can never
-- be removed (which would flip a real order to the platform sentinel 0).
--
-- Address snapshot columns are NULLable so Pickup orders (no address) can
-- be stored; Standard orders require them (validated in the API).
-- ============================================================

CREATE TABLE IF NOT EXISTS `checkout_session` (
  `SessionID`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `UserID`       INT UNSIGNED NOT NULL,
  `CheckoutKey`  VARCHAR(64)  NOT NULL,
  `RequestHash`  CHAR(64)     NOT NULL,
  `CreatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`SessionID`),
  UNIQUE KEY `uq_checkout_session_User_Key` (`UserID`, `CheckoutKey`),
  CONSTRAINT `fk_checkout_session_User` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `OrderID`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `OrderNumber`     VARCHAR(32)   NOT NULL,
  `CheckoutKey`     VARCHAR(64)   NOT NULL,
  `UserID`          INT UNSIGNED  NOT NULL,
  `SellerID`        INT UNSIGNED  NULL,
  `SellerKey`       INT UNSIGNED  GENERATED ALWAYS AS (IFNULL(`SellerID`, 0)) STORED,
  `AddressID`       INT UNSIGNED  NULL,
  `RecipientName`   VARCHAR(150)  NULL,
  `Phone`           VARCHAR(20)   NULL,
  `HouseUnit`       VARCHAR(100)  NULL,
  `Street`          VARCHAR(255)  NULL,
  `Barangay`        VARCHAR(100)  NULL,
  `City`            VARCHAR(100)  NULL,
  `Province`        VARCHAR(100)  NULL,
  `PostalCode`      VARCHAR(20)   NULL,
  `ShippingMethod`  VARCHAR(50)   NOT NULL DEFAULT 'Standard',
  `ShippingFee`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `DiscountAmount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `Subtotal`        DECIMAL(12,2) NOT NULL,
  `Total`           DECIMAL(12,2) NOT NULL,
  `OrderStatus`     VARCHAR(30)   NOT NULL DEFAULT 'Pending',
  `PaymentMethod`   VARCHAR(30)   NOT NULL DEFAULT 'COD',
  `PaymentStatus`   VARCHAR(20)   NOT NULL DEFAULT 'Unpaid',
  `CancelReason`    VARCHAR(255)  NULL,
  `CancelledAt`     DATETIME      NULL,
  `CreatedAt`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`OrderID`),
  UNIQUE KEY `uq_orders_OrderNumber` (`OrderNumber`),
  UNIQUE KEY `uq_orders_User_Checkout_Seller` (`UserID`, `CheckoutKey`, `SellerKey`),
  KEY `idx_orders_User` (`UserID`),
  KEY `idx_orders_Status` (`OrderStatus`),
  KEY `idx_orders_PaymentStatus` (`PaymentStatus`),
  KEY `idx_orders_Checkout` (`UserID`, `CheckoutKey`),
  CONSTRAINT `fk_orders_User`     FOREIGN KEY (`UserID`)     REFERENCES `user` (`UserID`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_Seller`   FOREIGN KEY (`SellerID`)   REFERENCES `user` (`UserID`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_Address`  FOREIGN KEY (`AddressID`)  REFERENCES `user_address` (`AddressID`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_Checkout` FOREIGN KEY (`UserID`, `CheckoutKey`) REFERENCES `checkout_session` (`UserID`, `CheckoutKey`) ON DELETE RESTRICT,
  CONSTRAINT `chk_orders_Seller`  CHECK (`SellerID` IS NULL OR `SellerID` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `OrderItemID` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `OrderID`     INT UNSIGNED  NOT NULL,
  `ProductID`   INT UNSIGNED  NULL,
  `ProductName` VARCHAR(255)  NOT NULL,
  `UnitPrice`   DECIMAL(12,2) NOT NULL,
  `ImageURL`    VARCHAR(500)  NULL,
  `Quantity`    INT UNSIGNED  NOT NULL,
  `Subtotal`    DECIMAL(12,2) NOT NULL,
  `SellerID`    INT UNSIGNED  NULL,
  PRIMARY KEY (`OrderItemID`),
  KEY `idx_order_items_Order` (`OrderID`),
  KEY `idx_order_items_Seller` (`SellerID`),
  CONSTRAINT `fk_order_items_Order` FOREIGN KEY (`OrderID`) REFERENCES `orders` (`OrderID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_status_history` (
  `HistoryID`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `OrderID`          INT UNSIGNED NOT NULL,
  `FromStatus`       VARCHAR(30)  NULL,
  `ToStatus`         VARCHAR(30)  NOT NULL,
  `Note`             VARCHAR(255) NULL,
  `ChangedByUserID`  INT UNSIGNED NULL,
  `CreatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`HistoryID`),
  KEY `idx_order_status_history_Order` (`OrderID`, `CreatedAt`),
  CONSTRAINT `fk_order_status_history_Order` FOREIGN KEY (`OrderID`) REFERENCES `orders` (`OrderID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_payment_history` (
  `PaymentHistoryID`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `OrderID`           INT UNSIGNED NOT NULL,
  `FromPaymentStatus` VARCHAR(20)  NULL,
  `ToPaymentStatus`   VARCHAR(20)  NOT NULL,
  `ConfirmedByUserID` INT UNSIGNED NULL,
  `CollectionMethod`  VARCHAR(20)  NOT NULL DEFAULT 'COD',
  `Reference`         VARCHAR(255) NULL,
  `Note`              VARCHAR(255) NULL,
  `CreatedAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PaymentHistoryID`),
  KEY `idx_payment_history_Order` (`OrderID`, `CreatedAt`),
  CONSTRAINT `fk_payment_history_Order` FOREIGN KEY (`OrderID`) REFERENCES `orders` (`OrderID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
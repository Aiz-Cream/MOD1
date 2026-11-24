-- ============================================================
-- KameraPH | Shopping Cart Module | Database Schema
-- Dependencies: Module 1 (User Account & Identity) user table
-- ============================================================

CREATE TABLE IF NOT EXISTS `carts` (
  CartID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (CartID),
  UNIQUE KEY uq_carts_UserID (UserID),
  CONSTRAINT fk_carts_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart_items` (
  CartItemID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  CartID INT UNSIGNED NOT NULL,
  ProductID INT UNSIGNED NOT NULL,
  ProductName VARCHAR(255) NOT NULL,
  UnitPrice DECIMAL(12,2) NOT NULL,
  ImageURL VARCHAR(500) NULL,
  Quantity INT UNSIGNED NOT NULL DEFAULT 1,
  AddedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (CartItemID),
  UNIQUE KEY uq_cart_items_Cart_Product (CartID, ProductID),
  CONSTRAINT fk_cart_items_CartID FOREIGN KEY (CartID) REFERENCES `carts` (CartID),
  CONSTRAINT chk_cart_items_Quantity CHECK (Quantity >= 1 AND Quantity <= 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
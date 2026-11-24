-- ============================================================
-- KameraPH | ONE-FILE LOCAL SETUP FOR XAMPP
-- phpMyAdmin -> Import tab -> choose this file -> Go.
-- Creates the database and every table (accounts, cart, catalog,
-- sellers, orders, support). Safe to import again (IF NOT EXISTS).
-- ============================================================
CREATE DATABASE IF NOT EXISTS `kph_user_account_identity` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `kph_user_account_identity`;
SET FOREIGN_KEY_CHECKS = 0;

-- >>>>>>>> module_01_user_account_and_identity.sql
-- ============================================================
-- KameraPH (KPH)  |  Module 1: User Account and Identity
-- Source: KameraPH_ERD_v5  |  MySQL / MariaDB (InnoDB, utf8mb4)
--
-- HOW TO USE: create an empty database for this module on your host,
-- open it in phpMyAdmin, go to the SQL tab, paste this whole file, click Go.
--
-- Tables in this module (8): user, role, user_role, user_profile, user_address, auth_identity, verification_token, kyc_verification
--
-- Columns owned by OTHER modules are plain ID columns (no FOREIGN KEY),
-- because each module has its own database. Get those records from the
-- other module's API instead of joining tables.
--
-- Outside IDs stored in this module:
--   kyc_verification.ReviewedByAdminID  ->  Module 10 (admin_user.AdminID)
-- ============================================================

-- USER
CREATE TABLE IF NOT EXISTS `user` (
  UserID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  FirstName VARCHAR(100) NOT NULL,
  MiddleName VARCHAR(100) NULL,
  LastName VARCHAR(100) NOT NULL,
  Email VARCHAR(191) NOT NULL,
  PhoneNumber VARCHAR(20) NOT NULL,
  PasswordHash VARCHAR(255) NULL,
  EmailVerifiedAt DATETIME NULL,
  PhoneVerifiedAt DATETIME NULL,
  DateJoined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  LastLoginAt DATETIME NULL,
  Status VARCHAR(30) NOT NULL,
  SuspensionReason TEXT NULL,
  PRIMARY KEY (UserID),
  UNIQUE KEY uq_user_Email (Email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLE
CREATE TABLE IF NOT EXISTS `role` (
  RoleID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  RoleName VARCHAR(100) NOT NULL,
  Description TEXT NULL,
  PRIMARY KEY (RoleID),
  UNIQUE KEY uq_role_RoleName (RoleName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER ROLE
CREATE TABLE IF NOT EXISTS `user_role` (
  UserRoleID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  RoleID INT UNSIGNED NOT NULL,
  AssignedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (UserRoleID),
  UNIQUE KEY uq_user_role_UserID_RoleID (UserID, RoleID),
  CONSTRAINT fk_user_role_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID),
  CONSTRAINT fk_user_role_RoleID FOREIGN KEY (RoleID) REFERENCES `role` (RoleID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER PROFILE
CREATE TABLE IF NOT EXISTS `user_profile` (
  ProfileID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Username VARCHAR(100) NOT NULL,
  Bio TEXT NULL,
  ProfilePictureURL VARCHAR(500) NULL,
  DateOfBirth DATE NULL,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ProfileID),
  UNIQUE KEY uq_user_profile_UserID (UserID),
  CONSTRAINT fk_user_profile_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER ADDRESS
CREATE TABLE IF NOT EXISTS `user_address` (
  AddressID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  RecipientFirstName VARCHAR(100) NOT NULL,
  RecipientMiddleName VARCHAR(100) NULL,
  RecipientLastName VARCHAR(100) NOT NULL,
  PhoneNumber VARCHAR(20) NOT NULL,
  HouseUnitNumber VARCHAR(100) NOT NULL,
  Street VARCHAR(255) NOT NULL,
  Barangay VARCHAR(100) NOT NULL,
  City VARCHAR(100) NOT NULL,
  Province VARCHAR(100) NOT NULL,
  PostalCode VARCHAR(10) NOT NULL,
  AddressType VARCHAR(30) NOT NULL,
  IsDefault TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (AddressID),
  CONSTRAINT fk_user_address_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTH IDENTITY
CREATE TABLE IF NOT EXISTS `auth_identity` (
  AuthIdentityID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Provider VARCHAR(100) NOT NULL,
  ProviderUserID VARCHAR(255) NOT NULL,
  LinkedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (AuthIdentityID),
  CONSTRAINT fk_auth_identity_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VERIFICATION TOKEN
CREATE TABLE IF NOT EXISTS `verification_token` (
  TokenID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Purpose VARCHAR(100) NOT NULL,
  TokenHash VARCHAR(255) NOT NULL,
  ExpiresAt DATETIME NOT NULL,
  UsedAt DATETIME NULL,
  PRIMARY KEY (TokenID),
  CONSTRAINT fk_verification_token_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KYC VERIFICATION
CREATE TABLE IF NOT EXISTS `kyc_verification` (
  KYCID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  IDType VARCHAR(100) NOT NULL,
  IDNumber VARCHAR(255) NOT NULL COMMENT 'stored encrypted',
  IDImageURL VARCHAR(500) NOT NULL,
  SelfieImageURL VARCHAR(500) NOT NULL,
  VerificationStatus VARCHAR(30) NOT NULL,
  SubmittedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  VerifiedAt DATETIME NULL,
  ReviewedByAdminID INT UNSIGNED NULL COMMENT 'Module 10: admin_user.AdminID (plain ID, no FK)',
  RejectionReason TEXT NULL,
  PRIMARY KEY (KYCID),
  KEY idx_kyc_verification_ReviewedByAdminID (ReviewedByAdminID),
  CONSTRAINT fk_kyc_verification_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- >>>>>>>> seed_roles.sql
-- ============================================================
-- KameraPH | Module 1 | Starting data (run AFTER module_01_user_account_and_identity.sql)
-- Safe to run twice: it only adds roles that are missing.
-- The PHP code also adds these three roles by itself, so this file is optional.
-- ============================================================

INSERT INTO `role` (RoleName, Description)
SELECT 'buyer', 'Can browse, buy, and review items.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'buyer');

INSERT INTO `role` (RoleName, Description)
SELECT 'seller', 'Can list items for sale after passing the seller check.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'seller');

INSERT INTO `role` (RoleName, Description)
SELECT 'admin', 'KPH staff who manage users, listings, disputes and reports.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'admin');

-- ------------------------------------------------------------
-- MAKE YOUR FIRST ADMIN (do this once, by hand).
-- Register a user first (POST api/users.php), then replace 1 with that UserID.
-- The API cannot create the very first admin, on purpose.
-- ------------------------------------------------------------
-- INSERT INTO `user_role` (UserID, RoleID)
-- SELECT 1, RoleID FROM `role` WHERE RoleName = 'admin';


-- >>>>>>>> 2026_11_login_history.sql
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

-- >>>>>>>> 2026_11_user_activity.sql
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

-- >>>>>>>> cart.sql
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

-- >>>>>>>> 2026_11_schema_migrations.sql
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

-- >>>>>>>> 2026_11_catalog.sql
-- ============================================================
-- KameraPH | Ecommerce migrations | Catalog
-- category + product tables and the 4 categories / 8 sample
-- products that match web/js/catalog.js and the existing cart
-- references. ProductIDs are explicit (101-104, 201-204).
--
-- Stock: 25 per product = approved demo placeholder (products are
-- purchasable so the checkout flow can be exercised). This is demo
-- data, not real inventory.
--
-- Approval model: product.Status is availability/archival only
-- (Active / Inactive / OutOfStock / Archived). ApprovalStatus is a
-- separate workflow (Pending / Approved / Rejected). Seed products
-- are Approved / IsApproved=1. A CHECK keeps IsApproved in sync with
-- ApprovalStatus.
-- ============================================================

CREATE TABLE IF NOT EXISTS `category` (
  `CategoryID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `Name`       VARCHAR(100) NOT NULL,
  `Slug`       VARCHAR(120) NOT NULL,
  `Note`       VARCHAR(255) NULL,
  `SortOrder`  INT          NOT NULL DEFAULT 0,
  `IsActive`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`CategoryID`),
  UNIQUE KEY `uq_category_Name` (`Name`),
  UNIQUE KEY `uq_category_Slug` (`Slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product` (
  `ProductID`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `CategoryID`        INT UNSIGNED  NULL,
  `SellerID`          INT UNSIGNED  NULL,
  `ProductName`       VARCHAR(255)  NOT NULL,
  `Description`       TEXT          NULL,
  `BasePrice`         DECIMAL(12,2) NOT NULL,
  `CompareAtPrice`    DECIMAL(12,2) NULL,
  `ImageURL`          VARCHAR(500)  NULL,
  `Stock`             INT UNSIGNED  NOT NULL DEFAULT 0,
  `Status`            VARCHAR(20)   NOT NULL DEFAULT 'OutOfStock',
  `IsApproved`        TINYINT(1)    NOT NULL DEFAULT 0,
  `ApprovalStatus`    VARCHAR(20)   NOT NULL DEFAULT 'Pending',
  `ReviewedByAdminID` INT UNSIGNED  NULL,
  `ReviewedAt`        DATETIME      NULL,
  `RejectionReason`   VARCHAR(500)  NULL,
  `Rating`            DECIMAL(2,1)  NULL,
  `ReviewCount`       INT UNSIGNED  NULL,
  `CreatedAt`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ProductID`),
  KEY `idx_product_Category` (`CategoryID`),
  KEY `idx_product_Seller` (`SellerID`),
  KEY `idx_product_Status` (`Status`),
  KEY `idx_product_Approval` (`ApprovalStatus`),
  CONSTRAINT `fk_product_Category` FOREIGN KEY (`CategoryID`) REFERENCES `category` (`CategoryID`) ON DELETE SET NULL,
  CONSTRAINT `fk_product_Seller`   FOREIGN KEY (`SellerID`)   REFERENCES `user` (`UserID`) ON DELETE RESTRICT,
  CONSTRAINT `chk_product_Status`   CHECK (`Status` IN ('Active','Inactive','OutOfStock','Archived')),
  CONSTRAINT `chk_product_Approval` CHECK (`IsApproved` = (`ApprovalStatus` = 'Approved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Seed categories (explicit IDs so storefront links are stable) ----
INSERT IGNORE INTO `category` (`CategoryID`, `Name`, `Slug`, `Note`, `SortOrder`, `IsActive`) VALUES
  (1, 'Cameras',     'cameras',     'Mirrorless and DSLR',   1, 1),
  (2, 'Lenses',      'lenses',      'Prime and zoom',        2, 1),
  (3, 'Drones',      'drones',      'Aerial and action',     3, 1),
  (4, 'Accessories', 'accessories', 'Bags, tripods and more',4, 1);

-- ---- Seed products (explicit IDs 101-104, 201-204; Approved / IsApproved=1) ----
INSERT IGNORE INTO `product`
  (`ProductID`, `CategoryID`, `SellerID`, `ProductName`, `Description`, `BasePrice`, `CompareAtPrice`, `ImageURL`, `Stock`, `Status`, `IsApproved`, `ApprovalStatus`, `Rating`, `ReviewCount`)
VALUES
  (101, 1, NULL, 'Canon EOS R6 Mark II',       'Full-frame mirrorless camera.',              154995.00, NULL,     'images/canon.jpg',   25, 'Active', 1, 'Approved', 4.9, NULL),
  (102, 1, NULL, 'Sony Alpha a7 IV',           'Full-frame mirrorless camera.',              124995.00, NULL,     'images/sony.jpg',    25, 'Active', 1, 'Approved', 4.9, NULL),
  (103, 2, NULL, 'Canon RF 24-70mm f/2.8L',    'Standard zoom lens.',                         89995.00, 99995.00, 'images/canonrf.jpg', 25, 'Active', 1, 'Approved', 4.8, NULL),
  (104, 3, NULL, 'DJI Mini 3 Pro',             'Compact camera drone.',                       34995.00, NULL,     'images/minipro.jpg', 25, 'Active', 1, 'Approved', 4.9, NULL),
  (201, 1, NULL, 'Fujifilm X-T5',              'APS-C mirrorless camera.',                    94995.00, NULL,     'images/fuji.jpg',    25, 'Active', 1, 'Approved', 4.8, NULL),
  (202, 1, NULL, 'Nikon Z6 III',               'Full-frame mirrorless camera.',              119995.00, 129995.00,'images/nikon.jpg',   25, 'Active', 1, 'Approved', 4.7, NULL),
  (203, 2, NULL, 'Sigma 35mm f/1.4 DG DN',     'Prime lens.',                                 42995.00, NULL,     'images/sigma.jpg',   25, 'Active', 1, 'Approved', 4.9, NULL),
  (204, 4, NULL, 'Peak Design Everyday Sling', 'Everyday carry sling bag.',                    6495.00, NULL,     'images/bag.jpg',     25, 'Active', 1, 'Approved', 4.6, NULL);

-- >>>>>>>> 2026_11_sellers.sql
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

-- >>>>>>>> 2026_11_orders.sql
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

-- >>>>>>>> 2026_11_support.sql
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

-- >>>>>>>> 2026_11_admin_audit.sql
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
SET FOREIGN_KEY_CHECKS = 1;

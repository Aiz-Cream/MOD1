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
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: kph_user_account_identity
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `auth_identity`
--

DROP TABLE IF EXISTS `auth_identity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_identity` (
  `AuthIdentityID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Provider` varchar(100) NOT NULL,
  `ProviderUserID` varchar(255) NOT NULL,
  `LinkedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AuthIdentityID`),
  KEY `fk_auth_identity_UserID` (`UserID`),
  CONSTRAINT `fk_auth_identity_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_identity`
--

LOCK TABLES `auth_identity` WRITE;
/*!40000 ALTER TABLE `auth_identity` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_identity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cart_items` (
  `CartItemID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `CartID` int(10) unsigned NOT NULL,
  `ProductID` int(10) unsigned NOT NULL,
  `ProductName` varchar(255) NOT NULL,
  `UnitPrice` decimal(12,2) NOT NULL,
  `ImageURL` varchar(500) DEFAULT NULL,
  `Quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `AddedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`CartItemID`),
  UNIQUE KEY `uq_cart_items_Cart_Product` (`CartID`,`ProductID`),
  CONSTRAINT `fk_cart_items_CartID` FOREIGN KEY (`CartID`) REFERENCES `carts` (`CartID`),
  CONSTRAINT `chk_cart_items_Quantity` CHECK (`Quantity` >= 1 and `Quantity` <= 99)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
INSERT INTO `cart_items` VALUES (4,1,2001,'Fujifilm X-T5',94995.00,NULL,2,'2026-11-21 16:27:41','2026-11-21 16:27:41'),(5,1,4000,'Price Test zero',0.00,NULL,1,'2026-11-21 16:27:41','2026-11-21 16:27:41'),(6,1,4010,'Price Test two decimals string',10.55,NULL,3,'2026-11-21 16:27:41','2026-11-21 16:27:41'),(9,1,5001,'Qty Test float',100.00,NULL,1,'2026-11-21 16:27:41','2026-11-21 16:27:41');
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `carts` (
  `CartID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`CartID`),
  UNIQUE KEY `uq_carts_UserID` (`UserID`),
  CONSTRAINT `fk_carts_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
INSERT INTO `carts` VALUES (1,4,'2026-11-21 16:27:41','2026-11-21 16:27:41'),(2,5,'2026-11-21 16:27:41','2026-11-21 16:27:41'),(3,6,'2026-11-21 16:30:40','2026-11-21 16:30:40'),(4,7,'2026-11-21 16:30:40','2026-11-21 16:30:40'),(5,10,'2026-11-21 17:17:44','2026-11-21 17:17:44'),(6,9,'2026-11-21 17:17:44','2026-11-21 17:17:44'),(7,11,'2026-11-21 17:17:45','2026-11-21 17:17:45'),(8,12,'2026-11-21 17:45:06','2026-11-21 17:45:06'),(9,13,'2026-11-21 17:45:06','2026-11-21 17:45:06'),(10,8,'2026-11-21 18:06:53','2026-11-21 18:06:53'),(11,16,'2026-11-21 18:38:23','2026-11-21 18:38:23'),(12,17,'2026-11-21 18:38:23','2026-11-21 18:38:23'),(13,18,'2026-11-22 14:05:29','2026-11-22 14:05:29'),(14,19,'2026-11-22 14:05:29','2026-11-22 14:05:29');
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kyc_verification`
--

DROP TABLE IF EXISTS `kyc_verification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kyc_verification` (
  `KYCID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `IDType` varchar(100) NOT NULL,
  `IDNumber` varchar(255) NOT NULL COMMENT 'stored encrypted',
  `IDImageURL` varchar(500) NOT NULL,
  `SelfieImageURL` varchar(500) NOT NULL,
  `VerificationStatus` varchar(30) NOT NULL,
  `SubmittedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `VerifiedAt` datetime DEFAULT NULL,
  `ReviewedByAdminID` int(10) unsigned DEFAULT NULL COMMENT 'Module 10: admin_user.AdminID (plain ID, no FK)',
  `RejectionReason` text DEFAULT NULL,
  PRIMARY KEY (`KYCID`),
  KEY `idx_kyc_verification_ReviewedByAdminID` (`ReviewedByAdminID`),
  KEY `fk_kyc_verification_UserID` (`UserID`),
  CONSTRAINT `fk_kyc_verification_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kyc_verification`
--

LOCK TABLES `kyc_verification` WRITE;
/*!40000 ALTER TABLE `kyc_verification` DISABLE KEYS */;
/*!40000 ALTER TABLE `kyc_verification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role`
--

DROP TABLE IF EXISTS `role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role` (
  `RoleID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `RoleName` varchar(100) NOT NULL,
  `Description` text DEFAULT NULL,
  PRIMARY KEY (`RoleID`),
  UNIQUE KEY `uq_role_RoleName` (`RoleName`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role`
--

LOCK TABLES `role` WRITE;
/*!40000 ALTER TABLE `role` DISABLE KEYS */;
INSERT INTO `role` VALUES (1,'buyer','Can browse, buy, and review items.'),(2,'seller','Can list items for sale after passing the seller check.'),(3,'admin','KPH staff who manage users, listings, disputes and reports.');
/*!40000 ALTER TABLE `role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user` (
  `UserID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `FirstName` varchar(100) NOT NULL,
  `MiddleName` varchar(100) DEFAULT NULL,
  `LastName` varchar(100) NOT NULL,
  `Email` varchar(191) NOT NULL,
  `PhoneNumber` varchar(20) NOT NULL,
  `PasswordHash` varchar(255) DEFAULT NULL,
  `EmailVerifiedAt` datetime DEFAULT NULL,
  `PhoneVerifiedAt` datetime DEFAULT NULL,
  `DateJoined` datetime NOT NULL DEFAULT current_timestamp(),
  `LastLoginAt` datetime DEFAULT NULL,
  `Status` varchar(30) NOT NULL,
  `SuspensionReason` text DEFAULT NULL,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `uq_user_Email` (`Email`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES (3,'Cristopher',NULL,'dabu','cristopher@gmail.com','09882281927','$2y$10$NAJQM9ef0GAvllQ.oJa9MOoA7B3Qt//nxYlqrZN4NZxCoGhd9K/Ii',NULL,NULL,'2026-11-21 15:58:01',NULL,'Active',NULL),(4,'Cart',NULL,'TestA','carttest.a.328c5f@example.test','09295542151','$2y$10$OvDIM/EXWyW8kT0ywYtqJOZZQ2oi55sZpcLMVj2yGPC3phjEg.T7q',NULL,NULL,'2026-11-21 16:27:41',NULL,'Active',NULL),(5,'Cart',NULL,'TestB','carttest.b.328c5f@example.test','09293294727','$2y$10$lY3ET9Wrk55nIr0zbsOZ8OUfIzv0kt00OaMEq/G/p.iIN1hF8HEIu',NULL,NULL,'2026-11-21 16:27:41',NULL,'Active',NULL),(6,'Cart',NULL,'TestA','carttest.a.4d20fb@example.test','09298473124','$2y$10$N93Zn/ywM31dNPuopp1Ao.YO9lZ1uQgMC9rC2SO94xBLctacLxsRK',NULL,NULL,'2026-11-21 16:30:40',NULL,'Active',NULL),(7,'Cart',NULL,'TestB','carttest.b.4d20fb@example.test','09299725241','$2y$10$M.82EaGXFaFA0vuCa1pTce.JdOzRM2n7Vzj6z.vcwf2ye/4uNo.GO',NULL,NULL,'2026-11-21 16:30:40',NULL,'Active',NULL),(8,'Cristopher',NULL,'dabu','cristopherdabu023@gmail.com','09882281929','$2y$10$hQraDnH/FjEl1T276XmUxeNYbPGPcUApUD72y.S081cs6UCB4JwNe',NULL,NULL,'2026-11-21 16:36:05',NULL,'Active',NULL),(9,'Http',NULL,'Check','httpcheck.5e37d2@example.test','09283718216','$2y$10$6QcEUNbg4o.pfXyQSOqeieQ91qAJxV3MTyDzhJosJsYJx0t.XSYkW',NULL,NULL,'2026-11-21 17:17:44','2026-11-21 17:17:44','Active',NULL),(10,'Cart',NULL,'TestA','carttest.a.2bb3ee@example.test','09297042110','$2y$10$ck2XdrXUC/9wAimDO9NirOw0JqoCJ9sXnY.H9oKZJjyMXDMzBsk22',NULL,NULL,'2026-11-21 17:17:44',NULL,'Active',NULL),(11,'Cart',NULL,'TestB','carttest.b.2bb3ee@example.test','09292414688','$2y$10$tAL3EzXLjd1U0LBlKQwb4ejo6LCt7K5jA3RgoIjLaqc.uD5bKbt5a',NULL,NULL,'2026-11-21 17:17:44',NULL,'Active',NULL),(12,'Cart',NULL,'TestA','carttest.a.470535@example.test','09296724716','$2y$10$Qob3awT62xUyRy05kefnvueFfk7bigSweqcOthXnYe32LMxaFM34G',NULL,NULL,'2026-11-21 17:45:06',NULL,'Active',NULL),(13,'Cart',NULL,'TestB','carttest.b.470535@example.test','09294788342','$2y$10$9RruStR2rM.uGADeCjQ6J.Arsfd2PBAN5aUuUsErjKPxbjk/2YsHK',NULL,NULL,'2026-11-21 17:45:06',NULL,'Active',NULL),(16,'Cart',NULL,'TestA','carttest.a.6f14f7@example.test','09299030437','$2y$10$7oWNJUCSc00Nxl/.Ava/GOmFSmwgMo0dvEtMvJCwxjWbku8Jl8Ku2',NULL,NULL,'2026-11-21 18:38:23',NULL,'Active',NULL),(17,'Cart',NULL,'TestB','carttest.b.6f14f7@example.test','09298538018','$2y$10$jiwBQqv3DdJm/kqPzpjPzeMhOjB8Z5VVczAj/NsbXtV7B69pXV/3m',NULL,NULL,'2026-11-21 18:38:23',NULL,'Active',NULL),(18,'Cart',NULL,'TestA','carttest.a.195a80@example.test','09294806032','$2y$10$l9uMhg/50gBxIbMgd5mOjecbIjsla6vNi4ekaTlUs4b927C2S5O7i',NULL,NULL,'2026-11-22 14:05:29',NULL,'Active',NULL),(19,'Cart',NULL,'TestB','carttest.b.195a80@example.test','09296589015','$2y$10$m/1tk/tgvv.xHT0osXezvuC5IENAHNovYQuKLq7Jwjfn9mEBfI.ly',NULL,NULL,'2026-11-22 14:05:29',NULL,'Active',NULL);
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_address`
--

DROP TABLE IF EXISTS `user_address`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_address` (
  `AddressID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `RecipientFirstName` varchar(100) NOT NULL,
  `RecipientMiddleName` varchar(100) DEFAULT NULL,
  `RecipientLastName` varchar(100) NOT NULL,
  `PhoneNumber` varchar(20) NOT NULL,
  `HouseUnitNumber` varchar(100) NOT NULL,
  `Street` varchar(255) NOT NULL,
  `Barangay` varchar(100) NOT NULL,
  `City` varchar(100) NOT NULL,
  `Province` varchar(100) NOT NULL,
  `PostalCode` varchar(10) NOT NULL,
  `AddressType` varchar(30) NOT NULL,
  `IsDefault` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`AddressID`),
  KEY `fk_user_address_UserID` (`UserID`),
  CONSTRAINT `fk_user_address_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_address`
--

LOCK TABLES `user_address` WRITE;
/*!40000 ALTER TABLE `user_address` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_address` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_profile`
--

DROP TABLE IF EXISTS `user_profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_profile` (
  `ProfileID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Username` varchar(100) NOT NULL,
  `Bio` text DEFAULT NULL,
  `ProfilePictureURL` varchar(500) DEFAULT NULL,
  `DateOfBirth` date DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ProfileID`),
  UNIQUE KEY `uq_user_profile_UserID` (`UserID`),
  CONSTRAINT `fk_user_profile_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_profile`
--

LOCK TABLES `user_profile` WRITE;
/*!40000 ALTER TABLE `user_profile` DISABLE KEYS */;
INSERT INTO `user_profile` VALUES (3,3,'cristopher',NULL,NULL,NULL,'2026-11-21 15:58:01'),(4,4,'carttest.a.328c5f',NULL,NULL,NULL,'2026-11-21 16:27:41'),(5,5,'carttest.b.328c5f',NULL,NULL,NULL,'2026-11-21 16:27:41'),(6,6,'carttest.a.4d20fb',NULL,NULL,NULL,'2026-11-21 16:30:40'),(7,7,'carttest.b.4d20fb',NULL,NULL,NULL,'2026-11-21 16:30:40'),(8,8,'cristopherdabu023','paldo bumili',NULL,'2025-07-16','2026-11-21 16:36:05'),(9,9,'httpcheck.5e37d2',NULL,NULL,NULL,'2026-11-21 17:17:44'),(10,10,'carttest.a.2bb3ee',NULL,NULL,NULL,'2026-11-21 17:17:44'),(11,11,'carttest.b.2bb3ee',NULL,NULL,NULL,'2026-11-21 17:17:44'),(12,12,'carttest.a.470535',NULL,NULL,NULL,'2026-11-21 17:45:06'),(13,13,'carttest.b.470535',NULL,NULL,NULL,'2026-11-21 17:45:06'),(16,16,'carttest.a.6f14f7',NULL,NULL,NULL,'2026-11-21 18:38:23'),(17,17,'carttest.b.6f14f7',NULL,NULL,NULL,'2026-11-21 18:38:23'),(18,18,'carttest.a.195a80',NULL,NULL,NULL,'2026-11-22 14:05:29'),(19,19,'carttest.b.195a80',NULL,NULL,NULL,'2026-11-22 14:05:29');
/*!40000 ALTER TABLE `user_profile` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_role`
--

DROP TABLE IF EXISTS `user_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_role` (
  `UserRoleID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `RoleID` int(10) unsigned NOT NULL,
  `AssignedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`UserRoleID`),
  UNIQUE KEY `uq_user_role_UserID_RoleID` (`UserID`,`RoleID`),
  KEY `fk_user_role_RoleID` (`RoleID`),
  CONSTRAINT `fk_user_role_RoleID` FOREIGN KEY (`RoleID`) REFERENCES `role` (`RoleID`),
  CONSTRAINT `fk_user_role_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_role`
--

LOCK TABLES `user_role` WRITE;
/*!40000 ALTER TABLE `user_role` DISABLE KEYS */;
INSERT INTO `user_role` VALUES (3,3,1,'2026-11-21 15:58:01'),(4,4,1,'2026-11-21 16:27:41'),(5,5,1,'2026-11-21 16:27:41'),(6,6,1,'2026-11-21 16:30:40'),(7,7,1,'2026-11-21 16:30:40'),(8,8,1,'2026-11-21 16:36:05'),(9,9,1,'2026-11-21 17:17:44'),(10,10,1,'2026-11-21 17:17:44'),(11,11,1,'2026-11-21 17:17:44'),(12,12,1,'2026-11-21 17:45:06'),(13,13,1,'2026-11-21 17:45:06'),(16,16,1,'2026-11-21 18:38:23'),(17,17,1,'2026-11-21 18:38:23'),(18,18,1,'2026-11-22 14:05:29'),(19,19,1,'2026-11-22 14:05:29');
/*!40000 ALTER TABLE `user_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verification_token`
--

DROP TABLE IF EXISTS `verification_token`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `verification_token` (
  `TokenID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Purpose` varchar(100) NOT NULL,
  `TokenHash` varchar(255) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `UsedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`TokenID`),
  KEY `fk_verification_token_UserID` (`UserID`),
  CONSTRAINT `fk_verification_token_UserID` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verification_token`
--

LOCK TABLES `verification_token` WRITE;
/*!40000 ALTER TABLE `verification_token` DISABLE KEYS */;
INSERT INTO `verification_token` VALUES (5,3,'session','088168a56b03e4a8ba342edc565bfda961cad74ad98c1fd6ea84d41bdc70a8f3','2026-11-22 15:58:01',NULL),(6,4,'session','6d057eaf55f9baa6492d84ff3fbe73147ae57ae8cf0d72bb5f7d749105c93a51','2026-11-22 16:27:41',NULL),(7,5,'session','e1d8990325fe44c0be112ecfad954ea02ac96164ac00ce2cf0de6c45c0300205','2026-11-22 16:27:41',NULL),(8,6,'session','add45dfa3257ba12049dc1ef187ea6bafa81710d7baec41a299fbc33c8883cea','2026-11-22 16:30:40',NULL),(9,7,'session','66b9cc8d38038e4a326b028aa82a2f0d2880ebebe1352f6fc4796dcd93ec1ea5','2026-11-22 16:30:40',NULL),(10,8,'session','231ff38c448f4b22b3613892720f6779f03aa78f10a8818e590fa45484d81f99','2026-11-22 16:36:05',NULL),(11,9,'session','4d8dd9be0b51b2da429fc53d21ed32dbcf913708e05a176d162fdef3f2351518','2026-11-22 17:17:44',NULL),(12,10,'session','ad5472eeb538416ef64b9873826e40c9cf5506b2fb1b7f9ac71102beef6f0ee7','2026-11-22 17:17:44',NULL),(13,9,'session','511f363d843bfe674f873098818983895a4d9a8443b188269d77c2829dd53d26','2026-11-22 17:17:44',NULL),(14,11,'session','fbc2c654a9c474490a1091af70c62ca1d331ac3da22bad1b45ce4eaee5b39bfb','2026-11-22 17:17:44',NULL),(15,12,'session','5823c07cf5b0bd3627aa51a6045ebb33881326bccd77a230cbddd303374224f6','2026-11-22 17:45:06',NULL),(16,13,'session','2775a8e0dde9e4dfda195b89861e210edb1218a0faa9ce620b7dc982a422b8ca','2026-11-22 17:45:06',NULL),(19,16,'session','bdb5a0673b1245def01fee8e943afcf80602f94511f45b0fe037795cb66962af','2026-11-22 18:38:23',NULL),(20,17,'session','5505ec4413645305428e9e1f1889aaa2befc077f66c6f2d7353e12bb720d8e77','2026-11-22 18:38:23',NULL),(23,18,'session','6b07cd0e78f49b00eff6d8889da45b4a25d9bd79458642afa6b7e9511a3e238a','2026-11-23 14:05:29',NULL),(24,19,'session','8c1ba02784dc4f35d607ed19c0e69e030eab3d971f426b8a1eb0abe28c67613f','2026-11-23 14:05:29',NULL);
/*!40000 ALTER TABLE `verification_token` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-22 14:08:15

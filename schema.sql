-- ============================================================================
-- ENG_251885 APP — Kids Store Telegram Mini App
-- MySQL 8.0+ Database Schema
-- Engine: InnoDB | Charset: utf8mb4 (full emoji / Amharic support)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `eng251885_kidstore`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `eng251885_kidstore`;

-- ----------------------------------------------------------------------------
-- 1. users — Telegram-authenticated customers
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `telegram_id`   BIGINT UNSIGNED NOT NULL,
  `username`      VARCHAR(64)     DEFAULT NULL,
  `first_name`    VARCHAR(128)    NOT NULL,
  `last_name`     VARCHAR(128)    DEFAULT NULL,
  `language_code` VARCHAR(8)      DEFAULT 'en',
  `is_premium`    TINYINT(1)      NOT NULL DEFAULT 0,
  `photo_url`     VARCHAR(512)    DEFAULT NULL,
  `total_spent`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `is_banned`     TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`telegram_id`),
  KEY `idx_users_username` (`username`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. categories — Shoes / Clothes / Kids Equipment + age sub-tags
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `parent_id`   INT UNSIGNED    DEFAULT NULL COMMENT 'NULL = top-level category, else age sub-tag',
  `name`        VARCHAR(128)    NOT NULL,
  `slug`        VARCHAR(160)    NOT NULL,
  `age_min`     TINYINT UNSIGNED DEFAULT NULL,
  `age_max`     TINYINT UNSIGNED DEFAULT NULL,
  `icon`        VARCHAR(32)     DEFAULT NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent`
    FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. products
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `category_id`    INT UNSIGNED    NOT NULL,
  `name`           VARCHAR(160)    NOT NULL,
  `description`    TEXT            DEFAULT NULL,
  `price`          DECIMAL(10,2)   NOT NULL,
  `age_range`      VARCHAR(32)     NOT NULL COMMENT 'e.g. 5-7Y',
  `stock`          INT UNSIGNED    NOT NULL DEFAULT 0,
  `image_url`      VARCHAR(512)    DEFAULT NULL,
  `download_link`  VARCHAR(512)    DEFAULT NULL COMMENT 'digital asset / manual path',
  `file_path`      VARCHAR(512)    DEFAULT NULL COMMENT 'server-side protected file path',
  `product_key`    VARCHAR(64)     DEFAULT NULL COMMENT 'license / redemption key template',
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_active_age` (`is_active`, `age_range`),
  FULLTEXT KEY `ftx_products_search` (`name`, `description`),
  CONSTRAINT `fk_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. orders
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `total_amount`        DECIMAL(12,2)   NOT NULL,
  `payment_method`      ENUM('telebirr','cbe_birr','dashen_amole','awash_birr','paypal','mastercard','telegram_stars')
                          NOT NULL,
  `payment_status`      ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `telegram_payment_id` VARCHAR(128)    DEFAULT NULL,
  `provider_reference`  VARCHAR(191)    DEFAULT NULL COMMENT 'bank/PSP transaction ref',
  `webhook_payload`     JSON            DEFAULT NULL,
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`             TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_status` (`payment_status`),
  KEY `idx_orders_provider_ref` (`provider_reference`),
  CONSTRAINT `fk_orders_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`telegram_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. order_items
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED    NOT NULL,
  `product_id`  INT UNSIGNED    NOT NULL,
  `quantity`    INT UNSIGNED    NOT NULL DEFAULT 1,
  `price`       DECIMAL(10,2)   NOT NULL COMMENT 'unit price at time of purchase',
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_product` (`product_id`),
  CONSTRAINT `fk_order_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. admins — backend dashboard operators
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(64)     NOT NULL,
  `password_hash`  VARCHAR(255)    NOT NULL COMMENT 'password_hash() / Argon2id',
  `session_token`  VARCHAR(128)    DEFAULT NULL,
  `session_expires` TIMESTAMP      NULL DEFAULT NULL,
  `last_login_at`  TIMESTAMP       NULL DEFAULT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`),
  UNIQUE KEY `uq_admins_session_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Seed data: top-level categories + age sub-tags
-- ----------------------------------------------------------------------------
INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `icon`, `sort_order`) VALUES
  (1, NULL, 'Shoes',          'shoes',           '👟', 1),
  (2, NULL, 'Clothes',        'clothes',         '👕', 2),
  (3, NULL, 'Kids Equipment', 'kids-equipment',  '🧸', 3);

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `age_min`, `age_max`, `sort_order`) VALUES
  (1, 'Infants 0-2Y', 'shoes-infants-0-2y', 0, 2,  1),
  (1, 'Kids 3-7Y',    'shoes-kids-3-7y',    3, 7,  2),
  (1, 'Juniors 8-12Y','shoes-juniors-8-12y',8, 12, 3),
  (1, 'Teens 13-16Y', 'shoes-teens-13-16y', 13,16, 4),

  (2, 'Baby 0-2Y',     'clothes-baby-0-2y',     0, 2,  1),
  (2, 'Toddlers 3-5Y', 'clothes-toddlers-3-5y', 3, 5,  2),
  (2, 'Children 6-10Y','clothes-children-6-10y',6, 10, 3),
  (2, 'Teens 11-16Y',  'clothes-teens-11-16y',  11,16, 4),

  (3, '0-3 Years',  'equipment-0-3y',  0, 3,  1),
  (3, '4-7 Years',  'equipment-4-7y',  4, 7,  2),
  (3, '8-12 Years', 'equipment-8-12y', 8, 12, 3),
  (3, '13-16 Years','equipment-13-16y',13,16, 4);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  VAPE CO. — database schema
--  Vape_ShopWEb / database / schema.sql
--
--  HOW TO USE
--    1. Open phpMyAdmin → Import → pick this file → Go.
--       (Or from the command line:
--        C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql)
--    2. That creates the `vape_shop` database and all 16 tables.
--    3. Then seed the data:
--         C:\xampp\php\php.exe database\import.php --confirm
--         C:\xampp\php\php.exe database\seed_admin.php admin@vapeco.ph "Admin@123" "Cristobal" "Admin"
--
--  NOTE: this file is a ONE-SHOT. It uses plain CREATE TABLE, so running it
--  twice will fail with "table already exists". Use database\reset.sql first
--  if you need to start over.
--
--  Every table is ENGINE=InnoDB (real foreign keys + transactions) and
--  utf8mb4 (so emoji and the ₱ sign survive).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS vape_shop
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vape_shop;

-- ---------------------------------------------------------------- roles
CREATE TABLE roles (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_name   VARCHAR(20)  NOT NULL UNIQUE,
  description VARCHAR(120) NOT NULL DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO roles (role_name, description) VALUES
  ('admin',    'Full access to the admin panel'),
  ('customer', 'Shopper account: cart, orders, reviews');

-- ---------------------------------------------------------------- users
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id       TINYINT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(60)  NOT NULL,
  last_name     VARCHAR(60)  NOT NULL,
  phone         VARCHAR(20)  DEFAULT NULL,
  birthdate     DATE NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  blocked_reason VARCHAR(255) DEFAULT NULL,
  blocked_at    DATETIME DEFAULT NULL,
  last_login_at DATETIME DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_role (role_id),
  INDEX idx_users_active (is_active)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- categories
CREATE TABLE categories (
  id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(40) NOT NULL UNIQUE,
  name       VARCHAR(60) NOT NULL,
  icon_key   VARCHAR(40) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- products
CREATE TABLE products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id TINYINT UNSIGNED NOT NULL,
  slug        VARCHAR(140) NOT NULL UNIQUE,
  name        VARCHAR(140) NOT NULL,
  brand       VARCHAR(60)  DEFAULT NULL,
  description TEXT NOT NULL,
  base_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  image_url   VARCHAR(255) DEFAULT NULL,
  puffs       SMALLINT UNSIGNED DEFAULT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_products_category (category_id),
  INDEX idx_products_active_featured (is_active, is_featured),
  INDEX idx_products_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- variants (SKUs)
-- strength / flavor use '' (not NULL) so the UNIQUE key below actually works.
CREATE TABLE product_variants (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id       INT UNSIGNED NOT NULL,
  sku              VARCHAR(40) NOT NULL UNIQUE,
  strength         VARCHAR(30) NOT NULL DEFAULT '',
  flavor           VARCHAR(60) NOT NULL DEFAULT '',
  price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock_qty        INT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_variant_combo (product_id, strength, flavor),
  INDEX idx_variants_stock (stock_qty)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- carts
-- user_id NULL  -> guest cart (keyed by guest_token)
-- guest_token NULL -> logged-in cart (keyed by user_id)
-- MySQL allows many NULLs in a UNIQUE index, so this holds both cases cleanly.
CREATE TABLE carts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  guest_token CHAR(64) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_carts_user (user_id),
  UNIQUE KEY uq_carts_guest (guest_token)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- cart items
CREATE TABLE cart_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id    INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NOT NULL,
  qty        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cart_items_cart    FOREIGN KEY (cart_id)    REFERENCES carts(id)            ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)  ON DELETE CASCADE,
  UNIQUE KEY uq_cart_variant (cart_id, variant_id),
  CONSTRAINT chk_cart_items_qty CHECK (qty BETWEEN 1 AND 10)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- addresses
CREATE TABLE addresses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  label          VARCHAR(40)  NOT NULL DEFAULT 'Home',
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(20)  NOT NULL,
  address_line   VARCHAR(255) NOT NULL,
  notes          VARCHAR(255) DEFAULT NULL,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_addresses_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- orders
CREATE TABLE orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number    VARCHAR(20) NOT NULL UNIQUE,
  user_id         INT UNSIGNED NOT NULL,
  status          ENUM('PENDING','PAID','PACKING','SHIPPED','DELIVERED','CANCELLED')
                    NOT NULL DEFAULT 'PENDING',
  payment_method  ENUM('CARD','GCASH','COD') NOT NULL,
  subtotal        DECIMAL(10,2) NOT NULL,
  vat_rate        DECIMAL(5,4) NOT NULL DEFAULT 0.1200,
  vat_amount      DECIMAL(10,2) NOT NULL,
  shipping_fee    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total           DECIMAL(10,2) NOT NULL,
  coupon_code     VARCHAR(30) DEFAULT NULL,
  ship_to_name    VARCHAR(120) NOT NULL,
  ship_to_phone   VARCHAR(20)  NOT NULL,
  ship_to_address VARCHAR(255) NOT NULL,
  ship_to_notes   VARCHAR(255) DEFAULT NULL,
  placed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_orders_user (user_id),
  INDEX idx_orders_status (status),
  INDEX idx_orders_placed (placed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- order items (frozen snapshot)
CREATE TABLE order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED DEFAULT NULL,
  variant_id   INT UNSIGNED DEFAULT NULL,
  product_name VARCHAR(140) NOT NULL,
  sku          VARCHAR(40)  DEFAULT NULL,
  strength     VARCHAR(30)  NOT NULL DEFAULT '',
  flavor       VARCHAR(60)  NOT NULL DEFAULT '',
  unit_price   DECIMAL(10,2) NOT NULL,
  qty          SMALLINT UNSIGNED NOT NULL,
  line_total   DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- order status history
CREATE TABLE order_status_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL,
  to_status   VARCHAR(20) NOT NULL,
  changed_by  INT UNSIGNED DEFAULT NULL,
  note        VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_osh_user  FOREIGN KEY (changed_by) REFERENCES users(id),
  INDEX idx_osh_order (order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- coupons (Phase 10 stretch)
CREATE TABLE coupons (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL UNIQUE,
  discount_type ENUM('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
  value         DECIMAL(10,2) NOT NULL,
  min_subtotal  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_uses      INT UNSIGNED DEFAULT NULL,
  used_count    INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at    DATETIME DEFAULT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- wishlist (Phase 10 stretch)
CREATE TABLE wishlist (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wishlist_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_wishlist (user_id, product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- reviews (Phase 10 stretch)
CREATE TABLE product_reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL,
  comment     TEXT DEFAULT NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  UNIQUE KEY uq_review (product_id, user_id),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- audit log
CREATE TABLE audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  VARCHAR(40) DEFAULT NULL,
  details    TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- login attempts
-- No FK on purpose: attempts must be recorded even for unknown email addresses.
CREATE TABLE login_attempts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL,
  ip_address  VARCHAR(45) NOT NULL,
  was_success TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_email (email),
  INDEX idx_attempts_created (created_at)
) ENGINE=InnoDB;

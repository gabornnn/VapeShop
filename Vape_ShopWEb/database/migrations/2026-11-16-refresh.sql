-- ============================================================================
--  VAPE CO. — refresh migration
--  Run once on an existing `vape_shop` database.
--
--  MySQL/MariaDB DDL auto-commits, so back up the database first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root vape_shop > backup.sql
--
--  Fresh installs should import database/schema.sql instead.
-- ============================================================================

USE vape_shop;

ALTER TABLE users
  ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL AFTER last_login_at,
  ADD COLUMN terms_version VARCHAR(20) DEFAULT NULL AFTER terms_accepted_at;

ALTER TABLE orders
  MODIFY payment_method VARCHAR(20) NOT NULL;

CREATE TABLE IF NOT EXISTS order_payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  method     VARCHAR(20) NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_payments_order (order_id),
  CONSTRAINT chk_order_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- Backfill old orders with one payment row each.
-- Old CARD/COD values remain readable because the column is now VARCHAR.
INSERT INTO order_payments (order_id, method, amount)
SELECT o.id, o.payment_method, o.total
  FROM orders o
 WHERE NOT EXISTS (
   SELECT 1 FROM order_payments op WHERE op.order_id = o.id
 );

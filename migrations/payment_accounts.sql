-- Run after product_orders.sql. Existing GCash orders and receipts are retained.
ALTER TABLE product_orders MODIFY payment_method ENUM('clinic','gcash','bank') NOT NULL;
CREATE TABLE IF NOT EXISTS product_payment_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method ENUM('gcash','bank') NOT NULL,
    provider VARCHAR(80) NOT NULL,
    account_name VARCHAR(120) NOT NULL,
    account_number VARCHAR(40) NOT NULL,
    qr_token CHAR(48) NOT NULL UNIQUE,
    qr_data MEDIUMBLOB NULL,
    qr_mime VARCHAR(30) NULL,
    enabled TINYINT NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_order_recipients (
    order_id INT PRIMARY KEY,
    account_id INT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES product_orders(id),
    FOREIGN KEY (account_id) REFERENCES product_payment_accounts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_order_bank_references (
    reference_key CHAR(64) PRIMARY KEY,
    order_id INT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES product_orders(id)
) ENGINE=InnoDB;

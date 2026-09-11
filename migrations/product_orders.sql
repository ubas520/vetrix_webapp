-- Additive migration. Existing inventory and POS tables must already exist.
CREATE TABLE IF NOT EXISTS product_payment_settings (
    id INT PRIMARY KEY,
    gcash_name VARCHAR(120) NOT NULL,
    gcash_number VARCHAR(30) NOT NULL,
    updated_by INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    request_key VARCHAR(80) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    payment_method ENUM('clinic','gcash') NOT NULL,
    payment_status ENUM('pending','under_review','rejected','paid') NOT NULL DEFAULT 'pending',
    status ENUM('placed','ready','completed','cancelled') NOT NULL DEFAULT 'placed',
    total_amount DECIMAL(10,2) NOT NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    staff_note VARCHAR(500) NOT NULL DEFAULT '',
    gcash_name VARCHAR(120) NOT NULL DEFAULT '',
    gcash_number VARCHAR(30) NOT NULL DEFAULT '',
    transaction_id INT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY client_request (client_id, request_key),
    INDEX order_queue (status, created_at),
    FOREIGN KEY (client_id) REFERENCES users(id),
    FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(160) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    UNIQUE KEY order_item (order_id, item_id),
    FOREIGN KEY (order_id) REFERENCES product_orders(id),
    FOREIGN KEY (item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_order_gcash_references (
    reference VARCHAR(40) PRIMARY KEY,
    order_id INT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES product_orders(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_order_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    reference VARCHAR(40) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    image_mime VARCHAR(30) NOT NULL,
    image_hash CHAR(64) NOT NULL,
    status ENUM('under_review','accepted','rejected') NOT NULL DEFAULT 'under_review',
    reviewed_by INT NULL,
    review_note VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX proof_order (order_id, id),
    INDEX proof_reference (reference),
    FOREIGN KEY (order_id) REFERENCES product_orders(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_order_notifications (
    notification_id INT PRIMARY KEY,
    order_id INT NOT NULL,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES product_orders(id)
) ENGINE=InnoDB;

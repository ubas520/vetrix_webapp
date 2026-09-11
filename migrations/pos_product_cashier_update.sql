USE vetrix;

CREATE TABLE IF NOT EXISTS pos_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    handled_by INT NULL,
    transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2) DEFAULT 0,
    payment_status ENUM('pending','paid','cancelled') DEFAULT 'paid',
    notes TEXT,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE pos_transactions ADD COLUMN IF NOT EXISTS payment_method ENUM('cash','qr') NOT NULL DEFAULT 'cash' AFTER payment_status;

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(60) NULL,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(80),
    sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_qty INT DEFAULT 0,
    unit VARCHAR(40),
    reorder_level INT DEFAULT 5,
    status ENUM('available','low_stock','out_of_stock','inactive') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS sku VARCHAR(60) NULL AFTER id;
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS sale_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER category;
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS pos_transaction_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    item_id INT NULL,
    item_name VARCHAR(160) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    movement_type ENUM('stock_in','stock_out') NOT NULL,
    quantity INT NOT NULL,
    remarks TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

UPDATE inventory_items SET sale_price = 0 WHERE sale_price IS NULL;


CREATE TABLE IF NOT EXISTS pos_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL UNIQUE,
    receipt_number VARCHAR(40) NOT NULL UNIQUE,
    generated_by INT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paper_width_mm SMALLINT NOT NULL DEFAULT 80,
    FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
);

DELETE r FROM pos_receipts r
JOIN pos_transactions t ON t.id = r.transaction_id
WHERE t.payment_status <> 'paid';

INSERT IGNORE INTO pos_receipts(transaction_id, receipt_number, generated_by, generated_at, paper_width_mm)
SELECT id, CONCAT('VTX-', LPAD(id, 6, '0')), handled_by, transaction_date, 80
FROM pos_transactions
WHERE payment_status = 'paid';

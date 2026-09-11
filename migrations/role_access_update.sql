USE vetrix;

ALTER TABLE users MODIFY role ENUM('admin','veterinarian','staff','client') NOT NULL DEFAULT 'client';
ALTER TABLE users MODIFY status ENUM('pending','approved','active','inactive','rejected') NOT NULL DEFAULT 'pending';

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS assigned_vet_id INT NULL AFTER scheduled_date;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS confirmation_code VARCHAR(40) NULL AFTER assigned_vet_id;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND CONSTRAINT_NAME = 'fk_appointments_assigned_vet'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_sql = IF(@fk_exists = 0,
    'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_assigned_vet FOREIGN KEY (assigned_vet_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

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

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(80),
    stock_qty INT DEFAULT 0,
    unit VARCHAR(40),
    reorder_level INT DEFAULT 5,
    status ENUM('available','low_stock','out_of_stock','inactive') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

INSERT IGNORE INTO users (full_name, email, phone, emergency_contact, address, password, role, account_source, created_by, status) VALUES
('Dr. Andrea Reyes', 'vet@vetclinic.test', '09170000004', 'Clinic Operations Team', 'Vetrix Main Clinic', '$2y$12$/xdg67Y34/6MsFk8HILsSOkF2NGcROKaHQW81yrI9p6rcSyzL.Hgi', 'veterinarian', 'system_seed', NULL, 'active'),
('Sofia Navarro', 'staff@vetclinic.test', '09170000005', 'Clinic Operations Team', 'Vetrix Main Clinic', '$2y$12$/xdg67Y34/6MsFk8HILsSOkF2NGcROKaHQW81yrI9p6rcSyzL.Hgi', 'staff', 'system_seed', NULL, 'active');


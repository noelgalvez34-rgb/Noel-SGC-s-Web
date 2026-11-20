CREATE DATABASE IF NOT EXISTS sgc_cooperative CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sgc_cooperative;

CREATE TABLE IF NOT EXISTS buyers (
  buyer_id INT AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(120) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  contact_number VARCHAR(40) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NULL,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(150) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Manager','Clerk','Buyer') NOT NULL DEFAULT 'Buyer',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_buyer FOREIGN KEY (buyer_id) REFERENCES buyers(buyer_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS farmers (
  farmer_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  contact_number VARCHAR(40) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grain_types (
  grain_id INT AUTO_INCREMENT PRIMARY KEY,
  grain_name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'kg',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory (
  inventory_id INT AUTO_INCREMENT PRIMARY KEY,
  grain_id INT NOT NULL UNIQUE,
  on_hand_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  reserved_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_grain FOREIGN KEY (grain_id) REFERENCES grain_types(grain_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deliveries (
  delivery_id INT AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT NOT NULL,
  grain_id INT NOT NULL,
  recorded_by INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  status ENUM('Pending','Accepted','Rejected') NOT NULL DEFAULT 'Accepted',
  notes TEXT NULL,
  delivery_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(farmer_id),
  CONSTRAINT fk_delivery_grain FOREIGN KEY (grain_id) REFERENCES grain_types(grain_id),
  CONSTRAINT fk_delivery_user FOREIGN KEY (recorded_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  order_id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  created_by INT NULL,
  order_reference VARCHAR(40) NOT NULL UNIQUE,
  order_source ENUM('Online','Clerk') NOT NULL DEFAULT 'Online',
  status ENUM('Pending','Approved','Rejected','Cancelled','Released') NOT NULL DEFAULT 'Pending',
  notes TEXT NULL,
  order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES buyers(buyer_id),
  CONSTRAINT fk_order_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  order_item_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  grain_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_item_grain FOREIGN KEY (grain_id) REFERENCES grain_types(grain_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  transaction_id INT AUTO_INCREMENT PRIMARY KEY,
  grain_id INT NOT NULL,
  reference_id INT NULL,
  reference_type VARCHAR(30) NOT NULL,
  transaction_type VARCHAR(40) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  recorded_by INT NULL,
  remarks VARCHAR(255) DEFAULT NULL,
  transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_grain FOREIGN KEY (grain_id) REFERENCES grain_types(grain_id),
  CONSTRAINT fk_tx_user FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_tx_date (transaction_date)
) ENGINE=InnoDB;

-- Demo logins are created by setup.php after importing this database.

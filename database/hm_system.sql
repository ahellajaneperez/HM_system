-- HM System database
-- Import this file using phpMyAdmin. Safe to import into a new database.
CREATE DATABASE IF NOT EXISTS hm_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hm_system;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    student_number VARCHAR(50) NULL UNIQUE,
    phone_number VARCHAR(20) NULL,
    course VARCHAR(150) NULL,
    section VARCHAR(80) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    category VARCHAR(120) NOT NULL,
    photo_path VARCHAR(255) NULL,
    total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_equipment_category (category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS borrowing_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    equipment_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    borrow_date DATE NOT NULL,
    return_date DATE NOT NULL,
    purpose TEXT NOT NULL,
    status ENUM('pending','approved','rejected','returned') NOT NULL DEFAULT 'pending',
    return_condition ENUM('good','damaged','missing') NULL,
    returned_at TIMESTAMP NULL DEFAULT NULL,
    return_notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_requests_user (user_id),
    INDEX idx_requests_equipment (equipment_id),
    INDEX idx_requests_status (status),
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_requests_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_request_quantity CHECK (quantity > 0),
    CONSTRAINT chk_request_dates CHECK (return_date >= borrow_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
INSERT INTO equipment (name, category, total_quantity) VALUES
('Wooden Spoon','Cooking Tools',10),
('Spatula','Cooking Tools',10),
('Ladle','Cooking Tools',10),
('Whisk','Cooking Tools',10),
('Tongs','Cooking Tools',10),
('Slotted Spoon','Cooking Tools',10),
('Peeler','Cooking Tools',10),
('Kitchen Fork','Cooking Tools',10),
('Measuring Cup','Measuring and Mixing',10),
('Measuring Spoons','Measuring and Mixing',10),
('Mixing Bowl','Measuring and Mixing',10),
('Kitchen Scale','Measuring and Mixing',10),
('Liquid Measuring Jug','Measuring and Mixing',10),
('Chef''s Knife','Cutting Tools',10),
('Paring Knife','Cutting Tools',10),
('Cutting Board','Cutting Tools',10),
('Kitchen Scissors','Cutting Tools',10),
('Bread Knife','Cutting Tools',10),
('Knife Sharpener','Cutting Tools',10),
('Cooking Pot','Pots and Pans',10),
('Frying Pan','Pots and Pans',10),
('Saucepan','Pots and Pans',10),
('Wok','Pots and Pans',10),
('Stockpot','Pots and Pans',10),
('Steamer','Pots and Pans',10),
('Baking Tray','Baking and Pastry Equipment',10),
('Rolling Pin','Baking and Pastry Equipment',10),
('Pastry Brush','Baking and Pastry Equipment',10),
('Cake Pan','Baking and Pastry Equipment',10),
('Muffin Tray','Baking and Pastry Equipment',10),
('Piping Bag','Baking and Pastry Equipment',10),
('Cooling Rack','Baking and Pastry Equipment',10),
('Dough Scraper','Baking and Pastry Equipment',10),
('Colander','Food Preparation Equipment',10),
('Strainer','Food Preparation Equipment',10),
('Salad Spinner','Food Preparation Equipment',10),
('Grater','Food Preparation Equipment',10),
('Mortar and Pestle','Food Preparation Equipment',10),
('Food Processor','Food Preparation Equipment',10),
('Blender','Food Preparation Equipment',10),
('Can Opener','Food Preparation Equipment',10),
('Food Storage Container','Food Storage and Preparation Containers',10),
('Mixing Container','Food Storage and Preparation Containers',10),
('Ingredient Bin','Food Storage and Preparation Containers',10),
('Measuring Jug','Food Storage and Preparation Containers',10),
('Food Tray','Food Storage and Preparation Containers',10),
('Stainless Steel Bowl','Food Storage and Preparation Containers',10),
('Plastic Food Container','Food Storage and Preparation Containers',10),
('Apron','Kitchen Safety and Cleaning Equipment',10),
('Oven Mitts','Kitchen Safety and Cleaning Equipment',10),
('Dishwashing Brush','Kitchen Safety and Cleaning Equipment',10),
('Cleaning Cloth','Kitchen Safety and Cleaning Equipment',10),
('Kitchen Gloves','Kitchen Safety and Cleaning Equipment',10),
('Dustpan and Brush','Kitchen Safety and Cleaning Equipment',10),
('Food Thermometer','Kitchen Safety and Cleaning Equipment',10),
('Sanitizing Spray Bottle','Kitchen Safety and Cleaning Equipment',10);

<?php
declare(strict_types=1);

// XAMPP default settings. Change these values if your MySQL credentials differ.
$host = '127.0.0.1';
$port = 3306;
$dbname = 'hm_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('HM System database connection error: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Import database/hm_system.sql in phpMyAdmin and check db.php settings.');
}

$profileColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
$profileColumns = [
    "student_number" => "VARCHAR(50) NULL UNIQUE",
    "course" => "VARCHAR(150) NULL",
    "section" => "VARCHAR(80) NULL",
];
foreach ($profileColumns as $columnName => $columnDefinition) {
    $profileColumnCheck->execute([$columnName]);
    if ((int) $profileColumnCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$columnName} {$columnDefinition}");
    }
}
$returnColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'borrowing_requests' AND COLUMN_NAME = ?");
$returnColumns = [
    "return_condition" => "ENUM('good','damaged','missing') NULL",
    "returned_at" => "TIMESTAMP NULL DEFAULT NULL",
    "return_notes" => "VARCHAR(255) NULL",
];
foreach ($returnColumns as $columnName => $columnDefinition) {
    $returnColumnCheck->execute([$columnName]);
    if ((int) $returnColumnCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE borrowing_requests ADD COLUMN {$columnName} {$columnDefinition}");
    }
}
$equipmentPhotoCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment' AND COLUMN_NAME = 'photo_path'");
$equipmentPhotoCheck->execute();
if ((int) $equipmentPhotoCheck->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE equipment ADD COLUMN photo_path VARCHAR(255) NULL AFTER category");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB");

function log_activity(PDO $pdo, ?int $userId, string $action, string $details): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $safeDetails = function_exists("mb_substr") ? mb_substr($details, 0, 255) : substr($details, 0, 255);
        $stmt->execute([$userId, $action, $safeDetails]);
    } catch (PDOException $e) {
        error_log('Activity log write failed: ' . $e->getMessage());
    }
}
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$name = 'Administrator';
$email = 'admin_hm@gmail.com';
$password = 'admin123';

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn();
if ($adminCount > 0) {
    exit('An administrator account already exists. Sign in with an administrator account to create additional administrators.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'superadmin')");
$stmt->execute([$name, $email, $hash]);
log_activity($pdo, (int) $pdo->lastInsertId(), "SuperAdmin created", "Initial SuperAdmin account was created.");

echo 'SuperAdmin account created. Email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
   . ' | Temporary password: ' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8')
   . '<br>Log in, then change the password. Delete create_admin.php after setup.';

<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$name = 'Administrator';
$email = 'admin_hm@gmail.com';
$password = 'admin123';

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    exit('Admin account already exists. Delete this setup file after installation.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute([$name, $email, $hash]);
log_activity($pdo, (int) $pdo->lastInsertId(), "Administrator created", "Initial administrator account was created.");

echo 'Admin account created. Email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
   . ' | Temporary password: ' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8')
   . '<br>Log in, then change the password. Delete create_admin.php after setup.';

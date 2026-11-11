<?php
$pageTitle = $pageTitle ?? "SuperAdmin";
$pageSubtitle = $pageSubtitle ?? "Hospitality Management System";
$activePage = $activePage ?? "dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= superadmin_e($pageTitle) ?> | HM System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/superadmin.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php">
            <img src="../images/ISU.png" alt="">
            <span><strong>HM SYSTEM</strong><small>SUPERADMIN PORTAL</small></span>
        </a>
        <div class="nav-label">MANAGEMENT</div>
        <nav class="side-nav" aria-label="SuperAdmin navigation">
            <a class="<?= $activePage === "dashboard" ? "active" : "" ?>" href="index.php" <?= $activePage === "dashboard" ? 'aria-current="page"' : "" ?>>Dashboard</a>
            <a class="<?= $activePage === "users" ? "active" : "" ?>" href="users.php" <?= $activePage === "users" ? 'aria-current="page"' : "" ?>>User Management</a>
            <a class="<?= $activePage === "passwords" ? "active" : "" ?>" href="reset_password.php" <?= $activePage === "passwords" ? 'aria-current="page"' : "" ?>>Reset Passwords</a>
            <a class="<?= $activePage === "activity" ? "active" : "" ?>" href="activity_logs.php" <?= $activePage === "activity" ? 'aria-current="page"' : "" ?>>Activity Logs</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="profile">
                <span class="avatar"><?= superadmin_e(strtoupper(substr($superAdminName, 0, 1))) ?></span>
                <span><strong><?= superadmin_e($superAdminName) ?></strong><small>SuperAdmin</small></span>
            </div>
            <a class="logout-link" href="../logout.php">Sign out</a>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div><h1><?= superadmin_e($pageTitle) ?></h1><p><?= superadmin_e($pageSubtitle) ?></p></div>
            <div class="topbar-user"><strong><?= superadmin_e($superAdminName) ?></strong><span>SuperAdmin</span></div>
        </header>
        <div class="page-content">

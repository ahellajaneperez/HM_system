<?php
$adminScript = basename($_SERVER["PHP_SELF"] ?? "");
if ($adminScript === "admin_dashboard.php") {
    $adminActiveSection = (string) ($_GET["section"] ?? "dashboard");
    if (!in_array($adminActiveSection, ["dashboard", "requests", "users"], true)) { $adminActiveSection = "dashboard"; }
} else {
    $adminActiveSection = [
        "admin_returns.php" => "returns",
        "borrowing_tools.php" => "tools",
        "activity_log.php" => "activity",
        "register.php" => (($_GET["type"] ?? "") === "admin" ? "admin-register" : "dashboard"),
    ][$adminScript] ?? "dashboard";
}
?>
<div class="nav-label">MAIN MENU</div>
<a href="admin_dashboard.php?section=dashboard" class="nav-link <?= $adminActiveSection === "dashboard" ? "active" : "" ?>" id="nav-dashboard" <?= $adminActiveSection === "dashboard" ? "aria-current=\"page\"" : "" ?>>Dashboard</a>
<a href="admin_dashboard.php?section=requests" class="nav-link <?= $adminActiveSection === "requests" ? "active" : "" ?>" id="nav-requests" <?= $adminActiveSection === "requests" ? "aria-current=\"page\"" : "" ?>>Equipment Requests</a>
<a href="admin_returns.php" class="nav-link <?= $adminActiveSection === "returns" ? "active" : "" ?>" <?= $adminActiveSection === "returns" ? "aria-current=\"page\"" : "" ?>>Returns</a>
<a href="borrowing_tools.php" class="nav-link <?= $adminActiveSection === "tools" ? "active" : "" ?>" <?= $adminActiveSection === "tools" ? "aria-current=\"page\"" : "" ?>>Borrowing Tools</a>
<a href="activity_log.php" class="nav-link <?= $adminActiveSection === "activity" ? "active" : "" ?>" <?= $adminActiveSection === "activity" ? "aria-current=\"page\"" : "" ?>>Activity Log</a>
<a href="admin_dashboard.php?section=users" class="nav-link <?= $adminActiveSection === "users" ? "active" : "" ?>" id="nav-users" <?= $adminActiveSection === "users" ? "aria-current=\"page\"" : "" ?>>User Accounts</a>
<?php if (($_SESSION["user_role"] ?? "") === "superadmin"): ?><a href="register.php?type=admin" class="nav-link <?= $adminActiveSection === "admin-register" ? "active" : "" ?>" <?= $adminActiveSection === "admin-register" ? "aria-current=\"page\"" : "" ?>>Create Administrator</a><?php endif; ?>
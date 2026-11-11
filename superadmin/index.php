<?php
require __DIR__ . "/_bootstrap.php";

$pageTitle = "Dashboard Overview";
$pageSubtitle = "SuperAdmin control center";
$activePage = "dashboard";

$metrics = [
    "users" => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    "students" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    "admins" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn(),
    "activities" => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn(),
];
$recentStmt = $pdo->query(
    "SELECT al.action, al.details, al.created_at, COALESCE(u.name, 'System') AS account_name
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 6"
);
$recentActivities = $recentStmt->fetchAll();

require __DIR__ . "/_header.php";
?>
<section class="stats-grid" aria-label="System totals">
    <article class="stat-card"><span class="label">Total users</span><strong><?= number_format($metrics["users"]) ?></strong><small>All registered accounts</small></article>
    <article class="stat-card"><span class="label">Students</span><strong><?= number_format($metrics["students"]) ?></strong><small>Student accounts</small></article>
    <article class="stat-card"><span class="label">Administrators</span><strong><?= number_format($metrics["admins"]) ?></strong><small>Admin and SuperAdmin accounts</small></article>
    <article class="stat-card"><span class="label">Activity today</span><strong><?= number_format($metrics["activities"]) ?></strong><small>Actions recorded today</small></article>
</section>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-heading"><div><h2>Quick actions</h2><p>Manage users and account access</p></div></div>
        <div class="quick-links">
            <a class="quick-link" href="users.php">Manage students and admins</a>
            <a class="quick-link" href="reset_password.php">Reset account passwords</a>
            <a class="quick-link" href="../register.php?type=admin">Create administrator</a>
            <a class="quick-link" href="activity_logs.php">Review activity logs</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2>Recent activity</h2><p>Latest account and system actions</p></div><a href="activity_logs.php">View all</a></div>
        <?php if ($recentActivities): ?>
            <div class="activity-list">
                <?php foreach ($recentActivities as $activity): ?>
                    <article class="activity-item">
                        <div><strong><?= superadmin_e($activity["account_name"]) ?> · <?= superadmin_e($activity["action"]) ?></strong><p><?= superadmin_e($activity["details"]) ?></p></div>
                        <time datetime="<?= superadmin_e(date(DATE_ATOM, strtotime($activity["created_at"]))) ?>"><?= superadmin_e(date("M j, g:i a", strtotime($activity["created_at"]))) ?></time>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No system activity has been recorded yet.</div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . "/_footer.php"; ?>

<?php
require __DIR__ . "/_bootstrap.php";

$pageTitle = "Activity Logs";
$pageSubtitle = "Review and filter recorded system activity";
$activePage = "activity";

function superadmin_csv_cell($value): string
{
    $value = (string) $value;
    return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value;
}

$search = trim((string) ($_GET["q"] ?? ""));
$actionFilter = trim((string) ($_GET["action"] ?? ""));
$roleFilter = (string) ($_GET["role"] ?? "");
$fromDate = trim((string) ($_GET["from"] ?? ""));
$toDate = trim((string) ($_GET["to"] ?? ""));
$message = "";
$validRoles = ["superadmin", "admin", "student", "system"];
if (!in_array($roleFilter, $validRoles, true)) {
    $roleFilter = "";
}

$actionOptions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
if ($actionFilter !== "" && !in_array($actionFilter, $actionOptions, true)) {
    $actionFilter = "";
}
$filters = [];
$params = [];
if ($search !== "") {
    $filters[] = "(u.name LIKE ? OR al.action LIKE ? OR al.details LIKE ? OR CAST(al.user_id AS CHAR) LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term, $term);
}
if ($actionFilter !== "") {
    $filters[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($roleFilter === "system") {
    $filters[] = "al.user_id IS NULL";
} elseif ($roleFilter !== "") {
    $filters[] = "u.role = ?";
    $params[] = $roleFilter;
}
if ($fromDate !== "") {
    if (superadmin_valid_date($fromDate)) {
        $filters[] = "al.created_at >= ?";
        $params[] = $fromDate . " 00:00:00";
    } else {
        $message = "The start date is invalid.";
        $fromDate = "";
    }
}
if ($toDate !== "") {
    if (superadmin_valid_date($toDate)) {
        $filters[] = "al.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $toDate;
    } else {
        $message = "The end date is invalid.";
        $toDate = "";
    }
}
$whereSql = $filters ? " WHERE " . implode(" AND ", $filters) : "";

$exportMode = (string) ($_GET["export"] ?? "");
if ($exportMode === "filtered" || $exportMode === "all") {
    $exportWhere = $exportMode === "filtered" ? $whereSql : "";
    $exportParams = $exportMode === "filtered" ? $params : [];
    $exportStmt = $pdo->prepare(
        "SELECT al.id, al.user_id, al.action, al.details, al.created_at,
                COALESCE(u.name, 'System') AS account_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id{$exportWhere}
         ORDER BY al.created_at DESC, al.id DESC"
    );
    $exportStmt->execute($exportParams);
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=hm-activity-log-" . date("Y-m-d") . ".csv");
    $output = fopen("php://output", "w");
    if ($output === false) {
        throw new RuntimeException("Could not open the CSV output stream.");
    }
    fputcsv($output, ["When", "Account", "Action", "User ID", "Details"]);
    while ($row = $exportStmt->fetch()) {
        fputcsv($output, array_map("superadmin_csv_cell", [
            $row["created_at"],
            $row["account_name"],
            $row["action"],
            $row["user_id"] ?? "System",
            $row["details"],
        ]));
    }
    fclose($output);
    exit;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id{$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageNumber = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($pageNumber - 1) * $perPage;
$listStmt = $pdo->prepare(
    "SELECT al.id, al.user_id, al.action, al.details, al.created_at,
            COALESCE(u.name, 'System') AS account_name, COALESCE(u.role, 'system') AS account_role
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id{$whereSql}
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$activities = $listStmt->fetchAll();
$firstRow = $totalRows === 0 ? 0 : $offset + 1;
$lastRow = min($offset + $perPage, $totalRows);

require __DIR__ . "/_header.php";
?>
<?php if ($message !== ""): ?><div class="notice error" role="status"><?= superadmin_e($message) ?></div><?php endif; ?>
<section class="panel filter-panel">
    <div class="panel-heading">
        <div><h2>System activity</h2><p><?= number_format($totalRows) ?> matching entr<?= $totalRows === 1 ? "y" : "ies" ?></p></div>
        <div class="form-actions"><a class="button secondary" href="activity_logs.php?export=filtered&amp;<?= superadmin_e(http_build_query(array_diff_key($_GET, ["page" => true, "export" => true]))) ?>">Export filtered</a><a class="button secondary" href="activity_logs.php?export=all">Export all</a></div>
    </div>
    <form class="filters" method="get" action="activity_logs.php">
        <div class="field search"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= superadmin_e($search) ?>" placeholder="Account, action, details or user ID"></div>
        <div class="field"><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option><?php foreach ($actionOptions as $option): ?><option value="<?= superadmin_e($option) ?>" <?= $actionFilter === $option ? "selected" : "" ?>><?= superadmin_e($option) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="role">Account role</label><select id="role" name="role"><option value="">All roles</option><option value="superadmin" <?= $roleFilter === "superadmin" ? "selected" : "" ?>>SuperAdmin</option><option value="admin" <?= $roleFilter === "admin" ? "selected" : "" ?>>Admin</option><option value="student" <?= $roleFilter === "student" ? "selected" : "" ?>>Student</option><option value="system" <?= $roleFilter === "system" ? "selected" : "" ?>>System</option></select></div>
        <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= superadmin_e($fromDate) ?>"></div>
        <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= superadmin_e($toDate) ?>"></div>
        <button class="button" type="submit">Apply filters</button>
        <a class="button secondary" href="activity_logs.php">Clear</a>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date and time</th><th>Account</th><th>Role</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$activities): ?>
                <tr><td class="empty-table" colspan="5">No activity matches these filters.</td></tr>
            <?php else: foreach ($activities as $activity): ?>
                <tr>
                    <td><?= superadmin_e(date("M j, Y", strtotime($activity["created_at"]))) ?><span class="subtext"><?= superadmin_e(date("g:i:s a", strtotime($activity["created_at"]))) ?></span></td>
                    <td><span class="user-name"><?= superadmin_e($activity["account_name"]) ?></span><?php if ($activity["user_id"] !== null): ?><span class="subtext">User #<?= (int) $activity["user_id"] ?></span><?php endif; ?></td>
                    <td><span class="role-badge <?= $activity["account_role"] === "superadmin" ? "superadmin" : "" ?>"><?= superadmin_e($activity["account_role"]) ?></span></td>
                    <td><?= superadmin_e($activity["action"]) ?></td>
                    <td style="white-space:normal;min-width:220px;max-width:420px;line-height:1.5"><?= superadmin_e($activity["details"]) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="pagination-row">
        <span>Showing <?= number_format($firstRow) ?>–<?= number_format($lastRow) ?> of <?= number_format($totalRows) ?></span>
        <nav class="pages" aria-label="Activity log table pages">
            <a class="<?= $pageNumber <= 1 ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("activity_logs.php", max(1, $pageNumber - 1))) ?>" aria-label="Previous page">‹</a>
            <?php for ($page = 1; $page <= $totalPages; $page++): ?><a class="<?= $page === $pageNumber ? "current" : "" ?>" href="<?= superadmin_e(superadmin_page_url("activity_logs.php", $page)) ?>" <?= $page === $pageNumber ? 'aria-current="page"' : "" ?>><?= $page ?></a><?php endfor; ?>
            <a class="<?= $pageNumber >= $totalPages ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("activity_logs.php", min($totalPages, $pageNumber + 1))) ?>" aria-label="Next page">›</a>
        </nav>
    </div>
</section>
<?php require __DIR__ . "/_footer.php"; ?>

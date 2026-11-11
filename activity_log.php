<?php
session_start();

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["user_role"] ?? "", ["admin", "superadmin"], true)) {
    header("Location: index.php");
    exit;
}

require_once "db.php";

$adminName = $_SESSION["user_name"] ?? "Administrator";
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }
function valid_date_filter(string $date): bool {
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}
function csv_cell($value): string {
    $value = (string) $value;
    return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value;
}

$search = trim((string) ($_GET["q"] ?? ""));
$actionFilter = trim((string) ($_GET["action"] ?? ""));
$fromDate = (string) ($_GET["from"] ?? "");
$toDate = (string) ($_GET["to"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$exportMode = (string) ($_GET["export"] ?? "");
if (!valid_date_filter($fromDate)) { $fromDate = ""; }
if (!valid_date_filter($toDate)) { $toDate = ""; }

$actionOptions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
if ($actionFilter !== "" && !in_array($actionFilter, $actionOptions, true)) { $actionFilter = ""; }
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
if ($fromDate !== "") {
    $filters[] = "al.created_at >= ?";
    $params[] = $fromDate . " 00:00:00";
}
if ($toDate !== "") {
    $filters[] = "al.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $toDate . " 00:00:00";
}
$whereSql = $filters ? " WHERE " . implode(" AND ", $filters) : "";


if ($exportMode === "all" || $exportMode === "filtered") {
    $exportWhere = $exportMode === "filtered" ? $whereSql : "";
    $exportParams = $exportMode === "filtered" ? $params : [];
    $stmt = $pdo->prepare("SELECT al.id, al.user_id, al.action, al.details, al.created_at, COALESCE(u.name, 'System') AS account_name
        FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id{$exportWhere}
        ORDER BY al.created_at DESC, al.id DESC");
    $stmt->execute($exportParams);
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=hm-activity-log-" . date("Y-m-d") . ".csv");
    $output = fopen("php://output", "w");
    fputcsv($output, ["When", "Account", "Action", "Record", "Details"]);
    while ($row = $stmt->fetch()) {
        $record = $row["user_id"] === null ? "—" : "user " . $row["user_id"];
        fputcsv($output, array_map("csv_cell", [$row["created_at"], $row["account_name"], $row["action"], $record, $row["details"]]));
    }
    fclose($output);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id{$whereSql}");
$countStmt->execute($params);
$totalEntries = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalEntries / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$listStmt = $pdo->prepare("SELECT al.id, al.user_id, al.action, al.details, al.created_at, COALESCE(u.name, 'System') AS account_name
    FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id{$whereSql}
    ORDER BY al.created_at DESC, al.id DESC LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$activities = $listStmt->fetchAll();
$firstEntry = $totalEntries === 0 ? 0 : $offset + 1;
$lastEntry = min($offset + $perPage, $totalEntries);

function page_url(int $targetPage): string {
    $query = $_GET;
    unset($query["export"]);
    $query["page"] = $targetPage;
    return "activity_log.php?" . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log | HM System</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#1e4620; --primary-dark:#153216; --accent-light:#e8f2e9; --bg-main:#f4f7f4; --text-main:#1f2923; --text-muted:#5e6c62; --border-color:#d1ded2; --white:#fff; --shadow:0 6px 20px rgba(30,70,32,.04); }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--bg-main); color:var(--text-main); -webkit-font-smoothing:antialiased; }
        a { color:inherit; text-decoration:none; }
        .layout { min-height:100vh; display:flex; }
        .sidebar { width:260px; flex-shrink:0; background:var(--primary-dark); color:#fff; padding:30px 20px; display:flex; flex-direction:column; position:sticky; top:0; height:100vh; }
        .brand { display:flex; align-items:center; gap:14px; padding-bottom:24px; border-bottom:1px solid rgba(255,255,255,.12); }
        .brand img { width:42px; height:42px; object-fit:contain; border-radius:10px; background:rgba(255,255,255,.1); padding:2px; }
        .brand h2 { font-size:16px; letter-spacing:.5px; }.brand p { font-size:11px; color:#a3c1a5; margin-top:3px; }
        .nav-label { color:#8fae92; font-size:10px; font-weight:700; letter-spacing:1.2px; margin:32px 10px 10px; }
        .nav-link { display:flex; padding:12px 14px; margin-bottom:6px; border-radius:10px; color:#d4e3d5; font-size:13px; font-weight:600; }
        .nav-link:hover,.nav-link.active { background:rgba(255,255,255,.12); color:#fff; }
        .sidebar-bottom { margin-top:auto; padding-top:20px; }.admin-mini { display:flex; align-items:center; gap:12px; padding:14px 8px; border-top:1px solid rgba(255,255,255,.12); margin-bottom:12px; }
        .avatar { width:38px; height:38px; display:grid; place-items:center; border-radius:50%; background:var(--accent-light); color:var(--primary); font-weight:700; }
        .admin-mini strong { font-size:13px; }.admin-mini small { display:block; font-size:11px; color:#a3c1a5; margin-top:2px; }
        .logout-link { display:block; text-align:center; border:1px solid rgba(255,255,255,.2); border-radius:10px; padding:11px; font-size:13px; font-weight:600; }
        .main { flex:1; min-width:0; }.topbar { min-height:80px; background:#fff; border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; padding:0 32px; }
        .topbar h1 { font-size:20px; color:var(--primary); }.topbar p { font-size:12px; color:var(--text-muted); margin-top:3px; }
        .topbar-action,.button { display:inline-flex; align-items:center; justify-content:center; min-height:42px; border:1px solid #c8d9ca; border-radius:9px; padding:0 15px; background:#fff; color:var(--primary); font-size:12px; font-weight:600; cursor:pointer; }
        .topbar-action:hover,.button:hover { background:#f3f8f3; }
        .content { padding:28px 32px; max-width:1800px; margin:0 auto; }.panel { background:#fff; border:1px solid var(--border-color); border-radius:14px; overflow:hidden; box-shadow:var(--shadow); }
        .panel-heading { display:flex; justify-content:space-between; align-items:center; padding:20px 24px 12px; }.panel-heading h2 { font-size:15px; color:var(--text-main); }.entry-count { color:var(--text-muted); font-size:13px; font-weight:600; }
        .filters { display:flex; align-items:flex-end; flex-wrap:wrap; gap:10px; padding:12px 24px 18px; }
        .field { display:flex; flex-direction:column; gap:7px; min-width:150px; }.field.search { width:216px; }.field.action { width:265px; }.field.date { width:152px; }
        .field label { color:#35536a; font-size:12px; font-weight:600; }.field input,.field select { height:42px; width:100%; border:1px solid #c8d9e4; border-radius:7px; padding:0 12px; background:#fff; color:#213e53; font:inherit; font-size:13px; }
        .table-wrap { overflow-x:auto; padding:0 24px; } table { width:100%; min-width:760px; border-collapse:collapse; } th { background:#f2f6f7; color:#547185; font-size:11px; letter-spacing:.4px; text-align:left; padding:13px 11px; }
        td { color:#35536a; font-size:13px; padding:14px 11px; border-bottom:1px solid #eceee9; vertical-align:top; } tbody tr:last-child td { border-bottom:0; } .when { white-space:nowrap; min-width:160px; }.account { min-width:180px; }.record { white-space:nowrap; }.details { min-width:190px; color:var(--text-muted); }
        .pagination-row { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:16px 24px 20px; color:#607b8d; font-size:12px; font-weight:600; }.pages { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }.page-btn { min-width:34px; height:32px; display:inline-flex; justify-content:center; align-items:center; padding:0 9px; border:1px solid #c8d9e4; border-radius:6px; color:#21425b; background:#fff; }.page-btn.current { background:#1e6ba8; color:#fff; border-color:#1e6ba8; }.page-btn.disabled { color:#a9bbc6; pointer-events:none; }.empty { padding:35px 24px; color:var(--text-muted); font-size:13px; }
        @media(max-width:900px) { .sidebar { width:220px; }.content { padding:20px 16px; }.filters { padding-left:16px; padding-right:16px; }.table-wrap { padding:0 16px; }.panel-heading,.pagination-row { padding-left:16px; padding-right:16px; } }
        @media(max-width:620px) { .layout { display:block; }.sidebar { width:100%; height:auto; position:static; padding:16px; }.brand { padding-bottom:12px; }.nav-label { margin:16px 10px 8px; }.sidebar-bottom { display:none; }.topbar { padding:14px 16px; }.content { padding:14px 10px; }.field.search,.field.action,.field.date { width:100%; }.filters .button { flex:1; }.pagination-row { align-items:flex-start; flex-direction:column; }.pages { max-width:100%; } }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><img src="images/ISU.png" alt="ISU Logo"><div><h2>HM SYSTEM</h2><p>Admin Portal</p></div></div>
        <?php require __DIR__ . "/admin_nav.php"; ?>
<div class="sidebar-bottom"><div class="admin-mini"><div class="avatar"><?= e(strtoupper(substr($adminName, 0, 1))) ?></div><div><strong><?= e($adminName) ?></strong><small>Administrator</small></div></div><a class="logout-link" href="logout.php">Logout</a></div>
    </aside>
    <main class="main">
        <header class="topbar"><div><h1>Activity Log</h1><p>Account and system actions</p></div><a class="topbar-action" href="activity_log.php?export=all">Export CSV</a></header>
        <div class="content">
            <section class="panel">
                <div class="panel-heading"><h2>Activity · <?= number_format($totalEntries) ?> entries</h2><span class="entry-count">HM System</span></div>
                <form class="filters" method="GET" action="activity_log.php">
                    <div class="field search"><label for="q">Search account or action</label><input id="q" type="search" name="q" value="<?= e($search) ?>"></div>
                    <div class="field action"><label for="action">Activity</label><select id="action" name="action"><option value="">All activity</option><?php foreach ($actionOptions as $option): ?><option value="<?= e($option) ?>" <?= $actionFilter === $option ? "selected" : "" ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
                    <div class="field date"><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($fromDate) ?>"></div>
                    <div class="field date"><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($toDate) ?>"></div>
                    <button class="button" type="submit">Filter activity</button>
                    <button class="button" type="submit" name="export" value="filtered">Export filtered CSV</button>
                </form>
                <div class="table-wrap"><table>
                    <thead><tr><th>WHEN</th><th>ACCOUNT</th><th>ACTION</th><th>RECORD</th><th>DETAILS</th></tr></thead>
                    <tbody>
                    <?php if (empty($activities)): ?><tr><td colspan="5" class="empty">No activity matches these filters.</td></tr>
                    <?php else: foreach ($activities as $activity): ?>
                        <tr><td class="when"><?= e(date("M j, Y g:i a", strtotime($activity["created_at"]))) ?></td><td class="account"><?= e($activity["account_name"]) ?></td><td><?= e($activity["action"]) ?></td><td class="record"><?= $activity["user_id"] === null ? "—" : "user " . (int) $activity["user_id"] ?></td><td class="details"><?= e($activity["details"]) ?></td></tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
                <div class="pagination-row"><span>Showing <?= number_format($firstEntry) ?>–<?= number_format($lastEntry) ?> of <?= number_format($totalEntries) ?></span>
                    <nav class="pages" aria-label="Activity pages">
                        <a class="page-btn <?= $page <= 1 ? "disabled" : "" ?>" href="<?= e(page_url(1)) ?>">« First</a>
                        <a class="page-btn <?= $page <= 1 ? "disabled" : "" ?>" href="<?= e(page_url(max(1, $page - 1))) ?>">‹ Back</a>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?><a class="page-btn <?= $p === $page ? "current" : "" ?>" href="<?= e(page_url($p)) ?>" <?= $p === $page ? "aria-current=\"page\"" : "" ?>><?= $p ?></a><?php endfor; ?>
                        <a class="page-btn <?= $page >= $totalPages ? "disabled" : "" ?>" href="<?= e(page_url(min($totalPages, $page + 1))) ?>">Next ›</a>
                        <a class="page-btn <?= $page >= $totalPages ? "disabled" : "" ?>" href="<?= e(page_url($totalPages)) ?>">Last »</a>
                    </nav>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
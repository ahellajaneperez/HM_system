<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["user_role"] ?? "") !== "student"
) {
    header("Location: index.php");
    exit;
}

require_once "db.php";

$userId = (int) $_SESSION["user_id"];
$studentName = $_SESSION["user_name"] ?? "Student";

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (!isset($_SESSION['borrow_cart'])) {
    $_SESSION['borrow_cart'] = [];
}
$cartCount = count($_SESSION['borrow_cart']);

$totalRequests = 0;
$pendingRequests = 0;
$approvedRequests = 0;
$recentBorrowings = [];
$historySearch = trim((string) ($_GET["q"] ?? ""));
$statusFilter = (string) ($_GET["status"] ?? "all");
if (!in_array($statusFilter, ["all", "pending", "approved", "rejected", "returned"], true)) { $statusFilter = "all"; }
$historyFrom = (string) ($_GET["from"] ?? "");
$historyTo = (string) ($_GET["to"] ?? "");
$validHistoryDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed !== false && $parsed->format("Y-m-d") === $date;
};
if (!$validHistoryDate($historyFrom)) { $historyFrom = ""; }
if (!$validHistoryDate($historyTo)) { $historyTo = ""; }
$historyWhere = ["br.user_id = ?"];
$historyParams = [$userId];
if ($historySearch !== "") { $historyWhere[] = "(e.name LIKE ? OR br.purpose LIKE ?)"; $historyParams[] = "%" . $historySearch . "%"; $historyParams[] = "%" . $historySearch . "%"; }
if ($statusFilter !== "all") { $historyWhere[] = "br.status = ?"; $historyParams[] = $statusFilter; }
if ($historyFrom !== "") { $historyWhere[] = "br.created_at >= ?"; $historyParams[] = $historyFrom . " 00:00:00"; }
if ($historyTo !== "") { $historyWhere[] = "br.created_at < DATE_ADD(?, INTERVAL 1 DAY)"; $historyParams[] = $historyTo . " 00:00:00"; }
$historyWhereSql = implode(" AND ", $historyWhere);
$historyTotal = 0;
$historyPerPage = 10;
$historyPage = max(1, (int) ($_GET["page"] ?? 1));
$historyTotalPages = 1;
$historyFirst = 0;
$historyLast = 0;
function student_history_page_url(int $targetPage): string {
    $query = $_GET;
    unset($query["page"], $query["submitted"]);
    $query["page"] = $targetPage;
    return "student_dashboard.php?" . http_build_query($query);
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalRequests = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $pendingRequests = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$userId]);
    $approvedRequests = (int) $stmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing_requests br INNER JOIN equipment e ON e.id = br.equipment_id WHERE {$historyWhereSql}");
    $countStmt->execute($historyParams);
    $historyTotal = (int) $countStmt->fetchColumn();
    $historyTotalPages = max(1, (int) ceil($historyTotal / $historyPerPage));
    $historyPage = min($historyPage, $historyTotalPages);
    $historyOffset = ($historyPage - 1) * $historyPerPage;

    $stmt = $pdo->prepare("SELECT br.id, br.quantity, br.borrow_date, br.return_date, br.purpose, br.status, br.created_at, e.name AS equipment_name
        FROM borrowing_requests br INNER JOIN equipment e ON e.id = br.equipment_id
        WHERE {$historyWhereSql} ORDER BY br.id DESC LIMIT {$historyPerPage} OFFSET {$historyOffset}");
    $stmt->execute($historyParams);
    $recentBorrowings = $stmt->fetchAll();
    $historyFirst = $historyTotal === 0 ? 0 : $historyOffset + 1;
    $historyLast = min($historyOffset + $historyPerPage, $historyTotal);
} catch (PDOException $ex) {
    error_log("Student dashboard borrowing query error: " . $ex->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard | HM System</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/table-pagination.css">
<style>
:root {
    --primary: #1e4620;
    --primary-dark: #153216;
    --accent-light: #e8f2e9;
    --bg-main: #f4f7f4;
    --text-main: #1f2923;
    --text-muted: #5e6c62;
    --border-color: #d1ded2;
    --white: #ffffff;
    --shadow: 0 6px 20px rgba(30, 70, 32, 0.04);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { 
    font-family: 'Plus Jakarta Sans', sans-serif; 
    color: var(--text-main); 
    background: var(--bg-main); 
    -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }
.layout { min-height: 100vh; display: flex; }

.sidebar {
    width: 260px;
    flex-shrink: 0;
    background: var(--primary-dark);
    color: var(--white);
    padding: 30px 20px;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
}
.brand { display: flex; align-items: center; gap: 14px; padding: 0 4px 24px; border-bottom: 1px solid rgba(255, 255, 255, 0.12); }
.brand-icon-img { width: 42px; height: 42px; object-fit: cover; border-radius: 50%; background: rgba(255, 255, 255, 0.1); padding: 2px; }
.brand h2 { font-size: 16px; font-weight: 700; }
.brand p { color: #a3c1a5; font-size: 11px; margin-top: 3px; font-weight: 500; }

.nav-label { color: #8fae92; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; margin: 32px 10px 10px; }
.nav-link { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; margin-bottom: 6px; border-radius: 10px; color: #d4e3d5; font-size: 13px; font-weight: 600; transition: 0.2s; }
.nav-link:hover, .nav-link.active { background: rgba(255, 255, 255, 0.12); color: var(--white); }
.cart-badge-count { background: var(--accent-light); color: var(--primary); font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 700; }

.sidebar-bottom { margin-top: auto; padding-top: 20px; }
.profile { padding: 14px 8px; border-top: 1px solid rgba(255, 255, 255, 0.12); display: flex; align-items: center; gap: 12px; }
.avatar { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; background: var(--accent-light); color: var(--primary); font-weight: 700; flex-shrink: 0; font-size: 14px; }
.profile strong { font-size: 13px; font-weight: 600; }
.profile small { display: block; font-size: 11px; color: #a3c1a5; margin-top: 2px; font-weight: 500; }

.logout { display: block; text-align: center; color: var(--white); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 11px; font-size: 13px; font-weight: 600; margin-top: 12px; transition: 0.2s; }
.logout:hover { background: rgba(255, 255, 255, 0.1); }

.main { flex: 1; min-width: 0; }
.topbar { height: 80px; background: var(--white); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 40px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02); }
.topbar h1 { font-size: 20px; font-weight: 700; color: var(--primary); }
.topbar p { font-size: 12px; color: var(--text-muted); margin-top: 3px; font-weight: 500; }

.content { max-width: 1350px; padding: 35px 40px; margin: auto; }

.welcome { display: flex; justify-content: space-between; align-items: center; background: var(--white); padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); margin-bottom: 35px; box-shadow: var(--shadow); }
.welcome h2 { font-size: 20px; color: var(--primary); font-weight: 700; margin-bottom: 6px; }
.welcome p { font-size: 13px; color: var(--text-muted); font-weight: 500; }

.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 25px; margin-bottom: 35px; }
.stat-card { background: var(--white); padding: 25px; border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--shadow); }
.stat-card h3 { font-size: 24px; color: var(--primary); font-weight: 700; margin-bottom: 4px; }
.stat-card small { font-size: 12px; color: var(--text-muted); font-weight: 600; }

.panel { background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; margin-bottom: 35px; box-shadow: var(--shadow); }
.panel-header { padding: 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
.panel-header h3 { font-size: 18px; color: var(--primary); font-weight: 700; }
.table-wrap { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 700px; }
th { background: #fafbf9; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; text-align: left; padding: 16px 25px; }
td { padding: 18px 25px; border-top: 1px solid var(--border-color); font-size: 13px; vertical-align: middle; font-weight: 500; }
.pill { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.pill.pending { background: #fff3d8; color: #885b00; }
.pill.approved { background: #e5f4e7; color: #216b30; }
.pill.rejected { background: #fde8e7; color: #a22c2c; }

.footer { padding: 30px; text-align: center; background: var(--white); border-top: 1px solid var(--border-color); margin-top: 50px; display: flex; flex-direction: column; align-items: center; gap: 15px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02); }
.footer-logos { display: flex; align-items: center; justify-content: center; gap: 20px; }
.footer-logos img { height: 50px; width: auto; object-fit: contain; }
.footer-text { font-size: 12px; color: var(--text-muted); font-weight: 500; }
.history-filters { display:flex; align-items:flex-end; flex-wrap:wrap; gap:10px; padding:18px 24px; border-bottom:1px solid var(--border-color); }
.history-filter { display:flex; flex-direction:column; gap:6px; min-width:140px; flex:1; }
.history-filter.search { min-width:200px; flex:1.5; }
.history-filter label { font-size:11px; font-weight:700; color:var(--text-muted); }
.history-filter input,.history-filter select { height:38px; padding:0 10px; border:1px solid var(--border-color); border-radius:8px; background:#fff; font:inherit; font-size:12px; }
.history-filter button { height:38px; padding:0 14px; border:0; border-radius:8px; background:var(--primary); color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
@media(max-width:700px) { .history-filters { padding:15px; } .history-filter,.history-filter.search { min-width:calc(50% - 6px); } }
.history-pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 24px; color:var(--text-muted); font-size:12px; }
.history-pages { display:flex; gap:5px; align-items:center; }.history-pages a { border:1px solid var(--border-color); border-radius:6px; padding:7px 9px; color:var(--primary); font-weight:600; }.history-pages a.current { background:var(--primary); color:#fff; }.history-pages a.disabled { color:#aab5ab; pointer-events:none; }
@media(max-width:700px) { .history-pagination { padding:14px 15px; flex-direction:column; align-items:flex-start; } }
</style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <img src="images/ISU.png" alt="ISU Logo" class="brand-icon-img">
            <div>
                <h2>HM SYSTEM</h2>
                <p>Student Portal</p>
            </div>
        </div>

        <div class="nav-label">STUDENT MENU</div>

        <a class="nav-link active" href="student_dashboard.php">Dashboard</a>
        <a class="nav-link" href="borrow_equipment.php">Borrow Equipment</a>
        <a class="nav-link" href="cart.php">
            <span>View Item</span>
            <?php if ($cartCount > 0): ?>
                <span class="cart-badge-count"><?= $cartCount ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link" href="my_borrowings.php">My Borrowing Requests</a>

        <div class="sidebar-bottom">
            <div class="profile">
                <div class="avatar"><?= e(strtoupper(substr($studentName, 0, 1))) ?></div>
                <div>
                    <strong><?= e($studentName) ?></strong>
                    <small>Student Account</small>
                </div>
            </div>
            <a class="logout" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1>Student Dashboard</h1>
                <p>Hospitality Management System</p>
            </div>
            <div style="background:var(--accent-light); padding:8px 14px; border-radius:20px; font-weight:700; color:var(--primary); font-size: 12px;">Active Student</div>
        </header>

        <div class="content">
            <section class="welcome">
                <div>
                    <h2>Welcome back, <?= e($studentName) ?>!</h2>
                    <p>Track your borrowed equipment and requests.</p>
                </div>
                <div style="color:var(--text-muted); font-weight:600; font-size: 13px;"><?= date("F j, Y") ?></div>
            </section>

            <section class="stats">
                <article class="stat-card">
                    <h3><?= number_format($totalRequests) ?></h3>
                    <small>Total Requests</small>
                </article>
                <article class="stat-card">
                    <h3><?= number_format($pendingRequests) ?></h3>
                    <small>Pending Requests</small>
                </article>
                <article class="stat-card">
                    <h3><?= number_format($approvedRequests) ?></h3>
                    <small>Approved Requests</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h3>My Borrowing Requests</h3><p>Search and filter your requests</p></div><a href="my_borrowings.php" style="color:var(--primary);font-size:12px;font-weight:700;">View all details →</a></div>
                <form class="history-filters" method="GET" action="student_dashboard.php">
                    <div class="history-filter search"><label for="history-search">Search equipment or purpose</label><input id="history-search" type="search" name="q" value="<?= e($historySearch) ?>" placeholder="Enter a keyword"></div>
                    <div class="history-filter"><label for="history-status">Status</label><select id="history-status" name="status"><option value="all" <?= $statusFilter === "all" ? "selected" : "" ?>>All statuses</option><option value="pending" <?= $statusFilter === "pending" ? "selected" : "" ?>>Pending</option><option value="approved" <?= $statusFilter === "approved" ? "selected" : "" ?>>Approved</option><option value="rejected" <?= $statusFilter === "rejected" ? "selected" : "" ?>>Rejected</option><option value="returned" <?= $statusFilter === "returned" ? "selected" : "" ?>>Returned</option></select></div>
                    <div class="history-filter"><label for="history-from">Submitted from</label><input id="history-from" type="date" name="from" value="<?= e($historyFrom) ?>"></div>
                    <div class="history-filter"><label for="history-to">Submitted to</label><input id="history-to" type="date" name="to" value="<?= e($historyTo) ?>"></div>
                    <button type="submit">Apply filters</button><a href="student_dashboard.php" style="height:38px;display:inline-flex;align-items:center;padding:0 12px;border:1px solid var(--border-color);border-radius:8px;color:var(--primary);font-size:12px;font-weight:700;">Clear</a>
                </form>
                <div class="table-wrap"><table data-pagination="false">
                    <thead><tr><th>Equipment</th><th>Qty</th><th>Period</th><th>Purpose</th><th>Status</th><th>Submitted</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentBorrowings)): ?><tr><td colspan="6" style="padding:40px;text-align:center;color:var(--text-muted);">No borrowing requests match these filters.</td></tr>
                    <?php else: foreach ($recentBorrowings as $row): ?><tr><td><strong><?= e($row["equipment_name"]) ?></strong></td><td><?= (int) $row["quantity"] ?></td><td><?= e(date("M j, Y", strtotime($row["borrow_date"]))) ?> to <?= e(date("M j, Y", strtotime($row["return_date"]))) ?></td><td><?= e($row["purpose"]) ?></td><td><span class="pill <?= e($row["status"]) ?>"><?= e($row["status"]) ?></span></td><td><?= e(date("M j, Y", strtotime($row["created_at"]))) ?></td></tr><?php endforeach; endif; ?>
                    </tbody>
                </table></div>
                <div class="history-pagination"><span>Showing <?= number_format($historyFirst) ?>–<?= number_format($historyLast) ?> of <?= number_format($historyTotal) ?> requests</span><nav class="history-pages" aria-label="Borrowing request pages">
                    <a class="<?= $historyPage <= 1 ? "disabled" : "" ?>" href="<?= e(student_history_page_url(max(1, $historyPage - 1))) ?>">‹ Previous</a>
                    <?php for ($pageNo = max(1, $historyPage - 2); $pageNo <= min($historyTotalPages, $historyPage + 2); $pageNo++): ?><a class="<?= $pageNo === $historyPage ? "current" : "" ?>" href="<?= e(student_history_page_url($pageNo)) ?>" <?= $pageNo === $historyPage ? "aria-current=\"page\"" : "" ?>><?= $pageNo ?></a><?php endfor; ?>
                    <a class="<?= $historyPage >= $historyTotalPages ? "disabled" : "" ?>" href="<?= e(student_history_page_url(min($historyTotalPages, $historyPage + 1))) ?>">Next ›</a>
                </nav></div>            </section>

            <div class="footer">
                <div class="footer-logos">
                    <img src="images/ISU.png" alt="ISU Logo">
                    <img src="images/ccsict.jpg" alt="CCSICT Logo">
                    <img src="images/Hm.jpg" alt="HM Logo">
                </div>
                <div class="footer-text">
                    &copy; <?= date("Y") ?> Hospitality Management System. All rights reserved.
                </div>
            </div>
        </div>
    </main>
</div>


</body>
</html>
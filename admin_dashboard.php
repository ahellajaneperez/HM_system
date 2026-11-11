<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["user_role"] ?? "") !== "admin"
) {
    header("Location: index.php");
    exit;
}

require_once "db.php";

$adminName = $_SESSION["user_name"] ?? "Administrator";


function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Process request decisions and prevent approved quantities from exceeding inventory.
$actionMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["request_id"], $_POST["action"])) {
    $requestId = (int) $_POST["request_id"];
    $action = (string) $_POST["action"];
    if (in_array($action, ["approved", "rejected"], true)) {
        try {
            $pdo->beginTransaction();
            $detailStmt = $pdo->prepare("SELECT br.status, br.equipment_id, br.quantity, e.name AS equipment_name, u.name AS student_name FROM borrowing_requests br INNER JOIN users u ON u.id = br.user_id INNER JOIN equipment e ON e.id = br.equipment_id WHERE br.id = ? FOR UPDATE");
            $detailStmt->execute([$requestId]);
            $requestDetails = $detailStmt->fetch();
            if (!$requestDetails) { throw new RuntimeException("Borrowing request was not found."); }
            if ($requestDetails["status"] !== "pending") { throw new RuntimeException("This request has already been processed."); }

            if ($action === "approved") {
                $stockLock = $pdo->prepare("SELECT total_quantity FROM equipment WHERE id = ? FOR UPDATE");
                $stockLock->execute([(int) $requestDetails["equipment_id"]]);
                $totalStock = (int) $stockLock->fetchColumn();
                $committedStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM borrowing_requests WHERE equipment_id = ? AND status = 'approved'");
                $committedStmt->execute([(int) $requestDetails["equipment_id"]]);
                $committedStock = (int) $committedStmt->fetchColumn();
                $availableStock = max(0, $totalStock - $committedStock);
                if ((int) $requestDetails["quantity"] > $availableStock) {
                    throw new RuntimeException("Not enough stock to approve this request. Available: " . $availableStock . ".");
                }
            }

            $updateStmt = $pdo->prepare("UPDATE borrowing_requests SET status = ? WHERE id = ? AND status = 'pending'");
            $updateStmt->execute([$action, $requestId]);
            if ($updateStmt->rowCount() !== 1) { throw new RuntimeException("This request has already been processed."); }
            $pdo->commit();
            log_activity($pdo, (int) $_SESSION["user_id"], "Request " . $action, $requestDetails["equipment_name"] . " requested by " . $requestDetails["student_name"] . " · quantity " . (int) $requestDetails["quantity"]);
            $actionMessage = "Borrowing request has been " . $action . " successfully.";
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $actionMessage = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Admin request decision error: " . $ex->getMessage());
            $actionMessage = "Error updating request. Please try again.";
        }
    }
}
$totalUsers = 0;
$totalStudents = 0;
$totalAdmins = 0;
$recentUsers = [];
$borrowRequests = [];
$pendingRequests = [];
$pendingCount = 0;
$approvedCount = 0;
$requestsByStudent = [];
$dbError = false;
$userSearch = trim((string) ($_GET["user_q"] ?? ""));
$userRoleFilter = (string) ($_GET["user_role"] ?? "student");
if (!in_array($userRoleFilter, ["", "student", "admin"], true)) { $userRoleFilter = ""; }
$userFrom = (string) ($_GET["user_from"] ?? "");
$userTo = (string) ($_GET["user_to"] ?? "");
$dateIsValid = static function (string $date): bool {
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed !== false && $parsed->format("Y-m-d") === $date;
};
if (!$dateIsValid($userFrom)) { $userFrom = ""; }
if (!$dateIsValid($userTo)) { $userTo = ""; }
$userSort = (string) ($_GET["user_sort"] ?? "newest");
$userSorts = ["newest" => "id DESC", "oldest" => "id ASC", "name" => "name ASC"];
if (!isset($userSorts[$userSort])) { $userSort = "newest"; }
$userFilters = [];
$userParams = [];
if ($userSearch !== "") { $userFilters[] = "(name LIKE ? OR email LIKE ? OR student_number LIKE ? OR course LIKE ? OR section LIKE ?)"; $userParams[] = "%" . $userSearch . "%"; $userParams[] = "%" . $userSearch . "%"; $userParams[] = "%" . $userSearch . "%"; $userParams[] = "%" . $userSearch . "%"; $userParams[] = "%" . $userSearch . "%"; }
if ($userRoleFilter !== "") { $userFilters[] = "role = ?"; $userParams[] = $userRoleFilter; }
if ($userFrom !== "") { $userFilters[] = "created_at >= ?"; $userParams[] = $userFrom . " 00:00:00"; }
if ($userTo !== "") { $userFilters[] = "created_at < DATE_ADD(?, INTERVAL 1 DAY)"; $userParams[] = $userTo . " 00:00:00"; }
$userWhereSql = $userFilters ? " WHERE " . implode(" AND ", $userFilters) : "";
$userPerPage = 10;
$userPage = max(1, (int) ($_GET["user_page"] ?? 1));
$userTotal = 0;
$userTotalPages = 1;
$userFirst = 0;
$userLast = 0;

try {
    $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $totalAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

    $userCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users{$userWhereSql}");
    $userCountStmt->execute($userParams);
    $userTotal = (int) $userCountStmt->fetchColumn();
    $userTotalPages = max(1, (int) ceil($userTotal / $userPerPage));
    $userPage = min($userPage, $userTotalPages);
    $userOffset = ($userPage - 1) * $userPerPage;
    $stmt = $pdo->prepare("SELECT id, name, email, role, student_number, course, section, created_at FROM users{$userWhereSql} ORDER BY {$userSorts[$userSort]} LIMIT {$userPerPage} OFFSET {$userOffset}");
    $stmt->execute($userParams);
    $recentUsers = $stmt->fetchAll();
    $userFirst = $userTotal === 0 ? 0 : $userOffset + 1;
    $userLast = min($userOffset + $userPerPage, $userTotal);

    $reqStmt = $pdo->query("
        SELECT 
            br.id, br.user_id, br.quantity, br.borrow_date, br.return_date, br.purpose, br.status, br.created_at,
            u.name AS student_name, u.email AS student_email,
            e.name AS equipment_name
        FROM borrowing_requests br
        INNER JOIN users u ON u.id = br.user_id
        INNER JOIN equipment e ON e.id = br.equipment_id
        ORDER BY br.id DESC
    ");
    $borrowRequests = $reqStmt->fetchAll();
    foreach ($borrowRequests as $request) {
        if ($request["status"] === "pending") {
            $pendingCount++;
            if (count($pendingRequests) < 5) { $pendingRequests[] = $request; }
        } elseif ($request["status"] === "approved") { $approvedCount++; }
    }
    foreach ($borrowRequests as $request) {
        $studentId = (int) $request["user_id"];
        if (!isset($requestsByStudent[$studentId])) {
            $requestsByStudent[$studentId] = [
                "name" => $request["student_name"],
                "email" => $request["student_email"],
                "requests" => [],
            ];
        }
        $requestsByStudent[$studentId]["requests"][] = $request;
    }

} catch (PDOException $exception) {
    error_log("Admin dashboard database error: " . $exception->getMessage());
    $dbError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | HM System</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-main); 
            color: var(--text-main); 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
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
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.05);
        }
        .brand { 
            display: flex; 
            align-items: center; 
            gap: 14px; 
            padding-bottom: 24px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.12); 
        }
        .brand-icon-img { 
            width: 42px; 
            height: 42px; 
            object-fit: contain; 
            border-radius: 10px; 
            background: rgba(255, 255, 255, 0.1); 
            padding: 2px; 
        }
        .brand h2 { font-size: 16px; font-weight: 700; letter-spacing: 0.5px; }
        .brand p { font-size: 11px; color: #a3c1a5; margin-top: 3px; font-weight: 500; }
        
        .nav-label { color: #8fae92; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; margin: 32px 10px 10px; }
        .nav-link { 
            display: flex; 
            align-items: center; 
            padding: 12px 14px; 
            margin-bottom: 6px; 
            border-radius: 10px; 
            color: #d4e3d5; 
            font-size: 13px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .nav-link:hover, .nav-link.active { background: rgba(255, 255, 255, 0.12); color: #fff; }
        
        .sidebar-bottom { margin-top: auto; padding-top: 20px; }
        .admin-mini { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 14px 8px; 
            border-top: 1px solid rgba(255, 255, 255, 0.12); 
            margin-bottom: 12px; 
        }
        .avatar { 
            width: 38px; 
            height: 38px; 
            display: grid; 
            place-items: center; 
            border-radius: 50%; 
            background: var(--accent-light); 
            color: var(--primary); 
            font-weight: 700; 
            font-size: 14px;
            flex-shrink: 0;
        }
        .admin-mini strong { font-size: 13px; font-weight: 600; }
        .admin-mini small { display: block; font-size: 11px; color: #a3c1a5; margin-top: 2px; font-weight: 500; }

        .logout-link { 
            display: block; 
            text-align: center; 
            border: 1px solid rgba(255, 255, 255, 0.2); 
            border-radius: 10px; 
            padding: 11px; 
            font-size: 13px; 
            font-weight: 600; 
            color: white; 
            transition: background 0.2s ease; 
        }
        .logout-link:hover { background: rgba(255, 255, 255, 0.1); }
        
        .main { flex: 1; min-width: 0; }
        .topbar { 
            height: 80px; 
            background: var(--white); 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 40px; 
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }
        .topbar h1 { font-size: 20px; font-weight: 700; color: var(--primary); }
        .topbar p { font-size: 12px; color: var(--text-muted); margin-top: 3px; font-weight: 500; }

        .content { padding: 35px 40px; max-width: 1500px; margin: auto; }
        .welcome { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: var(--white); 
            padding: 30px; 
            border-radius: 16px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 35px; 
            box-shadow: var(--shadow); 
        }
        .welcome h2 { font-size: 26px; color: var(--primary); font-weight: 700; margin-bottom: 6px; }
        .welcome p { font-size: 14px; color: var(--text-muted); font-weight: 500; line-height: 1.6; }
        
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; margin-bottom: 28px; }
        .stat-card { transition: transform .18s ease, box-shadow .18s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(30,70,32,.09); }
        .stat-card .stat-label { display:flex; align-items:center; gap:10px; color:var(--text-muted); font-size:12px; font-weight:600; }
        .stat-icon { display:grid; place-items:center; width:34px; height:34px; border-radius:11px; background:var(--accent-light); color:var(--primary); font-size:17px; }
        .dashboard-queue { background:var(--white); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; box-shadow:var(--shadow); margin-bottom:30px; }
        .queue-heading { display:flex; justify-content:space-between; align-items:center; padding:20px 24px; border-bottom:1px solid var(--border-color); }
        .queue-heading h3 { color:var(--primary); font-size:17px; margin-bottom:4px; }
        .queue-heading p { color:var(--text-muted); font-size:12px; }
        .queue-link { color:var(--primary); font-size:12px; font-weight:700; }
        .queue-empty { padding:28px 24px; color:var(--text-muted); font-size:13px; }
        .activity-list { list-style:none; padding:4px 24px; }
        .activity-item { display:flex; align-items:flex-start; gap:14px; padding:16px 0; border-bottom:1px solid var(--border-color); }
        .activity-item:last-child { border-bottom:0; }
        .activity-mark { flex:0 0 34px; height:34px; display:grid; place-items:center; border-radius:50%; background:var(--accent-light); color:var(--primary); font-weight:700; }
        .activity-copy { flex:1; min-width:0; }
        .activity-copy strong { display:block; color:var(--text-main); font-size:13px; margin-bottom:3px; }
        .activity-copy p { color:var(--text-muted); font-size:12px; overflow-wrap:anywhere; }
        .activity-time { color:var(--text-muted); font-size:11px; white-space:nowrap; }
        .toast { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13px; font-weight:600; }
        .toast-success { background:var(--accent-light); border:1px solid #c2dec4; color:var(--primary); }
        .toast-error { background:#fde8e7; border:1px solid #f3c5c2; color:#a22c2c; }
        .stat-card { background: var(--white); padding: 20px; border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--shadow); }
        .stat-card h3 { font-size: 24px; color: var(--primary); font-weight: 700; margin-bottom: 4px; }
        .stat-card small { font-size: 12px; color: var(--text-muted); font-weight: 600; }
        
        .panel { background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; margin-bottom: 35px; box-shadow: var(--shadow); }
        .panel-header { padding: 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
        .panel-header h3 { font-size: 18px; color: var(--primary); font-weight: 700; margin-bottom: 4px; }
        .panel-header p { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 750px; }
        th { background: #fafbf9; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; text-align: left; padding: 16px 25px; }
        td { padding: 18px 25px; border-top: 1px solid var(--border-color); font-size: 13px; vertical-align: middle; font-weight: 500; }
        
        .pill { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
        .pill.pending { background: #fff3d8; color: #885b00; }
        .pill.approved { background: #e5f4e7; color: #216b30; }
        .pill.rejected { background: #fde8e7; color: #a22c2c; }
        
        .btn-action { padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; }
        .btn-accept { background: #e5f4e7; color: #216b30; }
        .btn-reject { background: #fde8e7; color: #a22c2c; }
        .btn-print { background: #e8f2e9; color: #1e4620; display: inline-block; padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; text-decoration: none; }
        .btn-print:hover { background: #1e4620; color: #fff; }
        .student-requests summary { list-style: none; }
        .student-requests summary::-webkit-details-marker { display: none; }
        .student-email-toggle { display: flex; align-items: center; justify-content: space-between; gap: 16px; color: var(--text-main); cursor: pointer; }
        .student-email-toggle:hover { color: var(--primary); }
        .student-email-toggle:focus-visible { outline: 2px solid var(--primary); outline-offset: 4px; }
        .student-email-arrow { width: 9px; height: 9px; flex: 0 0 9px; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(-45deg); transition: transform 0.2s ease; }
        .student-requests[open] .student-email-arrow { transform: rotate(45deg); }
        .student-requests__list { padding: 0 18px 18px; background: #fafbf9; border-top: 1px solid var(--border-color); }
        .student-requests__table { min-width: 850px; }
        .student-requests__table th { padding: 12px 16px; }
        .student-requests__table td { padding: 14px 16px; }
        
        @media (max-width: 1050px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 700px) { .welcome { padding:22px; align-items:flex-start; flex-direction:column; gap:12px; } .welcome h2 { font-size:22px; } .stats { gap:12px; } .stat-card { padding:16px; } .content { padding:20px 16px; } .topbar { padding:18px 16px; } .queue-heading { padding:18px; } }
        @media (max-width: 420px) { .stats { grid-template-columns:1fr; } }
        .user-filters { display:flex; align-items:flex-end; flex-wrap:wrap; gap:10px; padding:18px 24px; border-bottom:1px solid var(--border-color); }
        .user-filter { display:flex; flex-direction:column; gap:6px; min-width:130px; flex:1; }
        .user-filter.search { min-width:200px; flex:1.5; }
        .user-filter label { font-size:11px; font-weight:700; color:var(--text-muted); }
        .user-filter input,.user-filter select { height:38px; padding:0 10px; border:1px solid var(--border-color); border-radius:8px; background:#fff; font:inherit; font-size:12px; }
        .user-filter-button { height:38px; padding:0 13px; border:0; border-radius:8px; background:var(--primary); color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
        .user-pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 24px; color:var(--text-muted); font-size:12px; }
        .user-pages { display:flex; gap:5px; align-items:center; }
        .user-pages a { border:1px solid var(--border-color); border-radius:6px; padding:7px 9px; color:var(--primary); font-weight:600; }
        .user-pages a.current { background:var(--primary); color:#fff; }
        .user-pages a.disabled { color:#aab5ab; pointer-events:none; }
        @media(max-width:700px) { .user-filters { padding:15px; } .user-filter,.user-filter.search { min-width:calc(50% - 6px); } .user-pagination { padding:14px 15px; flex-direction:column; align-items:flex-start; } }
        /* Footer styling with logos */
        .footer {
            padding: 30px;
            text-align: center;
            background: var(--white);
            border-top: 1px solid var(--border-color);
            margin-top: 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
        }
        .footer-logos {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        .footer-logos img {
            height: 50px;
            width: auto;
            object-fit: contain;
        }
        .footer-text {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <img src="images/ISU.png" alt="ISU Logo" class="brand-icon-img">
            <div><h2>HM SYSTEM</h2><p>Admin Portal</p></div>
        </div>
        <?php require __DIR__ . "/admin_nav.php"; ?>
<div class="sidebar-bottom">
            <div class="admin-mini">
                <div class="avatar"><?= e(strtoupper(substr($adminName, 0, 1))) ?></div>
                <div><strong><?= e($adminName) ?></strong><small>Administrator</small></div>
            </div>
            <a class="logout-link" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div><h1>Admin Dashboard</h1><p>Hospitality Management System</p></div>
            <div style="background:var(--accent-light); padding:8px 14px; border-radius:20px; font-weight:700; color:var(--primary);">Active Administrator</div>
        </header>

        <div class="content">
            <?php if ($actionMessage): ?>
                <div class="toast <?= str_contains($actionMessage, "successfully") ? "toast-success" : "toast-error" ?>" role="status"><?= e($actionMessage) ?></div>
            <?php endif; ?>

            <!-- DASHBOARD -->
            <div id="section-dashboard">
                <section class="welcome">
                    <div><h2>Welcome back, <?= e($adminName) ?>!</h2><p>Overview of system activity.</p></div>
                    <div style="color:var(--text-muted); font-weight:600;"><?= date("F j, Y") ?></div>
                </section>
                <section class="stats" aria-label="System overview">
                    <article class="stat-card"><h3><?= number_format($pendingCount) ?></h3><div class="stat-label"><span class="stat-icon">◷</span>Pending Requests</div></article>
                    <article class="stat-card"><h3><?= number_format($approvedCount) ?></h3><div class="stat-label"><span class="stat-icon">✓</span>Approved Requests</div></article>
                    <article class="stat-card"><h3><?= number_format($totalStudents) ?></h3><div class="stat-label"><span class="stat-icon">♙</span>Student Accounts</div></article>
                    <article class="stat-card"><h3><?= number_format($totalAdmins) ?></h3><div class="stat-label"><span class="stat-icon">⚙</span>Administrators</div></article>
                </section>
                <section class="dashboard-queue">
                    <div class="queue-heading"><div><h3>Requests needing attention</h3><p>Recent equipment requests awaiting a decision</p></div><a class="queue-link" href="#" onclick="showSection('requests'); return false;">View all requests →</a></div>
                    <?php if (empty($pendingRequests)): ?><div class="queue-empty">You’re all caught up. No pending requests.</div>
                    <?php else: ?><div class="table-wrap"><table style="min-width:620px"><thead><tr><th>Equipment</th><th>Student</th><th>Borrow period</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($pendingRequests as $request): ?><tr><td><strong><?= e($request["equipment_name"]) ?></strong></td><td><?= e($request["student_name"]) ?></td><td><?= e(date("M j", strtotime($request["borrow_date"]))) ?> – <?= e(date("M j, Y", strtotime($request["return_date"]))) ?></td><td><span class="pill pending">Pending</span></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
            </div>

            <!-- EQUIPMENT REQUESTS -->
            <section class="panel" id="section-requests" style="display: none;">
                <div class="panel-header">
                    <div><h3>Student Borrowing Requests</h3><p>Manage and review requests</p></div>
                    <span style="background:var(--accent-light); color:var(--primary); padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700;"><?= count($borrowRequests) ?> total requests</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <tbody>
                        <?php if (empty($borrowRequests)): ?>
                            <tr><td colspan="7" style="padding:45px; text-align:center; color:var(--text-muted);">No borrowing requests found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requestsByStudent as $studentId => $student): ?>
                                <tr>
                                    <td colspan="7">
                                        <details class="student-requests">
                                            <summary class="student-email-toggle" aria-expanded="false">
                                                <span><?= e($student["email"]) ?></span>
                                                <span class="student-email-arrow" aria-hidden="true"></span>
                                            </summary>
                                            <div class="student-requests__list">
                                                <table class="student-requests__table" data-pagination="false">
                                                    <thead>
                                                        <tr>
                                                            <th>Equipment</th>
                                                            <th>Qty</th>
                                                            <th>Period</th>
                                                            <th>Purpose</th>
                                                            <th>Status</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($student["requests"] as $req): ?>
                                                            <tr>
                                                                <td><strong><?= e($req["equipment_name"]) ?></strong></td>
                                                                <td><?= (int) $req["quantity"] ?></td>
                                                                <td><?= e(date("M j, Y", strtotime($req["borrow_date"]))) ?> to <?= e(date("M j, Y", strtotime($req["return_date"]))) ?></td>
                                                                <td><?= e($req["purpose"]) ?></td>
                                                                <td><span class="pill <?= e($req["status"]) ?>"><?= e($req["status"]) ?></span></td>
                                                                <td>
                                                                    <?php if ($req["status"] === 'pending'): ?>
                                                                        <form method="POST" style="display:inline-flex; gap:6px;">
                                                                            <input type="hidden" name="request_id" value="<?= (int) $req["id"] ?>">
                                                                            <button type="submit" name="action" value="approved" class="btn-action btn-accept">Accept</button>
                                                                            <button type="submit" name="action" value="rejected" class="btn-action btn-reject">Reject</button>
                                                                        </form>
                                                                    <?php elseif ($req["status"] === 'approved'): ?>
                                                                        <a href="print_receipt.php?id=<?= (int) $req["id"] ?>" target="_blank" class="btn-print">Print Receipt</a>
                                                                    <?php else: ?>
                                                                        <span style="font-size:12px; color:var(--text-muted); font-weight:600;">Closed</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- USERS -->
            <section class="panel" id="section-users" style="display: none;">
                <div class="panel-header"><div><h3>Student Accounts</h3><p>Search and filter students; choose account type to include administrators</p></div><span style="background:var(--accent-light); color:var(--primary); padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700;"><?= number_format($userTotal) ?> accounts</span></div>
                <form class="user-filters" method="GET" action="admin_dashboard.php">
                    <input type="hidden" name="section" value="users">
                    <div class="user-filter search"><label for="user-q">Search student number, name, or email</label><input id="user-q" type="search" name="user_q" value="<?= e($userSearch) ?>" placeholder="Name, email, or student number"></div>
                    <div class="user-filter"><label for="user-role">Account type</label><select id="user-role" name="user_role"><option value="">All accounts</option><option value="student" <?= $userRoleFilter === "student" ? "selected" : "" ?>>Students</option><option value="admin" <?= $userRoleFilter === "admin" ? "selected" : "" ?>>Administrators</option></select></div>
                    <div class="user-filter"><label for="user-from">Registered from</label><input id="user-from" type="date" name="user_from" value="<?= e($userFrom) ?>"></div>
                    <div class="user-filter"><label for="user-to">Registered to</label><input id="user-to" type="date" name="user_to" value="<?= e($userTo) ?>"></div>
                    <div class="user-filter"><label for="user-sort">Sort by</label><select id="user-sort" name="user_sort"><option value="newest" <?= $userSort === "newest" ? "selected" : "" ?>>Newest</option><option value="oldest" <?= $userSort === "oldest" ? "selected" : "" ?>>Oldest</option><option value="name" <?= $userSort === "name" ? "selected" : "" ?>>Name A–Z</option></select></div>
                    <button class="user-filter-button" type="submit">Apply filters</button><a class="user-filter-button" style="display:inline-flex;align-items:center;background:#fff;color:var(--primary);border:1px solid var(--border-color);text-decoration:none;" href="admin_dashboard.php?section=users">Clear</a>
                </form>
                <div class="table-wrap"><table data-pagination="false">
                    <thead><tr><th>Name</th><th>Student Number</th><th>Course</th><th>Section</th><th>Email</th><th>Role</th><th>Registered</th></tr></thead>
                    <tbody>
                        <?php if (empty($recentUsers)): ?><tr><td colspan="7" style="padding:35px; text-align:center; color:var(--text-muted);">No accounts match these filters.</td></tr>
                        <?php else: foreach ($recentUsers as $user): ?><tr><td><strong><?= e($user["name"]) ?></strong></td><td><?= e($user["student_number"] ?? "—") ?></td><td><?= e($user["course"] ?? "—") ?></td><td><?= e($user["section"] ?? "—") ?></td><td><?= e($user["email"]) ?></td><td><span style="text-transform: capitalize;"><?= e($user["role"]) ?></span></td><td><?= e(date("M j, Y", strtotime($user["created_at"]))) ?></td></tr><?php endforeach; endif; ?>
                    </tbody>
                </table></div>
                <div class="user-pagination"><span>Showing <?= number_format($userFirst) ?>–<?= number_format($userLast) ?> of <?= number_format($userTotal) ?> accounts</span><nav class="user-pages" aria-label="Account pages">
                    <?php $userQuery = $_GET; unset($userQuery["user_page"]); ?>
                    <a class="<?= $userPage <= 1 ? "disabled" : "" ?>" href="admin_dashboard.php?<?= e(http_build_query(array_merge($userQuery, ["user_page" => max(1, $userPage - 1)]))) ?>">‹ Previous</a>
                    <?php for ($pageNo = max(1, $userPage - 2); $pageNo <= min($userTotalPages, $userPage + 2); $pageNo++): ?><a class="<?= $pageNo === $userPage ? "current" : "" ?>" href="admin_dashboard.php?<?= e(http_build_query(array_merge($userQuery, ["user_page" => $pageNo]))) ?>" <?= $pageNo === $userPage ? "aria-current=\"page\"" : "" ?>><?= $pageNo ?></a><?php endfor; ?>
                    <a class="<?= $userPage >= $userTotalPages ? "disabled" : "" ?>" href="admin_dashboard.php?<?= e(http_build_query(array_merge($userQuery, ["user_page" => min($userTotalPages, $userPage + 1)]))) ?>">Next ›</a>
                </nav></div>
            </section>

            <!-- FOOTER WITH ISU AND CCSICT LOGOS -->
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

<script>
    function showSection(sectionId) {
        document.getElementById('section-dashboard').style.display = (sectionId === 'dashboard') ? 'block' : 'none';
        document.getElementById('section-requests').style.display = (sectionId === 'requests') ? 'block' : 'none';
        document.getElementById('section-users').style.display = (sectionId === 'users') ? 'block' : 'none';
        
        document.getElementById('nav-dashboard').className = 'nav-link ' + (sectionId === 'dashboard' ? 'active' : '');
        document.getElementById('nav-requests').className = 'nav-link ' + (sectionId === 'requests' ? 'active' : '');
        document.getElementById('nav-users').className = 'nav-link ' + (sectionId === 'users' ? 'active' : '');
    }
    const queryParams = new URLSearchParams(window.location.search);
    const requestedSection = queryParams.get("section");
    if (["dashboard", "requests", "users"].includes(requestedSection)) showSection(requestedSection);
</script>
<script>
    document.querySelectorAll('.student-requests').forEach((details) => {
        const summary = details.querySelector('.student-email-toggle');
        details.addEventListener('toggle', () => {
            summary.setAttribute('aria-expanded', String(details.open));
        });
    });
</script>
<script src="js/table-pagination.js" defer></script>
</body>
</html>
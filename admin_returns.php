<?php
session_start();
if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["user_role"] ?? "", ["admin", "superadmin"], true)) {
    header("Location: index.php");
    exit;
}
require_once "db.php";

$adminName = $_SESSION["user_name"] ?? "Administrator";
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }
if (empty($_SESSION["csrf_token"])) { $_SESSION["csrf_token"] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION["csrf_token"];
$message = "";
$messageType = "success";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrfToken, (string) ($_POST["csrf_token"] ?? ""))) {
        $message = "Your session expired. Refresh the page and try again.";
        $messageType = "error";
    } else {
        $requestId = (int) ($_POST["request_id"] ?? 0);
        try {
            $pdo->beginTransaction();
            $find = $pdo->prepare("SELECT br.id, br.status, br.quantity, e.name AS equipment_name, u.name AS student_name FROM borrowing_requests br INNER JOIN equipment e ON e.id = br.equipment_id INNER JOIN users u ON u.id = br.user_id WHERE br.id = ? FOR UPDATE");
            $find->execute([$requestId]);
            $request = $find->fetch();
            if (!$request || $request["status"] !== "approved") {
                throw new RuntimeException("This borrowing is no longer awaiting return.");
            }
            $update = $pdo->prepare("UPDATE borrowing_requests SET status = 'returned', return_condition = NULL, returned_at = NOW(), return_notes = NULL WHERE id = ? AND status = 'approved'");
            $update->execute([$requestId]);
            if ($update->rowCount() !== 1) { throw new RuntimeException("This borrowing was already processed."); }
            $pdo->commit();
            $detail = $request["equipment_name"] . " × " . (int) $request["quantity"] . " returned by " . $request["student_name"] . ".";
            log_activity($pdo, (int) $_SESSION["user_id"], "Borrowing returned", $detail);
            header("Location: admin_returns.php?notice=recorded");
            exit;
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $message = $ex->getMessage();
            $messageType = "error";
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Return processing error: " . $ex->getMessage());
            $message = "The return could not be recorded. Please try again.";
            $messageType = "error";
        }
    }
}
if (($_GET["notice"] ?? "") === "recorded") { $message = "Item return recorded."; }
$today = (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d");
$search = trim((string) ($_GET["q"] ?? ""));
$view = (string) ($_GET["view"] ?? "active");
if (!in_array($view, ["active", "overdue", "history"], true)) { $view = "active"; }
$where = [];
$params = [];
if ($view === "history") { $where[] = "br.status = 'returned'"; }
else { $where[] = "br.status = 'approved'"; if ($view === "overdue") { $where[] = "br.return_date < ?"; $params[] = $today; } }
if ($search !== "") { $where[] = "(u.name LIKE ? OR u.student_number LIKE ? OR e.name LIKE ?)"; $term = "%" . $search . "%"; array_push($params, $term, $term, $term); }
$whereSql = " WHERE " . implode(" AND ", $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM borrowing_requests br INNER JOIN users u ON u.id = br.user_id INNER JOIN equipment e ON e.id = br.equipment_id{$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageNumber = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($pageNumber - 1) * $perPage;
$list = $pdo->prepare("SELECT br.id, br.quantity, br.borrow_date, br.return_date, br.status, br.returned_at, br.created_at, u.name AS student_name, u.student_number, e.name AS equipment_name FROM borrowing_requests br INNER JOIN users u ON u.id = br.user_id INNER JOIN equipment e ON e.id = br.equipment_id{$whereSql} ORDER BY " . ($view === "history" ? "br.returned_at DESC, br.id DESC" : "br.return_date ASC, br.id ASC") . " LIMIT {$perPage} OFFSET {$offset}");
$list->execute($params);
$rows = $list->fetchAll();
$firstRow = $totalRows === 0 ? 0 : $offset + 1;
$lastRow = min($offset + $perPage, $totalRows);
function return_page_url(int $targetPage): string { $query = $_GET; unset($query["page"], $query["notice"]); $query["page"] = $targetPage; return "admin_returns.php?" . http_build_query($query); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Returns | HM System</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1e4620;--dark:#153216;--accent:#e8f2e9;--bg:#f4f7f4;--text:#1f2923;--muted:#5e6c62;--border:#d1ded2;--white:#fff}*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{display:flex;min-height:100vh}.sidebar{width:260px;flex-shrink:0;background:var(--dark);color:#fff;padding:30px 20px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}.brand{display:flex;align-items:center;gap:14px;padding-bottom:24px;border-bottom:1px solid #ffffff22}.brand img{width:42px;height:42px;object-fit:contain}.brand h2{font-size:16px}.brand p,.profile small{font-size:11px;color:#a3c1a5;margin-top:3px}.nav-label{color:#8fae92;font-size:10px;font-weight:700;letter-spacing:1.2px;margin:30px 10px 10px}.nav-link{padding:12px 14px;margin-bottom:6px;border-radius:10px;color:#d4e3d5;font-size:13px;font-weight:600}.nav-link:hover,.nav-link.active{background:#ffffff1e;color:#fff}.side-bottom{margin-top:auto}.profile{display:flex;align-items:center;gap:12px;padding:14px 8px;border-top:1px solid #ffffff22;margin-bottom:12px}.avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--accent);color:var(--primary);font-weight:700}.profile strong{font-size:13px}.profile small{display:block}.logout{display:block;text-align:center;border:1px solid #ffffff33;border-radius:10px;padding:11px;font-size:13px;font-weight:600}.main{min-width:0;flex:1}.topbar{min-height:80px;display:flex;align-items:center;justify-content:space-between;padding:0 32px;background:#fff;border-bottom:1px solid var(--border)}.topbar h1{font-size:20px;color:var(--primary)}.topbar p{font-size:12px;color:var(--muted);margin-top:4px}.content{max-width:1700px;margin:auto;padding:26px 32px}.message{padding:12px 15px;margin-bottom:16px;border-radius:9px;font-size:13px;font-weight:600}.success{background:var(--accent);color:var(--primary);border:1px solid #c2dec4}.error{background:#fde8e7;color:#a22c2c;border:1px solid #f3c5c2}.card{background:#fff;border:1px solid var(--border);border-radius:15px;overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;border-bottom:1px solid var(--border)}.card-head h2{font-size:16px;color:var(--primary)}.card-head p{font-size:12px;color:var(--muted);margin-top:4px}.filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;padding:17px 20px;border-bottom:1px solid var(--border)}.field{display:flex;flex-direction:column;gap:6px;min-width:170px;flex:1}.field.search{min-width:240px;flex:2}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input,.field select{height:39px;border:1px solid var(--border);border-radius:8px;padding:0 11px;background:#fff;font:inherit;font-size:12px}.button{height:39px;padding:0 14px;border:0;border-radius:8px;background:var(--primary);color:#fff;font-weight:700;font-size:12px;cursor:pointer}.clear{display:inline-flex;align-items:center;height:39px;padding:0 13px;border:1px solid var(--border);border-radius:8px;color:var(--primary);font-size:12px;font-weight:700}.table-wrap{overflow-x:auto}table{width:100%;min-width:1150px;border-collapse:collapse}th{background:#f5f8f5;color:#617365;font-size:10px;letter-spacing:.5px;text-align:left;padding:13px 12px}td{border-top:1px solid #e7ede7;padding:12px;font-size:12px;vertical-align:middle}td strong{font-size:13px}.status{display:inline-block;border-radius:16px;padding:5px 9px;font-size:10px;font-weight:700}.overdue{background:#fde8e7;color:#a22c2c}.on-time{background:var(--accent);color:var(--primary)}.due-today{background:#fff3d8;color:#885b00}.return-form{display:grid;grid-template-columns:110px minmax(120px,1fr) auto;gap:6px;min-width:300px}.return-form select,.return-form input{height:32px;min-width:0;padding:0 7px;border:1px solid var(--border);border-radius:6px;font:inherit;font-size:11px}.return-form button{border:0;border-radius:6px;background:var(--primary);color:#fff;padding:0 9px;font-size:11px;font-weight:700;cursor:pointer}.pager{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 20px;color:var(--muted);font-size:12px}.pages{display:flex;gap:5px}.pages a{border:1px solid var(--border);border-radius:6px;padding:7px 9px;color:var(--primary);font-weight:600}.pages a.current{background:var(--primary);color:#fff}.pages a.disabled{pointer-events:none;color:#aab5ab}.empty{padding:30px;text-align:center;color:var(--muted)}@media(max-width:700px){.layout{display:block}.sidebar{width:100%;height:auto;position:static;padding:16px}.brand{padding-bottom:12px}.nav-label{margin:16px 10px 8px}.side-bottom{display:none}.topbar{padding:14px 16px}.content{padding:15px 10px}.filters{padding:14px}.field,.field.search{min-width:100%}.pager{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body><div class="layout">
<aside class="sidebar"><div class="brand"><img src="images/ISU.png" alt="ISU"><div><h2>HM SYSTEM</h2><p>Admin Portal</p></div></div><?php require __DIR__ . "/admin_nav.php"; ?>
<div class="side-bottom"><div class="profile"><div class="avatar"><?= e(strtoupper(substr($adminName, 0, 1))) ?></div><div><strong><?= e($adminName) ?></strong><small>Administrator</small></div></div><a class="logout" href="logout.php">Logout</a></div></aside>
<main class="main"><header class="topbar"><div><h1>Returns</h1><p>Record item returns and identify overdue equipment</p></div></header><div class="content">
<?php if ($message !== ""): ?><div class="message <?= e($messageType) ?>" role="status"><?= e($message) ?></div><?php endif; ?>
<section class="card"><div class="card-head"><div><h2><?= $view === "history" ? "Return history" : ($view === "overdue" ? "Overdue items" : "Awaiting return") ?></h2><p>Overdue status is automatic after the due date. You can still record a return at any time.</p></div><span><?= number_format($totalRows) ?> records</span></div>
<form class="filters" method="GET" action="admin_returns.php"><div class="field search"><label for="q">Search student or equipment</label><input id="q" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, student number, or tool"></div><div class="field"><label for="view">Return status</label><select id="view" name="view"><option value="active" <?= $view === "active" ? "selected" : "" ?>>Awaiting return</option><option value="overdue" <?= $view === "overdue" ? "selected" : "" ?>>Overdue</option><option value="history" <?= $view === "history" ? "selected" : "" ?>>Return history</option></select></div><button class="button" type="submit">Apply filters</button><a class="clear" href="admin_returns.php">Clear</a></form>
<div class="table-wrap"><table data-pagination="false"><thead><tr><th>STUDENT</th><th>TOOL</th><th>QTY</th><th>BORROW PERIOD</th><th>DUE / RETURNED</th><th>STATUS</th><th>RETURN ACTION</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td class="empty" colspan="7">No items match this view.</td></tr><?php else: foreach ($rows as $row): $isOverdue = $row["status"] === "approved" && $row["return_date"] < $today; ?>
<tr><td><strong><?= e($row["student_name"]) ?></strong><br><span style="color:var(--muted)"><?= e($row["student_number"] ?? "") ?></span></td><td><?= e($row["equipment_name"]) ?></td><td><?= (int) $row["quantity"] ?></td><td><?= e(date("M j", strtotime($row["borrow_date"]))) ?> – <?= e(date("M j, Y", strtotime($row["return_date"]))) ?></td><td><?= $row["status"] === "returned" ? e(date("M j, Y g:i a", strtotime($row["returned_at"]))) : e(date("M j, Y", strtotime($row["return_date"]))) ?></td><td><?php if ($row["status"] === "returned"): ?><span class="status on-time">Recorded</span><?php elseif ($isOverdue): ?><span class="status overdue">Overdue</span><?php elseif ($row["return_date"] === date("Y-m-d")): ?><span class="status due-today">Due today</span><?php else: ?><span class="status on-time">On schedule</span><?php endif; ?></td><td>
<?php if ($row["status"] === "approved"): ?><form method="POST" onsubmit="return confirm('Mark this item as returned?');"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="request_id" value="<?= (int) $row["id"] ?>"><button type="submit" aria-label="Record return">Record return</button></form><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<div class="pager"><span>Showing <?= number_format($firstRow) ?>–<?= number_format($lastRow) ?> of <?= number_format($totalRows) ?></span><nav class="pages" aria-label="Return pages"><a class="<?= $pageNumber <= 1 ? "disabled" : "" ?>" href="<?= e(return_page_url(max(1,$pageNumber-1))) ?>">‹ Previous</a><?php for($i=max(1,$pageNumber-2);$i<=min($totalPages,$pageNumber+2);$i++): ?><a class="<?= $i===$pageNumber ? "current" : "" ?>" href="<?= e(return_page_url($i)) ?>" <?= $i===$pageNumber ? "aria-current=\"page\"" : "" ?>><?= $i ?></a><?php endfor; ?><a class="<?= $pageNumber >= $totalPages ? "disabled" : "" ?>" href="<?= e(return_page_url(min($totalPages,$pageNumber+1))) ?>">Next ›</a></nav></div>
</section></div></main></div></body></html>
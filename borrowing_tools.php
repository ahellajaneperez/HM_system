<?php
session_start();
if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["user_role"] ?? "", ["admin", "superadmin"], true)) {
    header("Location: index.php");
    exit;
}
require_once "db.php";

$adminName = $_SESSION["user_name"] ?? "Administrator";
if (empty($_SESSION["csrf_token"])) { $_SESSION["csrf_token"] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION["csrf_token"];
$message = "";
$messageType = "success";
$formData = ["id" => 0, "name" => "", "category" => "", "total_quantity" => "", "photo_path" => null];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrfToken, (string) ($_POST["csrf_token"] ?? ""))) {
        $message = "Your session expired. Refresh the page and try again.";
        $messageType = "error";
    } else {
        $action = (string) ($_POST["form_action"] ?? "");
        $toolId = (int) ($_POST["equipment_id"] ?? 0);
        if ($action === "save") {
            $formData = [
                "id" => $toolId,
                "name" => trim((string) ($_POST["name"] ?? "")),
                "category" => trim((string) ($_POST["category"] ?? "")),
                "total_quantity" => trim((string) ($_POST["total_quantity"] ?? "")),
            ];
            $quantityValid = filter_var($formData["total_quantity"], FILTER_VALIDATE_INT) !== false
                && (int) $formData["total_quantity"] >= 0 && (int) $formData["total_quantity"] <= 4294967295;
            $photoUpload = $_FILES["photo"] ?? null;
            $photoError = null;
            if ($photoUpload && $photoUpload["error"] !== UPLOAD_ERR_NO_FILE) {
                if ($photoUpload["error"] !== UPLOAD_ERR_OK) { $photoError = "The photo upload failed. Please try again."; }
                elseif ((int) $photoUpload["size"] > 5 * 1024 * 1024) { $photoError = "The photo must be 5 MB or smaller."; }
                else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($photoUpload["tmp_name"]);
                    $allowedPhotoTypes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                    if (!isset($allowedPhotoTypes[$mime])) { $photoError = "Upload a JPG, PNG, or WebP photo."; }
                }
            }
            if ($formData["name"] === "" || text_length($formData["name"]) > 150 || $formData["category"] === "" || text_length($formData["category"]) > 120 || !$quantityValid || $photoError !== null) {
                $message = $photoError ?? "Enter a tool name, category, and a whole-number quantity of zero or more.";
                $messageType = "error";
            } else {
                $newPhotoPath = null;
                $oldPhotoPath = null;
                try {
                    if ($photoUpload && $photoUpload["error"] === UPLOAD_ERR_OK) {
                        $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "equipment";
                        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) { throw new RuntimeException("The photo upload folder could not be created."); }
                        $fileName = bin2hex(random_bytes(16)) . "." . $allowedPhotoTypes[$mime];
                        if (!move_uploaded_file($photoUpload["tmp_name"], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) { throw new RuntimeException("The photo could not be saved. Check folder permissions and try again."); }
                        $newPhotoPath = "uploads/equipment/" . $fileName;
                    }
                    if ($toolId > 0) {
                        $oldStmt = $pdo->prepare("SELECT name, category, total_quantity, photo_path FROM equipment WHERE id = ?");
                        $oldStmt->execute([$toolId]);
                        $oldTool = $oldStmt->fetch();
                        if (!$oldTool) { throw new RuntimeException("That tool no longer exists."); }
                        $oldPhotoPath = $oldTool["photo_path"] ?? null;
                        $approvedStockStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM borrowing_requests WHERE equipment_id = ? AND status = 'approved'");
                        $approvedStockStmt->execute([$toolId]);
                        $approvedStock = (int) $approvedStockStmt->fetchColumn();
                        if ((int) $formData["total_quantity"] < $approvedStock) { throw new RuntimeException("Total inventory cannot be lower than the {$approvedStock} units currently approved for borrowing."); }
                        $update = $pdo->prepare("UPDATE equipment SET name = ?, category = ?, total_quantity = ?, photo_path = COALESCE(?, photo_path) WHERE id = ?");
                        $update->execute([$formData["name"], $formData["category"], (int) $formData["total_quantity"], $newPhotoPath, $toolId]);
                        log_activity($pdo, (int) $_SESSION["user_id"], "Tool updated", $oldTool["name"] . " updated to " . $formData["name"] . " · stock " . $oldTool["total_quantity"] . " → " . $formData["total_quantity"]);
                        if ($newPhotoPath !== null && $oldPhotoPath && str_starts_with($oldPhotoPath, "uploads/equipment/")) { $oldFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $oldPhotoPath); if (is_file($oldFile)) { unlink($oldFile); } }
                        header("Location: borrowing_tools.php?notice=updated");
                    } else {
                        $insert = $pdo->prepare("INSERT INTO equipment (name, category, photo_path, total_quantity) VALUES (?, ?, ?, ?)");
                        $insert->execute([$formData["name"], $formData["category"], $newPhotoPath, (int) $formData["total_quantity"]]);
                        log_activity($pdo, (int) $_SESSION["user_id"], "Tool created", $formData["name"] . " · " . $formData["category"] . " · stock " . $formData["total_quantity"]);
                        header("Location: borrowing_tools.php?notice=created");
                    }
                    exit;
                } catch (PDOException $ex) {
                    $message = $ex->getCode() === "23000" ? "A tool with that name already exists, or it is still linked to borrowing records." : "The tool could not be saved. Please try again.";
                    $messageType = "error";
                } catch (RuntimeException $ex) {
                    $message = $ex->getMessage();
                    $messageType = "error";
                }
            }
        } elseif ($action === "delete" && $toolId > 0) {
            try {
                $find = $pdo->prepare("SELECT name FROM equipment WHERE id = ?");
                $find->execute([$toolId]);
                $tool = $find->fetch();
                if (!$tool) { throw new RuntimeException("That tool no longer exists."); }
                $delete = $pdo->prepare("DELETE FROM equipment WHERE id = ?");
                $delete->execute([$toolId]);
                log_activity($pdo, (int) $_SESSION["user_id"], "Tool deleted", $tool["name"] . " removed from the equipment catalog.");
                header("Location: borrowing_tools.php?notice=deleted");
                exit;
            } catch (PDOException $ex) {
                $message = "This tool is linked to borrowing history and cannot be deleted. You can set its quantity to zero to make it unavailable to students.";
                $messageType = "error";
            } catch (RuntimeException $ex) {
                $message = $ex->getMessage();
                $messageType = "error";
            }
        }
    }
}

if (isset($_GET["notice"])) {
    $notices = ["created" => "Tool created successfully.", "updated" => "Tool updated successfully.", "deleted" => "Tool deleted successfully."];
    $message = $notices[(string) $_GET["notice"]] ?? "";
}

$editId = (int) ($_GET["edit"] ?? 0);
if ($editId > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $editStmt = $pdo->prepare("SELECT id, name, category, total_quantity, photo_path FROM equipment WHERE id = ?");
    $editStmt->execute([$editId]);
    $loadedTool = $editStmt->fetch();
    if ($loadedTool) { $formData = $loadedTool; }
}
$search = trim((string) ($_GET["q"] ?? ""));
$categoryFilter = trim((string) ($_GET["category"] ?? ""));
$stockFilter = (string) ($_GET["stock"] ?? "all");
if (!in_array($stockFilter, ["all", "available", "out"], true)) { $stockFilter = "all"; }
$sort = (string) ($_GET["sort"] ?? "name_asc");
$sorts = ["name_asc" => "e.name ASC", "name_desc" => "e.name DESC", "category" => "e.category ASC, e.name ASC", "stock_low" => "e.total_quantity ASC, e.name ASC", "stock_high" => "e.total_quantity DESC, e.name ASC", "recent" => "e.updated_at DESC, e.name ASC"];
if (!isset($sorts[$sort])) { $sort = "name_asc"; }
$categories = $pdo->query("SELECT DISTINCT category FROM equipment ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$availableStockSql = "GREATEST(e.total_quantity - COALESCE((SELECT SUM(br.quantity) FROM borrowing_requests br WHERE br.equipment_id = e.id AND br.status = 'approved'), 0), 0)";
$where = [];
$params = [];
if ($search !== "") { $where[] = "(e.name LIKE ? OR e.category LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($categoryFilter !== "" && in_array($categoryFilter, $categories, true)) { $where[] = "e.category = ?"; $params[] = $categoryFilter; }
if ($stockFilter === "available") { $where[] = "{$availableStockSql} > 0"; }
if ($stockFilter === "out") { $where[] = "{$availableStockSql} = 0"; }
$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM equipment e{$whereSql}");
$countStmt->execute($params);
$totalTools = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalTools / $perPage));
$page = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;
$toolStmt = $pdo->prepare("SELECT e.id, e.name, e.category, e.photo_path, e.total_quantity, {$availableStockSql} AS available_quantity, e.updated_at,
    (SELECT COUNT(*) FROM borrowing_requests br WHERE br.equipment_id = e.id) AS request_count
    FROM equipment e{$whereSql} ORDER BY {$sorts[$sort]} LIMIT {$perPage} OFFSET {$offset}");
$toolStmt->execute($params);
$tools = $toolStmt->fetchAll();
$firstTool = $totalTools === 0 ? 0 : $offset + 1;
$lastTool = min($offset + $perPage, $totalTools);
function tools_page_url(int $page): string { $query = $_GET; $query["page"] = $page; unset($query["notice"], $query["edit"]); return "borrowing_tools.php?" . http_build_query($query); }
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }
function text_length(string $value): int { return function_exists("mb_strlen") ? mb_strlen($value) : strlen($value); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Borrowing Tools | HM System</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1e4620;--primary-dark:#153216;--accent:#e8f2e9;--bg:#f4f7f4;--text:#1f2923;--muted:#5e6c62;--border:#d1ded2;--white:#fff;--shadow:0 6px 20px rgba(30,70,32,.04)}*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{display:flex;min-height:100vh}.sidebar{width:260px;flex-shrink:0;background:var(--primary-dark);color:#fff;padding:30px 20px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}.brand{display:flex;align-items:center;gap:14px;padding-bottom:24px;border-bottom:1px solid #ffffff20}.brand img{width:42px;height:42px;object-fit:contain;border-radius:10px}.brand h2{font-size:16px}.brand p,.admin-mini small{font-size:11px;color:#a3c1a5;margin-top:3px}.nav-label{color:#8fae92;font-size:10px;font-weight:700;letter-spacing:1.2px;margin:32px 10px 10px}.nav-link{display:flex;padding:12px 14px;margin-bottom:6px;border-radius:10px;color:#d4e3d5;font-size:13px;font-weight:600}.nav-link:hover,.nav-link.active{background:#ffffff1f;color:#fff}.sidebar-bottom{margin-top:auto;padding-top:20px}.admin-mini{display:flex;align-items:center;gap:12px;padding:14px 8px;border-top:1px solid #ffffff20;margin-bottom:12px}.avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--accent);color:var(--primary);font-weight:700}.admin-mini strong{font-size:13px}.admin-mini small{display:block}.logout{display:block;text-align:center;border:1px solid #ffffff33;border-radius:10px;padding:11px;font-size:13px;font-weight:600}.main{min-width:0;flex:1}.topbar{min-height:80px;padding:0 32px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}.topbar h1{font-size:20px;color:var(--primary)}.topbar p{font-size:12px;color:var(--muted);margin-top:4px}.content{max-width:1600px;margin:auto;padding:28px 32px}.grid{display:grid;grid-template-columns:minmax(270px,340px) minmax(0,1fr);gap:20px;align-items:start}.card{background:#fff;border:1px solid var(--border);border-radius:15px;box-shadow:var(--shadow);overflow:hidden}.card-head{padding:20px 22px;border-bottom:1px solid var(--border)}.card-head h2{font-size:16px;color:var(--primary);margin-bottom:4px}.card-head p{font-size:12px;color:var(--muted)}.form-body{padding:20px 22px}.field{margin-bottom:15px}.field label{display:block;font-size:12px;font-weight:700;color:#405a47;margin-bottom:7px}.field input,.field select{width:100%;height:42px;padding:0 12px;border:1px solid #cbd8cc;border-radius:8px;font:inherit;font-size:13px;color:var(--text)}.btn{min-height:39px;border:0;border-radius:8px;padding:0 14px;background:var(--primary);color:#fff;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.btn:hover{background:var(--primary-dark)}.btn.secondary{background:#fff;color:var(--primary);border:1px solid var(--border)}.btn.danger{background:#fff1f0;color:#a22c2c;border:1px solid #f0c8c5}.form-actions{display:flex;gap:8px}.message{padding:12px 15px;border-radius:9px;margin-bottom:16px;font-size:13px;font-weight:600}.message.success{background:var(--accent);color:var(--primary);border:1px solid #c2dec4}.message.error{background:#fde8e7;color:#a22c2c;border:1px solid #f3c5c2}.filters{padding:17px 20px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;border-bottom:1px solid var(--border)}.filters .field{margin:0;min-width:125px;flex:1}.filters .field.search{min-width:190px;flex:1.5}.filters .field.sort{min-width:150px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:730px}th{background:#f5f8f5;color:#617365;font-size:10px;letter-spacing:.5px;text-align:left;padding:13px 14px}td{border-top:1px solid #e7ede7;padding:13px 14px;font-size:12px;vertical-align:middle}td strong{font-size:13px;color:var(--text)}.stock{display:inline-block;padding:5px 9px;border-radius:15px;background:var(--accent);color:var(--primary);font-size:11px;font-weight:700}.stock.out{background:#fde8e7;color:#a22c2c}.row-actions{display:flex;gap:7px;align-items:center}.row-actions .btn{min-height:31px;padding:0 10px}.row-actions form{margin:0}.row-actions button:disabled{opacity:.45;cursor:not-allowed}.linked{color:var(--muted);font-size:11px}.pagination{padding:15px 18px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:var(--muted);font-size:12px}.pages{display:flex;gap:5px}.pages a{border:1px solid var(--border);border-radius:6px;padding:7px 10px;color:var(--primary);font-weight:600}.pages a.disabled{pointer-events:none;color:#9aaa9b}.empty{padding:30px;text-align:center;color:var(--muted);font-size:13px}
@media(max-width:1050px){.grid{grid-template-columns:1fr}.grid>.card:first-child{max-width:none}}@media(max-width:650px){.layout{display:block}.sidebar{position:static;width:100%;height:auto;padding:16px}.brand{padding-bottom:12px}.nav-label{margin:16px 10px 8px}.sidebar-bottom{display:none}.topbar{padding:15px 18px}.content{padding:16px 10px}.filters{padding:14px}.filters .field,.filters .field.search,.filters .field.sort{min-width:calc(50% - 6px)}.pagination{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar"><div class="brand"><img src="images/ISU.png" alt="ISU"><div><h2>HM SYSTEM</h2><p>Admin Portal</p></div></div><?php require __DIR__ . "/admin_nav.php"; ?>
<div class="sidebar-bottom"><div class="admin-mini"><div class="avatar"><?= e(strtoupper(substr($adminName,0,1))) ?></div><div><strong><?= e($adminName) ?></strong><small>Administrator</small></div></div><a class="logout" href="logout.php">Logout</a></div></aside>
<main class="main"><header class="topbar"><div><h1>Borrowing Tools</h1><p>Manage the catalog students use to request equipment</p></div><span class="stock"><?= number_format($totalTools) ?> tools</span></header>
<div class="content">
<?php if ($message !== ""): ?><div class="message <?= e($messageType) ?>" role="status"><?= e($message) ?></div><?php endif; ?>
<div class="grid">
<section class="card" id="tool-form"><div class="card-head"><h2><?= (int) $formData["id"] > 0 ? "Edit borrowing tool" : "Create borrowing tool" ?></h2><p>Changes and photos are shared with the student borrowing catalog.</p></div>
<form class="form-body" method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="form_action" value="save"><input type="hidden" name="equipment_id" value="<?= (int) $formData["id"] ?>">
<div class="field"><label for="name">Tool name</label><input id="name" name="name" maxlength="150" required value="<?= e($formData["name"]) ?>" placeholder="e.g. Chef's knife"></div>
<div class="field"><label for="category">Category</label><input id="category" name="category" list="categories" maxlength="120" required value="<?= e($formData["category"]) ?>" placeholder="e.g. Cutting Tools"><datalist id="categories"><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?php endforeach; ?></datalist></div>
<div class="field"><label for="total_quantity">Total inventory quantity</label><input id="total_quantity" type="number" name="total_quantity" min="0" max="4294967295" step="1" required value="<?= e($formData["total_quantity"]) ?>"></div>
<div class="field"><label for="photo">Tool photo <span style="font-weight:400;color:var(--muted)">(JPG, PNG, or WebP; max 5 MB)</span></label><input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp"><?php if (!empty($formData["photo_path"])): ?><div style="margin-top:10px"><img src="<?= e($formData["photo_path"]) ?>" alt="Current tool photo" style="width:100px;height:75px;object-fit:cover;border-radius:8px"><small style="display:block;color:var(--muted)">Choose a new photo to replace this one.</small></div><?php endif; ?></div>
<div class="form-actions"><button class="btn" type="submit"><?= (int) $formData["id"] > 0 ? "Save changes" : "Create tool" ?></button><?php if ((int) $formData["id"] > 0): ?><a class="btn secondary" href="borrowing_tools.php#tool-form">Cancel edit</a><?php endif; ?></div>
</form></section>
<section class="card"><div class="card-head"><h2>Equipment catalog</h2><p>Filter and manage tools stored in the shared MySQL inventory.</p></div>
<form class="filters" method="GET" action="borrowing_tools.php"><div class="field search"><label for="q">Search tools</label><input id="q" type="search" name="q" value="<?= e($search) ?>" placeholder="Name or category"></div>
<div class="field"><label for="category-filter">Category</label><select id="category-filter" name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= $categoryFilter === $category ? "selected" : "" ?>><?= e($category) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="stock">Stock status</label><select id="stock" name="stock"><option value="all" <?= $stockFilter === "all" ? "selected" : "" ?>>All stock</option><option value="available" <?= $stockFilter === "available" ? "selected" : "" ?>>In stock</option><option value="out" <?= $stockFilter === "out" ? "selected" : "" ?>>Out of stock</option></select></div>
<div class="field sort"><label for="sort">Sort by</label><select id="sort" name="sort"><option value="name_asc" <?= $sort === "name_asc" ? "selected" : "" ?>>Name A–Z</option><option value="name_desc" <?= $sort === "name_desc" ? "selected" : "" ?>>Name Z–A</option><option value="category" <?= $sort === "category" ? "selected" : "" ?>>Category</option><option value="stock_low" <?= $sort === "stock_low" ? "selected" : "" ?>>Lowest stock</option><option value="stock_high" <?= $sort === "stock_high" ? "selected" : "" ?>>Highest stock</option><option value="recent" <?= $sort === "recent" ? "selected" : "" ?>>Recently updated</option></select></div><button class="btn" type="submit">Apply filters</button><a class="btn secondary" href="borrowing_tools.php">Clear</a></form>
<div class="table-wrap"><table><thead><tr><th>TOOL</th><th>CATEGORY</th><th>AVAILABLE / TOTAL</th><th>REQUEST HISTORY</th><th>UPDATED</th><th>ACTIONS</th></tr></thead><tbody>
<?php if (!$tools): ?><tr><td class="empty" colspan="6">No tools match these filters.</td></tr><?php else: foreach ($tools as $tool): ?><tr><td><strong><?= e($tool["name"]) ?></strong></td><td><?= e($tool["category"]) ?></td><td><span class="stock <?= (int) $tool["available_quantity"] === 0 ? "out" : "" ?>"><?= (int) $tool["available_quantity"] ?> / <?= (int) $tool["total_quantity"] ?></span></td><td class="linked"><?= number_format((int) $tool["request_count"]) ?> requests</td><td><?= e(date("M j, Y", strtotime($tool["updated_at"]))) ?></td><td><div class="row-actions"><a class="btn secondary" href="<?= e("borrowing_tools.php?" . http_build_query(array_merge($_GET, ["edit" => (int) $tool["id"]]))) ?>#tool-form">Edit</a><form method="POST" onsubmit="return confirm('Delete <?= e(addslashes($tool["name"])) ?> from the catalog?');"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="form_action" value="delete"><input type="hidden" name="equipment_id" value="<?= (int) $tool["id"] ?>"><button class="btn danger" type="submit" <?= (int) $tool["request_count"] > 0 ? "disabled title=\"This tool has borrowing history and cannot be deleted.\"" : "" ?>>Delete</button></form></div></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
<div class="pagination"><span>Showing <?= number_format($firstTool) ?>–<?= number_format($lastTool) ?> of <?= number_format($totalTools) ?> tools</span><div class="pages"><a class="<?= $page <= 1 ? "disabled" : "" ?>" href="<?= e(tools_page_url(max(1,$page-1))) ?>">‹ Previous</a><a class="<?= $page >= $totalPages ? "disabled" : "" ?>" href="<?= e(tools_page_url(min($totalPages,$page+1))) ?>">Next ›</a></div></div>
</section>
</div></div></main></div>
</body>
</html>
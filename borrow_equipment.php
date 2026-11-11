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

$message = "";
$errorMsg = "";

// Handle adding equipment to the cart from the modal.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_to_cart"])) {
    $equipmentId = (int) $_POST["equipment_id"];
    $quantity = (int) $_POST["quantity"];

    try {
        $checkStmt = $pdo->prepare("SELECT id, name, GREATEST(total_quantity - COALESCE((SELECT SUM(br.quantity) FROM borrowing_requests br WHERE br.equipment_id = equipment.id AND br.status = 'approved'), 0), 0) AS available_quantity FROM equipment WHERE id = ?");
        $checkStmt->execute([$equipmentId]);
        $itemData = $checkStmt->fetch();

        if ($itemData && $quantity > 0) {
            $availableStock = (int) $itemData["available_quantity"];
            $currentInCart = $_SESSION['borrow_cart'][$equipmentId]['quantity'] ?? 0;
            
            if (($currentInCart + $quantity) <= $availableStock) {
                $_SESSION['borrow_cart'][$equipmentId] = [
                    "id" => $equipmentId,
                    "name" => $itemData["name"],
                    "quantity" => $currentInCart + $quantity,
                    "stock" => $availableStock
                ];
                log_activity($pdo, $userId, "Added item to cart", $itemData["name"] . " × " . ($currentInCart + $quantity));
                $message = "Item successfully added to your cart.";
            } else {
                $errorMsg = "The requested quantity exceeds the available stock.";
            }
        }
    } catch (PDOException $ex) {
        $errorMsg = "Error: " . $ex->getMessage();
    }
}

$cartCount = count($_SESSION['borrow_cart']);
$categoryImages = ["Cooking Tools"=>"images/Cooking Tools.png","Measuring and Mixing"=>"images/Measuring and Mixing.png","Cutting Tools"=>"images/Cutting Tools.png","Pots and Pans"=>"images/Pots and Pans.png","Baking and Pastry Equipment"=>"images/Baking and Pastry.png","Food Preparation Equipment"=>"images/Food Preparation.png","Food Storage and Preparation Containers"=>"images/Food Storage.png","Kitchen Safety and Cleaning Equipment"=>"images/Kitchen Safety.png"];
$catalogSearch = trim((string) ($_GET["q"] ?? ""));
$catalogCategory = trim((string) ($_GET["category"] ?? ""));
$availabilityFilter = (string) ($_GET["availability"] ?? "all");
if (!in_array($availabilityFilter, ["all", "available", "out"], true)) { $availabilityFilter = "all"; }
$availableStockSql = "GREATEST(e.total_quantity - COALESCE((SELECT SUM(br.quantity) FROM borrowing_requests br WHERE br.equipment_id = e.id AND br.status = 'approved'), 0), 0)";
$categoryOptions = $categories = [];
$catalogTotal = 0; $catalogPage = max(1, (int) ($_GET["page"] ?? 1)); $catalogPerPage = 12; $catalogTotalPages = 1; $catalogFirst = $catalogLast = 0;
$catalogWhere = []; $catalogParams = [];
try {
    $categoryOptions = $pdo->query("SELECT DISTINCT category FROM equipment ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    if ($catalogCategory !== "" && !in_array($catalogCategory, $categoryOptions, true)) { $catalogCategory = ""; }
    if ($catalogSearch !== "") { $catalogWhere[] = "(e.name LIKE ? OR e.category LIKE ?)"; $catalogParams[] = "%" . $catalogSearch . "%"; $catalogParams[] = "%" . $catalogSearch . "%"; }
    if ($catalogCategory !== "") { $catalogWhere[] = "e.category = ?"; $catalogParams[] = $catalogCategory; }
    if ($availabilityFilter === "available") { $catalogWhere[] = "{$availableStockSql} > 0"; }
    if ($availabilityFilter === "out") { $catalogWhere[] = "{$availableStockSql} = 0"; }
    $catalogWhereSql = $catalogWhere ? " WHERE " . implode(" AND ", $catalogWhere) : "";
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM equipment e{$catalogWhereSql}"); $countStmt->execute($catalogParams); $catalogTotal = (int) $countStmt->fetchColumn();
    $catalogTotalPages = max(1, (int) ceil($catalogTotal / $catalogPerPage)); $catalogPage = min($catalogPage, $catalogTotalPages); $catalogOffset = ($catalogPage - 1) * $catalogPerPage;
    $catalogStmt = $pdo->prepare("SELECT e.id, e.name, e.category, e.photo_path, e.total_quantity, {$availableStockSql} AS available_quantity FROM equipment e{$catalogWhereSql} ORDER BY e.category, e.name LIMIT {$catalogPerPage} OFFSET {$catalogOffset}");
    $catalogStmt->execute($catalogParams); $equipmentRows = $catalogStmt->fetchAll();
    $catalogFirst = $catalogTotal === 0 ? 0 : $catalogOffset + 1; $catalogLast = min($catalogOffset + $catalogPerPage, $catalogTotal);
    foreach ($equipmentRows as $equipment) { $categoryName = $equipment["category"]; if (!isset($categories[$categoryName])) { $categories[$categoryName] = ["name"=>$categoryName,"image"=>$categoryImages[$categoryName] ?? "images/Food Preparation.png","items"=>[]]; } $categories[$categoryName]["items"][] = $equipment; }
    $categories = array_values($categories);
} catch (PDOException $e) { error_log("Borrow equipment catalog query error: " . $e->getMessage()); $categories = []; }
function catalog_page_url(int $targetPage): string { $query = $_GET; unset($query["page"]); $query["page"] = $targetPage; return "borrow_equipment.php?" . http_build_query($query); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Borrow Equipment | HM System</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
body { font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); background: var(--bg-main); }
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
.avatar { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; background: var(--accent-light); color: var(--primary); font-weight: 700; flex-shrink: 0; }
.logout { display: block; text-align: center; color: var(--white); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 11px; font-size: 13px; font-weight: 600; margin-top: 12px; transition: 0.2s; }
.logout:hover { background: rgba(255, 255, 255, 0.1); }

.main { flex: 1; min-width: 0; }
.topbar { height: 80px; background: var(--white); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 40px; }
.content { max-width: 1350px; padding: 35px 40px; margin: auto; }
.welcome { margin-bottom: 35px; background: var(--white); padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); }

.category { margin-bottom: 45px; }
.category-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px; }
.count { background: var(--accent-light); color: var(--primary); padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.category-card { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 25px; background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; box-shadow: var(--shadow); }
.category-image { width: 100%; height: 100%; min-height: 240px; max-height: 320px; object-fit: cover; border-radius: 12px; background: var(--accent-light); }
.items-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.item { background: #fafbf9; border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; min-height: 135px; gap: 10px; }
.item-photo { width:100%; height:120px; object-fit:cover; border-radius:8px; background:var(--accent-light); margin-bottom:10px; }
.item-name { font-size: 13px; font-weight: 600; color: #2c382f; }
.item-stock { font-size: 11px; font-weight: 600; color: var(--text-muted); }
.borrow-btn { width: 100%; border: 0; border-radius: 8px; background: var(--accent-light); color: var(--primary); padding: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: 0.2s; }
.borrow-btn:hover { background: var(--primary); color: var(--white); }

.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); place-items: center; z-index: 1000; }
.modal-content { background: #fff; padding: 30px; border-radius: 16px; width: 400px; max-width: 90%; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: var(--text-muted); }
.form-group input { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.btn-submit { background: var(--primary); color: #fff; border: none; padding: 10px 15px; border-radius: 8px; font-weight: 700; cursor: pointer; flex: 1; }
.btn-cancel { background: #e2e8f0; color: #333; border: none; padding: 10px 15px; border-radius: 8px; font-weight: 700; cursor: pointer; }

.footer { padding: 30px; text-align: center; background: var(--white); border-top: 1px solid var(--border-color); margin-top: 50px; display: flex; flex-direction: column; align-items: center; gap: 15px; border-radius: 16px; }
.footer-logos { display: flex; align-items: center; justify-content: center; gap: 20px; }
.footer-logos img { height: 50px; width: auto; object-fit: contain; }
.footer-text { font-size: 12px; color: var(--text-muted); font-weight: 500; }
.catalog-filters{display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;padding:18px;margin:0 0 28px;background:#fff;border:1px solid var(--border-color);border-radius:14px;box-shadow:var(--shadow)}.catalog-filter{display:flex;flex-direction:column;gap:6px;min-width:155px;flex:1}.catalog-filter.search{min-width:220px;flex:1.6}.catalog-filter label{font-size:11px;font-weight:700;color:var(--text-muted)}.catalog-filter input,.catalog-filter select{height:40px;padding:0 11px;border:1px solid var(--border-color);border-radius:8px;background:#fff;color:var(--text-main);font:inherit;font-size:12px}.catalog-filter button,.catalog-clear{height:40px;padding:0 14px;border:0;border-radius:8px;background:var(--primary);color:#fff;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.catalog-clear{border:1px solid var(--border-color);background:#fff;color:var(--primary)}.catalog-summary{margin:-12px 2px 22px;color:var(--text-muted);font-size:12px;font-weight:600}.catalog-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;margin-top:18px;background:#fff;border:1px solid var(--border-color);border-radius:12px;color:var(--text-muted);font-size:12px}.catalog-pages{display:flex;gap:5px;align-items:center}.catalog-pages a{border:1px solid var(--border-color);border-radius:6px;padding:7px 9px;color:var(--primary);font-weight:600}.catalog-pages a.current{background:var(--primary);color:#fff}.catalog-pages a.disabled{color:#aab5ab;pointer-events:none}.borrow-btn:disabled{opacity:.55;cursor:not-allowed}@media(max-width:700px){.catalog-filters{padding:14px}.catalog-filter,.catalog-filter.search{min-width:calc(50% - 6px)}.catalog-pagination{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <img src="images/ISU.png" alt="ISU Logo" class="brand-icon-img">
            <div>
                <h2>HM SYSTEM</h2>
                <p>Borrowing Portal</p>
            </div>
        </div>

        <div class="nav-label">STUDENT MENU</div>

        <a class="nav-link" href="student_dashboard.php">Dashboard</a>
        <a class="nav-link active" href="borrow_equipment.php">Borrow Equipment</a>
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
                <h1>Kitchen Equipment Catalog</h1>
                <p>Hospitality Management System</p>
            </div>
            <div style="background:var(--accent-light); color:var(--primary); border-radius:20px; padding:8px 14px; font-size:12px; font-weight:700;">Equipment Selection</div>
        </header>

        <div class="content">
            <?php if ($message): ?>
                <div style="background:var(--accent-light); color:var(--primary); padding:12px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <div style="background:#fde8e7; color:#a22c2c; padding:12px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= e($errorMsg) ?></div>
            <?php endif; ?>

            <section class="welcome">
                <h2 style="font-size: 26px; color: var(--primary); margin-bottom: 8px;">Kitchen Tools and Equipment</h2>
                <p style="font-size: 14px; color: var(--text-muted);">Select items and add them to your cart before entering the borrowing details.</p>
            </section>
<form class="catalog-filters" method="GET" action="borrow_equipment.php"><div class="catalog-filter search"><label for="catalog-search">Search equipment</label><input id="catalog-search" type="search" name="q" value="<?= e($catalogSearch) ?>" placeholder="Search by tool name or category"></div><div class="catalog-filter"><label for="catalog-category">Category</label><select id="catalog-category" name="category"><option value="">All categories</option><?php foreach ($categoryOptions as $option): ?><option value="<?= e($option) ?>" <?= $catalogCategory === $option ? "selected" : "" ?>><?= e($option) ?></option><?php endforeach; ?></select></div><div class="catalog-filter"><label for="catalog-availability">Availability</label><select id="catalog-availability" name="availability"><option value="all" <?= $availabilityFilter === "all" ? "selected" : "" ?>>All equipment</option><option value="available" <?= $availabilityFilter === "available" ? "selected" : "" ?>>Available now</option><option value="out" <?= $availabilityFilter === "out" ? "selected" : "" ?>>Out of stock</option></select></div><button type="submit">Apply filters</button><a class="catalog-clear" href="borrow_equipment.php">Clear</a></form>
<div class="catalog-summary">Showing <?= number_format($catalogFirst) ?>–<?= number_format($catalogLast) ?> of <?= number_format($catalogTotal) ?> tools</div>

            <?php if (empty($categories)): ?><section class="category"><p>No equipment matches your search and filters.</p></section><?php endif; ?>
            <?php foreach ($categories as $index => $category): ?>
                <section class="category">
                    <div class="category-heading">
                        <h2 style="font-size: 19px; color: var(--primary);"><?= ($index + 1) ?>. <?= e($category["name"]) ?></h2>
                        <span class="count"><?= count($category["items"]) ?> tools listed</span>
                    </div>

                    <div class="category-card">
                        <img class="category-image" src="<?= e($category["image"]) ?>" alt="<?= e($category["name"]) ?>" loading="lazy">
                        <div class="items-grid">
                            <?php foreach ($category["items"] as $item): ?>
                                <?php $itemName = $item["name"]; $eqId = (int) $item["id"]; $stockVal = (int) $item["available_quantity"]; ?>
                                <article class="item">
                                    <div>
                                        <?php if (!empty($item["photo_path"])): ?><img class="item-photo" src="<?= e($item["photo_path"]) ?>" alt="<?= e($itemName) ?>" loading="lazy"><?php endif; ?><div class="item-name"><?= e($itemName) ?></div>
                                        <div class="item-stock"><?= $stockVal > 0 ? "Available Stock: " . $stockVal . " pcs" : "Currently unavailable" ?></div>
                                    </div>
                                    <button type="button" class="borrow-btn" <?= $stockVal < 1 ? "disabled" : "" ?> onclick="openModal(<?= $eqId ?>, <?= e(json_encode($itemName, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>, <?= $stockVal ?>)"><?= $stockVal > 0 ? "Add Item" : "Out of stock" ?></button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
<nav class="catalog-pagination" aria-label="Equipment pages"><span>Page <?= number_format($catalogPage) ?> of <?= number_format($catalogTotalPages) ?></span><div class="catalog-pages"><a class="<?= $catalogPage <= 1 ? "disabled" : "" ?>" href="<?= e(catalog_page_url(max(1, $catalogPage - 1))) ?>">‹ Previous</a><?php for ($pageNo = max(1, $catalogPage - 2); $pageNo <= min($catalogTotalPages, $catalogPage + 2); $pageNo++): ?><a class="<?= $pageNo === $catalogPage ? "current" : "" ?>" href="<?= e(catalog_page_url($pageNo)) ?>" <?= $pageNo === $catalogPage ? "aria-current=\"page\"" : "" ?>><?= $pageNo ?></a><?php endfor; ?><a class="<?= $catalogPage >= $catalogTotalPages ? "disabled" : "" ?>" href="<?= e(catalog_page_url(min($catalogTotalPages, $catalogPage + 1))) ?>">Next ›</a></div></nav>

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

<!-- Modal -->
<div class="modal" id="borrowModal">
    <div class="modal-content">
        <h3 id="modalTitle" style="color: var(--primary); margin-bottom: 15px;">Add to Cart</h3>
        <form method="POST">
            <input type="hidden" name="equipment_id" id="modalEquipmentId">
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" id="modalQuantity" value="1" min="1" required>
            </div>
            <div class="modal-actions">
                <button type="submit" name="add_to_cart" class="btn-submit">Add Item</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id, name, stock) {
    if (id === 0) { alert("This item is not yet available in the equipment list."); return; }
    document.getElementById('modalTitle').innerText = 'Add to Cart: ' + name;
    document.getElementById('modalEquipmentId').value = id;
    document.getElementById('modalQuantity').max = stock;
    document.getElementById('borrowModal').style.display = 'grid';
}
function closeModal() { document.getElementById('borrowModal').style.display = 'none'; }
</script>

</body>
</html>
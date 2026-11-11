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

$submitted = isset($_GET['submitted']);
$borrowRequests = [];

try {
    $stmt = $pdo->prepare("
        SELECT br.id, br.quantity, br.borrow_date, br.return_date, br.purpose, br.status, br.created_at, e.name AS equipment_name
        FROM borrowing_requests br
        INNER JOIN equipment e ON e.id = br.equipment_id
        WHERE br.user_id = ?
        ORDER BY br.id DESC
    ");
    $stmt->execute([$userId]);
    $borrowRequests = $stmt->fetchAll();
} catch (PDOException $ex) {
    // Handle error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Borrowing Requests | HM System</title>
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

.panel { background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow); }
.panel-header { padding: 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
.panel-header h3 { font-size: 18px; color: var(--primary); font-weight: 700; }
.table-wrap { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 750px; }
th { background: #fafbf9; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; text-align: left; padding: 16px 25px; }
td { padding: 18px 25px; border-top: 1px solid var(--border-color); font-size: 13px; vertical-align: middle; font-weight: 500; }
.pill { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.pill.pending { background: #fff3d8; color: #885b00; }
.pill.approved { background: #e5f4e7; color: #216b30; }
.pill.rejected { background: #fde8e7; color: #a22c2c; }
.btn-print { background: var(--accent-light); color: var(--primary); display: inline-block; padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; text-decoration: none; }
.btn-print:hover { background: var(--primary); color: #fff; }

.footer { padding: 30px; text-align: center; background: var(--white); border-top: 1px solid var(--border-color); margin-top: 50px; display: flex; flex-direction: column; align-items: center; gap: 15px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02); }
.footer-logos { display: flex; align-items: center; justify-content: center; gap: 20px; }
.footer-logos img { height: 50px; width: auto; object-fit: contain; }
.footer-text { font-size: 12px; color: var(--text-muted); font-weight: 500; }
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

        <a class="nav-link" href="student_dashboard.php">Dashboard</a>
        <a class="nav-link" href="borrow_equipment.php">Borrow Equipment</a>
        <a class="nav-link" href="cart.php">
            <span>View Item</span>
            <?php if ($cartCount > 0): ?>
                <span class="cart-badge-count"><?= $cartCount ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link active" href="my_borrowings.php">My Borrowing Requests</a>

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
                <h1>My Borrowing Requests</h1>
                <p>Hospitality Management System</p>
            </div>
            <div style="background:var(--accent-light); padding:8px 14px; border-radius:20px; font-weight:700; color:var(--primary); font-size: 12px;">Active Student</div>
        </header>

        <div class="content">
            <?php if ($submitted): ?>
                <div style="background:var(--accent-light); border:1px solid #c2dec4; color:var(--primary); padding:16px; border-radius:12px; margin-bottom:25px; font-weight:600;">Your borrowing requests were submitted successfully.</div>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-header">
                    <h3>All Your Requests</h3>
                </div>
                <div class="table-wrap">
                    <table>
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
                        <?php if (empty($borrowRequests)): ?>
                            <tr><td colspan="6" style="padding:45px; text-align:center; color:var(--text-muted);">No borrowing requests found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($borrowRequests as $req): ?>
                                <tr>
                                    <td><strong><?= e($req["equipment_name"]) ?></strong></td>
                                    <td><?= (int) $req["quantity"] ?></td>
                                    <td><?= e(date("M j, Y", strtotime($req["borrow_date"]))) ?> to <?= e(date("M j, Y", strtotime($req["return_date"]))) ?></td>
                                    <td><?= e($req["purpose"]) ?></td>
                                    <td><span class="pill <?= e($req["status"]) ?>"><?= e($req["status"]) ?></span></td>
                                    <td>
                                        <?php if ($req["status"] === 'approved'): ?>
                                            <a href="print_receipt.php?id=<?= (int)$req["id"] ?>" target="_blank" class="btn-print">Print Receipt</a>
                                        <?php else: ?>
                                            <span style="font-size:12px; color:var(--text-muted); font-weight:600;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

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

<script src="js/table-pagination.js" defer></script>
</body>
</html>
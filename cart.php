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

$errorMsg = "";

// Remove an item from the cart.
if (isset($_GET['remove'])) {
    $removeId = (int) $_GET['remove'];
    if (isset($_SESSION['borrow_cart'][$removeId])) {
        $removedItem = $_SESSION['borrow_cart'][$removeId];
        log_activity($pdo, $userId, "Removed item from cart", $removedItem['name'] . " removed from the borrowing cart.");
        unset($_SESSION['borrow_cart'][$removeId]);
    }
    header("Location: cart.php");
    exit;
}

// Submit all cart items to the database.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_all_requests"])) {
    $borrowDate = $_POST["borrow_date"];
    $returnDate = $_POST["return_date"];
    $purpose = trim($_POST["purpose"]);

    $validDates = $borrowDate !== '' && $returnDate !== ''
        && strtotime($borrowDate) !== false
        && strtotime($returnDate) !== false
        && $returnDate >= $borrowDate;

    if (!empty($_SESSION['borrow_cart']) && $validDates && $purpose !== '') {
        try {
            $pdo->beginTransaction();

            // Recheck stock at submission time so stale cart quantities cannot be submitted.
            $stockStmt = $pdo->prepare('SELECT total_quantity FROM equipment WHERE id = ? FOR UPDATE');
            $approvedStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM borrowing_requests WHERE equipment_id = ? AND status = 'approved'");
            foreach ($_SESSION['borrow_cart'] as $cartItem) {
                $equipmentId = (int) $cartItem['id'];
                $stockStmt->execute([$equipmentId]);
                $totalStock = $stockStmt->fetchColumn();
                $approvedStmt->execute([$equipmentId]);
                $availableStock = $totalStock === false ? 0 : max(0, (int) $totalStock - (int) $approvedStmt->fetchColumn());
                if ($totalStock === false || (int) $cartItem['quantity'] < 1 || (int) $cartItem['quantity'] > $availableStock) {
                    throw new RuntimeException('The requested quantity exceeds currently available stock. Please update your cart.');
                }
            }

            $insertReq = $pdo->prepare("
                INSERT INTO borrowing_requests (user_id, equipment_id, quantity, borrow_date, return_date, purpose, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");

            foreach ($_SESSION['borrow_cart'] as $cartItem) {
                $insertReq->execute([
                    $userId,
                    $cartItem['id'],
                    $cartItem['quantity'],
                    $borrowDate,
                    $returnDate,
                    $purpose
                ]);
            }

            $pdo->commit();
            $submittedItems = array_map(static fn($item) => $item['name'] . " × " . (int) $item['quantity'], array_values($_SESSION['borrow_cart']));
            log_activity($pdo, $userId, "Borrowing request submitted", implode("; ", $submittedItems));
            
            // Clear the cart after submission.
            $_SESSION['borrow_cart'] = [];

            header("Location: my_borrowings.php?submitted=1");
            exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Borrow cart submission error: ' . $ex->getMessage());
            $errorMsg = $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'The request could not be submitted. Please try again.';
        }
    } else {
        $errorMsg = "Complete all fields and make sure the borrow and return dates are valid.";
    }
}

$cartCount = count($_SESSION['borrow_cart']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Borrowing Cart | HM System</title>
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

.content { max-width: 1000px; padding: 35px 40px; margin: auto; }

.cart-panel { background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; box-shadow: var(--shadow); }
.cart-panel h2 { color: var(--primary); font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.cart-panel p { color: var(--text-muted); font-size: 13px; font-weight: 500; margin-bottom: 25px; }

.cart-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
.cart-table th, .cart-table td { padding: 14px 18px; border-bottom: 1px solid var(--border-color); font-size: 13px; text-align: left; }
.cart-table th { background: #fafbf9; color: var(--text-muted); font-size: 11px; text-transform: uppercase; font-weight: 700; }
.cart-table td { font-weight: 500; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-muted); }
.form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 13px; font-family: inherit; font-weight: 500; outline: none; }
.form-group input:focus, .form-group textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30, 70, 32, 0.1); }

.btn-submit { background: var(--primary); color: #fff; border: none; padding: 14px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; font-size: 14px; transition: 0.2s; }
.btn-submit:hover { background: var(--primary-dark); }

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
        <a class="nav-link active" href="cart.php">
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
                <h1>My Borrowing Cart</h1>
                <p>Hospitality Management System</p>
            </div>
            <div style="background:var(--accent-light); padding:8px 14px; border-radius:20px; font-weight:700; color:var(--primary); font-size: 12px;">Active Student</div>
        </header>

        <div class="content">
            <?php if ($errorMsg): ?>
                <div style="background:#fde8e7; color:#a22c2c; padding:12px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= e($errorMsg) ?></div>
            <?php endif; ?>

            <section class="cart-panel">
                <h2>🛒 Selected Equipment for Request</h2>
                <p>Review the selected items below and enter the borrowing details before submitting.</p>

                <?php if (empty($_SESSION['borrow_cart'])): ?>
                    <div style="text-align:center; padding:40px; color:var(--text-muted); font-size:13px;">
                        Your cart is empty. <br><br>
                        <a href="borrow_equipment.php" style="background:var(--primary); color:#fff; padding:10px 20px; border-radius:8px; font-weight:700; font-size:13px;">Browse Equipment</a>
                    </div>
                <?php else: ?>
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Quantity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['borrow_cart'] as $cartItem): ?>
                                <tr>
                                    <td><strong><?= e($cartItem['name']) ?></strong></td>
                                    <td><?= (int)$cartItem['quantity'] ?> pcs</td>
                                    <td><a href="cart.php?remove=<?= $cartItem['id'] ?>" style="color:#a22c2c; font-weight:700; font-size:12px;">Remove</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Borrow Date</label>
                                <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Return Date</label>
                                <input type="date" name="return_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Purpose</label>
                            <textarea name="purpose" rows="3" placeholder="Hal. Cooking Laboratory Task" required></textarea>
                        </div>

                        <button type="submit" name="submit_all_requests" class="btn-submit">Submit All Requests Now</button>
                    </form>
                <?php endif; ?>
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
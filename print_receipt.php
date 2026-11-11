<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

require_once "db.php";

$requestId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$userId = (int) $_SESSION["user_id"];
$userRole = $_SESSION["user_role"] ?? "";

try {
    // Retrieve the request details and ensure students can only access their own requests.
    $sql = "
        SELECT 
            br.id, br.user_id, br.quantity, br.borrow_date, br.return_date, br.purpose, br.status, br.created_at,
            u.name AS student_name, u.email AS student_email,
            e.name AS equipment_name
        FROM borrowing_requests br
        INNER JOIN users u ON u.id = br.user_id
        INNER JOIN equipment e ON e.id = br.equipment_id
        WHERE br.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$requestId]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        http_response_code(404);
        exit("Receipt not found.");
    }

    if ($userRole === 'student' && (int)$receipt['user_id'] !== $userId) {
        http_response_code(403);
        exit("Unauthorized access to this receipt.");
    }

    if (!in_array($userRole, ['student', 'admin'], true)) {
        http_response_code(403);
        exit("Unauthorized access.");
    }
} catch (PDOException $e) {
    exit("Database error: " . $e->getMessage());
}

if (!$receipt) {
    exit("Receipt not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Equipment Borrowing Receipt #<?= $receipt['id'] ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: #f4f7f4;
        color: #1f2923;
        padding: 40px;
        display: flex;
        justify-content: center;
    }
    .receipt-card {
        background: #ffffff;
        width: 100%;
        max-width: 600px;
        padding: 40px;
        border-radius: 16px;
        border: 1px solid #d1ded2;
        box-shadow: 0 6px 20px rgba(30, 70, 32, 0.05);
    }
    .receipt-header {
        text-align: center;
        border-bottom: 2px solid #e8f2e9;
        padding-bottom: 20px;
        margin-bottom: 25px;
    }
    .receipt-header h2 {
        color: #1e4620;
        font-size: 22px;
        font-weight: 700;
    }
    .receipt-header p {
        color: #5e6c62;
        font-size: 13px;
        margin-top: 4px;
    }
    .receipt-info {
        margin-bottom: 25px;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px dashed #e8f2e9;
        font-size: 14px;
    }
    .info-row span:first-child {
        color: #5e6c62;
        font-weight: 600;
    }
    .info-row span:last-child {
        color: #1f2923;
        font-weight: 700;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        background: #e5f4e7;
        color: #216b30;
        text-transform: uppercase;
    }
    .print-actions {
        margin-top: 30px;
        text-align: center;
    }
    .btn-print {
        background: #1e4620;
        color: #ffffff;
        border: none;
        padding: 12px 25px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-print:hover {
        background: #153216;
    }
    @media print {
        body { background: #ffffff; padding: 0; }
        .receipt-card { border: none; box-shadow: none; padding: 0; max-width: 100%; }
        .print-actions { display: none; }
    }
</style>
</head>
<body>

<div class="receipt-card">
    <div class="receipt-header">
        <h2>HM SYSTEM - BORROWING RECEIPT</h2>
        <p>Hospitality Management Equipment Portal</p>
    </div>

    <div class="receipt-info">
        <div class="info-row">
            <span>Transaction ID:</span>
            <span>#<?= $receipt['id'] ?></span>
        </div>
        <div class="info-row">
            <span>Student Name:</span>
            <span><?= htmlspecialchars($receipt['student_name']) ?></span>
        </div>
        <div class="info-row">
            <span>Student Email:</span>
            <span><?= htmlspecialchars($receipt['student_email']) ?></span>
        </div>
        <div class="info-row">
            <span>Equipment Borrowed:</span>
            <span><?= htmlspecialchars($receipt['equipment_name']) ?></span>
        </div>
        <div class="info-row">
            <span>Quantity:</span>
            <span><?= (int)$receipt['quantity'] ?> pcs</span>
        </div>
        <div class="info-row">
            <span>Borrow Period:</span>
            <span><?= date("M j, Y", strtotime($receipt['borrow_date'])) ?> to <?= date("M j, Y", strtotime($receipt['return_date'])) ?></span>
        </div>
        <div class="info-row">
            <span>Purpose / Task:</span>
            <span><?= htmlspecialchars($receipt['purpose']) ?></span>
        </div>
        <div class="info-row">
            <span>Status:</span>
            <span><span class="status-badge"><?= htmlspecialchars($receipt['status']) ?></span></span>
        </div>
        <div class="info-row">
            <span>Date Requested:</span>
            <span><?= date("M j, Y h:i A", strtotime($receipt['created_at'])) ?></span>
        </div>
    </div>

    <div style="font-size: 11px; color: #5e6c62; text-align: center; margin-top: 20px; line-height: 1.5;">
        * Please bring this printed receipt when collecting the equipment from the laboratory custodian.
    </div>

    <div class="print-actions">
        <button type="button" class="btn-print" onclick="window.print()">Print / Save as PDF</button>
    </div>
</div>

</body>
</html>
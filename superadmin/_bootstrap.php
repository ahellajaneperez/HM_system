<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["user_role"] ?? "") !== "superadmin"
) {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . "/../db.php";

$roleCheck = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$roleCheck->execute([(int) $_SESSION["user_id"]]);
if ($roleCheck->fetchColumn() !== "superadmin") {
    unset($_SESSION["user_id"], $_SESSION["user_name"], $_SESSION["user_role"]);
    header("Location: ../index.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$superAdminName = (string) ($_SESSION["user_name"] ?? "SuperAdmin");
$csrfToken = (string) $_SESSION["csrf_token"];

function superadmin_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function superadmin_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}

function superadmin_page_url(string $pageFile, int $page): string
{
    $query = $_GET;
    unset($query["page"], $query["success"]);
    $query["page"] = $page;
    return $pageFile . "?" . http_build_query($query);
}

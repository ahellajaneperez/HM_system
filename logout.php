
<?php
// Start or resume the current session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "db.php";
if (isset($_SESSION["user_id"])) {
    log_activity($pdo, (int) $_SESSION["user_id"], "Signed out", ($_SESSION["user_name"] ?? "User") . " signed out.");
}

// Clear all session data
$_SESSION = [];

// Remove the session cookie from the browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: index.php");
exit;
?>

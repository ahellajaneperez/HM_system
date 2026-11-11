
<?php
session_start();
require_once "db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";
$role = $_POST["role"] ?? "";

if (!in_array($role, ["admin", "student"], true)) {
    $_SESSION["login_error"] = "Please select a valid role.";
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, name, email, password, role
     FROM users WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

if (
    $user &&
    password_verify($password, $user["password"]) &&
    $user["role"] === $role
) {
    session_regenerate_id(true);

    $_SESSION["user_id"] = $user["id"];
    $_SESSION["user_name"] = $user["name"];
    $_SESSION["user_role"] = $user["role"];
    log_activity($pdo, (int) $user["id"], "Signed in", ucfirst($user["role"]) . " account signed in.");

    if ($user["role"] === "admin") {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: student_dashboard.php");
    }
    exit;
}

log_activity($pdo, null, "Failed sign-in", "Invalid credentials or account type.");
$_SESSION["login_error"] =
    "Invalid email, password, or account type.";

header("Location: index.php");
exit;
?>

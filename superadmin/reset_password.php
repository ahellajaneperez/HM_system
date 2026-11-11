<?php
require __DIR__ . "/_bootstrap.php";

$pageTitle = "Reset Passwords";
$pageSubtitle = "Set a new password for a student or administrator";
$activePage = "passwords";
$message = "";
$messageType = "success";

if (isset($_GET["success"]) && $_GET["success"] === "reset") {
    $message = "The password was updated successfully.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST["csrf_token"] ?? ""))) {
            throw new RuntimeException("Your session expired. Refresh the page and try again.");
        }
        $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        $password = (string) ($_POST["password"] ?? "");
        $confirmPassword = (string) ($_POST["confirm_password"] ?? "");
        if (!$userId || $userId < 1) {
            throw new RuntimeException("Choose a valid user account.");
        }
        if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new RuntimeException("Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.");
        }
        if (!hash_equals($password, $confirmPassword)) {
            throw new RuntimeException("The password confirmation does not match.");
        }

        $pdo->beginTransaction();
        $targetStmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? FOR UPDATE");
        $targetStmt->execute([$userId]);
        $target = $targetStmt->fetch();
        if (!$target || $target["role"] === "superadmin") {
            throw new RuntimeException("SuperAdmin passwords cannot be reset from this page.");
        }
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        log_activity($pdo, (int) $_SESSION["user_id"], "User password reset", "Reset the password for " . $target["name"] . " (" . $target["role"] . ").");
        $pdo->commit();
        header("Location: reset_password.php?success=reset");
        exit;
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        $messageType = "error";
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("SuperAdmin password reset error: " . $exception->getMessage());
        $message = "The password could not be updated. Please try again.";
        $messageType = "error";
    }
}

$search = trim((string) ($_GET["q"] ?? ""));
$roleFilter = (string) ($_GET["role"] ?? "");
$where = ["u.role <> 'superadmin'"];
$params = [];
if ($search !== "") {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term);
}
if (in_array($roleFilter, ["student", "admin"], true)) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
} else {
    $roleFilter = "";
}
$whereSql = " WHERE " . implode(" AND ", $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u" . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageNumber = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($pageNumber - 1) * $perPage;
$listStmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.student_number, u.role, u.created_at
     FROM users u{$whereSql}
     ORDER BY u.created_at DESC, u.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$users = $listStmt->fetchAll();
$firstRow = $totalRows === 0 ? 0 : $offset + 1;
$lastRow = min($offset + $perPage, $totalRows);

require __DIR__ . "/_header.php";
?>
<?php if ($message !== ""): ?><div class="notice <?= $messageType === "error" ? "error" : "" ?>" role="status"><?= superadmin_e($message) ?></div><?php endif; ?>
<section class="panel filter-panel">
    <div class="panel-heading"><div><h2>Account password resets</h2><p><?= number_format($totalRows) ?> eligible account<?= $totalRows === 1 ? "" : "s" ?> · SuperAdmin accounts are protected</p></div></div>
    <form class="filters" method="get" action="reset_password.php">
        <div class="field search"><label for="q">Search accounts</label><input id="q" name="q" type="search" value="<?= superadmin_e($search) ?>" placeholder="Name, email or student number"></div>
        <div class="field"><label for="role">Role</label><select id="role" name="role"><option value="">All eligible roles</option><option value="student" <?= $roleFilter === "student" ? "selected" : "" ?>>Student</option><option value="admin" <?= $roleFilter === "admin" ? "selected" : "" ?>>Admin</option></select></div>
        <button class="button" type="submit">Apply filters</button>
        <a class="button secondary" href="reset_password.php">Clear</a>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Account</th><th>Role</th><th>Created</th><th>Set new password</th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td class="empty-table" colspan="4">No accounts match these filters.</td></tr>
            <?php else: foreach ($users as $user): ?>
                <tr>
                    <td><span class="user-name"><?= superadmin_e($user["name"]) ?></span><span class="subtext"><?= superadmin_e($user["email"]) ?><?= $user["student_number"] ? " · " . superadmin_e($user["student_number"]) : "" ?></span></td>
                    <td><span class="role-badge"><?= superadmin_e($user["role"]) ?></span></td>
                    <td><?= superadmin_e(date("M j, Y", strtotime($user["created_at"]))) ?></td>
                    <td>
                        <form class="reset-box" method="post" action="reset_password.php">
                            <input type="hidden" name="csrf_token" value="<?= superadmin_e($csrfToken) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>">
                            <div class="field"><label for="password-<?= (int) $user["id"] ?>">New password</label><input id="password-<?= (int) $user["id"] ?>" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
                            <div class="field"><label for="confirm-<?= (int) $user["id"] ?>">Confirm</label><input id="confirm-<?= (int) $user["id"] ?>" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
                            <button class="button" type="submit">Reset</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="pagination-row">
        <span>Showing <?= number_format($firstRow) ?>–<?= number_format($lastRow) ?> of <?= number_format($totalRows) ?></span>
        <nav class="pages" aria-label="Password reset table pages">
            <a class="<?= $pageNumber <= 1 ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("reset_password.php", max(1, $pageNumber - 1))) ?>" aria-label="Previous page">‹</a>
            <?php for ($page = 1; $page <= $totalPages; $page++): ?><a class="<?= $page === $pageNumber ? "current" : "" ?>" href="<?= superadmin_e(superadmin_page_url("reset_password.php", $page)) ?>" <?= $page === $pageNumber ? 'aria-current="page"' : "" ?>><?= $page ?></a><?php endfor; ?>
            <a class="<?= $pageNumber >= $totalPages ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("reset_password.php", min($totalPages, $pageNumber + 1))) ?>" aria-label="Next page">›</a>
        </nav>
    </div>
</section>
<?php require __DIR__ . "/_footer.php"; ?>

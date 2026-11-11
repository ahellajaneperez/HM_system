<?php
require __DIR__ . "/_bootstrap.php";

$pageTitle = "User Management";
$pageSubtitle = "Search accounts, update profiles, and manage roles";
$activePage = "users";
$message = "";
$messageType = "success";

if (isset($_GET["success"])) {
    $successMessages = [
        "updated" => "Account changes were saved.",
        "deleted" => "The account was deleted.",
    ];
    $message = $successMessages[(string) $_GET["success"]] ?? "";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST["csrf_token"] ?? ""))) {
            throw new RuntimeException("Your session expired. Refresh the page and try again.");
        }

        $action = (string) ($_POST["action"] ?? "");
        $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            throw new RuntimeException("Choose a valid user account.");
        }

        $pdo->beginTransaction();
        $targetStmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? FOR UPDATE");
        $targetStmt->execute([$userId]);
        $target = $targetStmt->fetch();
        if (!$target || $target["role"] === "superadmin") {
            throw new RuntimeException("SuperAdmin accounts cannot be changed from User Management.");
        }

        if ($action === "update") {
            $name = trim((string) ($_POST["name"] ?? ""));
            $email = trim((string) ($_POST["email"] ?? ""));
            $phone = preg_replace('/[\s()-]/', '', trim((string) ($_POST["phone_number"] ?? "")));
            $role = (string) ($_POST["role"] ?? "");
            $studentNumber = trim((string) ($_POST["student_number"] ?? ""));
            $course = trim((string) ($_POST["course"] ?? ""));
            $section = trim((string) ($_POST["section"] ?? ""));
            $studentNumberDigits = preg_replace('/[\s-]/', '', $studentNumber);
            if (preg_match('/^[0-9]{7}$/', $studentNumberDigits)) {
                $studentNumber = substr($studentNumberDigits, 0, 2) . "-" . substr($studentNumberDigits, 2);
            }

            if ($name === "" || mb_strlen($name) > 100) {
                throw new RuntimeException("Enter a name of 1 to 100 characters.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                throw new RuntimeException("Enter a valid email address.");
            }
            if (mb_strlen($phone) > 20 || mb_strlen($studentNumber) > 50 || mb_strlen($course) > 150 || mb_strlen($section) > 80) {
                throw new RuntimeException("One or more profile fields exceed the allowed length.");
            }
            if (!in_array($role, ["admin", "student"], true)) {
                throw new RuntimeException("Choose Student or Admin as the account role.");
            }
            if ($phone !== "" && !preg_match('/^(?:09[0-9]{9}|\+639[0-9]{9})$/', $phone)) {
                throw new RuntimeException("Enter a phone number starting with 09 or +639, using digits only.");
            }
            if ($role === "student" && $phone === "") {
                throw new RuntimeException("Student accounts must have a phone number.");
            }
            if ($studentNumber !== "" && !preg_match('/^[0-9]{2}-[0-9]{5}$/', $studentNumber)) {
                throw new RuntimeException("Enter a 7-digit student number; the hyphen is added automatically.");
            }
            if ($role === "student" && $studentNumber === "") {
                throw new RuntimeException("Student accounts must have a student number.");
            }
            $validSections = [];
            foreach (range(1, 4) as $year) {
                foreach (["A", "B", "C"] as $letter) {
                    $validSections[] = "Year {$year} — {$letter}";
                }
            }
            if ($role === "student" && ($course !== "Bachelor of Science in Hospitality Management" || !in_array($section, $validSections, true))) {
                throw new RuntimeException("Choose a valid Hospitality Management course and year section.");
            }

            $update = $pdo->prepare(
                "UPDATE users
                 SET name = ?, email = ?, phone_number = ?, role = ?, student_number = NULLIF(?, ''),
                     course = NULLIF(?, ''), section = NULLIF(?, '')
                 WHERE id = ?"
            );
            $update->execute([$name, $email, $phone, $role, $studentNumber, $course, $section, $userId]);
            log_activity($pdo, (int) $_SESSION["user_id"], "User account updated", "Updated " . $name . " (" . $role . ").");
            $pdo->commit();
            header("Location: users.php?success=updated");
            exit;
        }

        if ($action === "delete") {
            $delete = $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'superadmin'");
            $delete->execute([$userId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException("The account could not be deleted.");
            }
            log_activity($pdo, (int) $_SESSION["user_id"], "User account deleted", "Deleted " . $target["name"] . " (" . $target["role"] . ").");
            $pdo->commit();
            header("Location: users.php?success=deleted");
            exit;
        }

        throw new RuntimeException("Choose a valid account action.");
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
        error_log("SuperAdmin user management error: " . $exception->getMessage());
        $message = $exception->getCode() === "23000"
            ? "That email or student number is already in use, or this account has linked records and cannot be deleted."
            : "The account change could not be completed. Please try again.";
        $messageType = "error";
    }
}

$search = trim((string) ($_GET["q"] ?? ""));
$roleFilter = (string) ($_GET["role"] ?? "");
$fromDate = trim((string) ($_GET["from"] ?? ""));
$toDate = trim((string) ($_GET["to"] ?? ""));
$where = [];
$params = [];
if ($search !== "") {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ? OR u.phone_number LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term, $term);
}
if (in_array($roleFilter, ["student", "admin", "superadmin"], true)) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
} else {
    $roleFilter = "";
}
if ($fromDate !== "") {
    if (superadmin_valid_date($fromDate)) {
        $where[] = "u.created_at >= ?";
        $params[] = $fromDate . " 00:00:00";
    } else {
        $message = "The start date is invalid.";
        $messageType = "error";
        $fromDate = "";
    }
}
if ($toDate !== "") {
    if (superadmin_valid_date($toDate)) {
        $where[] = "u.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $toDate;
    } else {
        $message = "The end date is invalid.";
        $messageType = "error";
        $toDate = "";
    }
}
$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u" . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$perPage = 10;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageNumber = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($pageNumber - 1) * $perPage;
$listStmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.phone_number, u.student_number, u.course, u.section, u.role, u.created_at
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
    <div class="panel-heading"><div><h2>Accounts</h2><p><?= number_format($totalRows) ?> matching account<?= $totalRows === 1 ? "" : "s" ?></p></div><a href="../register.php?type=admin">Create administrator</a></div>
    <form class="filters" method="get" action="users.php">
        <div class="field search"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= superadmin_e($search) ?>" placeholder="Name, email, student no. or phone"></div>
        <div class="field"><label for="role">Role</label><select id="role" name="role"><option value="">All roles</option><option value="student" <?= $roleFilter === "student" ? "selected" : "" ?>>Student</option><option value="admin" <?= $roleFilter === "admin" ? "selected" : "" ?>>Admin</option><option value="superadmin" <?= $roleFilter === "superadmin" ? "selected" : "" ?>>SuperAdmin</option></select></div>
        <div class="field"><label for="from">Created from</label><input id="from" name="from" type="date" value="<?= superadmin_e($fromDate) ?>"></div>
        <div class="field"><label for="to">Created to</label><input id="to" name="to" type="date" value="<?= superadmin_e($toDate) ?>"></div>
        <button class="button" type="submit">Apply filters</button>
        <a class="button secondary" href="users.php">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Account</th><th>Role</th><th>Phone</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td class="empty-table" colspan="5">No accounts match these filters.</td></tr>
            <?php else: foreach ($users as $user): ?>
                <tr>
                    <td><span class="user-name"><?= superadmin_e($user["name"]) ?></span><span class="subtext"><?= superadmin_e($user["email"]) ?></span>
                        <?php if ($user["student_number"]): ?><span class="subtext"><?= superadmin_e($user["student_number"]) ?><?= $user["course"] ? " · " . superadmin_e($user["course"]) : "" ?><?= $user["section"] ? " · " . superadmin_e($user["section"]) : "" ?></span><?php endif; ?>
                    </td>
                    <td><span class="role-badge <?= $user["role"] === "superadmin" ? "superadmin" : "" ?>"><?= superadmin_e($user["role"]) ?></span></td>
                    <td><?= superadmin_e($user["phone_number"] ?: "—") ?></td>
                    <td><?= superadmin_e(date("M j, Y", strtotime($user["created_at"]))) ?></td>
                    <td>
                        <?php if ($user["role"] !== "superadmin"): ?>
                            <div class="row-actions">
                                <details>
                                    <summary class="button secondary">Edit</summary>
                                    <form class="edit-popover" method="post" action="users.php">
                                        <input type="hidden" name="csrf_token" value="<?= superadmin_e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>">
                                        <div class="field"><label>Name</label><input name="name" maxlength="100" required value="<?= superadmin_e($user["name"]) ?>"></div>
                                        <div class="field"><label>Email</label><input name="email" type="email" maxlength="150" required value="<?= superadmin_e($user["email"]) ?>"></div>
                                        <div class="field"><label>Phone<?= $user["role"] === "student" ? " *" : " (optional)" ?></label><input name="phone_number" type="tel" inputmode="text" maxlength="13" pattern="(?:09[0-9]{9}|\+639[0-9]{9})" placeholder="09123456789 or +639123456789" value="<?= superadmin_e($user["phone_number"]) ?>" <?= $user["role"] === "student" ? "required" : "" ?>></div>
                                        <div class="field"><label>Role</label><select name="role"><option value="student" <?= $user["role"] === "student" ? "selected" : "" ?>>Student</option><option value="admin" <?= $user["role"] === "admin" ? "selected" : "" ?>>Admin</option></select></div>
                                        <div class="field"><label>Student number<?= $user["role"] === "student" ? " *" : " (optional)" ?></label><input name="student_number" maxlength="8" pattern="[0-9]{2}-?[0-9]{5}" placeholder="23-12345" value="<?= superadmin_e($user["student_number"]) ?>" <?= $user["role"] === "student" ? "required" : "" ?>></div>
                                        <div class="field"><label>Course</label><input name="course" maxlength="150" value="<?= superadmin_e($user["course"]) ?>"></div>
                                        <div class="field"><label>Section</label><input name="section" maxlength="80" value="<?= superadmin_e($user["section"]) ?>"></div>
                                        <button class="button" type="submit">Save changes</button>
                                    </form>
                                </details>
                                <form method="post" action="users.php" onsubmit="return confirm('Delete this account? Accounts with borrowing records cannot be deleted.');">
                                    <input type="hidden" name="csrf_token" value="<?= superadmin_e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>">
                                    <button class="button danger" type="submit">Delete</button>
                                </form>
                            </div>
                        <?php else: ?><span class="subtext">Protected account</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="pagination-row">
        <span>Showing <?= number_format($firstRow) ?>–<?= number_format($lastRow) ?> of <?= number_format($totalRows) ?></span>
        <nav class="pages" aria-label="User table pages">
            <a class="<?= $pageNumber <= 1 ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("users.php", max(1, $pageNumber - 1))) ?>" aria-label="Previous page">‹</a>
            <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                <a class="<?= $page === $pageNumber ? "current" : "" ?>" href="<?= superadmin_e(superadmin_page_url("users.php", $page)) ?>" <?= $page === $pageNumber ? 'aria-current="page"' : "" ?>><?= $page ?></a>
            <?php endfor; ?>
            <a class="<?= $pageNumber >= $totalPages ? "disabled" : "" ?>" href="<?= superadmin_e(superadmin_page_url("users.php", min($totalPages, $pageNumber + 1))) ?>" aria-label="Next page">›</a>
        </nav>
    </div>
</section>
<?php require __DIR__ . "/_footer.php"; ?>

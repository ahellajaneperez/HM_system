<?php
session_start();
require_once "db.php";

$message = "";
$accountType = (string) ($_GET["type"] ?? $_POST["account_type"] ?? "student");
if (!in_array($accountType, ["student", "admin"], true)) {
    $accountType = "student";
}
$isSuperAdmin = isset($_SESSION["user_id"]) && ($_SESSION["user_role"] ?? "") === "superadmin";
$isAdmin = isset($_SESSION["user_id"]) && in_array($_SESSION["user_role"] ?? "", ["admin", "superadmin"], true);
$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn();
$canCreateAdmin = $isSuperAdmin || $adminCount < 2;
$adminCreated = $accountType === "admin" && isset($_GET["created"]);
$adminName = "";
$adminEmail = "";
$adminPhone = "";
if ($accountType === "admin" && $canCreateAdmin && empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$courseName = "Bachelor of Science in Hospitality Management";
$sectionOptions = [];
foreach (range(1, 4) as $year) { foreach (["A", "B", "C"] as $letter) { $sectionOptions[] = "Year {$year} — {$letter}"; } }

if ($_SERVER["REQUEST_METHOD"] === "POST" && $accountType === "admin") {
    if (!$canCreateAdmin) {
        $message = "The first two administrator accounts can register here. After that, only a signed-in administrator can create accounts.";
    } elseif (
        empty($_SESSION["csrf_token"])
        || !hash_equals($_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))
    ) {
        http_response_code(403);
        $message = "Your form session expired. Refresh the page and try again.";
    } else {
        $adminName = trim($_POST["name"] ?? "");
        $adminEmail = trim($_POST["email"] ?? "");
        $adminPhoneInput = trim($_POST["phone_number"] ?? "");
        $adminPhone = preg_replace('/[\s()-]/', '', $adminPhoneInput);
        $adminPassword = $_POST["password"] ?? "";
        $adminConfirm = $_POST["confirm_password"] ?? "";
        $emailDomain = strtolower(substr($adminEmail, (int) strrpos($adminEmail, "@") + 1));
        $placeholderDomains = ["example.com", "example.org", "example.net", "sample.com"];
        $isPlaceholderEmail = preg_match('/\.(?:example|invalid|test|localhost)$/', $emailDomain) === 1;
        foreach ($placeholderDomains as $placeholderDomain) {
            if ($emailDomain === $placeholderDomain || str_ends_with($emailDomain, "." . $placeholderDomain)) {
                $isPlaceholderEmail = true;
                break;
            }
        }

        if ($adminName === "" || strlen($adminName) > 100) {
            $message = "Enter a name of up to 100 characters.";
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 150) {
            $message = "Enter a valid email address of up to 150 characters.";
        } elseif ($isPlaceholderEmail) {
            $message = "Please use a real email address. Example and sample email domains are not allowed.";
        } elseif ($adminPhone !== "" && !preg_match('/^(?:09[0-9]{9}|\+639[0-9]{9})$/', $adminPhone)) {
            $message = "Enter an 11-digit number starting with 09 or a Philippine number in +639 format.";
        } elseif (
            strlen($adminPassword) < 8
            || !preg_match('/[A-Z]/', $adminPassword)
            || !preg_match('/[a-z]/', $adminPassword)
            || !preg_match('/[0-9]/', $adminPassword)
            || !preg_match('/[^A-Za-z0-9]/', $adminPassword)
        ) {
            $message = "Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.";
        } elseif ($adminPassword !== $adminConfirm) {
            $message = "Passwords do not match.";
        } else {
            $lockAcquired = false;
            $adminAccountCreated = false;
            try {
                $lockAcquired = (int) $pdo->query(
                    "SELECT GET_LOCK('hm_system_admin_registration', 10)"
                )->fetchColumn() === 1;
                if (!$lockAcquired) {
                    $message = "Administrator registration is busy. Please try again.";
                } elseif (!$isSuperAdmin && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn() >= 2) {
                    $message = "The first two administrator accounts have already been created. Only the SuperAdmin can create another administrator.";
                } else {
                    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn();
                    $newAdminRole = $adminCount === 0 ? "superadmin" : "admin";
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (name, email, password, role, phone_number)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $adminName,
                        $adminEmail,
                        password_hash($adminPassword, PASSWORD_DEFAULT),
                        $newAdminRole,
                        $adminPhone !== "" ? $adminPhone : null,
                    ]);

                    $createdAdminId = (int) $pdo->lastInsertId();
                    log_activity(
                        $pdo,
                        $isAdmin ? (int) $_SESSION["user_id"] : $createdAdminId,
                        "Administrator created",
                        $adminName . " created " . $newAdminRole . " account #" . $createdAdminId . "."
                    );
                    $adminAccountCreated = true;
                }
            } catch (PDOException $e) {
                error_log("Administrator registration failed: " . $e->getMessage());
                $message = "Could not create the administrator account. The email may already be registered.";
            } finally {
                if ($lockAcquired) {
                    $pdo->query("SELECT RELEASE_LOCK('hm_system_admin_registration')");
                }
            }

            if ($adminAccountCreated) {
                header("Location: register.php?type=admin&created=1");
                exit;
            }

            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')")->fetchColumn();
            $canCreateAdmin = $isSuperAdmin || $adminCount < 2;
        }
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && $accountType === "student") {
    $name = trim($_POST["name"] ?? "");
    $studentNumberInput = trim($_POST["student_number"] ?? "");
    $studentNumberDigits = preg_replace('/[\s-]/', '', $studentNumberInput);
    $studentNumber = preg_match('/^[0-9]{7}$/', $studentNumberDigits)
        ? substr($studentNumberDigits, 0, 2) . '-' . substr($studentNumberDigits, 2)
        : $studentNumberInput;
    $phoneInput = trim($_POST["phone_number"] ?? "");
    $phoneNumber = preg_replace('/[\s()-]/', '', $phoneInput);
    $course = trim($_POST["course"] ?? "");
    $section = trim($_POST["section"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";
    $emailDomain = strtolower(substr($email, (int) strrpos($email, "@") + 1));
    $placeholderEmailDomains = ["example.com", "example.org", "example.net", "sample.com"];
    $isPlaceholderEmail = preg_match('/\.(?:example|invalid|test|localhost)$/', $emailDomain) === 1;
    foreach ($placeholderEmailDomains as $placeholderDomain) {
        if ($emailDomain === $placeholderDomain || str_ends_with($emailDomain, "." . $placeholderDomain)) {
            $isPlaceholderEmail = true;
            break;
        }
    }

    if ($name === "") {
        $message = "Please enter your name.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif ($isPlaceholderEmail) {
        $message = "Please use a real email address. Example and sample email domains are not allowed.";
    } elseif (!preg_match('/^[0-9]{2}-[0-9]{5}$/', $studentNumber)) {
        $message = "Enter 7 digits for your student number. The hyphen is added automatically (for example, 23-12345).";
    } elseif (!preg_match('/^(?:09[0-9]{9}|\+639[0-9]{9})$/', $phoneNumber)) {
        $message = "Enter an 11-digit number starting with 09 or a Philippine number in +639 format (for example, +639123456789).";
    } elseif ($course !== $courseName || !in_array($section, $sectionOptions, true)) {
        $message = "Select the Hospitality Management course and a valid section.";
    } elseif (
        strlen($password) < 8
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $message = "Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, student_number, phone_number, course, section)
                 VALUES (?, ?, ?, 'student', ?, ?, ?, ?)"
            );

            $stmt->execute([$name, $email, $hash, $studentNumber, $phoneNumber, $course, $section]);
            log_activity($pdo, (int) $pdo->lastInsertId(), "Account registered", $name . " created a student account.");

            header("Location: index.php?registered=1");
            exit;
        } catch (PDOException $e) {
            $message = "Registration failed. The email or student number may already be registered.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HM Borrowing System | Create Student Account</title>
    <style>
        :root {
            --primary-green: #1e4620;
            --primary-hover: #153216;
            --text-dark: #1b2e1d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background:
                linear-gradient(
                    120deg,
                    rgba(20, 48, 25, 0.75),
                    rgba(15, 35, 18, 0.55)
                ),
                url('images/isu.jpg') center / cover no-repeat fixed;
        }

        .main-wrapper {
            display: flex;
            width: 100%;
            max-width: 1120px;
            align-items: center;
            justify-content: space-between;
            gap: 50px;
        }

        /* KALIWA: DESCRIPTION TEXT */
        .info-section {
            color: #ffffff;
            max-width: 500px;
            background: rgba(15, 35, 18, 0.75);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        }

        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.18);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            letter-spacing: 0.5px;
        }

        .info-section h2 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.25;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
        }

        .info-section p {
            font-size: 15px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.9);
        }

        /* KANAN: REGISTER CARD CONTAINER (NAKA-BLUR OVERLAY) */
        .login-container {
            width: 100%;
            max-width: 560px;
            padding: 40px 35px;
            background: rgba(255, 255, 255, 0.55); 
            backdrop-filter: blur(14px); 
            -webkit-backdrop-filter: blur(14px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
            flex-shrink: 0;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-green);
            color: #ffffff;
            border-radius: 14px;
            font-size: 28px;
            box-shadow: 0 8px 15px rgba(30, 70, 32, 0.3);
        }

        .heading {
            text-align: center;
            margin-bottom: 22px;
        }

        .heading h1 {
            color: var(--primary-green);
            font-size: 22px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .heading p {
            color: #334155;
            font-size: 14px;
            font-weight: 500;
        }

        .account-type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; padding: 5px; margin: 0 0 20px; border: 1px solid rgba(203, 213, 225, .8); border-radius: 11px; background: rgba(241, 245, 249, .85); }
        .account-type-switch a { padding: 10px 8px; border-radius: 8px; color: #475569; font-size: 13px; font-weight: 700; text-align: center; text-decoration: none; }
        .account-type-switch a.active { background: #fff; color: var(--primary-green); box-shadow: 0 2px 8px rgba(15, 23, 42, .1); }
        .access-notice { margin-bottom: 16px; padding: 12px 14px; border: 1px solid #f1d7a8; border-radius: 9px; background: #fff8e9; color: #76520d; font-size: 13px; line-height: 1.55; }
        .access-notice a { color: var(--primary-green); font-weight: 700; }

        .student-info { border: 1px solid #d6dfed; border-radius: 14px; padding: 14px 16px 2px; margin: 4px 0 16px; }
        .student-info legend { color: #18315f; font-size: 14px; font-weight: 700; padding: 0 6px; }
        .student-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
        .student-grid select { width: 100%; padding: 11px 14px; border: 1px solid rgba(203, 213, 225, .9); border-radius: 10px; background: rgba(255,255,255,.9); color: var(--text-dark); font-size: 14px; }
        .student-grid select:focus { outline: none; border-color: var(--primary-green); box-shadow: 0 0 0 3px rgba(30,70,32,.15); }
        .required-mark { color: #b42318; }
        .account-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
        .form-group {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 13px;
        }

        input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid rgba(203, 213, 225, 0.8);
            border-radius: 10px;
            outline: none;
            background: rgba(255, 255, 255, 0.8); 
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s ease;
        }

        input:focus {
            border-color: var(--primary-green);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 3px rgba(30, 70, 32, 0.15);
        }

        .password-field {
            position: relative;
        }

        .password-field input {
            padding-right: 46px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            display: grid;
            place-items: center;
            width: 30px;
            height: 30px;
            padding: 0;
            transform: translateY(-50%);
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #475569;
            cursor: pointer;
        }

        .password-toggle:hover {
            background: rgba(30, 70, 32, 0.08);
            color: var(--primary-green);
        }

        .password-toggle:focus-visible {
            outline: 2px solid var(--primary-green);
            outline-offset: 2px;
        }

        .password-toggle svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .password-toggle [hidden] {
            display: none;
        }

        .password-hint {
            display: block;
            margin-top: 6px;
            color: #475569;
            font-size: 11px;
            line-height: 1.5;
        }

        .optional-label {
            display: inline;
            margin-left: 4px;
            color: #475569;
            font-size: 12px;
            font-weight: 400;
            white-space: nowrap;
        }

        .login-btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 10px;
            background: var(--primary-green);
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
            margin-top: 5px;
            box-shadow: 0 4px 10px rgba(30, 70, 32, 0.3);
        }

        .login-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .message {
            margin-bottom: 16px;
            padding: 10px 12px;
            color: #a52222;
            background: rgba(255, 240, 240, 0.85);
            border: 1px solid #ffcccc;
            border-radius: 8px;
            font-size: 13px;
        }

        .extra-links {
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
        }

        .extra-links a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .extra-links a:hover {
            text-decoration: underline;
            color: var(--primary-hover);
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            color: #334155;
            font-size: 12px;
            font-weight: 500;
        }

        @media (max-width: 900px) {
            .main-wrapper {
                flex-direction: column;
                gap: 25px;
            }

            .info-section {
                text-align: center;
                max-width: 100%;
                padding: 30px 20px;
            }

            .login-container {
                max-width: 560px;
            }
        }

        @media (max-width: 520px) {
            .student-grid,
            .account-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <div class="main-wrapper">

        <!-- KALIWA: DESCRIPTION TEXT -->
        <div class="info-section">
            <div class="badge">ISU SYSTEM PORTAL</div>
            <h2>Hospitality Management Borrowing System</h2>
            <p>A centralized portal for managing equipment,
                learning resources, and borrowing transactions.
                Please sign in to access your account.</p>
        </div>

        <!-- KANAN: REGISTER FORM CONTAINER (NAKA-BLUR OVERLAY) -->
        <div class="login-container">

            <div class="logo">📝</div>

            <div class="heading">
                <h1>Create Account</h1>
                <p><?= $accountType === "admin" ? "Register as an Administration" : "Register as a student" ?></p>
            </div>

            <nav class="account-type-switch" aria-label="Account registration type">
                <a href="register.php?type=student" class="<?= $accountType === "student" ? "active" : "" ?>" <?= $accountType === "student" ? 'aria-current="page"' : "" ?>>Student</a>
                <a href="register.php?type=admin" class="<?= $accountType === "admin" ? "active" : "" ?>" <?= $accountType === "admin" ? 'aria-current="page"' : "" ?>>Administration</a>
            </nav>

            <?php if ($accountType === "admin" && $adminCreated): ?>
                <div class="message" role="status" style="color:#1c633c;background:#edf8ef;border-color:#c9e8d0">
                    Administrator account created successfully.
                </div>
            <?php elseif ($accountType === "admin" && !$canCreateAdmin): ?>
                <div class="access-notice" role="status">
                    The first two administrator accounts can register here. After that, only the signed-in SuperAdmin can create accounts.
                    <a href="index.php">Sign in as the SuperAdmin</a>, then return here and choose Administration.
                </div>
            <?php endif; ?>

            <?php if ($message !== ""): ?>
                <div class="message">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($accountType === "student"): ?>
            <form method="POST">
                <input type="hidden" name="account_type" value="student">
                <fieldset class="student-info">
                    <legend>Student information</legend>
                    <div class="student-grid">
                        <div class="form-group"><label for="student_number">Student number <span class="required-mark">*</span></label><input type="text" id="student_number" name="student_number" placeholder="23-12345" maxlength="8" pattern="[0-9]{2}-[0-9]{5}" inputmode="numeric" title="Enter 7 digits; the hyphen is added automatically." required value="<?= htmlspecialchars($_POST["student_number"] ?? "", ENT_QUOTES, "UTF-8") ?>"></div>
                        <div class="form-group"><label for="course">Course <span class="required-mark">*</span></label><select id="course" name="course" required><option value="">Select course</option><option value="<?= htmlspecialchars($courseName, ENT_QUOTES, "UTF-8") ?>" <?= ($_POST["course"] ?? "") === $courseName ? "selected" : "" ?>>BSHM — Bachelor of Science in Hospitality Management</option></select></div>
                        <div class="form-group"><label for="section">Section <span class="required-mark">*</span></label><select id="section" name="section" required><option value="">Select year and section</option><?php foreach ($sectionOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, "UTF-8") ?>" <?= ($_POST["section"] ?? "") === $option ? "selected" : "" ?>>BSHM / <?= htmlspecialchars($option, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="phone_number">Phone number <span class="required-mark">*</span></label><input type="tel" id="phone_number" name="phone_number" placeholder="(09123456789 or +639123456789)" maxlength="13" pattern="(?:09[0-9]{9}|\+639[0-9]{9})" inputmode="text" title="Enter 11 digits starting with 09, or use +63 followed by 10 digits." aria-describedby="phone-hint" required value="<?= htmlspecialchars($_POST["phone_number"] ?? "", ENT_QUOTES, "UTF-8") ?>"><small class="password-hint" id="phone-hint">Use 11 digits starting with 09, or +63 followed by 10 digits.</small></div>
                    </div>
                </fieldset>
                <div class="account-grid">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" placeholder="Full name" maxlength="100" required value="<?= htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="Email address" maxlength="150" required value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" placeholder="Create a password" minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="Use at least 8 characters with uppercase and lowercase letters, a number, and a special character." aria-describedby="password-hint" required>
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle><path data-eye-slash d="m3 3 18 18"></path></svg>
                            </button>
                        </div>
                        <small class="password-hint" id="password-hint">At least 8 characters, with uppercase and lowercase letters, a number, and a special character.</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-field">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="confirm_password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle><path data-eye-slash d="m3 3 18 18"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="login-btn">Create Account</button>
            </form>
            <?php elseif ($canCreateAdmin): ?>
            <form method="POST">
                <input type="hidden" name="account_type" value="admin">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                <div class="account-grid">
                    <div class="form-group">
                        <label for="admin_name">Full Name</label>
                        <input type="text" id="admin_name" name="name" placeholder="Full name" maxlength="100" required value="<?= htmlspecialchars($adminName, ENT_QUOTES, "UTF-8") ?>">
                    </div>
                    <div class="form-group">
                        <label for="admin_email">Email Address</label>
                        <input type="email" id="admin_email" name="email" placeholder="Email address" maxlength="150" required value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, "UTF-8") ?>">
                    </div>
                    <div class="form-group">
                        <label for="admin_phone_number">Phone number <span class="optional-label">(optional)</span></label>
                        <input type="tel" id="admin_phone_number" name="phone_number" placeholder="09123456789 or +639123456789" maxlength="13" pattern="(?:09[0-9]{9}|\+639[0-9]{9})" inputmode="text" value="<?= htmlspecialchars($adminPhone, ENT_QUOTES, "UTF-8") ?>">
                    </div>
                    <div class="form-group">
                        <label for="admin_password">Password</label>
                        <div class="password-field">
                            <input type="password" id="admin_password" name="password" placeholder="Create a password" minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="Use at least 8 characters with uppercase and lowercase letters, a number, and a special character." aria-describedby="admin-password-hint" required>
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="admin_password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle><path data-eye-slash d="m3 3 18 18"></path></svg>
                            </button>
                        </div>
                        <small class="password-hint" id="admin-password-hint">At least 8 characters, including uppercase and lowercase letters, a number, and a special character.</small>
                    </div>
                    <div class="form-group">
                        <label for="admin_confirm_password">Confirm Password</label>
                        <div class="password-field">
                            <input type="password" id="admin_confirm_password" name="confirm_password" placeholder="Confirm password" required>
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="admin_confirm_password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle><path data-eye-slash d="m3 3 18 18"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="login-btn">Create Administration Account</button>
            </form>
            <?php endif; ?>

            <div class="extra-links">
                Already registered? <a href="index.php">Sign in</a>
            </div>

            <div class="footer">
                © 2026 Hospitality Management System
            </div>

        </div>

    </div>

<script>
    const studentNumberInput = document.getElementById("student_number");
    if (studentNumberInput) {
        const formatStudentNumber = () => {
            const digits = studentNumberInput.value.replace(/\D/g, "").slice(0, 7);
            studentNumberInput.value = digits.length > 2
                ? `${digits.slice(0, 2)}-${digits.slice(2)}`
                : digits;
        };

        studentNumberInput.addEventListener("input", formatStudentNumber);
        formatStudentNumber();
    }

    const phoneNumberInput = document.getElementById("phone_number");
    if (phoneNumberInput) {
        phoneNumberInput.addEventListener("input", () => {
            const cleaned = phoneNumberInput.value
                .replace(/[^\d+]/g, "")
                .replace(/(?!^)\+/g, "")
                .slice(0, 13);
            if (phoneNumberInput.value !== cleaned) {
                phoneNumberInput.value = cleaned;
            }
        });
    }

    const adminPhoneNumberInput = document.getElementById("admin_phone_number");
    if (adminPhoneNumberInput) {
        adminPhoneNumberInput.addEventListener("input", () => {
            adminPhoneNumberInput.value = adminPhoneNumberInput.value
                .replace(/[^\d+]/g, "")
                .replace(/(?!^)\+/g, "")
                .slice(0, 13);
        });
    }

    document.querySelectorAll(".password-toggle").forEach((toggle) => {
        const password = document.getElementById(toggle.getAttribute("aria-controls"));
        const eyeSlash = toggle.querySelector("[data-eye-slash]");
        const syncPasswordToggle = () => {
            const isVisible = password.type === "text";
            eyeSlash.setAttribute("visibility", isVisible ? "hidden" : "visible");
            toggle.setAttribute("aria-pressed", String(isVisible));
            toggle.setAttribute("aria-label", isVisible ? "Hide password" : "Show password");
        };

        syncPasswordToggle();
        toggle.addEventListener("click", () => {
            password.type = password.type === "text" ? "password" : "text";
            syncPasswordToggle();
        });
    });
</script>
</body>
</html>
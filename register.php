<?php
require_once "db.php";

$message = "";
$courseName = "Bachelor of Science in Hospitality Management";
$sectionOptions = [];
foreach (range(1, 4) as $year) { foreach (["A", "B", "C"] as $letter) { $sectionOptions[] = "Year {$year} — {$letter}"; } }

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $studentNumber = trim($_POST["student_number"] ?? "");
    $course = trim($_POST["course"] ?? "");
    $section = trim($_POST["section"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    if ($name === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid name and email.";
    } elseif ($studentNumber === "" || strlen($studentNumber) > 50) {
        $message = "Please enter a student number (up to 50 characters).";
    } elseif ($course !== $courseName || !in_array($section, $sectionOptions, true)) {
        $message = "Select the Hospitality Management course and a valid section.";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, student_number, course, section)
                 VALUES (?, ?, ?, 'student', ?, ?, ?)"
            );

            $stmt->execute([$name, $email, $hash, $studentNumber, $course, $section]);
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
            max-width: 1050px;
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
            max-width: 420px;
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

        .student-info { border: 1px solid #d6dfed; border-radius: 14px; padding: 14px 16px 2px; margin: 4px 0 16px; }
        .student-info legend { color: #18315f; font-size: 14px; font-weight: 700; padding: 0 6px; }
        .student-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
        .student-grid select { width: 100%; padding: 11px 14px; border: 1px solid rgba(203, 213, 225, .9); border-radius: 10px; background: rgba(255,255,255,.9); color: var(--text-dark); font-size: 14px; }
        .student-grid select:focus { outline: none; border-color: var(--primary-green); box-shadow: 0 0 0 3px rgba(30,70,32,.15); }
        .required-mark { color: #b42318; }
        @media (max-width: 520px) { .student-grid { grid-template-columns: 1fr; } }        .form-group {
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
                max-width: 100%;
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
                <p>Register as a student</p>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <fieldset class="student-info">
                    <legend>Student information</legend>
                    <div class="student-grid">
                        <div class="form-group"><label for="student_number">Student number <span class="required-mark">*</span></label><input type="text" id="student_number" name="student_number" placeholder="Your student number" maxlength="50" required value="<?= htmlspecialchars($_POST["student_number"] ?? "", ENT_QUOTES, "UTF-8") ?>"></div>
                        <div class="form-group"><label for="course">Course <span class="required-mark">*</span></label><select id="course" name="course" required><option value="">Select course</option><option value="<?= htmlspecialchars($courseName, ENT_QUOTES, "UTF-8") ?>" <?= ($_POST["course"] ?? "") === $courseName ? "selected" : "" ?>>BSHM — Bachelor of Science in Hospitality Management</option></select></div>
                        <div class="form-group"><label for="section">Section <span class="required-mark">*</span></label><select id="section" name="section" required><option value="">Select year and section</option><?php foreach ($sectionOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, "UTF-8") ?>" <?= ($_POST["section"] ?? "") === $option ? "selected" : "" ?>>BSHM / <?= htmlspecialchars($option, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></div>
                    </div>
                </fieldset>
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
                    <input type="password" id="password" name="password" placeholder="At least 8 characters" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                </div>

                <button type="submit" class="login-btn">Create Account</button>
            </form>

            <div class="extra-links">
                Already registered? <a href="index.php">Sign in</a>
            </div>

            <div class="footer">
                © 2026 Hospitality Management System
            </div>

        </div>

    </div>

</body>
</html>
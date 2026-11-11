<?php
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } else {
        $message = "Password recovery needs email verification. "
                 . "Please contact the system administrator for assistance.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HM Borrowing System | Forgot Password</title>

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

        /* KALIWA: INFO SECTION */
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

        /* KANAN: CARD CONTAINER (NAKA-BLUR OVERLAY) */
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
            margin-bottom: 25px;
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

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 13px;
        }

        input {
            width: 100%;
            padding: 12px 14px;
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

        .message-box {
            margin-bottom: 16px;
            padding: 10px 12px;
            color: #153216;
            background: rgba(230, 245, 232, 0.85);
            border: 1px solid #c2e0c4;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.4;
        }

        .extra-links {
            text-align: center;
            margin-top: 20px;
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
            margin-top: 22px;
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

        <!-- KANAN: FORGOT PASSWORD FORM (NAKA-BLUR OVERLAY) -->
        <div class="login-container">

            <div class="logo">🔒</div>

            <div class="heading">
                <h1>FORGOT PASSWORD</h1>
                <p>Enter your email to recover your account</p>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message-box">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Your registered email" required>
                </div>
                
                <button type="submit" class="login-btn">Continue</button>
            </form>

            <div class="extra-links">
                <a href="index.php">← Back to Login</a>
            </div>

            <div class="footer">
                © 2026 Hospitality Management System
            </div>

        </div>

    </div>

</body>
</html>
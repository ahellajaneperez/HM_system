<?php
session_start();

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
$registered = isset($_GET['registered']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173c2b">
    <meta name="description" content="The Hospitality Management Equipment Borrowing System helps students and administrators manage equipment requests, inventory, and returns.">
    <title>Hospitality Management | Equipment Borrowing System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--forest:#173c2b;--forest-deep:#102c20;--leaf:#b7d58a;--cream:#f7f7f0;--ink:#17251d;--muted:#65736a;--line:#e2e8df;--white:#fff}
        *{box-sizing:border-box}
        html{scroll-behavior:smooth;scroll-padding-top:88px}
        body{margin:0;background:var(--cream);color:var(--ink);font-family:'DM Sans',sans-serif;-webkit-font-smoothing:antialiased}
        a{color:inherit}
        .topbar{height:78px;padding:0 clamp(22px,6vw,92px);display:flex;align-items:center;justify-content:space-between;background:#fff;position:relative;z-index:2;border-bottom:1px solid rgba(23,60,43,.08)}
        .brand{display:flex;align-items:center;gap:12px;text-decoration:none;min-width:0}
        .brand img{width:46px;height:46px;border-radius:50%;object-fit:cover;border:1px solid #dbe4d7}
        .brand-copy{display:flex;flex-direction:column;gap:2px}.brand-copy strong{font:800 13px 'Manrope',sans-serif;letter-spacing:.07em;color:var(--forest)}.brand-copy span{font-size:11px;color:var(--muted)}
        .nav-links{display:flex;align-items:center;gap:30px}.nav-links>a:not(.nav-cta){font-size:13px;font-weight:600;text-decoration:none;color:#47574c}.nav-links>a:hover{color:var(--forest)}
        .nav-cta,.button{display:inline-flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:8px;background:var(--forest);color:#fff!important;text-decoration:none;font-weight:700;transition:transform .2s,background .2s,box-shadow .2s;box-shadow:0 7px 18px rgba(23,60,43,.16)}
        .nav-cta{padding:12px 18px;font-size:13px}.nav-cta:hover,.button:hover{background:#24563d;transform:translateY(-2px)}
        .hero{min-height:610px;padding:66px clamp(22px,8vw,128px) 78px;display:grid;grid-template-columns:minmax(0,1.12fr) minmax(360px,.72fr);align-items:center;gap:clamp(44px,8vw,120px);position:relative;isolation:isolate;overflow:hidden;background:linear-gradient(90deg,rgba(13,40,27,.94) 0%,rgba(15,48,32,.86) 48%,rgba(15,48,32,.66) 100%),url('images/isu.jpg') center 48%/cover no-repeat;color:#fff}
        .hero:after{content:"";position:absolute;z-index:-1;width:440px;height:440px;border:1px solid rgba(255,255,255,.12);border-radius:50%;right:36%;top:-255px;box-shadow:0 0 0 50px rgba(255,255,255,.025),0 0 0 100px rgba(255,255,255,.02)}
        .hero-copy{max-width:650px}.eyebrow{display:inline-flex;align-items:center;gap:9px;padding:8px 12px;border:1px solid rgba(226,242,212,.3);border-radius:99px;background:rgba(255,255,255,.08);color:#e0edcf;font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}.eyebrow:before{content:"";width:7px;height:7px;background:var(--leaf);border-radius:50%;box-shadow:0 0 0 4px rgba(183,213,138,.16)}
        h1,h2,h3,p{margin-top:0}.hero h1{max-width:650px;margin:22px 0 18px;font:800 clamp(38px,5.1vw,68px)/1.08 'Manrope',sans-serif;letter-spacing:-.055em}.hero h1 span{color:#c4df9b}.hero-copy>p{max-width:540px;margin-bottom:28px;color:rgba(255,255,255,.8);font-size:16px;line-height:1.8}
        .hero-actions{display:flex;align-items:center;flex-wrap:wrap;gap:12px}.button{padding:15px 20px;font-size:13px}.button-light{background:#fff;color:var(--forest)!important;box-shadow:none}.button-light:hover{background:#edf3e8;color:var(--forest)!important}.text-link{padding:12px 4px;text-decoration:none;font-size:13px;font-weight:700;color:#f4f7ee}.text-link span{margin-left:5px;color:var(--leaf)}
        .trust-line{display:flex;align-items:center;gap:12px;margin-top:38px;color:rgba(255,255,255,.72);font-size:12px}.trust-logos{display:flex;align-items:center}.trust-logos img{width:30px;height:30px;border-radius:50%;object-fit:cover;background:#fff;border:2px solid #244936;margin-right:-7px}.trust-line strong{color:#fff;font-weight:700}
        .login-card{width:100%;max-width:420px;justify-self:end;padding:30px;background:rgba(255,255,255,.97);color:var(--ink);border:1px solid rgba(255,255,255,.8);border-radius:15px;box-shadow:0 24px 70px rgba(3,21,12,.3)}
        .card-heading{display:flex;align-items:center;gap:14px;margin-bottom:22px}.card-mark{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;background:#edf4e7;color:var(--forest);font-size:21px}.card-heading h2{font:800 18px 'Manrope',sans-serif;margin:0 0 3px;letter-spacing:-.02em}.card-heading p{font-size:12px;color:var(--muted);margin:0}
        .notice{padding:11px 13px;margin:0 0 16px;border-radius:8px;font-size:12px;line-height:1.5}.notice.error{color:#9d2727;background:#fff0ef;border:1px solid #ffd3cf}.notice.success{color:#1c633c;background:#edf8ef;border:1px solid #c9e8d0}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:12px;font-weight:700;margin-bottom:7px;color:#34453a}.form-group input,.form-group select{width:100%;height:46px;padding:0 13px;border:1px solid #d7dfd5;border-radius:7px;background:#fff;color:var(--ink);font:500 13px 'DM Sans',sans-serif;outline:none;transition:border .2s,box-shadow .2s}.form-group input:focus,.form-group select:focus{border-color:#518064;box-shadow:0 0 0 3px rgba(61,119,82,.12)}
        .submit{width:100%;height:47px;margin-top:5px;cursor:pointer}.form-links{display:flex;justify-content:space-between;gap:10px;margin-top:15px;font-size:11px}.form-links a{color:var(--forest);font-weight:700;text-decoration:none}.form-links a:hover{text-decoration:underline}.card-foot{border-top:1px solid var(--line);margin-top:21px;padding-top:15px;color:#768178;font-size:10px;line-height:1.6}
        .section{padding:76px clamp(22px,8vw,128px)}.section-head{max-width:640px;margin:0 auto 34px;text-align:center}.section-kicker{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#557751}.section-head h2{margin:11px 0 10px;font:800 clamp(26px,3vw,38px)/1.2 'Manrope',sans-serif;letter-spacing:-.04em;color:var(--forest)}.section-head p{margin:0;color:var(--muted);font-size:14px;line-height:1.75}
        .feature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;max-width:1120px;margin:0 auto}.feature{padding:25px 24px 27px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 22px rgba(25,50,31,.035)}.feature-icon{width:44px;height:44px;display:grid;place-items:center;background:#eff5e9;border-radius:10px;color:var(--forest);font-size:20px;margin-bottom:20px}.feature h3{font:800 16px 'Manrope',sans-serif;margin:0 0 8px;color:#233a2b}.feature p{font-size:12px;line-height:1.8;color:var(--muted);margin:0}
        .process{padding-top:8px;padding-bottom:78px}.steps{max-width:1000px;margin:auto;display:grid;grid-template-columns:repeat(3,1fr);gap:26px}.step{display:flex;gap:14px}.step-num{flex:0 0 34px;height:34px;border-radius:50%;display:grid;place-items:center;background:var(--forest);color:#fff;font:800 13px 'Manrope',sans-serif}.step h3{font:800 14px 'Manrope',sans-serif;margin:2px 0 6px}.step p{font-size:12px;line-height:1.7;color:var(--muted);margin:0}
        .footer{padding:24px clamp(22px,8vw,128px);background:#102c20;color:rgba(255,255,255,.7);display:flex;justify-content:space-between;align-items:center;gap:20px;font-size:11px}.footer-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700}.footer-brand img{width:27px;height:27px;border-radius:50%;object-fit:cover;background:white}.footer a{color:#e0edcf;text-decoration:none}
        @media(max-width:900px){.hero{grid-template-columns:1fr minmax(320px,.85fr);gap:35px;padding-left:5vw;padding-right:5vw}.hero h1{font-size:clamp(38px,6vw,56px)}.login-card{padding:24px}.nav-links{gap:18px}}
        @media(max-width:700px){.topbar{height:68px;padding:0 20px}.brand img{width:40px;height:40px}.brand-copy strong{font-size:11px}.brand-copy span{font-size:10px}.nav-links>a:not(.nav-cta){display:none}.nav-cta{padding:10px 14px}.hero{grid-template-columns:1fr;padding:54px 22px 50px;gap:36px;background-position:58% center}.hero h1{font-size:clamp(39px,10vw,58px)}.hero-copy>p{font-size:14px}.login-card{justify-self:stretch;max-width:none}.trust-line{margin-top:26px}.section{padding:58px 22px}.feature-grid{grid-template-columns:1fr;gap:12px}.feature{padding:20px;display:grid;grid-template-columns:42px 1fr;column-gap:14px}.feature-icon{grid-row:span 2;margin:0;width:42px;height:42px}.feature h3{align-self:end}.steps{grid-template-columns:1fr;gap:20px}.process{padding-top:0;padding-bottom:58px}.footer{flex-direction:column;text-align:center;padding:22px}}
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*:before,*:after{transition:none!important}}
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="#top" aria-label="Hospitality Management home">
        <img src="images/Hm.jpg" alt="">
        <span class="brand-copy"><strong>HOSPITALITY MANAGEMENT</strong><span>Equipment Borrowing System</span></span>
    </a>
    <nav class="nav-links" aria-label="Main navigation">
        <a href="#features">What you can do</a>
        <a href="#how-it-works">How it works</a>
        <a class="nav-cta" href="#sign-in">Open the portal <span aria-hidden="true">↗</span></a>
    </nav>
</header>
<main id="top">
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-copy">
            <div class="eyebrow">Hospitality Management · ISU</div>
            <h1 id="hero-title">The right tools for <span>every practical.</span></h1>
            <p>A simple place for Hospitality Management students and administrators to find equipment, request what they need, and keep every return on record.</p>
            <div class="hero-actions">
                <a class="button button-light" href="#sign-in">Sign in to get started <span aria-hidden="true">→</span></a>
                <a class="text-link" href="register.php">New student? Create an account <span aria-hidden="true">↗</span></a>
            </div>
            <div class="trust-line"><span class="trust-logos"><img src="images/ISU.png" alt=""><img src="images/ccsict.jpg" alt=""><img src="images/Hm.jpg" alt=""></span><span>Built for <strong>students and administrators</strong></span></div>
        </div>
        <aside class="login-card" id="sign-in" aria-labelledby="signin-title">
            <div class="card-heading"><div class="card-mark" aria-hidden="true">⌂</div><div><h2 id="signin-title">Welcome back</h2><p>Sign in to your authorized account</p></div></div>
            <?php if ($registered): ?><div class="notice success" role="status">Your student account is ready. Sign in to continue.</div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form action="login.php" method="POST">
                <div class="form-group"><label for="role">Account type</label><select name="role" id="role" required><option value="">Choose account type</option><option value="admin">Administrator</option><option value="student">Student</option></select></div>
                <div class="form-group"><label for="email">Email address</label><input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="username" required></div>
                <div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required></div>
                <button type="submit" class="button submit">Sign in <span aria-hidden="true">→</span></button>
            </form>
            <div class="form-links"><a href="forgot_password.php">Forgot password?</a><a href="register.php">Create student account</a></div>
            <div class="card-foot">Use the account type assigned to your account. Students can create an account before signing in.</div>
        </aside>
    </section>
    <section class="section" id="features" aria-labelledby="features-title">
        <div class="section-head"><div class="section-kicker">One organized system</div><h2 id="features-title">From equipment request to return</h2><p>Keep the borrowing process clear for everyone, from checking what is available to confirming equipment has been returned.</p></div>
        <div class="feature-grid">
            <article class="feature"><div class="feature-icon" aria-hidden="true">▦</div><h3>Find equipment</h3><p>Browse the shared catalog and see the equipment available for Hospitality Management activities.</p></article>
            <article class="feature"><div class="feature-icon" aria-hidden="true">↗</div><h3>Track requests</h3><p>Submit borrowing requests and check their status from your student account.</p></article>
            <article class="feature"><div class="feature-icon" aria-hidden="true">✓</div><h3>Manage returns</h3><p>Administrators can review requests, manage inventory, and record equipment return condition.</p></article>
        </div>
    </section>
    <section class="section process" id="how-it-works" aria-labelledby="steps-title">
        <div class="section-head"><div class="section-kicker">A straightforward process</div><h2 id="steps-title">Ready when your class is</h2></div>
        <div class="steps">
            <article class="step"><div class="step-num">1</div><div><h3>Sign in or register</h3><p>Students can create an account; administrators sign in with their authorized account.</p></div></article>
            <article class="step"><div class="step-num">2</div><div><h3>Choose equipment</h3><p>Explore the catalog and send a request for the items needed for your activity.</p></div></article>
            <article class="step"><div class="step-num">3</div><div><h3>Follow the status</h3><p>Check your borrowings and return the equipment according to the request details.</p></div></article>
        </div>
    </section>
</main>
<footer class="footer"><div class="footer-brand"><img src="images/Hm.jpg" alt="">Hospitality Management Borrowing System</div><div>© <?= date('Y') ?> · <a href="#top">Back to top ↑</a></div></footer>
</body>
</html>

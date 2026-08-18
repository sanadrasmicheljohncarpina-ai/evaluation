<?php
// admin/admin_login.php
session_start();
require_once 'db.php';

// Already logged in → go straight to dashboard
if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin','superadmin','registrar'])) {
    header("Location: admin_dashboard.php");
    exit;
}

$error   = '';
$success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']     ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $mysqli->prepare(
            "SELECT id, full_name, email, password_hash, role, is_logged_in
             FROM users WHERE username = ? AND role IN ('admin','superadmin','registrar') AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            $upd = $mysqli->prepare("UPDATE users SET is_logged_in = 1 WHERE id = ?");
            $upd->bind_param("i", $user['id']);
            $upd->execute();
            $upd->close();

            $mysqli->close();
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $error = "Incorrect username or password. Please try again.";
        }
    }
    if ($mysqli->ping()) $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI Admin — Secure Access</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--blue-mid:#172A45;--blue-accent:#2B6CB0;--blue-hover:#4C78B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark-blue);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:24px;overflow-x:hidden;position:relative;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(43,108,176,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(43,108,176,.07) 1px,transparent 1px);background-size:48px 48px;animation:g 20s linear infinite;}
@keyframes g{0%{background-position:0 0}100%{background-position:48px 48px}}
.orb{position:fixed;border-radius:50%;filter:blur(80px);z-index:0;pointer-events:none;}
.orb-1{width:420px;height:420px;background:radial-gradient(circle,rgba(43,108,176,.25) 0%,transparent 70%);top:-100px;left:-100px;animation:o1 12s ease-in-out infinite;}
.orb-2{width:320px;height:320px;background:radial-gradient(circle,rgba(96,165,250,.15) 0%,transparent 70%);bottom:-80px;right:-80px;animation:o2 15s ease-in-out infinite;}
@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(40px,30px)}}
@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,-25px)}}
.login-card{position:relative;z-index:10;background:rgba(23,42,69,.82);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:40px 40px 32px;width:100%;max-width:420px;box-shadow:var(--shadow),0 0 0 1px rgba(43,108,176,.18);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:28px;}
.logo-img{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--blue-accent);box-shadow:0 0 20px rgba(43,108,176,.55);margin:0 auto 12px;display:block;}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:4px;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(43,108,176,.4),transparent);margin-bottom:24px;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.form-input{width:100%;padding:12px 13px 12px 38px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.form-input::placeholder{color:rgba(160,179,198,.4);}
.form-input:focus{border-color:var(--blue-accent);box-shadow:0 0 0 3px rgba(43,108,176,.2);}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0;transition:color .2s;}
.toggle-pw:hover{color:var(--light);}
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);color:#4ade80;}
.btn-main{width:100%;padding:13px;background:var(--blue-accent);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 14px rgba(43,108,176,.4);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-main:hover{background:var(--blue-hover);transform:translateY(-1px);}
.register-row{text-align:center;margin-top:16px;font-size:13px;color:var(--muted);}
.register-row a{color:var(--blue-hover);font-weight:600;text-decoration:none;}
.register-row a:hover{text-decoration:underline;}
.card-footer{text-align:center;font-size:12px;color:var(--muted);}
.card-footer a{color:var(--blue-hover);text-decoration:none;font-weight:600;}
.secure-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);margin-top:12px;}
.secure-badge i{color:#4ade80;font-size:10px;}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="login-card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"
             onerror="this.style.display='none'"/>
        <div class="card-title">Admin Access</div>
        <div class="card-subtitle">Pandan Bay Institute &mdash; Control Panel</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="admin_login.php" autocomplete="off">
        <div class="form-group">
            <label class="form-label">Username</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user f-icon"></i>
                <input class="form-input" type="text" name="username"
                       placeholder="Enter admin username" required autocomplete="off"/>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="lp" name="password"
                       placeholder="Enter your password" required autocomplete="new-password"/>
                <button type="button" class="toggle-pw" onclick="togglePw()">
                    <i class="fa-solid fa-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        
<div style="text-align:right; margin-top:-8px; margin-bottom:14px;">
    <a href="forgot_password.php" style="font-size:12px; color:var(--blue-hover); text-decoration:none; font-weight:600;">
        <i class="fa-solid fa-key"></i> Forgot Password?
    </a>
</div>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-shield-halved"></i> Sign In
        </button>
    </form>

    <div class="register-row">
        Don't have an account? <a href="admin_register.php">Register here</a>
    </div>
    <div class="card-footer">
            <span class="secure-badge"><i class="fa-solid fa-circle-check"></i> Secured &amp; Encrypted Connection</span>
    </div>
</div>

<script>
function togglePw() {
    const el = document.getElementById('lp'), ic = document.getElementById('eyeIcon');
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.className = el.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}
</script>
</body>
</html>
<?php
// student/reset_password.php
session_start();
require_once 'db.php';

$token = trim($_GET['token'] ?? '');
$error = $success = '';

$stmt = $mysqli->prepare(
    "SELECT pr.user_id, u.full_name FROM password_resets pr
     JOIN users u ON u.id = pr.user_id
     WHERE pr.token = ? LIMIT 1"
);
$stmt->bind_param("s", $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = "This reset link is invalid or has expired.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd  = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->bind_param("si", $hash, $row['user_id']);
        $upd->execute();
        $upd->close();

        $del = $mysqli->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $del->bind_param("i", $row['user_id']);
        $del->execute();
        $del->close();

        $_SESSION['reg_success'] = "Password reset successfully. Please sign in.";
        header("Location: student_login.php");
        exit;
    }
}
if ($mysqli->ping()) $mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--gold:#D97706;--gold-hover:#F59E0B;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark-blue);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:24px;position:relative;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(217,119,6,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(217,119,6,.05) 1px,transparent 1px);background-size:48px 48px;}
.orb{position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
.orb-1{width:400px;height:400px;background:radial-gradient(circle,rgba(217,119,6,.18) 0%,transparent 70%);bottom:-100px;right:-80px;}
.orb-2{width:280px;height:280px;background:radial-gradient(circle,rgba(43,108,176,.15) 0%,transparent 70%);top:-60px;left:-60px;}
.card{position:relative;z-index:10;background:rgba(23,42,69,.82);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:40px 40px 32px;width:100%;max-width:420px;box-shadow:var(--shadow);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:24px;}
.icon-wrap{width:64px;height:64px;border-radius:50%;background:rgba(217,119,6,.15);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:var(--gold-hover);}
.card-title{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(217,119,6,.4),transparent);margin-bottom:22px;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.form-input{width:100%;padding:12px 13px 12px 38px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(217,119,6,.2);}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;}
.pw-strength{height:3px;border-radius:2px;margin-top:6px;transition:all .3s;background:rgba(255,255,255,.08);}
.pw-hint{font-size:11px;color:var(--muted);margin-top:4px;}
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.btn-main{width:100%;padding:13px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 14px rgba(217,119,6,.4);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-main:hover{background:var(--gold-hover);transform:translateY(-1px);}
.back-row{text-align:center;margin-top:18px;font-size:13px;color:var(--muted);}
.back-row a{color:var(--gold-hover);font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="card">
    <div class="card-header">
        <div class="icon-wrap"><i class="fa-solid fa-lock-open"></i></div>
        <div class="card-title">Reset Password</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($row): ?>
    <form method="POST" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
        <div class="form-group">
            <label class="form-label">New Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="pw" name="password"
                       placeholder="Min. 8 characters" required oninput="checkStrength(this.value)"/>
                <button type="button" class="toggle-pw" onclick="togglePw('pw','eye1')">
                    <i class="fa-solid fa-eye" id="eye1"></i>
                </button>
            </div>
            <div class="pw-strength" id="pw-bar"></div>
            <div class="pw-hint" id="pw-hint"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="pw2" name="confirm_password"
                       placeholder="Repeat new password" required/>
                <button type="button" class="toggle-pw" onclick="togglePw('pw2','eye2')">
                    <i class="fa-solid fa-eye" id="eye2"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-shield-halved"></i> Reset Password
        </button>
    </form>
    <?php endif; ?>

    <div class="back-row">
        <a href="student_login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
function togglePw(id, ic) {
    const el = document.getElementById(id), i = document.getElementById(ic);
    el.type = el.type === 'password' ? 'text' : 'password';
    i.className = el.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}
function checkStrength(val) {
    const bar = document.getElementById('pw-bar'), hint = document.getElementById('pw-hint');
    let score = 0;
    if (val.length >= 8) score++; if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++; if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['#ff4444','#ff8800','#f0c040','#4ade80'];
    const labels = ['Weak','Fair','Good','Strong'];
    if (!val) { bar.style.background = 'rgba(255,255,255,.08)'; hint.textContent = ''; return; }
    bar.style.background = colors[score-1]||colors[0];
    hint.textContent = labels[score-1]||'Weak';
    hint.style.color = colors[score-1]||colors[0];
}
</script>
</body>
</html>
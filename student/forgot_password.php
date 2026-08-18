<?php
// student/forgot_password.php
session_start();
require_once 'db.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $mysqli->prepare(
            "SELECT id, full_name FROM users
             WHERE email = ? AND role = 'student' AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $ins = $mysqli->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)"
            );
            $ins->bind_param("iss", $user['id'], $token, $expires);
            $ins->execute();
            $ins->close();

            $reset_link = "http://localhost/index/student/reset_password.php?token=" . $token;

            @mail($email, 'PBI — Password Reset', "Reset link: $reset_link", "From: no-reply@pandanbay.edu.ph");

            // TEMP: show link directly (remove in production!)
            $success = "Reset link (dev only): <a href='$reset_link' style='color:var(--gold-hover)'>Click here to reset</a>";

        } else {
            $success = "If that email is registered, a reset link has been sent.";
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
<title>PBI — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--gold:#D97706;--gold-hover:#F59E0B;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark-blue);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:24px;overflow-x:hidden;position:relative;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(217,119,6,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(217,119,6,.05) 1px,transparent 1px);background-size:48px 48px;animation:g 25s linear infinite;}
@keyframes g{0%{background-position:0 0}100%{background-position:48px 48px}}
.orb{position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
.orb-1{width:400px;height:400px;background:radial-gradient(circle,rgba(217,119,6,.18) 0%,transparent 70%);bottom:-100px;right:-80px;animation:o1 16s ease-in-out infinite;}
.orb-2{width:280px;height:280px;background:radial-gradient(circle,rgba(43,108,176,.15) 0%,transparent 70%);top:-60px;left:-60px;animation:o2 20s ease-in-out infinite;}
@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(-25px,-20px)}}
@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(20px,18px)}}
.card{position:relative;z-index:10;background:rgba(23,42,69,.82);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:40px 40px 32px;width:100%;max-width:420px;box-shadow:var(--shadow);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:24px;}
.icon-wrap{width:64px;height:64px;border-radius:50%;background:rgba(217,119,6,.15);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:var(--gold-hover);box-shadow:0 0 20px rgba(217,119,6,.3);}
.card-title{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.6;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(217,119,6,.4),transparent);margin-bottom:22px;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.form-input{width:100%;padding:12px 13px 12px 38px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.form-input::placeholder{color:rgba(160,179,198,.4);}
.form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(217,119,6,.2);}
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.35);color:#fcd34d;}
.btn-main{width:100%;padding:13px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 14px rgba(217,119,6,.4);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-main:hover{background:var(--gold-hover);transform:translateY(-1px);}
.back-row{text-align:center;margin-top:18px;font-size:13px;color:var(--muted);}
.back-row a{color:var(--gold-hover);font-weight:600;text-decoration:none;}
.back-row a:hover{text-decoration:underline;}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="card">
    <div class="card-header">
        <div class="icon-wrap"><i class="fa-solid fa-key"></i></div>
        <div class="card-title">Forgot Password</div>
        <div class="card-subtitle">Enter your registered email and we'll send you a reset link.</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">
        <div class="form-group">
            <label class="form-label">Email Address</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope f-icon"></i>
                <input class="form-input" type="email" name="email"
                       placeholder="Enter your registered email" required/>
            </div>
        </div>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-paper-plane"></i> Send Reset Link
        </button>
    </form>

    <div class="back-row">
        <a href="student_login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>
</body>
</html>
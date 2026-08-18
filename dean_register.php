<?php
// dean/dean_register.php
// Place this file inside: htdocs/index/dean/
session_start();
require_once 'db.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $fullName        = trim($_POST['full_name'] ?? '');
        $employeeId      = trim($_POST['employee_id'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $department      = trim($_POST['department'] ?? '');
        $course          = trim($_POST['course'] ?? '');
        $designation     = trim($_POST['designation'] ?? '');

        if ($department === '' || $course === '') {
            $error = 'College/Department and Course are required.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = ems_register_account($mysqli, 'dean', [
                'full_name'   => $fullName,
                'employee_id' => $employeeId,
                'username'    => $username,
                'email'       => $email,
                'password'    => $password,
                'department'  => $department,
                'course'      => $course,
                'designation' => $designation,
            ]);

            if ($result['ok']) {
                $success = true;
            } else {
                $error = $result['error'];
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Dean Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--violet:#7C5FD9;--violet-h:#9C85F0;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;overflow-x:hidden;position:relative;padding:32px 0;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(124,95,217,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(124,95,217,.06) 1px,transparent 1px);background-size:48px 48px;animation:g 22s linear infinite;}
@keyframes g{0%{background-position:0 0}100%{background-position:48px 48px}}
.orb{position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
.orb-1{width:380px;height:380px;background:radial-gradient(circle,rgba(124,95,217,.2) 0%,transparent 70%);top:-80px;right:-80px;animation:o1 14s ease-in-out infinite;}
.orb-2{width:300px;height:300px;background:radial-gradient(circle,rgba(43,108,176,.15) 0%,transparent 70%);bottom:-60px;left:-60px;animation:o2 18s ease-in-out infinite;}
@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,25px)}}
@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(25px,-20px)}}
.reg-card{position:relative;z-index:10;background:rgba(23,42,69,.85);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:44px 44px 36px;width:100%;max-width:560px;box-shadow:var(--shadow),0 0 0 1px rgba(124,95,217,.15);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:26px;}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:4px;}
.role-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(124,95,217,.15);border:1px solid rgba(124,95,217,.35);color:var(--violet-h);font-size:11px;font-weight:700;padding:4px 14px;border-radius:20px;text-transform:uppercase;letter-spacing:.8px;margin-top:10px;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(124,95,217,.45),transparent);margin-bottom:24px;}
.section-label{font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--violet-h);margin:20px 0 12px;display:flex;align-items:center;gap:8px;}
.section-label:first-of-type{margin-top:0;}
.form-row{display:flex;gap:14px;}
.form-row > .form-group{flex:1;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;}
.form-input{width:100%;padding:12px 14px 12px 40px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;appearance:none;}
.form-input::placeholder{color:rgba(160,179,198,.45);}
.form-input:focus{border-color:var(--violet);box-shadow:0 0 0 3px rgba(124,95,217,.2);}
select.form-input{cursor:pointer;}
.toggle-pw{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:0;}
.hint{font-size:11px;color:var(--muted);margin-top:6px;}
.alert{border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.35);color:#a9f0c4;line-height:1.6;}
.btn-submit{width:100%;padding:13px;background:var(--violet);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 16px rgba(124,95,217,.4);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;}
.btn-submit:hover{background:var(--violet-h);transform:translateY(-1px);}
.login-row{text-align:center;margin-top:16px;font-size:13px;color:var(--muted);}
.login-row a{color:var(--violet-h);font-weight:600;text-decoration:none;}
.login-row a:hover{text-decoration:underline;}
@media(max-width:600px){.reg-card{padding:32px 22px 28px;margin:16px;}.form-row{flex-direction:column;gap:0;}}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="reg-card">
    <div class="card-header">
        <div class="card-title">Dean Registration</div>
        <div class="card-subtitle">Pandan Bay Institute — Evaluation System</div>
        <div class="role-pill"><i class="fa-solid fa-user-graduate"></i> Dean Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            Registration submitted. Your account is <strong>pending administrator approval</strong>
            — you'll be able to log in once approved.
        </div>
        <div class="login-row"><a href="dean_login.php">Back to Login</a></div>
    <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="dean_register.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="section-label"><i class="fa-solid fa-id-card"></i> Personal Details</div>

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <div class="input-wrap">
                    <input class="form-input" type="text" name="full_name" required
                           placeholder="e.g. Dr. Maria Santos"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
                    <i class="fa-solid fa-user f-icon"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Employee ID</label>
                    <div class="input-wrap">
                        <input class="form-input" type="text" name="employee_id" required
                               placeholder="EMP-0000"
                               value="<?= htmlspecialchars($_POST['employee_id'] ?? '') ?>"/>
                        <i class="fa-solid fa-id-badge f-icon"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Designation</label>
                    <div class="input-wrap">
                        <input class="form-input" type="text" name="designation"
                               placeholder="e.g. College Dean"
                               value="<?= htmlspecialchars($_POST['designation'] ?? '') ?>"/>
                        <i class="fa-solid fa-briefcase f-icon"></i>
                    </div>
                </div>
            </div>

            <div class="section-label"><i class="fa-solid fa-building-columns"></i> Academic Unit</div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">College / Department</label>
                    <div class="input-wrap">
                        <input class="form-input" type="text" name="department" required
                               placeholder="e.g. College of Engineering"
                               value="<?= htmlspecialchars($_POST['department'] ?? '') ?>"/>
                        <i class="fa-solid fa-building-columns f-icon"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Primary Course / Program</label>
                    <div class="input-wrap">
                        <input class="form-input" type="text" name="course" required
                               placeholder="e.g. BS Civil Engineering"
                               value="<?= htmlspecialchars($_POST['course'] ?? '') ?>"/>
                        <i class="fa-solid fa-book f-icon"></i>
                    </div>
                </div>
            </div>
            <div class="hint" style="margin-top:-8px;margin-bottom:16px;">Your dashboard scope covers the whole College division — this identifies your primary unit on record.</div>

            <div class="section-label"><i class="fa-solid fa-lock"></i> Account Credentials</div>

            <div class="form-group">
                <label class="form-label">Username</label>
                <div class="input-wrap">
                    <input class="form-input" type="text" name="username" required autocomplete="off"
                           placeholder="Choose a username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
                    <i class="fa-solid fa-at f-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <div class="input-wrap">
                    <input class="form-input" type="email" name="email" required
                           placeholder="you@pbi.edu.ph"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
                    <i class="fa-solid fa-envelope f-icon"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrap">
                        <input class="form-input" type="password" id="password" name="password" required
                               minlength="8" autocomplete="new-password" placeholder="At least 8 characters"/>
                        <i class="fa-solid fa-lock f-icon"></i>
                        <button type="button" class="toggle-pw" onclick="togglePw('password','eyeIcon1')">
                            <i class="fa-solid fa-eye" id="eyeIcon1"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-wrap">
                        <input class="form-input" type="password" id="confirm_password" name="confirm_password" required
                               minlength="8" autocomplete="new-password" placeholder="Re-enter password"/>
                        <i class="fa-solid fa-lock f-icon"></i>
                        <button type="button" class="toggle-pw" onclick="togglePw('confirm_password','eyeIcon2')">
                            <i class="fa-solid fa-eye" id="eyeIcon2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-user-plus"></i> Submit Registration
            </button>
        </form>

        <div class="login-row">
            Already have an account? <a href="dean_login.php">Log in</a>
        </div>
    <?php endif; ?>
</div>

<script>
function togglePw(inputId, iconId) {
    const pw = document.getElementById(inputId), ic = document.getElementById(iconId);
    pw.type = pw.type === 'password' ? 'text' : 'password';
    ic.className = pw.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}
</script>
</body>
</html>
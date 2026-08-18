<?php
// admin/admin_register.php
session_start();
require_once 'db.php';   // provides $mysqli + UPLOAD_DIR + UPLOAD_URL

// ── REGISTRATION LOCK ────────────────────────────────────────
// Once a superadmin account exists, this page closes itself automatically.
// To re-open it later (e.g. lost access, handing off to a second person),
// run this SQL once, register the new account, then flip it back to 0:
//   UPDATE system_settings SET setting_value=1 WHERE setting_key='superadmin_reg_open';
//   UPDATE system_settings SET setting_value=0 WHERE setting_key='superadmin_reg_open';
$reg_open = 0;
$tbl_check = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $flag = $mysqli->query("SELECT setting_value FROM system_settings WHERE setting_key='superadmin_reg_open'");
    if ($flag && $flag->num_rows > 0) {
        $reg_open = (int)$flag->fetch_assoc()['setting_value'];
    }
}

$count_res = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE role = 'superadmin'");
$superadmin_count = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;

if ($superadmin_count > 0 && !$reg_open) {
    http_response_code(403);
    die("Registration is closed. Contact your System Administrator.");
}

$error   = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first_name = trim($_POST['first_name']       ?? '');
    $last_name  = trim($_POST['last_name']        ?? '');
    $email      = trim($_POST['email']            ?? '');
    $username   = trim($_POST['username']         ?? '');
    $password   = $_POST['password']              ?? '';
    $confirm    = $_POST['confirm_password']      ?? '';

    // ── VALIDATION ────────────────────────────────────────────
    if (empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $chk->bind_param("ss", $username, $email);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) $error = "Username or email is already in use.";
        $chk->close();
    }

    // ── PHOTO UPLOAD ──────────────────────────────────────────
    $photo_filename = null;
    if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $file_type     = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size     = $_FILES['photo']['size'];

        if (!in_array($file_type, $allowed_types)) {
            $error = "Profile photo must be JPG, PNG, WebP, or GIF.";
        } elseif ($file_size > 10 * 1024 * 1024) {
            $error = "Profile photo must be under 10 MB.";
        } else {
            $ext            = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_filename = uniqid('adm_', true) . '.' . strtolower($ext);
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $photo_filename)) {
                $error = "Failed to save photo. Check folder permissions on /image/.";
                $photo_filename = null;
            }
        }
    }

    // ── INSERT ────────────────────────────────────────────────
    if (empty($error)) {
        $full_name = $first_name . ' ' . $last_name;
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $ins = $mysqli->prepare(
            "INSERT INTO users (full_name, email, username, password_hash, photo, role, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 'superadmin', 1, NOW())"
        );
        $ins->bind_param("sssss", $full_name, $email, $username, $hash, $photo_filename);

        if ($ins->execute()) {
            $ins->close();
            $mysqli->close();
            $_SESSION['reg_success'] = "Admin account created! You can now sign in.";
            header("Location: admin_login.php");
            exit;
        } else {
            $error = "Registration failed: " . $mysqli->error;
        }
        $ins->close();
    }
    if ($mysqli->ping()) $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI Admin — Create Account</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark-blue:#0A192F; --blue-mid:#172A45; --blue-inner:#0F1F3D;
    --blue-accent:#2B6CB0; --blue-hover:#4C78B8;
    --light:#E0E6F0; --muted:#A0B3C6; --radius:10px;
    --shadow:0 8px 32px rgba(0,0,0,0.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    min-height:100vh; background:var(--dark-blue);
    font-family:'DM Sans',sans-serif; color:var(--light);
    display:flex; align-items:center; justify-content:center;
    padding:40px 20px; overflow-x:hidden; position:relative;
}
.bg-grid{
    position:fixed; inset:0; z-index:0;
    background-image:
        linear-gradient(rgba(43,108,176,.07) 1px, transparent 1px),
        linear-gradient(90deg, rgba(43,108,176,.07) 1px, transparent 1px);
    background-size:48px 48px; animation:g 20s linear infinite;
}
@keyframes g{0%{background-position:0 0}100%{background-position:48px 48px}}
.orb{position:fixed;border-radius:50%;filter:blur(80px);z-index:0;pointer-events:none;}
.orb-1{width:420px;height:420px;background:radial-gradient(circle,rgba(43,108,176,.25) 0%,transparent 70%);top:-100px;left:-100px;animation:o1 12s ease-in-out infinite;}
.orb-2{width:320px;height:320px;background:radial-gradient(circle,rgba(96,165,250,.15) 0%,transparent 70%);bottom:-80px;right:-80px;animation:o2 15s ease-in-out infinite;}
@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(40px,30px)}}
@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,-25px)}}

/* ── Card ── */
.reg-card{
    position:relative; z-index:10;
    background:rgba(23,42,69,.82); backdrop-filter:blur(18px);
    border:1px solid rgba(255,255,255,.09); border-radius:18px;
    padding:40px 40px 36px; width:100%; max-width:500px;
    box-shadow:var(--shadow), 0 0 0 1px rgba(43,108,176,.18);
    animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}

/* ── Header ── */
.card-header{text-align:center;margin-bottom:22px;}
.logo-img{
    width:72px; height:72px; border-radius:50%;
    object-fit:cover;
    border:2.5px solid var(--blue-accent);
    box-shadow:0 0 20px rgba(43,108,176,.55);
    margin:0 auto 12px; display:block;
}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:4px;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(43,108,176,.4),transparent);margin-bottom:22px;}

/* ── Photo upload ── */
.photo-upload-area{display:flex;align-items:center;gap:18px;margin-bottom:20px;}
.photo-preview{
    width:80px; height:80px; border-radius:50%;
    background:var(--blue-inner);
    border:2px dashed rgba(43,108,176,.55);
    overflow:hidden; display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:border-color .2s; flex-shrink:0;
}
.photo-preview:hover{border-color:var(--blue-accent);}
.photo-preview img{width:100%;height:100%;object-fit:cover;display:none;}
.photo-preview .ph-icon{color:var(--muted);font-size:24px;}
.photo-info p{font-size:13px;color:var(--light);font-weight:600;margin-bottom:3px;}
.photo-info span{font-size:11px;color:var(--muted);}
.btn-photo{
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(43,108,176,.15); border:1px solid rgba(43,108,176,.4);
    color:var(--blue-hover); padding:7px 14px; border-radius:7px;
    font-size:12px; font-weight:600; cursor:pointer; transition:all .2s; margin-top:7px;
}
.btn-photo:hover{background:rgba(43,108,176,.28);}
input[type="file"]{display:none;}

/* ── Form ── */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.required{color:#ff8a8a;margin-left:2px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.form-input,.role-select{
    width:100%; padding:11px 13px 11px 38px;
    background:rgba(10,25,47,.7); border:1px solid rgba(255,255,255,.1);
    border-radius:var(--radius); color:var(--light);
    font-size:14px; font-family:'DM Sans',sans-serif;
    outline:none; transition:border-color .25s,box-shadow .25s;
}
.form-input::placeholder{color:rgba(160,179,198,.4);}
.form-input:focus,.role-select:focus{border-color:var(--blue-accent);box-shadow:0 0 0 3px rgba(43,108,176,.2);}
.role-select{appearance:none;cursor:pointer;}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0;transition:color .2s;}
.toggle-pw:hover{color:var(--light);}

/* ── Password strength ── */
.pw-strength{height:3px;border-radius:2px;margin-top:6px;transition:all .3s;background:rgba(255,255,255,.08);}
.pw-hint{font-size:11px;color:var(--muted);margin-top:4px;}

/* ── Alerts ── */
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}

/* ── Button ── */
.btn-main{
    width:100%; padding:13px; background:var(--blue-accent);
    border:none; border-radius:var(--radius); color:#fff;
    font-size:15px; font-weight:600; font-family:'DM Sans',sans-serif;
    cursor:pointer; transition:background .2s,transform .15s;
    box-shadow:0 4px 14px rgba(43,108,176,.4);
    display:flex; align-items:center; justify-content:center; gap:8px; margin-top:6px;
}
.btn-main:hover{background:var(--blue-hover);transform:translateY(-1px);}

.login-row{text-align:center;margin-top:18px;font-size:13px;color:var(--muted);}
.login-row a{color:var(--blue-hover);font-weight:600;text-decoration:none;}
.login-row a:hover{text-decoration:underline;}

@media(max-width:520px){
    .reg-card{padding:28px 16px 24px;}
    .form-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="reg-card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-title">Create Admin Account</div>
        <div class="card-subtitle">Pandan Bay Institute &mdash; Control Panel</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="admin_register.php" enctype="multipart/form-data" autocomplete="off">

        <!-- Profile Photo -->
        <div class="photo-upload-area">
            <div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()">
                <img id="photoImg" src="" alt="Preview"/>
                <i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
            </div>
            <div class="photo-info">
                <p>Profile Photo</p>
                <span>Shown on the admin panel &amp; audit logs — max 10 MB</span><br>
                <label class="btn-photo" for="photoFile">
                    <i class="fa-solid fa-upload"></i> Upload Photo
                </label>
                <input type="file" id="photoFile" name="photo"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       onchange="previewPhoto(this)"/>
            </div>
        </div>

        <!-- Name -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">First Name<span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="fa-solid fa-user f-icon"></i>
                    <input class="form-input" type="text" name="first_name" placeholder="Juan"
                           value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required/>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Last Name<span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="fa-solid fa-user f-icon"></i>
                    <input class="form-input" type="text" name="last_name" placeholder="dela Cruz"
                           value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required/>
                </div>
            </div>
        </div>

        <!-- Email -->
        <div class="form-group">
            <label class="form-label">Email Address<span class="required">*</span></label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope f-icon"></i>
                <input class="form-input" type="email" name="email" placeholder="admin@pandanbay.edu.ph"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
            </div>
        </div>

        <!-- Username -->
        <div class="form-group">
            <label class="form-label">Username<span class="required">*</span></label>
            <div class="input-wrap">
                <i class="fa-solid fa-at f-icon"></i>
                <input class="form-input" type="text" name="username" placeholder="Choose a username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="off"/>
            </div>
        </div>

        <!-- Password -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Password<span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock f-icon"></i>
                    <input class="form-input" type="password" id="pw" name="password"
                           placeholder="Min. 8 characters" required oninput="checkStrength(this.value)"/>
                    <button type="button" class="toggle-pw" onclick="togglePw('pw','eye1')">
                        <i class="fa-solid fa-eye" id="eye1"></i>
                    </button>
                </div>
                <div class="pw-strength" id="pw-bar"></div>
                <div class="pw-hint"     id="pw-hint"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password<span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock f-icon"></i>
                    <input class="form-input" type="password" id="pw2" name="confirm_password"
                           placeholder="Repeat password" required/>
                    <button type="button" class="toggle-pw" onclick="togglePw('pw2','eye2')">
                        <i class="fa-solid fa-eye" id="eye2"></i>
                    </button>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-main">
            <i class="fa-solid fa-user-plus"></i> Create Admin Account
        </button>
    </form>

    <div class="login-row">
        Already have an account? <a href="admin_login.php">Sign in here</a>
    </div>
</div>

<script>
function togglePw(id, ic) {
    const el = document.getElementById(id), i = document.getElementById(ic);
    el.type = el.type === 'password' ? 'text' : 'password';
    i.className = el.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            const img = document.getElementById('photoImg');
            const ic  = document.getElementById('phIcon');
            img.src = e.target.result;
            img.style.display = 'block';
            ic.style.display  = 'none';
        };
        r.readAsDataURL(input.files[0]);
    }
}
function checkStrength(val) {
    const bar = document.getElementById('pw-bar'), hint = document.getElementById('pw-hint');
    let score = 0;
    if (val.length >= 8)           score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    const colors = ['#ff4444','#ff8800','#f0c040','#4ade80'];
    const labels = ['Weak','Fair','Good','Strong'];
    if (!val) { bar.style.background = 'rgba(255,255,255,.08)'; hint.textContent = ''; return; }
    bar.style.background = colors[score - 1] || colors[0];
    hint.textContent     = labels[score - 1] || 'Weak';
    hint.style.color     = colors[score - 1] || colors[0];
}
</script>
</body>
</html>
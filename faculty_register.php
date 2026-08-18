<?php
	// faculty/faculty_register.php
	session_start();
	require_once 'db.php';   // gives $mysqli + UPLOAD_DIR + UPLOAD_URL

	$error   = "";
	$success = "";

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$full_name  = trim($_POST['full_name']  ?? '');
		$username   = trim($_POST['username']   ?? '');
		$email      = trim($_POST['email']      ?? '');
		$password   = $_POST['password']        ?? '';
		$confirm_pw = $_POST['confirm_password']?? '';
		$role       = in_array($_POST['role'] ?? '', ['teacher','staff']) ? $_POST['role'] : 'teacher';

		// ── VALIDATION ───────────────────────────────────────────
		if (empty($full_name) || empty($username) || empty($password)) {
			$error = "Full name, username, and password are required.";
		} elseif ($password !== $confirm_pw) {
			$error = "Passwords do not match.";
		} elseif (strlen($password) < 8) {
			$error = "Password must be at least 8 characters.";
		} else {
			$chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR (email = ? AND email != '') LIMIT 1");
			$chk->bind_param("ss", $username, $email);
			$chk->execute();
			$chk->store_result();
			if ($chk->num_rows > 0) $error = "Username or email is already taken.";
			$chk->close();
		}

		// ── PHOTO UPLOAD ─────────────────────────────────────────
		$photo_filename = null;
		if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
			$allowed   = ['image/jpeg','image/png','image/webp','image/gif'];
			$file_type = mime_content_type($_FILES['photo']['tmp_name']);
			$file_size = $_FILES['photo']['size'];

			if (!in_array($file_type, $allowed)) {
				$error = "Profile photo must be JPG, PNG, WebP, or GIF.";
			} elseif ($file_size > 10 * 1024 * 1024) {
				$error = "Profile photo must be under 10 MB.";
			} else {
				$ext            = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
				$photo_filename = uniqid('usr_', true) . '.' . strtolower($ext);
				// UPLOAD_DIR = absolute path to index/image/ — correct from any subfolder
				if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
				if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $photo_filename)) {
					$error = "Failed to save photo. Check folder permissions on /image/.";
					$photo_filename = null;
				}
			}
		}

		// ── INSERT ───────────────────────────────────────────────
		if (empty($error)) {
			$password_hash = password_hash($password, PASSWORD_BCRYPT);
			$designation   = ($role === 'teacher') ? 'Teacher' : 'Personnel';
			// The `sector` enum ('Teacher','Staff','Student') is what faculty_login.php
			// actually checks — map the lowercase role tab value to it here so it's not
			// left at its 'Student' default.
			$sector        = ($role === 'teacher') ? 'Teacher' : 'Staff';
			$is_active     = 1;

			$stmt = $mysqli->prepare(
				"INSERT INTO users (full_name, username, email, password_hash, role, sector, designation, photo, is_active)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
			);
			$stmt->bind_param("ssssssssi",
				$full_name, $username, $email,
				$password_hash, $role, $sector, $designation,
				$photo_filename, $is_active
			);
			if ($stmt->execute()) {
				$stmt->close();
				$mysqli->close();
				$_SESSION['reg_success'] = "Account created! Please log in. Your designation will be assigned by the admin.";
				header("Location: faculty_login.php");
				exit;
			} else {
				$error = "Registration failed: " . $mysqli->error;
			}
			$stmt->close();
		}
		if ($mysqli->ping()) $mysqli->close();
	}
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
	<title>PBI — Teacher & Staff Registration</title>
	<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
	<style>
	:root{
		--dark-blue:#0A192F; --blue-mid:#172A45; --blue-inner:#0F1F3D;
		--blue-accent:#2B6CB0; --teal:#0D9488; --teal-hover:#14B8A6;
		--light:#E0E6F0; --muted:#A0B3C6; --danger:#F05454; --radius:10px;
		--shadow:0 8px 32px rgba(0,0,0,0.45);
	}
	*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
	body{
		min-height:100vh; background:var(--dark-blue);
		font-family:'DM Sans',sans-serif; color:var(--light);
		display:flex; align-items:center; justify-content:center;
		padding:40px 20px; position:relative; overflow-x:hidden;
	}
	.bg-grid{
		position:fixed;inset:0;z-index:0;
		background-image:linear-gradient(rgba(13,148,136,.055) 1px,transparent 1px),
						 linear-gradient(90deg,rgba(13,148,136,.055) 1px,transparent 1px);
		background-size:48px 48px; animation:gridShift 22s linear infinite;
	}
	@keyframes gridShift{0%{background-position:0 0}100%{background-position:48px 48px}}
	.orb{position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
	.orb-1{width:380px;height:380px;background:radial-gradient(circle,rgba(13,148,136,.18) 0%,transparent 70%);top:-80px;right:-80px;animation:o1 14s ease-in-out infinite;}
	.orb-2{width:300px;height:300px;background:radial-gradient(circle,rgba(43,108,176,.15) 0%,transparent 70%);bottom:-60px;left:-60px;animation:o2 18s ease-in-out infinite;}
	@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(-28px,22px)}}
	@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(22px,-18px)}}

	.reg-card{
		position:relative;z-index:10;
		background:rgba(23,42,69,.88);backdrop-filter:blur(20px);
		border:1px solid rgba(255,255,255,.09);border-radius:20px;
		padding:44px 44px 40px;width:100%;max-width:560px;
		box-shadow:var(--shadow),0 0 0 1px rgba(13,148,136,.13);
		animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;
	}
	@keyframes cardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}

	.card-header{text-align:center;margin-bottom:24px;}
	.logo-ring{
		width:68px;height:68px;border-radius:50%;
		border:2.5px solid var(--teal);
		box-shadow:0 0 20px rgba(13,148,136,.4);
		margin:0 auto 14px;
		display:block;
		object-fit:cover;
	}
	.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
	.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:4px;}

	.role-tabs{display:flex;background:rgba(10,25,47,.6);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:4px;margin-bottom:22px;}
	.role-tab{flex:1;padding:9px 10px;border:none;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .25s;background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:7px;}
	.role-tab.active{background:var(--teal);color:#fff;box-shadow:0 2px 10px rgba(13,148,136,.35);}
	.role-tab:not(.active):hover{color:var(--light);background:rgba(255,255,255,.05);}

	.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(13,148,136,.4),transparent);margin-bottom:20px;}

	.steps-row{display:flex;align-items:center;justify-content:center;margin-bottom:22px;}
	.step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;position:relative;}
	.step:not(:last-child)::after{content:'';position:absolute;top:13px;left:60%;width:80%;height:1px;background:rgba(255,255,255,.1);}
	.step-dot{width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--muted);transition:all .3s;}
	.step.done .step-dot{background:var(--teal);border-color:var(--teal);color:#fff;}
	.step.active .step-dot{background:rgba(13,148,136,.25);border-color:var(--teal);color:var(--teal-hover);}
	.step-lbl{font-size:10px;color:var(--muted);letter-spacing:.5px;text-transform:uppercase;}
	.step.active .step-lbl,.step.done .step-lbl{color:var(--teal-hover);}

	.photo-upload-area{display:flex;align-items:center;gap:18px;margin-bottom:20px;}
	.photo-preview{width:80px;height:80px;border-radius:50%;background:var(--blue-inner);border:2px dashed rgba(13,148,136,.5);overflow:hidden;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:border-color .2s;flex-shrink:0;}
	.photo-preview:hover{border-color:var(--teal);}
	.photo-preview img{width:100%;height:100%;object-fit:cover;display:none;}
	.photo-preview .ph-icon{color:var(--muted);font-size:24px;}
	.photo-info p{font-size:13px;color:var(--light);font-weight:600;margin-bottom:3px;}
	.photo-info span{font-size:11px;color:var(--muted);}
	.btn-photo{display:inline-flex;align-items:center;gap:6px;background:rgba(13,148,136,.15);border:1px solid rgba(13,148,136,.4);color:var(--teal-hover);padding:7px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;margin-top:7px;}
	.btn-photo:hover{background:rgba(13,148,136,.25);}
	input[type="file"]{display:none;}

	.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
	.form-grid .full{grid-column:1/-1;}
	.form-group{display:flex;flex-direction:column;gap:6px;}
	.form-label{font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);}
	.input-wrap{position:relative;}
	.input-wrap .f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;transition:color .2s;}
	.form-input{width:100%;padding:11px 13px 11px 38px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
	.form-input::placeholder{color:rgba(160,179,198,.42);}
	.form-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.18);}
	.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0;transition:color .2s;}
	.toggle-pw:hover{color:var(--light);}

	.pw-strength{margin-top:6px;display:none;}
	.strength-bar{height:4px;border-radius:2px;background:rgba(255,255,255,.08);overflow:hidden;margin-bottom:4px;}
	.strength-fill{height:100%;border-radius:2px;width:0%;transition:width .3s,background .3s;}
	.strength-label{font-size:11px;color:var(--muted);}

	.alert{display:flex;align-items:flex-start;gap:9px;border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:16px;}
	.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
	.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.28);color:#86efac;}

	.btn-register{width:100%;padding:13px;background:var(--teal);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;letter-spacing:.5px;cursor:pointer;margin-top:18px;transition:background .2s,transform .15s,box-shadow .2s;box-shadow:0 4px 16px rgba(13,148,136,.38);display:flex;align-items:center;justify-content:center;gap:8px;}
	.btn-register:hover{background:var(--teal-hover);transform:translateY(-1px);box-shadow:0 6px 22px rgba(13,148,136,.5);}
	.btn-register:active{transform:translateY(0);}

	.card-footer{text-align:center;margin-top:22px;font-size:12px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:18px;}
	.card-footer a{color:var(--teal-hover);text-decoration:none;font-weight:600;}
	.card-footer a:hover{text-decoration:underline;}
	.secure-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);margin-top:12px;letter-spacing:.5px;}
	.secure-badge i{color:#4ade80;font-size:10px;}

	@media(max-width:540px){
		.reg-card{padding:28px 16px 24px;}
		.form-grid{grid-template-columns:1fr;}
		.form-grid .full{grid-column:1;}
	}
	</style>
	</head>
	<body>
	<div class="bg-grid"></div>
	<div class="orb orb-1"></div>
	<div class="orb orb-2"></div>

	<div class="reg-card">
		<div class="card-header">
			<img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
			<div class="card-title">Create Your Account</div>
			<div class="card-subtitle">Pandan Bay Institute — Teacher & Staff Portal</div>
		</div>

		<div class="steps-row">
			<div class="step done" id="step1"><div class="step-dot"><i class="fa-solid fa-user"></i></div><span class="step-lbl">Profile</span></div>
			<div class="step active" id="step2"><div class="step-dot"><i class="fa-solid fa-lock"></i></div><span class="step-lbl">Security</span></div>
			<div class="step" id="step3"><div class="step-dot"><i class="fa-solid fa-circle-check"></i></div><span class="step-lbl">Done</span></div>
		</div>

		<div class="role-tabs">
			<button class="role-tab active" id="tab-teacher" type="button" onclick="setRole('teacher')">
				<i class="fa-solid fa-chalkboard-user"></i> Teacher
			</button>
			<button class="role-tab" id="tab-staff" type="button" onclick="setRole('staff')">
				<i class="fa-solid fa-briefcase"></i> Staff
			</button>
		</div>
		<div class="divider"></div>

		<?php if ($error): ?>
		<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i><span><?= htmlspecialchars($error) ?></span></div>
		<?php endif; ?>
		<?php if ($success): ?>
		<div class="alert alert-success"><i class="fa-solid fa-circle-check" style="flex-shrink:0;margin-top:1px"></i>
			<span><?= htmlspecialchars($success) ?> <a href="faculty_login.php" style="color:#4ade80;font-weight:700;">Log in now →</a></span>
		</div>
		<?php endif; ?>

		<form method="POST" enctype="multipart/form-data" id="regForm" autocomplete="off">
			<input type="hidden" name="role" id="role_input" value="teacher"/>

			<div class="photo-upload-area">
				<div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()">
					<img id="photoImg" src="" alt="Preview"/>
					<i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
				</div>
				<div class="photo-info">
					<p>Profile Photo</p>
					<span>Appears on evaluation forms so students can identify you — max 10MB</span><br>
					<label class="btn-photo" for="photoFile"><i class="fa-solid fa-upload"></i> Upload Photo</label>
					<input type="file" id="photoFile" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewPhoto(this)"/>
				</div>
			</div>

			<div class="form-grid">
				<div class="form-group full">
					<label class="form-label" for="full_name">Full Name <span style="color:#f87171">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="text" id="full_name" name="full_name" placeholder="Last Name, First Name M.I." required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
						<i class="fa-solid fa-id-card f-icon"></i>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="username">Username <span style="color:#f87171">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="text" id="username" name="username" placeholder="Choose a username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
						<i class="fa-solid fa-user f-icon"></i>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="email">Email Address</label>
					<div class="input-wrap">
						<input class="form-input" type="email" id="email" name="email" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
						<i class="fa-solid fa-envelope f-icon"></i>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="password">Password <span style="color:#f87171">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="password" name="password" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)"/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('password','eye1')"><i class="fa-solid fa-eye" id="eye1"></i></button>
					</div>
					<div class="pw-strength" id="pwStrength">
						<div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
						<span class="strength-label" id="strengthLabel"></span>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="confirm_password">Confirm Password <span style="color:#f87171">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('confirm_password','eye2')"><i class="fa-solid fa-eye" id="eye2"></i></button>
					</div>
				</div>
			</div>

			<div style="background:rgba(43,108,176,.12);border:1px solid rgba(43,108,176,.25);border-radius:8px;padding:12px 14px;margin-top:14px;font-size:12px;color:var(--muted);display:flex;gap:8px;align-items:flex-start;">
				<i class="fa-solid fa-circle-info" style="color:#60a5fa;margin-top:1px;flex-shrink:0"></i>
				<span>Your account will be <strong style="color:var(--light)">active immediately</strong> after registration. The admin will assign your specific designation (e.g. Registrar, Librarian) before you appear on evaluation forms.</span>
			</div>

			<button type="submit" class="btn-register">
				<i class="fa-solid fa-user-plus"></i>
				<span id="regBtnLabel">Create Teacher Account</span>
			</button>
		</form>

		<div class="card-footer">
			Already have an account? <a href="faculty_login.php">Sign in here</a><br>
			<!-- Correct cross-folder path from faculty/ to student/ -->
			<a href="../student/student_login.php" style="color:var(--muted);">Student? Register here instead</a>
			<br>
			<span class="secure-badge"><i class="fa-solid fa-circle-check"></i> Secured &amp; Encrypted Connection</span>
		</div>
	</div>

	<script>
	function setRole(r){
		document.getElementById('role_input').value=r;
		document.getElementById('tab-teacher').classList.toggle('active',r==='teacher');
		document.getElementById('tab-staff').classList.toggle('active',r==='staff');
		document.getElementById('regBtnLabel').textContent=r==='teacher'?'Create Teacher Account':'Create Staff Account';
	}
	function togglePw(id,ic){const e=document.getElementById(id),i=document.getElementById(ic);e.type=e.type==='password'?'text':'password';i.className=e.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash';}
	function previewPhoto(input){if(input.files&&input.files[0]){const r=new FileReader();r.onload=e=>{const img=document.getElementById('photoImg'),ic=document.getElementById('phIcon');img.src=e.target.result;img.style.display='block';ic.style.display='none';};r.readAsDataURL(input.files[0]);}}
	function checkStrength(v){const b=document.getElementById('strengthFill'),l=document.getElementById('strengthLabel'),w=document.getElementById('pwStrength');w.style.display='block';let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;const lv=[{w:'20%',bg:'#f87171',lb:'Weak'},{w:'45%',bg:'#fb923c',lb:'Fair'},{w:'70%',bg:'#facc15',lb:'Good'},{w:'100%',bg:'#4ade80',lb:'Strong'}][Math.max(0,s-1)];b.style.width=lv.w;b.style.background=lv.bg;l.textContent=lv.lb;l.style.color=lv.bg;}
	document.getElementById('regForm').addEventListener('input',function(){const hp=document.getElementById('full_name').value.trim()&&document.getElementById('username').value.trim();const hs=document.getElementById('password').value.length>=8;document.getElementById('step1').className=hp?'step done':'step active';document.getElementById('step2').className=hs?'step done':(hp?'step active':'step');});
	</script>
	</body>
	</html>
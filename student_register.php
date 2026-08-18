<?php
	// student/student_register.php
	session_start();
	require_once 'db.php';

	$error   = '';
	$success = '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$full_name  = trim($_POST['full_name']  ?? '');
		$username   = trim($_POST['username']   ?? '');
		$email      = trim($_POST['email']      ?? '');
		$password   = $_POST['password']        ?? '';
		$confirm_pw = $_POST['confirm_password']?? '';
		$department = trim($_POST['department'] ?? 'JHS');
		$year_level = trim($_POST['year_level'] ?? '');

		// Map the department tab (JHS/SHS/College) to the education_level
		// enum used everywhere else in the system (junior_high/senior_high/college).
		// This is what student_tracker.php filters on -- without it, new
		// accounts get education_level = NULL and never show up under any
		// level tab.
		$dept_to_level = [
			'JHS'     => 'junior_high',
			'SHS'     => 'senior_high',
			'College' => 'college',
		];
		$education_level = $dept_to_level[$department] ?? null;

		// Basic validation
		if (empty($full_name))  { $error = "Full name is required."; }
		elseif (empty($username))  { $error = "Username is required."; }
		elseif (empty($password))  { $error = "Password is required."; }
		elseif (strlen($password) < 8) { $error = "Password must be at least 8 characters."; }
		elseif ($password !== $confirm_pw) { $error = "Passwords do not match."; }
		elseif (empty($year_level)) { $error = "Please select your year/grade level."; }
		else {
			// Check duplicate username
			$chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
			$chk->bind_param("s", $username);
			$chk->execute();
			$chk->store_result();
			if ($chk->num_rows > 0) {
				$error = "Username already taken. Please choose another.";
			}
			$chk->close();

			// Check duplicate email only if provided
			if (empty($error) && !empty($email)) {
				$chk2 = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
				$chk2->bind_param("s", $email);
				$chk2->execute();
				$chk2->store_result();
				if ($chk2->num_rows > 0) {
					$error = "Email already registered.";
				}
				$chk2->close();
			}
		}

		// Insert if no errors
		if (empty($error)) {
			$hash = password_hash($password, PASSWORD_DEFAULT);

			$stmt = $mysqli->prepare(
				"INSERT INTO users
				 (full_name, username, email, password_hash, role, designation, department, education_level, year_level, is_active)
				 VALUES (?, ?, ?, ?, 'student', 'Student', ?, ?, ?, 1)"
			);
			$stmt->bind_param(
				"sssssss",
				$full_name,
				$username,
				$email,
				$hash,
				$department,
				$education_level,
				$year_level
			);

			if ($stmt->execute()) {
				$stmt->close();
				$mysqli->close();
				$_SESSION['reg_success'] = "Account created! Please log in with your username and password.";
				header("Location: student_login.php");
				exit;
			} else {
				$error = "Registration failed: " . $mysqli->error;
				$stmt->close();
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
	<title>PBI — Student Registration</title>
	<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
	<style>
	:root{--dark-blue:#0A192F;--blue-mid:#172A45;--blue-inner:#0F1F3D;--gold:#D97706;--gold-hover:#F59E0B;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
	*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
	body{min-height:100vh;background:var(--dark-blue);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:40px 20px;position:relative;overflow-x:hidden;}
	.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(217,119,6,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(217,119,6,.05) 1px,transparent 1px);background-size:48px 48px;animation:gridShift 25s linear infinite;}
	@keyframes gridShift{0%{background-position:0 0}100%{background-position:48px 48px}}
	.orb{position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
	.orb-1{width:380px;height:380px;background:radial-gradient(circle,rgba(217,119,6,.17) 0%,transparent 70%);bottom:-80px;right:-80px;animation:o1 16s ease-in-out infinite;}
	.orb-2{width:280px;height:280px;background:radial-gradient(circle,rgba(43,108,176,.14) 0%,transparent 70%);top:-60px;left:-60px;animation:o2 20s ease-in-out infinite;}
	@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(-22px,-18px)}}
	@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(18px,16px)}}
	.reg-card{position:relative;z-index:10;background:rgba(23,42,69,.88);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.09);border-radius:20px;padding:44px 44px 40px;width:100%;max-width:580px;box-shadow:var(--shadow);animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;}
	@keyframes cardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}
	.card-header{text-align:center;margin-bottom:24px;}
	.logo-ring{width:68px;height:68px;border-radius:50%;display:block;object-fit:cover;border:2.5px solid var(--gold);box-shadow:0 0 20px rgba(217,119,6,.4);margin:0 auto 14px;}
	.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
	.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:4px;}
	.dept-tabs{display:flex;gap:5px;background:rgba(10,25,47,.6);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:4px;margin-bottom:20px;}
	.dept-tab{flex:1;padding:8px 6px;border:none;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .25s;background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:5px;}
	.dept-tab.active{background:var(--gold);color:#fff;box-shadow:0 2px 10px rgba(217,119,6,.4);}
	.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(217,119,6,.4),transparent);margin-bottom:20px;}
	.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
	.form-grid .full{grid-column:1/-1;}
	.form-group{display:flex;flex-direction:column;gap:6px;}
	.form-label{font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);}
	.req{color:#f87171;}
	.input-wrap{position:relative;}
	.input-wrap .f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
	.form-input{width:100%;padding:11px 13px 11px 38px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;appearance:none;}
	.form-input::placeholder{color:rgba(160,179,198,.42);}
	.form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(217,119,6,.18);}
	.select-arr::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;font-size:11px;}
	.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0;}
	.alert{display:flex;align-items:flex-start;gap:9px;border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:16px;}
	.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
	.btn-register{width:100%;padding:13px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:18px;transition:background .2s,transform .15s;box-shadow:0 4px 16px rgba(217,119,6,.38);display:flex;align-items:center;justify-content:center;gap:8px;}
	.btn-register:hover{background:var(--gold-hover);transform:translateY(-1px);}
	.card-footer{text-align:center;margin-top:20px;font-size:12px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:16px;}
	.card-footer a{color:var(--gold-hover);text-decoration:none;font-weight:600;}
	.college-only{display:none;}
	@media(max-width:540px){.reg-card{padding:28px 16px 24px;}.form-grid{grid-template-columns:1fr;}.form-grid .full{grid-column:1;}}
	</style>
	</head>
	<body>
	<div class="bg-grid"></div>
	<div class="orb orb-1"></div>
	<div class="orb orb-2"></div>

	<div class="reg-card">
		<div class="card-header">
			<img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
			<div class="card-title">Student Registration</div>
			<div class="card-subtitle">Pandan Bay Institute — Evaluation System</div>
		</div>

		<div class="dept-tabs">
			<button class="dept-tab active" id="tab-jhs"     type="button" onclick="setDept('JHS')"><i class="fa-solid fa-school"></i> JHS</button>
			<button class="dept-tab"        id="tab-shs"     type="button" onclick="setDept('SHS')"><i class="fa-solid fa-graduation-cap"></i> SHS</button>
			<button class="dept-tab"        id="tab-college" type="button" onclick="setDept('College')"><i class="fa-solid fa-building-columns"></i> College</button>
		</div>
		<div class="divider"></div>

		<?php if ($error): ?>
		<div class="alert alert-error">
			<i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
			<span><?= htmlspecialchars($error) ?></span>
		</div>
		<?php endif; ?>

		<form method="POST" action="student_register.php" id="regForm" autocomplete="off">
			<input type="hidden" name="department" id="dept_input" value="JHS"/>

			<div class="form-grid">
				<div class="form-group full">
					<label class="form-label">Full Name <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="text" name="full_name"
							   placeholder="Last Name, First Name M.I." required
							   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
						<i class="fa-solid fa-id-card f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Year / Grade Level <span class="req">*</span></label>
					<div class="input-wrap select-arr">
						<select class="form-input" name="year_level" id="year_level" required>
							<option value="" disabled selected>Select level</option>
						</select>
						<i class="fa-solid fa-layer-group f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Username <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="text" name="username"
							   placeholder="Choose a username" required
							   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
						<i class="fa-solid fa-user f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Email Address</label>
					<div class="input-wrap">
						<input class="form-input" type="email" name="email"
							   placeholder="your@email.com (optional)"
							   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
						<i class="fa-solid fa-envelope f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Password <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="pw1" name="password"
							   placeholder="Min. 8 characters" required/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('pw1','e1')">
							<i class="fa-solid fa-eye" id="e1"></i>
						</button>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Confirm Password <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="pw2" name="confirm_password"
							   placeholder="Re-enter password" required/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('pw2','e2')">
							<i class="fa-solid fa-eye" id="e2"></i>
						</button>
					</div>
				</div>
			</div>

			<button type="submit" class="btn-register">
				<i class="fa-solid fa-user-plus"></i>
				<span id="regBtnLabel">Create JHS Account</span>
			</button>
		</form>

		<div class="card-footer">
			Already have an account? <a href="student_login.php">Sign in here</a><br><br>
			<a href="../faculty/faculty_login.php" style="color:var(--muted)">Faculty / Staff? Register here</a>
		</div>
	</div>

	<script>
	const levels = {
		JHS:     ['Grade 7','Grade 8','Grade 9','Grade 10'],
		SHS:     ['Grade 11','Grade 12'],
		College: ['1st Year','2nd Year','3rd Year','4th Year']
	};
	function setDept(d) {
		['JHS','SHS','College'].forEach(x =>
			document.getElementById('tab-' + x.toLowerCase()).classList.toggle('active', x === d)
		);
		document.getElementById('dept_input').value = d;
		document.getElementById('regBtnLabel').textContent = 'Create ' + d + ' Account';
		const sel = document.getElementById('year_level');
		sel.innerHTML = '<option value="" disabled selected>Select level</option>';
		levels[d].forEach(l => {
			const o = document.createElement('option');
			o.value = l; o.textContent = l; sel.appendChild(o);
		});
	}
	function togglePw(id, ic) {
		const e = document.getElementById(id), i = document.getElementById(ic);
		e.type = e.type === 'password' ? 'text' : 'password';
		i.className = e.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
	}
	setDept('JHS');
	</script>
	</body>
	</html>
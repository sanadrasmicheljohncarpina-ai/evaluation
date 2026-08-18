<?php
// principal/principal_register.php
session_start();
require_once 'db.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $educationLevel = $_POST['education_level'] ?? '';

        if (!in_array($educationLevel, ['junior_high', 'senior_high', 'both'], true)) {
            $error = 'Please select a valid assigned school level.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = ems_register_account($mysqli, 'principal', [
                'full_name'       => $fullName,
                'employee_id'     => $employeeId,
                'username'        => $username,
                'email'           => $email,
                'password'        => $password,
                'education_level' => $educationLevel,
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
<meta charset="UTF-8">
<title>Principal Registration — PBI Evaluation System</title>
<style>
    :root {
        --pbi-navy: #0f1f3d;
        --pbi-navy-light: #16294f;
        --pbi-amber: #d99a2b;
        --pbi-amber-light: #f0b84d;
        --pbi-text: #e8ecf5;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--pbi-navy) 0%, #081226 100%);
        font-family: 'Segoe UI', Arial, sans-serif;
        color: var(--pbi-text);
        padding: 24px 0;
    }
    .card {
        width: 440px;
        background: var(--pbi-navy-light);
        border-radius: 12px;
        padding: 36px 32px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.4);
        border-top: 4px solid var(--pbi-amber);
    }
    .card h1 {
        font-size: 1.3rem;
        margin: 0 0 4px;
        color: var(--pbi-amber-light);
    }
    .card p.sub {
        margin: 0 0 24px;
        font-size: 0.85rem;
        color: #a7b1c9;
    }
    label {
        display: block;
        font-size: 0.8rem;
        margin-bottom: 6px;
        color: #c3cbdc;
    }
    input[type=text], input[type=email], input[type=password], select {
        width: 100%;
        padding: 10px 12px;
        margin-bottom: 16px;
        border-radius: 6px;
        border: 1px solid #2a3a5f;
        background: #0d1930;
        color: var(--pbi-text);
        font-size: 0.9rem;
    }
    input:focus, select:focus { outline: 2px solid var(--pbi-amber); }
    .row { display: flex; gap: 12px; }
    .row > div { flex: 1; }
    button {
        width: 100%;
        padding: 11px;
        border: none;
        border-radius: 6px;
        background: var(--pbi-amber);
        color: #1a1204;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        margin-top: 6px;
    }
    button:hover { background: var(--pbi-amber-light); }
    .error {
        background: #4a1c1c;
        color: #ffb4b4;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        margin-bottom: 18px;
    }
    .success {
        background: #1c3a2a;
        color: #a9f0c4;
        padding: 14px;
        border-radius: 6px;
        font-size: 0.9rem;
        line-height: 1.5;
    }
    .links {
        margin-top: 18px;
        text-align: center;
        font-size: 0.8rem;
    }
    .links a { color: var(--pbi-amber-light); text-decoration: none; }
    .links a:hover { text-decoration: underline; }
</style>
</head>
<body>
    <div class="card">
        <h1>Principal Registration</h1>
        <p class="sub">Junior High &amp; Senior High Evaluation Oversight</p>

        <?php if ($success): ?>
            <div class="success">
                Registration submitted. Your account is <strong>pending administrator approval</strong>
                — you'll be able to log in once approved.
            </div>
            <div class="links"><a href="principal_login.php">Back to Login</a></div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="principal_register.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">

                <div class="row">
                    <div>
                        <label for="employee_id">Employee ID</label>
                        <input type="text" id="employee_id" name="employee_id" required
                               value="<?= htmlspecialchars($_POST['employee_id'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>

                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

                <div class="row">
                    <div>
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required minlength="8">
                    </div>
                    <div>
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                </div>

                <label for="education_level">Assigned School Level</label>
                <select id="education_level" name="education_level" required>
                    <option value="">— Select —</option>
                    <option value="junior_high">Junior High</option>
                    <option value="senior_high">Senior High</option>
                    <option value="both">Both</option>
                </select>

                <button type="submit">Submit Registration</button>
            </form>

            <div class="links"><a href="principal_login.php">Already have an account? Log In</a></div>
        <?php endif; ?>
    </div>
</body>
</html>
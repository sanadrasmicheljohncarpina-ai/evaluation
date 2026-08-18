<?php
require_once 'principal_common.php';

$errors = [];
$success = null;

// ═══════════════════════════════════════════════════════════
// HANDLE FORM SUBMISSIONS
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $newName  = trim($_POST['full_name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');

        if ($newName === '') $errors[] = "Full name can't be empty.";
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";

        if (empty($errors)) {
            try {
                $stmt = $mysqli->prepare("UPDATE users SET full_name=?, email=? WHERE id=?");
                $stmt->bind_param("ssi", $newName, $newEmail, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
                $me['full_name'] = $newName;
                $me['email'] = $newEmail;
                $success = "Profile updated.";
            } catch (mysqli_sql_exception $e) {
                $errors[] = "That email address may already be in use.";
            }
        }
    }

    if ($action === 'change_photo' && !empty($_FILES['photo']['name'])) {
        $file = $_FILES['photo'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($file['tmp_name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Photo upload failed. Please try again.";
        } elseif (!isset($allowed[$mime])) {
            $errors[] = "Please upload a JPG, PNG, or WEBP image.";
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = "Photo must be smaller than 5MB.";
        } else {
            $destDir = __DIR__ . '/../image';
            if (!is_dir($destDir)) { @mkdir($destDir, 0755, true); }
            $filename = 'principal_' . $_SESSION['user_id'] . '_' . time() . '.' . $allowed[$mime];
            if (move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) {
                $stmt = $mysqli->prepare("UPDATE users SET photo=? WHERE id=?");
                $stmt->bind_param("si", $filename, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
                $me['photo'] = $filename;
                $photo_src = '../image/' . $filename;
                $success = "Profile photo updated.";
            } else {
                $errors[] = "Couldn't save the uploaded photo. Please try again.";
            }
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = safe_scalar($mysqli, "SELECT password FROM users WHERE id=?", "i", [$_SESSION['user_id']]);

        if ($row === null) {
            $errors[] = "Couldn't verify your current password. Please try again.";
        } elseif (!password_verify($current, $row)) {
            $errors[] = "Your current password is incorrect.";
        } elseif (strlen($new) < 8) {
            $errors[] = "New password must be at least 8 characters.";
        } elseif ($new !== $confirm) {
            $errors[] = "New password and confirmation don't match.";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed, $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();
            $success = "Password changed.";
        }
    }
}

html_head_open('PBI — Account Settings');
render_principal_sidebar('settings', $me, $scopeLabel, $photo_src);
?>
<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Account Settings</div>
            <div class="page-sub">Manage your profile and login credentials</div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="section">
        <h2><i class="fa-solid fa-id-badge"></i> Profile Photo</h2>
        <div class="profile-card">
            <img class="profile-photo-lg" src="<?= htmlspecialchars($photo_src) ?>" alt="">
            <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                <input type="hidden" name="action" value="change_photo">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn-primary" type="submit" style="width:fit-content;">Upload New Photo</button>
            </form>
        </div>
    </div>

    <div class="section">
        <h2><i class="fa-solid fa-user-pen"></i> Profile Details</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($me['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($me['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($me['username'] ?? '') ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Designation</label>
                    <input type="text" value="<?= htmlspecialchars($me['designation'] ?? '') ?>" disabled>
                </div>
            </div>
            <button class="btn-primary" type="submit">Save Changes</button>
        </form>
    </div>

    <div class="section">
        <h2><i class="fa-solid fa-lock"></i> Change Password</h2>
        <form method="post">
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" minlength="8" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" minlength="8" required>
                </div>
            </div>
            <button class="btn-primary" type="submit">Update Password</button>
        </form>
    </div>
</main>
</body></html>
<?php $mysqli->close(); ?>
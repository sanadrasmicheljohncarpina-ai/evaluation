<?php
// shared/AuthenticationService.php
// Place this file inside: htdocs/index/shared/
// Included automatically by principal/db.php and dean/db.php.
//
// Centralizes login, registration, and approval-gating logic so that
// Principal and Dean (and any future role) hit the same verified code path
// instead of duplicating password/session handling per module.
//
// Every function takes $mysqli as the first argument rather than opening
// its own connection — this file has no side effects on include.

/**
 * Verify a login attempt for a specific role.
 *
 * @param mysqli $mysqli
 * @param string $username
 * @param string $password  Plaintext password from the login form.
 * @param string $expectedRole  e.g. 'principal' or 'dean'
 * @return array{ok: bool, user?: array, error?: string}
 */
function ems_verify_login(mysqli $mysqli, string $username, string $password, string $expectedRole): array
{
    $stmt = $mysqli->prepare(
        "SELECT id, username, password_hash, full_name, role, account_status,
                education_level, department, designation, employee_id,
                year_level, secondary_role
         FROM users
         WHERE username = ? AND role = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param("ss", $username, $expectedRole);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // Deliberately generic — don't reveal whether the username exists.
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if ($user['account_status'] !== 'approved') {
        $status = $user['account_status'];
        $msg = $status === 'pending'
            ? 'Your account is awaiting administrator approval.'
            : 'Your account has been blocked. Contact the system administrator.';
        return ['ok' => false, 'error' => $msg];
    }

    unset($user['password_hash']); // never hand the hash back to the caller
    return ['ok' => true, 'user' => $user];
}

/**
 * Register a new Principal or Dean account. Always lands in 'pending' status —
 * same self-registration + admin-approval workflow used elsewhere in PBI.
 *
 * @param mysqli $mysqli
 * @param string $role  'principal' or 'dean'
 * @param array $fields Expected keys:
 *   Common:   full_name, employee_id, username, email, password
 *   Principal: education_level ('junior_high'|'senior_high'|'both')
 *   Dean:      department, designation
 *   (course/section no longer live on `users` — a dean's program/course
 *   assignments now belong in the `teaching_assignments` table.)
 * @return array{ok: bool, error?: string}
 */
function ems_register_account(mysqli $mysqli, string $role, array $fields): array
{
    $required = ['full_name', 'employee_id', 'username', 'email', 'password'];
    foreach ($required as $key) {
        if (empty($fields[$key])) {
            return ['ok' => false, 'error' => "Missing required field: $key"];
        }
    }

    if ($role === 'principal' && empty($fields['education_level'])) {
        return ['ok' => false, 'error' => 'Assigned school level is required.'];
    }
    if ($role === 'dean' && empty($fields['department'])) {
        return ['ok' => false, 'error' => 'Department is required.'];
    }

    // Uniqueness check across username and email regardless of role.
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $fields['username'], $fields['email']);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Username or email is already registered.'];
    }
    $stmt->close();

    $passwordHash = password_hash($fields['password'], PASSWORD_DEFAULT);
    $educationLevel = $fields['education_level'] ?? ($role === 'dean' ? 'college' : null);
    // department/designation don't apply to every role (e.g. Principal has
    // no designation on registration). `department` is nullable in the
    // current schema, so we pass through null rather than forcing ''.
    $department = $fields['department'] ?? null;
    $designation = $fields['designation'] ?? null;

    $stmt = $mysqli->prepare(
        "INSERT INTO users
            (username, password_hash, full_name, email, sector, role,
             education_level, department, designation, employee_id,
             registration_source, source, account_status)
         VALUES (?, ?, ?, ?, 'Student', ?, ?, ?, ?, ?, 'self', 'self', 'pending')"
    );
    // 'sector' has no ENUM value for Principal/Dean and isn't referenced
    // outside faculty/staff/student pages (confirmed via grep) — left at
    // its harmless default rather than altering the ENUM.
    $stmt->bind_param(
        "sssssssss",
        $fields['username'],
        $passwordHash,
        $fields['full_name'],
        $fields['email'],
        $role,
        $educationLevel,
        $department,
        $designation,
        $fields['employee_id']
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'Registration failed. Please try again.'];
}

/**
 * Populate $_SESSION after a successful login. Call session_start() before this.
 */
function ems_start_authenticated_session(array $user): void
{
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['account_status'] = $user['account_status'];
    $_SESSION['education_level'] = $user['education_level'];
    $_SESSION['department'] = $user['department'];
}
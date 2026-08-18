<?php
// admin/session_bootstrap.php
// Place this file inside: htdocs/index/admin/  (same folder as admin_login.php, db.php)
//
// Shared guard for the admin/superadmin/registrar workspace.
// Include at the top of any protected admin page with:
//   require_once 'session_bootstrap.php';
//
// This starts the session, connects to the DB ($mysqli), and makes sure
// the visitor is logged in as one of the allowed roles. Pages that need a
// tighter guard (e.g. teaching_assignments.php restricting to
// role==='superadmin' only) should add their own extra check AFTER this
// require, the same way teaching_assignments.php already does.

session_start();

require_once __DIR__ . '/db.php';

// Not logged in, or logged in under a role that doesn't belong in this
// workspace at all -> bounce to the login page.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'registrar'], true)) {
    header("Location: admin_login.php");
    exit;
}
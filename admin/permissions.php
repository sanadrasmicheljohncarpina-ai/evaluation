<?php
// admin/permissions.php
// Include this AFTER db.php and AFTER session_start() in every gated page.
// Usage at top of a gated page:
//
//   require_once 'permissions.php';
//   $can_edit = admin_can_edit($mysqli, 'user_management');
//
// Then wrap any add/edit/delete UI (buttons, forms, action links) in:
//   if ($can_edit) { ... show edit controls ... }
// And re-check $can_edit at the top of every POST/GET action handler
// before it touches the database (see notes in each page's diff).

/**
 * Returns true if the current logged-in user is allowed to EDIT the given feature.
 * - superadmin: always true, every feature.
 * - admin: true only if admin_permissions.admin_can_edit = 1 for that feature_key.
 * - anyone else (shouldn't reach gated pages, but fail safe): false.
 */
function admin_can_edit(mysqli $mysqli, string $feature_key): bool {
    $role = $_SESSION['role'] ?? '';

    if ($role === 'superadmin') {
        return true; // superadmin always has full access, no DB lookup needed
    }

    if ($role !== 'admin') {
        return false; // registrar or anything else: no edit rights on these admin-only features
    }

    $stmt = $mysqli->prepare("SELECT admin_can_edit FROM admin_permissions WHERE feature_key = ? LIMIT 1");
    $stmt->bind_param("s", $feature_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // If no row exists for this feature yet, default to view-only (fail safe = restrictive)
    return $row ? (bool)$row['admin_can_edit'] : false;
}

/**
 * Renders the small "view-only" notice banner used at the top of gated pages
 * when the logged-in admin does not have edit rights for that page.
 * Call this right after <body> or right after your page header, only when $can_edit is false.
 */
function render_view_only_banner(string $feature_label): void {
    ?>
    <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);border-radius:10px;
                padding:12px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:center;
                font-size:13px;color:#fbbf24;font-family:'DM Sans',sans-serif;">
        <i class="fa-solid fa-lock" style="flex-shrink:0"></i>
        <span>
            <strong>View-only access.</strong>
            You can browse <?= htmlspecialchars($feature_label) ?>, but editing has been disabled by the Super Admin for your account.
        </span>
    </div>
    <?php
}
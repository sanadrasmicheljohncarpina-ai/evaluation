<?php
// admin/manage_periods.php
// Include this as a tab in your admin questionnaire page,
// or link to it from the admin dashboard Quick Actions.
session_start();
require_once 'db.php';

// Session check handled by parent admin/index.php

// ── AUTO-CREATE TABLE ─────────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS evaluation_periods (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_label  VARCHAR(100) NOT NULL,
    semester      VARCHAR(50)  NOT NULL DEFAULT '1st Semester',
    school_year   VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
    is_active     TINYINT(1)   NOT NULL DEFAULT 0,
    start_date    DATE         NULL,
    end_date      DATE         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$toast = '';

// ── ACTIONS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label  = trim($_POST['period_label'] ?? '');
        $sem    = $_POST['semester']    ?? '1st Semester';
        $sy     = trim($_POST['school_year'] ?? '2025-2026');
        $start  = $_POST['start_date']  ?: null;
        $end    = $_POST['end_date']    ?: null;

        if ($label) {
            $stmt = $mysqli->prepare("INSERT INTO evaluation_periods (period_label, semester, school_year, start_date, end_date) VALUES (?,?,?,?,?)");
            $stmt->bind_param("sssss", $label, $sem, $sy, $start, $end);
            $stmt->execute(); $stmt->close();
            $toast = "Period '$label' created successfully.";
        }
    }

    if ($action === 'activate') {
        $id = intval($_POST['period_id']);
        // Deactivate all first, then activate the chosen one
        $mysqli->query("UPDATE evaluation_periods SET is_active=0");
        $mysqli->query("UPDATE evaluation_periods SET is_active=1 WHERE id=$id");
        $toast = "Evaluation period activated. Students and faculty can now submit evaluations.";
    }

    if ($action === 'deactivate') {
        $id = intval($_POST['period_id']);
        $mysqli->query("UPDATE evaluation_periods SET is_active=0 WHERE id=$id");
        $toast = "Evaluation period closed.";
    }

    if ($action === 'delete') {
        $id = intval($_POST['period_id']);
        $mysqli->query("DELETE FROM evaluation_periods WHERE id=$id");
        $toast = "Period deleted.";
    }

    $_SESSION['period_toast'] = $toast;
    header("Location: manage_periods.php"); exit;
}

$toast = $_SESSION['period_toast'] ?? ''; unset($_SESSION['period_toast']);

// ── FETCH PERIODS ─────────────────────────────────────────────
$periods = $mysqli->query("SELECT * FROM evaluation_periods ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Count submissions per period
// NOTE: evaluation_submissions is deprecated -- student, staff-peer,
// and faculty-peer evaluations now all live in evaluation_tracker,
// tagged by period_id.
$sub_counts = [];
$sc = $mysqli->query("SELECT period_id, COUNT(*) as c FROM evaluation_tracker WHERE period_id IS NOT NULL GROUP BY period_id");
if ($sc) while ($r = $sc->fetch_assoc()) $sub_counts[$r['period_id']] = $r['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Evaluation Periods — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--accent:#2B6CB0;--teal:#0D9488;--teal-h:#14B8A6;--light:#E0E6F0;--muted:#A0B3C6;--danger:#F05454;--border:rgba(255,255,255,0.08);--radius:10px;--shadow:0 4px 20px rgba(0,0,0,0.35);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;padding:32px 28px;}
.toast{border-radius:8px;padding:12px 18px;font-size:13px;margin-bottom:24px;display:flex;align-items:center;gap:8px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--border);}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;margin-bottom:4px;}
.page-header p{font-size:13px;color:var(--muted);}

/* CREATE FORM */
.create-panel{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:28px;}
.create-panel h2{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:14px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);}
.form-input{padding:10px 13px;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.form-input:focus{border-color:var(--teal);}
.form-input::placeholder{color:rgba(160,179,198,.4);}
.btn-create{background:var(--teal);color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:'DM Sans',sans-serif;transition:background .2s;}
.btn-create:hover{background:var(--teal-h);}

/* PERIODS LIST */
.period-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.period-card.active-period{border-color:rgba(13,148,136,.4);background:linear-gradient(135deg,var(--mid) 0%,rgba(13,148,136,.08) 100%);}
.period-main{flex:1;min-width:200px;}
.period-label-text{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:3px;}
.period-meta{font-size:12px;color:var(--muted);}
.status-badge{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.status-active{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);}
.status-closed{background:rgba(160,179,198,.1);color:var(--muted);border:1px solid var(--border);}
.period-stats{display:flex;gap:20px;flex-wrap:wrap;}
.period-stat{text-align:center;}
.period-stat-val{font-size:20px;font-weight:700;color:#fff;}
.period-stat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.period-actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn-activate{background:rgba(13,148,136,.15);border:1px solid rgba(13,148,136,.35);color:var(--teal-h);padding:7px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;}
.btn-activate:hover{background:var(--teal);color:#fff;border-color:var(--teal);}
.btn-deactivate{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);color:#fcd34d;padding:7px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;}
.btn-deactivate:hover{background:rgba(251,191,36,.2);}
.btn-del{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.25);color:#f87171;padding:7px 12px;border-radius:7px;font-size:12px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;}
.btn-del:hover{background:rgba(240,84,84,.2);}
.empty-state{text-align:center;padding:48px;color:var(--muted);}
.empty-state i{font-size:36px;opacity:.3;display:block;margin-bottom:12px;}

/* INFO BOX */
.info-box{background:rgba(43,108,176,.1);border:1px solid rgba(43,108,176,.25);border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:13px;color:var(--muted);display:flex;gap:10px;align-items:flex-start;}
.info-box i{color:#60a5fa;flex-shrink:0;margin-top:1px;}

@media(max-width:600px){body{padding:20px 14px;}.form-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php if ($toast): ?>
<div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>Evaluation Periods</h1>
        <p>Create and manage evaluation periods — only one period can be active at a time</p>
    </div>
</div>

<div class="info-box">
    <i class="fa-solid fa-circle-info"></i>
    <span>When a period is <strong style="color:var(--light)">Active</strong>, students and faculty can submit evaluations. When <strong style="color:var(--light)">Closed</strong>, the system is in read-only mode — existing results are still visible but no new submissions are accepted. Only one period can be active at a time.</span>
</div>

<!-- CREATE PERIOD -->
<div class="create-panel">
    <h2><i class="fa-solid fa-calendar-plus" style="color:var(--teal)"></i> Create New Period</h2>
    <form method="POST">
        <input type="hidden" name="action" value="create"/>
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">Period Label <span style="color:#f87171">*</span></label>
                <input class="form-input" type="text" name="period_label" placeholder="e.g. 1st Semester 2025-2026" required/>
            </div>
            <div class="form-group">
                <label class="form-label">Semester</label>
                <select class="form-input" name="semester">
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                    <option value="Summer">Summer</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">School Year</label>
                <input class="form-input" type="text" name="school_year" placeholder="e.g. 2025-2026" value="2025-2026"/>
            </div>
            <div class="form-group">
                <label class="form-label">Start Date</label>
                <input class="form-input" type="date" name="start_date"/>
            </div>
            <div class="form-group">
                <label class="form-label">End Date</label>
                <input class="form-input" type="date" name="end_date"/>
            </div>
        </div>
        <button type="submit" class="btn-create"><i class="fa-solid fa-plus"></i> Create Period</button>
    </form>
</div>

<!-- PERIODS LIST -->
<div style="font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:14px;">
    All Evaluation Periods
    <span style="font-size:13px;font-weight:400;color:var(--muted);font-family:'DM Sans',sans-serif;margin-left:8px;">(<?= count($periods) ?> total)</span>
</div>

<?php if (empty($periods)): ?>
<div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><p>No evaluation periods created yet.<br>Create your first period above.</p></div>
<?php else: ?>
<?php foreach ($periods as $p): $is_active = (bool)$p['is_active']; ?>
<div class="period-card <?= $is_active ? 'active-period' : '' ?>">
    <div class="period-main">
        <div class="period-label-text"><?= htmlspecialchars($p['period_label']) ?></div>
        <div class="period-meta">
            <?= htmlspecialchars($p['semester']) ?> · <?= htmlspecialchars($p['school_year']) ?>
            <?php if ($p['start_date']): ?> · <?= date('M d, Y', strtotime($p['start_date'])) ?> – <?= $p['end_date'] ? date('M d, Y', strtotime($p['end_date'])) : 'ongoing' ?><?php endif; ?>
        </div>
        <div style="margin-top:8px;">
            <?php if ($is_active): ?>
            <span class="status-badge status-active"><i class="fa-solid fa-circle" style="font-size:7px"></i> Active</span>
            <?php else: ?>
            <span class="status-badge status-closed"><i class="fa-solid fa-circle" style="font-size:7px"></i> Closed</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="period-stats">
        <div class="period-stat">
            <div class="period-stat-val"><?= $sub_counts[$p['id']] ?? 0 ?></div>
            <div class="period-stat-lbl">Submissions</div>
        </div>
    </div>

    <div class="period-actions">
        <?php if (!$is_active): ?>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="activate"/>
            <input type="hidden" name="period_id" value="<?= $p['id'] ?>"/>
            <button type="submit" class="btn-activate"><i class="fa-solid fa-play"></i> Activate</button>
        </form>
        <?php else: ?>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="deactivate"/>
            <input type="hidden" name="period_id" value="<?= $p['id'] ?>"/>
            <button type="submit" class="btn-deactivate"><i class="fa-solid fa-pause"></i> Close Period</button>
        </form>
        <?php endif; ?>

        <?php if (!$is_active && ($sub_counts[$p['id']] ?? 0) === 0): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Delete this period? This cannot be undone.')">
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="period_id" value="<?= $p['id'] ?>"/>
            <button type="submit" class="btn-del"><i class="fa-solid fa-trash-can"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php $mysqli->close(); ?>
</body>
</html>
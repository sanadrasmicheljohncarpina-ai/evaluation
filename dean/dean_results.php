<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// ── dean_results.php (Phase 2, new page) ────────────────────────────
// The Dean's OWN evaluation results — feedback the Dean received from
// Teachers (or whichever evaluator groups the evaluation policy defines
// as evaluating the Dean). This is a read-only "how am I doing" view,
// not an evaluation tool. Staff do not evaluate the Dean, so there is
// no Staff-results section here (per spec §6).
//
// ── CONFIDENTIALITY — NON-NEGOTIABLE ────────────────────────────────
// The Dean must NEVER be able to identify which Teacher submitted which
// response. Every query below intentionally does NOT join to the
// evaluator's identity (name/id/photo/department/account), and nothing
// derived from evaluator identity (timestamp-vs-other-students ordering,
// IP, etc.) is exposed. Responses are labeled only "Evaluation #1",
// "Evaluation #2", ... in submission order, or "Anonymous Response".

function safe_scalar(mysqli $mysqli, string $sql, string $types = '', array $params = []) {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return null; }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? reset($row) : null;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}
function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

$deanId = (int)$_SESSION['user_id'];

// ── DEAN PROFILE (for sidebar) ─────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, designation, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $deanId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$photo_src = !empty($me['photo']) ? UPLOAD_URL . $me['photo'] : UPLOAD_URL . 'pbi_logo';

// ── GLOBAL SYSTEM SETTINGS ──────────────────────────────────────────
$settings = get_system_settings($mysqli);
$period_id_int = $settings['period_id'] ?? 0;
$hasPeriod     = $period_id_int > 0;

const HIGHER_ED_LABEL = 'Higher Education';

// NOTE ON eval_type/eval_bucket VALUES BELOW:
// These follow the same 'evaluation_tracker' shape used throughout the
// Dean module (eval_type='student', eval_bucket='Faculty', level='college'
// for student→teacher rows). Results received BY the Dean presumably use
// a distinct eval_bucket (e.g. 'Dean') with eval_type='teacher' since the
// evaluator here is a Teacher, not a student. This is a placeholder
// convention — confirm the exact eval_type/eval_bucket values your
// evaluation_tracker actually uses for Teacher → Dean submissions and
// adjust the WHERE clauses below to match (search for "Dean" as a
// target_user_id with target role 'dean').
const DEAN_RESULT_EVAL_TYPE   = 'teacher';
const DEAN_RESULT_EVAL_BUCKET = 'Dean';

$overallAvg = null;
$responseCount = 0;
$categoryBreakdown = [];
$trend = [];
$history = [];

if ($hasPeriod) {
    // ── OVERALL AVERAGE (this period) ───────────────────────────────
    $overallAvgRaw = safe_scalar($mysqli, "
        SELECT AVG(score) v FROM evaluation_tracker
        WHERE eval_type=? AND eval_bucket=? AND status IN ('submitted','approved')
          AND target_user_id=? AND period_id=?
    ", "ssii", [DEAN_RESULT_EVAL_TYPE, DEAN_RESULT_EVAL_BUCKET, $deanId, $period_id_int]);
    $overallAvg = $overallAvgRaw !== null ? round((float)$overallAvgRaw, 2) : null;

    $responseCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM evaluation_tracker
        WHERE eval_type=? AND eval_bucket=? AND status IN ('submitted','approved')
          AND target_user_id=? AND period_id=?
    ", "ssii", [DEAN_RESULT_EVAL_TYPE, DEAN_RESULT_EVAL_BUCKET, $deanId, $period_id_int]) ?? 0);

    // ── CATEGORY BREAKDOWN ───────────────────────────────────────────
    // Assumes a per-question/category table (e.g. evaluation_answers)
    // keyed by category name + score, scoped to this dean/period. Adjust
    // the table/column names to match your actual questionnaire schema.
    $categoryBreakdown = safe_rows($mysqli, "
        SELECT category, AVG(score) avg_score, COUNT(*) n
        FROM evaluation_answers ea
        INNER JOIN evaluation_tracker et ON et.id = ea.tracker_id
        WHERE et.eval_type=? AND et.eval_bucket=? AND et.status IN ('submitted','approved')
          AND et.target_user_id=? AND et.period_id=?
        GROUP BY category
        ORDER BY category
    ", "ssii", [DEAN_RESULT_EVAL_TYPE, DEAN_RESULT_EVAL_BUCKET, $deanId, $period_id_int]);
    foreach ($categoryBreakdown as &$c) { $c['avg_score'] = round((float)$c['avg_score'], 2); }
    unset($c);

    // ── TREND (average rating per period, most recent periods) ──────
    // Scoped to this dean across all periods, not just the active one.
    $trend = safe_rows($mysqli, "
        SELECT ep.academic_term, ep.academic_year, AVG(et.score) avg_score
        FROM evaluation_tracker et
        INNER JOIN evaluation_periods ep ON ep.id = et.period_id
        WHERE et.eval_type=? AND et.eval_bucket=? AND et.status IN ('submitted','approved')
          AND et.target_user_id=?
        GROUP BY et.period_id, ep.academic_term, ep.academic_year
        ORDER BY ep.id ASC
        LIMIT 12
    ", "ssi", [DEAN_RESULT_EVAL_TYPE, DEAN_RESULT_EVAL_BUCKET, $deanId]);
    foreach ($trend as &$t) { $t['avg_score'] = round((float)$t['avg_score'], 2); }
    unset($t);

    // ── ANONYMOUS RESPONSE HISTORY ────────────────────────────────
    // Deliberately selects ONLY score + comment + submission order.
    // No evaluator_id, name, or any evaluator-identifying column is
    // selected — do not add one.
    $rawHistory = safe_rows($mysqli, "
        SELECT score, remarks AS comment, submitted_at
        FROM evaluation_tracker
        WHERE eval_type=? AND eval_bucket=? AND status IN ('submitted','approved')
          AND target_user_id=? AND period_id=?
        ORDER BY submitted_at ASC
    ", "ssii", [DEAN_RESULT_EVAL_TYPE, DEAN_RESULT_EVAL_BUCKET, $deanId, $period_id_int]);

    $n = 1;
    foreach ($rawHistory as $r) {
        $history[] = [
            'label'   => 'Evaluation #' . $n,
            'score'   => $r['score'] !== null ? round((float)$r['score'], 2) : null,
            'comment' => $r['comment'] ?: '',
        ];
        $n++;
    }
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — View Results</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

.sidebar{width:250px;flex-shrink:0;background:rgba(23,42,69,.9);border-right:1px solid rgba(255,255,255,.08);min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--violet);box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px;}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff;}
.sb-nav a i{width:18px;text-align:center;color:var(--violet-h);}
.sb-logout{margin-top:auto;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

.main{flex:1;padding:36px 44px;}
.page-header{margin-bottom:26px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

.confidentiality-note{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:12px;margin-bottom:26px;}
.confidentiality-note i{color:var(--good);font-size:18px;margin-top:2px;}
.confidentiality-note p{font-size:12.5px;color:var(--light);line-height:1.6;}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}

.cat-row{display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.cat-row:last-child{border-bottom:none;}
.cat-name{width:180px;font-size:13px;color:var(--light);flex-shrink:0;}
.bar-wrap{flex:1;background:rgba(255,255,255,.08);border-radius:6px;height:8px;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--violet-dark),var(--violet-h));border-radius:6px;}
.cat-score{width:48px;text-align:right;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;}

.trend-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;}
.trend-row:last-child{border-bottom:none;}
.trend-row .val{color:var(--violet-h);font-weight:700;}

.response-card{background:var(--inner);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:16px 18px;margin-bottom:12px;}
.response-card:last-child{margin-bottom:0;}
.response-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.response-label{font-size:12px;font-weight:700;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:6px;}
.response-score{font-size:13px;font-weight:700;color:#fff;background:rgba(124,95,217,.15);padding:2px 10px;border-radius:12px;}
.response-comment{font-size:13.5px;color:var(--light);line-height:1.6;font-style:italic;}

.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.cat-name{width:120px;}}
</style>
</head>
<body>

<?php
$active = 'results';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div class="page-title">View Results</div>
        <div class="page-sub">Your evaluation results, as submitted by Teachers this period.</div>
    </div>

    <div class="confidentiality-note">
        <i class="fa-solid fa-user-shield"></i>
        <p>
            Evaluator identities are never shown here. Responses below are numbered in submission
            order only — names, IDs, photos, departments, and timestamps that could identify who
            submitted a response are intentionally excluded.
        </p>
    </div>

    <?php if (!$hasPeriod): ?>
        <p class="empty-note">No active evaluation period right now.</p>
    <?php else: ?>

    <!-- OVERVIEW -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-star"></i><div class="num"><?= $overallAvg !== null ? $overallAvg : '—' ?></div><div class="label">Overall Average</div></div>
        <div class="stat-card"><i class="fa-solid fa-comments"></i><div class="num"><?= $responseCount ?></div><div class="label">Responses Received</div></div>
    </div>

    <!-- CATEGORY BREAKDOWN -->
    <div class="section">
        <h2><i class="fa-solid fa-list-check"></i> Category Breakdown</h2>
        <?php if (empty($categoryBreakdown)): ?>
            <p class="empty-note">No category-level results yet this period.</p>
        <?php else: ?>
            <?php foreach ($categoryBreakdown as $c): ?>
            <div class="cat-row">
                <div class="cat-name"><?= htmlspecialchars($c['category']) ?></div>
                <div class="bar-wrap"><div class="bar-fill" style="width:<?= min(100, ($c['avg_score'] / 5) * 100) ?>%"></div></div>
                <div class="cat-score"><?= htmlspecialchars((string)$c['avg_score']) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- EVALUATION TREND -->
    <div class="section">
        <h2><i class="fa-solid fa-chart-line"></i> Evaluation Trend</h2>
        <?php if (empty($trend)): ?>
            <p class="empty-note">Not enough history yet to show a trend.</p>
        <?php else: ?>
            <?php foreach ($trend as $t): ?>
            <div class="trend-row">
                <span><?= htmlspecialchars(trim($t['academic_term'] . ' ' . $t['academic_year'])) ?></span>
                <span class="val"><?= htmlspecialchars((string)$t['avg_score']) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ANONYMOUS RESPONSES -->
    <div class="section">
        <h2><i class="fa-solid fa-comment-dots"></i> Evaluation History — Anonymous Responses</h2>
        <?php if (empty($history)): ?>
            <p class="empty-note">No responses submitted yet this period.</p>
        <?php else: ?>
            <?php foreach ($history as $h): ?>
            <div class="response-card">
                <div class="response-head">
                    <span class="response-label"><i class="fa-solid fa-user-secret"></i> Anonymous — <?= htmlspecialchars($h['label']) ?></span>
                    <?php if ($h['score'] !== null): ?><span class="response-score"><?= htmlspecialchars((string)$h['score']) ?></span><?php endif; ?>
                </div>
                <?php if ($h['comment'] !== ''): ?>
                    <p class="response-comment">&ldquo;<?= htmlspecialchars($h['comment']) ?>&rdquo;</p>
                <?php else: ?>
                    <p class="empty-note">No written comment.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</main>
</body>
</html>
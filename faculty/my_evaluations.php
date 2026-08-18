<?php
session_start();

// Database Connection
$mysqli = new mysqli("localhost", "root", "", "school_db", 3306);
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// --- DUMMY SESSION FOR DEVELOPMENT ---
// Once your login system is active, remove these lines.
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Simulating Dr. Alan Ramos (Teacher)
    $_SESSION['name'] = "Dr. Alan Ramos";
    $_SESSION['designation'] = "Teacher";
}

$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['name'];
$currentUserDesignation = $_SESSION['designation'];

// Path to profile images (Up one directory from /faculty/ into /image/)
$uploadDir = '../image/';

// 1. FETCH OVERALL SUMMARY METRICS (Average Score and Total Submissions)
$summaryQuery = $mysqli->query("SELECT AVG(rating) as global_avg, COUNT(DISTINCT id) as total_responses FROM evaluation_results WHERE target_user_id = $currentUserId");
$summary = $summaryQuery->fetch_assoc();
$globalAvg = $summary['global_avg'] ? round($summary['global_avg'], 2) : "0.00";

// Quick adjustment to count total evaluators safely
$trackerQuery = $mysqli->query("SELECT COUNT(*) as count FROM evaluation_tracker WHERE target_user_id = $currentUserId");
$totalEvaluators = $trackerQuery->fetch_assoc()['count'];

// 2. FETCH BREAKDOWN BY QUESTION
$breakdownQuery = $mysqli->query("
    SELECT q.question_text, AVG(r.rating) as question_avg 
    FROM evaluation_results r
    JOIN evaluation_questions q ON r.question_id = q.id
    WHERE r.target_user_id = $currentUserId
    GROUP BY r.question_id
");
$questionMetrics = $breakdownQuery->fetch_all(MYSQLI_ASSOC);

// 3. FETCH NAMELESS COMMENTS (Strictly omitting evaluator IDs to maintain anonymity)
$commentsQuery = $mysqli->query("SELECT comment, submitted_at FROM evaluation_results WHERE target_user_id = $currentUserId AND comment IS NOT NULL AND comment != '' ORDER BY submitted_at DESC");
$comments = $commentsQuery->fetch_all(MYSQLI_ASSOC);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>My Evaluation Analytics - Pandan Bay Institute</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #0A192F;
            --card-bg: rgba(23, 42, 69, 0.4);
            --border-color: #111111;
            --accent-blue: #2B6CB0;
            --text-light: #E0E6F0;
            --text-muted: #A0B3C6;
            --gold-star: #F59E0B;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--dark-bg);
            color: var(--text-light);
            margin: 0;
            padding: 30px;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        
        .header-card {
            background: var(--card-bg);
            border: 4px solid var(--border-color);
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .profile-info h1 { margin: 0; font-size: 24px; color: #fff; }
        .profile-info p { margin: 5px 0 0 0; color: var(--text-muted); font-size: 14px; }
        
        .score-badge-box {
            text-align: center;
            background: rgba(10, 25, 47, 0.6);
            padding: 15px 25px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .big-score { font-size: 36px; font-weight: 700; color: var(--gold-star); }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        .main-panel, .side-panel {
            background: rgba(23, 42, 69, 0.2);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 8px;
            padding: 25px;
        }
        h2 { font-size: 18px; color: #fff; margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }

        .metric-row {
            background: rgba(10, 25, 47, 0.4);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .metric-text { font-size: 14px; line-height: 1.5; color: #fff; margin-bottom: 10px; }
        .progress-bar-container {
            background: rgba(255,255,255,0.1);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        .progress-fill { background: var(--accent-blue); height: 100%; border-radius: 4px; }

        .comment-card {
            background: rgba(10, 25, 47, 0.5);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 12px;
            border-left: 3px solid var(--accent-blue);
        }
        .comment-text { font-size: 13px; font-style: italic; color: #fff; line-height: 1.4; }
        .comment-date { font-size: 11px; color: var(--text-muted); margin-top: 8px; text-align: right; }
    </style>
</head>
<body>

<div class="container">
    <!-- Top Identity & Summary Block -->
    <div class="header-card">
        <div class="profile-info">
            <h1>Performance Report: <?= htmlspecialchars($currentUserName); ?></h1>
            <p>Designation Sector: <span style="color:#fff; font-weight:600;"><?= htmlspecialchars($currentUserDesignation); ?></span></p>
            <p style="font-size: 12px; color: #34D399;"><i class="fa-solid fa-mask"></i> Evaluator profiles hidden to secure absolute feedback protection.</p>
        </div>
        <div class="score-badge-box">
            <div class="big-score"><?= $globalAvg; ?> <span style="font-size:16px; color:var(--text-muted);">/ 5.0</span></div>
            <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-top: 5px;">
                From <?= $totalEvaluators; ?> Total Appraisals
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <!-- Left Side: Breakdown Per Diagnostic Item -->
        <div class="main-panel">
            <h2>Detailed Diagnostics Breakdown</h2>
            <?php if (empty($questionMetrics)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 40px 0;">No diagnostic entries have been recorded for your profile during this active appraisal cycle.</p>
            <?php else: ?>
                <?php foreach ($questionMetrics as $metric): 
                    $percentage = (floatval($metric['question_avg']) / 5) * 100;
                ?>
                    <div class="metric-row">
                        <div class="metric-text"><?= htmlspecialchars($metric['question_text']); ?></div>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div class="progress-bar-container" style="flex-grow: 1;">
                                <div class="progress-fill" style="width: <?= $percentage; ?>%;"></div>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: var(--gold-star); min-width: 30px; text-align: right;">
                                <?= round($metric['question_avg'], 2); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Right Side: Raw Qualitative Text Feedback -->
        <div class="side-panel">
            <h2>Anonymized Comments</h2>
            <div style="max-height: 500px; overflow-y: auto; padding-right: 5px;">
                <?php if (empty($comments)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 40px 0; font-size: 13px;">No qualitative reviews written yet.</p>
                <?php else: ?>
                    <?php foreach ($comments as $com): ?>
                        <div class="comment-card">
                            <div class="comment-text">"<?= htmlspecialchars($com['comment']); ?>"</div>
                            <div class="comment-date"><?= date("M d, Y", strtotime($com['submitted_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
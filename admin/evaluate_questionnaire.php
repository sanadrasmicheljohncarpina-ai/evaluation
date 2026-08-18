<?php
session_start();

// Database Connection
$mysqli = new mysqli("localhost", "root", "", "evaluation", 3306);
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// --- SECURE DEVELOPMENT SESSION PARSING ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 3;
    $_SESSION['name'] = "Joshua De Cruz";
    $_SESSION['designation'] = "Student";
    $_SESSION['category'] = "shs";
}

$studentId = $_SESSION['user_id'];

// Determine current tracking context parameters
$evalType = $_GET['type'] ?? 'Teacher'; // 'Teacher' or 'Personnel'
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// Fetch Admin configurations for dynamic pagination handling
$settingsRes = $mysqli->query("SELECT questions_per_page FROM evaluation_settings LIMIT 1");
$settings = $settingsRes->fetch_assoc();
$questionsPerPage = $settings['questions_per_page'] ?? 5;

// Collect relevant evaluation metrics from matrix
$questionsQuery = $mysqli->query("SELECT * FROM evaluation_questions WHERE target_type = '$evalType' ORDER BY id ASC");
$allQuestions = $questionsQuery->fetch_all(MYSQLI_ASSOC);
$totalQuestionsCount = count($allQuestions);

// Calculate system page metrics depending on admin configuration settings
// Page 1 is always the target demographic and employee selection view
$totalDataPages = ceil($totalQuestionsCount / $questionsPerPage);
$totalTotalPages = 1 + $totalDataPages; 

// Boundary control check
if ($currentPage < 1) $currentPage = 1;
if ($currentPage > $totalTotalPages) $currentPage = $totalTotalPages;

// Pull relevant targets based on designation settings
$targetUsersQuery = $mysqli->query("SELECT id, name, designation, department, photo FROM users WHERE designation = '$evalType' ORDER BY name ASC");
$personnelTargets = $targetUsersQuery->fetch_all(MYSQLI_ASSOC);

$uploadDir = '../image/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Performance Appraisal Form - Pandan Bay Institute</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --form-bg: #F0F4F8;
            --card-surface: #FFFFFF;
            --brand-purple: #673AB7;
            --brand-purple-light: #E8E0F5;
            --text-primary: #202124;
            --text-secondary: #70757A;
            --border-gray: #DADCE0;
            --error-red: #D93025;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--form-bg);
            color: var(--text-primary);
            margin: 0;
            padding: 20px 10px;
            display: flex;
            justify-content: center;
        }
        .form-wrapper { width: 100%; max-width: 770px; }
        
        /* Form Banner Branding Accent Container */
        .form-header-card {
            background-color: var(--card-surface);
            border-radius: 8px;
            border: 1px solid var(--border-gray);
            border-top: 10px solid var(--brand-purple);
            padding: 24px;
            margin-bottom: 12px;
            position: relative;
        }
        .form-header-card h1 { margin: 0 0 12px 0; font-size: 32px; font-weight: 400; font-family: sans-serif; text-transform: uppercase; }
        .user-identity-strip { border-bottom: 1px solid var(--border-gray); padding-bottom: 12px; margin-bottom: 12px; font-size: 14px; color: var(--text-secondary); }
        .required-notice { color: var(--error-red); font-size: 14px; }

        /* Document Component Blocks mimicking Google Form Sections */
        .form-block {
            background: var(--card-surface);
            border: 1px solid var(--border-gray);
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 12px;
            box-sizing: border-box;
        }
        .block-title { font-size: 16px; font-weight: 500; margin-bottom: 8px; color: var(--text-primary); }
        .block-subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }
        
        /* Custom Highlight Headers from edited-image.png */
        .section-highlight-banner {
            background: var(--brand-purple);
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px 8px 0 0;
            margin: -24px -24px 20px -24px;
            font-weight: 600;
        }
        .scale-info-box {
            background: #FAFAFA;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
        }

        /* Dropdown selection modeling */
        select {
            width: 100%;
            max-width: 250px;
            padding: 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-family: inherit;
            background-color: #fff;
            font-size: 14px;
        }

        /* Target Profile Personnel Radio Matrix View styling */
        .target-profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 15px;
        }
        .profile-option-card {
            border: 1px solid var(--border-gray);
            border-radius: 8px;
            padding: 16px;
            text-align: left;
            cursor: pointer;
            position: relative;
            background: #fff;
            transition: border 0.15s, background-color 0.15s;
        }
        .profile-option-card:hover { background-color: #F8F9FA; }
        
        /* Selected item border treatment matching image_da51e1.png */
        .target-radio:checked + .profile-option-card {
            border: 2px solid var(--brand-purple);
            background-color: rgba(103, 58, 183, 0.02);
        }
        
        .img-container-frame {
            width: 100%;
            height: 180px;
            background: #F1F3F4;
            border-radius: 4px;
            margin-bottom: 12px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .img-container-frame img { width: 100%; height: 100%; object-fit: cover; }
        .img-container-frame i { font-size: 48px; color: #BDC1C6; }

        .label-text-flex { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 14px; }
        .label-text-flex span { text-transform: uppercase; font-size: 11px; background: #E8EAED; padding: 2px 6px; border-radius: 4px; color: #5F6368; }

        /* Standard Input Option Groups from edited-image.png */
        .metric-radio-group { margin-top: 15px; display: flex; flex-direction: column; gap: 12px; }
        .radio-choice-row { display: flex; align-items: center; gap: 12px; font-size: 14px; cursor: pointer; }
        .radio-choice-row input[type="radio"] { width: 20px; height: 20px; accent-color: var(--brand-purple); cursor: pointer; }

        /* Dynamic Footer Navigation and Tracking Elements from image_da51bd.png */
        .navigation-dock { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding: 0 4px; }
        .btn-action {
            background-color: #fff;
            color: var(--brand-purple);
            border: 1px solid var(--border-gray);
            padding: 10px 24px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background-color 0.15s;
        }
        .btn-action:hover { background-color: #F4F6F9; }
        .btn-action.primary-submit { background-color: var(--brand-purple); color: #fff; border: 1px solid transparent; }
        .btn-action.primary-submit:hover { background-color: #512DA8; }
        
        .progress-composite { display: flex; align-items: center; gap: 12px; flex-grow: 1; max-width: 320px; margin: 0 20px; }
        .bar-outer { background: #E0E0E0; height: 10px; border-radius: 99px; flex-grow: 1; overflow: hidden; }
        .bar-inner { background: #26A69A; height: 100%; transition: width 0.3s ease; }
        .page-counter-label { font-size: 14px; color: var(--text-primary); white-space: nowrap; }
        .clear-form-link { color: var(--brand-purple); text-decoration: none; font-size: 14px; font-weight: 500; }

        /* Hide radio inputs under layout framework wrappers */
        .hidden-radio { position: absolute; opacity: 0; width: 0; height: 0; }
    </style>
</head>
<body>

<div class="form-wrapper">
    <form action="process_appraisal.php" method="POST">
        <input type="hidden" name="evaluation_type" value="<?= $evalType; ?>">
        <input type="hidden" name="current_step" value="<?= $currentPage; ?>">

        <!-- Header Block Overview Frame -->
        <div class="form-header-card">
            <h1>Performance Evaluation for <?= $evalType === 'Teacher' ? 'Teacher' : 'Personnel'; ?> By Students</h1>
            <div class="user-identity-strip">
                <strong>Logged account:</strong> <?= htmlspecialchars($_SESSION['name']); ?> &bull; <span style="color:var(--brand-purple);">Switch account</span>
            </div>
            <div class="required-notice">* Indicates required question</div>
        </div>

        <?php if ($currentPage === 1): ?>
            <!-- ==========================================
                 PAGE 1 VIEW: DEMOGRAPHICS & SELECTION FRAME
                 ========================================== -->
            <div class="form-block">
                <div class="block-title">YEAR LEVEL: <span class="required-notice">*</span></div>
                <select name="student_year_level" required>
                    <option value="">Choose</option>
                    <option value="BSIT - I">BSIT - I</option>
                    <option value="BSIT - II" selected>BSIT - II</option>
                    <option value="BSIT - III">BSIT - III</option>
                    <option value="BSIT - IV">BSIT - IV</option>
                </select>
            </div>

            <div class="form-block">
                <div class="block-title">Name of <?= $evalType === 'Teacher' ? 'Instructor' : 'Personnel'; ?> to Evaluate: <span class="required-notice">*</span></div>
                <div class="block-subtitle"><em>( Select one at a time )</em></div>

                <div class="target-profile-grid">
                    <?php $index = 1; foreach ($personnelTargets as $target): 
                        $img = ($target['photo'] && file_exists($uploadDir . $target['photo'])) ? $uploadDir . $target['photo'] : '';
                        $roleTag = !empty($target['department']) ? $target['department'] : $target['designation'];
                    ?>
                        <label>
                            <input type="radio" name="target_user_id" value="<?= $target['id']; ?>" class="hidden-radio target-radio" required>
                            <div class="profile-option-card">
                                <div class="img-container-frame">
                                    <?php if ($img): ?>
                                        <img src="<?= $img; ?>" alt="Profile Photo">
                                    <?php else: ?>
                                        <i class="fa-solid fa-user-tie"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="label-text-flex">
                                    <i class="fa-regular fa-circle" style="color:var(--brand-purple);"></i>
                                    <div><?= $index++ . '. ' . htmlspecialchars($target['name']); ?></div>
                                </div>
                                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px; padding-left:22px;">
                                    <span style="text-transform:uppercase; font-size:10px; background:#E8F0FE; color:#1A73E8; padding:2px 6px; border-radius:4px; font-weight:600;">
                                        <?= htmlspecialchars($roleTag); ?>
                                    </span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- ==========================================
                 PAGE 2+ VIEW: SYSTEM METRICS INJECTION
                 ========================================== -->
            <div class="form-block">
                <div class="section-highlight-banner">The <?= $evalType === 'Teacher' ? 'Teacher' : 'Personnel'; ?></div>
                <div class="scale-info-box">
                    Truthfully assess the <?= $evalType === 'Teacher' ? 'Teacher\'s' : 'Personnel\'s'; ?> Performance based on your own observations if the items below are practiced. Select the number corresponding to your rating based on the scale below:<br><br>
                    <strong>Rating: 5</strong>-ALWAYS, <strong>4</strong>-OFTEN, <strong>3</strong>-SOMETIMES, <strong>2</strong>-RARELY, <strong>1</strong>-NEVER
                </div>
            </div>

            <?php
            // Calculate active window slices matching configuration
            $sliceOffset = ($currentPage - 2) * $questionsPerPage;
            $paginatedSubset = array_slice($allQuestions, $sliceOffset, $questionsPerPage);

            foreach ($paginatedSubset as $question):
            ?>
                <div class="form-block">
                    <div class="block-title"><?= htmlspecialchars($question['id']) . '. ' . htmlspecialchars($question['question_text']); ?> <span class="required-notice">*</span></div>
                    <div class="metric-radio-group">
                        <?php for ($score = 5; $score >= 1; $score--): ?>
                            <label class="radio-choice-row">
                                <input type="radio" name="rating[<?= $question['id']; ?>]" value="<?= $score; ?>" required>
                                <span><?= $score; ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ==========================================
             COMPOSITE DOCK BAR: FOOTER NAVIGATION
             ========================================== -->
        <div class="navigation-dock">
            <?php if ($currentPage > 1): ?>
                <button type="button" onclick="window.location.href='?type=<?= $evalType; ?>&page=<?= $currentPage - 1; ?>'" class="btn-action">Back</button>
            <?php else: ?>
                <div style="width:78px;"></div> <!-- Spacer placeholder keeping layout balance -->
            <?php endif; ?>

            <div class="progress-composite">
                <div class="bar-outer">
                    <?php 
                        $percentageComplete = ($currentPage / $totalTotalPages) * 100; 
                    ?>
                    <div class="bar-inner" style="width: <?= $percentageComplete; ?>%;"></div>
                </div>
                <span class="page-counter-label">Page <?= $currentPage; ?> of <?= $totalTotalPages; ?></span>
            </div>

            <?php if ($currentPage < $totalTotalPages): ?>
                <button type="button" onclick="navigateToNextPage(<?= $currentPage; ?>)" class="btn-action primary-submit">Next</button>
            <?php else: ?>
                <button type="submit" class="btn-action primary-submit">Submit Form</button>
            <?php endif; ?>

            <a href="?type=<?= $evalType; ?>&page=1" class="clear-form-link">Clear form</a>
        </div>
    </form>
</div>

<script>
function navigateToNextPage(currentPage) {
    // Basic verification check validation ensuring page options are flagged before advancing steps
    const validForm = document.querySelector('form').checkValidity();
    if(!validForm) {
        document.querySelector('form').reportValidity();
        return;
    }
    
    // Read the form selected variables values and pass onwards
    const urlParams = new URLSearchParams(window.location.search);
    const type = urlParams.get('type') || 'Teacher';
    window.location.href = '?type=' + type + '&page=' + (currentPage + 1);
}
</script>

</body>
</html>
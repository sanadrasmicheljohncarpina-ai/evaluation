<?php
// index/index.php
// Landing page — just links to each existing role's login page.
// Does NOT check sessions, does NOT grant access to anything.
// Each button goes to the same separate login file that already
// enforces its own role check.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Pandan Bay Institute — Evaluation System</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
    --light:#E0E6F0;--muted:#A0B3C6;
    --border:rgba(255,255,255,0.08);--radius:14px;
    --shadow:0 8px 32px rgba(0,0,0,0.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;
    color:var(--light);display:flex;align-items:center;justify-content:center;
    padding:32px;position:relative;overflow-x:hidden;
}
.bg-grid{
    position:fixed;inset:0;z-index:0;
    background-image:linear-gradient(rgba(43,108,176,.06) 1px,transparent 1px),
                      linear-gradient(90deg,rgba(43,108,176,.06) 1px,transparent 1px);
    background-size:48px 48px;
}
.wrap{position:relative;z-index:10;width:100%;max-width:920px;text-align:center;}

.brand{margin-bottom:40px;}
.brand-logo{width:78px;height:78px;border-radius:50%;object-fit:cover;border:2.5px solid #2B6CB0;
    box-shadow:0 0 24px rgba(43,108,176,.4);margin:0 auto 16px;display:block;}
.brand-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;letter-spacing:1.5px;color:#fff;}
.brand-sub{font-size:13px;color:var(--muted);letter-spacing:1px;margin-top:6px;text-transform:uppercase;}

.role-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:18px;margin-bottom:8px;
}
.role-card{
    background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);
    padding:28px 22px;text-decoration:none;color:inherit;
    display:flex;flex-direction:column;align-items:center;gap:14px;
    transition:all .22s ease;position:relative;overflow:hidden;
}
.role-card:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:var(--accent);}
.role-icon{
    width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:22px;background:rgba(255,255,255,.06);color:var(--accent);
    border:2px solid var(--accent);
}
.role-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
.role-desc{font-size:12px;color:var(--muted);line-height:1.5;}

/* per-role accent colors, matched to each dashboard's existing theme */
.role-student   { --accent:#D97706; }
.role-faculty   { --accent:#0D9488; }
.role-staff     { --accent:#2B6CB0; }
.role-executive { --accent:#7C3AED; }
.role-schoolhead{ --accent:#DB2777; }
.role-admin     { --accent:#4C78B8; }

.footer-note{margin-top:36px;font-size:12px;color:var(--muted);}
.footer-note a{color:#6ea8ff;text-decoration:none;font-weight:600;}
.footer-note a:hover{text-decoration:underline;}

@media(max-width:480px){.brand-title{font-size:24px;}}
</style>
</head>
<body>
<div class="bg-grid"></div>

<div class="wrap">
    <div class="brand">
        <img class="brand-logo" src="image/pbi_logo" alt="PBI Logo" onerror="this.style.display='none'"/>
        <div class="brand-title">Pandan Bay Institute</div>
        <div class="brand-sub">Performance Evaluation System</div>
    </div>

    <div class="role-grid">
        <a class="role-card role-student" href="student/student_login.php">
            <div class="role-icon"><i class="fa-solid fa-user-graduate"></i></div>
            <div class="role-name">Student</div>
            <div class="role-desc">Evaluate your teachers and staff</div>
        </a>

        <a class="role-card role-faculty" href="faculty/faculty_login.php">
            <div class="role-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
            <div class="role-name">Teacher</div>
            <div class="role-desc">View results &amp; submit peer evaluations</div>
        </a>

        <a class="role-card role-staff" href="faculty/staff_login.php">
            <div class="role-icon"><i class="fa-solid fa-id-card-clip"></i></div>
            <div class="role-name">Staff</div>
            <div class="role-desc">View results &amp; submit peer evaluations</div>
        </a>

        <a class="role-card role-executive" href="executive/executive_login.php">
            <div class="role-icon"><i class="fa-solid fa-briefcase"></i></div>
            <div class="role-name">Executive Assistant</div>
            <div class="role-desc">Assist admin with assigned features</div>
        </a>

        <a class="role-card role-schoolhead" href="school_head/school_head_login.php">
            <div class="role-icon"><i class="fa-solid fa-building-columns"></i></div>
            <div class="role-name">School Head</div>
            <div class="role-desc">Evaluate faculty &amp; staff performance</div>
        </a>

        <a class="role-card role-admin" href="admin/admin_login.php">
            <div class="role-icon"><i class="fa-solid fa-user-shield"></i></div>
            <div class="role-name">Admin</div>
            <div class="role-desc">System administration</div>
        </a>
    </div>

    <div class="footer-note">
        Not sure which one is yours? Ask your department head or the System Admin.
    </div>
</div>

</body>
</html>
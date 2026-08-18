<?php
// admin/mail_config.php
// ── GMAIL SMTP SETTINGS ──────────────────────────────────────────
// You need a REAL Gmail account to send from (this can be different
// from the fake emails currently stored for your test users — this
// is the SENDER account, not a recipient).
//
// Setup steps (Gmail requires an "App Password", not your normal password):
// 1. Go to https://myaccount.google.com/security
// 2. Turn ON 2-Step Verification (required before App Passwords are available)
// 3. Go to https://myaccount.google.com/apppasswords
// 4. Create an app password (choose "Mail" as the app) — copy the 16-character code
// 5. Paste that code below as SMTP_PASS (NOT your real Gmail password)

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'youraddress@gmail.com');   // ← the Gmail account sending the email
define('SMTP_PASS', 'your16charapppassword');   // ← the App Password from step 4, no spaces
define('SMTP_FROM_NAME', 'PBI Admin Security');

// Also require the recipient's stored email to be a REAL inbox, or nothing
// will arrive — test using an account registered with your own real email.
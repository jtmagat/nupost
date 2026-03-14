<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];

// Unread notifications
$unread_q     = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_count = mysqli_fetch_assoc($unread_q)["c"];

$success = "";
$error   = "";

// Handle password change
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["change_password"])) {
    $current_pw = trim($_POST["current_password"] ?? "");
    $new_pw     = trim($_POST["new_password"]     ?? "");
    $confirm_pw = trim($_POST["confirm_password"] ?? "");

    $user_q = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
    $user   = mysqli_fetch_assoc($user_q);

    if (!$current_pw || !$new_pw || !$confirm_pw) {
        $error = "Please fill in all password fields.";
    } elseif ($user["password"] !== $current_pw && !password_verify($current_pw, $user["password"])) {
        $error = "Current password is incorrect.";
    } elseif ($new_pw !== $confirm_pw) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_pw) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        $hashed  = password_hash($new_pw, PASSWORD_DEFAULT);
        $escaped = mysqli_real_escape_string($conn, $hashed);
        mysqli_query($conn, "UPDATE users SET password='$escaped' WHERE id='$user_id'");
        $success = "Password updated successfully!";
    }
}

// Handle save all settings
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_settings"])) {
    $email_notif    = isset($_POST["email_notif"])    ? 1 : 0;
    $push_notif     = isset($_POST["push_notif"])     ? 1 : 0;
    $status_updates = isset($_POST["status_updates"]) ? 1 : 0;
    $perf_updates   = isset($_POST["perf_updates"])   ? 1 : 0;
    $weekly_digest  = isset($_POST["weekly_digest"])  ? 1 : 0;
    $public_profile = isset($_POST["public_profile"]) ? 1 : 0;

    mysqli_query($conn,
        "UPDATE users SET
            email_notif='$email_notif',
            push_notif='$push_notif',
            status_updates='$status_updates',
            perf_updates='$perf_updates',
            weekly_digest='$weekly_digest',
            public_profile='$public_profile'
         WHERE id='$user_id'"
    );
    $success = "Settings saved successfully!";
}

// Fetch latest user data
$user_q = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$user   = mysqli_fetch_assoc($user_q);

// Defaults if columns don't exist yet
$email_notif    = $user["email_notif"]    ?? 1;
$push_notif     = $user["push_notif"]     ?? 1;
$status_updates = $user["status_updates"] ?? 1;
$perf_updates   = $user["perf_updates"]   ?? 1;
$weekly_digest  = $user["weekly_digest"]  ?? 0;
$public_profile = $user["public_profile"] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Account Settings</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --color-primary: #002366;
    --color-primary-light: #003a8c;
    --color-bg: #f5f6fa;
    --color-border: #e5e7eb;
    --color-text: #111827;
    --color-text-muted: #6b7280;
    --color-orange: #f97316;
    --font: 'Inter', sans-serif;
    --topbar-height: 56px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* TOPNAV */
.topnav {
    position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-height);
    background: white; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; padding: 0 20px; gap: 8px; z-index: 100;
}
.topnav__logo img { height: 32px; width: auto; }
.topnav__logo-text { font-size: 15px; font-weight: 700; color: var(--color-primary); display: none; }
.topnav__nav { display: flex; align-items: center; gap: 4px; flex: 1; }
.topnav__link {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: background .15s, color .15s; white-space: nowrap;
}
.topnav__link:hover { background: var(--color-bg); color: var(--color-text); }
.topnav__create {
    display: flex; align-items: center; gap: 6px; padding: 7px 16px;
    background: var(--color-orange); color: white; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap;
}
.topnav__create:hover { opacity: .9; }
.topnav__search { flex: 1; max-width: 320px; position: relative; margin: 0 8px; }
.topnav__search input {
    width: 100%; height: 36px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px 0 36px; font-size: 13px; font-family: var(--font); background: var(--color-bg); outline: none;
}
.topnav__search input::placeholder { color: #9ca3af; }
.topnav__search input:focus { border-color: var(--color-primary); background: white; }
.topnav__search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.topnav__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topnav__icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); text-decoration: none; transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* LAYOUT */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 600px; margin: 0 auto; padding: 32px 24px; }

/* BREADCRUMB */
.breadcrumb { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 12.5px; }
.breadcrumb a { color: var(--color-primary); text-decoration: none; display: flex; align-items: center; gap: 4px; }
.breadcrumb a:hover { text-decoration: underline; }

.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* SECTION CARD */
.section-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 22px 24px; margin-bottom: 16px;
}
.section-header {
    display: flex; align-items: center; gap: 8px;
    font-size: 13.5px; font-weight: 600; color: var(--color-text);
    margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--color-border);
}
.section-header svg { color: var(--color-text-muted); flex-shrink: 0; }

/* FIELDS */
.field { margin-bottom: 14px; }
.field:last-of-type { margin-bottom: 0; }
.field label { display: block; font-size: 11.5px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 5px; }
.field input[type="password"],
.field input[type="text"] {
    width: 100%; border: 1px solid var(--color-border); border-radius: 7px;
    padding: 9px 12px; font-size: 13px; font-family: var(--font);
    color: var(--color-text); background: white; outline: none; transition: border-color .15s;
}
.field input:focus { border-color: var(--color-primary); }
.field input::placeholder { color: #d1d5db; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.btn-primary {
    padding: 9px 20px; background: var(--color-primary); color: white;
    border: none; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s; margin-top: 16px;
}
.btn-primary:hover { background: var(--color-primary-light); }

/* TOGGLE ROWS */
.toggle-row {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 14px 0; border-bottom: 1px solid #f3f4f6; gap: 16px;
    cursor: pointer; transition: background .1s; border-radius: 6px;
}
.toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
.toggle-row:hover { background: #fafafa; }
.toggle-label { font-size: 13px; font-weight: 500; color: var(--color-text); margin-bottom: 2px; }
.toggle-desc  { font-size: 11.5px; color: var(--color-text-muted); line-height: 1.4; }

/* TOGGLE SWITCH — real clickable */
.toggle-switch {
    position: relative; display: inline-block;
    width: 44px; height: 24px; flex-shrink: 0; margin-top: 2px;
}
.toggle-switch input {
    opacity: 0; width: 0; height: 0; position: absolute;
}
.toggle-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #d1d5db; border-radius: 24px; transition: background .25s;
}
.toggle-slider:before {
    content: ""; position: absolute;
    width: 18px; height: 18px;
    left: 3px; top: 3px;
    background: white; border-radius: 50%;
    transition: transform .25s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.toggle-switch input:checked + .toggle-slider {
    background: var(--color-primary);
}
.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(20px);
}
.toggle-switch input:focus + .toggle-slider {
    box-shadow: 0 0 0 3px rgba(0,35,102,0.15);
}

/* ON/OFF label */
.toggle-state {
    font-size: 11px; font-weight: 600; color: var(--color-text-muted);
    margin-top: 4px; text-align: center; display: block;
    transition: color .2s;
}
.toggle-state.on { color: var(--color-primary); }

/* SESSION INFO */
.session-info {
    background: #f9fafb; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 12px 14px; font-size: 12px; color: var(--color-text-muted); margin-top: 14px;
}
.session-info strong { color: var(--color-text); }

/* SAVE ALL BTN */
.save-all-wrap { display: flex; justify-content: flex-end; margin-top: 4px; }
.btn-save-all {
    padding: 10px 28px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s;
}
.btn-save-all:hover { background: var(--color-primary-light); }

/* ALERT */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; font-weight: 500; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.alert--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

/* TOGGLE WRAPPER — for label + state */
.toggle-wrap { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
</style>
</head>
<body>

<nav class="topnav">
    <div class="topnav__logo">
        <img src="../auth/assets/nupostlogo.png" alt="NUPost"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <span class="topnav__logo-text">NUPost</span>
    </div>
    <div class="topnav__nav">
        <a href="dashboard.php" class="topnav__link">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            Dashboard
        </a>
        <a href="requests.php" class="topnav__link">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Requests
        </a>
        <a href="calendar.php" class="topnav__link">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Calendar
        </a>
        <a href="create_request.php" class="topnav__create">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Request
        </a>
    </div>
    <div class="topnav__search">
        <span class="topnav__search-icon">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </span>
        <input type="text" placeholder="Search requests...">
    </div>
    <div class="topnav__actions">
        <a href="notifications.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php if ($unread_count > 0): ?>
                <span class="topnav__badge"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<div class="layout">
<main class="main">

    <div class="breadcrumb">
        <a href="profile.php">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Profile
        </a>
    </div>

    <div class="page-header">
        <h1>Account Settings</h1>
        <p>Manage your account preferences and security settings.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert--success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert--error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- PASSWORD & SECURITY -->
    <div class="section-card">
        <div class="section-header">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Password &amp; Security
        </div>
        <form method="POST">
            <div class="field">
                <label>Current Password</label>
                <input type="password" name="current_password" placeholder="Enter current password">
            </div>
            <div class="field-row">
                <div class="field">
                    <label>New Password</label>
                    <input type="password" name="new_password" placeholder="Enter new password">
                </div>
                <div class="field">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" placeholder="Confirm new password">
                </div>
            </div>
            <button type="submit" name="change_password" class="btn-primary">Change Password</button>
        </form>

        <!-- SESSION INFO -->
        <div class="session-info" style="margin-top:20px;">
            <strong>Logged in as:</strong> <?= htmlspecialchars($user["email"] ?? "—") ?>
        </div>
    </div>

    <!-- NOTIFICATION PREFERENCES + PRIVACY -->
    <form method="POST" id="settings-form">

        <div class="section-card">
            <div class="section-header">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                Notification Preferences
            </div>

            <!-- Email Notifications -->
            <div class="toggle-row" onclick="toggleSwitch('email_notif')">
                <div>
                    <div class="toggle-label">Email Notifications</div>
                    <div class="toggle-desc">Receive notifications via email</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="email_notif" id="email_notif"
                               <?= $email_notif ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $email_notif ? 'on' : '' ?>" id="state_email_notif">
                        <?= $email_notif ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>

            <!-- Push Notifications -->
            <div class="toggle-row" onclick="toggleSwitch('push_notif')">
                <div>
                    <div class="toggle-label">Push Notifications</div>
                    <div class="toggle-desc">Receive push notifications on your devices</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="push_notif" id="push_notif"
                               <?= $push_notif ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $push_notif ? 'on' : '' ?>" id="state_push_notif">
                        <?= $push_notif ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>

            <!-- Request Status Updates -->
            <div class="toggle-row" onclick="toggleSwitch('status_updates')">
                <div>
                    <div class="toggle-label">Request Status Updates</div>
                    <div class="toggle-desc">Get notified when your request status changes</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="status_updates" id="status_updates"
                               <?= $status_updates ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $status_updates ? 'on' : '' ?>" id="state_status_updates">
                        <?= $status_updates ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>

            <!-- Post Performance Updates -->
            <div class="toggle-row" onclick="toggleSwitch('perf_updates')">
                <div>
                    <div class="toggle-label">Post Performance Updates</div>
                    <div class="toggle-desc">Receive updates on how your posts are performing</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="perf_updates" id="perf_updates"
                               <?= $perf_updates ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $perf_updates ? 'on' : '' ?>" id="state_perf_updates">
                        <?= $perf_updates ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>

            <!-- Weekly Digest -->
            <div class="toggle-row" onclick="toggleSwitch('weekly_digest')">
                <div>
                    <div class="toggle-label">Weekly Digest</div>
                    <div class="toggle-desc">Get a weekly summary of all your activity</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="weekly_digest" id="weekly_digest"
                               <?= $weekly_digest ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $weekly_digest ? 'on' : '' ?>" id="state_weekly_digest">
                        <?= $weekly_digest ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- PRIVACY SETTINGS -->
        <div class="section-card">
            <div class="section-header">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Privacy Settings
            </div>

            <!-- Public Profile -->
            <div class="toggle-row" onclick="toggleSwitch('public_profile')" style="border:none;padding-bottom:0;">
                <div>
                    <div class="toggle-label">Public Profile</div>
                    <div class="toggle-desc">Make your profile visible to all users</div>
                </div>
                <div class="toggle-wrap">
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" name="public_profile" id="public_profile"
                               <?= $public_profile ? 'checked' : '' ?> onchange="updateState(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-state <?= $public_profile ? 'on' : '' ?>" id="state_public_profile">
                        <?= $public_profile ? 'ON' : 'OFF' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="save-all-wrap">
            <button type="submit" name="save_settings" class="btn-save-all">Save All Settings</button>
        </div>

    </form>

</main>
</div>

<script>
// Toggle switch when clicking anywhere on the row
function toggleSwitch(id) {
    const checkbox = document.getElementById(id);
    checkbox.checked = !checkbox.checked;
    updateState(checkbox);
}

// Update ON/OFF label
function updateState(checkbox) {
    const stateEl = document.getElementById('state_' + checkbox.id);
    if (!stateEl) return;
    if (checkbox.checked) {
        stateEl.textContent = 'ON';
        stateEl.classList.add('on');
    } else {
        stateEl.textContent = 'OFF';
        stateEl.classList.remove('on');
    }
}

// Init all states on page load
document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(cb => {
    updateState(cb);
});
</script>

</body>
</html>
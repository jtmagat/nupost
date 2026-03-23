<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];
$requester = mysqli_real_escape_string($conn, $user_name);

// Unread notifications
$unread_q     = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_count = mysqli_fetch_assoc($unread_q)["c"];

// Fetch full user info
$user_result = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$user = mysqli_fetch_assoc($user_result);

// Stats from requests table
$total_q    = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester'");
$total      = mysqli_fetch_assoc($total_q)["c"];

$pending_q  = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Pending Review'");
$pending    = mysqli_fetch_assoc($pending_q)["c"];

$approved_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Approved'");
$approved   = mysqli_fetch_assoc($approved_q)["c"];

$posted_q   = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Posted'");
$posted     = mysqli_fetch_assoc($posted_q)["c"];

$member_since = !empty($user["created_at"]) ? date("F Y", strtotime($user["created_at"])) : "N/A";

// Check if profile photo file actually exists
$has_photo = !empty($user["profile_photo"]) && file_exists("../uploads/" . $user["profile_photo"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Profile</title>
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
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* TOP NAV */
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
.topnav__link--active { background: var(--color-primary); color: white; }
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
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); transition: background .15s; text-decoration: none;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__icon-btn--active { background: var(--color-primary); color: white; }
.topnav__icon-btn--active:hover { background: var(--color-primary-light); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* LAYOUT */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 680px; margin: 0 auto; padding: 32px 24px; }

.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* PROFILE CARD */
.profile-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }

/* HERO */
.profile-hero {
    background: var(--color-primary); padding: 24px 28px;
    display: flex; align-items: center; gap: 16px;
}
.profile-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden; border: 2px solid rgba(255,255,255,0.3);
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.profile-avatar svg { color: white; }
.profile-hero-name { font-size: 17px; font-weight: 700; color: white; }
.profile-hero-role { font-size: 12.5px; color: rgba(255,255,255,0.7); margin-top: 2px; }

/* INFO GRID */
.profile-info { padding: 24px 28px; border-bottom: 1px solid var(--color-border); }
.profile-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 32px; }
.info-field-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 4px;
}
.info-field-label svg { flex-shrink: 0; }
.info-field-value { font-size: 13px; color: var(--color-text); }

.profile-member { padding: 12px 28px; border-bottom: 1px solid var(--color-border); }
.profile-member p { font-size: 12px; color: var(--color-text-muted); }
.profile-member strong { color: var(--color-text); font-weight: 500; }

/* STATS */
.profile-stats { padding: 24px 28px; border-bottom: 1px solid var(--color-border); }
.profile-stats h2 { font-size: 14px; font-weight: 600; margin-bottom: 16px; }
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.stat-box { border: 1px solid var(--color-border); border-radius: 8px; padding: 14px 10px; text-align: center; }
.stat-box__num { font-size: 22px; font-weight: 700; line-height: 1; margin-bottom: 4px; }
.stat-box__num--total    { color: var(--color-text); }
.stat-box__num--pending  { color: #f59e0b; }
.stat-box__num--approved { color: #10b981; }
.stat-box__num--posted   { color: #8b5cf6; }
.stat-box__label { font-size: 11.5px; color: var(--color-text-muted); }

/* ACTIONS */
.profile-actions { padding: 24px 28px; }
.profile-actions h2 { font-size: 14px; font-weight: 600; margin-bottom: 12px; }
.action-list { display: flex; flex-direction: column; gap: 6px; }
.action-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; border-radius: 8px; border: 1px solid var(--color-border);
    text-decoration: none; color: var(--color-text); font-size: 13px; font-weight: 500;
    transition: background .15s; background: white;
}
.action-item:hover { background: var(--color-bg); }
.action-item--danger { color: #ef4444; border-color: #fecaca; }
.action-item--danger:hover { background: #fff5f5; }
.action-item__left { display: flex; align-items: center; gap: 10px; }
.action-item__arrow { color: var(--color-text-muted); }
.action-item--danger .action-item__arrow { color: #fca5a5; }

@media (max-width: 600px) {
    .profile-info-grid { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
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
        <a href="profile.php" class="topnav__icon-btn topnav__icon-btn--active">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<div class="layout">
<main class="main">

    <div class="page-header">
        <h1>Profile</h1>
        <p>Manage your account information and settings</p>
    </div>

    <div class="profile-card">

        <!-- HERO — shows profile photo if uploaded -->
        <div class="profile-hero">
            <div class="profile-avatar">
                <?php if ($has_photo): ?>
                    <img src="../uploads/<?= htmlspecialchars($user["profile_photo"]) ?>?v=<?= time() ?>" alt="Profile Photo">
                <?php else: ?>
                    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="profile-hero-name"><?= htmlspecialchars($user["name"] ?? $user_name) ?></div>
                <div class="profile-hero-role">Requester</div>
            </div>
        </div>

        <!-- INFO GRID -->
        <div class="profile-info">
            <div class="profile-info-grid">
                <div>
                    <div class="info-field-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email Address
                    </div>
                    <div class="info-field-value"><?= htmlspecialchars($user["email"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-field-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.36 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.64a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Phone Number
                    </div>
                    <div class="info-field-value"><?= htmlspecialchars($user["phone"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-field-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Organization
                    </div>
                    <div class="info-field-value"><?= htmlspecialchars($user["organization"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-field-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                        Department
                    </div>
                    <div class="info-field-value"><?= htmlspecialchars($user["department"] ?? "—") ?></div>
                </div>
            </div>
        </div>

        <!-- MEMBER SINCE -->
        <div class="profile-member">
            <p>Member since: <strong><?= $member_since ?></strong></p>
        </div>

        <!-- STATS -->
        <div class="profile-stats">
            <h2>Your Statistics</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-box__num stat-box__num--total"><?= $total ?></div>
                    <div class="stat-box__label">Total Requests</div>
                </div>
                <div class="stat-box">
                    <div class="stat-box__num stat-box__num--pending"><?= $pending ?></div>
                    <div class="stat-box__label">Pending</div>
                </div>
                <div class="stat-box">
                    <div class="stat-box__num stat-box__num--approved"><?= $approved ?></div>
                    <div class="stat-box__label">Approved</div>
                </div>
                <div class="stat-box">
                    <div class="stat-box__num stat-box__num--posted"><?= $posted ?></div>
                    <div class="stat-box__label">Posted</div>
                </div>
            </div>
        </div>

        <!-- SETTINGS & ACTIONS -->
        <div class="profile-actions">
            <h2>Settings &amp; Actions</h2>
            <div class="action-list">
                <a href="account_settings.php" class="action-item">
                    <span class="action-item__left">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Account Settings
                    </span>
                    <span class="action-item__arrow">→</span>
                </a>
                <a href="edit_profile.php" class="action-item">
                    <span class="action-item__left">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Edit Profile
                    </span>
                    <span class="action-item__arrow">→</span>
                </a>
                <a href="../auth/logout.php" class="action-item action-item--danger">
                    <span class="action-item__left">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Logout
                    </span>
                    <span class="action-item__arrow">→</span>
                </a>
            </div>
        </div>

    </div>
</main>
</div>

</body>
</html>
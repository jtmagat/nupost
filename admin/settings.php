<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$tab = $_GET["tab"] ?? "users";
$allowed_tabs = ["users", "api", "templates", "notifications"];
if (!in_array($tab, $allowed_tabs)) $tab = "users";

$saved   = $_GET["saved"]   ?? "";
$error   = $_GET["error"]   ?? "";

// ── Auto-create admin_users table ─────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `role` VARCHAR(100) DEFAULT 'Viewer',
    `status` ENUM('Active','Inactive') DEFAULT 'Active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── Auto-create api_settings table ───────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `api_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service` VARCHAR(100) NOT NULL UNIQUE,
    `api_key` TEXT DEFAULT NULL,
    `meta` TEXT DEFAULT NULL,
    `is_connected` TINYINT(1) DEFAULT 0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ── Auto-create notification_settings table ───────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `notification_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TINYINT(1) DEFAULT 1,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Seed default notification settings if empty
$ns_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM notification_settings"));
if ((int)$ns_count['cnt'] === 0) {
    $defaults = [
        'email_on_new_request'   => 1,
        'email_on_status_change' => 1,
        'email_on_approval'      => 1,
        'email_on_rejection'     => 1,
        'email_digest_daily'     => 0,
        'email_digest_weekly'    => 1,
        'push_new_request'       => 1,
        'push_status_change'     => 1,
        'push_comments'          => 1,
    ];
    foreach ($defaults as $k => $v) {
        $k = mysqli_real_escape_string($conn, $k);
        mysqli_query($conn, "INSERT IGNORE INTO notification_settings (setting_key, setting_value) VALUES ('$k', $v)");
    }
}

// ── HANDLE: Add User ──────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_user"])) {
    $name   = mysqli_real_escape_string($conn, trim($_POST["name"] ?? ""));
    $email  = mysqli_real_escape_string($conn, trim($_POST["email"] ?? ""));
    $role   = mysqli_real_escape_string($conn, trim($_POST["role"] ?? "Viewer"));
    $status = "Active";
    if ($name && $email) {
        $res = mysqli_query($conn, "INSERT INTO admin_users (name, email, role, status) VALUES ('$name','$email','$role','$status')");
        if ($res) {
            header("Location: settings.php?tab=users&saved=user_added"); exit();
        } else {
            header("Location: settings.php?tab=users&error=duplicate_email"); exit();
        }
    }
    header("Location: settings.php?tab=users&error=missing_fields"); exit();
}

// ── HANDLE: Remove User ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_user"])) {
    $uid = (int)$_POST["user_id"];
    if ($uid) {
        mysqli_query($conn, "DELETE FROM admin_users WHERE id=$uid");
    }
    header("Location: settings.php?tab=users&saved=user_removed"); exit();
}

// ── HANDLE: Toggle User Status ────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_status"])) {
    $uid = (int)$_POST["user_id"];
    $new = mysqli_real_escape_string($conn, $_POST["new_status"] ?? "Active");
    mysqli_query($conn, "UPDATE admin_users SET status='$new' WHERE id=$uid");
    header("Location: settings.php?tab=users&saved=1"); exit();
}

// ── HANDLE: Save API Key ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_api"])) {
    $service = mysqli_real_escape_string($conn, $_POST["service"] ?? "");
    $api_key = mysqli_real_escape_string($conn, trim($_POST["api_key"] ?? ""));
    $meta    = mysqli_real_escape_string($conn, trim($_POST["meta"] ?? ""));
    if ($service) {
        mysqli_query($conn, "INSERT INTO api_settings (service, api_key, meta, is_connected)
            VALUES ('$service','$api_key','$meta',1)
            ON DUPLICATE KEY UPDATE api_key='$api_key', meta='$meta', is_connected=1, updated_at=NOW()");
    }
    header("Location: settings.php?tab=api&saved=1"); exit();
}

// ── HANDLE: Disconnect API ────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["disconnect_api"])) {
    $service = mysqli_real_escape_string($conn, $_POST["service"] ?? "");
    mysqli_query($conn, "UPDATE api_settings SET is_connected=0 WHERE service='$service'");
    header("Location: settings.php?tab=api&saved=disconnected"); exit();
}

// ── HANDLE: Save Notification Settings ───────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_notif"])) {
    $keys = [
        'email_on_new_request','email_on_status_change','email_on_approval','email_on_rejection',
        'email_digest_daily','email_digest_weekly',
        'push_new_request','push_status_change','push_comments'
    ];
    foreach ($keys as $k) {
        $val = isset($_POST[$k]) ? 1 : 0;
        $ks  = mysqli_real_escape_string($conn, $k);
        mysqli_query($conn, "INSERT INTO notification_settings (setting_key, setting_value)
            VALUES ('$ks', $val)
            ON DUPLICATE KEY UPDATE setting_value=$val, updated_at=NOW()");
    }
    header("Location: settings.php?tab=notifications&saved=1"); exit();
}

// ── FETCH DATA ────────────────────────────────────────────────────────────
$admin_users = [];
$au_q = mysqli_query($conn, "SELECT * FROM admin_users ORDER BY created_at DESC");
while ($u = mysqli_fetch_assoc($au_q)) $admin_users[] = $u;

$api_settings = [];
$as_q = mysqli_query($conn, "SELECT * FROM api_settings");
while ($a = mysqli_fetch_assoc($as_q)) $api_settings[$a['service']] = $a;

$notif_settings = [];
$ns_q = mysqli_query($conn, "SELECT setting_key, setting_value FROM notification_settings");
while ($n = mysqli_fetch_assoc($ns_q)) $notif_settings[$n['setting_key']] = (int)$n['setting_value'];

$ns = fn($key) => $notif_settings[$key] ?? 1;

// Role definitions
$roles = [
    'Marketing Manager' => 'Full access to all features',
    'Content Admin'     => 'Can manage requests and publish content',
    'Editor'            => 'Can edit and approve requests',
    'Viewer'            => 'Read-only access to requests',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings – NUPost Admin</title>
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
    --color-danger: #ef4444;
    --font: 'Inter', sans-serif;
    --sidebar-width: 220px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }
.app { display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar {
    width: var(--sidebar-width); background: var(--color-primary);
    display: flex; flex-direction: column; flex-shrink: 0;
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 50;
}
.sidebar__logo { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
.sidebar__logo img { height: 32px; width: auto; }
.sidebar__logo-text { font-size: 18px; font-weight: 700; color: white; }
.sidebar__nav { padding: 12px 10px; flex: 1; }
.sidebar__item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px; margin-bottom: 2px;
    color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__item:hover { background: rgba(255,255,255,0.1); color: white; }
.sidebar__item--active { background: rgba(255,255,255,0.15); color: white; }
.sidebar__footer { padding: 14px 10px; border-top: 1px solid rgba(255,255,255,0.1); }
<<<<<<< HEAD
.sidebar__footer-info { padding: 0 12px 12px; font-size: 11px; color: rgba(255,255,255,0.4); }
=======
>>>>>>> ecb05c3ae2b33b76297c4ed43c2a80995d659373
.sidebar__logout {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px;
    color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__logout:hover { background: rgba(255,255,255,0.1); color: white; }

/* ── MAIN ── */
.main { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; }

/* ── TOPBAR ── */
.topbar {
    background: white; border-bottom: 1px solid var(--color-border);
    padding: 0 28px; height: 56px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 40;
}
.topbar-title { font-size: 16px; font-weight: 700; }
.admin-badge {
    display: flex; align-items: center; gap: 7px; padding: 6px 12px;
    background: var(--color-bg); border-radius: 8px; border: 1px solid var(--color-border);
    font-size: 12.5px; font-weight: 500;
}

/* ── CONTENT ── */
.content { padding: 28px; flex: 1; }
.page-sub { font-size: 13px; color: var(--color-text-muted); margin-bottom: 20px; }

/* ── TABS ── */
.tabs-bar {
    display: flex; gap: 4px; margin-bottom: 24px; flex-wrap: wrap;
}
.tab-btn {
    display: flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 500;
    border: 1px solid var(--color-border); background: white; color: var(--color-text-muted);
    cursor: pointer; font-family: var(--font); text-decoration: none; transition: all .15s;
    white-space: nowrap;
}
.tab-btn:hover { background: var(--color-bg); color: var(--color-text); }
.tab-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

/* ── ALERT ── */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.alert--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

/* ── CARD ── */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 20px; overflow: hidden; }
.card-header {
    padding: 18px 24px; display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid var(--color-border);
}
.card-title { font-size: 14px; font-weight: 700; color: var(--color-text); }
.card-body { padding: 24px; }

/* ── BUTTONS ── */
.btn-primary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s; text-decoration: none;
}
.btn-primary:hover { background: var(--color-primary-light); }
.btn-danger {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; background: #fee2e2; color: var(--color-danger);
    border: none; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s;
}
.btn-danger:hover { background: #fecaca; }
.btn-edit {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; background: #eff6ff; color: #2563eb;
    border: none; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s;
}
.btn-edit:hover { background: #dbeafe; }
.btn-disconnect {
    flex: 1; padding: 10px; background: var(--color-danger); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: opacity .15s;
}
.btn-disconnect:hover { opacity: .85; }
.btn-secondary-sm {
    padding: 9px 16px; background: white; color: var(--color-text);
    border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px; font-weight: 500;
    cursor: pointer; font-family: var(--font); transition: background .15s;
}
.btn-secondary-sm:hover { background: var(--color-bg); }

/* ── USER TABLE ── */
.user-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.user-table thead th {
    text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-muted);
    text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 16px;
    border-bottom: 1px solid var(--color-border); background: #fafafa;
}
.user-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.user-table tbody tr:last-child td { border-bottom: none; }
.user-table tbody tr:hover td { background: #fafbff; }
.user-name { font-weight: 600; color: var(--color-text); font-size: 13px; }
.user-email { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }

.status-dot {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
}
.status-dot--active   { background: #dcfce7; color: #16a34a; }
.status-dot--inactive { background: #f3f4f6; color: #9ca3af; }
.status-dot::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.role-badge {
    display: inline-flex; padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; background: #eff6ff; color: #2563eb;
}

/* ── ADD USER MODAL ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 200;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay--open { display: flex; }
.modal {
    background: white; border-radius: 14px; max-width: 480px; width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden;
    animation: modalIn .18s ease;
}
@keyframes modalIn { from { opacity:0; transform:scale(.96) translateY(8px); } to { opacity:1; transform:scale(1); } }
.modal-header {
    padding: 20px 24px; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: space-between;
}
.modal-title { font-size: 15px; font-weight: 700; }
.modal-close {
    width: 30px; height: 30px; border-radius: 8px; border: none;
    background: var(--color-bg); cursor: pointer; font-size: 15px;
    display: flex; align-items: center; justify-content: center; color: var(--color-text-muted);
}
.modal-close:hover { background: #e5e7eb; }
.modal-body { padding: 24px; }
.form-field { margin-bottom: 16px; }
.form-label { display: block; font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.form-input {
    width: 100%; height: 38px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px; font-size: 13px; font-family: var(--font); outline: none;
    transition: border-color .15s; color: var(--color-text);
}
.form-input:focus { border-color: var(--color-primary); }
.form-select {
    width: 100%; height: 38px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px; font-size: 13px; font-family: var(--font); outline: none;
    transition: border-color .15s; color: var(--color-text); background: white; cursor: pointer;
}
.form-select:focus { border-color: var(--color-primary); }
.modal-footer { padding: 16px 24px; border-top: 1px solid var(--color-border); display: flex; gap: 8px; justify-content: flex-end; }

/* ── ROLE PERMISSIONS ── */
.role-perm-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px; border-bottom: 1px solid #f3f4f6;
}
.role-perm-item:last-child { border-bottom: none; }
.role-perm-name { font-size: 13.5px; font-weight: 600; color: var(--color-text); margin-bottom: 3px; }
.role-perm-desc { font-size: 12px; color: var(--color-text-muted); }
.btn-configure {
    padding: 6px 14px; background: white; color: var(--color-primary);
    border: 1px solid var(--color-border); border-radius: 7px; font-size: 12px;
    font-weight: 600; cursor: pointer; font-family: var(--font); transition: all .15s;
    flex-shrink: 0;
}
.btn-configure:hover { background: #eff6ff; border-color: var(--color-primary); }

/* ── API INTEGRATION CARDS ── */
.api-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 22px 24px; margin-bottom: 16px; border: 1px solid var(--color-border);
}
.api-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.api-card-left { display: flex; align-items: center; gap: 12px; }
.api-icon {
    width: 40px; height: 40px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; flex-shrink: 0; font-size: 18px;
}
.api-icon--facebook { background: #1877f2; color: white; }
.api-icon--gemini   { background: linear-gradient(135deg,#4285f4,#ea4335); color: white; }
.api-icon--default  { background: #f3f4f6; color: #6b7280; }
.api-name { font-size: 14px; font-weight: 700; color: var(--color-text); }
.api-desc { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }
.connected-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; background: #dcfce7; color: #16a34a;
    border-radius: 20px; font-size: 11.5px; font-weight: 600;
}
.connected-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #16a34a; }
.disconnected-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; background: #f3f4f6; color: #9ca3af;
    border-radius: 20px; font-size: 11.5px; font-weight: 600;
}
.api-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin-bottom: 16px; }
.api-meta-item { }
.api-meta-label { font-size: 10.5px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
.api-meta-value { font-size: 13px; color: var(--color-text); font-family: monospace; }
.api-action-row { display: flex; gap: 10px; align-items: center; }
.api-connect-form { }
.api-input-row { display: flex; gap: 8px; margin-bottom: 10px; }
.api-input {
    flex: 1; height: 38px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px; font-size: 13px; font-family: monospace; outline: none;
    transition: border-color .15s; color: var(--color-text);
}
.api-input:focus { border-color: var(--color-primary); }

/* ── TOGGLE SWITCH ── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0; border-bottom: 1px solid #f3f4f6;
}
.toggle-row:last-child { border-bottom: none; }
.toggle-label { font-size: 13.5px; font-weight: 500; color: var(--color-text); }
.toggle-sub   { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #d1d5db; border-radius: 24px; transition: .2s;
}
.toggle-slider::before {
    content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%;
    left: 3px; bottom: 3px; background: white; transition: .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.toggle-switch input:checked + .toggle-slider { background: var(--color-primary); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

.section-divider { font-size: 11px; font-weight: 700; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.6px; margin: 20px 0 8px; padding-bottom: 8px; border-bottom: 1px solid var(--color-border); }

/* ── TEMPLATE SECTION ── */
.template-item {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    padding: 16px 0; border-bottom: 1px solid #f3f4f6;
}
.template-item:last-child { border-bottom: none; }
.template-name { font-size: 13.5px; font-weight: 600; color: var(--color-text); margin-bottom: 4px; }
.template-desc { font-size: 12px; color: var(--color-text-muted); line-height: 1.5; }
.template-actions { display: flex; gap: 6px; flex-shrink: 0; }
</style>
</head>
<body>
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar__logo">
        <img src="../auth/assets/nupostlogo.png" alt="NUPost"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <span class="sidebar__logo-text" style="display:none;">NUPost</span>
    </div>
    <nav class="sidebar__nav">
        <a href="index.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            Dashboard
        </a>
        <a href="request_management.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Request Management
        </a>
        <a href="scheduling_calendar.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Scheduling &amp; Calendar
        </a>
        <a href="analytics.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Analytics
        </a>
        <a href="reports.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Reports
        </a>
        <a href="settings.php" class="sidebar__item sidebar__item--active">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>
    <div class="sidebar__footer">
<<<<<<< HEAD
        <div class="sidebar__footer-info">NU Lipa Marketing Office</div>
=======
>>>>>>> ecb05c3ae2b33b76297c4ed43c2a80995d659373
        <a href="../auth/logout.php" class="sidebar__logout">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <div class="topbar">
        <span class="topbar-title">Settings</span>
        <div class="admin-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
        </div>
    </div>

    <div class="content">
        <p class="page-sub">Configure system settings, users, and integrations.</p>

        <!-- TABS -->
        <div class="tabs-bar">
            <a href="?tab=users" class="tab-btn <?= $tab==='users' ? 'tab-btn--active' : '' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                User &amp; Role Management
            </a>
            <a href="?tab=api" class="tab-btn <?= $tab==='api' ? 'tab-btn--active' : '' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                API Integrations
            </a>
            <a href="?tab=templates" class="tab-btn <?= $tab==='templates' ? 'tab-btn--active' : '' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
                Template Management
            </a>
            <a href="?tab=notifications" class="tab-btn <?= $tab==='notifications' ? 'tab-btn--active' : '' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                Notifications
            </a>
        </div>

        <?php if ($saved): ?>
        <div class="alert alert--success">
            &#10003;
            <?php if ($saved === 'user_added') echo 'New user added successfully.';
            elseif ($saved === 'user_removed') echo 'User removed.';
            elseif ($saved === 'disconnected') echo 'API disconnected.';
            else echo 'Changes saved successfully.'; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert--error">
            &#9888;
            <?php if ($error === 'duplicate_email') echo 'That email is already registered.';
            elseif ($error === 'missing_fields') echo 'Please fill in all required fields.';
            else echo 'Something went wrong. Please try again.'; ?>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- TAB: USER & ROLE MANAGEMENT                                    -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <?php if ($tab === 'users'): ?>

        <!-- User Management Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">User Management</div>
                <button onclick="document.getElementById('add-user-modal').classList.add('modal-overlay--open')" class="btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add New User
                </button>
            </div>
            <?php if (!empty($admin_users)): ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admin_users as $u): ?>
                    <tr>
                        <td>
                            <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                        </td>
                        <td>
                            <div style="font-size:13px;color:var(--color-text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                        </td>
                        <td>
                            <span class="role-badge"><?= htmlspecialchars($u['role']) ?></span>
                        </td>
                        <td>
                            <span class="status-dot status-dot--<?= strtolower($u['status']) ?>">
                                <?= htmlspecialchars($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <button class="btn-edit"
                                        onclick="openEditModal(<?= $u['id'] ?>, '<?= addslashes($u['name']) ?>', '<?= addslashes($u['email']) ?>', '<?= addslashes($u['role']) ?>')">
                                    Edit
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this user?')">
                                    <input type="hidden" name="remove_user" value="1">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn-danger">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px;">
                No users added yet. Click "Add New User" to get started.
            </div>
            <?php endif; ?>
        </div>

        <!-- Role Permissions -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Role Permissions</div>
            </div>
            <?php foreach ($roles as $role_name => $role_desc): ?>
            <div class="role-perm-item">
                <div>
                    <div class="role-perm-name"><?= htmlspecialchars($role_name) ?></div>
                    <div class="role-perm-desc"><?= htmlspecialchars($role_desc) ?></div>
                </div>
                <button class="btn-configure" onclick="alert('Role configuration coming soon.')">Configure</button>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- TAB: API INTEGRATIONS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'api'): ?>

        <div style="font-size:14px;font-weight:700;color:var(--color-text);margin-bottom:16px;">Connected Accounts &amp; APIs</div>

        <!-- Facebook Page API -->
        <?php
        $fb = $api_settings['facebook'] ?? ['is_connected'=>0,'api_key'=>'','meta'=>''];
        $fb_connected = (int)($fb['is_connected'] ?? 0);
        $fb_meta = json_decode($fb['meta'] ?? '{}', true) ?: [];
        ?>
        <div class="api-card">
            <div class="api-card-header">
                <div class="api-card-left">
                    <div class="api-icon api-icon--facebook">
                        <svg width="20" height="20" fill="white" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </div>
                    <div>
                        <div class="api-name">Facebook Page</div>
                        <div class="api-desc">Meta Graph API Integration</div>
                    </div>
                </div>
                <?php if ($fb_connected): ?>
                    <span class="connected-badge">Connected</span>
                <?php else: ?>
                    <span class="disconnected-badge">Not Connected</span>
                <?php endif; ?>
            </div>

            <?php if ($fb_connected): ?>
            <div class="api-meta-grid">
                <div class="api-meta-item">
                    <div class="api-meta-label">Page Name</div>
                    <div class="api-meta-value"><?= htmlspecialchars($fb_meta['page_name'] ?? 'NU Lipa Official') ?></div>
                </div>
                <div class="api-meta-item">
                    <div class="api-meta-label">Page ID</div>
                    <div class="api-meta-value"><?= htmlspecialchars($fb_meta['page_id'] ?? '—') ?></div>
                </div>
                <div class="api-meta-item" style="grid-column:1/-1;">
                    <div class="api-meta-label">Access Token</div>
                    <div class="api-meta-value"><?= substr(htmlspecialchars($fb['api_key'] ?? ''), 0, 8) ?>••••••••</div>
                </div>
            </div>
            <div class="api-action-row">
                <form method="POST" style="flex:1;" onsubmit="return confirm('Disconnect Facebook API?')">
                    <input type="hidden" name="disconnect_api" value="1">
                    <input type="hidden" name="service" value="facebook">
                    <button type="submit" class="btn-disconnect">Disconnect</button>
                </form>
                <button class="btn-secondary-sm" onclick="alert('Token refresh coming soon.')">Refresh Token</button>
            </div>
            <?php else: ?>
            <form method="POST" class="api-connect-form">
                <input type="hidden" name="save_api" value="1">
                <input type="hidden" name="service" value="facebook">
                <div class="api-input-row">
                    <input type="text" class="api-input" name="api_key" placeholder="Page Access Token" required>
                </div>
                <div class="api-input-row">
                    <input type="text" class="api-input" name="meta" placeholder='{"page_name":"NU Lipa Official","page_id":"12345"}' style="font-family:monospace;font-size:11px;">
                </div>
                <button type="submit" class="btn-primary" style="width:100%;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Connect Facebook Page
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Google Gemini AI -->
        <?php
        $gem = $api_settings['gemini'] ?? ['is_connected'=>0,'api_key'=>'','meta'=>''];
        $gem_connected = (int)($gem['is_connected'] ?? 0);
        $gem_meta = json_decode($gem['meta'] ?? '{}', true) ?: [];
        ?>
        <div class="api-card">
            <div class="api-card-header">
                <div class="api-card-left">
                    <div class="api-icon api-icon--gemini">
                        <svg width="18" height="18" fill="white" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                    </div>
                    <div>
                        <div class="api-name">Google Gemini AI</div>
                        <div class="api-desc">AI Caption Generation</div>
                    </div>
                </div>
                <?php if ($gem_connected): ?>
                    <span class="connected-badge">Connected</span>
                <?php else: ?>
                    <span class="disconnected-badge">Not Connected</span>
                <?php endif; ?>
            </div>

            <?php if ($gem_connected): ?>
            <div class="api-meta-grid">
                <div class="api-meta-item">
                    <div class="api-meta-label">API Key</div>
                    <div class="api-meta-value"><?= substr(htmlspecialchars($gem['api_key'] ?? ''), 0, 8) ?>••••••••</div>
                </div>
                <div class="api-meta-item">
                    <div class="api-meta-label">Model</div>
                    <div class="api-meta-value"><?= htmlspecialchars($gem_meta['model'] ?? 'gemini-flash-lite-latest') ?></div>
                </div>
                <div class="api-meta-item">
                    <div class="api-meta-label">Monthly Quota Used</div>
                    <div class="api-meta-value"><?= htmlspecialchars($gem_meta['quota'] ?? '—') ?> / 1500 requests</div>
                </div>
            </div>
            <div class="api-action-row">
                <form method="POST" style="flex:1;" onsubmit="return confirm('Disconnect Gemini API?')">
                    <input type="hidden" name="disconnect_api" value="1">
                    <input type="hidden" name="service" value="gemini">
                    <button type="submit" class="btn-disconnect">Disconnect</button>
                </form>
                <button class="btn-secondary-sm" onclick="document.getElementById('gemini-update-form').style.display = document.getElementById('gemini-update-form').style.display === 'none' ? 'block' : 'none'">Update Key</button>
            </div>
            <form method="POST" id="gemini-update-form" style="display:none;margin-top:12px;">
                <input type="hidden" name="save_api" value="1">
                <input type="hidden" name="service" value="gemini">
                <div class="api-input-row">
                    <input type="text" class="api-input" name="api_key" placeholder="New Gemini API Key" required>
                    <button type="submit" class="btn-primary">Update</button>
                </div>
            </form>
            <?php else: ?>
            <form method="POST" class="api-connect-form">
                <input type="hidden" name="save_api" value="1">
                <input type="hidden" name="service" value="gemini">
                <div class="api-input-row">
                    <input type="text" class="api-input" name="api_key" placeholder="Gemini API Key (AIza...)" required>
                </div>
                <div class="api-input-row">
                    <input type="text" class="api-input" name="meta" placeholder='{"model":"gemini-flash-lite-latest","quota":"0"}' style="font-family:monospace;font-size:11px;">
                </div>
                <button type="submit" class="btn-primary" style="width:100%;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Connect Gemini AI
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- TAB: TEMPLATE MANAGEMENT                                       -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'templates'): ?>

        <div class="card">
            <div class="card-header">
                <div class="card-title">Post Templates</div>
                <button class="btn-primary" onclick="alert('Template creation coming soon.')">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Template
                </button>
            </div>
            <div class="card-body">
                <?php
                $templates = [
                    ['name'=>'Event Announcement', 'desc'=>'Standard template for school events and activities. Includes hashtags and call-to-action.'],
                    ['name'=>'Academic Update',    'desc'=>'For academic announcements, enrollment, and scholarship notices.'],
                    ['name'=>'Sports Achievement', 'desc'=>'Celebratory template for sports wins and athletic achievements.'],
                    ['name'=>'Community Post',     'desc'=>'General community updates and engagement posts.'],
                    ['name'=>'Urgent Notice',      'desc'=>'High-priority notices that need immediate attention from students.'],
                ];
                foreach ($templates as $tpl):
                ?>
                <div class="template-item">
                    <div>
                        <div class="template-name"><?= htmlspecialchars($tpl['name']) ?></div>
                        <div class="template-desc"><?= htmlspecialchars($tpl['desc']) ?></div>
                    </div>
                    <div class="template-actions">
                        <button class="btn-edit" onclick="alert('Template editing coming soon.')">Edit</button>
                        <button class="btn-danger" onclick="alert('Delete template?')">Delete</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- TAB: NOTIFICATIONS                                             -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'notifications'): ?>

        <form method="POST">
            <input type="hidden" name="save_notif" value="1">

            <!-- Email Notifications -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email Notifications
                    </div>
                </div>
                <div class="card-body">
                    <div class="section-divider">Request Events</div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">New Request Submitted</div>
                            <div class="toggle-sub">Get notified when a new request is submitted</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_on_new_request" <?= $ns('email_on_new_request') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Status Changes</div>
                            <div class="toggle-sub">Notify when a request status is updated</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_on_status_change" <?= $ns('email_on_status_change') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Request Approved</div>
                            <div class="toggle-sub">Email when a request is marked Approved</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_on_approval" <?= $ns('email_on_approval') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Request Rejected</div>
                            <div class="toggle-sub">Email when a request is rejected</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_on_rejection" <?= $ns('email_on_rejection') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="section-divider" style="margin-top:20px;">Digest</div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Daily Digest</div>
                            <div class="toggle-sub">Receive a daily summary of all activity</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_digest_daily" <?= $ns('email_digest_daily') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Weekly Digest</div>
                            <div class="toggle-sub">Receive a weekly summary every Monday</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_digest_weekly" <?= $ns('email_digest_weekly') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Push / In-App Notifications -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:6px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        In-App Notifications
                    </div>
                </div>
                <div class="card-body">
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">New Request</div>
                            <div class="toggle-sub">Show in-app notification for new requests</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="push_new_request" <?= $ns('push_new_request') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Status Updates</div>
                            <div class="toggle-sub">Show notification when request status changes</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="push_status_change" <?= $ns('push_status_change') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">New Comments</div>
                            <div class="toggle-sub">Notify when someone sends a message on a request</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="push_comments" <?= $ns('push_comments') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Notification Settings
                </button>
            </div>
        </form>

        <?php endif; ?>

    </div><!-- .content -->
</div><!-- .main -->
</div><!-- .app -->

<!-- ADD USER MODAL -->
<div class="modal-overlay" id="add-user-modal" onclick="if(event.target===this)this.classList.remove('modal-overlay--open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Add New User</div>
            <button class="modal-close" onclick="document.getElementById('add-user-modal').classList.remove('modal-overlay--open')">&#10005;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_user" value="1">
            <div class="modal-body">
                <div class="form-field">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Juan Dela Cruz" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-input" placeholder="user@nu-lipa.edu.ph" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <?php foreach ($roles as $rn => $rd): ?>
                            <option value="<?= htmlspecialchars($rn) ?>"><?= htmlspecialchars($rn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    Add User
                </button>
                <button type="button" class="btn-secondary-sm" onclick="document.getElementById('add-user-modal').classList.remove('modal-overlay--open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-overlay" id="edit-user-modal" onclick="if(event.target===this)this.classList.remove('modal-overlay--open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Edit User</div>
            <button class="modal-close" onclick="document.getElementById('edit-user-modal').classList.remove('modal-overlay--open')">&#10005;</button>
        </div>
        <form method="POST" id="edit-user-form">
            <input type="hidden" name="add_user" value="1">
            <input type="hidden" name="edit_user_id" id="edit_user_id">
            <div class="modal-body">
                <div class="form-field">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" id="edit_name" class="form-input" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" id="edit_email" class="form-input" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Role</label>
                    <select name="role" id="edit_role" class="form-select">
                        <?php foreach ($roles as $rn => $rd): ?>
                            <option value="<?= htmlspecialchars($rn) ?>"><?= htmlspecialchars($rn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary-sm" onclick="document.getElementById('edit-user-modal').classList.remove('modal-overlay--open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, email, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_name').value    = name;
    document.getElementById('edit_email').value   = email;
    const roleSelect = document.getElementById('edit_role');
    for (let opt of roleSelect.options) {
        opt.selected = opt.value === role;
    }
    document.getElementById('edit-user-modal').classList.add('modal-overlay--open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('modal-overlay--open'));
    }
});
</script>

</body>
</html>
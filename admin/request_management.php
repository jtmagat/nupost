<?php
session_start();
include "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// ===== Handle status update via POST =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {
    $req_id     = (int)$_POST["req_id"];
    $new_status = mysqli_real_escape_string($conn, $_POST["new_status"]);
    $allowed_statuses = ["Pending Review", "Under Review", "Approved", "Posted", "Rejected"];

    if (in_array($new_status, $allowed_statuses)) {
        $req_q   = mysqli_query($conn, "SELECT * FROM requests WHERE id=$req_id LIMIT 1");
        $req_row = mysqli_fetch_assoc($req_q);
        $old_status = $req_row["status"] ?? "";
        mysqli_query($conn, "UPDATE requests SET status='$new_status' WHERE id=$req_id");

        if ($req_row && $old_status !== $new_status) {
            $requester_esc = mysqli_real_escape_string($conn, $req_row["requester"]);
            $user_q   = mysqli_query($conn, "SELECT id FROM users WHERE name='$requester_esc' LIMIT 1");
            $user_row = mysqli_fetch_assoc($user_q);
            if ($user_row) {
                $uid = (int)$user_row["id"];
                $now = date("Y-m-d H:i:s");
                $notif_data = match($new_status) {
                    "Under Review" => ["title"=>"Request Under Review",  "message"=>"Your request \"{$req_row['title']}\" is now being reviewed by our team.", "type"=>"review"],
                    "Approved"     => ["title"=>"Request Approved! 🎉",  "message"=>"Great news! Your request \"{$req_row['title']}\" has been approved.",      "type"=>"approved"],
                    "Posted"       => ["title"=>"Request Posted! 🚀",     "message"=>"Your request \"{$req_row['title']}\" has been published.",                "type"=>"posted"],
                    "Rejected"     => ["title"=>"Request Rejected",       "message"=>"Unfortunately, your request \"{$req_row['title']}\" was not approved.",   "type"=>"rejected"],
                    default        => ["title"=>"Status Updated",         "message"=>"Your request \"{$req_row['title']}\" status: $new_status.",               "type"=>"review"],
                };
                $nt = mysqli_real_escape_string($conn, $notif_data["title"]);
                $nm = mysqli_real_escape_string($conn, $notif_data["message"]);
                $ny = mysqli_real_escape_string($conn, $notif_data["type"]);
                mysqli_query($conn, "INSERT INTO notifications (user_id,title,message,type,is_read,created_at) VALUES ('$uid','$nt','$nm','$ny',0,'$now')");
            }
        }
    }
    $qs_params = [];
    if (!empty($_GET['tab']))      $qs_params['tab']      = $_GET['tab'];
    if (!empty($_GET['search']))   $qs_params['search']   = $_GET['search'];
    if (!empty($_GET['priority'])) $qs_params['priority'] = $_GET['priority'];
    if (!empty($_GET['page']))     $qs_params['page']     = $_GET['page'];
    $qs = !empty($qs_params) ? '?' . http_build_query($qs_params) : '';
    header("Location: request_management.php" . $qs);
    exit();
}

// ===== Tab (replaces status filter) =====
$tab = $_GET['tab'] ?? 'active';
$allowed_tabs = ['active', 'posted', 'rejected'];
if (!in_array($tab, $allowed_tabs)) $tab = 'active';

// ===== Pagination & Search =====
$limit  = 5;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search         = isset($_GET['search'])   ? mysqli_real_escape_string($conn, $_GET['search'])   : '';
$priorityFilter = isset($_GET['priority']) ? mysqli_real_escape_string($conn, $_GET['priority']) : '';

// Build WHERE based on tab
$tab_where = match($tab) {
    'posted'   => "status = 'Posted'",
    'rejected' => "status = 'Rejected'",
    default    => "status NOT IN ('Posted','Rejected')",  // active = all unfinished
};

$where = $tab_where;
if ($search !== '') {
    $where .= " AND (title LIKE '%$search%' OR requester LIKE '%$search%' OR category LIKE '%$search%')";
}
$valid_priorities = ["Low","Medium","High","Urgent"];
if ($priorityFilter !== '' && in_array($priorityFilter, $valid_priorities)) {
    $where .= " AND priority='$priorityFilter'";
}

$totalQuery    = mysqli_query($conn, "SELECT COUNT(*) as total FROM requests WHERE $where");
$totalRow      = mysqli_fetch_assoc($totalQuery);
$totalRequests = $totalRow['total'];
$totalPages    = max(1, ceil($totalRequests / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$result = mysqli_query($conn, "SELECT * FROM requests WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

// Counts for stat cards + tabs
$count_pending  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Pending Review'"))["c"];
$count_review   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Under Review'"))["c"];
$count_approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Approved'"))["c"];
$count_posted   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Posted'"))["c"];
$count_rejected = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Rejected'"))["c"];
$count_active   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status NOT IN ('Posted','Rejected')"))["c"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request Management – NUPost Admin</title>
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
    --font: 'Inter', sans-serif;
    --sidebar-width: 220px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }
.app { display: flex; min-height: 100vh; }

/* SIDEBAR */
.sidebar {
    width: var(--sidebar-width); background: var(--color-primary);
    display: flex; flex-direction: column; flex-shrink: 0;
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 50;
}
.sidebar__logo { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
.sidebar__logo img { height: 32px; width: auto; }
.sidebar__logo-text { font-size: 18px; font-weight: 700; color: white; }
.sidebar__subtitle { font-size: 11px; color: rgba(255,255,255,0.5); margin-top: 3px; }
.sidebar__nav { padding: 12px 10px; flex: 1; }
.sidebar__item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px; margin-bottom: 2px;
    color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__item:hover { background: rgba(255,255,255,0.1); color: white; }
.sidebar__item--active { background: rgba(255,255,255,0.15); color: white; }
.sidebar__footer { padding: 14px 10px 20px; border-top: 1px solid rgba(255,255,255,0.1); }
.sidebar__footer-info { padding: 0 12px 12px; font-size: 11px; color: rgba(255,255,255,0.4); }
.sidebar__logout {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px;
    color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__logout:hover { background: rgba(255,255,255,0.1); color: white; }

/* MAIN */
.main { margin-left: var(--sidebar-width); flex: 1; padding: 28px; }
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.topbar h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.admin-badge {
    display: flex; align-items: center; gap: 7px; padding: 6px 12px;
    background: white; border-radius: 8px; border: 1px solid var(--color-border); font-size: 12.5px; font-weight: 500;
}

/* STAT CARDS */
.stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 24px; }
.stat-card {
    background: white; border-radius: var(--radius); padding: 18px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: var(--shadow-sm); border: 1.5px solid transparent;
}
.stat-card--yellow { border-color: #fde68a; }
.stat-card--blue   { border-color: #bfdbfe; }
.stat-card--green  { border-color: #a7f3d0; }
.stat-card--purple { border-color: #ddd6fe; }
.stat-card__label  { font-size: 11px; color: var(--color-text-muted); margin-bottom: 4px; }
.stat-card__value  { font-size: 26px; font-weight: 700; }
.stat-card__icon   { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.stat-card__icon--yellow { background: #fef3c7; color: #f59e0b; }
.stat-card__icon--blue   { background: #eff6ff; color: #3b82f6; }
.stat-card__icon--green  { background: #ecfdf5; color: #10b981; }
.stat-card__icon--purple { background: #f5f3ff; color: #8b5cf6; }

/* TABS */
.tab-row {
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 14px; flex-wrap: wrap;
}
.tab-btn {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 16px; border-radius: 8px; border: 1px solid var(--color-border);
    background: white; font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: all .15s; white-space: nowrap;
}
.tab-btn:hover { background: var(--color-bg); color: var(--color-text); }
.tab-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.tab-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 18px; padding: 0 5px;
    border-radius: 10px; font-size: 10px; font-weight: 700;
    background: rgba(255,255,255,0.25); color: white;
}
.tab-btn:not(.tab-btn--active) .tab-count {
    background: var(--color-bg); color: var(--color-text-muted);
}

/* FILTER BAR */
.filter-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-bar input[type="text"],
.filter-bar select {
    height: 36px; border: 1px solid var(--color-border); border-radius: 7px;
    padding: 0 12px; font-size: 13px; font-family: var(--font); background: white; color: var(--color-text); outline: none;
}
.filter-bar input[type="text"] { flex: 1; min-width: 200px; }
.filter-bar input:focus, .filter-bar select:focus { border-color: var(--color-primary); }
.filter-btn {
    height: 36px; padding: 0 16px; background: var(--color-primary); color: white;
    border: none; border-radius: 7px; font-size: 13px; font-weight: 500; cursor: pointer; font-family: var(--font);
}
.filter-btn:hover { background: var(--color-primary-light); }
.reset-btn {
    height: 36px; padding: 0 14px; background: white; color: var(--color-text-muted);
    border: 1px solid var(--color-border); border-radius: 7px; font-size: 13px;
    cursor: pointer; font-family: var(--font); text-decoration: none; display: flex; align-items: center;
}
.reset-btn:hover { background: var(--color-bg); }

/* TABLE */
.table-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.table-card-header {
    padding: 14px 20px; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: space-between;
}
.table-card-header span { font-size: 12.5px; color: var(--color-text-muted); }
.table-card-header strong { color: var(--color-text); }

table { width: 100%; border-collapse: collapse; }
thead tr { border-bottom: 1px solid var(--color-border); }
th { padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
td { padding: 12px 14px; font-size: 12.5px; vertical-align: middle; }

.req-title { font-weight: 600; color: var(--color-text); margin-bottom: 2px; }
.req-desc  { font-size: 11px; color: var(--color-text-muted); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.req-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; }
.req-thumb-placeholder {
    width: 36px; height: 36px; border-radius: 6px;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}

.badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
.badge--high          { background: #fee2e2; color: #dc2626; }
.badge--urgent        { background: #fef3c7; color: #b45309; }
.badge--medium        { background: #fef3c7; color: #d97706; }
.badge--low           { background: #f3f4f6; color: #6b7280; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge--pending       { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }

.status-select {
    padding: 4px 8px; border-radius: 6px; border: 1px solid var(--color-border);
    font-size: 11.5px; font-family: var(--font); background: white; color: var(--color-text); cursor: pointer; outline: none;
}
.status-select:focus { border-color: var(--color-primary); }
.update-btn {
    padding: 5px 12px; background: var(--color-primary); color: white;
    border: none; border-radius: 5px; font-size: 11px; font-weight: 600;
    cursor: pointer; font-family: var(--font); margin-left: 4px;
}
.update-btn:hover { background: var(--color-primary-light); }

.date-text { font-size: 11.5px; color: var(--color-text-muted); white-space: nowrap; }
.empty-state { padding: 48px; text-align: center; color: #9ca3af; font-size: 13px; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: #f0f4ff !important; }

/* PAGINATION */
.pagination { display: flex; align-items: center; gap: 4px; padding: 16px 20px; border-top: 1px solid var(--color-border); flex-wrap: wrap; }
.page-btn {
    min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
    border: 1px solid var(--color-border); background: white; color: var(--color-text-muted);
    font-size: 12.5px; text-decoration: none; display: flex; align-items: center; justify-content: center; transition: background .15s;
}
.page-btn:hover { background: var(--color-bg); }
.page-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); font-weight: 600; }
.page-btn--disabled { opacity: 0.4; pointer-events: none; }
.page-info { font-size: 12px; color: var(--color-text-muted); margin-left: auto; }
.page-ellipsis { padding: 0 6px; color: var(--color-text-muted); font-size: 13px; display: flex; align-items: center; }

/* TOAST */
.toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 999;
    background: #002366; color: white; padding: 12px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 500; box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    display: flex; align-items: center; gap: 10px;
    animation: slideIn .3s ease, fadeOut .4s ease 2.6s forwards;
}
.toast--success { background: #059669; }
.toast--reject  { background: #dc2626; }
@keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@keyframes fadeOut { from { opacity: 1; } to { opacity: 0; pointer-events: none; } }
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
        <a href="request_management.php" class="sidebar__item sidebar__item--active">
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
        <a href="settings.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>
    <div class="sidebar__footer">
        <div class="sidebar__footer-info">NU Lipa Marketing Office</div>
        <a href="../auth/logout.php" class="sidebar__logout">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <h1>Request Management</h1>
        <div class="admin-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats">
        <div class="stat-card stat-card--yellow">
            <div><div class="stat-card__label">Pending Review</div><div class="stat-card__value"><?= $count_pending ?></div></div>
            <div class="stat-card__icon stat-card__icon--yellow">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
        </div>
        <div class="stat-card stat-card--blue">
            <div><div class="stat-card__label">Under Review</div><div class="stat-card__value"><?= $count_review ?></div></div>
            <div class="stat-card__icon stat-card__icon--blue">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
        </div>
        <div class="stat-card stat-card--green">
            <div><div class="stat-card__label">Approved</div><div class="stat-card__value"><?= $count_approved ?></div></div>
            <div class="stat-card__icon stat-card__icon--green">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <div class="stat-card stat-card--purple">
            <div><div class="stat-card__label">Posted</div><div class="stat-card__value"><?= $count_posted ?></div></div>
            <div class="stat-card__icon stat-card__icon--purple">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <div class="tab-row">
        <a href="?tab=active"   class="tab-btn <?= $tab==='active'   ? 'tab-btn--active':'' ?>">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Active Requests
            <span class="tab-count"><?= $count_active ?></span>
        </a>
        <a href="?tab=posted"   class="tab-btn <?= $tab==='posted'   ? 'tab-btn--active':'' ?>">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Posted
            <span class="tab-count"><?= $count_posted ?></span>
        </a>
        <a href="?tab=rejected" class="tab-btn <?= $tab==='rejected' ? 'tab-btn--active':'' ?>">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Rejected
            <span class="tab-count"><?= $count_rejected ?></span>
        </a>
    </div>

    <!-- FILTER BAR -->
    <form method="get" class="filter-bar">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="text" name="search" placeholder="Search title, requester, category..."
               value="<?= htmlspecialchars($search) ?>">
        <select name="priority">
            <option value="">All Priorities</option>
            <?php foreach (["Low","Medium","High","Urgent"] as $p): ?>
                <option value="<?= $p ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="filter-btn">Apply</button>
        <a href="?tab=<?= $tab ?>" class="reset-btn">Reset</a>
    </form>

    <!-- TABLE -->
    <div class="table-card">
        <div class="table-card-header">
            <span>
                Showing <strong><?= min($limit, $totalRequests - $offset) ?></strong> of
                <strong><?= $totalRequests ?></strong>
                <?= $tab === 'posted' ? 'posted' : ($tab === 'rejected' ? 'rejected' : 'active') ?> requests
            </span>
            <span style="font-size:12px;color:var(--color-text-muted);">5 per page</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>REQUEST</th>
                    <th>REQUESTER</th>
                    <th>CATEGORY</th>
                    <th>PRIORITY</th>
                    <th>STATUS</th>
                    <th>DATE</th>
                    <?php if ($tab === 'active'): ?><th>ACTION</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($result)):
                    $priority_raw = strtolower($row["priority"]);
                    $priority_class = match($priority_raw) {
                        "urgent"=>"badge--urgent","high"=>"badge--high","medium"=>"badge--medium",default=>"badge--low"
                    };
                    $status_raw = strtolower($row["status"]);
                    $status_class = match(true) {
                        str_contains($status_raw,"approved")    =>"badge--approved",
                        str_contains($status_raw,"posted")      =>"badge--posted",
                        str_contains($status_raw,"under review")=>"badge--under-review",
                        str_contains($status_raw,"rejected")    =>"badge--rejected",
                        default                                 =>"badge--pending",
                    };
                    $date        = date("M j, Y", strtotime($row["created_at"]));
                    $media_files = explode(",", $row["media_file"] ?? "");
                    $first_media = trim($media_files[0]);
                ?>
                <tr class="clickable-row" onclick="window.location='request_info.php?id=<?= $row["id"] ?>'">
                    <td style="color:var(--color-text-muted);font-size:11.5px;">#<?= htmlspecialchars($row["request_id"]) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if ($first_media): ?>
                                <img class="req-thumb" src="../uploads/<?= htmlspecialchars($first_media) ?>" alt=""
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="req-thumb-placeholder" style="display:none;">📄</div>
                            <?php else: ?>
                                <div class="req-thumb-placeholder">📄</div>
                            <?php endif; ?>
                            <div>
                                <div class="req-title"><?= htmlspecialchars($row["title"]) ?></div>
                                <div class="req-desc"><?= htmlspecialchars($row["description"] ?? "") ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($row["requester"]) ?></td>
                    <td><?= htmlspecialchars($row["category"] ?? "—") ?></td>
                    <td><span class="badge <?= $priority_class ?>"><?= htmlspecialchars($row["priority"]) ?></span></td>
                    <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($row["status"]) ?></span></td>
                    <td class="date-text"><?= $date ?></td>
                    <?php if ($tab === 'active'): ?>
                    <td onclick="event.stopPropagation()">
                        <form method="POST" style="display:flex;align-items:center;gap:4px;">
                            <input type="hidden" name="req_id" value="<?= $row["id"] ?>">
                            <input type="hidden" name="update_status" value="1">
                            <select class="status-select" name="new_status">
                                <?php foreach (["Pending Review","Under Review","Approved","Posted","Rejected"] as $s): ?>
                                    <option value="<?= $s ?>" <?= $row["status"]===$s?'selected':'' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="update-btn">Save</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="<?= $tab==='active'?8:7 ?>">
                    <div class="empty-state">
                        <?php if ($tab==='posted'): ?>No posted requests yet.
                        <?php elseif ($tab==='rejected'): ?>No rejected requests.
                        <?php else: ?>No active requests found.
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $qs_base = http_build_query(array_filter(['tab'=>$tab,'search'=>$search,'priority'=>$priorityFilter]));

            // Prev
            if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&<?= $qs_base ?>" class="page-btn">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
            <?php else: ?>
                <span class="page-btn page-btn--disabled">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                </span>
            <?php endif;

            // Page numbers with ellipsis
            $range = 2;
            $show_first = $page > $range + 2;
            $show_last  = $page < $totalPages - $range - 1;

            if ($show_first) {
                echo "<a href='?page=1&$qs_base' class='page-btn'>1</a>";
                if ($page > $range + 3) echo "<span class='page-ellipsis'>…</span>";
            }

            for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++) {
                $active = $i === $page ? 'page-btn--active' : '';
                echo "<a href='?page=$i&$qs_base' class='page-btn $active'>$i</a>";
            }

            if ($show_last) {
                if ($page < $totalPages - $range - 2) echo "<span class='page-ellipsis'>…</span>";
                echo "<a href='?page=$totalPages&$qs_base' class='page-btn'>$totalPages</a>";
            }

            // Next
            if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&<?= $qs_base ?>" class="page-btn">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            <?php else: ?>
                <span class="page-btn page-btn--disabled">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </span>
            <?php endif; ?>
            <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php
$updated_status = $_GET['updated'] ?? '';
if ($updated_status):
    $toast_class = match($updated_status) {
        'Posted','Approved' => 'toast--success',
        'Rejected'          => 'toast--reject',
        default             => '',
    };
?>
<div class="toast <?= $toast_class ?>" id="toast">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    Status updated to <strong><?= htmlspecialchars($updated_status) ?></strong>
</div>
<script>setTimeout(() => { document.getElementById('toast')?.remove(); }, 3000);</script>
<?php endif; ?>
</body>
</html>
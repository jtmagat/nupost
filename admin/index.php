<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// Filter period
$period = $_GET["period"] ?? "30";
$allowed = ["7","30","90","365"];
if (!in_array($period, $allowed)) $period = "30";
$date_from = date("Y-m-d", strtotime("-{$period} days"));

// Stat counts
$total    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests"))["c"];
$pending  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Pending Review'"))["c"];
$approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Approved'"))["c"];
$posted   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Posted'"))["c"];
$rejected = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Rejected'"))["c"];
$review   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Under Review'"))["c"];

// Requests over time (daily count for period)
$timeline_q = mysqli_query($conn,
    "SELECT DATE(created_at) as d, COUNT(*) as c
     FROM requests
     WHERE created_at >= '$date_from'
     GROUP BY DATE(created_at)
     ORDER BY d ASC"
);
$timeline_labels = [];
$timeline_data   = [];
// Fill all days in range
$range_days = (int)$period;
for ($i = $range_days - 1; $i >= 0; $i--) {
    $day = date("Y-m-d", strtotime("-{$i} days"));
    $timeline_labels[] = date("M j", strtotime($day));
    $timeline_data[$day] = 0;
}
while ($row = mysqli_fetch_assoc($timeline_q)) {
    if (isset($timeline_data[$row["d"]])) {
        $timeline_data[$row["d"]] = (int)$row["c"];
    }
}
$timeline_values = array_values($timeline_data);

// Status distribution (donut)
$status_data = [
    "Pending Review" => (int)$pending,
    "Under Review"   => (int)$review,
    "Approved"       => (int)$approved,
    "Posted"         => (int)$posted,
    "Rejected"       => (int)$rejected,
];

// Priority breakdown (bar)
$priority_q = mysqli_query($conn,
    "SELECT priority, COUNT(*) as c FROM requests
     WHERE created_at >= '$date_from'
     GROUP BY priority ORDER BY FIELD(priority,'Urgent','High','Medium','Low')"
);
$priority_labels = []; $priority_values = [];
while ($row = mysqli_fetch_assoc($priority_q)) {
    $priority_labels[] = $row["priority"];
    $priority_values[] = (int)$row["c"];
}

// Category breakdown
$category_q = mysqli_query($conn,
    "SELECT category, COUNT(*) as c FROM requests
     WHERE created_at >= '$date_from' AND category IS NOT NULL AND category != ''
     GROUP BY category ORDER BY c DESC LIMIT 6"
);
$cat_labels = []; $cat_values = [];
while ($row = mysqli_fetch_assoc($category_q)) {
    $cat_labels[] = $row["category"];
    $cat_values[] = (int)$row["c"];
}

// Recent requests (latest 5)
$recent_q = mysqli_query($conn, "SELECT * FROM requests ORDER BY created_at DESC LIMIT 5");
$recent   = [];
while ($row = mysqli_fetch_assoc($recent_q)) $recent[] = $row;

// Upcoming scheduled
$today      = date("Y-m-d");
$upcoming_q = mysqli_query($conn,
    "SELECT * FROM requests
     WHERE preferred_date >= '$today' AND status NOT IN ('Posted','Rejected')
     ORDER BY preferred_date ASC LIMIT 4"
);
$upcoming = [];
while ($row = mysqli_fetch_assoc($upcoming_q)) $upcoming[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – NUPost Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

/* MAIN — scrollable */
.main { margin-left: var(--sidebar-width); flex: 1; padding: 28px; overflow-y: auto; height: 100vh; }

/* TOPBAR */
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.topbar h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.topbar p  { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }
.admin-badge {
    display: flex; align-items: center; gap: 10px; padding: 8px 14px;
    background: white; border-radius: 10px; border: 1px solid var(--color-border);
}
.admin-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--color-primary); display: flex; align-items: center;
    justify-content: center; color: white; font-size: 13px; font-weight: 700; flex-shrink: 0;
}
.admin-name { font-size: 13px; font-weight: 600; }
.admin-role { font-size: 11px; color: var(--color-text-muted); }

/* FILTER BAR */
.filter-row {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 20px; flex-wrap: wrap; gap: 10px;
}
.filter-row-label { font-size: 13px; color: var(--color-text-muted); font-weight: 500; }
.period-tabs { display: flex; gap: 4px; }
.period-tab {
    padding: 5px 14px; border-radius: 6px; border: 1px solid var(--color-border);
    background: white; font-size: 12.5px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: all .15s;
}
.period-tab:hover { background: var(--color-bg); }
.period-tab--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

/* STAT CARDS */
.stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.stat-card {
    background: white; border-radius: var(--radius); padding: 18px;
    display: flex; flex-direction: column; gap: 10px;
    box-shadow: var(--shadow-sm); border: 1.5px solid transparent; transition: box-shadow .15s;
}
.stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.stat-card--blue   { border-color: #bfdbfe; }
.stat-card--yellow { border-color: #fde68a; }
.stat-card--green  { border-color: #a7f3d0; }
.stat-card--purple { border-color: #ddd6fe; }
.stat-card__top { display: flex; align-items: center; justify-content: space-between; }
.stat-card__icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.stat-card__icon--blue   { background: #dbeafe; color: #2563eb; }
.stat-card__icon--yellow { background: #fef3c7; color: #d97706; }
.stat-card__icon--green  { background: #dcfce7; color: #16a34a; }
.stat-card__icon--purple { background: #f5f3ff; color: #7c3aed; }
.stat-card__trend { font-size: 12px; font-weight: 600; color: #16a34a; display: flex; align-items: center; gap: 3px; }
.stat-card__label { font-size: 12px; color: var(--color-text-muted); font-weight: 500; }
.stat-card__value { font-size: 30px; font-weight: 700; line-height: 1; }

/* CHART GRID */
.chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.chart-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 20px; }
.chart-card--full { grid-column: 1 / -1; }
.chart-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.chart-title { font-size: 13.5px; font-weight: 600; }
.chart-sub { font-size: 11.5px; color: var(--color-text-muted); margin-top: 2px; }
.chart-wrap { position: relative; height: 220px; }
.chart-wrap--tall { height: 260px; }

/* BOTTOM GRID */
.bottom-grid { display: grid; grid-template-columns: 1fr 320px; gap: 16px; margin-bottom: 20px; }

/* TABLE CARD */
.table-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.card-header {
    padding: 14px 18px; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: space-between;
}
.card-title { font-size: 13.5px; font-weight: 600; }
.card-link { font-size: 12px; color: var(--color-primary); text-decoration: none; font-weight: 500; }
.card-link:hover { text-decoration: underline; }

table { width: 100%; border-collapse: collapse; }
thead tr { border-bottom: 1px solid var(--color-border); background: #fafafa; }
th { padding: 9px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; cursor: pointer; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #f8faff; }
td { padding: 11px 14px; font-size: 12.5px; vertical-align: middle; }
.req-id { color: var(--color-primary); font-weight: 600; font-size: 11.5px; }
.req-title { font-weight: 600; }
.req-requester { color: var(--color-text-muted); }

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
.badge--high          { background: #fee2e2; color: #dc2626; }
.badge--urgent        { background: #fef3c7; color: #b45309; }
.badge--medium        { background: #fef9c3; color: #ca8a04; }
.badge--low           { background: #f0fdf4; color: #16a34a; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge--pending       { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }

/* UPCOMING CARD */
.upcoming-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.upcoming-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 18px; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background .1s;
}
.upcoming-item:last-child { border-bottom: none; }
.upcoming-item:hover { background: #f8faff; }
.upcoming-thumb {
    width: 40px; height: 40px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; font-size: 16px; overflow: hidden;
}
.upcoming-thumb img { width: 100%; height: 100%; object-fit: cover; }
.upcoming-info { flex: 1; min-width: 0; }
.upcoming-title { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.upcoming-requester { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.upcoming-date-pill {
    font-size: 11px; font-weight: 600; color: var(--color-primary);
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;
    padding: 3px 8px; white-space: nowrap; flex-shrink: 0;
}
.empty-state { padding: 28px; text-align: center; color: #9ca3af; font-size: 13px; }
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
<<<<<<< HEAD
=======
        <div class="sidebar__subtitle">Admin Dashboard</div>
>>>>>>> ecb05c3ae2b33b76297c4ed43c2a80995d659373
    </div>
    <nav class="sidebar__nav">
        <a href="index.php" class="sidebar__item sidebar__item--active">
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

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <h1>Dashboard</h1>
            <p>Welcome back! Here's what's happening today.</p>
        </div>
        <div class="admin-badge">
            <div class="admin-avatar">A</div>
            <div>
                <div class="admin-name"><?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?></div>
                <div class="admin-role">Marketing Manager</div>
            </div>
        </div>
    </div>

    <!-- PERIOD FILTER -->
    <div class="filter-row">
        <span class="filter-row-label">Showing data for the last:</span>
        <div class="period-tabs">
            <?php foreach (["7"=>"7 Days","30"=>"30 Days","90"=>"3 Months","365"=>"1 Year"] as $val=>$label): ?>
                <a href="?period=<?= $val ?>" class="period-tab <?= $period===$val ? 'period-tab--active':'' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats">
        <div class="stat-card stat-card--blue">
            <div class="stat-card__top">
                <div class="stat-card__icon stat-card__icon--blue">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <span class="stat-card__trend">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
                    All time
                </span>
            </div>
            <div class="stat-card__label">Total Requests</div>
            <div class="stat-card__value"><?= $total ?></div>
        </div>
        <div class="stat-card stat-card--yellow">
            <div class="stat-card__top">
                <div class="stat-card__icon stat-card__icon--yellow">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <span class="stat-card__trend" style="color:#d97706;">Needs action</span>
            </div>
            <div class="stat-card__label">Pending Review</div>
            <div class="stat-card__value"><?= $pending ?></div>
        </div>
        <div class="stat-card stat-card--green">
            <div class="stat-card__top">
                <div class="stat-card__icon stat-card__icon--green">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <span class="stat-card__trend">Approved</span>
            </div>
            <div class="stat-card__label">Approved</div>
            <div class="stat-card__value"><?= $approved ?></div>
        </div>
        <div class="stat-card stat-card--purple">
            <div class="stat-card__top">
                <div class="stat-card__icon stat-card__icon--purple">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </div>
                <span class="stat-card__trend" style="color:#7c3aed;">Published</span>
            </div>
            <div class="stat-card__label">Posted</div>
            <div class="stat-card__value"><?= $posted ?></div>
        </div>
    </div>

    <!-- CHARTS ROW 1: Timeline (full width) -->
    <div class="chart-grid" style="grid-template-columns:1fr; margin-bottom:16px;">
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Requests Over Time</div>
                    <div class="chart-sub">Daily submission count — last <?= $period ?> days</div>
                </div>
            </div>
            <div class="chart-wrap chart-wrap--tall">
                <canvas id="timelineChart"></canvas>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW 2: Donut + Bar + Category -->
    <div class="chart-grid" style="grid-template-columns:1fr 1fr 1fr; margin-bottom:20px;">
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Status Breakdown</div>
                    <div class="chart-sub">All requests by status</div>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Priority Distribution</div>
                    <div class="chart-sub">Last <?= $period ?> days</div>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="priorityChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Top Categories</div>
                    <div class="chart-sub">Last <?= $period ?> days</div>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- BOTTOM: Recent Requests + Upcoming -->
    <div class="bottom-grid">
        <div class="table-card">
            <div class="card-header">
                <div class="card-title">Recent Requests</div>
                <a href="request_management.php" class="card-link">View all →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Title</th><th>Requester</th><th>Priority</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="5" class="empty-state">No requests yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent as $row):
                        $pc = match(strtolower($row["priority"]??"")){
                            "urgent"=>"badge--urgent","high"=>"badge--high","medium"=>"badge--medium",default=>"badge--low"};
                        $sc = match(true){
                            str_contains(strtolower($row["status"]),"approved")    =>"badge--approved",
                            str_contains(strtolower($row["status"]),"posted")      =>"badge--posted",
                            str_contains(strtolower($row["status"]),"under review")=>"badge--under-review",
                            str_contains(strtolower($row["status"]),"rejected")    =>"badge--rejected",
                            default=>"badge--pending"};
                    ?>
                    <tr onclick="window.location='request_info.php?id=<?= $row["id"] ?>'">
                        <td><span class="req-id"><?= htmlspecialchars($row["request_id"]??"—") ?></span></td>
                        <td><div class="req-title"><?= htmlspecialchars(mb_strimwidth($row["title"],0,30,"…")) ?></div></td>
                        <td><span class="req-requester"><?= htmlspecialchars($row["requester"]) ?></span></td>
                        <td><span class="badge <?= $pc ?>"><?= htmlspecialchars($row["priority"]??"—") ?></span></td>
                        <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($row["status"]) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="upcoming-card">
            <div class="card-header">
                <div class="card-title">Upcoming Posts</div>
                <a href="scheduling_calendar.php" class="card-link">Calendar →</a>
            </div>
            <?php if (empty($upcoming)): ?>
                <div class="empty-state">No upcoming posts.</div>
            <?php else: ?>
                <?php foreach ($upcoming as $up):
                    $files = array_filter(array_map('trim', explode(",", $up["media_file"]??"")));
                    $thumb = !empty($files) ? reset($files) : "";
                    $pref  = !empty($up["preferred_date"]) ? date("M j, Y", strtotime($up["preferred_date"])) : "TBD";
                ?>
                <div class="upcoming-item" onclick="window.location='request_info.php?id=<?= $up["id"] ?>'">
                    <div class="upcoming-thumb">
                        <?php if ($thumb): ?>
                            <img src="../uploads/<?= htmlspecialchars($thumb) ?>" alt=""
                                 onerror="this.parentElement.innerHTML='📄';">
                        <?php else: ?>📄<?php endif; ?>
                    </div>
                    <div class="upcoming-info">
                        <div class="upcoming-title"><?= htmlspecialchars(mb_strimwidth($up["title"],0,28,"…")) ?></div>
                        <div class="upcoming-requester"><?= htmlspecialchars($up["requester"]) ?></div>
                    </div>
                    <div class="upcoming-date-pill"><?= $pref ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div><!-- .main -->
</div><!-- .app -->

<script>
Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.color = '#6b7280';

// ── TIMELINE CHART ────────────────────────────────────────────────────────
new Chart(document.getElementById('timelineChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($timeline_labels) ?>,
        datasets: [{
            label: 'Requests',
            data: <?= json_encode($timeline_values) ?>,
            borderColor: '#002366',
            backgroundColor: 'rgba(0,35,102,0.08)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 3,
            pointBackgroundColor: '#002366',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } }
        }
    }
});

// ── STATUS DONUT ──────────────────────────────────────────────────────────
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($status_data)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($status_data)) ?>,
            backgroundColor: ['#fde68a','#bfdbfe','#a7f3d0','#c4b5fd','#fca5a5'],
            borderColor:     ['#d97706','#2563eb','#16a34a','#7c3aed','#dc2626'],
            borderWidth: 1.5,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10, boxWidth: 12 } }
        }
    }
});

// ── PRIORITY BAR ──────────────────────────────────────────────────────────
new Chart(document.getElementById('priorityChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($priority_labels ?: ['No data']) ?>,
        datasets: [{
            label: 'Requests',
            data: <?= json_encode($priority_values ?: [0]) ?>,
            backgroundColor: ['#b45309','#dc2626','#d97706','#059669'],
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } }
        }
    }
});

// ── CATEGORY HORIZONTAL BAR ───────────────────────────────────────────────
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($cat_labels ?: ['No data']) ?>,
        datasets: [{
            label: 'Requests',
            data: <?= json_encode($cat_values ?: [0]) ?>,
            backgroundColor: 'rgba(0,35,102,0.12)',
            borderColor: '#002366',
            borderWidth: 1.5,
            borderRadius: 4,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } },
            y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});
</script>

</body>
</html>
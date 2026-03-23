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

// Public toggle — stored in session so it persists while browsing
if (isset($_GET["toggle_public"])) {
    $_SESSION["cal_public"] = !($_SESSION["cal_public"] ?? false);
    // Redirect to remove toggle param from URL
    $qs = http_build_query(array_filter([
        "month" => $_GET["month"] ?? null,
        "year"  => $_GET["year"]  ?? null,
    ]));
    header("Location: calendar.php" . ($qs ? "?$qs" : ""));
    exit();
}
$is_public = $_SESSION["cal_public"] ?? false;

// Month/Year navigation
$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)date("n");
$year  = isset($_GET["year"])  ? (int)$_GET["year"]  : (int)date("Y");

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$month_name    = date("F Y", mktime(0,0,0,$month,1,$year));
$first_day     = (int)date("w", mktime(0,0,0,$month,1,$year));
$days_in_month = (int)date("t", mktime(0,0,0,$month,1,$year));
$days_in_prev  = (int)date("t", mktime(0,0,0,$prev_month,1,$prev_year));

$today_day   = (int)date("j");
$today_month = (int)date("n");
$today_year  = (int)date("Y");

// Fetch events based on view mode
if ($is_public) {
    // Public: all users, preferred_date, title only for privacy
    $events_q = mysqli_query($conn,
        "SELECT id, title, status, preferred_date, requester,
                IF(requester='$requester', 1, 0) as is_mine
         FROM requests
         WHERE preferred_date IS NOT NULL AND preferred_date != ''
           AND MONTH(preferred_date) = $month
           AND YEAR(preferred_date)  = $year
         ORDER BY preferred_date ASC"
    );
} else {
    // Personal: only their requests by created_at
    $events_q = mysqli_query($conn,
        "SELECT *, 1 as is_mine FROM requests
         WHERE requester='$requester'
           AND MONTH(created_at) = $month
           AND YEAR(created_at)  = $year
         ORDER BY created_at ASC"
    );
}

$events = [];
while ($row = mysqli_fetch_assoc($events_q)) {
    if ($is_public) {
        $day = (int)date("j", strtotime($row["preferred_date"]));
    } else {
        $day = (int)date("j", strtotime($row["created_at"]));
    }
    $events[$day][] = $row;
}

// Upcoming 7 days
$today   = date("Y-m-d");
$in7days = date("Y-m-d", strtotime("+7 days"));
if ($is_public) {
    $upcoming_q = mysqli_query($conn,
        "SELECT id, title, status, preferred_date, requester,
                IF(requester='$requester', 1, 0) as is_mine
         FROM requests
         WHERE preferred_date BETWEEN '$today' AND '$in7days'
         ORDER BY preferred_date ASC"
    );
} else {
    $upcoming_q = mysqli_query($conn,
        "SELECT *, 1 as is_mine FROM requests
         WHERE requester='$requester'
           AND DATE(created_at) BETWEEN '$today' AND '$in7days'
         ORDER BY created_at ASC"
    );
}
$upcoming = [];
while ($row = mysqli_fetch_assoc($upcoming_q)) $upcoming[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Calendar</title>
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
    --color-event: #002366;
    --color-posted: #7c3aed;
    --font: 'Inter', sans-serif;
    --topbar-height: 56px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

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
.topnav__link--active { background: var(--color-primary); color: white; }
.topnav__link--active:hover { background: var(--color-primary-light); color: white; }
.topnav__create {
    display: flex; align-items: center; gap: 6px; padding: 7px 16px;
    background: var(--color-orange); color: white; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap;
}
.topnav__create:hover { opacity: .9; }
.topnav__search { flex: 1; max-width: 320px; position: relative; margin: 0 8px; }
.topnav__search form { display: flex; }
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
    background: none; border: none; cursor: pointer; color: var(--color-text-muted);
    text-decoration: none; transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 900px; margin: 0 auto; padding: 32px 24px; display: flex; flex-direction: column; gap: 20px; }

/* PAGE HEADER */
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.page-header-left h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header-left p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* PUBLIC TOGGLE */
.toggle-wrap { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.toggle-label { font-size: 12.5px; font-weight: 500; color: var(--color-text-muted); }
.toggle-btn {
    position: relative; width: 44px; height: 24px; border-radius: 12px;
    background: #d1d5db; border: none; cursor: pointer;
    transition: background .2s; flex-shrink: 0; padding: 0;
}
.toggle-btn.on { background: var(--color-primary); }
.toggle-btn::after {
    content: ''; position: absolute; top: 3px; left: 3px;
    width: 18px; height: 18px; border-radius: 50%; background: white;
    transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.toggle-btn.on::after { transform: translateX(20px); }

/* PUBLIC MODE BANNER */
.public-banner {
    display: flex; align-items: center; gap: 10px;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;
    padding: 10px 14px; font-size: 12.5px; color: #1e40af;
}
.public-banner svg { flex-shrink: 0; }

/* CALENDAR */
.cal-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.cal-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--color-border);
}
.cal-nav { display: flex; align-items: center; gap: 12px; }
.cal-nav-btn {
    width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--color-text-muted); transition: background .15s; text-decoration: none;
}
.cal-nav-btn:hover { background: var(--color-bg); }
.cal-month-label { font-size: 14px; font-weight: 600; min-width: 120px; text-align: center; }
.cal-today-btn {
    padding: 5px 14px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; font-size: 12.5px; font-weight: 500; cursor: pointer;
    color: var(--color-text-muted); font-family: var(--font); text-decoration: none;
}
.cal-today-btn:hover { background: var(--color-bg); }

.cal-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
.cal-grid thead th {
    padding: 10px 8px; text-align: center; font-size: 11px; font-weight: 600;
    color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--color-border);
}
.cal-grid tbody td {
    vertical-align: top; height: 100px; padding: 6px 8px;
    border: 1px solid #f3f4f6; font-size: 12px;
}
.cal-day-num {
    font-size: 12px; font-weight: 500; color: var(--color-text);
    width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; margin-bottom: 4px;
}
.cal-day-num--today { background: var(--color-primary); color: white; font-weight: 700; }
.cal-day--other .cal-day-num { color: #d1d5db; }
.cal-day--other { background: #fafafa; }

/* DAY LOAD INDICATOR */
.day-load {
    width: 100%; height: 4px; border-radius: 2px; margin-bottom: 4px;
}
.day-load--low    { background: #bbf7d0; }
.day-load--medium { background: #fde68a; }
.day-load--high   { background: #fca5a5; }
.day-count {
    font-size: 9px; font-weight: 600; color: var(--color-text-muted);
    margin-bottom: 3px; display: flex; align-items: center; gap: 3px;
}
.day-count-dot { width: 6px; height: 6px; border-radius: 50%; background: #6b7280; }

/* EVENT CHIPS */
.cal-event {
    display: block; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 500;
    color: white; background: var(--color-event); margin-bottom: 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; line-height: 1.4;
}
.cal-event--posted   { background: var(--color-posted); }
.cal-event--approved { background: #059669; }
.cal-event--pending  { background: #6b7280; }
.cal-event--review   { background: #d97706; }
.cal-event--others   { background: #9ca3af; font-style: italic; }
.cal-event--mine     { border-left: 3px solid #f97316; }
.cal-event-time { font-size: 9px; opacity: 0.85; display: block; }

/* BUSY BADGE */
.busy-badge {
    display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 9px;
    font-weight: 700; margin-bottom: 3px;
}
.busy-badge--low    { background: #dcfce7; color: #16a34a; }
.busy-badge--medium { background: #fef3c7; color: #d97706; }
.busy-badge--high   { background: #fee2e2; color: #dc2626; }

/* LEGEND */
.cal-legend {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    padding: 12px 20px; border-top: 1px solid var(--color-border);
}
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--color-text-muted); }
.legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.legend-dot--mine    { background: var(--color-event); }
.legend-dot--others  { background: #9ca3af; }
.legend-dot--posted  { background: var(--color-posted); }
.legend-dot--today   { background: var(--color-primary); border-radius: 50%; }
.legend-dot--busy-low    { background: #bbf7d0; border-radius: 2px; }
.legend-dot--busy-high   { background: #fca5a5; border-radius: 2px; }

/* UPCOMING */
.upcoming-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 20px; }
.upcoming-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 14px; }
.upcoming-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; color: #9ca3af; gap: 8px; }
.upcoming-empty p { font-size: 13px; }
.upcoming-item { display: flex; align-items: center; gap: 14px; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
.upcoming-item:last-child { border-bottom: none; }
.upcoming-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--color-event); flex-shrink: 0; }
.upcoming-dot--others { background: #9ca3af; }
.upcoming-dot--posted { background: var(--color-posted); }
.upcoming-title { font-size: 13px; font-weight: 500; }
.upcoming-meta  { font-size: 11.5px; color: var(--color-text-muted); margin-top: 1px; }
.upcoming-mine-tag {
    display: inline-block; padding: 1px 7px; background: #eff6ff; color: var(--color-primary);
    border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: 6px;
}
.upcoming-others-tag {
    display: inline-block; padding: 1px 7px; background: #f3f4f6; color: #6b7280;
    border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: 6px;
}

@media (max-width: 768px) {
    .topnav__search { display: none; }
    .cal-grid tbody td { height: 70px; padding: 4px; }
    .cal-event-time { display: none; }
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
        <a href="calendar.php" class="topnav__link topnav__link--active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Calendar
        </a>
        <a href="create_request.php" class="topnav__create">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Request
        </a>
    </div>
    <div class="topnav__search">
        <form method="GET" action="requests.php">
            <span class="topnav__search-icon">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="text" name="search" placeholder="Search requests...">
        </form>
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

    <!-- PAGE HEADER + TOGGLE -->
    <div class="page-header">
        <div class="page-header-left">
            <h1>Post Tracking Calendar</h1>
            <p><?= $is_public ? 'Showing all users\' preferred posting dates (titles only)' : 'Showing your personal post schedule' ?></p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-label">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Public Calendar
            </span>
            <a href="?toggle_public=1&month=<?= $month ?>&year=<?= $year ?>"
               style="text-decoration:none;">
                <div class="toggle-btn <?= $is_public ? 'on' : '' ?>"></div>
            </a>
        </div>
    </div>

    <!-- PUBLIC MODE BANNER -->
    <?php if ($is_public): ?>
    <div class="public-banner">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>
            <strong>Public view is ON</strong> — You can see all users' preferred posting dates (titles only, no personal info).
            Dates with many requests are highlighted in red so you can pick a less busy date.
            <strong>Your requests</strong> are shown with an orange left border.
        </span>
    </div>
    <?php endif; ?>

    <!-- CALENDAR CARD -->
    <div class="cal-card">
        <div class="cal-toolbar">
            <div class="cal-nav">
                <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="cal-nav-btn">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
                <span class="cal-month-label"><?= $month_name ?></span>
                <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="cal-nav-btn">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
            <a href="?month=<?= $today_month ?>&year=<?= $today_year ?>" class="cal-today-btn">Today</a>
        </div>

        <table class="cal-grid">
            <thead>
                <tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>
            </thead>
            <tbody>
            <?php
            $cell = 0;
            $total_cells = ceil(($first_day + $days_in_month) / 7) * 7;
            echo "<tr>";
            for ($i = 0; $i < $total_cells; $i++) {
                $day_num    = $i - $first_day + 1;
                $is_current = ($day_num >= 1 && $day_num <= $days_in_month);
                $is_today   = $is_current && $day_num === $today_day && $month === $today_month && $year === $today_year;

                if ($is_current)         $display_num = $day_num;
                elseif ($i < $first_day) $display_num = $days_in_prev - ($first_day - $i - 1);
                else                     $display_num = $day_num - $days_in_month;

                $cell_class = $is_current ? "" : "cal-day--other";
                echo "<td class='$cell_class'>";
                $num_class = $is_today ? "cal-day-num cal-day-num--today" : "cal-day-num";
                echo "<div class='$num_class'>$display_num</div>";

                if ($is_current && isset($events[$day_num])) {
                    $day_events = $events[$day_num];
                    $count      = count($day_events);

                    // In public mode — show busy indicator
                    if ($is_public) {
                        $busy_class = $count >= 4 ? "high" : ($count >= 2 ? "medium" : "low");
                        $busy_label = $count >= 4 ? "Busy" : ($count >= 2 ? "Moderate" : "Open");
                        echo "<div class='day-load day-load--$busy_class'></div>";
                        echo "<div class='day-count'><span class='day-count-dot'></span>$count request" . ($count > 1 ? "s" : "") . "</div>";
                    }

                    $shown = 0;
                    foreach ($day_events as $ev) {
                        if ($shown >= ($is_public ? 2 : 3)) break;

                        $st = strtolower($ev["status"]);
                        $is_mine = (bool)($ev["is_mine"] ?? false);

                        if ($is_public && !$is_mine) {
                            // Other users' events — show title only, grey chip
                            $short = htmlspecialchars(mb_strimwidth($ev["title"], 0, 18, "…"));
                            echo "<span class='cal-event cal-event--others' title='Someone else has a request on this date'>$short</span>";
                        } else {
                            $ev_class = match(true) {
                                str_contains($st, "posted")       => "cal-event cal-event--posted",
                                str_contains($st, "approved")     => "cal-event cal-event--approved",
                                str_contains($st, "under review") => "cal-event cal-event--review",
                                default                           => "cal-event cal-event--pending",
                            };
                            if ($is_public) $ev_class .= " cal-event--mine";
                            $short = htmlspecialchars(mb_strimwidth($ev["title"], 0, 18, "…"));
                            if (!$is_public) {
                                $time = date("g:i A", strtotime($ev["created_at"]));
                                echo "<span class='$ev_class' title='" . htmlspecialchars($ev["title"]) . "'>$short<span class='cal-event-time'>$time</span></span>";
                            } else {
                                echo "<span class='$ev_class' title='" . htmlspecialchars($ev["title"]) . " (Your request)'>$short</span>";
                            }
                        }
                        $shown++;
                    }
                    $remaining = $count - $shown;
                    if ($remaining > 0) {
                        echo "<span style='font-size:9px;color:var(--color-primary);font-weight:600;'>+$remaining more</span>";
                    }
                }
                echo "</td>";
                $cell++;
                if ($cell % 7 === 0 && $i < $total_cells - 1) echo "</tr><tr>";
            }
            echo "</tr>";
            ?>
            </tbody>
        </table>

        <!-- LEGEND -->
        <div class="cal-legend">
            <?php if ($is_public): ?>
                <div class="legend-item"><span class="legend-dot legend-dot--mine"></span>Your request</div>
                <div class="legend-item"><span class="legend-dot legend-dot--others"></span>Others' request</div>
                <div class="legend-item"><span class="legend-dot legend-dot--busy-low"></span>Open day</div>
                <div class="legend-item"><span class="legend-dot legend-dot--busy-high"></span>Busy day</div>
                <div class="legend-item"><span class="legend-dot legend-dot--today"></span>Today</div>
            <?php else: ?>
                <div class="legend-item"><span class="legend-dot legend-dot--mine"></span>Scheduled</div>
                <div class="legend-item"><span class="legend-dot legend-dot--posted"></span>Posted</div>
                <div class="legend-item"><span class="legend-dot legend-dot--today"></span>Today</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- UPCOMING -->
    <div class="upcoming-card">
        <h3><?= $is_public ? 'Upcoming Posts — Next 7 Days (All Users)' : 'Your Upcoming Posts — Next 7 Days' ?></h3>
        <?php if (empty($upcoming)): ?>
            <div class="upcoming-empty">
                <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <p>No upcoming scheduled posts in the next 7 days</p>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming as $up):
                $st        = strtolower($up["status"]);
                $is_mine   = (bool)($up["is_mine"] ?? false);
                $dot_class = !$is_mine ? "upcoming-dot upcoming-dot--others"
                           : (str_contains($st, "posted") ? "upcoming-dot upcoming-dot--posted" : "upcoming-dot");
                if ($is_public) {
                    $date_fmt = date("M j, Y", strtotime($up["preferred_date"]));
                } else {
                    $date_fmt = date("M j, Y · g:i A", strtotime($up["created_at"]));
                }
            ?>
            <div class="upcoming-item">
                <span class="<?= $dot_class ?>"></span>
                <div>
                    <div class="upcoming-title">
                        <?= htmlspecialchars($up["title"]) ?>
                        <?php if ($is_public): ?>
                            <?php if ($is_mine): ?>
                                <span class="upcoming-mine-tag">Yours</span>
                            <?php else: ?>
                                <span class="upcoming-others-tag">Others</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="upcoming-meta"><?= $date_fmt ?> · <?= htmlspecialchars($up["status"]) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>
</div>
</body>
</html>
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

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

// Fetch all requests with preferred_date in this month
$month_pad = str_pad($month, 2, "0", STR_PAD_LEFT);
$events_q  = mysqli_query($conn,
    "SELECT * FROM requests
     WHERE preferred_date IS NOT NULL
       AND preferred_date != ''
       AND MONTH(preferred_date) = $month
       AND YEAR(preferred_date)  = $year
     ORDER BY preferred_date ASC, priority DESC"
);
$events = []; // keyed by day
while ($row = mysqli_fetch_assoc($events_q)) {
    $day = (int)date("j", strtotime($row["preferred_date"]));
    $events[$day][] = $row;
}

// Upcoming 7 days from requests table
$today   = date("Y-m-d");
$in7days = date("Y-m-d", strtotime("+7 days"));
$upcoming_q = mysqli_query($conn,
    "SELECT * FROM requests
     WHERE preferred_date BETWEEN '$today' AND '$in7days'
       AND status NOT IN ('Posted','Rejected')
     ORDER BY preferred_date ASC, priority DESC"
);
$upcoming = [];
while ($row = mysqli_fetch_assoc($upcoming_q)) $upcoming[] = $row;

// For day detail panel (AJAX or GET param)
$detail_day = isset($_GET["day"]) ? (int)$_GET["day"] : 0;
$detail_requests = [];
if ($detail_day > 0 && isset($events[$detail_day])) {
    $detail_requests = $events[$detail_day];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scheduling & Calendar – NUPost Admin</title>
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
.topbar h1 { font-size: 20px; font-weight: 700; }
.admin-badge {
    display: flex; align-items: center; gap: 7px; padding: 6px 12px;
    background: white; border-radius: 8px; border: 1px solid var(--color-border); font-size: 12.5px; font-weight: 500;
}

/* LAYOUT: calendar + side panel */
.cal-layout { display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }

/* CALENDAR CARD */
.cal-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.cal-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--color-border);
}
.cal-nav { display: flex; align-items: center; gap: 12px; }
.cal-nav-btn {
    width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--color-border);
    background: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--color-text-muted); transition: background .15s; text-decoration: none;
}
.cal-nav-btn:hover { background: var(--color-bg); }
.cal-month-label { font-size: 15px; font-weight: 600; min-width: 130px; text-align: center; }
.cal-today-btn {
    padding: 5px 14px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; font-size: 12.5px; font-weight: 500; cursor: pointer;
    color: var(--color-text-muted); font-family: var(--font); text-decoration: none;
}
.cal-today-btn:hover { background: var(--color-bg); }

/* GRID */
.cal-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
.cal-grid thead th {
    padding: 10px 8px; text-align: center; font-size: 11px; font-weight: 600;
    color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--color-border);
}
.cal-grid tbody td {
    vertical-align: top; height: 110px; padding: 6px 7px;
    border: 1px solid #f3f4f6; font-size: 12px; cursor: pointer;
    transition: background .1s; user-select: none;
}
/* All child elements pass clicks through to the td */
.cal-grid tbody td * { pointer-events: none; }
.cal-grid tbody td:hover { background: #f8faff; }
.cal-grid tbody td.has-events { background: #f0f5ff; }
.cal-grid tbody td.has-events:hover { background: #e8f0fe; }
.cal-grid tbody td.selected { background: #dbeafe !important; outline: 2px solid var(--color-primary); outline-offset: -2px; }
.cal-day-num {
    font-size: 12px; font-weight: 500; color: var(--color-text);
    width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; margin-bottom: 4px; pointer-events: none;
}
.cal-day-num--today { background: var(--color-primary); color: white; font-weight: 700; }
.cal-day--other .cal-day-num { color: #d1d5db; }
.cal-day--other { background: #fafafa !important; }

/* EVENT CHIPS */
.cal-event {
    display: flex; align-items: center; gap: 4px;
    padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 500;
    color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 100%; pointer-events: none;
}
.cal-event--urgent { background: #b45309; }
.cal-event--high   { background: #dc2626; }
.cal-event--medium { background: #d97706; }
.cal-event--low    { background: #059669; }
.cal-event-dot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,0.7); flex-shrink: 0; }
.cal-more { font-size: 10px; color: var(--color-primary); font-weight: 600; margin-top: 2px; pointer-events: none; }

/* LEGEND */
.cal-legend {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    padding: 12px 20px; border-top: 1px solid var(--color-border);
}
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--color-text-muted); }
.legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }

/* SIDE PANEL */
.side-panel { display: flex; flex-direction: column; gap: 16px; }

/* DAY DETAIL CARD */
.day-detail-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden;
}
.day-detail-header {
    padding: 14px 18px; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: space-between;
}
.day-detail-title { font-size: 14px; font-weight: 600; }
.day-detail-count { font-size: 12px; color: var(--color-text-muted); }
.day-detail-body { padding: 0; max-height: 400px; overflow-y: auto; }

.day-req-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 13px 18px; border-bottom: 1px solid #f3f4f6;
    text-decoration: none; color: inherit; transition: background .1s;
}
.day-req-item:last-child { border-bottom: none; }
.day-req-item:hover { background: #f8faff; }
.day-req-priority-bar { width: 4px; border-radius: 4px; flex-shrink: 0; align-self: stretch; min-height: 40px; }
.priority-bar--urgent { background: #b45309; }
.priority-bar--high   { background: #dc2626; }
.priority-bar--medium { background: #d97706; }
.priority-bar--low    { background: #059669; }
.day-req-info { flex: 1; min-width: 0; }
.day-req-title { font-size: 12.5px; font-weight: 600; color: var(--color-text); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.day-req-requester { font-size: 11.5px; color: var(--color-text-muted); margin-bottom: 4px; }
.day-req-badges { display: flex; gap: 5px; flex-wrap: wrap; }

.day-empty { padding: 28px; text-align: center; color: #9ca3af; font-size: 13px; }

/* UPCOMING CARD */
.upcoming-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.upcoming-header { padding: 14px 18px; border-bottom: 1px solid var(--color-border); }
.upcoming-header h3 { font-size: 14px; font-weight: 600; }
.upcoming-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 18px; border-bottom: 1px solid #f3f4f6;
    text-decoration: none; color: inherit; transition: background .1s;
}
.upcoming-item:last-child { border-bottom: none; }
.upcoming-item:hover { background: #f8faff; }
.upcoming-date-box {
    width: 40px; height: 44px; background: #eff6ff; border-radius: 8px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    flex-shrink: 0; border: 1px solid #bfdbfe;
}
.upcoming-date-day   { font-size: 16px; font-weight: 700; color: var(--color-primary); line-height: 1; }
.upcoming-date-month { font-size: 9px; font-weight: 600; color: #3b82f6; text-transform: uppercase; }
.upcoming-info { flex: 1; min-width: 0; }
.upcoming-title     { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.upcoming-requester { font-size: 11.5px; color: var(--color-text-muted); margin-top: 2px; }
.upcoming-empty { padding: 24px; text-align: center; color: #9ca3af; font-size: 12.5px; }

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; white-space: nowrap; }
.badge--urgent        { background: #fef3c7; color: #b45309; }
.badge--high          { background: #fee2e2; color: #dc2626; }
.badge--medium        { background: #fef3c7; color: #d97706; }
.badge--low           { background: #f0fdf4; color: #16a34a; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; }
.badge--pending       { background: #f3f4f6; color: #6b7280; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }
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
        <a href="scheduling_calendar.php" class="sidebar__item sidebar__item--active">
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

<!-- MAIN -->
<div class="main">

    <div class="topbar">
        <div>
            <h1>Scheduling &amp; Calendar</h1>
            <p style="font-size:13px;color:var(--color-text-muted);margin-top:2px;">View and manage preferred posting dates</p>
        </div>
        <div class="admin-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
        </div>
    </div>

    <div class="cal-layout">

        <!-- CALENDAR -->
        <div>
            <div class="cal-card">
                <!-- TOOLBAR -->
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

                <!-- GRID -->
                <table class="cal-grid">
                    <thead>
                        <tr>
                            <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                        </tr>
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
                        $has_events = $is_current && isset($events[$day_num]);
                        $is_selected = $is_current && $day_num === $detail_day;

                        if ($is_current)         $display_num = $day_num;
                        elseif ($i < $first_day) $display_num = $days_in_prev - ($first_day - $i - 1);
                        else                     $display_num = $day_num - $days_in_month;

                        $classes = [];
                        if (!$is_current) $classes[] = "cal-day--other";
                        if ($has_events)  $classes[] = "has-events";
                        if ($is_selected) $classes[] = "selected";
                        $class_str = implode(" ", $classes);

                        $onclick = $is_current ? "onclick=\"selectDay($day_num, this)\"" : "";
                        echo "<td class='$class_str' $onclick>";

                        $num_class = $is_today ? "cal-day-num cal-day-num--today" : "cal-day-num";
                        echo "<div class='$num_class'>$display_num</div>";

                        if ($has_events) {
                            $shown = 0;
                            foreach ($events[$day_num] as $ev) {
                                if ($shown >= 2) break;
                                $p = strtolower($ev["priority"] ?? "low");
                                $chip_class = match($p) {
                                    "urgent" => "cal-event--urgent",
                                    "high"   => "cal-event--high",
                                    "medium" => "cal-event--medium",
                                    default  => "cal-event--low",
                                };
                                $short = htmlspecialchars(mb_strimwidth($ev["title"], 0, 18, "…"));
                                echo "<div class='cal-event $chip_class'><span class='cal-event-dot'></span>$short</div>";
                                $shown++;
                            }
                            $remaining = count($events[$day_num]) - $shown;
                            if ($remaining > 0) {
                                echo "<div class='cal-more'>+$remaining more</div>";
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
                    <div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span>Urgent</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#dc2626;"></span>High</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#d97706;"></span>Medium</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#059669;"></span>Low</div>
                    <div class="legend-item"><span class="legend-dot" style="background:var(--color-primary);border-radius:50%;"></span>Today</div>
                </div>
            </div>
        </div>

        <!-- SIDE PANEL -->
        <div class="side-panel">

            <!-- DAY DETAIL -->
            <div class="day-detail-card" id="day-detail-card">
                <div class="day-detail-header">
                    <div class="day-detail-title" id="day-detail-title">
                        <?php if ($detail_day > 0): ?>
                            <?= date("F j, Y", mktime(0,0,0,$month,$detail_day,$year)) ?>
                        <?php else: ?>
                            Click a date to view requests
                        <?php endif; ?>
                    </div>
                    <?php if ($detail_day > 0 && !empty($detail_requests)): ?>
                        <span class="day-detail-count"><?= count($detail_requests) ?> request<?= count($detail_requests) !== 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
                <div class="day-detail-body" id="day-detail-body">
                    <?php if ($detail_day > 0): ?>
                        <?php if (!empty($detail_requests)): ?>
                            <?php foreach ($detail_requests as $req):
                                $p = strtolower($req["priority"] ?? "low");
                                $bar_class = match($p) { "urgent"=>"priority-bar--urgent","high"=>"priority-bar--high","medium"=>"priority-bar--medium",default=>"priority-bar--low" };
                                $pbadge = match($p) { "urgent"=>"badge--urgent","high"=>"badge--high","medium"=>"badge--medium",default=>"badge--low" };
                                $s = strtolower($req["status"]);
                                $sbadge = match(true) {
                                    str_contains($s,"approved")     => "badge--approved",
                                    str_contains($s,"posted")       => "badge--posted",
                                    str_contains($s,"under review") => "badge--under-review",
                                    str_contains($s,"rejected")     => "badge--rejected",
                                    default                         => "badge--pending",
                                };
                            ?>
                            <a href="request_info.php?id=<?= $req["id"] ?>" class="day-req-item">
                                <div class="day-req-priority-bar <?= $bar_class ?>"></div>
                                <div class="day-req-info">
                                    <div class="day-req-title"><?= htmlspecialchars($req["title"]) ?></div>
                                    <div class="day-req-requester"><?= htmlspecialchars($req["requester"]) ?></div>
                                    <div class="day-req-badges">
                                        <span class="badge <?= $pbadge ?>"><?= htmlspecialchars($req["priority"]) ?></span>
                                        <span class="badge <?= $sbadge ?>"><?= htmlspecialchars($req["status"]) ?></span>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="day-empty">No requests for this date.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="day-empty">
                            <svg width="32" height="32" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;">
                                <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            Click any date on the calendar to see the requests scheduled for that day.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- UPCOMING 7 DAYS -->
            <div class="upcoming-card">
                <div class="upcoming-header">
                    <h3>Upcoming — Next 7 Days</h3>
                </div>
                <?php if (empty($upcoming)): ?>
                    <div class="upcoming-empty">No upcoming scheduled requests.</div>
                <?php else: ?>
                    <?php foreach ($upcoming as $up):
                        $p  = strtolower($up["priority"] ?? "low");
                        $pb = match($p) { "urgent"=>"badge--urgent","high"=>"badge--high","medium"=>"badge--medium",default=>"badge--low" };
                        $day_num_up   = date("j",  strtotime($up["preferred_date"]));
                        $month_up     = date("M",  strtotime($up["preferred_date"]));
                    ?>
                    <a href="request_info.php?id=<?= $up["id"] ?>" class="upcoming-item">
                        <div class="upcoming-date-box">
                            <div class="upcoming-date-day"><?= $day_num_up ?></div>
                            <div class="upcoming-date-month"><?= $month_up ?></div>
                        </div>
                        <div class="upcoming-info">
                            <div class="upcoming-title"><?= htmlspecialchars($up["title"]) ?></div>
                            <div class="upcoming-requester"><?= htmlspecialchars($up["requester"]) ?></div>
                        </div>
                        <span class="badge <?= $pb ?>"><?= htmlspecialchars($up["priority"]) ?></span>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div><!-- .side-panel -->
    </div><!-- .cal-layout -->

</div><!-- .main -->
</div><!-- .app -->

<script>
// All events from PHP — keyed by day number
const allEvents = <?php
    $js_events = [];
    foreach ($events as $day => $reqs) {
        $js_events[$day] = array_map(function($r) {
            return [
                'id'        => $r['id'],
                'title'     => $r['title'],
                'requester' => $r['requester'],
                'priority'  => $r['priority'],
                'status'    => $r['status'],
            ];
        }, $reqs);
    }
    echo json_encode($js_events);
?>;

const monthName  = '<?= $month_name ?>';
const monthNum   = <?= $month ?>;
const yearNum    = <?= $year ?>;

function selectDay(day, el) {
    // Remove previous selection from ALL tds
    document.querySelectorAll('.cal-grid tbody td').forEach(td => td.classList.remove('selected'));

    // Find the td by traversing up from whatever was clicked
    let td = el;
    while (td && td.tagName !== 'TD') td = td.parentElement;
    if (td) td.classList.add('selected');

    const reqs    = allEvents[day] || [];
    const months  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const dateStr = months[monthNum - 1] + ' ' + day + ', ' + yearNum;

    // Always update the header
    document.querySelector('.day-detail-header').innerHTML = `
        <div class="day-detail-title">${dateStr}</div>
        ${reqs.length > 0 ? `<span class="day-detail-count">${reqs.length} request${reqs.length !== 1 ? 's' : ''}</span>` : ''}
    `;

    const body = document.getElementById('day-detail-body');

    if (reqs.length === 0) {
        body.innerHTML = '<div class="day-empty">No requests for this date.</div>';
        return;
    }

    function priorityBar(p) {
        p = (p||'').toLowerCase();
        if (p === 'urgent') return 'priority-bar--urgent';
        if (p === 'high')   return 'priority-bar--high';
        if (p === 'medium') return 'priority-bar--medium';
        return 'priority-bar--low';
    }
    function priorityBadge(p) {
        p = (p||'').toLowerCase();
        if (p === 'urgent') return 'badge--urgent';
        if (p === 'high')   return 'badge--high';
        if (p === 'medium') return 'badge--medium';
        return 'badge--low';
    }
    function statusBadge(s) {
        s = (s||'').toLowerCase();
        if (s.includes('approved'))     return 'badge--approved';
        if (s.includes('posted'))       return 'badge--posted';
        if (s.includes('under review')) return 'badge--under-review';
        if (s.includes('rejected'))     return 'badge--rejected';
        return 'badge--pending';
    }
    function esc(str) { return str ? String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '—'; }

    body.innerHTML = reqs.map(r => `
        <a href="request_info.php?id=${r.id}" class="day-req-item">
            <div class="day-req-priority-bar ${priorityBar(r.priority)}"></div>
            <div class="day-req-info">
                <div class="day-req-title">${esc(r.title)}</div>
                <div class="day-req-requester">${esc(r.requester)}</div>
                <div class="day-req-badges">
                    <span class="badge ${priorityBadge(r.priority)}">${esc(r.priority)}</span>
                    <span class="badge ${statusBadge(r.status)}">${esc(r.status)}</span>
                </div>
            </div>
        </a>
    `).join('');
}
</script>

</body>
</html>
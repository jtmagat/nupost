<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_name = $_SESSION["name"];
$requester = mysqli_real_escape_string($conn, $user_name);

// Current month/year navigation
$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)date("n");
$year  = isset($_GET["year"])  ? (int)$_GET["year"]  : (int)date("Y");

// Clamp valid ranges
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prev_month = $month - 1;
$prev_year  = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year  = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$month_name   = date("F Y", mktime(0,0,0,$month,1,$year));
$first_day    = (int)date("w", mktime(0,0,0,$month,1,$year));  // 0=Sun
$days_in_month = (int)date("t", mktime(0,0,0,$month,1,$year));
$days_in_prev  = (int)date("t", mktime(0,0,0,$prev_month,1,$prev_year));

// Fetch this user's requests that have a created_at in this month (or scheduled_date if you add it)
// We use created_at as the calendar date for now
$month_pad = str_pad($month, 2, "0", STR_PAD_LEFT);
$result = mysqli_query($conn,
    "SELECT * FROM requests
     WHERE requester='$requester'
     AND MONTH(created_at)=$month AND YEAR(created_at)=$year
     ORDER BY created_at ASC"
);

$events = []; // keyed by day number
while ($row = mysqli_fetch_assoc($result)) {
    $day = (int)date("j", strtotime($row["created_at"]));
    $events[$day][] = $row;
}

// Upcoming 7 days
$today     = date("Y-m-d");
$in7days   = date("Y-m-d", strtotime("+7 days"));
$upcoming_result = mysqli_query($conn,
    "SELECT * FROM requests
     WHERE requester='$requester'
     AND DATE(created_at) BETWEEN '$today' AND '$in7days'
     ORDER BY created_at ASC"
);
$upcoming = [];
while ($row = mysqli_fetch_assoc($upcoming_result)) {
    $upcoming[] = $row;
}

$today_day   = (int)date("j");
$today_month = (int)date("n");
$today_year  = (int)date("Y");
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
    --color-today-bg: #dbeafe;
    --font: 'Inter', sans-serif;
    --topbar-height: 56px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* ===== TOP NAV ===== */
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
.topnav__search input {
    width: 100%; height: 36px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px 0 36px; font-size: 13px; font-family: var(--font);
    background: var(--color-bg); outline: none;
}
.topnav__search input::placeholder { color: #9ca3af; }
.topnav__search input:focus { border-color: var(--color-primary); background: white; }
.topnav__search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.topnav__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topnav__icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer; color: var(--color-text-muted);
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* ===== LAYOUT ===== */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 900px; margin: 0 auto; padding: 32px 24px; display: flex; flex-direction: column; gap: 20px; }

/* ===== PAGE HEADER ===== */
.page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* ===== CALENDAR CARD ===== */
.cal-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }

/* CALENDAR TOOLBAR */
.cal-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--color-border);
}
.cal-nav { display: flex; align-items: center; gap: 12px; }
.cal-nav-btn {
    width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--color-text-muted); transition: background .15s;
}
.cal-nav-btn:hover { background: var(--color-bg); }
.cal-month-label { font-size: 14px; font-weight: 600; min-width: 120px; text-align: center; }
.cal-view-toggle { display: flex; align-items: center; gap: 6px; }
.cal-today-btn {
    padding: 5px 14px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; font-size: 12.5px; font-weight: 500; cursor: pointer;
    color: var(--color-text-muted); font-family: var(--font);
}
.cal-today-btn:hover { background: var(--color-bg); }
.cal-view-btn {
    padding: 5px 14px; border-radius: 6px; border: 1px solid var(--color-border);
    background: none; font-size: 12.5px; font-weight: 500; cursor: pointer;
    color: var(--color-text-muted); font-family: var(--font); transition: background .15s, color .15s;
}
.cal-view-btn:hover { background: var(--color-bg); }
.cal-view-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

/* CALENDAR GRID */
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

.cal-day-num--today {
    background: var(--color-primary); color: white; font-weight: 700;
}

.cal-day--other .cal-day-num { color: #d1d5db; }
.cal-day--other { background: #fafafa; }

/* EVENT CHIPS */
.cal-event {
    display: block; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 500;
    color: white; background: var(--color-event); margin-bottom: 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer;
    line-height: 1.4;
}
.cal-event--posted   { background: var(--color-posted); }
.cal-event--approved { background: #059669; }
.cal-event--pending  { background: #6b7280; }
.cal-event--review   { background: #d97706; }
.cal-event-time { font-size: 9px; opacity: 0.85; display: block; }

/* ===== LEGEND ===== */
.cal-legend {
    display: flex; align-items: center; gap: 20px;
    padding: 12px 20px; border-top: 1px solid var(--color-border);
}
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--color-text-muted); }
.legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.legend-dot--scheduled { background: var(--color-event); }
.legend-dot--posted    { background: var(--color-posted); }
.legend-dot--today     { background: var(--color-primary); border-radius: 50%; }

/* ===== UPCOMING CARD ===== */
.upcoming-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 20px; }
.upcoming-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 30px 20px; color: #9ca3af; gap: 10px;
}
.upcoming-empty p { font-size: 13px; }

.upcoming-item {
    display: flex; align-items: center; gap: 14px;
    padding: 10px 0; border-bottom: 1px solid #f3f4f6;
}
.upcoming-item:last-child { border-bottom: none; }
.upcoming-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--color-event); flex-shrink: 0; }
.upcoming-dot--posted { background: var(--color-posted); }
.upcoming-title { font-size: 13px; font-weight: 500; }
.upcoming-meta  { font-size: 11.5px; color: var(--color-text-muted); margin-top: 1px; }

@media (max-width: 768px) {
    .topnav__search { display: none; }
    .cal-grid tbody td { height: 70px; padding: 4px; }
    .cal-event-time { display: none; }
}
</style>
</head>
<body>

<!-- TOP NAV -->
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
        <span class="topnav__search-icon">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </span>
        <input type="text" placeholder="Search requests...">
    </div>

    <div class="topnav__actions">
        <button class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="topnav__badge">0</span>
        </button>
        <a href="profile.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<!-- MAIN -->
<div class="layout">
<main class="main">

    <div class="page-header">
        <h1>Post Tracking Calendar</h1>
        <p>Manage and schedule your content posts</p>
    </div>

    <!-- CALENDAR CARD -->
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

            <div class="cal-view-toggle">
                <a href="?month=<?= $today_month ?>&year=<?= $today_year ?>" class="cal-today-btn">Today</a>
                <button class="cal-view-btn cal-view-btn--active">Month</button>
                <button class="cal-view-btn">Week</button>
            </div>
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

                if ($is_current) {
                    $display_num = $day_num;
                } elseif ($i < $first_day) {
                    $display_num = $days_in_prev - ($first_day - $i - 1);
                } else {
                    $display_num = $day_num - $days_in_month;
                }

                $cell_class = $is_current ? "" : "cal-day--other";
                echo "<td class='$cell_class'>";

                $num_class = $is_today ? "cal-day-num cal-day-num--today" : "cal-day-num";
                echo "<div class='$num_class'>$display_num</div>";

                // Render events on this day
                if ($is_current && isset($events[$day_num])) {
                    foreach ($events[$day_num] as $ev) {
                        $st = strtolower($ev["status"]);
                        $ev_class = match(true) {
                            str_contains($st, "posted")       => "cal-event cal-event--posted",
                            str_contains($st, "approved")     => "cal-event cal-event--approved",
                            str_contains($st, "under review") => "cal-event cal-event--review",
                            default                           => "cal-event cal-event--pending",
                        };
                        $time = date("g:i A", strtotime($ev["created_at"]));
                        $title = htmlspecialchars(mb_strimwidth($ev["title"], 0, 20, "…"));
                        echo "<span class='$ev_class' title='" . htmlspecialchars($ev["title"]) . "'>
                                $title
                                <span class='cal-event-time'>$time</span>
                              </span>";
                    }
                }

                echo "</td>";
                $cell++;

                if ($cell % 7 === 0 && $i < $total_cells - 1) {
                    echo "</tr><tr>";
                }
            }
            echo "</tr>";
            ?>
            </tbody>
        </table>

        <!-- LEGEND -->
        <div class="cal-legend">
            <div class="legend-item">
                <span class="legend-dot legend-dot--scheduled"></span>
                Scheduled Post
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-dot--posted"></span>
                Posted
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-dot--today"></span>
                Today
            </div>
        </div>

    </div>

    <!-- UPCOMING CARD -->
    <div class="upcoming-card">
        <?php if (empty($upcoming)): ?>
            <div class="upcoming-empty">
                <!-- calendar icon -->
                <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <p>No upcoming posts scheduled for the next 7 days</p>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming as $up):
                $st = strtolower($up["status"]);
                $dot_class = str_contains($st, "posted") ? "upcoming-dot upcoming-dot--posted" : "upcoming-dot";
                $date_fmt  = date("M j, Y · g:i A", strtotime($up["created_at"]));
            ?>
            <div class="upcoming-item">
                <span class="<?= $dot_class ?>"></span>
                <div>
                    <div class="upcoming-title"><?= htmlspecialchars($up["title"]) ?></div>
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
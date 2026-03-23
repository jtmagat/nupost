<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// ── FILTERS ───────────────────────────────────────────────────────────────
$report_type = $_GET["report_type"] ?? "";
$start_date  = $_GET["start_date"]  ?? "";
$end_date    = $_GET["end_date"]    ?? "";

$where_parts = ["1=1"];
if ($start_date !== "") $where_parts[] = "DATE(created_at) >= '" . mysqli_real_escape_string($conn, $start_date) . "'";
if ($end_date   !== "") $where_parts[] = "DATE(created_at) <= '" . mysqli_real_escape_string($conn, $end_date) . "'";
if ($report_type !== "") $where_parts[] = "category = '" . mysqli_real_escape_string($conn, $report_type) . "'";
$where = implode(" AND ", $where_parts);

// ── STAT CARDS ────────────────────────────────────────────────────────────
$total_q   = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM requests WHERE $where");
$total_row = mysqli_fetch_assoc($total_q);
$total_requests = (int)($total_row['cnt'] ?? 0);

$posted_q   = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM requests WHERE $where AND status='Posted'");
$posted_row = mysqli_fetch_assoc($posted_q);
$posted_count = (int)($posted_row['cnt'] ?? 0);
$completion_rate = $total_requests > 0 ? round(($posted_count / $total_requests) * 100, 1) : 0;

// Avg processing time (days from created_at to when status became Posted — approx using preferred_date)
$avg_q   = mysqli_query($conn, "SELECT AVG(DATEDIFF(COALESCE(preferred_date, NOW()), created_at)) as avg_days FROM requests WHERE $where AND status='Posted'");
$avg_row = mysqli_fetch_assoc($avg_q);
$avg_days = round((float)($avg_row['avg_days'] ?? 0), 1);
if ($avg_days <= 0) $avg_days = "—";

// Most common category
$cat_q   = mysqli_query($conn, "SELECT category, COUNT(*) as cnt FROM requests WHERE $where GROUP BY category ORDER BY cnt DESC LIMIT 1");
$cat_row = mysqli_fetch_assoc($cat_q);
$top_category = $cat_row['category'] ?? "—";

// ── STATUS DISTRIBUTION ───────────────────────────────────────────────────
$status_q = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM requests WHERE $where GROUP BY status");
$status_data = [];
while ($s = mysqli_fetch_assoc($status_q)) $status_data[$s['status']] = (int)$s['cnt'];

// ── CATEGORY DISTRIBUTION ─────────────────────────────────────────────────
$cat_dist_q = mysqli_query($conn, "SELECT category, COUNT(*) as cnt FROM requests WHERE $where GROUP BY category ORDER BY cnt DESC");
$cat_labels = $cat_counts = [];
while ($c = mysqli_fetch_assoc($cat_dist_q)) {
    $cat_labels[] = $c['category'] ?: 'Uncategorized';
    $cat_counts[] = (int)$c['cnt'];
}

// ── PRIORITY DISTRIBUTION ─────────────────────────────────────────────────
$prio_q = mysqli_query($conn, "SELECT priority, COUNT(*) as cnt FROM requests WHERE $where GROUP BY priority");
$prio_data = [];
while ($p = mysqli_fetch_assoc($prio_q)) $prio_data[$p['priority']] = (int)$p['cnt'];

// ── TOP REQUESTERS ────────────────────────────────────────────────────────
$top_req_q = mysqli_query($conn, "SELECT requester, COUNT(*) as cnt FROM requests WHERE $where GROUP BY requester ORDER BY cnt DESC LIMIT 5");
$top_requesters = [];
while ($r = mysqli_fetch_assoc($top_req_q)) $top_requesters[] = $r;
$max_req_count = !empty($top_requesters) ? $top_requesters[0]['cnt'] : 1;

// ── REQUEST DETAILS TABLE ─────────────────────────────────────────────────
$details_q = mysqli_query($conn, "SELECT request_id, title, requester, category, priority, status, created_at FROM requests WHERE $where ORDER BY created_at DESC");
$details = [];
while ($d = mysqli_fetch_assoc($details_q)) $details[] = $d;

// ── DISTINCT CATEGORIES FOR FILTER ────────────────────────────────────────
$cats_q  = mysqli_query($conn, "SELECT DISTINCT category FROM requests WHERE category IS NOT NULL AND category != '' ORDER BY category");
$all_cats = [];
while ($c = mysqli_fetch_assoc($cats_q)) $all_cats[] = $c['category'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports – NUPost Admin</title>
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
    --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
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
.page-sub { font-size: 13px; color: var(--color-text-muted); margin-bottom: 22px; }

/* ── REPORT CONFIG CARD ── */
.config-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 22px 24px; margin-bottom: 24px;
}
.config-title { font-size: 14px; font-weight: 700; color: var(--color-text); margin-bottom: 16px; }
.config-fields { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.config-field { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 160px; }
.config-field label { font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; }
.config-field select,
.config-field input[type="date"] {
    height: 38px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px; font-size: 13px; font-family: var(--font);
    background: white; color: var(--color-text); outline: none;
    transition: border-color .15s; cursor: pointer;
}
.config-field select:focus,
.config-field input[type="date"]:focus { border-color: var(--color-primary); }
.config-btns { display: flex; gap: 8px; padding-bottom: 1px; }
.btn-export-pdf {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s; text-decoration: none;
}
.btn-export-pdf:hover { background: var(--color-primary-light); }
.btn-export-csv {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: white; color: var(--color-text);
    border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s; text-decoration: none;
}
.btn-export-csv:hover { background: var(--color-bg); }

/* ── STAT CARDS ── */
.stat-cards { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 18px 20px; display: flex; align-items: flex-start; gap: 14px;
}
.stat-card-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.stat-card-icon--blue   { background: #eff6ff; color: #3b82f6; }
.stat-card-icon--green  { background: #ecfdf5; color: #10b981; }
.stat-card-icon--orange { background: #fff7ed; color: #f97316; }
.stat-card-icon--yellow { background: #fefce8; color: #eab308; }
.stat-card-body { flex: 1; }
.stat-card-label { font-size: 11.5px; color: var(--color-text-muted); font-weight: 500; margin-bottom: 5px; }
.stat-card-value { font-size: 22px; font-weight: 700; color: var(--color-text); line-height: 1.2; }
.stat-card-sub { font-size: 11px; color: var(--color-text-muted); margin-top: 3px; }

/* ── TWO COL GRID ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
.chart-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 22px 24px;
}
.chart-card-title { font-size: 13.5px; font-weight: 700; color: var(--color-text); margin-bottom: 18px; }

/* PIE CHART */
.pie-wrap { display: flex; align-items: center; gap: 24px; }
.pie-canvas-wrap { position: relative; width: 180px; height: 180px; flex-shrink: 0; }
.pie-legend { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.pie-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--color-text-muted); }
.pie-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.pie-legend-label { flex: 1; }
.pie-legend-val { font-weight: 600; color: var(--color-text); }

/* BAR CHART */
.bar-canvas-wrap { position: relative; height: 200px; }

/* TOP REQUESTERS */
.top-req-list { display: flex; flex-direction: column; gap: 12px; }
.top-req-item { display: flex; align-items: center; gap: 10px; }
.top-req-rank {
    width: 22px; height: 22px; border-radius: 50%; background: var(--color-primary);
    color: white; font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.top-req-rank--2 { background: #3b82f6; }
.top-req-rank--3 { background: #10b981; }
.top-req-rank--4 { background: #f59e0b; }
.top-req-rank--5 { background: #8b5cf6; }
.top-req-name { font-size: 13px; font-weight: 500; color: var(--color-text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-req-bar-wrap { flex: 2; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
.top-req-bar { height: 100%; border-radius: 3px; background: var(--color-primary); transition: width .4s; }
.top-req-count { font-size: 11.5px; color: var(--color-text-muted); flex-shrink: 0; min-width: 60px; text-align: right; }

/* ── REQUEST DETAILS TABLE ── */
.table-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 22px 24px; margin-bottom: 24px; overflow: hidden;
}
.table-card-title { font-size: 13.5px; font-weight: 700; color: var(--color-text); margin-bottom: 18px; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
    text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-muted);
    text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 12px;
    border-bottom: 1px solid var(--color-border); white-space: nowrap; background: #fafafa;
}
tbody td { padding: 12px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: var(--color-text); }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #fafbff; }
.req-id-link { color: var(--color-primary); font-weight: 600; text-decoration: none; font-size: 12px; }
.req-id-link:hover { text-decoration: underline; }

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; }
.badge--pending       { background: #f3f4f6; color: #6b7280; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }
.badge--scheduled     { background: #ede9fe; color: #7c3aed; }
.badge--seen          { background: #f0f9ff; color: #0284c7; }
.badge--high          { background: #fee2e2; color: #dc2626; }
.badge--urgent        { background: #fef3c7; color: #b45309; }
.badge--medium        { background: #fef9c3; color: #854d0e; }
.badge--low           { background: #f3f4f6; color: #6b7280; }

/* EMPTY STATE */
.empty-state { padding: 40px; text-align: center; color: #9ca3af; font-size: 13px; }

/* PRINT */
@media print {
    .sidebar, .topbar, .config-card, .config-btns { display: none !important; }
    .main { margin-left: 0 !important; }
    .content { padding: 0 !important; }
}
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
        <a href="reports.php" class="sidebar__item sidebar__item--active">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Reports
        </a>
        <a href="settings.php" class="sidebar__item">
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
        <span class="topbar-title">Reports</span>
        <div class="admin-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
        </div>
    </div>

    <div class="content" id="report-content">
        <p class="page-sub">Generate and export detailed reports on content requests and performance.</p>

        <!-- REPORT CONFIGURATION -->
        <div class="config-card">
            <div class="config-title">Report Configuration</div>
            <form method="GET" action="reports.php">
                <div class="config-fields">
                    <div class="config-field">
                        <label>Report Type</label>
                        <select name="report_type">
                            <option value="">All Categories</option>
                            <?php foreach ($all_cats as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $report_type === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="config-field">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="config-field">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="config-field" style="flex:0;">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-export-pdf" style="background:#374151;color:white;">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            Filter
                        </button>
                    </div>
                </div>
                <div style="margin-top:14px;" class="config-btns">
                    <button type="button" onclick="exportPDF()" class="btn-export-pdf">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Export as PDF
                    </button>
                    <a href="export_csv.php?report_type=<?= urlencode($report_type) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="btn-export-csv">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
                        Export as CSV
                    </a>
                </div>
            </form>
        </div>

        <!-- STAT CARDS -->
        <div class="stat-cards">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--blue">
                    <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-label">Total Requests</div>
                    <div class="stat-card-value"><?= $total_requests ?></div>
                    <div class="stat-card-sub">In selected period</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--green">
                    <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-label">Completion Rate</div>
                    <div class="stat-card-value"><?= $completion_rate ?>%</div>
                    <div class="stat-card-sub">Posted vs. total</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--orange">
                    <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-label">Avg. Processing Time</div>
                    <div class="stat-card-value"><?= $avg_days ?></div>
                    <div class="stat-card-sub">Days from submit to post</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--yellow">
                    <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-label">Most Common</div>
                    <div class="stat-card-value" style="font-size:18px;"><?= htmlspecialchars($top_category) ?></div>
                    <div class="stat-card-sub">Top category</div>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW 1 -->
        <div class="two-col">
            <!-- Request Status Distribution -->
            <div class="chart-card">
                <div class="chart-card-title">Request Status Distribution</div>
                <?php if (!empty($status_data)): ?>
                <div class="pie-wrap">
                    <div class="pie-canvas-wrap">
                        <canvas id="statusPie"></canvas>
                    </div>
                    <div class="pie-legend">
                        <?php
                        $status_colors = [
                            'Pending Review' => '#94a3b8',
                            'Under Review'   => '#3b82f6',
                            'Approved'       => '#8b5cf6',
                            'Posted'         => '#002366',
                            'Rejected'       => '#ef4444',
                        ];
                        $status_pct_total = array_sum($status_data);
                        foreach ($status_data as $st => $cnt):
                            $pct = $status_pct_total > 0 ? round(($cnt / $status_pct_total) * 100) : 0;
                            $color = $status_colors[$st] ?? '#6b7280';
                        ?>
                        <div class="pie-legend-item">
                            <div class="pie-legend-dot" style="background:<?= $color ?>;"></div>
                            <span class="pie-legend-label"><?= htmlspecialchars($st) ?> <?= $pct ?>%</span>
                            <span class="pie-legend-val"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                    <div class="empty-state">No data yet.</div>
                <?php endif; ?>
            </div>

            <!-- Requests by Category Bar -->
            <div class="chart-card">
                <div class="chart-card-title">Requests by Category</div>
                <?php if (!empty($cat_labels)): ?>
                <div class="bar-canvas-wrap">
                    <canvas id="categoryBar"></canvas>
                </div>
                <?php else: ?>
                    <div class="empty-state">No data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- CHARTS ROW 2 -->
        <div class="two-col">
            <!-- Priority Distribution -->
            <div class="chart-card">
                <div class="chart-card-title">Priority Distribution</div>
                <?php if (!empty($prio_data)): ?>
                <div class="pie-wrap">
                    <div class="pie-canvas-wrap">
                        <canvas id="priorityPie"></canvas>
                    </div>
                    <div class="pie-legend">
                        <?php
                        $prio_colors = [
                            'Low'    => '#10b981',
                            'Medium' => '#f59e0b',
                            'High'   => '#f97316',
                            'Urgent' => '#ef4444',
                        ];
                        $prio_total = array_sum($prio_data);
                        foreach ($prio_data as $pr => $cnt):
                            $pct = $prio_total > 0 ? round(($cnt / $prio_total) * 100) : 0;
                            $color = $prio_colors[$pr] ?? '#6b7280';
                        ?>
                        <div class="pie-legend-item">
                            <div class="pie-legend-dot" style="background:<?= $color ?>;"></div>
                            <span class="pie-legend-label"><?= htmlspecialchars($pr) ?>: <?= $cnt ?></span>
                            <span class="pie-legend-val"><?= $pct ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                    <div class="empty-state">No data yet.</div>
                <?php endif; ?>
            </div>

            <!-- Top Requesters -->
            <div class="chart-card">
                <div class="chart-card-title">Top Requesters</div>
                <?php if (!empty($top_requesters)): ?>
                <div class="top-req-list">
                    <?php foreach ($top_requesters as $i => $tr):
                        $rank = $i + 1;
                        $bar_pct = $max_req_count > 0 ? round(($tr['cnt'] / $max_req_count) * 100) : 0;
                        $rank_class = $rank > 1 ? "top-req-rank--{$rank}" : "";
                    ?>
                    <div class="top-req-item">
                        <div class="top-req-rank <?= $rank_class ?>"><?= $rank ?></div>
                        <div class="top-req-name"><?= htmlspecialchars($tr['requester']) ?></div>
                        <div class="top-req-bar-wrap">
                            <div class="top-req-bar" style="width:<?= $bar_pct ?>%;"></div>
                        </div>
                        <div class="top-req-count"><?= $tr['cnt'] ?> request<?= $tr['cnt'] != 1 ? 's' : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="empty-state">No data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- REQUEST DETAILS TABLE -->
        <div class="table-card">
            <div class="table-card-title">Request Details</div>
            <?php if (!empty($details)): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Title</th>
                            <th>Requester</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details as $d):
                            $rid = htmlspecialchars($d['request_id'] ?? $d['id'] ?? '—');
                            $status_lc = strtolower($d['status'] ?? '');
                            $status_badge = match(true) {
                                str_contains($status_lc, 'approved')     => 'badge--approved',
                                str_contains($status_lc, 'posted')       => 'badge--posted',
                                str_contains($status_lc, 'under review') => 'badge--under-review',
                                str_contains($status_lc, 'rejected')     => 'badge--rejected',
                                str_contains($status_lc, 'seen')         => 'badge--seen',
                                default                                  => 'badge--pending',
                            };
                            $prio_lc = strtolower($d['priority'] ?? '');
                            $prio_badge = match($prio_lc) {
                                'high'   => 'badge--high',
                                'urgent' => 'badge--urgent',
                                'medium' => 'badge--medium',
                                default  => 'badge--low',
                            };
                            $date_fmt = !empty($d['created_at']) ? date("M j, Y", strtotime($d['created_at'])) : '—';
                        ?>
                        <tr>
                            <td>
                                <a href="request_info.php?id=<?= htmlspecialchars($d['id'] ?? '') ?>" class="req-id-link">
                                    REQ-<?= $rid ?>
                                </a>
                            </td>
                            <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= htmlspecialchars($d['title'] ?? '—') ?>
                            </td>
                            <td><?= htmlspecialchars($d['requester'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($d['category'] ?? '—') ?></td>
                            <td><span class="badge <?= $prio_badge ?>"><?= htmlspecialchars($d['priority'] ?? '—') ?></span></td>
                            <td><span class="badge <?= $status_badge ?>"><?= htmlspecialchars($d['status'] ?? '—') ?></span></td>
                            <td style="white-space:nowrap;"><?= $date_fmt ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <svg width="32" height="32" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 8px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    No requests found for the selected filters.
                </div>
            <?php endif; ?>
        </div>

    </div><!-- .content -->
</div><!-- .main -->
</div><!-- .app -->

<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7280';

// ── STATUS PIE ────────────────────────────────────────────────────────────
<?php if (!empty($status_data)): ?>
new Chart(document.getElementById('statusPie'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($status_data)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($status_data)) ?>,
            backgroundColor: <?= json_encode(array_values(array_map(fn($k) => [
                'Pending Review'=>'#94a3b8','Under Review'=>'#3b82f6',
                'Approved'=>'#8b5cf6','Posted'=>'#002366','Rejected'=>'#ef4444'
            ][$k] ?? '#6b7280', array_keys($status_data)))) ?>,
            borderWidth: 2,
            borderColor: 'white',
            hoverOffset: 4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'white', titleColor: '#111827', bodyColor: '#6b7280',
                borderColor: '#e5e7eb', borderWidth: 1, padding: 10,
            }
        }
    }
});
<?php endif; ?>

// ── CATEGORY BAR ──────────────────────────────────────────────────────────
<?php if (!empty($cat_labels)): ?>
new Chart(document.getElementById('categoryBar'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($cat_labels) ?>,
        datasets: [{
            data: <?= json_encode($cat_counts) ?>,
            backgroundColor: '#002366',
            borderRadius: 5,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'white', titleColor: '#111827', bodyColor: '#6b7280',
                borderColor: '#e5e7eb', borderWidth: 1, padding: 10,
            }
        },
        scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, border: { display: false }, ticks: { precision: 0 } }
        }
    }
});
<?php endif; ?>

// ── PRIORITY PIE ─────────────────────────────────────────────────────────
<?php if (!empty($prio_data)): ?>
new Chart(document.getElementById('priorityPie'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($prio_data)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($prio_data)) ?>,
            backgroundColor: <?= json_encode(array_values(array_map(fn($k) => [
                'Low'=>'#10b981','Medium'=>'#f59e0b','High'=>'#f97316','Urgent'=>'#ef4444'
            ][$k] ?? '#6b7280', array_keys($prio_data)))) ?>,
            borderWidth: 2,
            borderColor: 'white',
            hoverOffset: 4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'white', titleColor: '#111827', bodyColor: '#6b7280',
                borderColor: '#e5e7eb', borderWidth: 1, padding: 10,
            }
        }
    }
});
<?php endif; ?>

// ── EXPORT PDF ────────────────────────────────────────────────────────────
function exportPDF() {
    window.print();
}
</script>

</body>
</html>
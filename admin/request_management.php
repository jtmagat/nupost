<?php
session_start();
include "../config/database.php";

// Admin only
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
        mysqli_query($conn, "UPDATE requests SET status='$new_status' WHERE id=$req_id");
    }
    // Redirect to avoid resubmit on refresh
    $qs = http_build_query(array_diff_key($_GET, ['page'=>1]));
    header("Location: request_management.php" . ($qs ? "?$qs" : ""));
    exit();
}

// ===== Pagination & Search =====
$limit  = 10;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search       = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$priorityFilter = isset($_GET['priority']) ? mysqli_real_escape_string($conn, $_GET['priority']) : '';

$where = "1";
if ($search !== '') {
    $where .= " AND (title LIKE '%$search%' OR requester LIKE '%$search%' OR category LIKE '%$search%')";
}
$valid_statuses = ["Pending Review","Under Review","Approved","Posted","Rejected"];
if ($statusFilter !== '' && in_array($statusFilter, $valid_statuses)) {
    $where .= " AND status='$statusFilter'";
}
$valid_priorities = ["Low","Medium","High","Urgent"];
if ($priorityFilter !== '' && in_array($priorityFilter, $valid_priorities)) {
    $where .= " AND priority='$priorityFilter'";
}

$totalQuery    = mysqli_query($conn, "SELECT COUNT(*) as total FROM requests WHERE $where");
$totalRow      = mysqli_fetch_assoc($totalQuery);
$totalRequests = $totalRow['total'];
$totalPages    = max(1, ceil($totalRequests / $limit));

$result = mysqli_query($conn, "SELECT * FROM requests WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

// Counts for stat cards
$count_pending  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Pending Review'"))["c"];
$count_review   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Under Review'"))["c"];
$count_approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Approved'"))["c"];
$count_posted   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE status='Posted'"))["c"];
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
    --color-sidebar: #001a4d;
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

/* ===== LAYOUT ===== */
.app { display: flex; min-height: 100vh; }

/* ===== SIDEBAR ===== */
.sidebar {
    width: var(--sidebar-width); background: var(--color-primary);
    display: flex; flex-direction: column; flex-shrink: 0;
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 50;
}
.sidebar__logo {
    padding: 20px 20px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sidebar__logo img { height: 32px; width: auto; }
.sidebar__logo-text { font-size: 18px; font-weight: 700; color: white; }
.sidebar__nav { padding: 12px 10px; flex: 1; }
.sidebar__item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px; margin-bottom: 2px;
    color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;
    text-decoration: none; cursor: pointer; transition: background .15s, color .15s;
}
.sidebar__item:hover { background: rgba(255,255,255,0.1); color: white; }
.sidebar__item--active { background: rgba(255,255,255,0.15); color: white; }
.sidebar__footer {
    padding: 14px 10px;
    border-top: 1px solid rgba(255,255,255,0.1);
}
.sidebar__logout {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px;
    color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__logout:hover { background: rgba(255,255,255,0.1); color: white; }

/* ===== MAIN ===== */
.main { margin-left: var(--sidebar-width); flex: 1; padding: 28px 28px; }

/* ===== TOP BAR ===== */
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.topbar h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.admin-badge {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 12px; background: white; border-radius: 8px;
    border: 1px solid var(--color-border); font-size: 12.5px; font-weight: 500;
}

/* ===== STAT CARDS ===== */
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

/* ===== SEARCH / FILTER BAR ===== */
.filter-bar {
    display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;
}
.filter-bar input[type="text"],
.filter-bar select {
    height: 36px; border: 1px solid var(--color-border); border-radius: 7px;
    padding: 0 12px; font-size: 13px; font-family: var(--font);
    background: white; color: var(--color-text); outline: none;
}
.filter-bar input[type="text"] { flex: 1; min-width: 180px; }
.filter-bar input:focus,
.filter-bar select:focus { border-color: var(--color-primary); }
.filter-btn {
    height: 36px; padding: 0 16px; background: var(--color-primary); color: white;
    border: none; border-radius: 7px; font-size: 13px; font-weight: 500;
    cursor: pointer; font-family: var(--font);
}
.filter-btn:hover { background: var(--color-primary-light); }
.reset-btn {
    height: 36px; padding: 0 14px; background: white; color: var(--color-text-muted);
    border: 1px solid var(--color-border); border-radius: 7px; font-size: 13px;
    cursor: pointer; font-family: var(--font); text-decoration: none;
    display: flex; align-items: center;
}
.reset-btn:hover { background: var(--color-bg); }

/* ===== TABLE CARD ===== */
.table-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.table-card-header {
    padding: 14px 20px; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: space-between;
}
.table-card-header span { font-size: 12.5px; color: var(--color-text-muted); }
.table-card-header strong { color: var(--color-text); }

table { width: 100%; border-collapse: collapse; }
thead tr { border-bottom: 1px solid var(--color-border); }
th {
    padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600;
    color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #fafafa; }
td { padding: 12px 14px; font-size: 12.5px; vertical-align: middle; }

/* REQUEST CELL */
.req-title { font-weight: 600; color: var(--color-text); margin-bottom: 2px; }
.req-desc  { font-size: 11px; color: var(--color-text-muted); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* THUMBNAIL */
.req-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; }
.req-thumb-placeholder {
    width: 36px; height: 36px; border-radius: 6px;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}

/* BADGES */
.badge {
    display: inline-flex; align-items: center; padding: 3px 9px;
    border-radius: 20px; font-size: 10.5px; font-weight: 600; white-space: nowrap;
}
.badge--high     { background: #fee2e2; color: #dc2626; }
.badge--urgent   { background: #fef3c7; color: #b45309; }
.badge--medium   { background: #fef3c7; color: #d97706; }
.badge--low      { background: #f3f4f6; color: #6b7280; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge--pending       { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }

/* STATUS DROPDOWN IN TABLE */
.status-select {
    padding: 4px 8px; border-radius: 6px; border: 1px solid var(--color-border);
    font-size: 11.5px; font-family: var(--font); background: white;
    color: var(--color-text); cursor: pointer; outline: none;
}
.status-select:focus { border-color: var(--color-primary); }
.update-btn {
    padding: 4px 10px; background: var(--color-primary); color: white;
    border: none; border-radius: 5px; font-size: 11px; font-weight: 600;
    cursor: pointer; font-family: var(--font); margin-left: 4px;
}
.update-btn:hover { background: var(--color-primary-light); }

/* DATE */
.date-text { font-size: 11.5px; color: var(--color-text-muted); white-space: nowrap; }

/* EMPTY STATE */
.empty-state { padding: 48px; text-align: center; color: #9ca3af; font-size: 13px; }

/* ===== PAGINATION ===== */
.pagination { display: flex; align-items: center; gap: 4px; padding: 14px 20px; border-top: 1px solid var(--color-border); flex-wrap: wrap; }
.page-btn {
    min-width: 32px; height: 32px; padding: 0 10px; border-radius: 7px;
    border: 1px solid var(--color-border); background: white; color: var(--color-text-muted);
    font-size: 12.5px; text-decoration: none; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.page-btn:hover { background: var(--color-bg); }
.page-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.page-info { font-size: 12px; color: var(--color-text-muted); margin-left: auto; }
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
        <a href="../auth/logout.php" class="sidebar__logout">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- MAIN CONTENT -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h1>Request Management</h1>
        <div class="topbar-right">
            <div class="admin-badge">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
            </div>
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

    <!-- FILTER BAR -->
    <form method="get" class="filter-bar">
        <input type="text" name="search" placeholder="Search title, requester, category..."
               value="<?= htmlspecialchars($search) ?>">
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (["Pending Review","Under Review","Approved","Posted","Rejected"] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority">
            <option value="">All Priorities</option>
            <?php foreach (["Low","Medium","High","Urgent"] as $p): ?>
                <option value="<?= $p ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="filter-btn">Apply</button>
        <a href="request_management.php" class="reset-btn">Reset</a>
    </form>

    <!-- TABLE CARD -->
    <div class="table-card">
        <div class="table-card-header">
            <span>Showing <strong><?= mysqli_num_rows($result) ?></strong> of <strong><?= $totalRequests ?></strong> requests</span>
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
                    <th>ACTION</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($result)):
                    $priority_raw = strtolower($row["priority"]);
                    $priority_class = match($priority_raw) {
                        "urgent" => "badge--urgent", "high" => "badge--high",
                        "medium" => "badge--medium", default => "badge--low",
                    };
                    $status_raw = strtolower($row["status"]);
                    $status_class = match(true) {
                        str_contains($status_raw, "approved")     => "badge--approved",
                        str_contains($status_raw, "posted")       => "badge--posted",
                        str_contains($status_raw, "under review") => "badge--under-review",
                        str_contains($status_raw, "rejected")     => "badge--rejected",
                        default                                   => "badge--pending",
                    };
                    $date = date("M j, Y", strtotime($row["created_at"]));
                    $media_files = explode(",", $row["media_file"] ?? "");
                    $first_media = trim($media_files[0]);
                ?>
                <tr>
                    <td style="color:var(--color-text-muted);font-size:11.5px;">#<?= $row["request_id"] ?></td>
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
                    <td>
                        <!-- Quick status update form -->
                        <form method="POST" style="display:flex;align-items:center;gap:4px;">
                            <input type="hidden" name="req_id" value="<?= $row["id"] ?>">
                            <input type="hidden" name="update_status" value="1">
                            <select class="status-select" name="new_status">
                                <?php foreach (["Pending Review","Under Review","Approved","Posted","Rejected"] as $s): ?>
                                    <option value="<?= $s ?>" <?= $row["status"] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="update-btn">Save</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8"><div class="empty-state">No requests found.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $qs_base = http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter,'priority'=>$priorityFilter]));
            if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&<?= $qs_base ?>" class="page-btn">‹</a>
            <?php endif;
            for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                <a href="?page=<?= $i ?>&<?= $qs_base ?>" class="page-btn <?= $i===$page ? 'page-btn--active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&<?= $qs_base ?>" class="page-btn">›</a>
            <?php endif; ?>
            <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- .main -->
</div><!-- .app -->
</body>
</html>
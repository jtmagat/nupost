<?php
session_start();
require_once "../config/database.php";

// Redirect if not logged in
if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_name = $_SESSION["name"];
$requester = mysqli_real_escape_string($conn, $user_name);

// Filter
$filter = $_GET["filter"] ?? "all";
$allowed_filters = ["all", "pending", "seen", "approved", "posted"];
if (!in_array($filter, $allowed_filters)) $filter = "all";

if ($filter === "all") {
    $query = mysqli_query($conn, "SELECT * FROM requests WHERE requester='$requester' ORDER BY created_at DESC");
} else {
    $status_map = [
        "pending"  => "Pending Review",
        "seen"     => "Under Review",
        "approved" => "Approved",
        "posted"   => "Posted",
    ];
    $status_val = mysqli_real_escape_string($conn, $status_map[$filter]);
    $query = mysqli_query($conn, "SELECT * FROM requests WHERE requester='$requester' AND status='$status_val' ORDER BY created_at DESC");
}

$requests = [];
while ($row = mysqli_fetch_assoc($query)) {
    $requests[] = $row;
}
$total = count($requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – My Requests</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --color-primary: #002366;
    --color-primary-light: #003a8c;
    --color-white: #ffffff;
    --color-bg: #f5f6fa;
    --color-border: #e5e7eb;
    --color-text: #111827;
    --color-text-muted: #6b7280;
    --color-orange: #f97316;
    --font: 'Inter', sans-serif;
    --topbar-height: 56px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --radius: 10px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* ===== TOP NAV ===== */
.topnav {
    position: fixed; top: 0; left: 0; right: 0;
    height: var(--topbar-height);
    background: white; border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; padding: 0 20px; gap: 8px; z-index: 100;
}
.topnav__logo img { height: 32px; width: auto; }
.topnav__logo-text { font-size: 15px; font-weight: 700; color: var(--color-primary); letter-spacing: -0.3px; display:none; }
.topnav__nav { display: flex; align-items: center; gap: 4px; flex: 1; }
.topnav__link {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: background .15s, color .15s; white-space: nowrap;
}
.topnav__link:hover { background: var(--color-bg); color: var(--color-text); }
.topnav__link--active { background: var(--color-primary); color: white; }
.topnav__link--active:hover { background: var(--color-primary-light); color: white; }
.topnav__create {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 16px; background: var(--color-orange);
    color: white; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    text-decoration: none; transition: opacity .15s; white-space: nowrap;
}
.topnav__create:hover { opacity: .9; }
.topnav__search { flex: 1; max-width: 320px; position: relative; margin: 0 8px; }
.topnav__search input {
    width: 100%; height: 36px; border: 1px solid var(--color-border);
    border-radius: 8px; padding: 0 12px 0 36px; font-size: 13px;
    font-family: var(--font); color: var(--color-text); background: var(--color-bg); outline: none;
}
.topnav__search input::placeholder { color: #9ca3af; }
.topnav__search input:focus { border-color: var(--color-primary); background: white; }
.topnav__search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.topnav__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topnav__icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* ===== LAYOUT ===== */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 900px; margin: 0 auto; padding: 32px 24px; }

/* ===== PAGE HEADER ===== */
.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* ===== CARD ===== */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }

/* ===== FILTER BAR ===== */
.filter-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px; border-bottom: 1px solid var(--color-border); gap: 10px; flex-wrap: wrap;
}
.filter-tabs { display: flex; align-items: center; gap: 4px; }
.filter-icon { color: var(--color-text-muted); margin-right: 4px; display: flex; align-items: center; }
.filter-tab {
    padding: 5px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 500;
    color: var(--color-text-muted); text-decoration: none; transition: background .15s, color .15s;
}
.filter-tab:hover { background: var(--color-bg); color: var(--color-text); }
.filter-tab--active { background: var(--color-primary); color: white; }
.filter-tab--active:hover { background: var(--color-primary-light); color: white; }

.view-toggle { display: flex; align-items: center; gap: 4px; }
.view-btn {
    width: 30px; height: 30px; border-radius: 6px; border: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    background: none; color: var(--color-text-muted); transition: background .15s;
}
.view-btn:hover { background: var(--color-bg); }
.view-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

/* ===== COUNT TEXT ===== */
.count-text { padding: 10px 16px 6px; font-size: 11.5px; color: var(--color-text-muted); }
.count-text span { font-weight: 600; color: var(--color-text); }

/* ===== TABLE ===== */
.requests-table { width: 100%; border-collapse: collapse; }
.requests-table thead tr { border-bottom: 1px solid var(--color-border); }
.requests-table th {
    padding: 8px 12px; text-align: left; font-size: 10.5px;
    font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
.requests-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.requests-table tbody tr:last-child { border-bottom: none; }
.requests-table tbody tr:hover { background: #fafafa; }
.requests-table td { padding: 12px 12px; vertical-align: middle; font-size: 12.5px; }

/* REQUEST COLUMN */
.req-col { display: flex; align-items: center; gap: 10px; }
.req-thumb { width: 48px; height: 36px; border-radius: 6px; object-fit: cover; flex-shrink: 0; }
.req-thumb-placeholder {
    width: 48px; height: 36px; border-radius: 6px; flex-shrink: 0;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 14px;
}
.req-title { font-size: 12.5px; font-weight: 600; color: var(--color-text); line-height: 1.3; }
.req-desc {
    font-size: 11px; color: var(--color-text-muted); margin-top: 2px; line-height: 1.4;
    max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* PRIORITY BADGES */
.badge {
    display: inline-flex; align-items: center; padding: 3px 9px;
    border-radius: 20px; font-size: 10.5px; font-weight: 600; white-space: nowrap;
}
.badge--high   { background: #fee2e2; color: #dc2626; }
.badge--urgent { background: #fef3c7; color: #b45309; }
.badge--medium { background: #fef3c7; color: #d97706; }
.badge--low    { background: #f3f4f6; color: #6b7280; }

/* STATUS BADGES */
.status-badge {
    display: inline-flex; align-items: center; padding: 4px 10px;
    border-radius: 20px; font-size: 10.5px; font-weight: 500; white-space: nowrap;
}
.status-badge--approved     { background: #dcfce7; color: #16a34a; }
.status-badge--posted       { background: #dbeafe; color: #2563eb; }
.status-badge--under-review { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.status-badge--pending      { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.status-badge--rejected     { background: #fee2e2; color: #dc2626; }

/* DATE */
.date-text { font-size: 11.5px; color: var(--color-text-muted); white-space: nowrap; }

/* EMPTY STATE */
.empty-state { padding: 50px 20px; text-align: center; color: #9ca3af; font-size: 13px; }

@media (max-width: 768px) {
    .topnav__search { display: none; }
    .requests-table th:nth-child(2),
    .requests-table td:nth-child(2) { display: none; }
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
        <a href="requests.php" class="topnav__link topnav__link--active">
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
        <h1>My Requests</h1>
        <p>View and manage all your post requests</p>
    </div>

    <div class="card">

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <div class="filter-tabs">
                <span class="filter-icon">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                </span>
                <a href="?filter=all"      class="filter-tab <?= $filter === 'all'      ? 'filter-tab--active' : '' ?>">All</a>
                <a href="?filter=pending"  class="filter-tab <?= $filter === 'pending'  ? 'filter-tab--active' : '' ?>">Pending</a>
                <a href="?filter=seen"     class="filter-tab <?= $filter === 'seen'     ? 'filter-tab--active' : '' ?>">Seen</a>
                <a href="?filter=approved" class="filter-tab <?= $filter === 'approved' ? 'filter-tab--active' : '' ?>">Approved</a>
                <a href="?filter=posted"   class="filter-tab <?= $filter === 'posted'   ? 'filter-tab--active' : '' ?>">Posted</a>
            </div>

            <div class="view-toggle">
                <button class="view-btn view-btn--active" title="List view">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button class="view-btn" title="Grid view">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                </button>
            </div>
        </div>

        <!-- COUNT -->
        <div class="count-text">
            Showing <span><?= $total ?></span> request<?= $total !== 1 ? 's' : '' ?>
        </div>

        <!-- TABLE -->
        <?php if ($total > 0): ?>
        <table class="requests-table">
            <thead>
                <tr>
                    <th>REQUEST</th>
                    <th>CATEGORY</th>
                    <th>PRIORITY</th>
                    <th>STATUS</th>
                    <th>SUBMITTED</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
            <?php
                $status_raw = strtolower($req["status"]);
                $status_class = match(true) {
                    str_contains($status_raw, "approved")     => "approved",
                    str_contains($status_raw, "posted")       => "posted",
                    str_contains($status_raw, "under review") => "under-review",
                    str_contains($status_raw, "rejected")     => "rejected",
                    default                                   => "pending",
                };

                $priority_raw = strtolower($req["priority"] ?? "");
                $priority_class = match($priority_raw) {
                    "urgent" => "urgent",
                    "high"   => "high",
                    "medium" => "medium",
                    "low"    => "low",
                    default  => "low",
                };

                $submitted = !empty($req["created_at"]) ? date("n/j/Y", strtotime($req["created_at"])) : "—";
            ?>
                <tr>
                    <!-- REQUEST -->
                    <td>
                        <div class="req-col">
                            <?php if (!empty($req["media_file"])): ?>
                                <img class="req-thumb" src="../uploads/<?= htmlspecialchars($req["media_file"]) ?>" alt=""
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="req-thumb-placeholder" style="display:none;">📄</div>
                            <?php else: ?>
                                <div class="req-thumb-placeholder">📄</div>
                            <?php endif; ?>
                            <div>
                                <div class="req-title"><?= htmlspecialchars($req["title"]) ?></div>
                                <div class="req-desc"><?= htmlspecialchars($req["description"] ?? "") ?></div>
                            </div>
                        </div>
                    </td>

                    <!-- CATEGORY -->
                    <td><?= htmlspecialchars($req["category"] ?? "—") ?></td>

                    <!-- PRIORITY -->
                    <td>
                        <span class="badge badge--<?= $priority_class ?>">
                            <?= strtoupper(htmlspecialchars($req["priority"] ?? "—")) ?>
                        </span>
                    </td>

                    <!-- STATUS -->
                    <td>
                        <span class="status-badge status-badge--<?= $status_class ?>">
                            <?= htmlspecialchars($req["status"]) ?>
                        </span>
                    </td>

                    <!-- SUBMITTED DATE -->
                    <td class="date-text"><?= $submitted ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-state">No requests found.</div>
        <?php endif; ?>

    </div>

</main>
</div>

</body>
</html>
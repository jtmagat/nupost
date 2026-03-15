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

// Search
$search     = trim($_GET["search"] ?? "");
$esc_search = mysqli_real_escape_string($conn, $search);

// Filter
$filter          = $_GET["filter"] ?? "all";
$allowed_filters = ["all", "pending", "seen", "approved", "posted"];
if (!in_array($filter, $allowed_filters)) $filter = "all";

$status_map = [
    "pending"  => "Pending Review",
    "seen"     => "Under Review",
    "approved" => "Approved",
    "posted"   => "Posted",
];

$where = "requester='$requester'";
if ($filter !== "all") {
    $status_val = mysqli_real_escape_string($conn, $status_map[$filter]);
    $where .= " AND status='$status_val'";
}
if ($search !== "") {
    $where .= " AND (title LIKE '%$esc_search%' OR description LIKE '%$esc_search%' OR category LIKE '%$esc_search%')";
}

$query    = mysqli_query($conn, "SELECT * FROM requests WHERE $where ORDER BY created_at DESC");
$requests = [];
while ($row = mysqli_fetch_assoc($query)) { $requests[] = $row; }
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
    padding: 0 36px 0 36px; font-size: 13px; font-family: var(--font); background: var(--color-bg); outline: none; transition: border-color .15s;
}
.topnav__search input::placeholder { color: #9ca3af; }
.topnav__search input:focus { border-color: var(--color-primary); background: white; }
.topnav__search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.topnav__search-clear {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #9ca3af; padding: 2px;
    display: flex; align-items: center; border-radius: 50%; transition: color .15s;
}
.topnav__search-clear:hover { color: var(--color-text); }
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

/* LAYOUT */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* SEARCH BANNER */
.search-banner {
    display: flex; align-items: center; justify-content: space-between;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;
    padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #1e40af;
}
.search-banner strong { font-weight: 600; }
.search-banner a { color: #2563eb; text-decoration: none; font-size: 12px; font-weight: 500; display: flex; align-items: center; gap: 4px; }
.search-banner a:hover { text-decoration: underline; }

.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }

/* FILTER BAR */
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
.filter-tab--active:hover { background: var(--color-primary-light); }

/* VIEW TOGGLE */
.view-toggle { display: flex; align-items: center; gap: 4px; }
.view-btn {
    width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    background: none; color: var(--color-text-muted); transition: all .15s; font-family: var(--font);
}
.view-btn:hover { background: var(--color-bg); }
.view-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

.count-text { padding: 10px 16px 6px; font-size: 11.5px; color: var(--color-text-muted); }
.count-text span { font-weight: 600; color: var(--color-text); }

/* ===== LIST VIEW ===== */
.requests-table { width: 100%; border-collapse: collapse; }
.requests-table thead tr { border-bottom: 1px solid var(--color-border); }
.requests-table th {
    padding: 8px 12px; text-align: left; font-size: 10.5px;
    font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
.requests-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.requests-table tbody tr:last-child { border-bottom: none; }
.requests-table tbody tr:hover { background: #fafafa; }
.requests-table td { padding: 12px; vertical-align: middle; font-size: 12.5px; }
.req-col { display: flex; align-items: center; gap: 10px; }
.req-thumb { width: 48px; height: 36px; border-radius: 6px; object-fit: cover; flex-shrink: 0; }
.req-thumb-placeholder {
    width: 48px; height: 36px; border-radius: 6px; flex-shrink: 0;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 14px;
}
.req-title { font-size: 12.5px; font-weight: 600; color: var(--color-text); line-height: 1.3; }
.req-desc { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ===== GRID VIEW ===== */
.requests-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; padding: 16px;
}
.grid-card {
    background: white; border: 1px solid var(--color-border); border-radius: 10px;
    overflow: hidden; transition: box-shadow .15s, transform .15s; cursor: default;
}
.grid-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
.grid-card__thumb {
    width: 100%; height: 140px; object-fit: cover; display: block; background: #f3f4f6;
}
.grid-card__thumb-placeholder {
    width: 100%; height: 140px; background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; font-size: 32px; color: #9ca3af;
}
.grid-card__body { padding: 12px 14px; }
.grid-card__title { font-size: 13px; font-weight: 600; color: var(--color-text); margin-bottom: 6px; line-height: 1.4; }
.grid-card__desc { font-size: 11.5px; color: var(--color-text-muted); line-height: 1.5; margin-bottom: 10px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.grid-card__footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
.grid-card__date { font-size: 10.5px; color: #9ca3af; }

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
.badge--high   { background: #fee2e2; color: #dc2626; }
.badge--urgent { background: #fef3c7; color: #b45309; }
.badge--medium { background: #fef3c7; color: #d97706; }
.badge--low    { background: #f3f4f6; color: #6b7280; }
.status-badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 10.5px; font-weight: 500; white-space: nowrap; }
.status-badge--approved     { background: #dcfce7; color: #16a34a; }
.status-badge--posted       { background: #dbeafe; color: #2563eb; }
.status-badge--under-review { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.status-badge--pending      { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.status-badge--rejected     { background: #fee2e2; color: #dc2626; }
.date-text { font-size: 11.5px; color: var(--color-text-muted); white-space: nowrap; }

/* HIGHLIGHT */
.highlight { background: #fef08a; border-radius: 2px; padding: 0 2px; font-weight: 600; color: #713f12; }

/* EMPTY */
.empty-state { padding: 50px 20px; text-align: center; color: #9ca3af; font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.empty-state svg { color: #d1d5db; }

@media (max-width: 768px) {
    .topnav__search { display: flex; }
    .requests-table th:nth-child(2), .requests-table td:nth-child(2) { display: none; }
    .requests-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .requests-grid { grid-template-columns: 1fr; }
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
        <form method="GET" action="requests.php" id="search-form">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <?php endif; ?>
            <span class="topnav__search-icon">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="text" name="search" id="search-input"
                   placeholder="Search requests..."
                   value="<?= htmlspecialchars($search) ?>"
                   autocomplete="off">
            <?php if ($search !== ''): ?>
                <button type="button" class="topnav__search-clear" onclick="clearSearch()">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            <?php endif; ?>
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

    <div class="page-header">
        <h1>My Requests</h1>
        <p>View and manage all your post requests</p>
    </div>

    <?php if ($search !== ''): ?>
    <div class="search-banner">
        <span>Showing results for <strong>"<?= htmlspecialchars($search) ?>"</strong> — <?= $total ?> result<?= $total !== 1 ? 's' : '' ?> found</span>
        <a href="requests.php?filter=<?= $filter ?>">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Clear search
        </a>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="filter-bar">
            <div class="filter-tabs">
                <span class="filter-icon">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                </span>
                <a href="?filter=all<?= $search ? '&search='.urlencode($search) : '' ?>"      class="filter-tab <?= $filter==='all'      ? 'filter-tab--active':'' ?>">All</a>
                <a href="?filter=pending<?= $search ? '&search='.urlencode($search) : '' ?>"  class="filter-tab <?= $filter==='pending'  ? 'filter-tab--active':'' ?>">Pending</a>
                <a href="?filter=seen<?= $search ? '&search='.urlencode($search) : '' ?>"     class="filter-tab <?= $filter==='seen'     ? 'filter-tab--active':'' ?>">Seen</a>
                <a href="?filter=approved<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-tab <?= $filter==='approved' ? 'filter-tab--active':'' ?>">Approved</a>
                <a href="?filter=posted<?= $search ? '&search='.urlencode($search) : '' ?>"   class="filter-tab <?= $filter==='posted'   ? 'filter-tab--active':'' ?>">Posted</a>
            </div>

            <!-- VIEW TOGGLE — list / grid -->
            <div class="view-toggle">
                <button class="view-btn view-btn--active" id="btn-list" onclick="setView('list')" title="List view">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button class="view-btn" id="btn-grid" onclick="setView('grid')" title="Grid view">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                </button>
            </div>
        </div>

        <div class="count-text">
            <?php if ($search !== ''): ?>
                Found <span><?= $total ?></span> result<?= $total !== 1 ? 's' : '' ?> for "<?= htmlspecialchars($search) ?>"
            <?php else: ?>
                Showing <span><?= $total ?></span> request<?= $total !== 1 ? 's' : '' ?>
            <?php endif; ?>
        </div>

        <?php if ($total > 0): ?>

        <!-- ===== LIST VIEW ===== -->
        <div id="view-list">
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
                <?php foreach ($requests as $req):
                    $status_raw   = strtolower($req["status"]);
                    $status_class = match(true) {
                        str_contains($status_raw, "approved")     => "approved",
                        str_contains($status_raw, "posted")       => "posted",
                        str_contains($status_raw, "under review") => "under-review",
                        str_contains($status_raw, "rejected")     => "rejected",
                        default                                   => "pending",
                    };
                    $priority_raw   = strtolower($req["priority"] ?? "");
                    $priority_class = match($priority_raw) { "urgent"=>"urgent","high"=>"high","medium"=>"medium",default=>"low" };
                    $submitted      = !empty($req["created_at"]) ? date("n/j/Y", strtotime($req["created_at"])) : "—";
                    $media_files    = explode(",", $req["media_file"] ?? "");
                    $thumb          = trim($media_files[0]);
                    $title_display  = htmlspecialchars($req["title"]);
                    $desc_display   = htmlspecialchars(mb_strimwidth($req["description"] ?? "", 0, 100, "…"));
                    if ($search !== '') {
                        $hl      = '<span class="highlight">$0</span>';
                        $pattern = '/' . preg_quote(htmlspecialchars($search), '/') . '/i';
                        $title_display = preg_replace($pattern, $hl, $title_display);
                        $desc_display  = preg_replace($pattern, $hl, $desc_display);
                    }
                ?>
                    <tr>
                        <td>
                            <div class="req-col">
                                <?php if (!empty($thumb)): ?>
                                    <img class="req-thumb" src="../uploads/<?= htmlspecialchars($thumb) ?>" alt=""
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="req-thumb-placeholder" style="display:none;">📄</div>
                                <?php else: ?>
                                    <div class="req-thumb-placeholder">📄</div>
                                <?php endif; ?>
                                <div>
                                    <div class="req-title"><?= $title_display ?></div>
                                    <div class="req-desc"><?= $desc_display ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($req["category"] ?? "—") ?></td>
                        <td><span class="badge badge--<?= $priority_class ?>"><?= strtoupper(htmlspecialchars($req["priority"] ?? "—")) ?></span></td>
                        <td><span class="status-badge status-badge--<?= $status_class ?>"><?= htmlspecialchars($req["status"]) ?></span></td>
                        <td class="date-text"><?= $submitted ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ===== GRID VIEW ===== -->
        <div id="view-grid" style="display:none;">
            <div class="requests-grid">
            <?php foreach ($requests as $req):
                $status_raw   = strtolower($req["status"]);
                $status_class = match(true) {
                    str_contains($status_raw, "approved")     => "approved",
                    str_contains($status_raw, "posted")       => "posted",
                    str_contains($status_raw, "under review") => "under-review",
                    str_contains($status_raw, "rejected")     => "rejected",
                    default                                   => "pending",
                };
                $priority_raw   = strtolower($req["priority"] ?? "");
                $priority_class = match($priority_raw) { "urgent"=>"urgent","high"=>"high","medium"=>"medium",default=>"low" };
                $submitted      = !empty($req["created_at"]) ? date("M j, Y", strtotime($req["created_at"])) : "—";
                $media_files    = explode(",", $req["media_file"] ?? "");
                $thumb          = trim($media_files[0]);
            ?>
                <div class="grid-card">
                    <?php if (!empty($thumb)): ?>
                        <img class="grid-card__thumb" src="../uploads/<?= htmlspecialchars($thumb) ?>" alt=""
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="grid-card__thumb-placeholder" style="display:none;">📄</div>
                    <?php else: ?>
                        <div class="grid-card__thumb-placeholder">📄</div>
                    <?php endif; ?>
                    <div class="grid-card__body">
                        <div class="grid-card__title"><?= htmlspecialchars($req["title"]) ?></div>
                        <div class="grid-card__desc"><?= htmlspecialchars($req["description"] ?? "") ?></div>
                        <div class="grid-card__footer">
                            <span class="status-badge status-badge--<?= $status_class ?>"><?= htmlspecialchars($req["status"]) ?></span>
                            <span class="badge badge--<?= $priority_class ?>"><?= strtoupper(htmlspecialchars($req["priority"] ?? "")) ?></span>
                        </div>
                        <div class="grid-card__date" style="margin-top:8px;"><?= $submitted ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <?php else: ?>
            <div class="empty-state">
                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?php if ($search !== ''): ?>
                    <p>No results found for "<strong><?= htmlspecialchars($search) ?></strong>"</p>
                    <a href="requests.php" style="color:var(--color-primary);font-size:12px;">Clear search</a>
                <?php else: ?>
                    <p>No requests found.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</main>
</div>

<script>
// ── VIEW TOGGLE ──────────────────────────────────────────────────────────
const STORAGE_KEY = 'nupost_view';

function setView(v) {
    const listEl  = document.getElementById('view-list');
    const gridEl  = document.getElementById('view-grid');
    const btnList = document.getElementById('btn-list');
    const btnGrid = document.getElementById('btn-grid');

    if (v === 'grid') {
        listEl.style.display  = 'none';
        gridEl.style.display  = 'block';
        btnList.classList.remove('view-btn--active');
        btnGrid.classList.add('view-btn--active');
    } else {
        listEl.style.display  = 'block';
        gridEl.style.display  = 'none';
        btnGrid.classList.remove('view-btn--active');
        btnList.classList.add('view-btn--active');
    }
    localStorage.setItem(STORAGE_KEY, v);
}

// Restore last view on page load
const savedView = localStorage.getItem(STORAGE_KEY) || 'list';
setView(savedView);

// ── SEARCH ───────────────────────────────────────────────────────────────
const searchInput = document.getElementById('search-input');
let searchTimer;
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        document.getElementById('search-form').submit();
    }, 500);
});

function clearSearch() {
    searchInput.value = '';
    document.getElementById('search-form').submit();
}
</script>

</body>
</html>
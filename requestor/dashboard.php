<?php
session_start();
require_once "../config/database.php";

// Redirect if not logged in
if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];
$requester = mysqli_real_escape_string($conn, $user_name);

// Stat counts
$pending_q  = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Pending Review'");
$pending    = mysqli_fetch_assoc($pending_q)["c"];

$review_q   = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Under Review'");
$review     = mysqli_fetch_assoc($review_q)["c"];

$approved_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Approved'");
$approved   = mysqli_fetch_assoc($approved_q)["c"];

$posted_q   = mysqli_query($conn, "SELECT COUNT(*) as c FROM requests WHERE requester='$requester' AND status='Posted'");
$posted     = mysqli_fetch_assoc($posted_q)["c"];

// Recent requests (latest 5)
$recent_q = mysqli_query($conn, "SELECT * FROM requests WHERE requester='$requester' ORDER BY created_at DESC LIMIT 5");
$recent = [];
while ($row = mysqli_fetch_assoc($recent_q)) {
    $recent[] = $row;
}

// Unread notifications count
$unread_q     = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_count = mysqli_fetch_assoc($unread_q)["c"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Dashboard</title>

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
    --color-yellow: #f59e0b;
    --color-blue: #3b82f6;
    --color-green: #10b981;
    --color-purple: #8b5cf6;
    --color-orange: #f97316;
    --color-red: #ef4444;
    --font: 'Inter', sans-serif;
    --nav-width: 0px;
    --topbar-height: 56px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.04);
    --radius: 10px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* TOP NAV */
.topnav {
    position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-height);
    background: var(--color-white); border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; padding: 0 20px; gap: 8px; z-index: 100;
}
.topnav__logo { display: flex; align-items: center; gap: 6px; margin-right: 8px; }
.topnav__logo img { height: 32px; width: auto; }
.topnav__logo-text { font-size: 15px; font-weight: 700; color: var(--color-primary); letter-spacing: -0.3px; }
.topnav__nav { display: flex; align-items: center; gap: 4px; flex: 1; }
.topnav__link {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: background 0.15s, color 0.15s; white-space: nowrap;
}
.topnav__link:hover { background: var(--color-bg); color: var(--color-text); }
.topnav__link--active { background: var(--color-primary); color: var(--color-white); }
.topnav__link--active:hover { background: var(--color-primary-light); color: var(--color-white); }
.topnav__create {
    display: flex; align-items: center; gap: 6px; padding: 7px 16px;
    background: var(--color-orange); color: white; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
    transition: opacity 0.15s; white-space: nowrap;
}
.topnav__create:hover { opacity: 0.9; }
.topnav__search { flex: 1; max-width: 320px; position: relative; margin: 0 8px; }
.topnav__search form { display: flex; }
.topnav__search input {
    width: 100%; height: 36px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px 0 36px; font-size: 13px; font-family: var(--font);
    color: var(--color-text); background: var(--color-bg); outline: none; transition: border-color 0.15s;
}
.topnav__search input::placeholder { color: #9ca3af; }
.topnav__search input:focus { border-color: var(--color-primary); background: white; }
.topnav__search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.topnav__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topnav__icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer; color: var(--color-text-muted);
    transition: background 0.15s; text-decoration: none;
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

/* PAGE HEADER */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 22px; font-weight: 700; color: var(--color-text); letter-spacing: -0.3px; }
.page-header p { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* STAT CARDS */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
.stat-card {
    background: white; border-radius: var(--radius); padding: 20px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: var(--shadow-sm); border: 1.5px solid transparent; transition: box-shadow 0.15s;
}
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-card--yellow { border-color: #fde68a; }
.stat-card--blue   { border-color: #bfdbfe; }
.stat-card--green  { border-color: #a7f3d0; }
.stat-card--purple { border-color: #ddd6fe; }
.stat-card__label { font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 6px; }
.stat-card__value { font-size: 28px; font-weight: 700; color: var(--color-text); line-height: 1; }
.stat-card__icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-card__icon--yellow { background: #fef3c7; color: var(--color-yellow); }
.stat-card__icon--blue   { background: #eff6ff; color: var(--color-blue); }
.stat-card__icon--green  { background: #ecfdf5; color: var(--color-green); }
.stat-card__icon--purple { background: #f5f3ff; color: var(--color-purple); }

/* CARD */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.card__header { padding: 18px 20px 14px; border-bottom: 1px solid var(--color-border); }
.card__title { font-size: 15px; font-weight: 600; color: var(--color-text); }

/* REQUEST LIST */
.request-list { list-style: none; }
.request-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; border-bottom: 1px solid #f3f4f6; transition: background 0.1s;
}
.request-item:last-child { border-bottom: none; }
.request-item:hover { background: #fafafa; }
.request-item__info { flex: 1; min-width: 0; }
.request-item__title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.request-item__title { font-size: 13.5px; font-weight: 600; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.request-item__desc { font-size: 12px; color: var(--color-text-muted); margin-bottom: 8px; line-height: 1.5; }
.request-item__meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.request-item__thumb { width: 68px; height: 68px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: #e5e7eb; }
.request-item__thumb-placeholder {
    width: 68px; height: 68px; border-radius: 8px; flex-shrink: 0;
    background: linear-gradient(135deg, #e5e7eb, #d1d5db);
    display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 20px;
}

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; white-space: nowrap; }
.badge--approved     { background: #dcfce7; color: #16a34a; }
.badge--posted       { background: #dbeafe; color: #2563eb; }
.badge--under-review { background: #fef3c7; color: #d97706; }
.badge--pending      { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected     { background: #fee2e2; color: #dc2626; }
.badge--high         { background: #fee2e2; color: #dc2626; }
.badge--urgent       { background: #fef3c7; color: #b45309; }
.badge--medium       { background: #fef3c7; color: #d97706; }
.badge--low          { background: #f3f4f6; color: #6b7280; }
.tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 400; background: #f3f4f6; color: #374151; white-space: nowrap; }

/* RESPONSIVE */
@media (max-width: 768px) {
    .stats { grid-template-columns: repeat(2, 1fr); }
    .topnav__search { display: none; }
}
@media (max-width: 480px) {
    .stats { grid-template-columns: 1fr 1fr; gap: 10px; }
    .main { padding: 20px 14px; }
}
</style>
</head>
<body>

<nav class="topnav">
    <div class="topnav__logo">
        <img src="../auth/assets/nupostlogo.png" alt="NUPost"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <span class="topnav__logo-text" style="display:none;">NUPost</span>
    </div>

    <div class="topnav__nav">
        <a href="dashboard.php" class="topnav__link topnav__link--active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            Dashboard
        </a>
        <a href="requests.php" class="topnav__link">
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
            <span class="topnav__search-icon">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="text" name="search" id="search-input" placeholder="Search requests..." autocomplete="off">
        </form>
    </div>

    <div class="topnav__actions">
        <!-- NOTIFICATIONS BELL — live unread count -->
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
        <h1>Dashboard</h1>
        <p>Overview of your post requests and performance</p>
    </div>

    <!-- STAT CARDS — live from DB -->
    <div class="stats">
        <div class="stat-card stat-card--yellow">
            <div class="stat-card__left">
                <div class="stat-card__label">Pending Review</div>
                <div class="stat-card__value"><?= $pending ?></div>
            </div>
            <div class="stat-card__icon stat-card__icon--yellow">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
        </div>

        <div class="stat-card stat-card--blue">
            <div class="stat-card__left">
                <div class="stat-card__label">Under Review</div>
                <div class="stat-card__value"><?= $review ?></div>
            </div>
            <div class="stat-card__icon stat-card__icon--blue">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
        </div>

        <div class="stat-card stat-card--green">
            <div class="stat-card__left">
                <div class="stat-card__label">Approved</div>
                <div class="stat-card__value"><?= $approved ?></div>
            </div>
            <div class="stat-card__icon stat-card__icon--green">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>

        <div class="stat-card stat-card--purple">
            <div class="stat-card__left">
                <div class="stat-card__label">Posted</div>
                <div class="stat-card__value"><?= $posted ?></div>
            </div>
            <div class="stat-card__icon stat-card__icon--purple">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </div>
        </div>
    </div>

    <!-- RECENT REQUESTS — live from DB -->
    <div class="card">
        <div class="card__header">
            <div class="card__title">Recent Requests</div>
        </div>

        <ul class="request-list">
            <?php if (empty($recent)): ?>
                <li style="padding:40px 20px; text-align:center; color:#9ca3af; font-size:13px;">
                    No requests yet. <a href="create_request.php" style="color:var(--color-primary);font-weight:600;">Create one →</a>
                </li>
            <?php else: ?>
                <?php foreach ($recent as $req):
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
                        "urgent" => "urgent", "high" => "high", "medium" => "medium", default => "low",
                    };
                    // First file only for thumbnail
                    $media_files = explode(",", $req["media_file"] ?? "");
                    $thumb = trim($media_files[0]);
                ?>
                <li class="request-item">
                    <div class="request-item__info">
                        <div class="request-item__title-row">
                            <span class="request-item__title"><?= htmlspecialchars($req["title"]) ?></span>
                            <span class="badge badge--<?= $status_class ?>"><?= htmlspecialchars($req["status"]) ?></span>
                        </div>
                        <p class="request-item__desc"><?= htmlspecialchars(mb_strimwidth($req["description"] ?? "", 0, 120, "…")) ?></p>
                        <div class="request-item__meta">
                            <span class="tag"><?= htmlspecialchars($req["category"] ?? "") ?></span>
                            <span class="badge badge--<?= $priority_class ?>"><?= strtoupper(htmlspecialchars($req["priority"] ?? "")) ?></span>
                            <?php if (!empty($req["platform"])): ?>
                                <span class="tag"><?= htmlspecialchars($req["platform"]) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($thumb)): ?>
                        <img class="request-item__thumb" src="../uploads/<?= htmlspecialchars($thumb) ?>" alt=""
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="request-item__thumb-placeholder" style="display:none;">📄</div>
                    <?php else: ?>
                        <div class="request-item__thumb-placeholder">📄</div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

</main>
</div>

<script>
// Auto-submit search after 500ms pause
const searchInput = document.getElementById('search-input');
let searchTimer;
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        document.getElementById('search-form').submit();
    }, 500);
});
</script>

</body>
</html>
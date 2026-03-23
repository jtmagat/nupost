<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// Mark all as read when page is opened
mysqli_query($conn, "UPDATE notifications SET is_read=1 WHERE user_id='$user_id'");

// Fetch all notifications newest first
$result = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id='$user_id' ORDER BY created_at DESC");
$notifications = [];
while ($row = mysqli_fetch_assoc($result)) {
    $notifications[] = $row;
}
$total = count($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Notifications</title>
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
.topnav__create {
    display: flex; align-items: center; gap: 6px; padding: 7px 16px;
    background: var(--color-orange); color: white; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap;
}
.topnav__create:hover { opacity: .9; }
.topnav__search { flex: 1; max-width: 320px; position: relative; margin: 0 8px; }
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
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); text-decoration: none;
    transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__icon-btn--active { background: var(--color-primary); color: white; }
.topnav__icon-btn--active:hover { background: var(--color-primary-light); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* ===== LAYOUT ===== */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 680px; margin: 0 auto; padding: 32px 24px; }

/* ===== PAGE HEADER ===== */
.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* ===== CARD ===== */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }

/* ===== NOTIFICATION ITEMS ===== */
.notif-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; border-bottom: 1px solid #f3f4f6;
    transition: background .1s; position: relative;
    cursor: pointer;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: #fafafa; }
.notif-item--unread { background: #f0f5ff; }
.notif-item--unread:hover { background: #e8f0fe; }

.notif-dot {
    position: absolute; top: 18px; right: 16px;
    width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; flex-shrink: 0;
}

/* ICON */
.notif-icon {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px;
}
.notif-icon--approved  { background: #dcfce7; color: #16a34a; }
.notif-icon--posted    { background: #ede9fe; color: #7c3aed; }
.notif-icon--reviewed  { background: #dbeafe; color: #2563eb; }
.notif-icon--rejected  { background: #fee2e2; color: #dc2626; }
.notif-icon--comment   { background: #fef3c7; color: #d97706; }
.notif-icon--default   { background: #f3f4f6; color: #6b7280; }

/* TEXT */
.notif-body { flex: 1; min-width: 0; padding-right: 16px; }
.notif-title { font-size: 13.5px; font-weight: 600; color: var(--color-text); margin-bottom: 3px; }
.notif-message { font-size: 12.5px; color: var(--color-text-muted); line-height: 1.5; margin-bottom: 4px; }
.notif-time { font-size: 11px; color: #9ca3af; }

/* EMPTY STATE */
.empty-state {
    padding: 60px 20px; text-align: center;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.empty-state svg { color: #d1d5db; }
.empty-state p { font-size: 13px; color: #9ca3af; }

/* ===== NOTIFICATION DETAIL MODAL ===== */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 200;
    align-items: center; justify-content: center;
    padding: 20px;
}
.modal-overlay--open { display: flex; }
.modal {
    background: white; border-radius: 14px; max-width: 520px; width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden;
    animation: modalIn .18s ease;
}
@keyframes modalIn { from { opacity:0; transform:scale(.96) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }

.modal-header {
    display: flex; align-items: center; gap: 14px;
    padding: 20px 22px; border-bottom: 1px solid var(--color-border);
}
.modal-icon {
    width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.modal-header-text { flex: 1; }
.modal-title { font-size: 15px; font-weight: 700; color: var(--color-text); margin-bottom: 2px; }
.modal-time { font-size: 11.5px; color: #9ca3af; }
.modal-close {
    width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--color-bg);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--color-text-muted); font-size: 16px; transition: background .15s;
    flex-shrink: 0;
}
.modal-close:hover { background: #e5e7eb; }

.modal-body { padding: 22px; }
.modal-message {
    font-size: 14px; color: var(--color-text); line-height: 1.75;
    margin-bottom: 16px;
}

/* Admin comment highlight box */
.modal-comment-box {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
    padding: 14px 16px; margin-bottom: 16px;
}
.modal-comment-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; color: #92400e;
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px;
}
.modal-comment-text {
    font-size: 13.5px; color: #78350f; line-height: 1.65;
}

.modal-footer {
    padding: 16px 22px; border-top: 1px solid var(--color-border);
    display: flex; gap: 8px; justify-content: flex-end;
}
.btn-modal-primary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); text-decoration: none; transition: background .15s;
}
.btn-modal-primary:hover { background: var(--color-primary-light); }
.btn-modal-secondary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: white; color: var(--color-text-muted);
    border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px;
    cursor: pointer; font-family: var(--font); transition: background .15s;
}
.btn-modal-secondary:hover { background: var(--color-bg); }
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
        <a href="notifications.php" class="topnav__icon-btn topnav__icon-btn--active">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </a>
        <a href="profile.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<!-- MAIN -->
<div class="layout">
<main class="main">

    <div class="page-header">
        <h1>Notifications</h1>
        <p>Stay updated on your request statuses</p>
    </div>

    <div class="card">
        <?php if ($total > 0): ?>
            <?php foreach ($notifications as $notif):
                $type      = strtolower($notif["type"] ?? "default");
                $is_unread = !$notif["is_read"];
                $time      = date("n/j/Y, g:i A", strtotime($notif["created_at"]));

                $icon_class = match(true) {
                    str_contains($type, "approv")  => "notif-icon--approved",
                    str_contains($type, "post")    => "notif-icon--posted",
                    str_contains($type, "review")  => "notif-icon--reviewed",
                    str_contains($type, "reject")  => "notif-icon--rejected",
                    str_contains($type, "comment") => "notif-icon--comment",
                    default                        => "notif-icon--default",
                };

                $icon_svg = match(true) {
                    str_contains($type, "approv")  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                    str_contains($type, "post")    => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
                    str_contains($type, "review")  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
                    str_contains($type, "reject")  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                    str_contains($type, "comment") => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                    default                        => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
                };

                // Extract admin note from message if present
                $raw_message  = $notif["message"];
                $admin_note   = "";
                $clean_message = $raw_message;
                if (strpos($raw_message, " Admin note: ") !== false) {
                    $parts         = explode(" Admin note: ", $raw_message, 2);
                    $clean_message = $parts[0];
                    $admin_note    = $parts[1] ?? "";
                }

                // Data for modal (JSON encoded)
                $modal_data = json_encode([
                    "title"      => $notif["title"],
                    "message"    => $clean_message,
                    "adminNote"  => $admin_note,
                    "time"       => date("F j, Y g:i A", strtotime($notif["created_at"])),
                    "type"       => $type,
                    "iconClass"  => $icon_class,
                    "iconSvg"    => $icon_svg,
                ]);
            ?>
            <div class="notif-item <?= $is_unread ? 'notif-item--unread' : '' ?>"
                 onclick="openNotif(<?= htmlspecialchars($modal_data, ENT_QUOTES) ?>)">
                <div class="notif-icon <?= $icon_class ?>"><?= $icon_svg ?></div>
                <div class="notif-body">
                    <div class="notif-title"><?= htmlspecialchars($notif["title"]) ?></div>
                    <div class="notif-message"><?= htmlspecialchars($clean_message) ?></div>
                    <?php if ($admin_note): ?>
                        <div style="display:inline-flex;align-items:center;gap:4px;margin-top:4px;font-size:11px;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:20px;font-weight:600;">
                            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            Admin comment attached — click to view
                        </div>
                    <?php endif; ?>
                    <div class="notif-time"><?= $time ?></div>
                </div>
                <?php if ($is_unread): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <p>No notifications yet.</p>
            </div>
        <?php endif; ?>
    </div>

</main>
</div>

<!-- NOTIFICATION DETAIL MODAL -->
<div class="modal-overlay" id="notif-modal" onclick="closeNotif(event)">
    <div class="modal" id="modal-box">
        <div class="modal-header">
            <div class="modal-icon" id="modal-icon"></div>
            <div class="modal-header-text">
                <div class="modal-title" id="modal-title"></div>
                <div class="modal-time" id="modal-time"></div>
            </div>
            <button class="modal-close" onclick="closeModalDirect()">&#10005;</button>
        </div>
        <div class="modal-body">
            <div class="modal-message" id="modal-message"></div>
            <div class="modal-comment-box" id="modal-comment-box" style="display:none;">
                <div class="modal-comment-label">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Comment from Admin
                </div>
                <div class="modal-comment-text" id="modal-comment-text"></div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="requests.php" class="btn-modal-primary">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                View My Requests
            </a>
            <button class="btn-modal-secondary" onclick="closeModalDirect()">Close</button>
        </div>
    </div>
</div>

<script>
function openNotif(data) {
    document.getElementById('modal-title').textContent   = data.title;
    document.getElementById('modal-time').textContent    = data.time;
    document.getElementById('modal-message').textContent = data.message;
    document.getElementById('modal-icon').className      = 'modal-icon ' + data.iconClass;
    document.getElementById('modal-icon').innerHTML      = data.iconSvg;

    const commentBox  = document.getElementById('modal-comment-box');
    const commentText = document.getElementById('modal-comment-text');
    if (data.adminNote && data.adminNote.trim() !== '') {
        commentText.textContent = data.adminNote;
        commentBox.style.display = 'block';
    } else {
        commentBox.style.display = 'none';
    }

    document.getElementById('notif-modal').classList.add('modal-overlay--open');
    document.body.style.overflow = 'hidden';
}

function closeNotif(e) {
    if (e.target === document.getElementById('notif-modal')) {
        closeModalDirect();
    }
}

function closeModalDirect() {
    document.getElementById('notif-modal').classList.remove('modal-overlay--open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModalDirect();
});
</script>

</body>
</html>
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "requestor") {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];

$req_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if (!$req_id) {
    header("Location: requests.php");
    exit();
}

// Fetch request — make sure it belongs to this user
$req_q = mysqli_query($conn, "SELECT * FROM requests WHERE id=$req_id AND requester='" . mysqli_real_escape_string($conn, $user_name) . "' LIMIT 1");
$req   = mysqli_fetch_assoc($req_q);
if (!$req) {
    header("Location: requests.php");
    exit();
}

// ── Handle send message ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["send_chat"])) {
    $chat_msg = trim($_POST["chat_message"] ?? "");
    if ($chat_msg !== "") {
        $cm  = mysqli_real_escape_string($conn, $chat_msg);
        $sn  = mysqli_real_escape_string($conn, $user_name);
        $now = date("Y-m-d H:i:s");
        mysqli_query($conn, "INSERT INTO request_comments (request_id, sender_role, sender_name, message, created_at) VALUES ('$req_id','requestor','$sn','$cm','$now')");
        // Note: could notify admin here too if admin user row exists
    }
    header("Location: request_chat.php?id=$req_id");
    exit();
}

// Fetch chat messages
$chat_q = mysqli_query($conn, "SELECT * FROM request_comments WHERE request_id=$req_id ORDER BY created_at ASC");
$chat_messages = [];
while ($c = mysqli_fetch_assoc($chat_q)) $chat_messages[] = $c;

// Unread notifications count for bell
$unread_q   = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_row = mysqli_fetch_assoc($unread_q);
$unread_cnt = (int)($unread_row["cnt"] ?? 0);

$status_class = match(true) {
    str_contains(strtolower($req["status"]), "approved")     => "badge--approved",
    str_contains(strtolower($req["status"]), "posted")       => "badge--posted",
    str_contains(strtolower($req["status"]), "under review") => "badge--under-review",
    str_contains(strtolower($req["status"]), "rejected")     => "badge--rejected",
    default => "badge--pending",
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comments – <?= htmlspecialchars($req["title"]) ?></title>
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

/* TOP NAV */
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
.topnav__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topnav__icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); text-decoration: none;
    transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* LAYOUT */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 700px; margin: 0 auto; padding: 28px 24px; }

/* BACK BTN */
.back-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
    background: white; border: 1px solid var(--color-border); border-radius: 8px;
    font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: background .15s; margin-bottom: 20px;
}
.back-btn:hover { background: var(--color-bg); }

/* REQUEST INFO STRIP */
.req-strip {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 16px 20px; margin-bottom: 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.req-strip-title { font-size: 14.5px; font-weight: 700; color: var(--color-text); }
.req-strip-id    { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge--pending       { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }

/* CHATBOX */
.chat-card {
    background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm);
    overflow: hidden; display: flex; flex-direction: column; height: 560px;
}
.chat-header {
    padding: 14px 18px; background: var(--color-primary); color: white;
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.chat-header-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
.chat-header-title { font-size: 13.5px; font-weight: 600; }
.chat-header-sub { font-size: 11px; opacity: .7; }

.chat-messages {
    flex: 1; overflow-y: auto; padding: 20px 18px;
    display: flex; flex-direction: column; gap: 14px; background: #f8faff;
}
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

.chat-bubble-row { display: flex; align-items: flex-end; gap: 8px; }
.chat-bubble-row--me { flex-direction: row-reverse; }

.chat-avatar {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: white;
}
.chat-avatar--me    { background: #7c3aed; }
.chat-avatar--admin { background: var(--color-primary); }

.chat-bubble {
    max-width: 72%; padding: 10px 14px; border-radius: 14px;
    font-size: 13px; line-height: 1.55; word-break: break-word;
}
.chat-bubble--me {
    background: #7c3aed; color: white; border-bottom-right-radius: 4px;
}
.chat-bubble--admin {
    background: white; color: var(--color-text);
    border: 1px solid var(--color-border); border-bottom-left-radius: 4px;
}
.chat-meta { font-size: 10.5px; margin-top: 4px; opacity: .65; }
.chat-bubble--me .chat-meta    { text-align: right; color: rgba(255,255,255,.8); }
.chat-bubble--admin .chat-meta { color: #9ca3af; }

.chat-empty {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; color: #9ca3af;
}
.chat-empty p { font-size: 13px; }

.chat-input-area {
    border-top: 1px solid var(--color-border); padding: 14px 16px;
    background: white; display: flex; gap: 10px; align-items: flex-end; flex-shrink: 0;
}
.chat-input {
    flex: 1; border: 1px solid var(--color-border); border-radius: 10px;
    padding: 10px 14px; font-size: 13px; font-family: var(--font); resize: none;
    outline: none; transition: border-color .15s; color: var(--color-text);
    min-height: 42px; max-height: 120px; line-height: 1.5; overflow-y: auto;
}
.chat-input:focus { border-color: #7c3aed; }
.chat-send-btn {
    width: 42px; height: 42px; background: #7c3aed; color: white;
    border: none; border-radius: 10px; cursor: pointer; display: flex;
    align-items: center; justify-content: center; flex-shrink: 0; transition: background .15s;
}
.chat-send-btn:hover { background: #6d28d9; }
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
    <div class="topnav__actions">
        <a href="notifications.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php if ($unread_cnt > 0): ?>
                <span class="topnav__badge"><?= $unread_cnt > 9 ? '9+' : $unread_cnt ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<div class="layout">
<main class="main">

    <a href="requests.php" class="back-btn">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Requests
    </a>

    <!-- Request Info Strip -->
    <div class="req-strip">
        <div>
            <div class="req-strip-title"><?= htmlspecialchars($req["title"]) ?></div>
            <div class="req-strip-id">REQ-<?= htmlspecialchars($req["request_id"] ?? $req["id"]) ?></div>
        </div>
        <span class="badge <?= $status_class ?>"><?= htmlspecialchars($req["status"]) ?></span>
    </div>

    <!-- Chatbox -->
    <div class="chat-card">
        <div class="chat-header">
            <div class="chat-header-dot"></div>
            <div>
                <div class="chat-header-title">Comments with Admin</div>
                <div class="chat-header-sub">Messages here are visible to admin</div>
            </div>
        </div>

        <div class="chat-messages" id="chat-messages">
            <?php if (empty($chat_messages)): ?>
                <div class="chat-empty">
                    <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <p>No messages yet. You can ask admin a question here!</p>
                </div>
            <?php else: ?>
                <?php foreach ($chat_messages as $msg):
                    $is_me    = $msg["sender_role"] === "requestor";
                    $initials = strtoupper(substr($msg["sender_name"], 0, 1));
                    $time_str = date("M j, g:i A", strtotime($msg["created_at"]));
                ?>
                <div class="chat-bubble-row <?= $is_me ? 'chat-bubble-row--me' : '' ?>">
                    <div class="chat-avatar <?= $is_me ? 'chat-avatar--me' : 'chat-avatar--admin' ?>">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="chat-bubble <?= $is_me ? 'chat-bubble--me' : 'chat-bubble--admin' ?>">
                        <?= nl2br(htmlspecialchars($msg["message"])) ?>
                        <div class="chat-meta">
                            <?= $is_me ? 'You' : htmlspecialchars($msg["sender_name"]) ?> &middot; <?= $time_str ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" class="chat-input-area" id="chat-form">
            <input type="hidden" name="send_chat" value="1">
            <textarea class="chat-input" name="chat_message" id="chat-input"
                      placeholder="Send a message or question to admin..."
                      rows="1"
                      onkeydown="handleChatKey(event)"></textarea>
            <button type="submit" class="chat-send-btn" title="Send">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </form>
    </div>

</main>
</div>

<script>
// Scroll to bottom of chat on load
document.addEventListener('DOMContentLoaded', () => {
    const chatBox = document.getElementById('chat-messages');
    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
});

function handleChatKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const val = document.getElementById('chat-input').value.trim();
        if (val) document.getElementById('chat-form').submit();
    }
}
</script>

</body>
</html>
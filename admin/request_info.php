<?php
session_start();
include "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$req_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if (!$req_id) {
    header("Location: request_management.php");
    exit();
}

// ── Auto-create request_comments table if not exists ─────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `request_comments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT(11) NOT NULL,
    `sender_role` VARCHAR(20) NOT NULL DEFAULT 'admin',
    `sender_name` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_request_id` (`request_id`)
)");

// Fetch request
$req_q = mysqli_query($conn, "SELECT r.*, u.email as user_email, u.id as user_db_id FROM requests r LEFT JOIN users u ON u.name = r.requester WHERE r.id = $req_id LIMIT 1");
$req   = mysqli_fetch_assoc($req_q);
if (!$req) {
    header("Location: request_management.php");
    exit();
}

// Active tab
$tab = $_GET["tab"] ?? "info";
$allowed_tabs = ["info", "caption", "workflow", "activity", "chat"];
if (!in_array($tab, $allowed_tabs)) $tab = "info";

// ── Handle status update ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {
    $new_status       = mysqli_real_escape_string($conn, $_POST["new_status"]);
    $inline_comment   = trim($_POST["status_comment"] ?? "");
    $allowed_statuses = ["Pending Review","Under Review","Approved","Posted","Rejected"];
    $old_status       = $req["status"];

    if (in_array($new_status, $allowed_statuses)) {
        mysqli_query($conn, "UPDATE requests SET status='$new_status' WHERE id=$req_id");

        // Determine requester user_id
        $uid = (int)($req["user_db_id"] ?? 0);
        if (!$uid) {
            $uq  = mysqli_query($conn, "SELECT id FROM users WHERE name='" . mysqli_real_escape_string($conn, $req["requester"]) . "' LIMIT 1");
            $ur  = mysqli_fetch_assoc($uq);
            $uid = $ur ? (int)$ur["id"] : 0;
        }

        $now = date("Y-m-d H:i:s");
        $t   = $req["title"];

        // Build notification message (append comment if provided)
        $base_msgs = [
            "Pending Review" => "Your request \"$t\" has been reset to Pending Review.",
            "Under Review"   => "Your request \"$t\" is now being reviewed by our team.",
            "Approved"       => "Great news! Your request \"$t\" has been approved and is ready for posting.",
            "Posted"         => "Your request \"$t\" has been successfully published on the platform.",
            "Rejected"       => "Unfortunately, your request \"$t\" was not approved. You may submit a revised one.",
        ];
        $notif_types = [
            "Pending Review" => "review",
            "Under Review"   => "review",
            "Approved"       => "approved",
            "Posted"         => "posted",
            "Rejected"       => "rejected",
        ];
        $notif_titles = [
            "Pending Review" => "Request Status Updated",
            "Under Review"   => "Request Under Review",
            "Approved"       => "Request Approved! 🎉",
            "Posted"         => "Request Posted! 🚀",
            "Rejected"       => "Request Rejected",
        ];

        $notif_msg = $base_msgs[$new_status] ?? "Your request \"$t\" status changed to $new_status.";
        if ($inline_comment !== "") {
            $notif_msg .= " Admin note: " . $inline_comment;
        }

        if ($uid) {
            $nt = mysqli_real_escape_string($conn, $notif_titles[$new_status] ?? "Status Updated");
            $nm = mysqli_real_escape_string($conn, $notif_msg);
            $ny = mysqli_real_escape_string($conn, $notif_types[$new_status] ?? "review");
            // Store comment reference as JSON meta in message so notifications.php can show it
            mysqli_query($conn, "INSERT INTO notifications (user_id,title,message,type,is_read,created_at) VALUES ('$uid','$nt','$nm','$ny',0,'$now')");
        }

        // Save inline comment to chat
        if ($inline_comment !== "") {
            $actor   = mysqli_real_escape_string($conn, $_SESSION["admin_email"] ?? "Admin");
            $cm      = mysqli_real_escape_string($conn, $inline_comment);
            mysqli_query($conn, "INSERT INTO request_comments (request_id, sender_role, sender_name, message, created_at) VALUES ('$req_id','admin','$actor','$cm','$now')");
        }

        // Log activity
        $actor   = mysqli_real_escape_string($conn, $_SESSION["admin_email"] ?? "Admin");
        $log_msg = mysqli_real_escape_string($conn, "Status changed from \"$old_status\" to \"$new_status\"");
        mysqli_query($conn, "INSERT INTO request_activity (request_id, actor, action, created_at) VALUES ('$req_id','$actor','$log_msg','$now')");
    }

    header("Location: request_info.php?id=$req_id&tab=workflow&saved=1");
    exit();
}

// ── Handle caption save ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_caption"])) {
    $caption = mysqli_real_escape_string($conn, trim($_POST["caption"] ?? ""));
    mysqli_query($conn, "UPDATE requests SET caption='$caption' WHERE id=$req_id");
    header("Location: request_info.php?id=$req_id&tab=caption&saved=1");
    exit();
}

// ── Handle internal notes save ────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_notes"])) {
    $notes    = mysqli_real_escape_string($conn, trim($_POST["internal_notes"] ?? ""));
    $sched_dt = mysqli_real_escape_string($conn, trim($_POST["scheduled_datetime"] ?? ""));
    if ($sched_dt !== "") {
        $sched_date = date("Y-m-d", strtotime($sched_dt));
        mysqli_query($conn, "UPDATE requests SET preferred_date='$sched_date' WHERE id=$req_id");
    }
    if ($notes !== "") {
        $actor   = mysqli_real_escape_string($conn, $_SESSION["admin_email"] ?? "Admin");
        $log_msg = mysqli_real_escape_string($conn, "Internal note: $notes");
        $now     = date("Y-m-d H:i:s");
        mysqli_query($conn, "INSERT INTO request_activity (request_id, actor, action, created_at) VALUES ('$req_id','$actor','$log_msg','$now')");
    }
    header("Location: request_info.php?id=$req_id&tab=workflow&saved=1");
    exit();
}

// ── Handle chat message send ──────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["send_chat"])) {
    $chat_msg = trim($_POST["chat_message"] ?? "");
    if ($chat_msg !== "") {
        $actor = mysqli_real_escape_string($conn, $_SESSION["admin_email"] ?? "Admin");
        $cm    = mysqli_real_escape_string($conn, $chat_msg);
        $now   = date("Y-m-d H:i:s");
        mysqli_query($conn, "INSERT INTO request_comments (request_id, sender_role, sender_name, message, created_at) VALUES ('$req_id','admin','$actor','$cm','$now')");

        // Also notify the requester
        $uid = (int)($req["user_db_id"] ?? 0);
        if (!$uid) {
            $uq  = mysqli_query($conn, "SELECT id FROM users WHERE name='" . mysqli_real_escape_string($conn, $req["requester"]) . "' LIMIT 1");
            $ur  = mysqli_fetch_assoc($uq);
            $uid = $ur ? (int)$ur["id"] : 0;
        }
        if ($uid) {
            $t   = mysqli_real_escape_string($conn, $req["title"]);
            $nm  = mysqli_real_escape_string($conn, "Admin commented on your request \"$t\": $chat_msg");
            mysqli_query($conn, "INSERT INTO notifications (user_id,title,message,type,is_read,created_at) VALUES ('$uid','New Comment from Admin','$nm','comment',0,'$now')");
        }
    }
    header("Location: request_info.php?id=$req_id&tab=chat&saved=1");
    exit();
}

// Re-fetch after possible update
$req_q = mysqli_query($conn, "SELECT r.*, u.email as user_email, u.id as user_db_id FROM requests r LEFT JOIN users u ON u.name = r.requester WHERE r.id = $req_id LIMIT 1");
$req   = mysqli_fetch_assoc($req_q);

// Activity log
$activity_q = mysqli_query($conn, "SELECT * FROM request_activity WHERE request_id=$req_id ORDER BY created_at DESC");
$activities = [];
while ($a = mysqli_fetch_assoc($activity_q)) $activities[] = $a;

// Chat messages
$chat_q = mysqli_query($conn, "SELECT * FROM request_comments WHERE request_id=$req_id ORDER BY created_at ASC");
$chat_messages = [];
while ($c = mysqli_fetch_assoc($chat_q)) $chat_messages[] = $c;

// Unread chat count (messages from requestor not yet seen - for future use)
$chat_count = count($chat_messages);

// Helpers
$media_files = array_filter(array_map('trim', explode(",", $req["media_file"] ?? "")));
$platforms   = array_filter(array_map('trim', explode(",", $req["platform"] ?? "")));

$status_class = match(true) {
    str_contains(strtolower($req["status"]), "approved")     => "badge--approved",
    str_contains(strtolower($req["status"]), "posted")       => "badge--posted",
    str_contains(strtolower($req["status"]), "under review") => "badge--under-review",
    str_contains(strtolower($req["status"]), "rejected")     => "badge--rejected",
    default => "badge--pending",
};
$priority_class = match(strtolower($req["priority"] ?? "")) {
    "urgent" => "badge--urgent", "high" => "badge--high", "medium" => "badge--medium", default => "badge--low",
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($req["title"]) ?> – NUPost Admin</title>
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
.sidebar__logout {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px;
    color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500;
    text-decoration: none; transition: background .15s, color .15s;
}
.sidebar__logout:hover { background: rgba(255,255,255,0.1); color: white; }

/* MAIN */
.main { margin-left: var(--sidebar-width); flex: 1; padding: 28px; }

/* TOPBAR */
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.topbar-left { display: flex; align-items: center; gap: 12px; }
.back-btn {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px;
    background: white; border: 1px solid var(--color-border); border-radius: 8px;
    font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; transition: background .15s;
}
.back-btn:hover { background: var(--color-bg); }
.topbar-title { font-size: 16px; font-weight: 600; color: var(--color-text); }
.admin-badge {
    display: flex; align-items: center; gap: 7px; padding: 6px 12px;
    background: white; border-radius: 8px; border: 1px solid var(--color-border); font-size: 12.5px; font-weight: 500;
}

/* CARD */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 20px; }

/* REQUEST HEADER CARD */
.req-header {
    padding: 20px 24px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
}
.req-header-title { font-size: 18px; font-weight: 700; color: var(--color-text); margin-bottom: 4px; }
.req-header-id { font-size: 12px; color: var(--color-text-muted); margin-bottom: 10px; }
.req-header-badges { display: flex; gap: 6px; flex-wrap: wrap; }

/* TABS */
.tabs { display: flex; gap: 0; border-bottom: 1px solid var(--color-border); padding: 0 24px; background: white; }
.tab-link {
    padding: 12px 18px; font-size: 13px; font-weight: 500; color: var(--color-text-muted);
    text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px;
    display: flex; align-items: center; gap: 6px; transition: color .15s;
    white-space: nowrap;
}
.tab-link:hover { color: var(--color-text); }
.tab-link--active { color: var(--color-primary); border-bottom-color: var(--color-primary); font-weight: 600; }
.tab-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; background: #ef4444; color: white;
    border-radius: 50%; font-size: 10px; font-weight: 700;
}

/* TAB CONTENT */
.tab-content { padding: 24px; }

/* BADGES */
.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge--approved      { background: #dcfce7; color: #16a34a; }
.badge--posted        { background: #dbeafe; color: #2563eb; }
.badge--under-review  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge--pending       { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.badge--rejected      { background: #fee2e2; color: #dc2626; }
.badge--high          { background: #fee2e2; color: #dc2626; }
.badge--urgent        { background: #fef3c7; color: #b45309; }
.badge--medium        { background: #fef3c7; color: #d97706; }
.badge--low           { background: #f3f4f6; color: #6b7280; }

/* INFO GRID */
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 40px; margin-bottom: 28px; }
.info-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 600; color: var(--color-text-muted);
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 5px;
}
.info-value { font-size: 13.5px; color: var(--color-text); line-height: 1.5; }

/* SECTION TITLE */
.section-title {
    font-size: 11px; font-weight: 600; color: var(--color-text-muted);
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px; padding-bottom: 8px;
    border-bottom: 1px solid var(--color-border);
}

/* DESCRIPTION */
.description-box {
    font-size: 13.5px; color: var(--color-text); line-height: 1.8;
    background: var(--color-bg); border-radius: 8px; padding: 16px 18px;
    border: 1px solid var(--color-border); margin-bottom: 24px;
}

/* MEDIA */
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 140px));
    gap: 14px; margin-bottom: 24px;
}
.media-wrap { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 140px; }
.media-thumb-item {
    width: 140px; height: 140px; border-radius: 10px; object-fit: cover;
    border: 1px solid var(--color-border); display: block; cursor: zoom-in;
    transition: transform .15s, box-shadow .15s; flex-shrink: 0;
}
.media-thumb-item:hover { transform: scale(1.03); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
.media-download-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    padding: 5px 0; width: 140px; background: var(--color-primary); color: white;
    border-radius: 6px; font-size: 11px; font-weight: 600;
    text-decoration: none; transition: opacity .15s; white-space: nowrap;
}
.media-download-btn:hover { opacity: .85; }
.media-empty { font-size: 13px; color: #9ca3af; }

/* PLATFORM TAGS */
.platform-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.platform-tag {
    padding: 5px 14px; background: #eff6ff; color: var(--color-primary);
    border: 1px solid #bfdbfe; border-radius: 20px; font-size: 12px; font-weight: 500;
}

/* CAPTION TAB */
.caption-area {
    width: 100%; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 12px 14px; font-size: 13.5px; font-family: var(--font); resize: vertical;
    min-height: 120px; outline: none; transition: border-color .15s; color: var(--color-text);
}
.caption-area:focus { border-color: var(--color-primary); }
.char-count { font-size: 11.5px; color: var(--color-text-muted); margin-top: 6px; }
.ai-suggestions { margin-top: 20px; }
.ai-suggestion-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px; border: 1px solid var(--color-border); border-radius: 8px;
    margin-bottom: 10px; cursor: pointer; transition: border-color .15s, background .15s; background: white;
}
.ai-suggestion-item:hover { border-color: var(--color-primary); background: #f8faff; }
.ai-suggestion-num {
    width: 22px; height: 22px; background: var(--color-primary); color: white;
    border-radius: 50%; font-size: 10px; font-weight: 700; display: flex;
    align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px;
}
.ai-suggestion-text { font-size: 13px; color: var(--color-text); line-height: 1.6; }

/* WORKFLOW TAB */
.status-btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.status-btn {
    flex: 1; min-width: 120px; padding: 14px 10px; border-radius: 10px;
    border: 2px solid var(--color-border); background: white; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    font-family: var(--font); font-size: 12.5px; font-weight: 500;
    color: var(--color-text-muted); transition: all .15s;
}
.status-btn:hover { border-color: var(--color-primary); color: var(--color-primary); background: #f0f4ff; }
.status-btn--active { border-color: var(--color-primary); background: var(--color-primary); color: white; }
.status-btn--active:hover { background: var(--color-primary-light); }
.status-btn__icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.2); }
.status-btn:not(.status-btn--active) .status-btn__icon { background: var(--color-bg); }
.status-btn__label { font-size: 12px; font-weight: 600; }

.schedule-field { margin-bottom: 20px; }
.schedule-field label { display: block; font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.schedule-field input[type="datetime-local"] {
    height: 38px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 0 12px; font-size: 13px; font-family: var(--font); background: white;
    color: var(--color-text); outline: none; transition: border-color .15s; width: 280px;
}
.schedule-field input:focus { border-color: var(--color-primary); }
.notes-field { margin-bottom: 20px; }
.notes-field label { display: block; font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.notes-textarea {
    width: 100%; min-height: 100px; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 12px 14px; font-size: 13px; font-family: var(--font); resize: vertical;
    outline: none; transition: border-color .15s; color: var(--color-text); line-height: 1.6;
}
.notes-textarea:focus { border-color: var(--color-primary); }

/* ── INLINE STATUS COMMENT BOX ── */
.status-comment-box {
    background: #f8faff; border: 1px solid #c7d7f5; border-radius: 10px;
    padding: 16px 18px; margin-bottom: 20px;
}
.status-comment-box label {
    display: block; font-size: 11px; font-weight: 600; color: var(--color-primary);
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px;
}
.status-comment-input {
    width: 100%; border: 1px solid var(--color-border); border-radius: 8px;
    padding: 10px 14px; font-size: 13px; font-family: var(--font); resize: none;
    outline: none; transition: border-color .15s; color: var(--color-text); line-height: 1.6;
    min-height: 72px; background: white;
}
.status-comment-input:focus { border-color: var(--color-primary); }
.status-comment-hint { font-size: 11px; color: var(--color-text-muted); margin-top: 6px; }

/* ACTIVITY LOG - TIMELINE */
.activity-timeline { position: relative; padding-left: 32px; }
.activity-timeline::before {
    content: ''; position: absolute; left: 11px; top: 8px; bottom: 8px;
    width: 2px; background: var(--color-border); border-radius: 2px;
}
.activity-item { display: flex; align-items: flex-start; gap: 14px; padding: 0 0 24px 0; position: relative; }
.activity-item:last-child { padding-bottom: 0; }
.activity-dot {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--color-primary); border: 3px solid white;
    box-shadow: 0 0 0 2px var(--color-primary);
    flex-shrink: 0; position: absolute; left: -32px; top: 0;
    display: flex; align-items: center; justify-content: center;
}
.activity-dot svg { width: 10px; height: 10px; color: white; }
.activity-dot--green  { background: #059669; box-shadow: 0 0 0 2px #059669; }
.activity-dot--blue   { background: #2563eb; box-shadow: 0 0 0 2px #2563eb; }
.activity-dot--yellow { background: #d97706; box-shadow: 0 0 0 2px #d97706; }
.activity-dot--red    { background: #dc2626; box-shadow: 0 0 0 2px #dc2626; }
.activity-dot--purple { background: #7c3aed; box-shadow: 0 0 0 2px #7c3aed; }
.activity-content { flex: 1; }
.activity-action { font-size: 13px; color: var(--color-text); font-weight: 600; margin-bottom: 3px; }
.activity-sub    { font-size: 12px; color: var(--color-text-muted); margin-bottom: 4px; }
.activity-meta   { font-size: 11px; color: #9ca3af; }
.activity-empty  { padding: 30px 0; text-align: center; color: #9ca3af; font-size: 13px; }

/* ── CHATBOX ── */
.chat-wrap {
    display: flex; flex-direction: column; height: 520px;
    border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden;
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
    display: flex; flex-direction: column; gap: 14px;
    background: #f8faff;
}
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

/* Bubble */
.chat-bubble-row { display: flex; align-items: flex-end; gap: 8px; }
.chat-bubble-row--admin { flex-direction: row-reverse; }
.chat-avatar {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: white;
}
.chat-avatar--admin    { background: var(--color-primary); }
.chat-avatar--requestor{ background: #7c3aed; }
.chat-bubble {
    max-width: 72%; padding: 10px 14px; border-radius: 14px;
    font-size: 13px; line-height: 1.55; word-break: break-word;
}
.chat-bubble--admin {
    background: var(--color-primary); color: white;
    border-bottom-right-radius: 4px;
}
.chat-bubble--requestor {
    background: white; color: var(--color-text);
    border: 1px solid var(--color-border);
    border-bottom-left-radius: 4px;
}
.chat-meta {
    font-size: 10.5px; margin-top: 4px; opacity: .65;
}
.chat-bubble--admin .chat-meta { text-align: right; color: rgba(255,255,255,.8); }
.chat-bubble--requestor .chat-meta { color: #9ca3af; }

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
.chat-input:focus { border-color: var(--color-primary); }
.chat-send-btn {
    width: 42px; height: 42px; background: var(--color-primary); color: white;
    border: none; border-radius: 10px; cursor: pointer; display: flex;
    align-items: center; justify-content: center; flex-shrink: 0; transition: background .15s;
}
.chat-send-btn:hover { background: var(--color-primary-light); }

/* BUTTONS */
.btn-primary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 20px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: background .15s; text-decoration: none;
}
.btn-primary:hover { background: var(--color-primary-light); }
.btn-secondary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: white; color: var(--color-text-muted);
    border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px;
    cursor: pointer; font-family: var(--font); transition: background .15s; text-decoration: none;
}
.btn-secondary:hover { background: var(--color-bg); }
.btn-ai {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: #6d28d9; color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: var(--font); transition: opacity .15s;
}
.btn-ai:hover { opacity: .85; }
.btn-ai:disabled { opacity: .5; cursor: not-allowed; }
.btn-row { display: flex; align-items: center; gap: 8px; margin-top: 16px; }

/* ALERT */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }

@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
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

<!-- MAIN -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <a href="request_management.php" class="back-btn">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Back
            </a>
            <span class="topbar-title">Request Management / Request Info</span>
        </div>
        <div class="admin-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
        </div>
    </div>

    <!-- REQUEST HEADER CARD -->
    <div class="card">
        <div class="req-header">
            <div class="req-header-left">
                <div class="req-header-title"><?= htmlspecialchars($req["title"]) ?></div>
                <div class="req-header-id">REQ-<?= htmlspecialchars($req["request_id"] ?? $req["id"]) ?></div>
                <div class="req-header-badges">
                    <span class="badge <?= $status_class ?>"><?= htmlspecialchars($req["status"]) ?></span>
                    <span class="badge <?= $priority_class ?>"><?= htmlspecialchars($req["priority"]) ?></span>
                    <?php if (!empty($req["category"])): ?>
                        <span class="badge" style="background:#f3f4f6;color:#374151;"><?= htmlspecialchars($req["category"]) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="tabs">
            <a href="?id=<?= $req_id ?>&tab=info"     class="tab-link <?= $tab==='info'     ? 'tab-link--active':'' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Request Information
            </a>
            <a href="?id=<?= $req_id ?>&tab=caption"  class="tab-link <?= $tab==='caption'  ? 'tab-link--active':'' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Content &amp; Caption
            </a>
            <a href="?id=<?= $req_id ?>&tab=workflow" class="tab-link <?= $tab==='workflow' ? 'tab-link--active':'' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Workflow &amp; Status
            </a>
            <a href="?id=<?= $req_id ?>&tab=activity" class="tab-link <?= $tab==='activity' ? 'tab-link--active':'' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Activity Log
            </a>
            <a href="?id=<?= $req_id ?>&tab=chat" class="tab-link <?= $tab==='chat' ? 'tab-link--active':'' ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Comments
                <?php if ($chat_count > 0): ?>
                    <span class="tab-badge"><?= $chat_count ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <?php if (isset($_GET["saved"])): ?>
        <div class="alert alert--success">&#10003; Changes saved successfully!</div>
    <?php endif; ?>

    <!-- ===== TAB: REQUEST INFORMATION ===== -->
    <?php if ($tab === 'info'): ?>
    <div class="card">
        <div class="tab-content">
            <div class="info-grid">
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Requester Name
                    </div>
                    <div class="info-value"><?= htmlspecialchars($req["requester"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email
                    </div>
                    <div class="info-value"><?= htmlspecialchars($req["user_email"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                        Category
                    </div>
                    <div class="info-value"><?= htmlspecialchars($req["category"] ?? "—") ?></div>
                </div>
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                        Preferred Posting Date
                    </div>
                    <div class="info-value">
                        <?= !empty($req["preferred_date"]) ? date("F j, Y", strtotime($req["preferred_date"])) : "—" ?>
                    </div>
                </div>
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Created At
                    </div>
                    <div class="info-value">
                        <?= !empty($req["created_at"]) ? date("F j, Y g:i A", strtotime($req["created_at"])) : "—" ?>
                    </div>
                </div>
                <div>
                    <div class="info-label">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Target Platforms
                    </div>
                    <div class="info-value">
                        <?php if (!empty($platforms)): ?>
                            <div class="platform-tags">
                                <?php foreach ($platforms as $p): ?>
                                    <span class="platform-tag"><?= htmlspecialchars($p) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>—<?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="section-title">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Event Description
            </div>
            <div class="description-box"><?= nl2br(htmlspecialchars($req["description"] ?? "—")) ?></div>

            <div class="section-title">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
                Uploaded Media (<?= count($media_files) ?>)
            </div>
            <?php if (!empty($media_files)): ?>
            <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
                <button onclick="downloadAll()" class="btn-primary" style="background:#059669;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
                    Save All (<?= count($media_files) ?>)
                </button>
            </div>
            <?php endif; ?>

            <div class="media-grid">
                <?php if (!empty($media_files)): ?>
                    <?php foreach ($media_files as $mf):
                        $ext      = strtolower(pathinfo($mf, PATHINFO_EXTENSION));
                        $is_video = in_array($ext, ['mp4','mov','webm']);
                        $dl_url   = "download_media.php?file=" . urlencode($mf);
                    ?>
                    <div class="media-wrap">
                        <?php if ($is_video): ?>
                            <video class="media-thumb-item" style="cursor:pointer;"
                                   onclick="window.open('../uploads/<?= htmlspecialchars($mf) ?>','_blank')"
                                   title="Click to view full video">
                                <source src="../uploads/<?= htmlspecialchars($mf) ?>">
                            </video>
                        <?php else: ?>
                            <img class="media-thumb-item"
                                 src="../uploads/<?= htmlspecialchars($mf) ?>"
                                 alt="" style="cursor:zoom-in;"
                                 onclick="openLightbox('../uploads/<?= htmlspecialchars($mf) ?>')"
                                 title="Click to view full size"
                                 onerror="this.parentElement.style.display='none';">
                        <?php endif; ?>
                        <a href="<?= $dl_url ?>" class="media-download-btn" title="Download <?= htmlspecialchars($mf) ?>">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
                            Save
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="media-empty">No media attached.</p>
                <?php endif; ?>
            </div>

            <!-- LIGHTBOX -->
            <div id="lightbox" onclick="closeLightbox()"
                 style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:999;
                        align-items:center;justify-content:center;cursor:zoom-out;">
                <div style="position:relative;max-width:90vw;max-height:90vh;">
                    <img id="lightbox-img" src="" alt=""
                         style="max-width:90vw;max-height:90vh;border-radius:10px;object-fit:contain;display:block;">
                    <div style="position:absolute;top:-40px;right:0;display:flex;gap:10px;">
                        <a id="lightbox-dl" href="" class="media-download-btn" onclick="event.stopPropagation()" style="background:white;color:#002366;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
                            Download
                        </a>
                        <button onclick="closeLightbox()"
                                style="background:white;border:none;border-radius:8px;padding:5px 12px;
                                       font-size:13px;font-weight:600;cursor:pointer;color:#111;">&#10005; Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== TAB: CONTENT & CAPTION ===== -->
    <?php elseif ($tab === 'caption'): ?>
    <div class="card">
        <div class="tab-content">
            <form method="POST">
                <div class="section-title">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    Post Caption
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:10px;">
                    <a href="https://www.canva.com/create/social-media/" target="_blank"
                       style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;
                              background:#7c3aed;color:white;border:none;border-radius:8px;
                              font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font);
                              text-decoration:none;transition:opacity .15s;"
                       onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
                        Open in Canva
                    </a>
                    <button type="button" class="btn-ai" id="ai-btn" onclick="generateCaption()">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Generate with AI
                    </button>
                </div>
                <textarea class="caption-area" name="caption" id="caption-field"
                          placeholder="Write or generate a caption for this post..."
                          oninput="updateCharCount(this)"><?= htmlspecialchars($req["caption"] ?? "") ?></textarea>
                <div class="char-count" id="char-count"><?= strlen($req["caption"] ?? "") ?> characters</div>
                <div class="btn-row">
                    <button type="submit" name="save_caption" class="btn-primary">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Caption
                    </button>
                </div>
            </form>
            <div class="ai-suggestions" id="ai-suggestions" style="display:none;">
                <div class="section-title" style="margin-top:24px;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg>
                    AI-Generated Suggestions — click to use
                </div>
                <div id="suggestions-list"></div>
            </div>
        </div>
    </div>

    <!-- ===== TAB: WORKFLOW & STATUS ===== -->
    <?php elseif ($tab === 'workflow'): ?>
    <div class="card">
        <div class="tab-content">

            <!-- STATUS CHANGE FORM -->
            <form method="POST" id="status-form">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="new_status" id="new_status_input" value="<?= htmlspecialchars($req['status']) ?>">

                <div class="section-title">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Change Status
                </div>

                <div class="status-btn-group">
                    <?php
                    $statuses = [
                        "Pending Review" => ["label"=>"Submitted",
                            "icon"=>'<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                        "Under Review"   => ["label"=>"Seen",
                            "icon"=>'<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'],
                        "Approved"       => ["label"=>"Approved",
                            "icon"=>'<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'],
                        "Posted"         => ["label"=>"Posted",
                            "icon"=>'<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>'],
                        "Rejected"       => ["label"=>"Rejected",
                            "icon"=>'<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'],
                    ];
                    foreach ($statuses as $val => $meta):
                        $is_active = $req["status"] === $val;
                    ?>
                    <button type="button"
                            class="status-btn <?= $is_active ? 'status-btn--active' : '' ?>"
                            onclick="selectStatus('<?= $val ?>', this)">
                        <div class="status-btn__icon"><?= $meta['icon'] ?></div>
                        <div class="status-btn__label"><?= $meta['label'] ?></div>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- INLINE COMMENT WITH STATUS -->
                <div class="status-comment-box">
                    <label for="status_comment">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:4px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Add a comment with this status update (optional — will be sent to requestor)
                    </label>
                    <textarea class="status-comment-input" name="status_comment" id="status_comment"
                              placeholder="e.g. Please revise the photo and resubmit. Your caption looks great!"></textarea>
                    <div class="status-comment-hint">&#128276; The requestor will receive a notification with your comment included.</div>
                </div>

                <!-- NOTIFICATION PREVIEW -->
                <div class="section-title">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    Notification Preview
                </div>
                <div id="notif-preview"
                     style="background:var(--color-bg);border:1px solid var(--color-border);border-radius:8px;
                            padding:14px 16px;font-size:13px;color:var(--color-text-muted);margin-bottom:20px;">
                    <?php
                    $cur = $req["status"];
                    $notif_msgs = [
                        "Under Review"  => "Your request \"{$req['title']}\" is now being reviewed by our team.",
                        "Approved"      => "Great news! Your request \"{$req['title']}\" has been approved.",
                        "Posted"        => "Your request \"{$req['title']}\" has been successfully published.",
                        "Rejected"      => "Unfortunately, your request \"{$req['title']}\" was not approved.",
                        "Pending Review"=> "Your request \"{$req['title']}\" has been reset to Pending Review.",
                    ];
                    if (isset($notif_msgs[$cur])) {
                        echo '<strong>&#128231; Notification to be sent:</strong><br>' . htmlspecialchars($notif_msgs[$cur]);
                    } else {
                        echo 'Select a status to preview the notification.';
                    }
                    ?>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-primary">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Save &amp; Notify Requester
                    </button>
                    <a href="?id=<?= $req_id ?>&tab=workflow" class="btn-secondary">Cancel</a>
                </div>
            </form>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:24px 0;">

            <!-- SCHEDULE + INTERNAL NOTES FORM -->
            <form method="POST">
                <input type="hidden" name="save_notes" value="1">
                <div class="section-title">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                    Schedule Posting Date &amp; Time
                </div>
                <div class="schedule-field">
                    <label for="scheduled_datetime">Preferred Date &amp; Time</label>
                    <input type="datetime-local" name="scheduled_datetime" id="scheduled_datetime"
                           value="<?= !empty($req['preferred_date']) ? $req['preferred_date'].'T00:00' : '' ?>">
                </div>
                <div class="section-title" style="margin-top:20px;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    Internal Notes (Admin Only — Not sent to requestor)
                </div>
                <div class="notes-field">
                    <textarea class="notes-textarea" name="internal_notes"
                              placeholder="Add internal notes about this request (only visible to admins)..."></textarea>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn-primary">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Notes &amp; Schedule
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- ===== TAB: ACTIVITY LOG ===== -->
    <?php elseif ($tab === 'activity'): ?>
    <div class="card">
        <div class="tab-content">
            <div class="section-title">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Request Timeline
            </div>

            <?php if (!empty($activities)): ?>
            <div class="activity-timeline">
                <?php foreach ($activities as $act):
                    $action_lower = strtolower($act["action"]);
                    $dot_class = match(true) {
                        str_contains($action_lower, "approved")     => "activity-dot--green",
                        str_contains($action_lower, "posted")       => "activity-dot--purple",
                        str_contains($action_lower, "rejected")     => "activity-dot--red",
                        str_contains($action_lower, "under review") => "activity-dot--yellow",
                        str_contains($action_lower, "review")       => "activity-dot--yellow",
                        str_contains($action_lower, "caption")      => "activity-dot--blue",
                        str_contains($action_lower, "note")         => "activity-dot--blue",
                        default                                     => "",
                    };
                    $dot_icon = match(true) {
                        str_contains($action_lower, "approved") => '<svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
                        str_contains($action_lower, "posted")   => '<svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
                        str_contains($action_lower, "rejected") => '<svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
                        str_contains($action_lower, "note")     => '<svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>',
                        default                                 => '<svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
                    };
                    $parts     = explode(":", $act["action"], 2);
                    $act_title = trim($parts[0]);
                    $act_sub   = isset($parts[1]) ? trim($parts[1]) : "";
                    $time_fmt  = date("M j, Y", strtotime($act["created_at"]));
                    $time_full = date("g:i A", strtotime($act["created_at"]));
                ?>
                <div class="activity-item">
                    <span class="activity-dot <?= $dot_class ?>"><?= $dot_icon ?></span>
                    <div class="activity-content">
                        <div class="activity-action"><?= htmlspecialchars($act_title) ?></div>
                        <?php if ($act_sub): ?>
                            <div class="activity-sub"><?= htmlspecialchars($act_sub) ?></div>
                        <?php endif; ?>
                        <div class="activity-meta">
                            By <?= htmlspecialchars($act["actor"]) ?> &middot; <?= $time_fmt ?> at <?= $time_full ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="activity-empty">
                    <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    No activity recorded yet.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== TAB: COMMENTS / CHATBOX ===== -->
    <?php elseif ($tab === 'chat'): ?>
    <div class="card">
        <div class="tab-content" style="padding:20px;">

            <div style="margin-bottom:14px;">
                <div class="section-title">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Comments &amp; Messaging
                    <span style="font-size:11px;color:var(--color-text-muted);font-weight:400;text-transform:none;letter-spacing:0;">
                        — Messages here are visible to both admin and the requestor
                    </span>
                </div>
            </div>

            <div class="chat-wrap">
                <div class="chat-header">
                    <div class="chat-header-dot"></div>
                    <div>
                        <div class="chat-header-title"><?= htmlspecialchars($req["title"]) ?></div>
                        <div class="chat-header-sub">Conversation with <?= htmlspecialchars($req["requester"]) ?></div>
                    </div>
                </div>

                <div class="chat-messages" id="chat-messages">
                    <?php if (empty($chat_messages)): ?>
                        <div class="chat-empty">
                            <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <p>No messages yet. Start the conversation!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chat_messages as $msg):
                            $is_admin = $msg["sender_role"] === "admin";
                            $initials = strtoupper(substr($msg["sender_name"], 0, 1));
                            $time_str = date("M j, g:i A", strtotime($msg["created_at"]));
                        ?>
                        <div class="chat-bubble-row <?= $is_admin ? 'chat-bubble-row--admin' : '' ?>">
                            <div class="chat-avatar <?= $is_admin ? 'chat-avatar--admin' : 'chat-avatar--requestor' ?>">
                                <?= htmlspecialchars($initials) ?>
                            </div>
                            <div class="chat-bubble <?= $is_admin ? 'chat-bubble--admin' : 'chat-bubble--requestor' ?>">
                                <?= nl2br(htmlspecialchars($msg["message"])) ?>
                                <div class="chat-meta"><?= htmlspecialchars($msg["sender_name"]) ?> &middot; <?= $time_str ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" class="chat-input-area" id="chat-form">
                    <input type="hidden" name="send_chat" value="1">
                    <textarea class="chat-input" name="chat_message" id="chat-input"
                              placeholder="Type a message to the requestor..."
                              rows="1"
                              onkeydown="handleChatKey(event)"></textarea>
                    <button type="submit" class="chat-send-btn" title="Send message">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </form>
            </div>

        </div>
    </div>
    <?php endif; ?>

</div><!-- .main -->
</div><!-- .app -->

<script>
// ── CAPTION ──────────────────────────────────────────────────────────────
function updateCharCount(el) {
    document.getElementById('char-count').textContent = el.value.length + ' characters';
}

async function generateCaption() {
    const btn = document.getElementById('ai-btn');
    btn.disabled = true;
    btn.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation:spin 1s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Generating...`;
    try {
        const resp = await fetch('../api/generate_caption.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                title:       '<?= addslashes($req["title"]) ?>',
                description: '<?= addslashes($req["description"] ?? "") ?>',
                category:    '<?= addslashes($req["category"] ?? "") ?>',
                platforms:   '<?= addslashes($req["platform"] ?? "") ?>'
            })
        });
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        const text = data.candidates?.[0]?.content?.parts?.[0]?.text?.trim();
        if (!text) throw new Error("No caption returned.");
        const suggestionsDiv = document.getElementById('ai-suggestions');
        const list = document.getElementById('suggestions-list');
        suggestionsDiv.style.display = 'block';
        const variations = [text];
        list.innerHTML = variations.map((v, i) => `
            <div class="ai-suggestion-item" onclick="useCaption(this)">
                <div class="ai-suggestion-num">${i+1}</div>
                <div class="ai-suggestion-text">${v}</div>
            </div>
        `).join('');
    } catch(e) {
        alert('Caption generation failed: ' + e.message);
    }
    btn.disabled = false;
    btn.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Generate with AI`;
}

function useCaption(el) {
    const text  = el.querySelector('.ai-suggestion-text').textContent;
    const field = document.getElementById('caption-field');
    field.value = text;
    updateCharCount(field);
    document.querySelectorAll('.ai-suggestion-item').forEach(i => i.style.borderColor = '');
    el.style.borderColor = 'var(--color-primary)';
}

// ── MEDIA ─────────────────────────────────────────────────────────────────
const mediaFiles = <?php echo json_encode(array_values($media_files)); ?>;

function openLightbox(src) {
    const lb    = document.getElementById('lightbox');
    const img   = document.getElementById('lightbox-img');
    const dlBtn = document.getElementById('lightbox-dl');
    img.src = src;
    const filename = src.split('/').pop();
    dlBtn.href = 'download_media.php?file=' + encodeURIComponent(filename);
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

function downloadAll() {
    mediaFiles.forEach((file, i) => {
        setTimeout(() => {
            const a = document.createElement('a');
            a.href = 'download_media.php?file=' + encodeURIComponent(file.trim());
            a.click();
        }, i * 600);
    });
}

// ── WORKFLOW STATUS BUTTONS ───────────────────────────────────────────────
const notifMessages = {
    'Pending Review': 'Your request "<?= addslashes($req["title"]) ?>" has been reset to Pending Review.',
    'Under Review':   'Your request "<?= addslashes($req["title"]) ?>" is now being reviewed by our team.',
    'Approved':       'Great news! Your request "<?= addslashes($req["title"]) ?>" has been approved and is ready for posting.',
    'Posted':         'Your request "<?= addslashes($req["title"]) ?>" has been successfully published on the platform.',
    'Rejected':       'Unfortunately, your request "<?= addslashes($req["title"]) ?>" was not approved. You may submit a revised one.',
};

function selectStatus(status, btn) {
    document.getElementById('new_status_input').value = status;
    document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('status-btn--active'));
    btn.classList.add('status-btn--active');
    const preview = document.getElementById('notif-preview');
    if (preview) {
        const comment = document.getElementById('status_comment')?.value?.trim();
        let msg = notifMessages[status] || 'No notification for this status.';
        if (comment) msg += ' Admin note: ' + comment;
        preview.innerHTML = `<strong>&#128231; Notification to be sent:</strong><br>${msg}`;
        preview.style.color = 'var(--color-text)';
    }
}

// Live update notification preview when comment changes
document.addEventListener('DOMContentLoaded', () => {
    const commentField = document.getElementById('status_comment');
    if (commentField) {
        commentField.addEventListener('input', () => {
            const currentStatus = document.getElementById('new_status_input')?.value;
            if (currentStatus) {
                const preview = document.getElementById('notif-preview');
                let msg = notifMessages[currentStatus] || '';
                const comment = commentField.value.trim();
                if (comment) msg += ' Admin note: ' + comment;
                if (msg) {
                    preview.innerHTML = `<strong>&#128231; Notification to be sent:</strong><br>${msg}`;
                    preview.style.color = 'var(--color-text)';
                }
            }
        });
    }

    // Scroll chat to bottom
    const chatBox = document.getElementById('chat-messages');
    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
});

// ── CHAT ──────────────────────────────────────────────────────────────────
function handleChatKey(e) {
    // Enter to send, Shift+Enter for newline
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const val = document.getElementById('chat-input').value.trim();
        if (val) document.getElementById('chat-form').submit();
    }
}
</script>

</body>
</html>
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];
$success = "";
$error   = "";

// Unread notifications count
$unread_q     = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_count = mysqli_fetch_assoc($unread_q)["c"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $title       = mysqli_real_escape_string($conn, trim($_POST["title"] ?? ""));
    $description = mysqli_real_escape_string($conn, trim($_POST["description"] ?? ""));
    $category    = mysqli_real_escape_string($conn, trim($_POST["category"] ?? ""));
    $priority    = mysqli_real_escape_string($conn, trim($_POST["priority"] ?? ""));
    $post_date   = mysqli_real_escape_string($conn, trim($_POST["post_date"] ?? ""));
    $caption     = mysqli_real_escape_string($conn, trim($_POST["caption"] ?? ""));
    $requester   = mysqli_real_escape_string($conn, $user_name);
    $status      = "Pending Review";

    $platforms = $_POST["platforms"] ?? [];
    $platform  = mysqli_real_escape_string($conn, implode(",", $platforms));

    if (!$title || !$description || !$category || !$priority) {
        $error = "Please fill in all required fields.";
    } else {
        $media_file = "";
        if (!empty($_FILES["media"]["name"][0])) {
            $upload_dir = "../uploads/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $allowed_types = ["image/jpeg","image/png","image/gif","image/webp","video/mp4","video/quicktime"];
            $max_size = 10 * 1024 * 1024;

            $uploaded_files = [];
            $file_count = min(count($_FILES["media"]["name"]), 4);

            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES["media"]["error"][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES["media"]["size"][$i] > $max_size) continue;
                if (!in_array($_FILES["media"]["type"][$i], $allowed_types)) continue;

                $ext      = pathinfo($_FILES["media"]["name"][$i], PATHINFO_EXTENSION);
                $filename = uniqid("media_") . "." . $ext;
                move_uploaded_file($_FILES["media"]["tmp_name"][$i], $upload_dir . $filename);
                $uploaded_files[] = $filename;
            }
            $media_file = mysqli_real_escape_string($conn, implode(",", $uploaded_files));
        }

        $insert = mysqli_query($conn,
            "INSERT INTO requests (title, requester, category, priority, status, description, media_file, platform, caption, preferred_date, created_at)
             VALUES ('$title','$requester','$category','$priority','$status','$description','$media_file','$platform','$caption','$post_date', NOW())"
        );

        if ($insert) {
            $success = "Request submitted successfully!";
        } else {
            $error = "Something went wrong. Please try again. " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Create Request</title>
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
html, body { font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }

/* ===== TOPNAV ===== */
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
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* ===== LAYOUT ===== */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 680px; margin: 0 auto; padding: 32px 24px; }

/* ===== PAGE HEADER ===== */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* ===== FORM SECTIONS ===== */
.form-section { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 24px; margin-bottom: 16px; }
.section-title { font-size: 14px; font-weight: 600; margin-bottom: 18px; }
.section-title span { color: #ef4444; margin-left: 2px; }

/* ===== FIELDS ===== */
.field { margin-bottom: 16px; }
.field:last-child { margin-bottom: 0; }
.field label { display: block; font-size: 12px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 6px; }
.field label span { color: #ef4444; }
.field input[type="text"],
.field input[type="date"],
.field select,
.field textarea {
    width: 100%; border: 1px solid var(--color-border); border-radius: 7px;
    padding: 9px 12px; font-size: 13px; font-family: var(--font);
    color: var(--color-text); background: white; outline: none; transition: border-color .15s;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--color-primary); }
.field textarea { resize: vertical; min-height: 100px; }
.field input::placeholder, .field textarea::placeholder { color: #d1d5db; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ===== PLATFORMS ===== */
.platform-group { display: flex; gap: 8px; flex-wrap: wrap; }
.platform-btn {
    padding: 8px 20px; border-radius: 7px; border: 1px solid var(--color-border);
    font-size: 13px; font-weight: 500; cursor: pointer; background: white;
    color: var(--color-text); font-family: var(--font); transition: all .15s; user-select: none;
}
.platform-btn.selected { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.platform-btn:hover:not(.selected) { background: var(--color-bg); }

/* ===== MEDIA UPLOAD ===== */
.upload-area { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; align-items: flex-start; }
.upload-box {
    width: 80px; height: 80px; border: 1.5px dashed var(--color-border);
    border-radius: 8px; display: flex; flex-direction: column;
    align-items: center; justify-content: center; cursor: pointer;
    color: var(--color-text-muted); font-size: 11px; gap: 5px;
    transition: border-color .15s, background .15s; background: #fafafa;
}
.upload-box:hover { border-color: var(--color-primary); background: #f0f4ff; }
.upload-box svg { color: #9ca3af; }
.upload-hint { font-size: 11.5px; color: var(--color-text-muted); }
#media-input { display: none; }
.preview-wrap { position: relative; width: 80px; height: 80px; }
.preview-thumb { width: 80px; height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid var(--color-border); display: block; }
.preview-remove {
    position: absolute; top: -6px; right: -6px; width: 20px; height: 20px;
    background: #ef4444; color: white; border: none; border-radius: 50%;
    font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center;
    line-height: 1; font-weight: 700;
}

/* ===== AI CAPTION ===== */
.ai-section { background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: var(--radius); padding: 20px; margin-bottom: 16px; }
.ai-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.ai-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #5b21b6; }
.ai-generate-btn {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px; background: #6d28d9; color: white;
    border: none; border-radius: 7px; font-size: 12.5px; font-weight: 500; cursor: pointer; font-family: var(--font); transition: opacity .15s;
}
.ai-generate-btn:hover { opacity: .85; }
.ai-generate-btn:disabled { opacity: .5; cursor: not-allowed; }
.ai-caption-label { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
.ai-textarea {
    width: 100%; border: 1px solid #ddd6fe; border-radius: 7px; padding: 10px 12px; font-size: 13px; font-family: var(--font);
    background: white; color: var(--color-text); outline: none; resize: vertical; min-height: 90px;
}
.ai-textarea:focus { border-color: #8b5cf6; }
.ai-textarea::placeholder { color: #d1d5db; }

/* ===== ALERTS ===== */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; font-weight: 500; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.alert--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

/* ===== FORM ACTIONS ===== */
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }
.btn-cancel {
    padding: 9px 22px; border-radius: 7px; border: 1px solid var(--color-border);
    background: white; font-size: 13px; font-weight: 500; cursor: pointer; color: var(--color-text); font-family: var(--font);
}
.btn-cancel:hover { background: var(--color-bg); }
.btn-submit {
    padding: 9px 22px; border-radius: 7px; border: none; background: var(--color-primary); color: white;
    font-size: 13px; font-weight: 600; cursor: pointer; font-family: var(--font);
}
.btn-submit:hover { background: var(--color-primary-light); }

@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
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
        <h1>Create New Request</h1>
        <p>Submit a new social media post request</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert--success">
            <?= $success ?> <a href="requests.php" style="color:inherit;font-weight:700;">View your requests →</a>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert--error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="create-form">

        <!-- BASIC INFORMATION -->
        <div class="form-section">
            <div class="section-title">Basic Information</div>
            <div class="field">
                <label>Event/Post Title <span>*</span></label>
                <input type="text" name="title" placeholder="e.g., College Week 2025 Opening Ceremony"
                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Description <span>*</span></label>
                <textarea name="description" rows="4"
                          placeholder="Provide detailed information about your event or announcement..."
                          required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>Category <span>*</span></label>
                    <select name="category" required>
                        <option value="" disabled <?= empty($_POST['category']) ? 'selected' : '' ?>>Select category</option>
                        <option value="Events"        <?= ($_POST['category'] ?? '') === 'Events'        ? 'selected' : '' ?>>Events</option>
                        <option value="Announcements" <?= ($_POST['category'] ?? '') === 'Announcements' ? 'selected' : '' ?>>Announcements</option>
                        <option value="Academic"      <?= ($_POST['category'] ?? '') === 'Academic'      ? 'selected' : '' ?>>Academic</option>
                        <option value="Sports"        <?= ($_POST['category'] ?? '') === 'Sports'        ? 'selected' : '' ?>>Sports</option>
                        <option value="Community"     <?= ($_POST['category'] ?? '') === 'Community'     ? 'selected' : '' ?>>Community</option>
                        <option value="Others"        <?= ($_POST['category'] ?? '') === 'Others'        ? 'selected' : '' ?>>Others</option>
                    </select>
                </div>
                <div class="field">
                    <label>Priority <span>*</span></label>
                    <select name="priority" required>
                        <option value="" disabled <?= empty($_POST['priority']) ? 'selected' : '' ?>>Select priority</option>
                        <option value="Low"    <?= ($_POST['priority'] ?? '') === 'Low'    ? 'selected' : '' ?>>Low</option>
                        <option value="Medium" <?= ($_POST['priority'] ?? '') === 'Medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="High"   <?= ($_POST['priority'] ?? '') === 'High'   ? 'selected' : '' ?>>High</option>
                        <option value="Urgent" <?= ($_POST['priority'] ?? '') === 'Urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Preferred Post Date (Optional)</label>
                <input type="date" name="post_date" value="<?= htmlspecialchars($_POST['post_date'] ?? '') ?>">
            </div>
        </div>

        <!-- TARGET PLATFORMS -->
        <div class="form-section">
            <div class="section-title">Target Platforms <span style="color:#ef4444;font-size:13px;font-weight:400;">*</span></div>
            <div class="platform-group" id="platform-group">
                <?php foreach (["Facebook","Youtube","Tiktok","LinkedIn","Instagram","Twitter"] as $p):
                    $sel = in_array($p, $_POST["platforms"] ?? []) ? "selected" : "";
                ?>
                <button type="button" class="platform-btn <?= $sel ?>" data-platform="<?= $p ?>"><?= $p ?></button>
                <?php endforeach; ?>
            </div>
            <div id="platform-inputs"></div>
        </div>

        <!-- MEDIA ATTACHMENTS -->
        <div class="form-section">
            <div class="section-title">Media Attachments</div>
            <div class="upload-area" id="upload-area">
                <label class="upload-box" for="media-input" id="upload-label">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <polyline points="16 16 12 12 8 16"/>
                        <line x1="12" y1="12" x2="12" y2="21"/>
                        <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                    </svg>
                    Upload
                </label>
            </div>
            <input type="file" id="media-input" name="media[]" multiple accept="image/*,video/mp4,video/quicktime">
            <p class="upload-hint" style="margin-top:8px;">Upload up to 4 images or videos (Max 10MB each)</p>
        </div>

        <!-- AI CAPTION GENERATOR -->
        <div class="ai-section">
            <div class="ai-header">
                <div class="ai-title">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                        <path d="M2 17l10 5 10-5"/>
                        <path d="M2 12l10 5 10-5"/>
                    </svg>
                    AI Caption Generator
                </div>
                <button type="button" class="ai-generate-btn" id="generate-btn" onclick="generateCaption()">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    Generate
                </button>
            </div>
            <div class="ai-caption-label">Caption (Editable)</div>
            <textarea class="ai-textarea" name="caption" id="caption-field"
                      placeholder="Write or generate an AI-powered caption for your post..."><?= htmlspecialchars($_POST['caption'] ?? '') ?></textarea>
        </div>

        <!-- FORM ACTIONS -->
        <div class="form-actions">
            <button type="button" class="btn-cancel" onclick="history.back()">Cancel</button>
            <button type="submit" class="btn-submit">Submit Request</button>
        </div>

    </form>
</main>
</div>

<script>
const platformBtns = document.querySelectorAll('.platform-btn');
const platformInputs = document.getElementById('platform-inputs');

function updatePlatformInputs() {
    platformInputs.innerHTML = '';
    document.querySelectorAll('.platform-btn.selected').forEach(btn => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'platforms[]';
        inp.value = btn.dataset.platform;
        platformInputs.appendChild(inp);
    });
}

platformBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        btn.classList.toggle('selected');
        updatePlatformInputs();
    });
});
updatePlatformInputs();

const mediaInput = document.getElementById('media-input');
const uploadArea = document.getElementById('upload-area');
const uploadLabel = document.getElementById('upload-label');

mediaInput.addEventListener('change', () => {
    document.querySelectorAll('.preview-wrap').forEach(el => el.remove());
    const files = Array.from(mediaInput.files).slice(0, 4);
    files.forEach((file) => {
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.createElement('div');
            wrap.className = 'preview-wrap';
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'preview-thumb';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'preview-remove';
            removeBtn.innerHTML = '×';
            removeBtn.onclick = () => wrap.remove();
            wrap.appendChild(img);
            wrap.appendChild(removeBtn);
            uploadArea.insertBefore(wrap, uploadLabel);
        };
        reader.readAsDataURL(file);
    });
    uploadLabel.style.display = files.length >= 4 ? 'none' : 'flex';
});

async function generateCaption() {
    const title = document.querySelector('input[name="title"]').value.trim();
    const desc  = document.querySelector('textarea[name="description"]').value.trim();
    const cat   = document.querySelector('select[name="category"]').value;
    const plats = Array.from(document.querySelectorAll('.platform-btn.selected'))
                       .map(b => b.dataset.platform).join(', ');

    if (!title || !desc) {
        alert("Please fill in the Title and Description first before generating a caption.");
        return;
    }

    const btn = document.getElementById('generate-btn');
    btn.disabled = true;
    btn.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation:spin 1s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Generating...`;

    try {
        const response = await fetch('../api/generate_caption.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, description: desc, category: cat, platforms: plats })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        const resultText = data.candidates?.[0]?.content?.parts?.[0]?.text;
        if (!resultText) throw new Error("No caption returned. Please try again.");
        document.getElementById('caption-field').value = resultText.trim();
    } catch (e) {
        alert("Caption generation failed: " + e.message);
    }

    btn.disabled = false;
    btn.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Generate`;
}
</script>

</body>
</html>
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION["user_id"];
$user_name = $_SESSION["name"];

// Unread notifications
$unread_q     = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id='$user_id' AND is_read=0");
$unread_count = mysqli_fetch_assoc($unread_q)["c"];

$success = "";
$error   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name         = mysqli_real_escape_string($conn, trim($_POST["name"] ?? ""));
    $email        = mysqli_real_escape_string($conn, trim($_POST["email"] ?? ""));
    $phone        = mysqli_real_escape_string($conn, trim($_POST["phone"] ?? ""));
    $organization = mysqli_real_escape_string($conn, trim($_POST["organization"] ?? ""));
    $department   = mysqli_real_escape_string($conn, trim($_POST["department"] ?? ""));
    $bio          = mysqli_real_escape_string($conn, trim($_POST["bio"] ?? ""));

    if (!$name || !$email) {
        $error = "Full name and email are required.";
    } else {
        $photo_col = "";

        // Handle profile photo upload
        if (isset($_FILES["photo"]) && $_FILES["photo"]["error"] === UPLOAD_ERR_OK && !empty($_FILES["photo"]["name"])) {
            $allowed_types = ["image/jpeg", "image/png", "image/gif", "image/webp"];
            $max_size      = 5 * 1024 * 1024;
            $file_type     = mime_content_type($_FILES["photo"]["tmp_name"]); // more reliable than browser type
            $file_size     = $_FILES["photo"]["size"];

            if (!in_array($file_type, $allowed_types)) {
                $error = "Invalid file type. Only JPG, PNG, GIF, WEBP allowed.";
            } elseif ($file_size > $max_size) {
                $error = "Photo must be under 5MB.";
            } else {
                $upload_dir = "../uploads/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                // Delete old avatar if exists
                $old_q    = mysqli_query($conn, "SELECT profile_photo FROM users WHERE id='$user_id'");
                $old_user = mysqli_fetch_assoc($old_q);
                if (!empty($old_user["profile_photo"])) {
                    $old_path = $upload_dir . $old_user["profile_photo"];
                    if (file_exists($old_path)) unlink($old_path);
                }

                $ext      = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
                $filename = "avatar_" . $user_id . "_" . time() . "." . $ext;
                if (move_uploaded_file($_FILES["photo"]["tmp_name"], $upload_dir . $filename)) {
                    $escaped_filename = mysqli_real_escape_string($conn, $filename);
                    $photo_col = ", profile_photo='$escaped_filename'";
                } else {
                    $error = "Failed to upload photo. Check folder permissions.";
                }
            }
        }

        if (!$error) {
            $result = mysqli_query($conn,
                "UPDATE users SET
                    name='$name',
                    email='$email',
                    phone='$phone',
                    organization='$organization',
                    department='$department',
                    bio='$bio'
                    $photo_col
                 WHERE id='$user_id'"
            );

            if ($result) {
                $_SESSION["name"] = $name;
                $success = "Profile updated successfully!";
            } else {
                $error = "Database error: " . mysqli_error($conn);
            }
        }
    }
}

// Fetch latest user data after update
$user_q = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$user   = mysqli_fetch_assoc($user_q);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Edit Profile</title>
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
    background: none; border: none; cursor: pointer; color: var(--color-text-muted); text-decoration: none; transition: background .15s;
}
.topnav__icon-btn:hover { background: var(--color-bg); }
.topnav__icon-btn--active { background: var(--color-primary) !important; color: white !important; }
.topnav__badge {
    position: absolute; top: 4px; right: 4px; width: 16px; height: 16px;
    background: #ef4444; color: white; font-size: 9px; font-weight: 700;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* LAYOUT */
.layout { padding-top: var(--topbar-height); min-height: 100vh; }
.main { max-width: 600px; margin: 0 auto; padding: 32px 24px; }

/* BREADCRUMB */
.breadcrumb { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 12.5px; }
.breadcrumb a { color: var(--color-primary); text-decoration: none; display: flex; align-items: center; gap: 4px; }
.breadcrumb a:hover { text-decoration: underline; }

.page-header { margin-bottom: 20px; }
.page-header h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
.page-header p  { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

/* ALERT */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; font-weight: 500; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.alert--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

/* CARD */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 24px; margin-bottom: 16px; }
.card-title { font-size: 13.5px; font-weight: 600; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--color-border); }

/* PROFILE PHOTO */
.photo-section { display: flex; align-items: center; gap: 18px; }
.photo-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: #dbeafe; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}
.photo-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-avatar svg { color: #3b82f6; }
.photo-info { flex: 1; }
.btn-upload {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: var(--color-primary); color: white;
    border: none; border-radius: 7px; font-size: 12.5px; font-weight: 600;
    cursor: pointer; font-family: var(--font); margin-bottom: 6px;
    text-decoration: none;
}
.btn-upload:hover { background: var(--color-primary-light); }
.photo-hint { font-size: 11.5px; color: var(--color-text-muted); }
#photo-input { display: none; }
.photo-preview-name { font-size: 11.5px; color: var(--color-primary); margin-top: 4px; font-weight: 500; }

/* FORM FIELDS */
.field { margin-bottom: 14px; }
.field label {
    display: flex; align-items: center; gap: 5px;
    font-size: 11.5px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 5px;
}
.field label svg { flex-shrink: 0; }
.field input[type="text"],
.field input[type="email"],
.field input[type="tel"],
.field textarea {
    width: 100%; border: 1px solid var(--color-border); border-radius: 7px;
    padding: 9px 12px; font-size: 13px; font-family: var(--font);
    color: var(--color-text); background: white; outline: none; transition: border-color .15s;
}
.field input:focus, .field textarea:focus { border-color: var(--color-primary); }
.field input::placeholder, .field textarea::placeholder { color: #d1d5db; }
.field textarea { resize: vertical; min-height: 90px; }
.field-hint { font-size: 11px; color: #9ca3af; margin-top: 4px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.req { color: #ef4444; margin-left: 2px; }

/* FORM ACTIONS */
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.btn-cancel {
    padding: 9px 22px; border-radius: 7px; border: 1px solid var(--color-border);
    background: white; font-size: 13px; font-weight: 500; cursor: pointer;
    color: var(--color-text); font-family: var(--font); text-decoration: none;
    display: inline-flex; align-items: center;
}
.btn-cancel:hover { background: var(--color-bg); }
.btn-save {
    padding: 9px 22px; border-radius: 7px; border: none;
    background: var(--color-primary); color: white;
    font-size: 13px; font-weight: 600; cursor: pointer; font-family: var(--font);
}
.btn-save:hover { background: var(--color-primary-light); }

@media (max-width: 600px) { .field-row { grid-template-columns: 1fr; } }
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
        <a href="notifications.php" class="topnav__icon-btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php if ($unread_count > 0): ?>
                <span class="topnav__badge"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="topnav__icon-btn topnav__icon-btn--active">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
    </div>
</nav>

<div class="layout">
<main class="main">

    <div class="breadcrumb">
        <a href="profile.php">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Profile
        </a>
    </div>

    <div class="page-header">
        <h1>Edit Profile</h1>
        <p>Update your personal information and profile details.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert--success"><?= $success ?> <a href="profile.php" style="color:inherit;font-weight:700;">View Profile →</a></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert--error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <!-- PROFILE PHOTO -->
        <div class="card">
            <div class="card-title">Profile Photo</div>
            <div class="photo-section">
                <div class="photo-avatar" id="photo-preview">
                    <?php if (!empty($user["profile_photo"]) && file_exists("../uploads/" . $user["profile_photo"])): ?>
                        <img src="../uploads/<?= htmlspecialchars($user["profile_photo"]) ?>?v=<?= time() ?>" alt="Avatar">
                    <?php else: ?>
                        <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="photo-info">
                    <label class="btn-upload" for="photo-input">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                        Upload Photo
                    </label>
                    <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div class="photo-hint">JPG, PNG, GIF or WEBP (Max size 5MB)</div>
                    <div class="photo-preview-name" id="photo-name"></div>
                </div>
            </div>
        </div>

        <!-- PERSONAL INFORMATION -->
        <div class="card">
            <div class="card-title">Personal Information</div>

            <div class="field-row">
                <div class="field">
                    <label>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Full Name <span class="req">*</span>
                    </label>
                    <input type="text" name="name" value="<?= htmlspecialchars($user["name"] ?? '') ?>" placeholder="Your full name" required>
                </div>
                <div class="field">
                    <label>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email Address <span class="req">*</span>
                    </label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user["email"] ?? '') ?>" placeholder="your@email.com" required>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.36 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.64a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Phone Number
                    </label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user["phone"] ?? '') ?>" placeholder="+63 912 345 6789">
                </div>
                <div class="field">
                    <label>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Organization
                    </label>
                    <input type="text" name="organization" value="<?= htmlspecialchars($user["organization"] ?? '') ?>" placeholder="e.g., Computer Science Student Council">
                </div>
            </div>

            <div class="field">
                <label>
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    Department
                </label>
                <input type="text" name="department" value="<?= htmlspecialchars($user["department"] ?? '') ?>" placeholder="e.g., College of Computing and Information Sciences">
            </div>

            <div class="field">
                <label>
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Bio (Optional)
                </label>
                <textarea name="bio" placeholder="Tell us about yourself..." maxlength="250"><?= htmlspecialchars($user["bio"] ?? '') ?></textarea>
                <div class="field-hint">Brief description for your profile. Maximum 250 characters.</div>
            </div>
        </div>

        <div class="form-actions">
            <a href="profile.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save Changes</button>
        </div>

    </form>
</main>
</div>

<script>
document.getElementById('photo-input').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    // Show filename
    document.getElementById('photo-name').textContent = '📎 ' + file.name;

    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('photo-preview');
        preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
    };
    reader.readAsDataURL(file);
});
</script>

</body>
</html>
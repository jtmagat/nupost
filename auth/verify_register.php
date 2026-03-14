<?php
session_start();
require_once "../config/database.php";

// Guard
if (!isset($_SESSION["reg_user_id"]) || !isset($_SESSION["reg_email"])) {
    header("Location: registration.php");
    exit();
}

$user_id    = $_SESSION["reg_user_id"];
$user_email = $_SESSION["reg_email"];
$user_name  = $_SESSION["reg_name"]  ?? "User";
$otp_sent   = $_SESSION["reg_sent"]  ?? false;
$show_otp   = $_SESSION["reg_otp"]   ?? null; // shown only if email failed

$error   = "";
$success = "";

// ─── RESEND OTP ─────────────────────────────────────────────────────────────
if (isset($_GET["resend"])) {
    $otp        = str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $expires_at = date("Y-m-d H:i:s", strtotime("+5 minutes"));
    $uid_esc    = (int)$user_id;
    $email_esc  = mysqli_real_escape_string($conn, $user_email);
    $otp_esc    = mysqli_real_escape_string($conn, $otp);

    mysqli_query($conn, "DELETE FROM otp_codes WHERE user_id='$uid_esc'");
    mysqli_query($conn, "INSERT INTO otp_codes (user_id, email, otp_code, expires_at)
                          VALUES ('$uid_esc','$email_esc','$otp_esc','$expires_at')");

    $resent = false;
    try {
        require_once "send_otp.php";
        $resent = sendOTPEmail($user_email, $user_name, $otp);
    } catch (Exception $e) { $resent = false; }

    if ($resent) {
        $success = "A new code has been sent to your email.";
        $_SESSION["reg_sent"] = true;
        unset($_SESSION["reg_otp"]);
        $show_otp = null;
        $otp_sent = true;
    } else {
        $_SESSION["reg_otp"] = $otp;
        $show_otp = $otp;
        $success  = "Email sending failed. Use the code below instead.";
    }
}

// ─── VERIFY OTP ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $digits = [];
    for ($i = 1; $i <= 6; $i++) {
        $digits[] = preg_replace('/\D/', '', $_POST["d$i"] ?? "");
    }
    $entered_otp = implode("", $digits);

    if (strlen($entered_otp) !== 6) {
        $error = "Please enter all 6 digits.";
    } else {
        $uid_esc = (int)$user_id;
        $otp_esc = mysqli_real_escape_string($conn, $entered_otp);
        $now     = date("Y-m-d H:i:s");

        $otp_q = mysqli_query($conn,
            "SELECT * FROM otp_codes
             WHERE user_id='$uid_esc'
               AND otp_code='$otp_esc'
               AND is_used=0
               AND expires_at > '$now'
             LIMIT 1"
        );

        if ($otp_q && mysqli_num_rows($otp_q) === 1) {
            $otp_row = mysqli_fetch_assoc($otp_q);
            mysqli_query($conn, "UPDATE otp_codes SET is_used=1 WHERE id='" . (int)$otp_row["id"] . "'");
            mysqli_query($conn, "UPDATE users SET is_verified=1 WHERE id='$uid_esc'");

            unset($_SESSION["reg_user_id"], $_SESSION["reg_email"],
                  $_SESSION["reg_name"],    $_SESSION["reg_otp"],
                  $_SESSION["reg_sent"]);

            $_SESSION["reg_success"] = "Account verified! You can now log in.";
            header("Location: login.php?verified=1");
            exit();
        } else {
            $exp_q = mysqli_query($conn,
                "SELECT * FROM otp_codes WHERE user_id='$uid_esc' AND otp_code='$otp_esc' AND is_used=0 LIMIT 1"
            );
            if ($exp_q && mysqli_num_rows($exp_q) === 1) {
                $error = "This code has expired. Click Resend Code.";
            } else {
                $error = "Invalid code. Please try again.";
            }
        }
    }
}

function maskEmail($email) {
    $parts  = explode("@", $email);
    $local  = $parts[0];
    $domain = $parts[1] ?? "";
    $masked = substr($local, 0, 2) . str_repeat("*", max(strlen($local) - 3, 2)) . substr($local, -1);
    return $masked . "@" . $domain;
}
$masked_email = maskEmail($user_email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Verify Your Email</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --color-primary: #002366;
    --color-primary-light: #003a8c;
    --color-bg: #f5f6fa;
    --color-border: #e5e7eb;
    --color-text: #111827;
    --color-muted: #6b7280;
    --font: 'Inter', sans-serif;
    --shadow-card: 0 10px 15px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.08);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); }

.page { position: relative; min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.page__bg { position: absolute; inset: 0; z-index: 0; }
.page__bg img { width: 100%; height: 100%; object-fit: cover; }

.card {
    position: relative; z-index: 1; background: white;
    width: 460px; border-radius: 12px; box-shadow: var(--shadow-card);
    padding: 40px 40px 32px; display: flex; flex-direction: column; align-items: center;
}
.logo { width: 140px; margin-bottom: 24px; }
.logo img { width: 100%; }

.shield-wrap {
    width: 64px; height: 64px; background: #eff6ff; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; margin-bottom: 16px;
}
.shield-wrap svg { color: var(--color-primary); }

.title    { font-size: 20px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; text-align: center; }
.subtitle { font-size: 13px; color: var(--color-muted); text-align: center; line-height: 1.6; margin-bottom: 20px; }
.subtitle strong { color: var(--color-text); }

/* TIMER */
.timer-wrap { width: 100%; margin-bottom: 20px; }
.timer-label { display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--color-muted); margin-bottom: 6px; }
.timer-label span { font-weight: 600; color: var(--color-primary); }
.timer-bar-bg { height: 4px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
.timer-bar { height: 4px; background: var(--color-primary); border-radius: 4px; width: 100%; transition: width 1s linear; }
.timer-bar.warning { background: #f59e0b; }
.timer-bar.danger  { background: #ef4444; }

/* FALLBACK OTP DISPLAY — shown when email fails */
.otp-fallback {
    width: 100%; background: #fef9c3; border: 1.5px solid #fde047;
    border-radius: 10px; padding: 16px 18px; margin-bottom: 20px; text-align: center;
}
.otp-fallback__label {
    font-size: 11px; font-weight: 600; color: #854d0e;
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;
}
.otp-fallback__digits { display: flex; justify-content: center; gap: 8px; margin-bottom: 10px; }
.otp-fallback__digit {
    width: 40px; height: 48px; background: white; border: 2px solid #fbbf24;
    border-radius: 8px; font-size: 22px; font-weight: 700; color: #002366;
    display: flex; align-items: center; justify-content: center; font-family: monospace;
    cursor: pointer; transition: background .15s;
}
.otp-fallback__digit:hover { background: #fef3c7; }
.otp-fallback__note { font-size: 11.5px; color: #92400e; line-height: 1.5; }
.otp-fallback__autofill {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;
    padding: 7px 16px; background: #002366; color: white; border: none;
    border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer;
    font-family: var(--font); transition: opacity .15s;
}
.otp-fallback__autofill:hover { opacity: .85; }

/* OTP INPUT BOXES */
.otp-form { width: 100%; }
.otp-boxes { display: flex; gap: 10px; justify-content: center; margin-bottom: 16px; }
.otp-box {
    width: 52px; height: 60px;
    border: 2px solid var(--color-border); border-radius: 10px;
    font-size: 26px; font-weight: 700; color: var(--color-primary);
    text-align: center; outline: none; font-family: monospace;
    transition: border-color .15s, box-shadow .15s; background: #fafafa;
    caret-color: var(--color-primary);
}
.otp-box:focus { border-color: var(--color-primary); background: white; box-shadow: 0 0 0 3px rgba(0,35,102,0.1); }
.otp-box.filled { border-color: var(--color-primary); background: #f0f4ff; }
.otp-box.error  { border-color: #ef4444; background: #fff5f5; }

.btn-verify {
    width: 100%; height: 44px; background: var(--color-primary); color: white;
    border: none; border-radius: 8px; font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: var(--font); transition: opacity .15s; margin-bottom: 14px;
}
.btn-verify:hover { opacity: .9; }
.btn-verify:disabled { opacity: .5; cursor: not-allowed; }

/* ALERTS */
.alert { width: 100%; padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; text-align: center; }
.alert--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
.alert--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }

.links { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; }
.resend-row { font-size: 12.5px; color: var(--color-muted); }
.resend-row a { color: var(--color-primary); font-weight: 600; text-decoration: none; }
.resend-row a:hover { text-decoration: underline; }
.back-link { font-size: 12px; color: var(--color-muted); text-decoration: none; display: flex; align-items: center; gap: 4px; }
.back-link:hover { color: var(--color-text); }

.secure-badge { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #9ca3af; margin-top: 18px; }
.secure-badge svg { color: #10b981; }

@media(max-width: 500px) {
    .card { width: 92%; padding: 32px 20px 24px; }
    .otp-box { width: 42px; height: 52px; font-size: 22px; }
}
</style>
</head>
<body>
<div class="page">
    <div class="page__bg">
        <img src="assets/nubg1.png" alt="NU Lipa">
    </div>

    <div class="card">
        <div class="logo">
            <img src="assets/nupostlogo.png" alt="NUPost" onerror="this.style.display='none';">
        </div>

        <div class="shield-wrap">
            <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
        </div>

        <div class="title">Verify Your Email</div>
        <div class="subtitle">
            <?php if ($otp_sent): ?>
                We sent a 6-digit code to<br>
                <strong><?= htmlspecialchars($masked_email) ?></strong>
            <?php else: ?>
                Email could not be sent. Use the code below to verify your account.
            <?php endif; ?>
        </div>

        <!-- TIMER -->
        <div class="timer-wrap">
            <div class="timer-label">
                <span>Code expires in:</span>
                <span id="timer-text">5:00</span>
            </div>
            <div class="timer-bar-bg">
                <div class="timer-bar" id="timer-bar"></div>
            </div>
        </div>

        <!-- FALLBACK OTP — shown if email failed -->
        <?php if ($show_otp): ?>
        <div class="otp-fallback">
            <div class="otp-fallback__label">⚠️ Email not sent — Your verification code:</div>
            <div class="otp-fallback__digits" id="fallback-digits">
                <?php foreach (str_split($show_otp) as $d): ?>
                    <div class="otp-fallback__digit"><?= $d ?></div>
                <?php endforeach; ?>
            </div>
            <div class="otp-fallback__note">
                Click the button below to auto-fill, or type the code manually in the boxes.
            </div>
            <button type="button" class="otp-fallback__autofill" onclick="autofillOTP('<?= $show_otp ?>')">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Auto-fill Code
            </button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert--success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- OTP FORM -->
        <form class="otp-form" method="POST" id="otp-form">
            <div class="otp-boxes">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                <input class="otp-box" type="text" name="d<?= $i ?>"
                       id="d<?= $i ?>" maxlength="1" inputmode="numeric"
                       pattern="[0-9]" autocomplete="off">
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn-verify" id="verify-btn" disabled>Verify Email</button>
        </form>

        <div class="links">
            <div class="resend-row">
                Didn't receive the code? <a href="?resend=1">Resend Code</a>
            </div>
            <a href="registration.php" class="back-link">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Back to Registration
            </a>
        </div>

        <div class="secure-badge">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Email Verification Required
        </div>
    </div>
</div>

<script>
// ─── AUTO-FILL OTP ─────────────────────────────────────────────────────────
function autofillOTP(code) {
    const boxes = document.querySelectorAll('.otp-box');
    boxes.forEach((box, i) => {
        box.value = code[i] || '';
        box.classList.toggle('filled', !!box.value);
    });
    checkAllFilled();
    // Auto-submit after short delay
    setTimeout(() => {
        document.getElementById('otp-form').submit();
    }, 400);
}

// ─── OTP BOX NAVIGATION ────────────────────────────────────────────────────
const boxes = document.querySelectorAll('.otp-box');

boxes.forEach((box, i) => {
    box.addEventListener('input', (e) => {
        const val = e.target.value.replace(/\D/g, '');
        e.target.value = val ? val[val.length - 1] : '';
        if (val && i < boxes.length - 1) boxes[i + 1].focus();
        box.classList.toggle('filled', !!e.target.value);
        checkAllFilled();
    });

    box.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !box.value && i > 0) {
            boxes[i - 1].focus();
            boxes[i - 1].value = '';
            boxes[i - 1].classList.remove('filled');
        }
        if (e.key === 'ArrowLeft'  && i > 0)                boxes[i - 1].focus();
        if (e.key === 'ArrowRight' && i < boxes.length - 1) boxes[i + 1].focus();
    });

    box.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        if (pasted.length === 6) {
            boxes.forEach((b, idx) => { b.value = pasted[idx] || ''; b.classList.toggle('filled', !!b.value); });
            boxes[5].focus();
            checkAllFilled();
        }
    });
});

function checkAllFilled() {
    document.getElementById('verify-btn').disabled = !Array.from(boxes).every(b => b.value !== '');
}

boxes[0].focus();

// ─── COUNTDOWN TIMER ──────────────────────────────────────────────────────
const TOTAL = 5 * 60;
let remaining = TOTAL;
const timerText = document.getElementById('timer-text');
const timerBar  = document.getElementById('timer-bar');

const countdown = setInterval(() => {
    remaining--;
    const mins = Math.floor(remaining / 60);
    const secs = remaining % 60;
    timerText.textContent = `${mins}:${secs.toString().padStart(2,'0')}`;

    const pct = (remaining / TOTAL) * 100;
    timerBar.style.width = pct + '%';

    if (pct <= 20)      { timerBar.className = 'timer-bar danger';  timerText.style.color = '#ef4444'; }
    else if (pct <= 40) { timerBar.className = 'timer-bar warning'; timerText.style.color = '#d97706'; }

    if (remaining <= 0) {
        clearInterval(countdown);
        timerText.textContent = 'Expired';
        document.getElementById('verify-btn').disabled = true;
        document.getElementById('verify-btn').textContent = 'Code Expired — Click Resend';
        boxes.forEach(b => { b.disabled = true; b.classList.add('error'); });
    }
}, 1000);
</script>
</body>
</html>
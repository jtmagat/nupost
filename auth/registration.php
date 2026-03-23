<?php
session_start();
require_once "../config/database.php";

$error   = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name     = trim($_POST["name"]             ?? "");
    $email    = trim($_POST["email"]            ?? "");
    $password = trim($_POST["password"]         ?? "");
    $confirm  = trim($_POST["confirm_password"] ?? "");

    if (!$name || !$email || !$password || !$confirm) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $esc_email = mysqli_real_escape_string($conn, $email);
        $check     = mysqli_query($conn, "SELECT id FROM users WHERE email='$esc_email'");

        if (mysqli_num_rows($check) > 0) {
            $error = "Email is already registered.";
        } else {
            $esc_name = mysqli_real_escape_string($conn, $name);
            $esc_pw   = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));

            // Generate OTP
            $otp        = str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);
            $expires_at = date("Y-m-d H:i:s", strtotime("+5 minutes"));
            $esc_otp    = mysqli_real_escape_string($conn, $otp);

            // Insert user as unverified
            $insert = mysqli_query($conn,
                "INSERT INTO users (name, email, password, is_verified, created_at)
                 VALUES ('$esc_name','$esc_email','$esc_pw', 0, NOW())"
            );

            if ($insert) {
                $new_user_id = mysqli_insert_id($conn);

                // Save OTP
                mysqli_query($conn, "DELETE FROM otp_codes WHERE email='$esc_email'");
                mysqli_query($conn,
                    "INSERT INTO otp_codes (user_id, email, otp_code, expires_at)
                     VALUES ('$new_user_id','$esc_email','$esc_otp','$expires_at')"
                );

                // Try sending email — but don't block if it fails
                $sent = false;
                try {
                    require_once "send_otp.php";
                    $sent = sendOTPEmail($email, $name, $otp);
                } catch (Exception $e) {
                    $sent = false;
                }

                // Store in session for verify page
                $_SESSION["reg_user_id"] = $new_user_id;
                $_SESSION["reg_email"]   = $email;
                $_SESSION["reg_name"]    = $name;
                $_SESSION["reg_otp"]     = $otp; // shown on page if email fails
                $_SESSION["reg_sent"]    = $sent;

                header("Location: verify_register.php");
                exit();
            } else {
                $error = "Registration failed: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Register</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;300;400;700&family=Arimo:wght@400&display=swap" rel="stylesheet">
<style>
:root {
    --color-primary: #002366;
    --color-white: #ffffff;
    --color-input-border: rgba(0,0,0,0.2);
    --font-inter: 'Inter', sans-serif;
    --radius-card: 8px;
    --radius-input: 5px;
    --radius-btn: 5px;
    --shadow-card: 0px 10px 15px rgba(0,0,0,0.1), 0px 4px 6px rgba(0,0,0,0.1);
    --card-width: 448px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font-inter); }
.register { position: relative; width: 100%; min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.register__bg { position: absolute; inset: 0; z-index: 0; }
.register__bg img { width: 100%; height: 100%; object-fit: cover; }
.register__card {
    position: relative; z-index: 1; background: white;
    width: var(--card-width); border-radius: var(--radius-card);
    box-shadow: var(--shadow-card); padding: 32px;
    display: flex; flex-direction: column; align-items: center;
}
.register__logo { width: 200px; margin-bottom: 24px; }
.register__logo img { width: 100%; }
.register__form { width: 100%; display: flex; flex-direction: column; gap: 20px; }
.register__field { display: flex; flex-direction: column; gap: 8px; }
.register__label { font-size: 12px; font-weight: 300; }
.register__input {
    width: 100%; height: 44px; border: 1px solid var(--color-input-border);
    border-radius: var(--radius-input); padding: 0 12px; font-size: 12px; outline: none; transition: border-color .15s;
}
.register__input:focus { border-color: var(--color-primary); }
.register__button {
    width: 100%; height: 40px; background: var(--color-primary);
    color: white; border: none; border-radius: var(--radius-btn);
    font-weight: 700; font-size: 12px; cursor: pointer; transition: opacity .15s;
}
.register__button:hover { opacity: .9; }
.message { font-size: 12px; text-align: center; padding: 8px 12px; border-radius: 6px; }
.error   { color: #dc2626; background: #fee2e2; border: 1px solid #fecaca; }
.login-link { margin-top: 15px; font-size: 12px; text-align: center; }
.login-link a { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.login-link a:hover { text-decoration: underline; }
.verify-notice {
    display: flex; align-items: flex-start; gap: 10px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #1e40af; line-height: 1.5;
    width: 100%; margin-bottom: 20px;
}
.verify-notice svg { flex-shrink: 0; margin-top: 1px; }
@media(max-width: 768px) { .register__card { width: 92%; padding: 28px 24px; } }
</style>
</head>
<body>
<main class="register">
    <div class="register__bg">
        <img src="assets/nubg1.png" alt="NU Lipa Campus">
    </div>
    <section class="register__card">
        <div class="register__logo">
            <img src="assets/nupostlogo.png" alt="NUPost Logo">
        </div>

        <div class="verify-notice">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            A 6-digit verification code will be sent to your email after registration.
        </div>

        <form class="register__form" method="POST">
            <div class="register__field">
                <label class="register__label">FULL NAME:</label>
                <input class="register__input" type="text" name="name"
                       placeholder="Juan Dela Cruz" required
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <div class="register__field">
                <label class="register__label">EMAIL ADDRESS:</label>
                <input class="register__input" type="email" name="email"
                       placeholder="your.email@nu-lipa.edu.ph" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="register__field">
                <label class="register__label">PASSWORD:</label>
                <input class="register__input" type="password" name="password" required placeholder="Min. 6 characters">
            </div>
            <div class="register__field">
                <label class="register__label">CONFIRM PASSWORD:</label>
                <input class="register__input" type="password" name="confirm_password" required placeholder="Re-enter password">
            </div>
            <button class="register__button" type="submit">CREATE ACCOUNT</button>
            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </section>
</main>
</body>
</html>
<?php
// Place this in: NUPost/auth/send_otp.php
// Helper function used by login.php

require_once "../config/mailer_config.php";

// Support both Composer and manual install
if (file_exists("../vendor/autoload.php")) {
    require "../vendor/autoload.php";
} else {
    require "../vendor/phpmailer/PHPMailer.php";
    require "../vendor/phpmailer/SMTP.php";
    require "../vendor/phpmailer/Exception.php";
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendOTPEmail($to_email, $to_name, $otp_code) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = 'Your NUPost Verification Code';
        $mail->Body    = getOTPEmailTemplate($to_name, $otp_code);
        $mail->AltBody = "Hi $to_name, your NUPost verification code is: $otp_code. Valid for 5 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getOTPEmailTemplate($name, $otp) {
    $digits = str_split($otp);
    $boxes  = '';
    foreach ($digits as $d) {
        $boxes .= "<span style='
            display:inline-block;
            width:48px; height:56px;
            background:#f0f4ff;
            border:2px solid #002366;
            border-radius:10px;
            font-size:28px;
            font-weight:700;
            color:#002366;
            line-height:56px;
            text-align:center;
            margin:0 4px;
            font-family:monospace;
        '>$d</span>";
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5f6fa;font-family:Inter,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;padding:40px 0;">
<tr><td align="center">

  <!-- CARD -->
  <table width="520" cellpadding="0" cellspacing="0" style="background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

    <!-- HEADER -->
    <tr>
      <td style="background:#002366;padding:32px 40px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:white;letter-spacing:-0.5px;">NUPost</div>
        <div style="font-size:13px;color:rgba(255,255,255,0.7);margin-top:4px;">NU Lipa Social Media Request System</div>
      </td>
    </tr>

    <!-- SHIELD ICON -->
    <tr>
      <td style="padding:36px 40px 0;text-align:center;">
        <div style="
            width:64px;height:64px;
            background:#f0f4ff;
            border-radius:50%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            margin-bottom:20px;
        ">
          <svg width="32" height="32" fill="none" stroke="#002366" stroke-width="2" viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>

        <h1 style="font-size:20px;font-weight:700;color:#111827;margin:0 0 8px;">Verify Your Identity</h1>
        <p style="font-size:14px;color:#6b7280;margin:0 0 28px;line-height:1.6;">
          Hi <strong style="color:#111827;">' . htmlspecialchars($name) . '</strong>, use the code below to complete your login.
        </p>
      </td>
    </tr>

    <!-- OTP BOXES -->
    <tr>
      <td style="padding:0 40px 28px;text-align:center;">
        <div style="
            background:#f8faff;
            border:1.5px solid #e0e8ff;
            border-radius:12px;
            padding:28px 20px;
            display:inline-block;
        ">
          <div style="font-size:11px;font-weight:600;color:#6b7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:16px;">
            Your Verification Code
          </div>
          <div>' . $boxes . '</div>
        </div>
      </td>
    </tr>

    <!-- TIMER WARNING -->
    <tr>
      <td style="padding:0 40px 28px;text-align:center;">
        <div style="
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:#fef3c7;
            border:1px solid #fde68a;
            border-radius:8px;
            padding:10px 18px;
            font-size:13px;
            color:#92400e;
            font-weight:500;
        ">
          ⏱️ This code expires in <strong>5 minutes</strong>
        </div>
      </td>
    </tr>

    <!-- SECURITY NOTE -->
    <tr>
      <td style="padding:0 40px 32px;">
        <div style="
            background:#f9fafb;
            border-radius:10px;
            padding:16px 18px;
            font-size:12.5px;
            color:#6b7280;
            line-height:1.6;
        ">
          🔒 <strong style="color:#374151;">Security Notice:</strong>
          Never share this code with anyone. NUPost staff will never ask for your verification code.
          If you did not request this, please ignore this email.
        </div>
      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td style="background:#f5f6fa;padding:20px 40px;text-align:center;border-top:1px solid #e5e7eb;">
        <p style="font-size:12px;color:#9ca3af;margin:0;">
          © ' . date('Y') . ' NUPost — NU Lipa. This is an automated message, please do not reply.
        </p>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>';
}
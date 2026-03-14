<?php
session_start();
require_once "../config/database.php";

// Delete remember me token from DB
if (isset($_COOKIE["remember_token"])) {
    $token = mysqli_real_escape_string($conn, $_COOKIE["remember_token"]);
    mysqli_query($conn, "DELETE FROM remembered_devices WHERE token='$token'");

    // Delete the cookie by setting expiry in the past
    setcookie("remember_token", "", time() - 3600, "/", "", false, true);
}

// Destroy session
session_unset();
session_destroy();

header("Location: ../auth/login.php");
exit();
?>
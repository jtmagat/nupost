<?php

/* ==============================
   MYSQL DATABASE CONNECTION
============================== */

$host = "localhost";
$user = "root";
$password = "";
$database = "nupost_db";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}


/* ==============================
   FIREBASE CONFIGURATION
============================== */

define('FIREBASE_API_KEY', 'YOUR_API_KEY');
define('FIREBASE_AUTH_DOMAIN', 'YOUR_PROJECT.firebaseapp.com');
define('FIREBASE_PROJECT_ID', 'YOUR_PROJECT_ID');
define('FIREBASE_STORAGE_BUCKET', 'YOUR_PROJECT.appspot.com');


/* ==============================
   APP SETTINGS
============================== */

define('APP_NAME', 'NUPost Admin');
define('APP_ENV', 'development');

?>

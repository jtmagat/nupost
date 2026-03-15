<?php
session_start();
require_once "../config/database.php";

// Admin only
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    exit("Unauthorized");
}

$file = basename($_GET["file"] ?? "");
if (empty($file)) {
    http_response_code(400);
    exit("No file specified");
}

$path = "../uploads/" . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit("File not found");
}

// Force download
$mime = mime_content_type($path);
header("Content-Type: $mime");
header("Content-Disposition: attachment; filename=\"" . $file . "\"");
header("Content-Length: " . filesize($path));
header("Cache-Control: no-cache");
readfile($path);
exit();
?>
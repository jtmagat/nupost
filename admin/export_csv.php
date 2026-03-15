<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$report_type = $_GET["report_type"] ?? "";
$start_date  = $_GET["start_date"]  ?? "";
$end_date    = $_GET["end_date"]    ?? "";

$where_parts = ["1=1"];
if ($start_date !== "") $where_parts[] = "DATE(created_at) >= '" . mysqli_real_escape_string($conn, $start_date) . "'";
if ($end_date   !== "") $where_parts[] = "DATE(created_at) <= '" . mysqli_real_escape_string($conn, $end_date) . "'";
if ($report_type !== "") $where_parts[] = "category = '" . mysqli_real_escape_string($conn, $report_type) . "'";
$where = implode(" AND ", $where_parts);

$result = mysqli_query($conn, "SELECT request_id, title, requester, category, priority, status, description, platform, preferred_date, created_at FROM requests WHERE $where ORDER BY created_at DESC");

$filename = "nupost_report_" . date("Y-m-d") . ".csv";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$out = fopen("php://output", "w");

// Header row
fputcsv($out, ["Request ID", "Title", "Requester", "Category", "Priority", "Status", "Description", "Platform", "Preferred Date", "Created At"]);

while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($out, [
        "REQ-" . ($row["request_id"] ?? ""),
        $row["title"]          ?? "",
        $row["requester"]      ?? "",
        $row["category"]       ?? "",
        $row["priority"]       ?? "",
        $row["status"]         ?? "",
        $row["description"]    ?? "",
        $row["platform"]       ?? "",
        $row["preferred_date"] ?? "",
        $row["created_at"]     ?? "",
    ]);
}

fclose($out);
exit();
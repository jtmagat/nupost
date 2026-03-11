<?php
session_start();
require_once "../config/database.php";

/* ===============================
   DASHBOARD COUNTS
================================ */

// Initialize counters
$total = $pending = $approved = $posted = 0;

// Fetch status counts from requests table
$statusQuery = mysqli_query($conn, "SELECT status FROM requests");
if (!$statusQuery) {
    die("Failed to fetch request statuses: " . mysqli_error($conn));
}

while ($row = mysqli_fetch_assoc($statusQuery)) {
    $total++;
    if ($row['status'] == "pending") $pending++;
    if ($row['status'] == "approved") $approved++;
    if ($row['status'] == "posted") $posted++;
}

// Fetch recent requests for table (last 5)
$recent = mysqli_query($conn, "SELECT * FROM requests ORDER BY created_at DESC LIMIT 5");
if (!$recent) {
    die("Failed to fetch recent requests: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>NUPost Admin Dashboard</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI'}
        body{background:#f5f7fb;display:flex;min-height:100vh;}
        /* SIDEBAR */
        .sidebar{width:240px;background:linear-gradient(180deg,#003366,#0059b3);color:white;padding:20px;}
        .sidebar .logo {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 30px;
            color: white;
            text-align: center;
        }
        .sidebar ul{list-style:none;}
        .sidebar li{padding:12px;border-radius:6px;margin-bottom:8px;cursor:pointer;}
        .sidebar li:hover,.sidebar li.active{background:rgba(255,255,255,0.15);}
        /* MAIN */
        .main{flex:1;display:flex;flex-direction:column;}
        .topbar{
            background:white;
            padding:15px 25px;
            box-shadow:0 1px 6px rgba(0,0,0,0.05);
            display:flex;
            justify-content: space-between;
            align-items:center;
        }
        .topbar h2{margin:0;}
        .logout-btn{
            background:#ff4d4f;
            color:white;
            padding:8px 16px;
            border:none;
            border-radius:6px;
            cursor:pointer;
            text-decoration:none;
            font-weight:bold;
        }
        .content{padding:25px;}
        /* CARDS */
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:25px;}
        .card{background:white;padding:20px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        .card small{color:#777;}
        .card h2{margin-top:10px;}
        /* TABLE */
        .table-box{background:white;border-radius:12px;padding:20px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        table{width:100%;border-collapse:collapse;margin-top:15px;}
        th,td{padding:12px;border-bottom:1px solid #eee;text-align:left;}
        .badge{padding:4px 10px;border-radius:20px;font-size:12px;color:white;}
        .high{background:#ff7675;}
        .medium{background:#fbc531;color:black;}
        .low{background:#2ecc71;}
        .status{background:#e0e7ff;color:#3b5bdb;}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo">NUPost</div>
    <ul>
         <li class="active">Dashboard</li>
        <li onclick="location.href='request_management.php'">Request Management</li>
         <li onclick="location.href='scheduling_calendar.php'">Scheduling & Calendar</li>
       <li onclick="location.href='analytics.php'">Analytics</li>
        <li onclick="location.href='reports.php'">Reports</li>
        <li>Settings</li>
    </ul>
</div>

<div class="main">
    <div class="topbar">
        <h2>Admin Dashboard</h2>
        <a href="http://localhost/NUPost/auth/login.php" class="logout-btn">Logout</a>
    </div>

    <div class="content">
        <!-- Stats Cards -->
        <div class="stats">
            <div class="card">
                <small>Total Requests</small>
                <h2><?php echo $total; ?></h2>
            </div>
            <div class="card">
                <small>Pending</small>
                <h2><?php echo $pending; ?></h2>
            </div>
            <div class="card">
                <small>Approved</small>
                <h2><?php echo $approved; ?></h2>
            </div>
            <div class="card">
                <small>Posted</small>
                <h2><?php echo $posted; ?></h2>
            </div>
        </div>

        <!-- Recent Requests Table -->
        <div class="table-box">
            <h3>Recent Requests</h3>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Requester</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
                <?php while($row = mysqli_fetch_assoc($recent)){ ?>
                <tr>
                    <td><?php echo $row['request_id']; ?></td>
                    <td><?php echo $row['title']; ?></td>
                    <td><?php echo $row['requester']; ?></td>
                    <td><?php echo $row['category']; ?></td>
                    <td><span class="badge <?php echo strtolower($row['priority']); ?>"><?php echo $row['priority']; ?></span></td>
                    <td><span class="badge status"><?php echo $row['status']; ?></span></td>
                    <td><?php echo $row['created_at']; ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>

</body>
</html>

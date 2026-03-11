<?php
session_start();
require_once "../config/database.php";

// Fetch total requests
$totalRequests = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM requests"))['total'] ?? 0;

// Calculate completion rate (Posted / Total)
$postedCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS posted FROM requests WHERE status='posted'"))['posted'] ?? 0;
$completionRate = $totalRequests > 0 ? round(($postedCount/$totalRequests)*100,2) : 0;

// Calculate avg processing time in days (Created -> Posted)
$avgProcessingTime = 0;
$timeQuery = mysqli_query($conn, "SELECT DATEDIFF(created_at, created_at) as diff FROM requests WHERE status='posted'");
if(mysqli_num_rows($timeQuery)>0){
    $totalDays=0;
    $count=0;
    while($row=mysqli_fetch_assoc($timeQuery)){
        $totalDays+=$row['diff'];
        $count++;
    }
    $avgProcessingTime = $count>0 ? round($totalDays/$count,2) : 0;
}

// Get most common category
$commonCategory = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category, COUNT(*) as cnt FROM requests GROUP BY category ORDER BY cnt DESC LIMIT 1"))['category'] ?? 'N/A';

// Status distribution
$statusData = ['Seen'=>0,'Submitted'=>0,'Approved'=>0,'Scheduled'=>0,'Posted'=>0];
$statusQuery = mysqli_query($conn,"SELECT status, COUNT(*) as cnt FROM requests GROUP BY status");
while($row=mysqli_fetch_assoc($statusQuery)){
    $statusData[$row['status']]=$row['cnt'];
}

// Requests by category
$categoryData = [];
$categoryQuery = mysqli_query($conn,"SELECT category, COUNT(*) as cnt FROM requests GROUP BY category");
while($row=mysqli_fetch_assoc($categoryQuery)){
    $categoryData[$row['category']]=$row['cnt'];
}

// Priority distribution
$priorityData = ['High'=>0,'Medium'=>0,'Low'=>0,'Urgent'=>0];
$priorityQuery = mysqli_query($conn,"SELECT priority, COUNT(*) as cnt FROM requests GROUP BY priority");
while($row=mysqli_fetch_assoc($priorityQuery)){
    $priorityData[$row['priority']]=$row['cnt'];
}

// Top requesters
$topRequesters = [];
$topQuery = mysqli_query($conn,"SELECT requester, COUNT(*) as cnt FROM requests GROUP BY requester ORDER BY cnt DESC LIMIT 5");
while($row=mysqli_fetch_assoc($topQuery)){
    $topRequesters[] = $row;
}

// Recent requests
$recentRequests = mysqli_query($conn,"SELECT * FROM requests ORDER BY created_at DESC LIMIT 8");

?>

<!DOCTYPE html>
<html>
<head>
    <title>NUPost Reports</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI'}
        body{display:flex;min-height:100vh;background:#f5f7fb;}
        /* SIDEBAR */
        .sidebar{width:240px;background:linear-gradient(180deg,#003366,#0059b3);color:white;padding:20px;flex-shrink:0;}
        .sidebar .logo{font-size:28px;font-weight:bold;margin-bottom:30px;text-align:center;}
        .sidebar ul{list-style:none;}
        .sidebar li{padding:12px;border-radius:6px;margin-bottom:8px;cursor:pointer;}
        .sidebar li:hover,.sidebar li.active{background:rgba(255,255,255,0.15);}
        /* MAIN */
        .main{flex:1;display:flex;flex-direction:column;}
        .topbar{
            background:white;padding:15px 25px;box-shadow:0 1px 6px rgba(0,0,0,0.05);
            display:flex;justify-content:space-between;align-items:center;
        }
        .topbar h2{margin:0;}
        .logout-btn{
            background:#ff4d4f;color:white;padding:8px 16px;border:none;border-radius:6px;
            cursor:pointer;text-decoration:none;font-weight:bold;
        }
        .content{padding:25px;flex:1;}
        .report-config{display:flex;gap:15px;align-items:center;margin-bottom:25px;flex-wrap:wrap;}
        .report-config input, .report-config select{padding:8px 12px;border-radius:6px;border:1px solid #ccc;}
        .report-config button{padding:8px 16px;border-radius:6px;border:none;background:#003366;color:white;cursor:pointer;}
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:25px;}
        .card{background:white;padding:20px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        .card small{color:#777;}
        .card h2{margin-top:10px;}
        .charts{display:grid;grid-template-columns:1fr 1fr;gap:25px;margin-bottom:25px;}
        canvas{background:white;border-radius:12px;padding:20px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        .top-requesters{background:white;padding:20px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.05);margin-bottom:25px;}
        .top-requesters li{padding:6px 0;border-bottom:1px solid #eee;}
        .table-box{background:white;border-radius:12px;padding:20px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        table{width:100%;border-collapse:collapse;margin-top:15px;}
        th,td{padding:12px;border-bottom:1px solid #eee;text-align:left;}
        .badge{padding:4px 10px;border-radius:20px;font-size:12px;color:white;}
        .high{background:#ff7675;}
        .medium{background:#fbc531;color:black;}
        .low{background:#2ecc71;}
        .urgent{background:#ff9f43;}
        .status-approved{background:#2ecc71;}
        .status-submitted{background:#fbc531;color:black;}
        .status-posted{background:#3b5bdb;}
        .status-seen{background:#95a5a6;}
        .status-scheduled{background:#8e44ad;}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo">NUPost</div>
    <ul>
        <li onclick="location.href='index.php'">Dashboard</li>
        <li onclick="location.href='request_management.php'">Request Management</li>
        <li onclick="location.href='scheduling_calendar.php'">Scheduling & Calendar</li>
        <li onclick="location.href='analytics.php'">Analytics</li>
        <li class="active">Reports</li>
        <li>Settings</li>
    </ul>
</div>

<div class="main">
    <div class="topbar">
        <h2>Reports</h2>
        <a href="http://localhost/NUPost/admin/login.php" class="logout-btn">Logout</a>
    </div>

    <div class="content">
        <!-- Report Configuration -->
        <div class="report-config">
            <select>
                <option value="">Report Type</option>
            </select>
            <input type="date" placeholder="Start Date">
            <input type="date" placeholder="End Date">
            <button>Export as PDF</button>
            <button>Export as CSV</button>
        </div>

        <!-- Summary Cards -->
        <div class="cards">
            <div class="card"><small>Total Requests</small><h2><?php echo $totalRequests; ?></h2></div>
            <div class="card"><small>Completion Rate</small><h2><?php echo $completionRate; ?>%</h2></div>
            <div class="card"><small>Avg. Processing Time</small><h2><?php echo $avgProcessingTime; ?> Days</h2></div>
            <div class="card"><small>Most Common</small><h2><?php echo $commonCategory; ?></h2></div>
        </div>

        <!-- Charts -->
        <div class="charts">
            <canvas id="statusChart"></canvas>
            <canvas id="categoryChart"></canvas>
        </div>
        <div class="charts">
            <canvas id="priorityChart"></canvas>
            <div class="top-requesters">
                <h3>Top Requesters</h3>
                <ol>
                    <?php foreach($topRequesters as $r){ ?>
                        <li><?php echo $r['requester'].' ('.$r['cnt'].' requests)'; ?></li>
                    <?php } ?>
                </ol>
            </div>
        </div>

        <!-- Request Details Table -->
        <div class="table-box">
            <h3>Request Details</h3>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Requester</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                </tr>
                <?php while($row=mysqli_fetch_assoc($recentRequests)){ ?>
                <tr>
                    <td><?php echo $row['request_id']; ?></td>
                    <td><?php echo $row['title']; ?></td>
                    <td><?php echo $row['requester']; ?></td>
                    <td><?php echo $row['category']; ?></td>
                    <td><span class="badge <?php echo strtolower($row['priority']); ?>"><?php echo $row['priority']; ?></span></td>
                    <td>
                        <?php 
                        $statusClass='status-'.strtolower($row['status']); 
                        echo "<span class='badge $statusClass'>".$row['status']."</span>";
                        ?>
                    </td>
                </tr>
                <?php } ?>
            </table>
        </div>

    </div>
</div>

<script>
// Status Distribution Pie
new Chart(document.getElementById("statusChart"),{
    type:'pie',
    data:{
        labels:<?php echo json_encode(array_keys($statusData)); ?>,
        datasets:[{
            data:<?php echo json_encode(array_values($statusData)); ?>,
            backgroundColor:['#95a5a6','#fbc531','#2ecc71','#8e44ad','#3b5bdb']
        }]
    },
    options:{responsive:true}
});

// Category Bar
new Chart(document.getElementById("categoryChart"),{
    type:'bar',
    data:{
        labels:<?php echo json_encode(array_keys($categoryData)); ?>,
        datasets:[{
            data:<?php echo json_encode(array_values($categoryData)); ?>,
            backgroundColor:'#003366'
        }]
    },
    options:{responsive:true,scales:{y:{beginAtZero:true}}}
});

// Priority Pie
new Chart(document.getElementById("priorityChart"),{
    type:'pie',
    data:{
        labels:<?php echo json_encode(array_keys($priorityData)); ?>,
        datasets:[{
            data:<?php echo json_encode(array_values($priorityData)); ?>,
            backgroundColor:['#ff7675','#fbc531','#2ecc71','#ff9f43']
        }]
    },
    options:{responsive:true}
});
</script>

</body>
</html>

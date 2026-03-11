<?php
session_start();
require_once "../config/database.php";

// Fetch analytics totals from post_analytics
$reach = $engagement = $reactions = 0;

$q = mysqli_query($conn, "
    SELECT 
        SUM(reach) as reach, 
        SUM(engagement) as engagement, 
        SUM(reactions) as reactions 
    FROM post_analytics
");

if($q && mysqli_num_rows($q) > 0){
    $row = mysqli_fetch_assoc($q);
    $reach = $row['reach'] ?? 0;
    $engagement = $row['engagement'] ?? 0;
    $reactions = $row['reactions'] ?? 0;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>NUPost Analytics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin:0; padding:0; font-family:'Segoe UI'; }
        body { display:flex; min-height:100vh; background:#f5f7fb; }

        /* SIDEBAR */
        .sidebar {
            width:240px;
            background: linear-gradient(180deg,#003366,#0059b3);
            color:white;
            padding:20px;
            flex-shrink:0;
        }
        .sidebar .logo {
            font-size:28px;
            font-weight:bold;
            margin-bottom:30px;
            text-align:center;
        }
        .sidebar ul { list-style:none; padding-left:0; }
        .sidebar li {
            padding:12px;
            border-radius:6px;
            margin-bottom:8px;
            cursor:pointer;
            transition: background 0.2s;
        }
        .sidebar li:hover { background: rgba(255,255,255,0.15); }
        .sidebar li.active { background: rgba(255,255,255,0.15); font-weight:bold; }

        /* MAIN */
        .main { flex:1; display:flex; flex-direction:column; }

        /* TOPBAR */
        .topbar {
            background:white;
            padding:15px 25px;
            box-shadow:0 1px 6px rgba(0,0,0,0.05);
        }

        /* CONTENT */
        .content { padding:25px; }

        /* CARDS */
        .stats {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
            gap:20px;
            margin-bottom:25px;
        }
        .card {
            background:white;
            padding:20px;
            border-radius:12px;
            box-shadow:0 4px 10px rgba(0,0,0,0.05);
        }
        .card small { color:#777; }
        .card h2 { margin-top:10px; }

        /* CHART */
        #chart {
            margin-top:25px;
            background:white;
            border-radius:12px;
            padding:20px;
            box-shadow:0 4px 10px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo">NUPost</div>
    <ul>
        <li onclick="location.href='index.php'">Dashboard</li>
        <li onclick="location.href='request_management.php'">Request Management</li>
        <li onclick="location.href='scheduling_calendar.php'">Scheduling & Calendar</li>
        <li class="active">Analytics</li>
        <li onclick="location.href='reports.php'">Reports</li>
        <li>Settings</li>
    </ul>
</div>

<div class="main">
    <div class="topbar">
        <h2>Analytics</h2>
    </div>

    <div class="content">

        <!-- Stats Cards -->
        <div class="stats">
            <div class="card">
                <small>Total Reach</small>
                <h2><?php echo $reach; ?></h2>
            </div>
            <div class="card">
                <small>Total Engagement</small>
                <h2><?php echo $engagement; ?></h2>
            </div>
            <div class="card">
                <small>Total Reactions</small>
                <h2><?php echo $reactions; ?></h2>
            </div>
        </div>

        <!-- Bar Chart -->
        <canvas id="chart"></canvas>

    </div>
</div>

<script>
new Chart(document.getElementById("chart"), {
    type:'bar',
    data:{
        labels:['Reach','Engagement','Reactions'],
        datasets:[{
            label:'Analytics',
            backgroundColor:['#003366','#0059b3','#2ecc71'],
            data:[<?php echo $reach ?>,<?php echo $engagement ?>,<?php echo $reactions ?>]
        }]
    },
    options:{
        responsive:true,
        plugins:{ legend:{ display:false } },
        scales:{ y:{ beginAtZero:true } }
    }
});
</script>

</body>
</html>

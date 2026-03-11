<?php
session_start();
require_once "../config/database.php";

/* GET MONTH + YEAR */
$month = date("m");
$year = date("Y");

/* CALENDAR VALUES */
$firstDay = mktime(0,0,0,$month,1,$year);
$daysInMonth = date("t",$firstDay);
$dayOfWeek = date("w",$firstDay);

/* EVENTS QUERY */
$events = [];
$query = mysqli_query($conn,"SELECT * FROM scheduled_posts");
if($query){
    while($row = mysqli_fetch_assoc($query)){
        $events[$row['scheduled_date']][] = $row;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>NUPost Scheduling & Calendar</title>
    <style>
        *{ box-sizing:border-box; margin:0; padding:0; font-family:'Segoe UI'; }
        body{ display:flex; min-height:100vh; background:#f5f7fb; }

        /* SIDEBAR */
        .sidebar{
            width:240px;
            background: linear-gradient(180deg,#003366,#0059b3);
            color:white;
            padding:20px;
        }
        .sidebar .logo{
            font-size:28px;
            font-weight:bold;
            margin-bottom:30px;
            text-align:center;
        }
        .sidebar ul{ list-style:none; }
        .sidebar li{
            padding:12px;
            border-radius:6px;
            margin-bottom:8px;
            cursor:pointer;
        }
        .sidebar li:hover, .sidebar li.active{ background: rgba(255,255,255,0.15); }

        /* MAIN */
        .main{ flex:1; display:flex; flex-direction:column; }

        /* TOPBAR */
        .topbar{
            background:white;
            padding:15px 25px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 1px 6px rgba(0,0,0,0.05);
        }
        .topbar input{
            padding:8px 12px;
            width:280px;
            border-radius:6px;
            border:1px solid #ddd;
        }

        /* CONTENT */
        .content{ padding:25px; }

        /* CALENDAR */
        .calendar{
            background:white;
            border-radius:12px;
            padding:20px;
            box-shadow:0 4px 10px rgba(0,0,0,0.05);
            margin-bottom:25px;
        }
        .calendar-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
        }
        .calendar-header h2{ font-size:18px; }
        .calendar-grid{
            display:grid;
            grid-template-columns:repeat(7,1fr);
            border:1px solid #eee;
        }
        .day-name{
            background:#fafafa;
            padding:10px;
            font-weight:600;
            border-bottom:1px solid #eee;
            text-align:center;
        }
        .day{
            min-height:100px;
            border:1px solid #eee;
            padding:6px;
            position:relative;
        }
        .day-number{ font-size:12px; color:#777; }
        .event{
            margin-top:5px;
            padding:4px 6px;
            font-size:11px;
            background:#0d3b82;
            color:white;
            border-radius:4px;
        }

        /* UPCOMING POSTS */
        .upcoming{
            background:white;
            border-radius:12px;
            padding:20px;
            box-shadow:0 4px 10px rgba(0,0,0,0.05);
        }
        .upcoming-item{
            display:flex;
            justify-content:space-between;
            padding:10px 0;
            border-bottom:1px solid #eee;
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="logo">NUPost</div>
    <ul>
        <li onclick="location.href='index.php'">Dashboard</li>
        <li onclick="location.href='request_management.php'">Request Management</li>
        <li class="active">Scheduling & Calendar</li>
        <li onclick="location.href='analytics.php'">Analytics</li>
        <li onclick="location.href='reports.php'">Reports</li>
        <li>Settings</li>
    </ul>
</div>

<!-- MAIN -->
<div class="main">
    <div class="topbar">
        <input type="text" placeholder="Search..." />
        <div><strong>Admin</strong></div>
    </div>

    <div class="content">

        <!-- CALENDAR -->
        <div class="calendar">
            <div class="calendar-header">
                <h2><?php echo date("F Y",$firstDay); ?></h2>
            </div>
            <div class="calendar-grid">
                <div class="day-name">Sun</div>
                <div class="day-name">Mon</div>
                <div class="day-name">Tue</div>
                <div class="day-name">Wed</div>
                <div class="day-name">Thu</div>
                <div class="day-name">Fri</div>
                <div class="day-name">Sat</div>

                <?php
                $currentDay = 1;
                for($i=0;$i<42;$i++){
                    echo "<div class='day'>";
                    if($i >= $dayOfWeek && $currentDay <= $daysInMonth){
                        $date = $year."-".$month."-".str_pad($currentDay,2,"0",STR_PAD_LEFT);
                        echo "<div class='day-number'>$currentDay</div>";
                        if(isset($events[$date])){
                            foreach($events[$date] as $event){
                                echo "<div class='event'>".$event['title']."</div>";
                            }
                        }
                        $currentDay++;
                    }
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <!-- UPCOMING POSTS -->
        <div class="upcoming">
            <h3>Upcoming Posts (Next 7 Days)</h3>
            <?php
            $today = date("Y-m-d");
            $nextWeek = date("Y-m-d",strtotime("+7 days"));
            $upcoming = mysqli_query($conn,"
                SELECT * FROM scheduled_posts
                WHERE scheduled_date BETWEEN '$today' AND '$nextWeek'
                ORDER BY scheduled_date ASC
            ");

            if(mysqli_num_rows($upcoming) > 0){
                while($row = mysqli_fetch_assoc($upcoming)){
                    echo "
                    <div class='upcoming-item'>
                        <div>
                            <strong>".$row['title']."</strong><br>
                            <small>".$row['requester']."</small>
                        </div>
                        <div>
                            ".$row['scheduled_date']."<br>
                            <small>".$row['scheduled_time']."</small>
                        </div>
                    </div>";
                }
            } else{
                echo "<p>No scheduled posts yet.</p>";
            }
            ?>
        </div>

    </div>
</div>

</body>
</html>

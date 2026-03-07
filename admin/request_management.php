<?php
session_start();
include "../config/database.php";

// ===== Pagination & Search =====
$limit = 10; // requests per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search and filter
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build SQL query
$where = "1"; // default
if ($search !== '') {
    $where .= " AND (title LIKE '%$search%' OR requester LIKE '%$search%' OR category LIKE '%$search%')";
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending','approved','posted'])) {
    $where .= " AND status='$statusFilter'";
}

// Total count for pagination
$totalQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM requests WHERE $where");
$totalRow = mysqli_fetch_assoc($totalQuery);
$totalRequests = $totalRow['total'];
$totalPages = ceil($totalRequests / $limit);

// Fetch requests for current page
$result = mysqli_query($conn, "SELECT * FROM requests WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Request Management - NUPost</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI'}
        body{background:#f5f7fb;display:flex;min-height:100vh;}
        /* SIDEBAR */
        .sidebar{width:240px;background:linear-gradient(180deg,#003366,#0059b3);color:white;padding:20px;}
        .sidebar .logo {font-size:28px;font-weight:bold;margin-bottom:30px;color:white;text-align:center;}
        .sidebar ul{list-style:none;}
        .sidebar li{padding:12px;border-radius:6px;margin-bottom:8px;cursor:pointer;}
        .sidebar li:hover,.sidebar li.active{background:rgba(255,255,255,0.15);}
        /* MAIN */
        .main{flex:1;padding:25px;}
        .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
        .topbar h2{margin-bottom:0;}
        /* SEARCH & FILTER */
        .search-filter{display:flex;gap:10px;margin-bottom:20px;}
        .search-filter input, .search-filter select{padding:8px 12px;border-radius:6px;border:1px solid #ccc;}
        .search-filter button{padding:8px 12px;background:#0059b3;color:white;border:none;border-radius:6px;cursor:pointer;}
        .search-filter button:hover{background:#003366;}
        /* TABLE */
        .table-box{background:white;border-radius:12px;padding:20px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
        table{width:100%;border-collapse:collapse;margin-top:15px;}
        th,td{padding:12px;border-bottom:1px solid #eee;text-align:left;}
        .badge{padding:4px 10px;border-radius:20px;font-size:12px;color:white;}
        .high{background:#ff7675;}
        .medium{background:#fbc531;color:black;}
        .low{background:#2ecc71;}
        .status{background:#e0e7ff;color:#3b5bdb;}
        /* PAGINATION */
        .pagination{margin-top:20px;display:flex;gap:5px;flex-wrap:wrap;}
        .pagination a{padding:8px 12px;background:white;border:1px solid #ccc;border-radius:6px;text-decoration:none;color:#333;}
        .pagination a.active{background:#0059b3;color:white;border-color:#0059b3;}
        .pagination a:hover{background:#003366;color:white;border-color:#003366;}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo">NUPost</div>
    <ul>
        <li onclick="location.href='index.php'">Dashboard</li>
        <li class="active">Request Management</li>
        <li>Scheduling</li>
        <li>Analytics</li>
        <li>Reports</li>
        <li>Settings</li>
    </ul>
</div>

<div class="main">
    <div class="topbar">
        <h2>Request Management</h2>
    </div>

    <!-- Search & Filter -->
    <form method="get" class="search-filter">
        <input type="text" name="search" placeholder="Search by title, requester, category" value="<?php echo htmlspecialchars($search); ?>">
        <select name="status">
            <option value="">All Statuses</option>
            <option value="pending" <?php if($statusFilter=='pending') echo 'selected'; ?>>Pending</option>
            <option value="approved" <?php if($statusFilter=='approved') echo 'selected'; ?>>Approved</option>
            <option value="posted" <?php if($statusFilter=='posted') echo 'selected'; ?>>Posted</option>
        </select>
        <button type="submit">Apply</button>
    </form>

    <div class="table-box">
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
            <?php if(mysqli_num_rows($result) > 0): ?>
                <?php while($row = mysqli_fetch_assoc($result)): ?>
                <tr>
                    <td><?php echo $row['request_id']; ?></td>
                    <td><?php echo $row['title']; ?></td>
                    <td><?php echo $row['requester']; ?></td>
                    <td><?php echo $row['category']; ?></td>
                    <td><span class="badge <?php echo strtolower($row['priority']); ?>"><?php echo $row['priority']; ?></span></td>
                    <td><span class="badge status"><?php echo $row['status']; ?></span></td>
                    <td><?php echo $row['created_at']; ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;">No requests found.</td></tr>
            <?php endif; ?>
        </table>

        <!-- Pagination -->
        <div class="pagination">
            <?php for($i=1; $i<=$totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>" class="<?php if($i==$page) echo 'active'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    </div>
</div>

</body>
</html>

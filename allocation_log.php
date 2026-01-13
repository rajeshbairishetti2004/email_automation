<?php
// allocation_log.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);

$pdo = getPdo();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Get unique months for dropdown
$monthStmt = $pdo->query("SELECT DISTINCT month_year FROM allocation_log ORDER BY month_year DESC");
$months = $monthStmt->fetchAll(PDO::FETCH_COLUMN);

// Get date range from GET or default to current month
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-t');
$selectedMonth = $_GET['month'] ?? '';

// Build query for allocation logs with date range
$whereClauses = [];
$params = [];
$paramTypes = [];

if (!empty($selectedMonth)) {
    $whereClauses[] = "al.month_year = :month";
    $params[':month'] = $selectedMonth;
    $paramTypes[':month'] = PDO::PARAM_STR;
} else {
    // Use date range
    $whereClauses[] = "DATE(al.created_at) BETWEEN :from_date AND :to_date";
    $params[':from_date'] = $fromDate;
    $params[':to_date'] = $toDate;
    $paramTypes[':from_date'] = PDO::PARAM_STR;
    $paramTypes[':to_date'] = PDO::PARAM_STR;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get allocation logs
$query = "SELECT al.*, u.name as user_name, u.username 
          FROM allocation_log al 
          LEFT JOIN users u ON al.user_id = u.id 
          $whereSQL 
          ORDER BY al.created_at DESC";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, $paramTypes[$key] ?? PDO::PARAM_STR);
}
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get detailed statistics for the period
function getPeriodStatistics($pdo, $fromDate, $toDate, $selectedMonth = '') {
    $stats = [
        'total_allocations' => 0,
        'total_clients_processed' => 0,
        'total_clients_assigned' => 0,
        'total_clients_inserted' => 0,
        'total_clients_updated' => 0,
        'unique_importers' => 0,
        'unique_tags' => 0,
        'monthly_breakdown' => []
    ];
    
    $whereClauses = [];
    $params = [];
    
    if (!empty($selectedMonth)) {
        $whereClauses[] = "month_year = :month";
        $params[':month'] = $selectedMonth;
    } else {
        $whereClauses[] = "DATE(created_at) BETWEEN :from_date AND :to_date";
        $params[':from_date'] = $fromDate;
        $params[':to_date'] = $toDate;
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // Basic stats
    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM allocation_log $whereSQL");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $stats['total_allocations'] = $countStmt->fetchColumn();
    
    // Sum of all clients processed
    $sumStmt = $pdo->prepare("SELECT 
        SUM(clients_count) as total_clients,
        SUM(assigned_count) as total_assigned,
        SUM(inserted_count) as total_inserted,
        SUM(updated_count) as total_updated
        FROM allocation_log $whereSQL");
    
    foreach ($params as $key => $value) {
        $sumStmt->bindValue($key, $value);
    }
    $sumStmt->execute();
    $sums = $sumStmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total_clients_processed'] = $sums['total_clients'] ?? 0;
    $stats['total_clients_assigned'] = $sums['total_assigned'] ?? 0;
    $stats['total_clients_inserted'] = $sums['total_inserted'] ?? 0;
    $stats['total_clients_updated'] = $sums['total_updated'] ?? 0;
    
    // Unique importers
    $userStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM allocation_log $whereSQL");
    foreach ($params as $key => $value) {
        $userStmt->bindValue($key, $value);
    }
    $userStmt->execute();
    $stats['unique_importers'] = $userStmt->fetchColumn();
    
    // Unique tags
    $tagStmt = $pdo->prepare("SELECT COUNT(DISTINCT target_tag) FROM allocation_log $whereSQL");
    foreach ($params as $key => $value) {
        $tagStmt->bindValue($key, $value);
    }
    $tagStmt->execute();
    $stats['unique_tags'] = $tagStmt->fetchColumn();
    
    // Monthly breakdown
    if (empty($selectedMonth)) {
        $monthlyStmt = $pdo->prepare("
            SELECT 
                month_year,
                COUNT(*) as allocation_count,
                SUM(clients_count) as total_clients,
                SUM(assigned_count) as assigned_clients,
                SUM(inserted_count) as inserted_clients,
                SUM(updated_count) as updated_clients
            FROM allocation_log 
            WHERE DATE(created_at) BETWEEN :from_date AND :to_date
            GROUP BY month_year 
            ORDER BY month_year DESC
        ");
        $monthlyStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $stats['monthly_breakdown'] = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $stats;
}

$periodStats = getPeriodStatistics($pdo, $fromDate, $toDate, $selectedMonth);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Allocation Log & Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #eaeaea;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .top-bar {
            display: flex;
            align-items: center;
            padding: 12px 28px;
            background:rgba(148, 227, 241, 0.319);
            margin-bottom: 18px;
        }
        .top-bar img {
            height: 40px;
            vertical-align: middle;
            margin-right: 10px;
        }

        .brand-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #e3f2fd;
            border-radius: 8px;
            padding: 10px 24px 10px 14px;
        }
        .brand-wrapper img {
            height: 38px;
            width: auto;
        }
        
        .nav-brand {
            font-size: 1.18rem;
            font-weight: 600;
            color: #0f172a;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            white-space: nowrap;
            margin-left: 6px;
        }

        .nav-links {
            display: flex;
            gap: 32px;
            margin-left: 32px;
        }
        
        .nav-links a {
            text-decoration: none;
            font-weight: 600;
            color: #64748b;
            padding: 10px 0;
            border-bottom: 2.5px solid transparent;
            font-size: 1.08rem;
            transition: color 0.18s, border-color 0.18s;
        }
        
        .nav-links a.active {
            color: #2563eb;
            border-bottom: 2.5px solid #2563eb;
        }
        
        .nav-links a:hover {
            color: #2563eb;
            border-bottom: 2.5px solid #2563eb;
        }
        
        .nav-user {
            color: #2563eb;
            font-weight: 600;
            padding: 8px 22px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            background: #e3f2fd;
            border-radius: 50px;
            border: 1.5px solid #b3e5fc;
            position: relative;
            transition: 0.2s;
            font-size: 1.08rem;
            box-shadow: 0 2px 8px rgba(41, 182, 246, 0.10);
        }
        
        .nav-user:hover {
            background: #2563eb;
            color: #fff;
        }
        .profile-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 36px;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            min-width: 180px;
            z-index: 100;
            margin-right: 20px;
        }
        
        .profile-dropdown div {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            border-bottom: 1px solid #eee;
            padding: 8px 12px 5px;
        }
        
        .profile-dropdown a {
            display: block;
            padding: 8px 12px;
            text-align: right;
            color: #0288D1;
            font-weight: 600;
            text-decoration: none;
        }
        
        .profile-dropdown a.logout-link {
            color: #e53935 !important;
            font-weight: 700;
            background: none;
            transition: background 0.2s, color 0.2s;
        }
        
        .profile-dropdown a.logout-link:hover {
            background: #ffebee;
            color: #b71c1c !important;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        h2 {
            color: #2c3e50;
            font-size: 28px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0288D1;
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
        }
        
        .btn-apply {
            background: #0288D1;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            align-self: flex-end;
        }
        
        .btn-apply:hover {
            background: #0277bd;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            align-self: flex-end;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        /* Add additional styles for date range picker */
        .date-range-picker {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .date-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        .stat-card h4 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #0288D1;
        }
        .stat-details {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
        .state-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .state-draft { background: #ffc107; }
        .state-ready { background: #17a2b8; }
        .state-reviewed { background: #28a745; }
        .state-sent { background: #6f42c1; }
        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin-top: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #0288D1;
            border-radius: 4px;
        }
        .rm-stats {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .rm-stats table {
            width: 100%;
            border-collapse: collapse;
        }
        .rm-stats th {
            text-align: left;
            padding: 10px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
        }
        .rm-stats td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .rm-stats tr:hover {
            background: #f8f9fa;
        }
        
        .table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 16px 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 14px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            color: #666;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .timestamp {
            font-size: 12px;
            color: #999;
        }
        
        .action-type {
            font-weight: 600;
            color: #2c3e50;
        }
        
        /* New styles for clickable rows */
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .clickable-row:hover {
            background-color: #e3f2fd !important;
        }
        
        .btn-view {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        }
        
        .btn-view:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="top-bar">
                <img src="image.png" alt="Logo">
                <a href="upload.php" class="nav-brand">Finance Doctor</a>
            </div>
            <div class="nav-links">
                <a href="upload.php">Dashboard</a>
                <a href="view_saved_reports.php">All Reports</a>
                <a href="bulk_import.php">Bulk Allocate</a>
                <a href="allocation_log.php" class="active">Allocation Log</a>
            </div>
        </div>
        <div class="nav-user" style="position:relative;">
            <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
            <div id="profileDropdown" class="profile-dropdown" style="display:none;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding: 8px 12px 5px;">
                    <?= htmlspecialchars($userDesignation) ?>
                </div>
                <a href="profile.php" style="color:#0288D1; font-weight:600;">My Profile</a>
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2><i class="fas fa-chart-line"></i> Allocation Log & Analytics</h2>
        </div>
        
        <div class="filters">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Date Range</label>
                        <div class="date-range-picker">
                            <input type="text" name="from_date" class="date-input datepicker" 
                                   placeholder="From Date" value="<?php echo htmlspecialchars($fromDate); ?>">
                            <span>to</span>
                            <input type="text" name="to_date" class="date-input datepicker" 
                                   placeholder="To Date" value="<?php echo htmlspecialchars($toDate); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-filter"></i> Or Select Month</label>
                        <select name="month" onchange="this.form.submit()">
                            <option value="">Select Month</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?php echo htmlspecialchars($month); ?>" 
                                    <?php echo $selectedMonth == $month ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($month); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filter-row">
                    <button type="submit" class="btn-apply">
                        <i class="fas fa-chart-bar"></i> Generate Report
                    </button>
                    <button type="button" class="btn-reset" onclick="window.location.href='allocation_log.php'">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Summary Statistics -->
        
        
        <!-- Allocation Log Table -->
        <?php if (!empty($logs)): ?>
        <div class="table-container">
            <h4 style="padding: 20px 20px 0;"><i class="fas fa-history"></i> Allocation Log Details</h4>
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Month</th>
                        <th>Tag</th>
                        <th>Clients</th>
                        <th>Assigned</th>
                        <th>Details</th>
                        <th>File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr class="clickable-row" onclick="viewAllocationDetails(<?php echo $log['id']; ?>)">
                        <td>
                            <div><?php echo date('d M Y', strtotime($log['created_at'])); ?></div>
                            <div class="timestamp"><?php echo date('h:i A', strtotime($log['created_at'])); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($log['user_name'] ?: $log['username']); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($log['month_year']); ?></span></td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($log['target_tag']); ?></span></td>
                        <td><strong><?php echo (int)$log['clients_count']; ?></strong></td>
                        <td>
                            <?php 
                            $assignedPercent = $log['clients_count'] > 0 
                                ? round(($log['assigned_count'] / $log['clients_count']) * 100) 
                                : 0;
                            echo '<span class="badge badge-success">' . (int)$log['assigned_count'] . ' (' . $assignedPercent . '%)</span>';
                            ?>
                        </td>
                        <td>
                            <small>
                                +<?php echo (int)$log['inserted_count']; ?> new,
                                ↑<?php echo (int)$log['updated_count']; ?> updated
                            </small>
                        </td>
                        <td>
                            <?php if (!empty($log['file_name'])): ?>
                                <small><?php echo htmlspecialchars($log['file_name']); ?></small>
                            <?php else: ?>
                                <small class="timestamp">N/A</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-view" onclick="event.stopPropagation(); viewAllocationDetails(<?php echo $log['id']; ?>)">
                                <i class="fas fa-eye"></i> View Clients
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-chart-bar"></i>
            <h3>No allocation records found for selected period</h3>
            <p>Try selecting a different date range or month.</p>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr('.datepicker', {
            dateFormat: 'Y-m-d',
            allowInput: true
        });
        
        // Profile dropdown
        const profilePic = document.getElementById('profilePic');
        const profileDropdown = document.getElementById('profileDropdown');
        
        if (profilePic) {
            profilePic.addEventListener('click', function(e) {
                profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
                e.stopPropagation();
            });
            
            document.addEventListener('click', function() {
                profileDropdown.style.display = 'none';
            });
        }
        
        // Auto-submit when month is selected
        document.querySelector('select[name="month"]').addEventListener('change', function() {
            if (this.value) {
                // Clear date range when month is selected
                document.querySelector('input[name="from_date"]').value = '';
                document.querySelector('input[name="to_date"]').value = '';
                this.form.submit();
            }
        });
        
        // Clear month selection when date range is used
        document.querySelectorAll('.date-input').forEach(input => {
            input.addEventListener('change', function() {
                if (this.value) {
                    document.querySelector('select[name="month"]').value = '';
                }
            });
        });
        
        // View allocation details
        function viewAllocationDetails(allocationId) {
            window.open(`view_allocation_clients.php?id=${allocationId}`, '_blank');
        }
    </script>
</body>
</html>
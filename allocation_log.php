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

// Handle delete actions
$successMessage = '';
$errorMessage = '';

// Handle single delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_allocation'])) {
    $allocationId = (int)$_POST['allocation_id'];
    
    if ($allocationId > 0) {
        try {
            // Delete the allocation log entry
            $deleteStmt = $pdo->prepare("DELETE FROM allocation_log WHERE id = ?");
            $deleteStmt->execute([$allocationId]);
            
            $successMessage = "Allocation record deleted successfully!";
        } catch (Exception $e) {
            $errorMessage = "Error deleting allocation: " . $e->getMessage();
        }
    }
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
    
    if (!empty($selectedIds)) {
        try {
            // Sanitize IDs
            $selectedIds = array_filter(array_map('intval', $selectedIds));
            
            if (!empty($selectedIds)) {
                // Delete in batches for safety
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM allocation_log WHERE id IN ($placeholders)");
                $deleteStmt->execute($selectedIds);
                
                $affectedRows = $deleteStmt->rowCount();
                $successMessage = "Successfully deleted $affectedRows allocation record(s).";
            }
        } catch (Exception $e) {
            $errorMessage = "Error deleting allocations: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please select at least one allocation to delete.";
    }
}

// Check delete mode
$deleteMode = isset($_GET['delete_mode']) && $_GET['delete_mode'] === '1';

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
        
        /* Clickable rows */
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
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-view:hover {
            background: #218838;
        }
        
        .btn-delete {
            background: #e53935;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 5px;
        }
        
        .btn-delete:hover {
            background: #c62828;
        }
        
        /* Delete Mode Styles */
        .delete-mode-active { 
            background-color: #fff5f5 !important; 
        }
        
        .delete-mode-btn { 
            background-color: #ffebee; 
            color: #e53935; 
            border: 2px solid #e53935; 
            padding: 8px 16px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: 600; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            margin-left: 10px; 
            transition: all 0.2s; 
            text-decoration: none;
        }
        
        .delete-mode-btn:hover { 
            background-color: #e53935; 
            color: white; 
        }
        
        .cancel-delete-btn { 
            background-color: #6c757d; 
            color: white; 
            border: none; 
            padding: 8px 16px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: 600; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            margin-left: 10px; 
            text-decoration: none;
        }
        
        .cancel-delete-btn:hover { 
            background-color: #5a6268; 
        }
        
        .delete-confirm-modal { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.5); 
            z-index: 10000; 
            justify-content: center; 
            align-items: center; 
        }
        
        .delete-confirm-content { 
            background: white; 
            padding: 30px; 
            border-radius: 12px; 
            width: 500px; 
            max-width: 90%; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
        }
        
        .warning-icon { 
            color: #e53935; 
            font-size: 48px; 
            text-align: center; 
            margin-bottom: 20px; 
        }
        
        .client-checkbox { 
            width: 18px; 
            height: 18px; 
            cursor: pointer; 
        }
        
        .select-all-cell { 
            position: relative; 
        }
        
        .select-all-label { 
            position: absolute; 
            top: 50%; 
            left: 50%; 
            transform: translate(-50%, -50%); 
            font-size: 11px; 
            color: #666; 
            pointer-events: none; 
        }
        
        .bulk-actions-bar {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e9ecef;
        }
        
        .bulk-selection-info {
            font-weight: 600;
            color: #495057;
        }
        
        .bulk-actions-bar button {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        
        /* Action buttons container */
        .action-buttons {
            display: flex;
            gap: 5px;
            align-items: center;
        }
    </style>
</head>
<body class="<?php echo $deleteMode ? 'delete-mode-active' : ''; ?>">
    <?php include 'navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2><i class="fas fa-chart-line"></i> Allocation Log & Analytics</h2>
            <div>
                <?php if (!$deleteMode): ?>
                    <?php
                    // Build query string for delete mode
                    $deleteModeParams = [];
                    if ($fromDate != date('Y-m-01')) $deleteModeParams['from_date'] = $fromDate;
                    if ($toDate != date('Y-m-t')) $deleteModeParams['to_date'] = $toDate;
                    if ($selectedMonth) $deleteModeParams['month'] = $selectedMonth;
                    $deleteModeQuery = !empty($deleteModeParams) ? '?' . http_build_query($deleteModeParams) . '&delete_mode=1' : '?delete_mode=1';
                    ?>
                    <a href="allocation_log.php<?php echo $deleteModeQuery; ?>" 
                       class="delete-mode-btn">
                        <i class="fa-solid fa-trash"></i> Enable Delete Mode
                    </a>
                <?php else: ?>
                    <?php
                    $paramString = '';
                    $firstParam = true;
                    
                    // Helper function to add parameters
                    function addParam(&$paramString, &$firstParam, $name, $value) {
                        if (!empty($value)) {
                            $paramString .= $firstParam ? '?' : '&';
                            $paramString .= $name . '=' . urlencode($value);
                            $firstParam = false;
                        }
                    }
                    
                    addParam($paramString, $firstParam, 'from_date', $fromDate);
                    addParam($paramString, $firstParam, 'to_date', $toDate);
                    addParam($paramString, $firstParam, 'month', $selectedMonth);
                    ?>
                    
                    <a href="allocation_log.php<?php echo $paramString; ?>" 
                       class="cancel-delete-btn">
                        <i class="fa-solid fa-times"></i> Cancel Delete Mode
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($successMessage): ?>
            <div class="alert alert-success" id="successMessage">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($deleteMode): ?>
            <div class="alert alert-warning">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Delete Mode Active</strong>
                <p style="margin: 5px 0 0 0;">Select allocations using checkboxes, then click "Delete Selected" to remove them.</p>
            </div>
        <?php endif; ?>

        <div class="filters">
            <form method="GET" action="">
                <input type="hidden" name="delete_mode" value="<?php echo $deleteMode ? '1' : '0'; ?>">
                
                
                
            </form>
        </div>
        
        <!-- Bulk Actions Bar for Delete Mode -->
        <?php if ($deleteMode && !empty($logs)): ?>
        <form method="post" id="bulkDeleteForm">
            <input type="hidden" name="bulk_delete" value="1">
            <div class="bulk-actions-bar">
                <span class="bulk-selection-info">With Selected:</span>
                <button type="button" onclick="confirmDelete()" class="btn-delete" style="margin-left: 0;">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
                <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
            </div>
        <?php endif; ?>

        <!-- Allocation Log Table -->
        <?php if (!empty($logs)): ?>
        <div class="table-container">
            <h4 style="padding: 20px 20px 0;"><i class="fas fa-history"></i> Allocation Log Details</h4>
            <table>
                <thead>
                    <tr>
                        <?php if ($deleteMode): ?>
                        <th style="width: 40px;" class="select-all-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="client-checkbox" onclick="toggleSelectAll(this)">
                            <span class="select-all-label">All</span>
                        </th>
                        <?php endif; ?>
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
                    <tr class="clickable-row" onclick="!<?php echo $deleteMode ? 'true' : 'false'; ?> && viewAllocationDetails(<?php echo $log['id']; ?>)">
                        <?php if ($deleteMode): ?>
                        <td>
                            <input type="checkbox" class="client-checkbox delete-checkbox" name="selected_ids[]" value="<?php echo (int)$log['id']; ?>" onchange="updateSelectedCount()">
                        </td>
                        <?php endif; ?>
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
                            <div class="action-buttons">
                                <button class="btn-view" onclick="event.stopPropagation(); viewAllocationDetails(<?php echo $log['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (!$deleteMode): ?>
                                <button class="btn-delete" onclick="event.stopPropagation(); confirmSingleDelete(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars(addslashes($log['month_year'] . ' - ' . $log['target_tag'])); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($deleteMode): ?>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-chart-bar"></i>
            <h3>No allocation records found for selected period</h3>
            <p>Try selecting a different date range or month.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="delete-confirm-modal">
        <div class="delete-confirm-content">
            <div class="warning-icon">
                <i class="fa-solid fa-exclamation-triangle"></i>
            </div>
            <h3 style="color: #e53935; text-align: center; margin-bottom: 15px;">Confirm Deletion</h3>
            <p id="deleteConfirmMessage" style="text-align: center; margin-bottom: 25px;">
                Are you sure you want to delete <span id="deleteCount">0</span> selected allocation(s)?
            </p>
            <p style="text-align: center; color: #666; font-size: 14px; margin-bottom: 25px;">
                <i class="fa-solid fa-exclamation-circle"></i> This action cannot be undone. All allocation data will be permanently deleted.
            </p>
            <div style="display: flex; justify-content: center; gap: 15px;">
                <button type="button" onclick="closeDeleteModal()" style="padding: 10px 24px; border: 1px solid #ced4da; background: #fff; color: #555; border-radius: 6px; cursor: pointer; font-weight: 500;">Cancel</button>
                <button type="button" onclick="submitDelete()" style="padding: 10px 24px; border: none; background: #e53935; color: white; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
            </div>
        </div>
    </div>

    <!-- Single Delete Form (hidden) -->
    <form id="singleDeleteForm" method="post" style="display: none;">
        <input type="hidden" name="delete_allocation" value="1">
        <input type="hidden" name="allocation_id" id="deleteAllocationId" value="">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr('.datepicker', {
            dateFormat: 'Y-m-d',
            allowInput: true
        });
        
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
        
        // Toggle select all checkboxes
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.delete-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelectedCount();
        }
        
        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.delete-checkbox');
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            document.getElementById('selectedCount').textContent = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '') + ' selected';
            
            // Update select all checkbox state
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (!selectAllCheckbox) return;
            const allChecked = selectedCount > 0 && Array.from(checkboxes).every(c => c.checked);
            const someChecked = Array.from(checkboxes).some(c => c.checked);
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }
        
        // Show delete confirmation modal for bulk delete
        function confirmDelete() {
            const checkboxes = document.querySelectorAll('.delete-checkbox');
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (selectedCount === 0) {
                alert('Please select at least one allocation to delete.');
                return;
            }
            
            document.getElementById('deleteCount').textContent = selectedCount;
            document.getElementById('deleteConfirmMessage').innerHTML = 
                `Are you sure you want to delete <span id="deleteCount">${selectedCount}</span> selected allocation(s)?`;
            document.getElementById('deleteConfirmModal').style.display = 'flex';
        }
        
        // Show single delete confirmation
        function confirmSingleDelete(allocationId, allocationName) {
            if (confirm(`Are you sure you want to delete allocation: ${allocationName}?\n\nThis action cannot be undone.`)) {
                document.getElementById('deleteAllocationId').value = allocationId;
                document.getElementById('singleDeleteForm').submit();
            }
        }
        
        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').style.display = 'none';
        }
        
        // Submit delete form
        function submitDelete() {
            document.getElementById('bulkDeleteForm').submit();
        }
        
        // Update count on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.delete-checkbox')) {
                updateSelectedCount();
            }
            
            // Add checkbox event listeners
            const checkboxes = document.querySelectorAll('.delete-checkbox');
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
            
            // Auto-hide success message after 3 seconds
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.transition = 'opacity 0.5s ease';
                    successMessage.style.opacity = '0';
                    
                    // Remove from DOM after fade out
                    setTimeout(function() {
                        successMessage.style.display = 'none';
                    }, 500);
                }, 3000);
            }
        });
    </script>
</body>
</html>
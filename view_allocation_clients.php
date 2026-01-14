<?php
// view_allocation_clients.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');

$pdo = getPdo();

// Get allocation ID from query parameter
$allocationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($allocationId <= 0) {
    header('Location: allocation_log.php');
    exit;
}

// Get allocation details
$stmt = $pdo->prepare("
    SELECT al.*, u.name as user_name, u.username 
    FROM allocation_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.id = :id
");
$stmt->execute([':id' => $allocationId]);
$allocation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$allocation) {
    header('Location: allocation_log.php');
    exit;
}

// Get LATEST clients for this allocation - FIXED QUERY
$clientStmt = $pdo->prepare("
    SELECT 
        c1.id,
        c1.name as client_name,
        c1.email,
        c1.assigned_to,
        c1.review_assigned_to,
        c1.report_state,
        c1.month_year,
        c1.review_cycle,
        c1.total_amount as aum,
        c1.priority,
        c1.created_at,
        c1.updated_at,
        c1.allocation_id,
        c1.reviewed_at,
        c1.sent_at,
        c1.ready_at,
        c1.draft_at,
        rm.name as rm_name,
        reviewer.name as reviewer_name,
        DATE_FORMAT(c1.updated_at, '%d %b %Y %h:%i %p') as last_updated
    FROM clients c1
    LEFT JOIN users rm ON c1.assigned_to = rm.id
    LEFT JOIN users reviewer ON c1.review_assigned_to = reviewer.id
    WHERE 
        c1.month_year = :month_year 
        AND c1.review_cycle = :review_cycle
        AND c1.id = (
            SELECT MAX(c2.id)
            FROM clients c2
            WHERE c2.name = c1.name
            AND c2.month_year = :month_year2
            AND c2.review_cycle = :review_cycle2
        )
    ORDER BY c1.name
");
$clientStmt->execute([
    ':month_year' => $allocation['month_year'],
    ':review_cycle' => $allocation['target_tag'],
    ':month_year2' => $allocation['month_year'],
    ':review_cycle2' => $allocation['target_tag']
]);

$clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate client statistics
$totalClients = count($clients);
$assignedClients = array_filter($clients, function($c) {
    return !empty($c['assigned_to']);
});
$unassignedClients = $totalClients - count($assignedClients);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Allocation Clients - <?php echo htmlspecialchars($allocation['month_year']); ?> - <?php echo htmlspecialchars($allocation['target_tag']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        .back-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-button:hover {
            background: #5a6268;
        }
        
        .allocation-info {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .client-summary {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #0288D1;
        }
        
        .summary-text {
            font-size: 15px;
            color: #0c5460;
        }
        
        .summary-text strong {
            color: #0c5460;
        }
        
        .export-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        
        .export-btn:hover {
            background: #218838;
        }
        
        .client-table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
            overflow-x: auto;
        }
        
        .client-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        .client-table th {
            padding: 16px 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 14px;
            background: #f8f9fa;
            white-space: nowrap;
        }
        
        .client-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            color: #666;
            font-size: 14px;
            vertical-align: middle;
        }
        
        .client-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-draft { background: #fff3cd; color: #856404; }
        .status-ready { background: #d1ecf1; color: #0c5460; }
        .status-reviewed { background: #d4edda; color: #155724; }
        .status-sent { background: #e2d9f3; color: #6f42c1; }
        .status-pending { background: #f8d7da; color: #721c24; }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .priority-high { background: #f8d7da; color: #721c24; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-normal { background: #d1ecf1; color: #0c5460; }
        
        .btn-view-report {
            background: #0288D1;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .btn-view-report:hover {
            background: #0277bd;
        }
        
        .btn-view-history {
            background: #6c757d;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .btn-view-history:hover {
            background: #5a6268;
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
        
        h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .tag-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e3f2fd;
            color: #0288D1;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .aum-value {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .report-state-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            margin-right: 8px;
            font-size: 12px;
        }
        
        .report-state-draft { background: #fff3cd; color: #856404; }
        .report-state-ready { background: #d1ecf1; color: #0c5460; }
        .report-state-reviewed { background: #d4edda; color: #155724; }
        .report-state-sent { background: #e2d9f3; color: #6f42c1; }
        .report-state-pending { background: #f8d7da; color: #721c24; }
        
        .has-report {
            color: #28a745;
            font-weight: 600;
            font-size: 12px;
        }
        
        .no-report {
            color: #999;
            font-size: 12px;
        }

        /* =============================================
           ENHANCED HISTORY MODAL STYLES
        ============================================= */
        .history-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .history-modal-content {
            background: white;
            border-radius: 10px;
            width: 95%;
            max-width: 1400px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .history-modal-header {
            background: #0288D1;
            color: white;
            padding: 20px 25px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .history-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .history-modal-close {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            transition: background 0.2s;
        }
        
        .history-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .history-modal-body {
            padding: 25px;
        }
        
        /* Client Summary Header */
        .history-client-header {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 5px solid #0288D1;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .history-client-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .history-client-title i {
            color: #0288D1;
        }
        
        .history-client-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .history-client-item {
            display: flex;
            flex-direction: column;
        }
        
        .history-client-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .history-client-value {
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .history-client-email {
            font-size: 13px;
            color: #666;
            margin-top: 3px;
        }
        
        .history-stats-bar {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .history-stat-item {
            display: flex;
            flex-direction: column;
        }
        
        .history-stat-value {
            font-weight: 600;
            color: #0288D1;
            font-size: 14px;
        }
        
        .history-stat-label {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        /* History Table */
        .history-table-section {
            margin-top: 30px;
        }
        
        .history-table-title {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .history-table-title i {
            color: #0288D1;
        }
        
        .history-table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin-top: 15px;
        }
        
        .compact-history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
        }
        
        .compact-history-table th {
            background: #f8f9fa;
            padding: 14px 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 12px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .compact-history-table td {
            padding: 14px 15px;
            border-bottom: 1px solid #e9ecef;
            color: #666;
            font-size: 12px;
            vertical-align: middle;
        }
        
        .compact-history-table tr:hover {
            background: #f8f9fa;
        }
        
        .current-history-row {
            background-color: #e8f5e9 !important;
            border-left: 4px solid #4caf50;
        }
        
        /* Compact badges */
        .compact-status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }
        
        .compact-tag-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e3f2fd;
            color: #0288D1;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .compact-priority-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            min-width: 70px;
            text-align: center;
        }
        
        /* Date & Time */
        .date-time-cell {
            min-width: 150px;
        }
        
        .date-time-value {
            font-size: 12px;
            color: #666;
        }
        
        .date-time-value .date {
            font-weight: 600;
            color: #333;
            display: block;
        }
        
        .date-time-value .time {
            color: #888;
            font-size: 11px;
            margin-top: 2px;
        }
        
        /* Action buttons */
        .btn-view-history-report {
            background: #0288D1;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
            min-width: 80px;
            justify-content: center;
        }
        
        .btn-view-history-report:hover {
            background: #0277bd;
        }
        
        .btn-view-history-report:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .no-report-badge {
            font-size: 11px;
            color: #999;
            font-style: italic;
        }
        
        /* Attachments styling */
        .attachments-cell {
            max-width: 200px;
        }
        
        .attachments-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .attachment-item {
            margin-bottom: 5px;
            padding: 4px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .attachment-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        
        .attachment-download {
            color: #0288D1;
            text-decoration: none;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            background: #e3f2fd;
            transition: background 0.2s;
        }
        
        .attachment-download:hover {
            background: #bbdefb;
        }
        
        .no-attachments {
            font-size: 11px;
            color: #999;
            font-style: italic;
        }
        
        /* Filter controls */
        .history-filter-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: center;
            flex-wrap: wrap;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .history-filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            font-size: 12px;
            min-width: 140px;
        }
        
        .history-filter-btn {
            padding: 8px 15px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        
        .history-filter-btn:hover {
            background: #5a6268;
        }
        
        .history-result-count {
            font-size: 12px;
            color: #666;
            margin-left: auto;
            font-weight: 500;
        }
        
        /* Empty state */
        .empty-history {
            text-align: center;
            padding: 50px 20px;
            color: #999;
            grid-column: 1 / -1;
        }
        
        .empty-history i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .loading-spinner {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
            color: #0288D1;
        }
        
        .loading-spinner i {
            font-size: 24px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Clickable client name style */
        .client-name-link {
            color: #0288D1;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .client-name-link:hover {
            color: #01579b;
            text-decoration: underline;
        }
        
        .history-divider {
            border: none;
            height: 1px;
            background: #e9ecef;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container">
        <button class="back-button" onclick="window.location.href='allocation_log.php'">
            <i class="fas fa-arrow-left"></i> Back to Allocation Log
        </button>
        
        <h2>
            <i class="fas fa-users"></i> 
            Clients for Allocation: <?php echo htmlspecialchars($allocation['month_year']); ?> 
            <span class="tag-badge"><?php echo htmlspecialchars($allocation['target_tag']); ?></span>
        </h2>
        
        <div class="allocation-info">
            <div class="info-item">
                <span class="info-label">Imported by</span>
                <span class="info-value"><?php echo htmlspecialchars($allocation['user_name'] ?: $allocation['username']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Import Date & Time</span>
                <span class="info-value"><?php echo date('d M Y h:i A', strtotime($allocation['created_at'])); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Clients</span>
                <span class="info-value"><?php echo number_format($allocation['clients_count']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Assigned Clients</span>
                <span class="info-value">
                    <?php 
                    $assignedPercent = $allocation['clients_count'] > 0 
                        ? round(($allocation['assigned_count'] / $allocation['clients_count']) * 100) 
                        : 0;
                    echo number_format($allocation['assigned_count']) . " ($assignedPercent%)";
                    ?>
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">New / Updated</span>
                <span class="info-value">
                    +<?php echo number_format($allocation['inserted_count']); ?> new /
                    ↑<?php echo number_format($allocation['updated_count']); ?> updated
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">Source File</span>
                <span class="info-value"><?php echo htmlspecialchars($allocation['file_name'] ?? 'N/A'); ?></span>
            </div>
        </div>
        
        <?php if (!empty($clients)): ?>
        <div class="client-summary">
            <div class="summary-text">
                <strong>Client Summary:</strong> 
                Total: <?php echo $totalClients; ?> | 
                Assigned: <?php echo count($assignedClients); ?> | 
                Unassigned: <?php echo $unassignedClients; ?>
            </div>
            <div>
                <button class="export-btn" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            </div>
        </div>
        
        <div class="client-table-container">
            <table class="client-table">
                <thead>
                    <tr>
                        <th>Client Name</th>
                        <th>Email</th>
                        <th>Relationship Manager</th>
                        <th>Reviewed By</th>
                        <th>Review Cycle</th>
                        <th>AUM (₹)</th>
                    
                        
                        
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr>
                        <td>
                            <span class="client-name-link" 
                                  onclick="showClientHistory(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['client_name'] ?? 'Client', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($client['email'] ?? 'N/A', ENT_QUOTES); ?>')">
                                <?php echo htmlspecialchars($client['client_name'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($client['rm_name'] ?? 'Unassigned'); ?></td>
                        <td>
                            <?php 
                            if (!empty($client['reviewer_name'])) {
                                echo htmlspecialchars($client['reviewer_name']);
                            } else {
                                echo 'Unassigned';
                            }
                            ?>
                        </td>
                        <td><span class="tag-badge"><?php echo htmlspecialchars($client['review_cycle'] ?? 'N/A'); ?></span></td>
                        <td class="aum-value"> ₹<?= number_format(((float)($client['aum'] ?? 0)) / 10000000, 2); ?> Cr</td>
                        
                   
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>No clients found for this allocation</h3>
            <p>This allocation might not have any clients assigned yet, or the clients may not match the criteria.</p>
            <p style="margin-top: 10px; font-size: 14px;">
                <strong>Allocation Criteria:</strong><br>
                Month: <?php echo htmlspecialchars($allocation['month_year']); ?><br>
                Review Cycle: <?php echo htmlspecialchars($allocation['target_tag']); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="history-modal">
        <div class="history-modal-content">
            <div class="history-modal-header">
                <h3><i class="fas fa-history"></i> Client Review History</h3>
               <button class="history-modal-close" onclick="closeHistoryModal()">×</button>
            </div>
            <div class="history-modal-body" id="historyModalBody">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
<script>
    // Function for main table View Report button
    function viewReport(clientId) {
        // Use 'id' parameter as per your example URL
        window.open(`view_report.php?id=${clientId}`, '_blank');
    }
    
    function exportToCSV() {
        const table = document.querySelector('.client-table');
        let csv = [];
        
        const headers = [];
        table.querySelectorAll('thead th').forEach(header => {
            headers.push(header.textContent.trim());
        });
        csv.push(headers.join(','));
        
        table.querySelectorAll('tbody tr').forEach(row => {
            const rowData = [];
            row.querySelectorAll('td').forEach(cell => {
                const text = cell.textContent.trim().replace(/,/g, ';');
                rowData.push(`"${text}"`);
            });
            csv.push(rowData.join(','));
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', `allocation_clients_<?php echo htmlspecialchars($allocation['month_year']); ?>_<?php echo htmlspecialchars($allocation['target_tag']); ?>.csv`);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // SIMPLE UTILITY FUNCTION
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // SIMPLIFIED History Function
    function showClientHistory(clientId, clientName, clientEmail) {
        console.log('Showing history for client:', {clientId, clientName});
        
        const modal = document.getElementById('historyModal');
        const modalBody = document.getElementById('historyModalBody');
        
        if (!modal || !modalBody) {
            alert('History modal not found. Please refresh the page.');
            return;
        }
        
        // Show loading message
        modalBody.innerHTML = `
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <div style="margin-left: 15px;">
                    <div>Loading complete client history...</div>
                    <div style="font-size: 12px; margin-top: 5px; color: #666;">
                        Client: ${escapeHtml(clientName)}<br>
                        ID: ${clientId}
                    </div>
                </div>
            </div>
        `;
        
        // Show modal
        modal.style.display = 'flex';
        
        // Fetch data - using client name instead of ID to get ALL records
        const apiUrl = `get_client_history.php?client_name=${encodeURIComponent(clientName)}&include_attachments=1`;
        console.log('Fetching all records for client:', clientName);
        
        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Received complete history data:', data);
                if (data.success) {
                    displayCompleteHistory(data, clientName, clientEmail, clientId);
                } else {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ff9800; margin-bottom: 20px;"></i>
                            <h3>No History Found</h3>
                            <p>${data.error || 'No review history available for this client.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #dc3545; margin-bottom: 20px;"></i>
                        <h3>Error Loading History</h3>
                        <p>Could not load client history. Please try again.</p>
                        <p style="font-size: 12px; color: #666; margin-top: 10px;">Error: ${error.message}</p>
                    </div>
                `;
            });
    }
    
    function displayCompleteHistory(data, clientName, clientEmail, currentClientId) {
        const modalBody = document.getElementById('historyModalBody');
        
        // Calculate statistics
        const totalRecords = data.history ? data.history.length : 0;
        let totalAUM = 0;
        let years = new Set();
        let cycles = new Set();
        let statuses = new Set();
        
        if (data.history && data.history.length > 0) {
            data.history.forEach(review => {
                totalAUM += parseFloat(review.aum || 0);
                if (review.month_year) {
                    const year = review.month_year.split('-')[0];
                    if (year) years.add(year);
                }
                if (review.review_cycle) cycles.add(review.review_cycle);
                if (review.report_state) statuses.add(review.report_state);
            });
        }
        
        // Get RM and Reviewer names from current record
        let currentRM = 'Unassigned';
        let currentReviewer = 'Unassigned';
        let currentAUM = '₹0.00';
        if (data.history && data.history.length > 0) {
            const currentRecord = data.history.find(r => parseInt(r.id) === parseInt(currentClientId));
            if (currentRecord) {
                currentRM = currentRecord.rm_name || 'Unassigned';
                currentReviewer = currentRecord.reviewer_name || 'Unassigned';
                currentAUM = parseFloat(currentRecord.aum || 0) > 0 
                    ? `₹${(parseFloat(currentRecord.aum || 0)/10000000).toFixed(2)} Cr` 
                    : '₹0.00';
            }
        }
        
        let html = `
            <!-- CLIENT HEADER SECTION -->
            <div class="history-client-header">
                <div class="history-client-title">
                    <i class="fas fa-user-circle"></i>
                    Client Overview
                </div>
                
                <div class="history-client-grid">
                    <div class="history-client-item">
                        <span class="history-client-label">Client Name</span>
                        <span class="history-client-value">${escapeHtml(clientName)}</span>
                        <span class="history-client-email">${escapeHtml(clientEmail || 'N/A')}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Relationship Manager</span>
                        <span class="history-client-value">${escapeHtml(currentRM)}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Reviewed By</span>
                        <span class="history-client-value">${escapeHtml(currentReviewer)}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Current AUM</span>
                        <span class="history-client-value">${currentAUM}</span>
                    </div>
                </div>
                
                <div class="history-stats-bar">
                    <div class="history-stat-item">
                        <span class="history-stat-value">${totalRecords}</span>
                        <span class="history-stat-label">Total Records</span>
                    </div>
                    
                    <div class="history-stat-item">
                        <span class="history-stat-value">${Array.from(years).length}</span>
                        <span class="history-stat-label">Years Covered</span>
                    </div>
                    
                    <div class="history-stat-item">
                        <span class="history-stat-value">${currentClientId}</span>
                        <span class="history-stat-label">Current ID</span>
                    </div>
                    
                    
                </div>
            </div>
            
            <hr class="history-divider">
            
        
        `;
        
        if (data.history && data.history.length > 0) {
            
            html += `<div class="history-table-container">`;
            html += `<table class="compact-history-table" id="historyTable">`;
            html += `<thead>
                <tr>
                    <th>ID</th>
                    <th>Month/Year</th>
                    <th>Review Cycle</th>
                    <th>RM</th>
                    <th>Reviewed By</th>
                    <th>AUM (₹)</th>
                    <th>Status</th>
                    <th>Date & Time</th>
                    <th>Actions</th>
                </tr>
            </thead><tbody>`;
            
            // Sort by created_at descending (newest first)
            data.history.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            
            data.history.forEach(review => {
                const status = review.report_state || 'pending';
                const statusClass = `compact-status-badge status-${status}`;
                const statusText = status.charAt(0).toUpperCase() + status.slice(1);
                
                // Check if this is the current row
                const isCurrentRow = parseInt(review.id) === parseInt(currentClientId);
                const rowClass = isCurrentRow ? 'current-history-row' : '';
                
                // Priority badge
                const priority = review.priority || 'Normal';
                const priorityClass = `compact-priority-badge priority-${priority.toLowerCase()}`;
                
                // Format date and time
                const createdDate = review.created_at ? new Date(review.created_at) : null;
                let dateTimeHtml = 'N/A';
                if (createdDate) {
                    const dateStr = createdDate.toLocaleDateString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                    const timeStr = createdDate.toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    }).toLowerCase();
                    dateTimeHtml = `
                        <div class="date-time-value">
                            <span class="date">${dateStr}</span>
                            <span class="time">${timeStr}</span>
                        </div>
                    `;
                }
                
                // Format AUM
                const aumValue = parseFloat(review.aum || 0);
                const aumDisplay = aumValue > 0 ? `₹${(aumValue/10000000).toFixed(2)} Cr` : '₹0.00';
                
                // View button - only show if report exists
                const canView = status !== 'pending';
                // Use direct onclick with 'id' parameter
                const viewButton = canView ? 
                    `<button class="btn-view-history-report" onclick="window.open('view_report.php?id=${review.id}', '_blank')">
                        <i class="fas fa-eye"></i> View Report
                    </button>` :
                    `<span class="no-report-badge">No report</span>`;
                
                html += `
                    <tr class="${rowClass}" data-cycle="${review.review_cycle || ''}" data-status="${status}">
                        <td>
                            ${review.id} 
                            ${isCurrentRow ? '<i class="fas fa-star" style="color: #ff9800; margin-left: 5px;" title="Current Record"></i>' : ''}
                        </td>
                        <td><strong>${escapeHtml(review.month_year || 'N/A')}</strong></td>
                        <td><span class="compact-tag-badge">${escapeHtml(review.review_cycle || 'N/A')}</span></td>
                        <td>${escapeHtml(review.rm_name || 'Unassigned')}</td>
                        <td>${escapeHtml(review.reviewer_name || 'Unassigned')}</td>
                        <td style="font-weight: 600;">${aumDisplay}</td>
                        <td><span class="${statusClass}">${statusText}</span></td>
                        <td class="date-time-cell">${dateTimeHtml}</td>
                        <td>${viewButton}</td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div>`;
        } else {
            html += `
                <div class="empty-history">
                    <i class="fas fa-history"></i>
                    <h3>No Review History</h3>
                    <p>This client doesn't have any review history yet.</p>
                </div>
            `;
        }
        
        html += `</div>`; // Close history-table-section
        
        modalBody.innerHTML = html;
    }
    
    function filterHistoryTable() {
        const cycleFilter = document.getElementById('cycleFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('#historyTable tbody tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const cycle = row.getAttribute('data-cycle');
            const status = row.getAttribute('data-status');
            let show = true;
            
            if (cycleFilter && cycle !== cycleFilter) {
                show = false;
            }
            if (statusFilter && status !== statusFilter) {
                show = false;
            }
            
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        
        // Update count display
        const countElement = document.getElementById('resultCount');
        if (countElement) {
            countElement.innerHTML = `Showing ${visibleCount} records`;
        }
    }
    
    function resetHistoryFilters() {
        document.getElementById('cycleFilter').value = '';
        document.getElementById('statusFilter').value = '';
        filterHistoryTable();
    }
    
    // Function for history modal View Report button
    function viewReportFromHistory(clientId) {
        // Use 'id' parameter as per your example URL
        window.open(`view_report.php?id=${clientId}`, '_blank');
    }
    
    function closeHistoryModal() {
        document.getElementById('historyModal').style.display = 'none';
        document.getElementById('historyModalBody').innerHTML = '';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('historyModal');
        if (event.target === modal) {
            closeHistoryModal();
        }
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeHistoryModal();
        }
    });
</script>
</body>
</html>
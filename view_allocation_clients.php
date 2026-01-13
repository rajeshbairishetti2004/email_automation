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

// Get clients for this allocation
// Note: allocation_log.target_tag maps to clients.review_cycle
$clientStmt = $pdo->prepare("
    SELECT 
        c.id,
        c.name as client_name,
        c.email,
        c.assigned_to,
        c.review_assigned_to,
        c.report_state,
        c.month_year,
        c.review_cycle,
        c.total_amount as aum,
        c.priority,
        c.created_at,
        c.updated_at,
        c.allocation_id,
        c.reviewed_at,
        c.sent_at,
        c.ready_at,
        c.draft_at,
        rm.name as rm_name,
        reviewer.name as reviewer_name,
        DATE_FORMAT(c.updated_at, '%d %b %Y %h:%i %p') as last_updated
    FROM clients c
    LEFT JOIN users rm ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    WHERE 
        c.month_year = :month_year 
        AND c.review_cycle = :review_cycle
    ORDER BY c.name
");
$clientStmt->execute([
    ':month_year' => $allocation['month_year'],
    ':review_cycle' => $allocation['target_tag']
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

        /* History Modal Styles */
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
            width: 90%;
            max-width: 1000px;
            max-height: 85vh;
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
        
        .client-info-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #0288D1;
        }
        
        .client-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .client-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .client-info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .client-info-value {
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .history-table th {
            background: #f8f9fa;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 13px;
            white-space: nowrap;
        }
        
        .history-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e9ecef;
            color: #666;
            font-size: 13px;
            vertical-align: top;
        }
        
        .history-table tr:hover {
            background: #f8f9fa;
        }
        
        .history-status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .history-year-section {
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .history-year-header {
            background: #e3f2fd;
            padding: 12px 20px;
            font-weight: 600;
            color: #0288D1;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .history-year-summary {
            font-size: 13px;
            color: #666;
            font-weight: normal;
        }
        
        .no-history {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .no-history i {
            font-size: 36px;
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
        
        .history-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .btn-view-report-small {
            background: #0288D1;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s;
        }
        
        .btn-view-report-small:hover {
            background: #0277bd;
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
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($client['client_name'] ?? 'N/A'); ?></td>
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
                        <td class="aum-value">₹<?php echo number_format($client['aum'] ?? 0, 2); ?> Cr</td>
                        <td>
                            <?php 
                            $priority = $client['priority'] ?? 'Normal';
                            $priorityClass = 'priority-' . strtolower($priority);
                            echo "<span class='priority-badge $priorityClass'>$priority</span>";
                            ?>
                        </td>
                        <td>
                            <?php 
                            $status = $client['report_state'] ?? 'pending';
                            $statusClass = 'status-' . $status;
                            $statusText = ucfirst($status);
                            
                            $icon = '';
                            switch($status) {
                                case 'draft': $icon = 'fa-edit'; break;
                                case 'ready': $icon = 'fa-check-circle'; break;
                                case 'reviewed': $icon = 'fa-eye'; break;
                                case 'sent': $icon = 'fa-paper-plane'; break;
                                case 'pending': $icon = 'fa-clock'; break;
                                default: $icon = 'fa-file';
                            }
                            
                            echo "<span class='report-state-icon report-state-$status'><i class='fas $icon'></i></span>";
                            echo "<span class='status-badge $statusClass'>$statusText</span>";
                            ?>
                        </td>
                        <td><?php echo $client['last_updated'] ?? 'N/A'; ?></td>
                        <td>
                            <div class="action-buttons">
                                <?php 
                                $canViewReport = false;
                                $reportStatus = $client['report_state'] ?? 'pending';
                                
                                if (in_array($reportStatus, ['ready', 'reviewed', 'sent', 'draft'])) {
                                    $canViewReport = true;
                                }
                                
                                if ($canViewReport): ?>
                                <button class="btn-view-report" onclick="viewReport(<?php echo $client['id']; ?>)">
                                    <i class="fas fa-file-pdf"></i> View Report
                                </button>
                                <?php else: ?>
                                <span class="no-report">No report</span>
                                <?php endif; ?>
                                
                                <!-- Always show History button -->
                                <button class="btn-view-history" onclick="showClientHistory(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['client_name'] ?? 'Client'); ?>', '<?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?>')">
                                    <i class="fas fa-history"></i> History
                                </button>
                            </div>
                        </td>
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
        function viewReport(clientId) {
            window.open(`view_report.php?client_id=${clientId}`, '_blank');
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
        
        // History Modal Functions
        function showClientHistory(clientId, clientName, clientEmail) {
            const modal = document.getElementById('historyModal');
            const modalBody = document.getElementById('historyModalBody');
            
            // Show loading state
            modalBody.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner"></i> Loading client history...
                </div>
            `;
            
            modal.style.display = 'flex';
            
            // Fetch client history via AJAX
            fetch(`get_client_history.php?client_id=${clientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderClientHistory(data, clientName, clientEmail);
                    } else {
                        modalBody.innerHTML = `
                            <div class="no-history">
                                <i class="fas fa-history"></i>
                                <h3>No History Found</h3>
                                <p>No review history found for this client.</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    modalBody.innerHTML = `
                        <div class="no-history">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Error Loading History</h3>
                            <p>Could not load client history. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        function renderClientHistory(data, clientName, clientEmail) {
            const modalBody = document.getElementById('historyModalBody');
            let html = `
                <div class="client-info-summary">
                    <div class="client-info-row">
                        <div class="client-info-item">
                            <span class="client-info-label">Client Name</span>
                            <span class="client-info-value">${escapeHtml(clientName)}</span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Email</span>
                            <span class="client-info-value">${escapeHtml(clientEmail)}</span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Total Reviews</span>
                            <span class="client-info-value">${data.total_reviews || 0}</span>
                        </div>
                    </div>
                    <div class="client-info-row">
                        <div class="client-info-item">
                            <span class="client-info-label">Years Covered</span>
                            <span class="client-info-value">${data.years_covered || 'N/A'}</span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Latest Review</span>
                            <span class="client-info-value">${data.latest_review_date || 'N/A'}</span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">First Review</span>
                            <span class="client-info-value">${data.first_review_date || 'N/A'}</span>
                        </div>
                    </div>
                </div>
            `;
            
            if (data.history && data.history.length > 0) {
                // Group by year
                const groupedByYear = {};
                data.history.forEach(review => {
                    const year = new Date(review.month_year + '-01').getFullYear();
                    if (!groupedByYear[year]) {
                        groupedByYear[year] = [];
                    }
                    groupedByYear[year].push(review);
                });
                
                // Sort years in descending order
                const years = Object.keys(groupedByYear).sort((a, b) => b - a);
                
                years.forEach(year => {
                    const yearReviews = groupedByYear[year];
                    const sortedReviews = yearReviews.sort((a, b) => {
                        return new Date(b.month_year + '-01') - new Date(a.month_year + '-01');
                    });
                    
                    html += `
                        <div class="history-year-section">
                            <div class="history-year-header">
                                <span>${year}</span>
                                <span class="history-year-summary">${sortedReviews.length} review(s)</span>
                            </div>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Review Cycle</th>
                                        <th>RM</th>
                                        <th>Reviewer</th>
                                        <th>AUM (₹)</th>
                                        <th>Status</th>
                                        <th>Dates</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    sortedReviews.forEach(review => {
                        const month = new Date(review.month_year + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                        const statusClass = `history-status-badge status-${review.report_state || 'pending'}`;
                        const statusText = (review.report_state || 'pending').charAt(0).toUpperCase() + (review.report_state || 'pending').slice(1);
                        
                        // Format dates
                        const dates = [];
                        if (review.draft_at) dates.push(`Draft: ${formatDate(review.draft_at)}`);
                        if (review.ready_at) dates.push(`Ready: ${formatDate(review.ready_at)}`);
                        if (review.reviewed_at) dates.push(`Reviewed: ${formatDate(review.reviewed_at)}`);
                        if (review.sent_at) dates.push(`Sent: ${formatDate(review.sent_at)}`);
                        
                        html += `
                            <tr>
                                <td><strong>${month}</strong></td>
                                <td><span class="tag-badge">${escapeHtml(review.review_cycle || 'N/A')}</span></td>
                                <td>${escapeHtml(review.rm_name || 'Unassigned')}</td>
                                <td>${escapeHtml(review.reviewer_name || 'Unassigned')}</td>
                                <td>₹${parseFloat(review.aum || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}Cr</td>
                                <td><span class="${statusClass}">${statusText}</span></td>
                                <td><small>${dates.join('<br>') || 'No dates'}</small></td>
                                <td>
                                    <div class="history-actions">
                                        ${review.report_state && review.report_state !== 'pending' ? 
                                            `<button class="btn-view-report-small" onclick="viewReport(${review.id})">
                                                <i class="fas fa-eye"></i> View
                                            </button>` : 
                                            '<span class="no-report">No report</span>'
                                        }
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                    
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="no-history">
                        <i class="fas fa-history"></i>
                        <h3>No Review History Found</h3>
                        <p>This client doesn't have any review history yet.</p>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
        }
        
        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('historyModal');
            if (event.target === modal) {
                closeHistoryModal();
            }
        }
        
        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', { 
                day: '2-digit', 
                month: 'short', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    </script>
</body>
</html>
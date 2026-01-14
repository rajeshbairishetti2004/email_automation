<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear
// - Added: Bulk reassignment functionality and split owner columns
// - Added: Delete mode with checkboxes

require_once 'auth.php';
requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$myId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0);

require_once 'db_config.php';

$pdo = getPdo();
$successMessage = '';
$errorMessage = '';

// Initialize deleteMode variable here
$deleteMode = isset($_GET['delete_mode']) && $_GET['delete_mode'] === '1';

// Handle POST request for bulk reassignment and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    if ($_POST['action_type'] === 'reassign') {
        try {
            $newOwnerId = 0;
            if (isset($_POST['new_owner_id'])) {
                $newOwnerId = (int)$_POST['new_owner_id'];
            } elseif (isset($_POST['new_owner'])) {
                $newOwnerId = (int)$_POST['new_owner'];
            }
            $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
            
            if ($newOwnerId <= 0) {
                $errorMessage = "Please select a valid user to assign to.";
            } elseif (empty($selectedIds)) {
                $errorMessage = "Please select at least one client to reassign.";
            } else {
                // Sanitize selected IDs
                $selectedIds = array_filter(array_map('intval', $selectedIds));
                
                if (!empty($selectedIds)) {
                    // Update reviewer assignment for selected clients
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $updateStmt = $pdo->prepare("UPDATE clients SET review_assigned_to = ?, updated_at = NOW() WHERE id IN ($placeholders)");
                    
                    $params = array_merge([$newOwnerId], $selectedIds);
                    $updateStmt->execute($params);
                    
                    $affectedRows = $updateStmt->rowCount();
                    $successMessage = "Successfully assigned reviewer for $affectedRows client(s).";
                }
            }
        } catch (Exception $e) {
            $errorMessage = "Error during reassignment: " . $e->getMessage();
        }
    }
    
    // Handle bulk delete
    if ($_POST['action_type'] === 'delete') {
        try {
            $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
            
            if (empty($selectedIds)) {
                $errorMessage = "Please select at least one client to delete.";
            } else {
                // Sanitize selected IDs
                $selectedIds = array_filter(array_map('intval', $selectedIds));
                
                if (!empty($selectedIds)) {
                    $pdo->beginTransaction();
                    
                    // Delete related records first (foreign key constraints)
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    
                    // Delete from related tables
                    $tables = ['client_goals', 'client_allocations', 'client_schemes', 'client_annexures'];
                    foreach ($tables as $table) {
                        $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE client_id IN ($placeholders)");
                        $deleteStmt->execute($selectedIds);
                    }
                    
                    // Now delete from clients table
                    $deleteClientStmt = $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
                    $deleteClientStmt->execute($selectedIds);
                    
                    $affectedRows = $deleteClientStmt->rowCount();
                    
                    $pdo->commit();
                    $successMessage = "Successfully deleted $affectedRows client(s).";
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = "Error during deletion: " . $e->getMessage();
        }
    }
}

// 1. Get Filter Inputs
$q           = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter      = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$ownerFilter = isset($_GET['owner_filter']) ? trim($_GET['owner_filter']) : 'all';
$cycleFilter = isset($_GET['cycle_filter']) ? trim($_GET['cycle_filter']) : '';
$sortBy      = isset($_GET['sort']) ? trim($_GET['sort']) : 'updated_at';
$sortOrder   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit       = 20;
$offset      = ($page - 1) * $limit;

$whereParts = [];
$params = [];

// Build WHERE clause
if ($q !== '') {
    $whereParts[] = "(c.name LIKE ? OR c.as_on LIKE ?)";
    $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
}
if ($filter !== '' && in_array($filter, ['pending','draft','ready','reviewed','sent'])) {
    $whereParts[] = "c.report_state = ?";
    $params[] = $filter;
}
if ($cycleFilter !== '') {
    $whereParts[] = "c.review_cycle = ?";
    $params[] = $cycleFilter;
}
if ($ownerFilter === 'mine') {
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = $myId; $params[] = $myId;
} elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = (int)$ownerFilter; $params[] = (int)$ownerFilter;
}
$whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// --- CONTEXTUAL COUNTS FOR DROPDOWNS ---
$cycleTotals = [];
$cycleCountStmt = $pdo->prepare("SELECT c.review_cycle, COUNT(*) as total FROM clients c $whereClause GROUP BY c.review_cycle");
$cycleCountStmt->execute($params);
foreach ($cycleCountStmt as $row) {
    $cycleTotals[$row['review_cycle']] = (int)$row['total'];
}
$allCyclesTotal = array_sum($cycleTotals);

// For Owner dropdown: only filter by cycle (not by state)
$ownerWhereParts = [];
$ownerParams = [];
if ($cycleFilter !== '') {
    $ownerWhereParts[] = "c.review_cycle = ?";
    $ownerParams[] = $cycleFilter;
}
$whereOwner = $ownerWhereParts ? 'WHERE ' . implode(' AND ', $ownerWhereParts) : '';

$ownerTotals = [];
$ownerCountStmt = $pdo->prepare("SELECT u.id, u.username, COUNT(c.id) as total 
    FROM users u 
    INNER JOIN clients c ON (c.assigned_to = u.id OR c.review_assigned_to = u.id) $whereOwner 
    GROUP BY u.id, u.username HAVING total > 0");
$ownerCountStmt->execute($ownerParams);
foreach ($ownerCountStmt as $row) {
    $ownerTotals[$row['id']] = [
        'username' => $row['username'],
        'total' => (int)$row['total']
    ];
}

// For State dropdown: filter by cycle + owner
$stateWhereParts = [];
$stateParams = [];
if ($cycleFilter !== '') {
    $stateWhereParts[] = "c.review_cycle = ?";
    $stateParams[] = $cycleFilter;
}
if ($ownerFilter === 'mine') {
    $stateWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $stateParams[] = $myId; $stateParams[] = $myId;
} elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
    $stateWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $stateParams[] = (int)$ownerFilter; $stateParams[] = (int)$ownerFilter;
}
$whereState = $stateWhereParts ? 'WHERE ' . implode(' AND ', $stateWhereParts) : '';

$statusTotals = [];
$statusCountStmt = $pdo->prepare("SELECT c.report_state, COUNT(*) as total FROM clients c $whereState GROUP BY c.report_state HAVING total > 0");
$statusCountStmt->execute($stateParams);
foreach ($statusCountStmt as $row) {
    $statusTotals[$row['report_state']] = (int)$row['total'];
}
$allStatesTotal = array_sum($statusTotals);

// 1. Count Total Rows
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients c {$whereClause}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

// 2. Fetch Data (INCLUDING NEW WORKFLOW COLUMNS AND CREATOR INFO)
// Validate sort column to prevent SQL injection
$allowedSorts = ['id', 'name', 'updated_at', 'priority', 'report_state'];
$sortColumn = in_array($sortBy, $allowedSorts) ? $sortBy : 'updated_at';

// Priority sorting needs special handling for NULL and High/Normal/Low ordering
$orderByClause = '';
if ($sortColumn === 'priority') {
    // Sort: High -> Normal -> Low -> NULL
    $orderByClause = "ORDER BY CASE c.priority 
        WHEN 'High' THEN 1 
        WHEN 'Normal' THEN 2 
        WHEN 'Low' THEN 3 
        ELSE 4 END {$sortOrder}, c.id DESC";
} else {
    $orderByClause = "ORDER BY c.{$sortColumn} {$sortOrder}, c.id DESC";
}

// Update the SELECT query to include meeting_status and meeting_remarks:
// Update the SELECT query to include aum column:
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.as_on, c.created_at, c.updated_at, c.total_amount, c.profit,
           c.aum,
           c.report_state, c.review_not_ok, c.review_comment, c.created_by, c.assigned_to, c.review_assigned_to,
           c.priority, c.meeting_status, c.meeting_remarks,
           c.review_cycle,
           creator.username AS created_by_username,
           rm.username AS rm_username,
           reviewer.username AS reviewer_username
    FROM clients c
    LEFT JOIN users creator  ON c.created_by = creator.id
    LEFT JOIN users rm       ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    {$whereClause}
    {$orderByClause}
    LIMIT ? OFFSET ?
");

// Add pagination parameters to the params array
$paramsData = $params;
$paramsData[] = $limit;
$paramsData[] = $offset;

// Execute with all parameters
$stmt->execute($paramsData);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users for reassignment dropdown
$allUsersStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// --- AJAX endpoint for client name search ---
if (isset($_GET['search_client']) && isset($_GET['q'])) {
    require_once 'db_config.php';
    $pdo = getPdo();
    $q = trim($_GET['q']);
    $stmt = $pdo->prepare("SELECT DISTINCT name FROM clients WHERE name LIKE ? ORDER BY name ASC LIMIT 10");
    $stmt->execute(["%$q%"]);
    $clients = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clients[] = $row['name'];
    }
    header('Content-Type: application/json');
    echo json_encode($clients);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Stored Client Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/view_saved_reports.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
    /* [PATCH] Meeting Column Styles */
    .meet-select {
        padding: 6px 10px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        background-color: #fff;
        font-size: 13px;
        color: #495057;
        cursor: pointer;
        outline: none;
        width: 100px;
    }
    .meet-select:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    .meet-btn {
        display: inline-block;
        background-color: #e3f2fd;
        color: #1976d2;
        border: 1px solid #90caf9;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        white-space: nowrap;
    }
    .meet-btn:hover {
        background-color: #bbdefb;
        border-color: #64b5f6;
        transform: translateY(-1px);
    }

    /* Delete Mode Styles */
    .delete-mode-active { 
        background-color: #fff5f5 !important; 
    }
    
    .delete-btn { 
        background-color: #e53935; 
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
        transition: background-color 0.2s; 
    }
    
    .delete-btn:hover { 
        background-color: #c62828; 
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
    
    .bulk-actions-bar select {
        padding: 6px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        min-width: 180px;
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
    </style>
</head>
<body class="<?php echo $deleteMode ? 'delete-mode-active' : ''; ?>">
  
<?php include 'navbar.php'; ?>

<div class="container">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1>Stored Client Reports</h1>
        <div>
            <?php if (!$deleteMode): ?>
                <a href="?delete_mode=1<?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?><?php echo $sortBy ? '&sort=' . urlencode($sortBy) : ''; ?><?php echo $sortOrder !== 'DESC' ? '&order=' . strtolower($sortOrder) : ''; ?>" 
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
    
    addParam($paramString, $firstParam, 'q', $q);
    addParam($paramString, $firstParam, 'filter', $filter);
    addParam($paramString, $firstParam, 'owner_filter', $ownerFilter);
    addParam($paramString, $firstParam, 'cycle_filter', $cycleFilter);
    addParam($paramString, $firstParam, 'sort', $sortBy);
    if ($sortOrder !== 'DESC') {
        addParam($paramString, $firstParam, 'order', strtolower($sortOrder));
    }
    ?>
    
    <a href="view_saved_reports.php<?php echo $paramString; ?>" 
       class="cancel-delete-btn">
        <i class="fa-solid fa-times"></i> Cancel Delete Mode
    </a>
<?php endif; ?>
        </div>
    </div>

<?php if ($successMessage): ?>
    <div class="alert alert-success" id="successMessage">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
<?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    
    <?php if ($deleteMode): ?>
        <div class="alert alert-warning">
            <strong><i class="fa-solid fa-exclamation-triangle"></i> Delete Mode Active</strong>
            <p style="margin: 5px 0 0 0;">Select clients using checkboxes, then click "Delete Selected" to remove them.</p>
        </div>
    <?php endif; ?>

    <form method="get" class="search-box" id="filterForm" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
        <div style="position:relative; flex:1;">
            <input type="text" name="q" id="client-search" placeholder="Search..." value="<?php echo htmlspecialchars($q); ?>" autocomplete="off">
            <div id="client-search-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;z-index:1000;border:1px solid #e2e8f0;border-top:none;max-height:200px;overflow-y:auto;"></div>
        </div>

        <select id="cycle-filter" name="cycle_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:140px;">
            <option value="">All Cycles</option>
            <?php foreach(['RJ', 'RM', 'RF'] as $c): ?>
                <option value="<?= $c ?>" <?= $cycleFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
        </select>

        <select id="owner-filter" name="owner_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
            <option value="all">All Owners / Global View</option>
            <option value="mine" <?= ($ownerFilter === 'mine') ? 'selected' : '' ?>>My Reports</option>
            <?php foreach ($ownerTotals as $uid => $info): ?>
                <?php if ((int)$uid === (int)$myId) continue; ?>
                <option value="<?= $uid ?>" <?= (string)$ownerFilter === (string)$uid ? 'selected' : '' ?>>
                    <?= htmlspecialchars($info['username']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select id="stateFilter" name="filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:160px;">
            <option value="">All States (<?php echo $allStatesTotal; ?>)</option>
            <option value="pending" <?= ($filter === 'pending') ? 'selected' : '' ?>>Review Not Started (<?= $statusTotals['pending'] ?? 0 ?>)</option>
            <option value="draft" <?= ($filter === 'draft') ? 'selected' : '' ?>>Draft (<?= $statusTotals['draft'] ?? 0 ?>)</option>
            <option value="ready" <?= ($filter === 'ready') ? 'selected' : '' ?>>Ready (<?= $statusTotals['ready'] ?? 0 ?>)</option>
            <option value="reviewed" <?= ($filter === 'reviewed') ? 'selected' : '' ?>>Reviewed (<?= $statusTotals['reviewed'] ?? 0 ?>)</option>
            <option value="sent" <?= ($filter === 'sent') ? 'selected' : '' ?>>Sent (<?= $statusTotals['sent'] ?? 0 ?>)</option>
        </select>

        <button type="submit" style="background-color: #0288D1; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">Search</button>
        <a href="view_saved_reports.php" style="text-decoration: none; background-color: #666; color: white; padding: 8px 15px; border-radius: 4px; font-size: 13px;">Reset Filters</a>
    </form>

    <?php if (!$clients): ?>
        <p style="margin-top: 20px;">No reports found. Try uploading from <a href="upload.php">Upload Page</a>.</p>
    <?php else: ?>
        <!-- Delete Mode Bulk Actions -->
        <?php if ($deleteMode): ?>
        <form method="post" id="bulkDeleteForm">
            <input type="hidden" name="action_type" value="delete">
            <div class="bulk-actions-bar">
                <span class="bulk-selection-info">With Selected:</span>
                <button type="button" onclick="confirmDelete()" class="delete-btn">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
                <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
            </div>
        <?php endif; ?>
        
        <!-- Reassignment Form (only when not in delete mode) -->
        <?php if (!$deleteMode): ?>
        <form method="post" id="bulkReassignForm">
            <input type="hidden" name="action_type" value="reassign">
            <div class="bulk-actions-bar">
                <span class="bulk-selection-info">With Selected:</span>
                <select name="new_owner_id" id="newOwnerSelect" required>
                    <option value="">-- Assign to... --</option>
                    <?php foreach ($allUsers as $user): ?>
                        <option value="<?php echo (int)$user['id']; ?>">
                            <?php echo htmlspecialchars($user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" style="background-color: #28a745; color: white;">Reassign</button>
            </div>
        <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <?php if ($deleteMode): ?>
                        <th style="width: 40px;" class="select-all-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="client-checkbox" onclick="toggleSelectAll(this)">
                            <span class="select-all-label">All</span>
                        </th>
                        <?php endif; ?>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=id&order=<?php echo ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                ID <?php if ($sortBy === 'id') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=name&order=<?php echo ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Client Name <?php if ($sortBy === 'name') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>AUM (Cr)</th>
                        <th>Drafted By</th>
                        <th>RM</th>
                        <th>Cycle</th>
                        <th>Review Assigned to</th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=priority&order=<?php echo ($sortBy === 'priority' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Priority <?php if ($sortBy === 'priority') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=updated_at&order=<?php echo ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Last Updated <?php if ($sortBy === 'updated_at') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=report_state&order=<?php echo ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Status <?php if ($sortBy === 'report_state') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th style="text-align: center; width: 120px;">
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=meeting_status&order=<?php echo ($sortBy === 'meeting_status' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none;">
                                Meeting Status <?php if ($sortBy === 'meeting_status') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th style="text-align: center; width: 140px;">
                            <a href="?<?php echo $deleteMode ? 'delete_mode=1&' : ''; ?>sort=meeting_remarks&order=<?php echo ($sortBy === 'meeting_remarks' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none;">
                                Meeting Remarks <?php if ($sortBy === 'meeting_remarks') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): 
    // --- WORKFLOW BADGE LOGIC ---
    $statusHtml = '';
    
    if (isset($c['review_not_ok']) && $c['review_not_ok'] == 1) {
        $comment = htmlspecialchars($c['review_comment'] ?? '');
        $statusHtml = "<span class='badge badge-rejected' title='RM Comment: $comment'>NOT OK</span>";
    } else {
        $state = $c['report_state'] ?? 'draft';
        $badgeClass = 'badge-' . $state;

        if ($state === 'sent') {
            $displayText = 'Email Sent';
        } elseif ($state === 'pending') {
            $displayText = 'Review Not Started';
            $badgeClass = 'badge-pending';
        } else {
            $displayText = ucfirst($state);
        }

        $statusHtml = "<span class='badge $badgeClass'>" . $displayText . "</span>";
    }

    // Check for attachments
    $hasAttachments = false;
    $cDir = __DIR__ . '/uploads/attachments/client_' . $c['id'];
    if (is_dir($cDir)) {
        $files = array_diff(scandir($cDir), ['.', '..']);
        if (count($files) > 0) {
            $hasAttachments = true;
        }
    }
    
    // Priority badge styling
    $priorityBadgeClass = 'badge';
    $priorityText = htmlspecialchars($c['priority'] ?? 'Normal');
    
    if (strtolower($priorityText) === 'high') {
        $priorityBadgeClass .= ' badge-ready';
    } elseif (strtolower($priorityText) === 'low') {
        $priorityBadgeClass .= ' badge-draft';
    }
?>
    <tr>
        <?php if ($deleteMode): ?>
        <td>
            <input type="checkbox" class="client-checkbox delete-checkbox" name="selected_ids[]" value="<?php echo (int)$c['id']; ?>" onchange="updateSelectedCount()">
        </td>
        <?php endif; ?>
        <td><?php echo (int)$c['id']; ?></td>
        <td>
            <div style="font-weight: 600; color: #333; display:flex; align-items:center; gap:8px;">
                <span><?php echo htmlspecialchars($c['name']); ?></span>
                <?php if($hasAttachments): ?>
                    <span title="Has Attachments">📎</span>
                <?php endif; ?>
            </div>
        </td>
        <!-- AUM Column - FIXED -->
<td>
    <span style="font-weight:600; color:#1976d2;">
        ₹<?= number_format(((float)($c['aum'] ?? 0)) / 10000000, 2); ?> Cr
    </span>
</td>

        <td>
            <?php $currState = strtolower($c['report_state'] ?? 'draft'); ?>
            <?php if ($currState === 'pending'): ?>
                <span style="color: #999; font-size: 0.85em; font-weight:600;">Not Drafted</span>
            <?php else: ?>
                <?php if (!empty($c['created_by_username'])): ?>
                    <span class="badge" style="background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; padding: 5px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                        <?php echo htmlspecialchars($c['created_by_username']); ?>
                    </span>
                <?php else: ?>
                    <span style="color: #999; font-size: 0.85em;">System</span>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <td>
            <span style="color:#333; font-weight:600;">
                <?php echo !empty($c['rm_username']) ? htmlspecialchars($c['rm_username']) : '—'; ?>
            </span>
        </td>
        <td>
            <span class="badge" style="background: #f5f5f5; color: #333; border: 1px solid #ddd; padding: 2px 6px; border-radius: 4px;">
                <?php echo htmlspecialchars($c['review_cycle'] ?? '—'); ?>
            </span>
        </td>
        <td>
            <?php 
                $isReviewer = ((int)($c['review_assigned_to'] ?? 0) === $myId);
                $reviewerStyle = 'background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; padding: 5px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;';
                if ($isReviewer) {
                    $reviewerStyle .= ' font-weight: 800; border-color: #1565c0;';
                }
            ?>
            <?php if (!empty($c['reviewer_username'])): ?>
                <span class="badge" style="<?php echo $reviewerStyle; ?>">
                    <?php echo htmlspecialchars($c['reviewer_username']); ?>
                    <?php if ($isReviewer): ?><span style="margin-left:6px; color:#0d47a1; font-weight:800;">You</span><?php endif; ?>
                </span>
            <?php else: ?>
                <span style="color: #999; font-size: 0.85em;">Unassigned</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($c['priority'])): ?>
                <span class="<?php echo $priorityBadgeClass; ?>" style="text-transform:capitalize;">
                    <?php echo $priorityText; ?>
                </span>
            <?php else: ?>
                <span style="color: #999; font-size: 0.85em;">Normal</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($c['updated_at'])): ?>
                <span style="color: #555; font-size: 0.9em;">
                    <?php echo date('d-M-Y', strtotime($c['updated_at'])); ?>
                    <span style="color: #999; font-size: 0.85em;">&nbsp;<?php echo date('h:i A', strtotime($c['updated_at'])); ?></span>
                </span>
            <?php else: ?>
                <span style="color: #999; font-size: 0.85em;">N/A</span>
            <?php endif; ?>
        </td>
        <td><?php echo $statusHtml; ?></td>
        <td style="text-align: center;">
            <select onchange="handleListMeetingChange(this, <?php echo $c['id']; ?>)"
                    class="meet-select"
                    id="meet_select_<?php echo $c['id']; ?>">
                <option value="pending" <?php echo ($c['meeting_status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                <option value="yes" <?php echo ($c['meeting_status'] === 'yes') ? 'selected' : ''; ?>>✅ Yes</option>
                <option value="no" <?php echo ($c['meeting_status'] === 'no') ? 'selected' : ''; ?>>❌ No</option>
            </select>
        </td>

        <td style="text-align: center;">
            <button type="button" 
                    id="meet_btn_<?php echo $c['id']; ?>"
                    class="meet-btn"
                    onclick="openListMeetingModal(<?php echo $c['id']; ?>)"
                    style="display: <?php echo ($c['meeting_status'] !== 'pending') ? 'inline-block' : 'none'; ?>;">
                Remarks <?php echo !empty($c['meeting_remarks']) ? '(Edit)' : '(Add)'; ?>
            </button>
            
            <input type="hidden" id="remarks_store_<?php echo $c['id']; ?>" value="<?php echo htmlspecialchars($c['meeting_remarks'] ?? ''); ?>">
        </td>
        <td>
            <?php if (($c['report_state'] ?? '') === 'pending'): ?>
                <a href="upload.php?auto_search=<?php echo urlencode($c['name']); ?>" 
                   style="font-weight: 600; color:#0288D1; text-decoration:none;">
                    📂 Upload <?php echo htmlspecialchars($c['name']); ?>'s Files
                </a>
            <?php else: ?>
                <a href="view_report.php?id=<?php echo (int)$c['id']; ?>" 
                   style="font-weight: 600; color:#0288D1; text-decoration:none;">Open</a>
                <span style="color:#ccc; margin:0 6px;">|</span>
                <a href="upload.php?auto_search=<?php echo urlencode($c['name']); ?>" 
                   style="font-size:0.9em; color:#555; text-decoration:none;" 
                   title="Upload files for <?php echo htmlspecialchars($c['name']); ?>">Upload</a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <div class="pagination">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>:
            <?php
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p == $page) {
                    echo "<strong>{$p}</strong> ";
                } else {
                    $params = ['page' => $p];
                    if ($deleteMode) $params['delete_mode'] = '1';
                    if ($q !== '') $params['q'] = $q;
                    if ($filter !== '') $params['filter'] = $filter;
                    if ($ownerFilter !== '') $params['owner_filter'] = $ownerFilter;
                    if ($cycleFilter !== '') $params['cycle_filter'] = $cycleFilter;
                    if ($sortBy !== 'updated_at') $params['sort'] = $sortBy;
                    if ($sortOrder !== 'DESC') $params['order'] = strtolower($sortOrder);
                    $url = 'view_saved_reports.php?' . http_build_query($params);
                    echo "<a href=\"{$url}\">{$p}</a> ";
                }
            }
            ?>
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
            Are you sure you want to delete <span id="deleteCount">0</span> selected client(s)?
        </p>
        <p style="text-align: center; color: #666; font-size: 14px; margin-bottom: 25px;">
            <i class="fa-solid fa-exclamation-circle"></i> This action cannot be undone. All related data will be permanently deleted.
        </p>
        <div style="display: flex; justify-content: center; gap: 15px;">
            <button type="button" onclick="closeDeleteModal()" style="padding: 10px 24px; border: 1px solid #ced4da; background: #fff; color: #555; border-radius: 6px; cursor: pointer; font-weight: 500;">Cancel</button>
            <button type="button" onclick="submitDelete()" style="padding: 10px 24px; border: none; background: #e53935; color: white; border-radius: 6px; cursor: pointer; font-weight: 600;">
                <i class="fa-solid fa-trash"></i> Delete Selected
            </button>
        </div>
    </div>
</div>

<script>
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

// Show delete confirmation modal
function confirmDelete() {
    const checkboxes = document.querySelectorAll('.delete-checkbox');
    const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    
    if (selectedCount === 0) {
        alert('Please select at least one client to delete.');
        return;
    }
    
    document.getElementById('deleteCount').textContent = selectedCount;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
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
});

// Add checkbox event listeners
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.delete-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
});

// Prevent reassignment form submission if no owner selected
const bulkForm = document.getElementById('bulkReassignForm');
if (bulkForm) {
    bulkForm.addEventListener('submit', function(e) {
        const newOwner = document.getElementById('newOwnerSelect').value;
        if (!newOwner) {
            e.preventDefault();
            alert('Please select a user to assign to before clicking Reassign.');
        }
    });
}


</script>

<!-- Meeting Remarks Modal -->
<div id="listMeetingModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: white; padding: 24px; border-radius: 12px; width: 450px; max-width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.2); font-family: 'Inter', sans-serif;">
        <h3 style="margin-top: 0; color: #1976d2; font-size: 18px; display: flex; align-items: center; gap: 8px;">
            <span>📝</span> Meeting Remarks
        </h3>
        <p style="font-size: 14px; color: #666; margin-bottom: 12px;">Enter details about the discussion:</p>
        
        <input type="hidden" id="current_modal_client_id">
        <textarea id="listModalRemarks" rows="5" style="width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 8px; font-family: inherit; margin-bottom: 20px; resize: vertical; font-size: 14px;" placeholder="e.g., Client agreed to increase SIP..."></textarea>
        
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" onclick="closeListMeetingModal()" style="padding: 8px 16px; border: 1px solid #ced4da; background: #fff; color: #555; border-radius: 6px; cursor: pointer; font-weight: 500;">Cancel</button>
            <button type="button" onclick="saveListMeetingRemarks()" style="padding: 8px 20px; border: none; background: #1976d2; color: white; border-radius: 6px; cursor: pointer; font-weight: 600;">Save Remarks</button>
        </div>
    </div>
</div>

<script>
    // 1. Handle Dropdown Change
    function handleListMeetingChange(select, clientId) {
        const status = select.value;
        const remarksBtn = document.getElementById('meet_btn_' + clientId);
        const storedRemarks = document.getElementById('remarks_store_' + clientId).value;

        if (status === 'yes') {
            openListMeetingModal(clientId);
            remarksBtn.style.display = 'inline-block';
        } else {
            // Save 'No' or 'Pending' immediately
            saveData(clientId, status, storedRemarks, false);
            remarksBtn.style.display = (status === 'pending') ? 'none' : 'inline-block';
        }
    }

    // 2. Open Modal
    function openListMeetingModal(clientId) {
        const remarks = document.getElementById('remarks_store_' + clientId).value;
        document.getElementById('current_modal_client_id').value = clientId;
        document.getElementById('listModalRemarks').value = remarks;
        document.getElementById('listMeetingModal').style.display = 'flex';
        document.getElementById('listModalRemarks').focus();
    }

    // 3. Close Modal
    function closeListMeetingModal() {
        document.getElementById('listMeetingModal').style.display = 'none';
    }

    // 4. Save
    function saveListMeetingRemarks() {
        const clientId = document.getElementById('current_modal_client_id').value;
        const remarks = document.getElementById('listModalRemarks').value;
        const select = document.getElementById('meet_select_' + clientId);
        const status = select ? select.value : 'yes'; // Default to yes if saving remarks
        
        saveData(clientId, status, remarks, true);
    }

    // 5. AJAX Save
    function saveData(clientId, status, remarks, isModal) {
        const formData = new URLSearchParams();
        formData.append('action', 'save_meeting_status');
        formData.append('client_id', clientId);
        formData.append('status', status);
        formData.append('remarks', remarks);

        fetch('meeting_tracker.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                const store = document.getElementById('remarks_store_' + clientId);
                if(store) store.value = remarks;
                
                const btn = document.getElementById('meet_btn_' + clientId);
                if(btn) btn.innerHTML = 'Remarks ' + (remarks ? '(Edit)' : '(Add)');

                if(isModal) {
                    closeListMeetingModal();
                    if(typeof showToast === 'function') showToast("Meeting remarks saved!");
                }
            } else {
                alert("Error: " + data.error);
            }
        });
    }
</script>

<script>
    // --- Client Name Autocomplete for Search Box ---
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('client-search');
        const dropdown = document.getElementById('client-search-dropdown');
        if (!input) return;
        input.addEventListener('input', function() {
            let val = input.value.trim();
            if (val.length < 1) {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                return;
            }
            fetch('view_saved_reports.php?search_client=1&q=' + encodeURIComponent(val))
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        dropdown.innerHTML = data.map(name =>
                            `<div style="padding:8px 12px;cursor:pointer;" 
                                onmousedown="selectClientName('${name.replace(/'/g,"\\'")}')">${name}</div>`
                        ).join('');
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">No clients found</div>';
                        dropdown.style.display = 'block';
                    }
                });
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== input) {
                dropdown.style.display = 'none';
            }
        });
    });
    
    function selectClientName(name) {
        document.getElementById('client-search').value = name;
        document.getElementById('client-search-dropdown').style.display = 'none';
        document.getElementById('filterForm').submit();
    }
</script>

<script>
// Auto-hide success message after 3 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    if (successMessage) {
        setTimeout(function() {
            successMessage.style.transition = 'opacity 0.5s ease';
            successMessage.style.opacity = '0';
            
            // Remove from DOM after fade out
            setTimeout(function() {
                successMessage.style.display = 'none';
            }, 500); // Wait for fade out to complete
        }, 3000); // Show for 3 seconds
    }
});
</script>

</body>
</html>
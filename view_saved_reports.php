<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear
// - Added: Bulk reassignment functionality and split owner columns
// - Added: Delete mode with checkboxes that appear only when clicking action icons

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

// Initialize action mode variables
$deleteMode = false;
$reassignMode = false;
$showCheckboxes = false;

// Check for action modes
if (isset($_GET['mode'])) {
    if ($_GET['mode'] === 'delete') {
        $deleteMode = true;
        $showCheckboxes = true;
    } elseif ($_GET['mode'] === 'reassign') {
        $reassignMode = true;
        $showCheckboxes = true;
    }
}

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

// --- BEGIN: Reassigned summary logic (admin or logged-in user only) ---
$showReassignedSummary = false;
$reassignedSummary = [];
$isAdmin = (strtolower($currentUser['username'] ?? '') === strtolower(getenv('ADMIN_USERNAME') ?: 'admin'));

// Determine which user's summary to show (for admin, based on owner_filter)
$summaryUserId = $myId;
if ($isAdmin && isset($_GET['owner_filter']) && ctype_digit($_GET['owner_filter'])) {
    $summaryUserId = (int)$_GET['owner_filter'];
}

if ($isAdmin || $myId) {
    $showReassignedSummary = true;
    // For admin: show summary for selected owner_filter, for user: only their own
    if ($isAdmin && isset($_GET['owner_filter']) && ctype_digit($_GET['owner_filter'])) {
        // Show summary for selected owner
        $stmtReassigned = $pdo->prepare("
            SELECT u.username, COUNT(c.id) as total
            FROM clients c
            INNER JOIN users u ON u.id = c.review_assigned_to
            WHERE c.assigned_to = ?
            GROUP BY u.username
            ORDER BY u.username
        ");
        $stmtReassigned->execute([$summaryUserId]);
        $reassignedSummary = $stmtReassigned->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($isAdmin && (!isset($_GET['owner_filter']) || $_GET['owner_filter'] === 'all' || $_GET['owner_filter'] === 'mine')) {
        // Show global summary for all users (admin, no specific owner selected)
        $stmtReassigned = $pdo->query("
            SELECT u.username, COUNT(c.id) as total
            FROM clients c
            INNER JOIN users u ON u.id = c.review_assigned_to
            WHERE c.assigned_to <> c.review_assigned_to
            GROUP BY u.username
            ORDER BY u.username
        ");
        $reassignedSummary = $stmtReassigned->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // For normal user or admin with "mine"
        $stmtReassigned = $pdo->prepare("
            SELECT u.username, COUNT(c.id) as total
            FROM clients c
            INNER JOIN users u ON u.id = c.review_assigned_to
            WHERE c.assigned_to = ?
            GROUP BY u.username
            ORDER BY u.username
        ");
        $stmtReassigned->execute([$myId]);
        $reassignedSummary = $stmtReassigned->fetchAll(PDO::FETCH_ASSOC);
    }
}
// --- END: Reassigned summary logic ---

// 1. Get Filter Inputs
$q           = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter      = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$ownerFilter = isset($_GET['owner_filter']) ? trim($_GET['owner_filter']) : 'all'; // Default to 'all' for admin
$cycleFilter = isset($_GET['cycle_filter']) ? trim($_GET['cycle_filter']) : '';
$sortBy      = isset($_GET['sort']) ? trim($_GET['sort']) : 'updated_at';
$sortOrder   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit       = 20;
$offset      = ($page - 1) * $limit;

$whereParts = [];
$params = [];

// Always restrict to clients where the logged-in user is RM or Reviewer (except for admin)
$isAdmin = (strtolower($currentUser['username'] ?? '') === strtolower(getenv('ADMIN_USERNAME') ?: 'admin'));

// --- FIX: Only restrict for non-admin, do NOT add this clause for admin ---
if (!$isAdmin) {
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = $myId;
    $params[] = $myId;
}

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
// Only apply ownerFilter for admin, for non-admin it's always "mine"
if ($isAdmin) {
    if ($ownerFilter === 'mine') {
        $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
        $params[] = $myId; $params[] = $myId;
    } elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
        $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
        $params[] = (int)$ownerFilter; $params[] = (int)$ownerFilter;
    }
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
$allowedSorts = ['id', 'name', 'updated_at', 'priority', 'report_state', 'aum'];
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
} elseif ($sortColumn === 'aum') {
    // Sort by AUM (numeric sorting)
    $orderByClause = "ORDER BY CAST(c.aum AS DECIMAL(15,2)) {$sortOrder}, c.id DESC";
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
    <link rel="stylesheet" href="public/css/navbar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* Additional CSS for action modes */
        .action-mode-active .bulk-actions-bar,
        .action-mode-active .select-all-cell,
        .action-mode-active .action-checkbox {
            display: block !important;
        }
        
        .action-mode-active .action-icon-cell {
            display: none !important;
        }
        
        .action-icons-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .action-icon-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .delete-icon-btn {
            background-color: #e53935;
        }
        
        .delete-icon-btn:hover {
            background-color: #c62828;
            transform: scale(1.05);
        }
        
        .reassign-icon-btn {
            background-color: #0288D1;
        }
        
        .reassign-icon-btn:hover {
            background-color: #0277BD;
            transform: scale(1.05);
        }
        
        .cancel-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        
        .cancel-action-btn:hover {
            background-color: #5a6268;
        }
        
        .select-all-cell,
        .action-checkbox {
            display: none;
        }
        
        .action-checkbox {
            vertical-align: middle;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .sort-dropdown {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            background-color: white;
            cursor: pointer;
            min-width: 160px;
        }
        
        .sort-dropdown:focus {
            outline: none;
            border-color: #0288D1;
            box-shadow: 0 0 0 2px rgba(2, 136, 209, 0.2);
        }
        
        .bulk-actions-bar {
            display: none;
            background-color: #f8f9fa;
            padding: 12px 20px;
            margin: 15px 0;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            align-items: center;
            gap: 15px;
        }
        
        .bulk-selection-info {
            font-weight: 600;
            color: #495057;
        }
        
        .delete-btn {
            background-color: #e53935;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
        }
        
        .delete-btn:hover {
            background-color: #c62828;
        }
        
        .reassign-select {
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            min-width: 180px;
        }
        
        .reassign-submit-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }
        
        .reassign-submit-btn:hover {
            background-color: #218838;
        }
      .btn {
    text-decoration: none;
    border: none;
    padding: 9px 18px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;

    transition:
        background 0.25s ease,
        box-shadow 0.25s ease,
        transform 0.2s ease,
        filter 0.2s ease;
}

/* ---------- SEARCH BUTTON ---------- */
.btn-search {
    color: #fff;
    background: linear-gradient(135deg, #039BE5, #0277BD);
    box-shadow: 0 2px 6px rgba(2, 136, 209, 0.35);
}

.btn-search:hover {
    background: linear-gradient(135deg, #03A9F4, #0288D1);
    box-shadow:
        0 6px 14px rgba(2, 136, 209, 0.45),
        inset 0 1px 0 rgba(255,255,255,0.15);
    transform: translateY(-2px);
}

.btn-search:active {
    transform: translateY(0);
    box-shadow:
        0 3px 8px rgba(2, 136, 209, 0.4),
        inset 0 2px 4px rgba(0,0,0,0.2);
}

/* Focus (keyboard users) */
.btn-search:focus-visible {
    outline: none;
    box-shadow:
        0 0 0 3px rgba(2, 136, 209, 0.35),
        0 4px 10px rgba(2, 136, 209, 0.45);
}

/* ---------- RESET BUTTON ---------- */
.btn-reset {
    color: #fff;
    background: linear-gradient(135deg, #757575, #616161);
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
}

.btn-reset:hover {
    background: linear-gradient(135deg, #8E8E8E, #555);
    box-shadow:
        0 6px 14px rgba(0,0,0,0.35),
        inset 0 1px 0 rgba(255,255,255,0.12);
    transform: translateY(-2px);
}

.btn-reset:active {
    transform: translateY(0);
    box-shadow:
        0 3px 8px rgba(0,0,0,0.35),
        inset 0 2px 4px rgba(0,0,0,0.25);
}

.btn-reset:focus-visible {
    outline: none;
    box-shadow:
        0 0 0 3px rgba(0,0,0,0.3),
        0 4px 10px rgba(0,0,0,0.35);
}


.meet-select {
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 6px;
    border: 1px solid #ccc;
    cursor: pointer;
    background-color: #fff;
    min-width: 110px;

    /* remove default arrow */
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    /* custom arrow */
    background-image:
        linear-gradient(45deg, transparent 50%, #555 50%),
        linear-gradient(135deg, #555 50%, transparent 50%),
        linear-gradient(to right, #e0e0e0, #e0e0e0);
    background-position:
        calc(100% - 18px) calc(50% - 3px),
        calc(100% - 13px) calc(50% - 3px),
        calc(100% - 2.2em) 50%;
    background-size:
        5px 5px,
        5px 5px,
        1px 1.5em;
    background-repeat: no-repeat;

    transition: all 0.25s ease;
}

/* Hover */
.meet-select:hover {
    border-color: #0288D1;
    box-shadow: 0 2px 6px rgba(2, 136, 209, 0.25);
}

/* Focus */
.meet-select:focus {
    outline: none;
    border-color: #0288D1;
    box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.25);
}

/* ---------- Status-based coloring ---------- */
.meet-select option[value="pending"] {
    color: #f9a825;
}

.meet-select option[value="yes"] {
    color: #2e7d32;
}

.meet-select option[value="no"] {
    color: #c62828;
}

/* Overlay */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(3px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Card */
.modal-card {
    background: #fff;
    width: 460px;
    max-width: 92%;
    padding: 22px 24px;
    border-radius: 14px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.25);
    font-family: 'Inter', sans-serif;
    animation: modalFadeIn 0.25s ease;
}

/* Header */
.modal-header {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
}

.modal-icon {
    background: linear-gradient(135deg, #1976d2, #42a5f5);
    color: #fff;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.modal-header h3 {
    margin: 0;
    font-size: 17px;
    color: #1f2937;
}

.modal-header p {
    margin: 2px 0 0;
    font-size: 13px;
    color: #6b7280;
}

/* Textarea */
#listModalRemarks {
    width: 95%;
    min-height: 110px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    font-size: 14px;
    resize: vertical;
    font-family: inherit;
    transition: all 0.25s ease;
}

#listModalRemarks::placeholder {
    color: #9ca3af;
}

#listModalRemarks:focus {
    outline: none;
    border-color: #1976d2;
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.25);
}

/* Footer */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

/* Animation */
@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}


    </style>
</head>
<body class="<?php echo ($deleteMode || $reassignMode) ? 'action-mode-active' : ''; ?>">
  
<?php include 'navbar.php'; ?>

<div class="container">
<?php
// --- Place dashboard summary OUTSIDE the .container and just after navbar ---
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">

    <!-- LEFT: Reassigned Summary (UNCHANGED) -->
    <?php if ($showReassignedSummary && !empty($reassignedSummary)): ?>
        <div class="dashboard-summary">
            <div class="dashboard-summary-inner">
                <span class="dashboard-summary-title">Reassigned Summary</span>

                <?php foreach ($reassignedSummary as $row): ?>
                    <?php
                    $uid = null;
                    foreach ($allUsers as $u) {
                        if ($u['username'] === $row['username']) {
                            $uid = $u['id'];
                            break;
                        }
                    }
                    $filterParams = [];
                    if ($q !== '') $filterParams['q'] = $q;
                    if ($filter !== '') $filterParams['filter'] = $filter;
                    if ($cycleFilter !== '') $filterParams['cycle_filter'] = $cycleFilter;
                    if ($sortBy !== 'updated_at') $filterParams['sort'] = $sortBy;
                    if ($sortOrder !== 'DESC') $filterParams['order'] = strtolower($sortOrder);
                    $filterParams['owner_filter'] = $uid;
                    $filterUrl = 'view_saved_reports.php?' . http_build_query($filterParams);
                    ?>
                    <a
                        href="<?= htmlspecialchars($filterUrl) ?>"
                        class="dashboard-summary-user"
                        style="color:#1976d2; font-weight:600; text-decoration:none; cursor:pointer; margin:0 16px;"
                    >
                        <?= htmlspecialchars($row['username']) ?> - <b><?= (int)$row['total'] ?></b>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- RIGHT: Reassign Button (UNCHANGED) -->
    <div class="action-icons-container">
        <?php if ($isAdmin && !$deleteMode && !$reassignMode): ?>
            <a href="?mode=reassign<?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?><?php echo $sortBy ? '&sort=' . urlencode($sortBy) : ''; ?><?php echo $sortOrder !== 'DESC' ? '&order=' . strtolower($sortOrder) : ''; ?>" 
               class="action-btn reassign-btn" title="Reassign Clients">
                <i class="fa-solid fa-user-group"></i>
                <span>Reassign</span>
            </a>
        <?php elseif ($deleteMode || $reassignMode): ?>
            <a href="view_saved_reports.php" class="cancel-action-btn">
                <i class="fa-solid fa-times"></i> Cancel
            </a>
        <?php endif; ?>
    </div>

</div>

    <!-- Show filtered row count above search bar -->
    <div style="margin-bottom: 8px; font-weight:600; color:#1976d2;">
        <?php
            echo "Showing $totalRows row" . ($totalRows !== 1 ? "s" : "") . " for current filters.";
        ?>
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
    <?php elseif ($reassignMode): ?>
        <div class="alert alert-info">
            <strong><i class="fa-solid fa-user-group"></i> Reassign Mode Active</strong>
            <p style="margin: 5px 0 0 0;">Select clients using checkboxes, choose a user from dropdown, then click "Reassign".</p>
        </div>
    <?php endif; ?>



    <form method="get" class="search-box" id="filterForm" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">

<div style="position:relative; flex:1; max-width:60%;">
    <input type="text"
           name="q"
           id="client-search"
           placeholder="Search..."
           value="<?php echo htmlspecialchars($q); ?>"
           autocomplete="off"
           style="width:60%; padding:10px 14px; font-size:15px; box-sizing:border-box;">

    <div id="client-search-dropdown"
         style="display:none;
                position:absolute;
                top:100%;
                left:0;
                width:60%;
                background:#fff;
                z-index:1000;
                border:1px solid #e2e8f0;
                border-top:none;
                max-height:200px;
                overflow-y:auto;
                box-sizing:border-box;">
    </div>
</div>



        <select id="cycle-filter" name="cycle_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:140px;">
            <option value="">All Cycles</option>
            <?php foreach(['RJ', 'RM', 'RF'] as $c): ?>
                <option value="<?= $c ?>" <?= $cycleFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
        </select>

       <?php if ($isAdmin): ?>
    <select id="owner-filter" name="owner_filter"
            style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
        <option value="all">All Owners / Global View</option>
        <option value="mine" <?= ($ownerFilter === 'mine') ? 'selected' : '' ?>>My Reports</option>

        <?php foreach ($ownerTotals as $uid => $info): ?>
            <?php if ((int)$uid === (int)$myId) continue; ?>
            <option value="<?= $uid ?>" <?= (string)$ownerFilter === (string)$uid ? 'selected' : '' ?>>
                <?= htmlspecialchars($info['username']) ?>
            </option>
        <?php endforeach; ?>
    </select>
<?php endif; ?>

        <select id="stateFilter" name="filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:160px;">
            <option value="">All States (<?php echo $allStatesTotal; ?>)</option>
            <option value="pending" <?= ($filter === 'pending') ? 'selected' : '' ?>>Review Not Started (<?= $statusTotals['pending'] ?? 0 ?>)</option>
            <option value="draft" <?= ($filter === 'draft') ? 'selected' : '' ?>>Draft (<?= $statusTotals['draft'] ?? 0 ?>)</option>
            <option value="ready" <?= ($filter === 'ready') ? 'selected' : '' ?>>Ready (<?= $statusTotals['ready'] ?? 0 ?>)</option>
            <option value="reviewed" <?= ($filter === 'reviewed') ? 'selected' : '' ?>>Reviewed (<?= $statusTotals['reviewed'] ?? 0 ?>)</option>
            <option value="sent" <?= ($filter === 'sent') ? 'selected' : '' ?>>Sent (<?= $statusTotals['sent'] ?? 0 ?>)</option>
        </select>

        <select name="sort" class="sort-dropdown">
            <option value="updated_at" <?php echo $sortBy === 'updated_at' ? 'selected' : ''; ?>>Sort by: Last Updated</option>
            <option value="id" <?php echo $sortBy === 'id' ? 'selected' : ''; ?>>Sort by: ID</option>
            <option value="priority" <?php echo $sortBy === 'priority' ? 'selected' : ''; ?>>Sort by: Priority</option>
            <option value="aum" <?php echo $sortBy === 'aum' ? 'selected' : ''; ?>>Sort by: AUM</option>
            <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Sort by: Client Name</option>
            <option value="report_state" <?php echo $sortBy === 'report_state' ? 'selected' : ''; ?>>Sort by: Status</option>
        </select>

        <select name="order" style="padding:8px; border:1px solid #ccc; border-radius:4px; font-size:14px;">
            <option value="desc" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Descending</option>
            <option value="asc" <?php echo $sortOrder === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
        </select>

        <input type="hidden" name="mode" value="<?php echo $deleteMode ? 'delete' : ($reassignMode ? 'reassign' : ''); ?>">

<button type="submit" class="btn btn-search">Search</button>

<a href="view_saved_reports.php" class="btn btn-reset">Reset Filters</a>

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
        <?php elseif ($reassignMode): ?>
        <!-- Reassignment Form -->
        <form method="post" id="bulkReassignForm">
            <input type="hidden" name="action_type" value="reassign">
            <div class="bulk-actions-bar">
                <span class="bulk-selection-info">With Selected:</span>
                <select name="new_owner_id" class="reassign-select" required>
                    <option value="">-- Assign to... --</option>
                    <?php foreach ($allUsers as $user): ?>
                        <option value="<?php echo (int)$user['id']; ?>">
                            <?php echo htmlspecialchars($user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="reassign-submit-btn">Reassign</button>
                <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
            </div>
        <?php else: ?>
        <!-- Default view (no form needed) -->
        <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <!-- Show checkboxes only in action mode -->
                        <?php if ($deleteMode || $reassignMode): ?>
                        <th style="width: 40px;" class="select-all-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="action-checkbox" onclick="toggleSelectAll(this)">
                            <span class="select-all-label">All</span>
                        </th>
                        <?php endif; ?>
                        
                        <?php if (!$deleteMode && !$reassignMode): ?>
                        <th style="width: 40px;" class="action-icon-cell">
                            <!-- Empty cell for icon column when not in action mode -->
                        </th>
                        <?php endif; ?>
                        
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=id&order=<?php echo ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                ID <?php if ($sortBy === 'id') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=name&order=<?php echo ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Client Name <?php if ($sortBy === 'name') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=aum&order=<?php echo ($sortBy === 'aum' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                AUM (Cr) <?php if ($sortBy === 'aum') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>Drafted By</th>
                        <th>RM</th>
                        <th>Cycle</th>
                        <th>Review Assigned to</th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=priority&order=<?php echo ($sortBy === 'priority' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Priority <?php if ($sortBy === 'priority') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=updated_at&order=<?php echo ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Last Updated <?php if ($sortBy === 'updated_at') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=report_state&order=<?php echo ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                Status <?php if ($sortBy === 'report_state') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th style="text-align: center; width: 120px;">
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=meeting_status&order=<?php echo ($sortBy === 'meeting_status' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none;">
                                Meeting Status <?php if ($sortBy === 'meeting_status') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                            </a>
                        </th>
                        <th style="text-align: center; width: 140px;">
                            <a href="?<?php echo $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : ''); ?>sort=meeting_remarks&order=<?php echo ($sortBy === 'meeting_remarks' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>" style="color: #333; text-decoration: none;">
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
        <!-- Show checkboxes only in action mode -->
        <?php if ($deleteMode || $reassignMode): ?>
        <td>
            <input type="checkbox" class="action-checkbox client-checkbox" name="selected_ids[]" value="<?php echo (int)$c['id']; ?>" onchange="updateSelectedCount()">
        </td>
        <?php else: ?>
        <td class="action-icon-cell">
            <!-- Empty cell for icon column when not in action mode -->
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
    <select
        onchange="handleListMeetingChange(this, <?php echo $c['id']; ?>)"
        class="meet-select"
        id="meet_select_<?php echo $c['id']; ?>">
        
        <option value="pending" <?php echo ($c['meeting_status'] === 'pending') ? 'selected' : ''; ?>>
            ⏳ Pending
        </option>
        <option value="yes" <?php echo ($c['meeting_status'] === 'yes') ? 'selected' : ''; ?>>
            ✅ Yes
        </option>
        <option value="no" <?php echo ($c['meeting_status'] === 'no') ? 'selected' : ''; ?>>
            ❌ No
        </option>
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
                    if ($deleteMode) $params['mode'] = 'delete';
                    if ($reassignMode) $params['mode'] = 'reassign';
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

<script>
// Toggle select all checkboxes
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.client-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedCount();
}

// Update selected count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.client-checkbox');
    const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    const selectedCountElem = document.getElementById('selectedCount');
    if (selectedCountElem) {
        selectedCountElem.textContent = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '') + ' selected';
    }
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
    const checkboxes = document.querySelectorAll('.client-checkbox');
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
    if (document.querySelector('.client-checkbox')) {
        updateSelectedCount();
    }
});

// Add checkbox event listeners
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.client-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
});

// Prevent reassignment form submission if no owner selected or no clients selected
const bulkReassignForm = document.getElementById('bulkReassignForm');
if (bulkReassignForm) {
    bulkReassignForm.addEventListener('submit', function(e) {
        const newOwner = bulkReassignForm.querySelector('select[name="new_owner_id"]').value;
        const checkboxes = document.querySelectorAll('.client-checkbox');
        const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        if (!newOwner) {
            e.preventDefault();
            alert('Please select a user to assign to before clicking Reassign.');
        } else if (selectedCount === 0) {
            e.preventDefault();
            alert('Please select at least one client to reassign.');
        }
    });
}
</script>

<!-- Meeting Remarks Modal -->
<div id="listMeetingModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        
        <!-- Header -->
        <div class="modal-header">
            <div class="modal-icon">📝</div>
            <div>
                <h3>Meeting Remarks</h3>
                <p>Enter details about the discussion</p>
            </div>
        </div>

        <!-- Body -->
        <input type="hidden" id="current_modal_client_id">

        <textarea
            id="listModalRemarks"
            rows="5"
            placeholder="e.g., Client agreed to increase SIP, follow-up next month..."
        ></textarea>

        <!-- Footer -->
        <div class="modal-actions">
            <button type="button" class="btn btn-reset" onclick="closeListMeetingModal()">
                Cancel
            </button>
            <button type="button" class="btn btn-search" onclick="saveListMeetingRemarks()">
                Save Remarks
            </button>
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

function renderDropdown(names) {
    const dropdown = document.getElementById("client-search-dropdown");
    dropdown.innerHTML = "";

    names.forEach(name => {
        const firstLetter = name.charAt(0);

        const item = document.createElement("div");
        item.className = "search-item";

        item.innerHTML = `
            <div class="search-avatar">${firstLetter}</div>
            <div class="search-name">${name}</div>
        `;

        item.onclick = () => {
            document.getElementById("client-search").value = name;
            dropdown.style.display = "none";
        };

        dropdown.appendChild(item);
    });

    dropdown.style.display = names.length ? "block" : "none";
}

</script>

</body>
</html>
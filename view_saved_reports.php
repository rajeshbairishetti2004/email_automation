<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear
// - Added: Bulk reassignment functionality and split owner columns

require_once 'auth.php';
require_once 'db_config.php';

requireAuth(); // Ensure login

$pdo = getPdo();
$successMessage = '';
$errorMessage = '';

// Handle POST request for bulk reassignment
// Accept either new_owner_id (preferred) or fallback to new_owner
// Ensure valid user is selected
// Sanitize selected IDs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'reassign') {
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

$navUser = $_SESSION['username'] ?? 'User';
$currentPage = basename($_SERVER['PHP_SELF']);
$myId = (int)($_SESSION['user_id'] ?? 0);

$q           = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter      = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$ownerFilter = isset($_GET['owner_filter']) ? trim($_GET['owner_filter']) : 'all';
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
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$validStates = ['pending', 'draft', 'ready', 'reviewed', 'sent'];
if ($filter !== '' && in_array($filter, $validStates, true)) {
    // Explicit filter requested (e.g., user clicked a card)
    $whereParts[] = "c.report_state = ?";
    $params[] = $filter;
}

if ($ownerFilter === 'mine') {
    // Show where user is RM or Reviewer
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = $myId;
    $params[] = $myId;
} elseif ($ownerFilter === 'all' || $ownerFilter === '') {
    // no additional filter, show all clients
} elseif (ctype_digit($ownerFilter)) {
    // Show where selected user is RM or Reviewer
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = (int)$ownerFilter;
    $params[] = (int)$ownerFilter;
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// 1. Count Total Rows
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients c {$where}");
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

$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.as_on, c.created_at, c.updated_at, c.total_amount, c.profit,
           c.report_state, c.review_not_ok, c.review_comment, c.created_by, c.assigned_to, c.review_assigned_to,
           c.priority,
           creator.username AS created_by_username,
           rm.username AS rm_username,
           reviewer.username AS reviewer_username
    FROM clients c
    LEFT JOIN users creator  ON c.created_by = creator.id
    LEFT JOIN users rm       ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    {$where}
    {$orderByClause}
    LIMIT ? OFFSET ?
");

// Add pagination parameters to the params array
$params[] = $limit;
$params[] = $offset;

// Execute with all parameters
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users for reassignment dropdown
$allUsersStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Stored Client Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        body { font-family: 'Inter', sans-serif; margin: 0; padding: 0; background: #f7f9fb; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        h1 { margin-bottom: 20px; font-family: 'Poppins', sans-serif; color: #0288D1; }
        
        table { border-collapse: collapse; width: 100%; margin-top: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        th, td { padding: 12px 15px; font-size: 14px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f1f8e9; color: #333; font-weight: 600; }
        tr:hover { background: #f9f9f9; }
        
        a { text-decoration: none; color: #0056b3; }
        a:hover { text-decoration: underline; }

        .nav-bar { margin-bottom: 20px; }
        .nav-button { display: inline-block; margin-right: 10px; padding: 8px 16px; background-color: #0288D1; color: #fff; border-radius: 5px; text-decoration: none; font-size: 14px; font-weight: 500; }
        .nav-button:hover { background-color: #01579B; }

        .search-box { margin-top: 10px; }
        .search-box input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 250px; }
        .search-box button { padding: 8px 15px; background: #0288D1; color: white; border: none; border-radius: 4px; cursor: pointer; }

        .pagination { margin-top: 20px; font-size: 14px; }
        .pagination a { margin-right: 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; color: #333; }
        .pagination strong { padding: 5px 10px; background: #0288D1; color: white; border-radius: 4px; margin-right: 5px; }
        
        /* Workflow Status Badges */
        .badge { padding: 5px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; border: 1px solid transparent; display: inline-block; min-width: 60px; text-align: center;}
        .badge-pending { background: #fff7ed; color: #9a3412; border-color: #fde68a; }
        .badge-draft { background: #e0e0e0; color: #555; border-color: #ccc; }
        .badge-ready { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .badge-reviewed { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .badge-sent { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .badge-rejected { background: #f8d7da; color: #721c24; border-color: #f5c6cb; cursor: help; }
        
        /* Bulk Actions Bar */
        .bulk-actions-bar {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .bulk-actions-bar select, .bulk-actions-bar button {
            padding: 8px 12px;
            border: 1px solid #999;
            border-radius: 4px;
            font-size: 14px;
        }
        .bulk-actions-bar button {
            background: #4caf50;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        .bulk-actions-bar button:hover {
            background: #388e3c;
        }
        .bulk-selection-info {
            font-size: 13px;
            color: #333;
            font-weight: 500;
        }
        
        /* Message Alerts */
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Checkbox Styling */
        input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        .top-bar {
            background: #fff;
            padding: 15px 30px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .top-bar img {
            height: 40px;
            vertical-align: middle;
            margin-right: 10px;
        }
        .top-bar .brand-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #2c3e50;
            text-decoration: none;
        }
    </style>
</head>
<body>

<nav class="navbar" style="background:#fff;border-bottom:1px solid #e0e0e0;padding:15px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 4px rgba(0,0,0,0.05);margin-bottom:20px;font-family:'Segoe UI',system-ui,sans-serif;">
    <div style="display:flex;align-items:center;">
        <a href="upload.php" class="nav-brand" style="font-size:1.25rem;font-weight:700;color:#2c3e50;text-decoration:none;margin-right:32px;">
            <img src="image.png" alt="Logo" style="height:40px;vertical-align:middle;margin-right:10px;">
            Finance Doctor
        </a>
        <div class="nav-links" style="display:flex;gap:18px;">
            <a href="upload.php" class="<?php echo $currentPage === 'upload.php' ? 'active' : ''; ?>" style="text-decoration:none;color:#555;font-weight:600;<?php echo $currentPage === 'upload.php' ? 'color:#1565c0;' : ''; ?>">Dashboard</a>
            <a href="view_saved_reports.php" class="<?php echo $currentPage === 'view_saved_reports.php' ? 'active' : ''; ?>" style="text-decoration:none;color:#555;font-weight:600;<?php echo $currentPage === 'view_saved_reports.php' ? 'color:#1565c0;' : ''; ?>">All Reports</a>
            <a href="bulk_import.php" class="<?php echo $currentPage === 'bulk_import.php' ? 'active' : ''; ?>" style="text-decoration:none;color:#555;font-weight:600;<?php echo $currentPage === 'bulk_import.php' ? 'color:#1565c0;' : ''; ?>">Bulk Allocate</a>
        </div>
    </div>
    <div class="nav-user" style="display:flex;align-items:center;gap:12px;font-size:0.95rem;color:#777;">
        <span>👤 <?php echo htmlspecialchars($navUser); ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;padding:6px 14px;background-color:#ffebee;color:#c62828;border-radius:6px;font-weight:700;font-size:0.85rem;">Logout</a>
    </div>
</nav>

<div class="container">

    <h1>Stored Client Reports</h1>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form method="get" class="search-box" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
        <input type="text" name="q" placeholder="Search by name or date..." value="<?php echo htmlspecialchars($q); ?>">
        <select name="owner_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
            <option value="all" <?php echo ($ownerFilter === 'all' || $ownerFilter === '') ? 'selected' : ''; ?>>All Owners / Global View</option>
            <option value="mine" <?php echo ($ownerFilter === 'mine') ? 'selected' : ''; ?>>My Reports</option>
            <?php
            $userOptions = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($userOptions as $uOpt): ?>
                <option value="<?php echo (int)$uOpt['id']; ?>" <?php echo ((string)$ownerFilter === (string)$uOpt['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($uOpt['username']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:140px;">
            <option value="">All States</option>
            <option value="pending" <?php echo ($filter === 'pending') ? 'selected' : ''; ?>>Review Not Started</option>
            <option value="draft" <?php echo ($filter === 'draft') ? 'selected' : ''; ?>>Draft</option>
            <option value="ready" <?php echo ($filter === 'ready') ? 'selected' : ''; ?>>Ready</option>
            <option value="reviewed" <?php echo ($filter === 'reviewed') ? 'selected' : ''; ?>>Reviewed</option>
            <option value="sent" <?php echo ($filter === 'sent') ? 'selected' : ''; ?>>Sent</option>
        </select>
        <button type="submit">Search</button>
    </form>

    <?php if (!$clients): ?>
        <p style="margin-top: 20px;">No reports found. Try uploading from <a href="upload.php">Upload Page</a>.</p>
    <?php else: ?>
        <!-- Bulk Reassignment Form wrapping the table -->
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
                <button type="submit">Reassign</button>
            </div>

            <table>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)">
                    </th>
                    <th>
                        <a href="?sort=id&order=<?php echo ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                            ID <?php if ($sortBy === 'id') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                        </a>
                    </th>
                    <th>
                        <a href="?sort=name&order=<?php echo ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                            Client Name <?php if ($sortBy === 'name') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                        </a>
                    </th>
                    <th>Drafted By</th>
                    <th>RM</th>
                    <th>Reviewer</th>
                    <th>
                        <a href="?sort=priority&order=<?php echo ($sortBy === 'priority' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                            Priority <?php if ($sortBy === 'priority') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                        </a>
                    </th>
                    <th>
                        <a href="?sort=updated_at&order=<?php echo ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                            Last Updated <?php if ($sortBy === 'updated_at') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                        </a>
                    </th>
                    <th>
                        <a href="?sort=report_state&order=<?php echo ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc'; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?>" style="color: #333; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                            Status <?php if ($sortBy === 'report_state') echo ($sortOrder === 'ASC' ? '↑' : '↓'); ?>
                        </a>
                    </th>
                    <th>Action</th>
                </tr>
            <?php foreach ($clients as $c): 
                // --- WORKFLOW BADGE LOGIC ---
                $statusHtml = '';
                
                // 1. Check for Rejection first
                if (isset($c['review_not_ok']) && $c['review_not_ok'] == 1) {
                    $comment = htmlspecialchars($c['review_comment'] ?? '');
                    $statusHtml = "<span class='badge badge-rejected' title='RM Comment: $comment'>NOT OK</span>";
                } 
                // 2. Otherwise check state
                else {
                    $state = $c['report_state'] ?? 'draft'; // Default to draft if null
                    $badgeClass = 'badge-' . $state;

                    // Special display names
                    if ($state === 'sent') {
                        $displayText = 'Email Sent';
                    } elseif ($state === 'pending') {
                        $displayText = 'Review Not Started';
                        // ensure class exists for pending visual
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
                    // Check if directory has any files (ignoring . and ..)
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
                    <td>
                        <input type="checkbox" class="client-checkbox" name="selected_ids[]" value="<?php echo (int)$c['id']; ?>">
                    </td>
                    <td><?php echo (int)$c['id']; ?></td>
                    <td>
                        <div style="font-weight: 600; color: #333; display:flex; align-items:center; gap:8px;">
                            <span><?php echo htmlspecialchars($c['name']); ?></span>
                            <?php if($hasAttachments): ?>
                                <span title="Has Attachments">📎</span>
                            <?php endif; ?>
                        </div>
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
                    <td>
                        <?php if (($c['report_state'] ?? '') === 'pending'): ?>
                            <a href="upload.php" style="font-weight: 600; color:#0288D1;">Upload Files</a>
                        <?php else: ?>
                            <a href="view_report.php?id=<?php echo (int)$c['id']; ?>" style="font-weight: 600; color:#0288D1;">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
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
                    if ($q !== '') $params['q'] = $q;
                    if ($filter !== '') $params['filter'] = $filter;
                    if ($ownerFilter !== '') $params['owner_filter'] = $ownerFilter;
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
        updateSelectAllState();
    }

    // Update select all checkbox state based on individual checkboxes
    function updateSelectAllState() {
        const allCheckboxes = document.querySelectorAll('.client-checkbox');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        if (!selectAllCheckbox) return;
        const allChecked = Array.from(allCheckboxes).length > 0 && Array.from(allCheckboxes).every(c => c.checked);
        const someChecked = Array.from(allCheckboxes).some(c => c.checked);
        selectAllCheckbox.checked = allChecked;
        selectAllCheckbox.indeterminate = someChecked && !allChecked;
    }

    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectAllState);
    });

    // Prevent form submission if no owner selected
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
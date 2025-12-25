<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear
// - Added: Bulk reassignment functionality and split owner columns

require_once 'auth.php';
requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$myId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0); // <-- Add this line to define $myId

require_once 'db_config.php';

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

// Update the SELECT query to include meeting_status and meeting_remarks:
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.as_on, c.created_at, c.updated_at, c.total_amount, c.profit,
           c.report_state, c.review_not_ok, c.review_comment, c.created_by, c.assigned_to, c.review_assigned_to,
           c.priority, c.meeting_status, c.meeting_remarks,
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
    <!-- <link rel="stylesheet" href="public/css/styles.css"> -->
    <link rel="stylesheet" href="public/css/view_saved_reports.css">

</head>
<body>

<nav class="navbar">
    <div class="nav-left">
        <div class="top-bar">
            <img src="image.png" alt="Logo" style="height:40px; margin-right:10px;">
            <a href="upload.php" class="nav-brand">Finance Doctor</a>
         </div>
        <div class="nav-links">
            <a href="upload.php">Dashboard</a>
            <a href="view_saved_reports.php" class="active">All Reports</a>
            <a href="bulk_import.php">Bulk Allocate</a>
        </div>
    </div>
     <div class="nav-user" style="position:relative;">
            <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
            <div id="profileDropdown" class="profile-dropdown" style="display:none; position:absolute; right:0; top:36px; background:#fff; border:1px solid #eee; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.07); min-width:180px; z-index:100;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding: 8px 12px 5px;">
                    <?= htmlspecialchars($userDesignation) ?>
                </div>
                <a href="profile.php" style="display:block; padding:8px 12px; text-align:right; color:#0288D1; font-weight:600;">My Profile</a>
                <a href="logout.php" class="logout-link" style="display:block; padding:8px 12px; text-align:right;">Logout</a>
            </div>
        </div>
</nav>
<script>
    // Simple dropdown toggle
    const profilePic = document.getElementById('profilePic');
    const profileDropdown = document.getElementById('profileDropdown');
    document.addEventListener('click', function(e) {
        if (profilePic.contains(e.target)) {
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
        } else if (!profileDropdown.contains(e.target)) {
            profileDropdown.style.display = 'none';
        }
    });
</script>

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
                    <th>Review Assigned to</th>
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
                    <th>Meeting Status</th>
                    <th>Meeting Remarks</th>
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
                        <?php
                            $meetingStatus = $c['meeting_status'] ?? '';
                            if ($meetingStatus === 'yes') {
                                echo '<span style="color: #388e3c; font-weight:600;">Completed</span>';
                            } elseif ($meetingStatus === 'no') {
                                echo '<span style="color: #e53935; font-weight:600;">Not Done</span>';
                            } else {
                                echo '<span style="color: #999;">-</span>';
                            }
                        ?>
                    </td>
                    <td>
                        <?php
                            $remarks = trim($c['meeting_remarks'] ?? '');
                            echo $remarks !== '' ? htmlspecialchars($remarks) : '<span style="color:#999;">-</span>';
                        ?>
                    </td>
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
</body>
</html>
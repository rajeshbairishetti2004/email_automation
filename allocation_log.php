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
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build query for allocation logs with date range
$whereClauses = [];
$params = [];

if (!empty($selectedMonth)) {
    $whereClauses[] = "al.month_year = ?";
    $params[] = $selectedMonth;
} else {
    // Use date range
    $whereClauses[] = "DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
}

// Search filter for all columns
if (!empty($q)) {
    $whereClauses[] = '('
        . "DATE(al.created_at) LIKE ? "
        . "OR u.name LIKE ? "
        . "OR u.username LIKE ? "
        . "OR al.month_year LIKE ? "
        . "OR al.target_tag LIKE ? "
        . "OR al.clients_count LIKE ? "
        . "OR al.assigned_count LIKE ? "
        . "OR al.inserted_count LIKE ? "
        . "OR al.updated_count LIKE ? "
        . "OR al.file_name LIKE ? "
    . ')';
    for ($i = 0; $i < 10; $i++) {
        $params[] = "%$q%";
    }
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get allocation logs
$query = "SELECT al.*, u.name as user_name, u.username 
          FROM allocation_log al 
          LEFT JOIN users u ON al.user_id = u.id 
          $whereSQL 
          ORDER BY al.created_at DESC";

$stmt = $pdo->prepare($query);

// FIXED LINE 125: Execute with parameters directly
try {
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Query error: " . $e->getMessage());
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
    $logs = [];
    $errorMessage = "Error loading allocation logs: " . $e->getMessage();
}

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
        $whereClauses[] = "month_year = ?";
        $params[] = $selectedMonth;
    } else {
        $whereClauses[] = "DATE(created_at) BETWEEN ? AND ?";
        $params[] = $fromDate;
        $params[] = $toDate;
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    try {
        // Basic stats
        $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM allocation_log $whereSQL");
        $countStmt->execute($params);
        $stats['total_allocations'] = $countStmt->fetchColumn();
        
        // Sum of all clients processed
        $sumStmt = $pdo->prepare("SELECT 
            SUM(clients_count) as total_clients,
            SUM(assigned_count) as total_assigned,
            SUM(inserted_count) as total_inserted,
            SUM(updated_count) as total_updated
            FROM allocation_log $whereSQL");
        
        $sumStmt->execute($params);
        $sums = $sumStmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['total_clients_processed'] = $sums['total_clients'] ?? 0;
        $stats['total_clients_assigned'] = $sums['total_assigned'] ?? 0;
        $stats['total_clients_inserted'] = $sums['total_inserted'] ?? 0;
        $stats['total_clients_updated'] = $sums['total_updated'] ?? 0;
        
        // Unique importers
        $userStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM allocation_log $whereSQL");
        $userStmt->execute($params);
        $stats['unique_importers'] = $userStmt->fetchColumn();
        
        // Unique tags
        $tagStmt = $pdo->prepare("SELECT COUNT(DISTINCT target_tag) FROM allocation_log $whereSQL");
        $tagStmt->execute($params);
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
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY month_year 
                ORDER BY month_year DESC
            ");
            $monthlyStmt->execute([$fromDate, $toDate]);
            $stats['monthly_breakdown'] = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Statistics error: " . $e->getMessage());
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
    <link rel="stylesheet" href="public/css/allocation_log.css">
    <link rel="stylesheet" href="public/css/navbar.css">
 <style>
            .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            display: <?php echo !$deleteMode ? 'block' : 'none'; ?>;
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

        
        
        <!-- Filters Section -->
        <div class="filters">
            <form method="get" id="filterForm">
                <?php if ($deleteMode): ?>
                    <input type="hidden" name="delete_mode" value="1">
                <?php endif; ?>
                <?php if (!empty($q)): ?>
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                <?php endif; ?>
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="month">Filter by Month:</label>
                        <select name="month" id="month" onchange="this.form.submit()">
                            <option value="">-- Select Month --</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?php echo htmlspecialchars($month); ?>" <?php echo $selectedMonth == $month ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($month); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date Range (if month not selected):</label>
                        <div class="date-range-picker">
                            <input type="text" name="from_date" class="datepicker date-input" 
                                   value="<?php echo htmlspecialchars($fromDate); ?>" 
                                   placeholder="From Date" 
                                   onchange="document.getElementById('month').value='';">
                            <span>to</span>
                            <input type="text" name="to_date" class="datepicker date-input" 
                                   value="<?php echo htmlspecialchars($toDate); ?>" 
                                   placeholder="To Date"
                                   onchange="document.getElementById('month').value='';">
                        </div>
                    </div>
                    
                    <div class="filter-group" style="display: flex; align-items: flex-end; gap: 10px;">
                        <button type="submit" class="btn-apply">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="allocation_log.php<?php echo $deleteMode ? '?delete_mode=1' : ''; ?>" class="btn-reset">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
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
            const selectedCountElement = document.getElementById('selectedCount');
            if (selectedCountElement) {
                selectedCountElement.textContent = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '') + ' selected';
            }
            
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
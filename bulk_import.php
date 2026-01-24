<?php
// bulk_import.php
// - Uploads "Sample Customer List" format
// - Filters by "Tag/Quarter"
// - Case-Sensitive RM Assignment
// - Added delete functionality with search and selection
// - NEW: Includes allocation_id support for tracking imports
// - UPDATED: Always creates new records instead of updating existing ones

require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');

$currentPage = basename($_SERVER['PHP_SELF']);

// Add this for role check
function isRelationshipManager($designation) {
    return (stripos($designation, 'relationship manager') !== false) &&
           (stripos($designation, 'associate') === false);
}

function getCurrentMonthYear() {
    return date('M Y'); // e.g., "Jan 2026"
}

// Modified logAllocation function to return allocation_id
function logAllocation($pdo, $userId, $monthYear, $targetTag, $summary, $fileName = null) {
    try {
$stmt = $pdo->prepare("
    INSERT INTO allocation_log 
    (user_id, month_year, target_tag, file_name, clients_count, 
     assigned_count, unassigned_count, inserted_count, updated_count) 
    VALUES (:user_id, :month_year, :target_tag, :file_name, :clients_count,
            :assigned_count, :unassigned_count, :inserted_count, :updated_count)
");
        
$stmt->execute([
    ':user_id' => $userId,
    ':month_year' => $monthYear,    
    ':target_tag' => $targetTag,
    ':file_name' => $fileName,
    ':clients_count' => $summary['processed'],
    ':assigned_count' => $summary['assigned'],
    ':unassigned_count' => $summary['unassigned'],  // Add this
    ':inserted_count' => $summary['inserted'],
    ':updated_count' => $summary['updated']
]);
        // Return the allocation ID
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Failed to log allocation: " . $e->getMessage());
        return 0;
    }
}

$pdo = getPdo();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Initialize Summary Stats
$summary = [
    'processed'   => 0,
    'assigned'    => 0,
    'unassigned'  => 0,
    'inserted'    => 0,
    'updated'     => 0,
    'skipped'     => 0,
    'duplicates'  => 0,
    'errors'      => [],
];

// NEW: Store allocation_id for this import
$allocationId = 0;

// Handle AJAX requests for client list and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'get_clients_list') {
        $search = $_POST['search'] ?? '';
        try {
           $sql = "SELECT id, name, email, assigned_to, updated_at FROM clients WHERE 1=1";
            $params = [];
            if (!empty($search)) {
                $sql .= " AND (name LIKE :search OR email LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            $sql .= " ORDER BY name LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Also return total count for UI
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients" . (!empty($search) ? " WHERE name LIKE :search OR email LIKE :search" : ""));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'clients' => $clients,
                'total_count' => (int)$totalCount
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Get all client IDs (for select all)
    if ($_POST['ajax_action'] === 'get_all_client_ids') {
        $search = $_POST['search'] ?? '';
        try {
            $sql = "SELECT id FROM clients WHERE 1=1";
            $params = [];
            if (!empty($search)) {
                $sql .= " AND (name LIKE :search OR email LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode([
                'success' => true,
                'ids' => $ids
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'delete_clients') {
        $clientIds = $_POST['client_ids'] ?? [];
        if (!is_array($clientIds)) {
            $clientIds = [$clientIds];
        }
        if (empty($clientIds)) {
            echo json_encode(['success' => false, 'error' => 'No clients selected']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
            $stmt->execute($clientIds);
            $placeholdersGoals = implode(',', array_fill(0, count($clientIds), '?'));
            $stmtGoals = $pdo->prepare("DELETE FROM client_goals WHERE client_id IN ($placeholdersGoals)");
            $stmtGoals->execute($clientIds);
            $placeholdersSchemes = implode(',', array_fill(0, count($clientIds), '?'));
            $stmtSchemes = $pdo->prepare("DELETE FROM client_schemes WHERE client_id IN ($placeholdersSchemes)");
            $stmtSchemes->execute($clientIds);
            $placeholdersAnnex = implode(',', array_fill(0, count($clientIds), '?'));
            $stmtAnnex = $pdo->prepare("DELETE FROM client_annexures WHERE client_id IN ($placeholdersAnnex)");
            $stmtAnnex->execute($clientIds);
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Selected clients deleted successfully',
                'deleted_count' => count($clientIds)
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Deletion failed: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['allocation_file'])) {

    $targetTag = trim($_POST['target_tag'] ?? '');

    if (empty($targetTag)) {
        $summary['errors'][] = "Please specify a Target Quarter/Tag (e.g., RJ) to import.";
    } else {
        $file = $_FILES['allocation_file']['tmp_name'];
        $monthYear = getCurrentMonthYear();
        $fileName = $_FILES['allocation_file']['name'];

        try {
            // NEW: Create allocation log entry BEFORE processing
$allocationId = logAllocation(
    $pdo,
    $currentUserId,
    $monthYear,
    $targetTag,
    ['processed' => 0, 'assigned' => 0, 'unassigned' => 0, 'inserted' => 0, 'updated' => 0],
    $fileName
);

            if (!$allocationId) {
                throw new Exception("Failed to create allocation log entry");
            }

            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            // NEW: Prepare statements with allocation_id (UPDATED VERSION)
            
            // Check if client exists in any month (for reference)
            $checkPrevStmt = $pdo->prepare("
                SELECT id, email, assigned_to, review_cycle, aum, priority 
                FROM clients 
                WHERE name = :name 
                ORDER BY created_at DESC LIMIT 1
            ");
            
            // NEW: Check for exact duplicates (same name, month, allocation)
            $checkDuplicateStmt = $pdo->prepare("
                SELECT id FROM clients 
                WHERE name = :name 
                AND month_year = :month_year 
                AND allocation_id = :allocation_id 
                LIMIT 1
            ");
            
            // ALWAYS INSERT - never update
            $insertStmt = $pdo->prepare("
                INSERT INTO clients 
                (name, email, assigned_to, review_assigned_to, total_amount, aum, priority, 
                 review_cycle, report_state, created_at, created_by, month_year, 
                 original_client_id, allocation_id, is_latest) 
                VALUES (:name, :email, :assign, :reviewer, :aum, :aum_val, :prio, 
                        :cycle, 'pending', NOW(), :creator, :month_year, 
                        :original_id, :allocation_id, 1)
            ");
            
            // Update is_latest for previous records of this client
            $updatePreviousLatestStmt = $pdo->prepare("
                UPDATE clients 
                SET is_latest = 0, updated_at = NOW()
                WHERE name = :name 
                AND is_latest = 1
            ");

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex < 2) continue; // Skip Header

                $rawName     = trim($row['B'] ?? '');
                $rawRM       = trim($row['H'] ?? '');
                $rawReviewer = trim($row['I'] ?? '');
                $rawTag      = trim($row['E'] ?? '');
                $rawAum      = $row['G'] ?? 0;
                $rawPriority = trim($row['A'] ?? '');

                if (empty($rawName)) continue;

                // FILTER BY TAG/QUARTER
                if (stripos($rawTag, $targetTag) === false) {
                    $summary['skipped']++;
                    continue;
                }

                $summary['processed']++;

                // 1. FETCH USERS (Smart Matching)
                $allUsers = [];
                $uStmt = $pdo->query("SELECT id, username, name FROM users");
                while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
                    $usernameKey = strtolower(trim($uRow['username']));
                    $fullnameKey = strtolower(trim($uRow['name']));
                    $allUsers[$usernameKey] = $uRow['id'];
                    if (!empty($fullnameKey)) {
                        $allUsers[$fullnameKey] = $uRow['id'];
                    }
                }

                // SMART LOOKUP
                $assignedToId = null;
                $reviewerId   = null;
                $rmKey        = strtolower($rawRM);
                $revKey       = strtolower($rawReviewer);

                if (!empty($rmKey) && isset($allUsers[$rmKey])) {
                    $assignedToId = $allUsers[$rmKey];  
                    $summary['assigned']++;
                } else {
                    $summary['unassigned']++;
                }
                
                if (!empty($revKey) && isset($allUsers[$revKey])) {
                    $reviewerId = $allUsers[$revKey];
                }

                // --- AUM CLEANING & CRORE CONVERSION ---
                $cleanAumVal = preg_replace('/[^-0-9.]/', '', (string)$rawAum);
                $numericAum  = (float)$cleanAumVal;
                $aumInCrores = $numericAum; // Conversion Logic

                $reviewCycleValue = !empty($rawTag) ? strtoupper($rawTag) : null;

                // --- NEW LOGIC: Always create new record ---
                
                // Check for exact duplicates first
                $checkDuplicateStmt->execute([
                    ':name' => $rawName,
                    ':month_year' => $monthYear,
                    ':allocation_id' => $allocationId
                ]);
                $duplicateExists = $checkDuplicateStmt->fetchColumn();
                
                if ($duplicateExists) {
                    // Skip if exact duplicate already exists (same allocation)
                    $summary['duplicates']++;
                    continue;
                }
                
                // Check if client exists in any previous record (for reference)
                $checkPrevStmt->execute([':name' => $rawName]);
                $previousClient = $checkPrevStmt->fetch(PDO::FETCH_ASSOC);
                
                // Get previous client's info for continuity
                $clientEmail = null;
                $originalClientId = null;
                if ($previousClient) {
                    $clientEmail = $previousClient['email'] ?? null;
                    $originalClientId = $previousClient['id'] ?? null;
                    
                    // Mark all previous records as not latest
                    $updatePreviousLatestStmt->execute([':name' => $rawName]);
                }
                
                // --- PRIORITY CARRY-FORWARD LOGIC ---
                // If client exists before, use previous priority, otherwise use new priority
                $finalPriority = $rawPriority;
                if ($previousClient && !empty($previousClient['priority'])) {
                    $finalPriority = $previousClient['priority'];
                }
                if (empty($finalPriority)) {
                    $finalPriority = 'Normal';
                }

                // Always insert new record for current month
                try {
                    $insertStmt->execute([
                        ':name' => $rawName,
                        ':email' => $clientEmail,
                        ':assign' => $assignedToId,
                        ':reviewer' => $reviewerId,
                        ':aum' => $aumInCrores,
                        ':aum_val' => $aumInCrores,
                        ':prio' => $finalPriority,
                        ':cycle' => $reviewCycleValue,
                        ':creator' => $currentUserId,
                        ':month_year' => $monthYear,
                        ':original_id' => $originalClientId,
                        ':allocation_id' => $allocationId
                    ]);
                    $summary['inserted']++;
                    
                } catch (Exception $e) {
                    $summary['errors'][] = "Failed to insert client '{$rawName}': " . $e->getMessage();
                    $summary['skipped']++;
                }
            }

$updateAllocStmt = $pdo->prepare("
    UPDATE allocation_log 
    SET clients_count = :clients_count,
        assigned_count = :assigned_count,
        unassigned_count = :unassigned_count,
        inserted_count = :inserted_count,
        updated_count = :updated_count
    WHERE id = :id
");

$updateAllocStmt->execute([
    ':clients_count' => $summary['processed'],
    ':assigned_count' => $summary['assigned'],
    ':unassigned_count' => $summary['unassigned'],  // Add this
    ':inserted_count' => $summary['inserted'],
    ':updated_count' => $summary['updated'],
    ':id' => $allocationId
]);
        } catch (Exception $e) {
            // NEW: Delete allocation log if import failed
            if ($allocationId > 0) {
                try {
                    $pdo->prepare("DELETE FROM allocation_log WHERE id = ?")->execute([$allocationId]);
                } catch (Exception $deleteErr) {
                    error_log("Failed to delete failed allocation log: " . $deleteErr->getMessage());
                }
            }
            $summary['errors'][] = "Error processing file: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Allocation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    <link rel="stylesheet" href="public/css/bulk_import.css">
</head>
<body>

  <?php include 'navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2>Bulk Client Allocation</h2>
            <?php if (isRelationshipManager($userDesignation)): ?>
            <button type="button" class="delete-toggle-btn" onclick="openDeleteModal()">
                <i class="fas fa-trash-alt"></i> Delete Clients
            </button>
            <?php endif; ?>
        </div>
        
        <p style="color:#666; margin-bottom: 25px;">Upload the "<?php echo date('M Y'); ?>" Customer List format to assign tasks.</p>
        
        <div class="info-note">
            <i class="fas fa-info-circle"></i> <strong>Important:</strong> This import will always create new records. 
            Existing clients will get new entries while preserving old data. Duplicate records (same client in same allocation) will be skipped.
        </div>

        <form method="post" enctype="multipart/form-data">
            
            <div class="form-group">
                <label>1. Enter Quarter/Tag to Import (Required)</label>
                <input type="text" name="target_tag" placeholder="e.g. RJ, RM, or RF" required>
                <small style="color:#888;">Only clients with this exact tag in Column E will be imported.</small>
            </div>

            <div class="form-group">
                <label>2. Select Excel File (.xlsx)</label>
                <input type="file" name="allocation_file" accept=".xlsx, .xls" required>
            </div>

            <button type="submit">Import & Allocate</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['allocation_file'])): ?>
            <div class="summary">
                <h4>Import Result for Tag: "<?php echo htmlspecialchars($targetTag); ?>"</h4>
<div><strong>Processed:</strong> <?php echo (int)$summary['processed']; ?> 
    (Skipped: <?php echo (int)$summary['skipped']; ?>)</div>
                <div style="margin-top:5px;"><strong>Assigned:</strong> <?php echo (int)$summary['assigned']; ?> | 
                    <strong>Unassigned:</strong> <?php echo (int)$summary['unassigned']; ?></div>
                <div style="margin-top:5px;"><strong>New Clients Created:</strong> <?php echo (int)$summary['inserted']; ?></div>
                
                <?php if (!empty($summary['errors'])): ?>
                    <div class="error" style="margin-top:15px;">
                        <strong>Errors:</strong>
                        <ul>
                            <?php foreach ($summary['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if ($allocationId > 0 && empty($summary['errors'])): ?>
                <div class="allocation-info show" id="allocationInfo">
                    <strong><i class="fas fa-info-circle"></i> Allocation Created</strong>
                    <p>This import has been logged with Allocation ID: <strong>#<?php echo $allocationId; ?></strong></p>
                    <p>All client data has been preserved. New records have been created for this month.</p>
                    <a href="allocation_log.php" class="allocation-link">
                        <i class="fas fa-external-link-alt"></i> View Allocation Log
                    </a>
                    <a href="view_allocation_clients.php?id=<?php echo $allocationId; ?>" target="_blank" class="allocation-link" style="margin-left: 10px;">
                        <i class="fas fa-eye"></i> View Clients in this Allocation
                    </a>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div id="successMessage" class="success-message"></div>
        <div id="errorMessage" class="error-message"></div>
    </div>

    <!-- Delete Modal -->
    <?php if (isRelationshipManager($userDesignation)): ?>
    <div id="deleteModal" class="delete-modal-overlay">
        <div class="delete-modal">
            <div class="delete-modal-header">
                <h3><i class="fas fa-trash-alt"></i> Delete Clients</h3>
                <button type="button" class="close-modal" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="delete-modal-body">
                <div class="search-section">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" 
                               id="clientSearch" 
                               class="search-input" 
                               placeholder="Search clients by name or email...">
                    </div>
                </div>
                <!-- Select All Row -->
                <div class="select-all-row" id="selectAllRow" style="display:none;">
                    <input type="checkbox" id="selectAllCheckbox" class="select-all-checkbox" onchange="toggleSelectAll(this)">
                    <label for="selectAllCheckbox" style="cursor:pointer;">Select All (visible)</label>
                    <span id="selectAllAll" class="select-all-all-label" style="display:none;" onclick="selectAllClientsFromServer()">Select All (<span id="totalClientsCount"></span> clients)</span>
                </div>
                <div class="clients-list-container" id="clientsList">
                    <!-- Client list will be loaded here -->
                    <div class="no-clients">Start typing to search for clients...</div>
                </div>
                
                <div class="delete-actions">
                    <div class="selection-count">
                        Selected: <span id="selectedCount">0</span> clients
                    </div>
                    <div class="delete-actions-buttons">
                        <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                        <button type="button" class="btn-delete" id="deleteBtn" onclick="confirmDelete()" disabled>
                            <i class="fas fa-trash-alt"></i> Delete Selected
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Confirmation Modal -->
    <div id="confirmationModal" class="delete-modal-overlay" style="display:none;">
        <div class="confirmation-modal">
            <div class="confirmation-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="confirmation-text" id="confirmationText">
                Are you sure you want to delete <span id="deleteCount">0</span> client(s)?<br>
                <small style="color:#dc3545;">This action cannot be undone!</small>
            </div>
            <div class="confirmation-buttons">
                <button type="button" class="btn-no" onclick="closeConfirmationModal()">No, Cancel</button>
                <button type="button" class="btn-yes" onclick="deleteSelectedClients()">Yes, Delete</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Delete functionality
        let allClients = [];
        let selectedClients = new Set();
        let searchTimeout = null;
        let totalClientsCount = 0;
        let allClientIdsFetched = false;

        function openDeleteModal() {
            document.getElementById('deleteModal').style.display = 'flex';
            document.getElementById('clientSearch').focus();
            loadClients('');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.getElementById('clientSearch').value = '';
            selectedClients.clear();
            updateSelectedCount();
        }
        
        function loadClients(searchTerm = '') {
            fetch('bulk_import.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    ajax_action: 'get_clients_list',
                    search: searchTerm
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allClients = data.clients;
                    totalClientsCount = data.total_count || allClients.length;
                    document.getElementById('totalClientsCount').textContent = totalClientsCount;
                    // Show "Select All (all N clients)" if more than 50
                    document.getElementById('selectAllAll').style.display = (totalClientsCount > 50) ? 'inline' : 'none';
                    allClientIdsFetched = false;
                    renderClientList(data.clients);
                } else {
                    showError(data.error || 'Failed to load clients');
                }
            })
            .catch(error => {
                showError('Network error: ' + error.message);
            });
        }

        // Select All logic (visible)
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.client-checkbox');
            if (checkbox.checked) {
                checkboxes.forEach(cb => {
                    cb.checked = true;
                    selectedClients.add(parseInt(cb.value));
                });
            } else {
                checkboxes.forEach(cb => {
                    cb.checked = false;
                    selectedClients.delete(parseInt(cb.value));
                });
            }
            updateSelectedCount();
        }

        // Select All (all clients in DB)
        function selectAllClientsFromServer() {
            const searchTerm = document.getElementById('clientSearch').value;
            fetch('bulk_import.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    ajax_action: 'get_all_client_ids',
                    search: searchTerm
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Use string IDs for consistency with backend
                    selectedClients = new Set(data.ids.map(id => parseInt(id)));
                    allClientIdsFetched = true;
                    // Check all visible checkboxes
                    const checkboxes = document.querySelectorAll('.client-checkbox');
                    checkboxes.forEach(cb => {
                        cb.checked = true;
                    });
                    document.getElementById('selectAllCheckbox').checked = true;
                    updateSelectedCount();
                } else {
                    showError(data.error || 'Failed to select all clients');
                }
            })
            .catch(error => {
                showError('Network error: ' + error.message);
            });
        }

        function renderClientList(clients) {
            const container = document.getElementById('clientsList');
            const selectAllRow = document.getElementById('selectAllRow');
            if (clients.length === 0) {
                container.innerHTML = '<div class="no-clients">No clients found</div>';
                selectAllRow.style.display = 'none';
                return;
            }
            selectAllRow.style.display = 'flex';
            // If all visible clients are selected, check the select all checkbox
            const allIds = clients.map(c => c.id);
            const allSelected = allIds.every(id => selectedClients.has(parseInt(id)));
            document.getElementById('selectAllCheckbox').checked = allSelected;

            let html = '';
            clients.forEach(client => {
                const isSelected = selectedClients.has(parseInt(client.id));
html += `
    <div class="client-checkbox-item">
        <input type="checkbox" 
               class="client-checkbox" 
               id="client_${client.id}"
               value="${client.id}"
               ${isSelected ? 'checked' : ''}
               onchange="toggleClientSelection(${client.id})">
        <div class="client-info">
            <div class="client-details">
                <div class="client-name">${escapeHtml(client.name)}</div>
                ${client.email ? `<div class="client-email">${escapeHtml(client.email)}</div>` : ''}
            </div>
            <div class="client-meta">
                <div style="font-size: 12px; color: #666; font-weight: 600; margin-bottom: 3px;">
                    ID: ${client.id}
                </div>
                ${client.updated_at ? `<div style="font-size: 11px; color: #888;">
                    ${formatDate(client.updated_at)}
                </div>` : '<div style="font-size: 11px; color: #888;">Never</div>'}
            </div>
        </div>
    </div>
`;
            });
            
            container.innerHTML = html;
        }

        function toggleClientSelection(clientId) {
            if (selectedClients.has(clientId)) {
                selectedClients.delete(clientId);
            } else {
                selectedClients.add(clientId);
            }
            // Update select all checkbox state
            const checkboxes = document.querySelectorAll('.client-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            document.getElementById('selectAllCheckbox').checked = allChecked;
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const count = selectedClients.size;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('deleteBtn').disabled = count === 0;
        }
        
        function confirmDelete() {
            const count = selectedClients.size;
            document.getElementById('deleteCount').textContent = count;
            document.getElementById('confirmationModal').style.display = 'flex';
        }
        
        function closeConfirmationModal() {
            document.getElementById('confirmationModal').style.display = 'none';
        }
        
        function deleteSelectedClients() {
            const clientIds = Array.from(selectedClients);
            if (clientIds.length === 0) {
                showError('No clients selected');
                return;
            }
            const params = new URLSearchParams();
            params.append('ajax_action', 'delete_clients');
            clientIds.forEach(id => params.append('client_ids[]', id));
            fetch('bulk_import.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(`Successfully deleted ${data.deleted_count} client(s)`);
                    closeConfirmationModal();
                    // After deletion, reload the client list and keep modal open if there are still clients
                    const currentSearch = document.getElementById('clientSearch').value;
                    loadClients(currentSearch);
                    selectedClients.clear();
                    updateSelectedCount();
                    // If all clients are deleted, close the modal
                    setTimeout(() => {
                        if (totalClientsCount === data.deleted_count) {
                            closeDeleteModal();
                        }
                    }, 500);
                } else {
                    showError(data.error || 'Failed to delete clients');
                    closeConfirmationModal();
                }
            })
            .catch(error => {
                showError('Network error: ' + error.message);
                closeConfirmationModal();
            });
        }
        
        function showSuccess(message) {
            const successDiv = document.getElementById('successMessage');
            successDiv.textContent = message;
            successDiv.style.display = 'block';
            successDiv.scrollIntoView({ behavior: 'smooth' });
            
            setTimeout(() => {
                successDiv.style.display = 'none';
            }, 5000);
        }
        
        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            errorDiv.scrollIntoView({ behavior: 'smooth' });
            
            setTimeout(() => {
                errorDiv.style.display = 'none';
            }, 5000);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Search functionality with debounce
        document.getElementById('clientSearch').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadClients(e.target.value);
            }, 300);
        });
        
        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            const deleteModal = document.getElementById('deleteModal');
            const confirmationModal = document.getElementById('confirmationModal');
            
            if (deleteModal.style.display === 'flex' && e.target === deleteModal) {
                closeDeleteModal();
            }
            
            if (confirmationModal.style.display === 'flex' && e.target === confirmationModal) {
                closeConfirmationModal();
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('confirmationModal').style.display === 'flex') {
                    closeConfirmationModal();
                } else if (document.getElementById('deleteModal').style.display === 'flex') {
                    closeDeleteModal();
                }
            }
        });
        
        function formatDate(dateString) {
            if (!dateString) return 'Never';
            const date = new Date(dateString);
            
            const day = date.getDate().toString().padStart(2, '0');
            const month = date.toLocaleString('en-GB', { month: 'short' });
            const year = date.getFullYear();
            const hours = date.getHours().toString().padStart(2, '0');
            const minutes = date.getMinutes().toString().padStart(2, '0');
            
            return `${day} ${month} ${year}, ${hours}:${minutes}`;
        }
    </script>
</body>
</html>
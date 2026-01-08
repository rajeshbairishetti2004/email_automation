<?php
// bulk_import.php
// - Uploads "Sample Customer List" format
// - Filters by "Tag/Quarter"
// - Case-Sensitive RM Assignment
// - Added delete functionality with search and selection

require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');

// Add this for role check
function isRelationshipManager($designation) {
    return (stripos($designation, 'relationship manager') !== false) &&
           (stripos($designation, 'associate') === false);
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
    'errors'      => [],
];

// Handle AJAX requests for client list and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'get_clients_list') {
        $search = $_POST['search'] ?? '';
        try {
            $sql = "SELECT id, name, email, assigned_to FROM clients WHERE 1=1";
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

    // New: Get all client IDs (for select all)
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
        
        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

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
                    // Map BOTH "username" and "name" to the User ID (Lowercase for flexibility)
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
                $aumInCrores = $numericAum / 10000000; // Conversion Logic

                $reviewCycleValue = !empty($rawTag) ? strtoupper($rawTag) : null;

                // DB UPSERT
                $chk = $pdo->prepare("SELECT id FROM clients WHERE name = :name LIMIT 1");
                $chk->execute([':name' => $rawName]);
                $exists = $chk->fetchColumn();

                if ($exists) {
                    $upd = $pdo->prepare("UPDATE clients SET assigned_to=:assign, review_assigned_to=:reviewer, total_amount=:aum, aum=:aum_val, priority=:prio, review_cycle=:cycle, updated_at=NOW() WHERE id=:id");
                    $upd->execute([':assign'=>$assignedToId, ':reviewer'=>$reviewerId, ':aum'=>$aumInCrores, ':aum_val'=>$aumInCrores, ':prio'=>$rawPriority, ':cycle'=>$reviewCycleValue, ':id'=>(int)$exists]);
                    $summary['updated']++;
                } else {
                    $ins = $pdo->prepare("INSERT INTO clients (name, assigned_to, review_assigned_to, total_amount, aum, priority, review_cycle, report_state, created_at, created_by) VALUES (:name, :assign, :reviewer, :aum, :aum_val, :prio, :cycle, 'pending', NOW(), :creator)");
                    $ins->execute([':name'=>$rawName, ':assign'=>$assignedToId, ':reviewer'=>$reviewerId, ':aum'=>$aumInCrores, ':aum_val'=>$aumInCrores, ':prio'=>$rawPriority, ':cycle'=>$reviewCycleValue, ':creator'=>$currentUserId]);
                    $summary['inserted']++;
                }
            }

        } catch (Exception $e) {
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
            max-width: 1200px;
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
        
        .delete-toggle-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .delete-toggle-btn:hover {
            background: #c82333;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
        }
        
        .form-group input[type="text"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: border 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #0288D1;
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
        }
        
        button[type="submit"] {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            width: 100%;
        }
        
        button[type="submit"]:hover {
            background: #218838;
        }
        
        .summary {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #28a745;
        }
        
        .summary h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
            border-left: 4px solid #0288D1;
        }
        
        .info-box strong {
            color: #0288D1;
            display: block;
            margin-bottom: 10px;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            border-left: 4px solid #dc3545;
        }
        
        /* Delete Modal Styles */
        .delete-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .delete-modal {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .delete-modal-header {
            padding: 20px 25px;
            background: #dc3545;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .delete-modal-header h3 {
            margin: 0;
            font-size: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .delete-modal-body {
            padding: 25px;
        }
        
        .search-section {
            margin-bottom: 20px;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .clients-list-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 6px;
            padding: 10px;
        }
        
        .client-checkbox-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .client-checkbox-item:hover {
            background: #f8f9fa;
        }
        
        .client-checkbox-item:last-child {
            border-bottom: none;
        }
        
        .client-checkbox {
            margin-right: 15px;
            transform: scale(1.2);
        }
        
        .client-info {
            flex: 1;
        }
        
        .client-name {
            font-weight: 500;
            color: #333;
        }
        
        .client-email {
            font-size: 13px;
            color: #666;
            margin-top: 3px;
        }
        
        .no-clients {
            text-align: center;
            padding: 30px;
            color: #999;
            font-style: italic;
        }
        
        .delete-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .selection-count {
            font-size: 14px;
            color: #666;
        }
        
        .delete-actions-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .btn-delete:hover {
            background: #c82333;
        }
        
        .btn-delete:disabled {
            background: #e6a0a8;
            cursor: not-allowed;
        }
        
        .confirmation-modal {
            background: white;
            border-radius: 8px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            text-align: center;
        }
        
        .confirmation-icon {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .confirmation-text {
            font-size: 18px;
            color: #333;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .confirmation-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .btn-no {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-yes {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-no:hover {
            background: #5a6268;
        }
        
        .btn-yes:hover {
            background: #c82333;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            border-left: 4px solid #28a745;
            display: none;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            border-left: 4px solid #dc3545;
            display: none;
        }

        /* Add style for select all row */
        .select-all-row {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            background: #f5f7fa;
            border-bottom: 1px solid #eee;
            font-weight: 500;
            color: #0288D1;
        }
        .select-all-checkbox {
            margin-right: 15px;
            transform: scale(1.2);
        }
        .select-all-all-label {
            margin-left: 10px;
            color: #dc3545;
            font-weight: 600;
            cursor: pointer;
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
                <a href="upload.php" class="<?php echo $currentPage === 'upload.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="view_saved_reports.php" class="<?php echo $currentPage === 'view_saved_reports.php' ? 'active' : ''; ?>">All Reports</a>
                <a href="bulk_import.php" class="<?php echo $currentPage === 'bulk_import.php' ? 'active' : ''; ?>">Bulk Allocate</a>
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
            <h2>Bulk Client Allocation</h2>
            <?php if (isRelationshipManager($userDesignation)): ?>
            <button type="button" class="delete-toggle-btn" onclick="openDeleteModal()">
                <i class="fas fa-trash-alt"></i> Delete Clients
            </button>
            <?php endif; ?>
        </div>
        
        <p style="color:#666; margin-bottom: 25px;">Upload the "<?php echo date('M Y'); ?>" Customer List format to assign tasks.</p>

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
                <div><strong>Processed:</strong> <?php echo (int)$summary['processed']; ?> (Skipped: <?php echo (int)$summary['skipped']; ?>)</div>
                <div style="margin-top:5px;"><strong>Assigned:</strong> <?php echo (int)$summary['assigned']; ?> | <strong>Unassigned:</strong> <?php echo (int)$summary['unassigned']; ?></div>
                <div style="margin-top:5px;"><strong>New Clients:</strong> <?php echo (int)$summary['inserted']; ?> | <strong>Updated:</strong> <?php echo (int)$summary['updated']; ?></div>
                
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
        // Profile dropdown
        const profilePic = document.getElementById('profilePic');
        const profileDropdown = document.getElementById('profileDropdown');
        
        profilePic.addEventListener('click', function(e) {
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
            e.stopPropagation();
        });
        
        document.addEventListener('click', function() {
            profileDropdown.style.display = 'none';
        });
        
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
                            <div class="client-name">${escapeHtml(client.name)}</div>
                            ${client.email ? `<div class="client-email">${escapeHtml(client.email)}</div>` : ''}
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Select All logic
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
        
        // Select all functionality (optional)
        function selectAllClients() {
            const checkboxes = document.querySelectorAll('.client-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                selectedClients.add(parseInt(checkbox.value));
            });
            updateSelectedCount();
        }
        
        function clearAllSelection() {
            const checkboxes = document.querySelectorAll('.client-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectedClients.clear();
            updateSelectedCount();
        }
        
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
    </script>
</body>
</html>
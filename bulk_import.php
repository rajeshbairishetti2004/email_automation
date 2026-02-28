<?php
// bulk_import.php
// - Uploads client list Excel files (any format)
// - Maps columns by HEADER NAME (not column order) — works with any file layout
// - Filters by "Tag/Quarter" — composite tags like "RF, NRI" or "OldM, RF" are treated as the target tag
// - review_cycle always stores the clean target tag (e.g. "RF"), not the raw composite value
// - Case-Sensitive RM Assignment
// - Includes allocation_id support for tracking imports
// - Always creates new records instead of updating existing ones

require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');

$username = strtolower($currentUser['username'] ?? '');
if ($username !== 'admin') {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

function isRelationshipManager($designation)
{
    return (stripos($designation, 'relationship manager') !== false) &&
        (stripos($designation, 'associate') === false);
}

function getCurrentMonthYear()
{
    return date('M Y'); // e.g., "Jan 2026"
}

function logAllocation($pdo, $userId, $monthYear, $targetTag, $summary, $fileName = null)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO allocation_log 
            (user_id, month_year, target_tag, file_name, clients_count, 
             assigned_count, unassigned_count, inserted_count, updated_count) 
            VALUES (:user_id, :month_year, :target_tag, :file_name, :clients_count,
                    :assigned_count, :unassigned_count, :inserted_count, :updated_count)
        ");

        $stmt->execute([
            ':user_id'          => $userId,
            ':month_year'       => $monthYear,
            ':target_tag'       => $targetTag,
            ':file_name'        => $fileName,
            ':clients_count'    => $summary['processed'],
            ':assigned_count'   => $summary['assigned'],
            ':unassigned_count' => $summary['unassigned'],
            ':inserted_count'   => $summary['inserted'],
            ':updated_count'    => $summary['updated']
        ]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Failed to log allocation: " . $e->getMessage());
        return 0;
    }
}

/**
 * Build a header-name → column-index map from the first row of the sheet.
 * Keys are trimmed & lowercased for case-insensitive matching.
 * Multiple spaces are normalised to a single space.
 * Returns: [ 'normalised header' => zero-based column index, ... ]
 */
function buildHeaderMap(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $colIndex => $cellValue) {
        if ($cellValue !== null && $cellValue !== '') {

            $key = (string)$cellValue;

            // Remove BOM
            $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);

            // Remove non-breaking spaces
            $key = str_replace("\xC2\xA0", ' ', $key);

            // Normalize
            $key = strtolower(trim($key));
            $key = preg_replace('/\s+/', ' ', $key);

            $map[$key] = $colIndex;
        }
    }
    return $map;
}
/**
 * Safely retrieve a value from a data row using the header map.
 * $headerKey is normalised internally (lowercase, single-space).
 * Returns trimmed string, or $default if column not found / cell empty.
 */
function getCol(array $row, array $headerMap, string $headerKey, $default = null)
{
    $normKey = preg_replace('/\s+/', ' ', strtolower(trim($headerKey)));
    if (!isset($headerMap[$normKey])) {
        return $default;
    }
    $val = $row[$headerMap[$normKey]] ?? $default;
    return ($val !== null && $val !== '') ? trim((string)$val) : $default;
}

$pdo = getPdo();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

$summary = [
    'processed'  => 0,
    'assigned'   => 0,
    'unassigned' => 0,
    'inserted'   => 0,
    'updated'    => 0,
    'skipped'    => 0,
    'duplicates' => 0,
    'errors'     => [],
];

$allocationId = 0;

// ─────────────────────────────────────────────
// Handle AJAX requests for client list
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'get_clients_list') {
        $search = $_POST['search'] ?? '';
        try {
            $sql    = "SELECT id, name, email, assigned_to, updated_at FROM clients WHERE 1=1";
            $params = [];
            if (!empty($search)) {
                $sql               .= " AND (name LIKE :search OR email LIKE :search)";
                $params[':search']  = '%' . $search . '%';
            }
            $sql .= " ORDER BY name LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM clients" .
                    (!empty($search) ? " WHERE name LIKE :search OR email LIKE :search" : "")
            );
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();

            echo json_encode([
                'success'     => true,
                'clients'     => $clients,
                'total_count' => (int)$totalCount
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ─────────────────────────────────────────────
// Handle file upload and import
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['allocation_file'])) {

    $targetTag = strtoupper(trim($_POST['target_tag'] ?? ''));

    if (empty($targetTag)) {
        $summary['errors'][] = "Please specify a Target Quarter/Tag (e.g., RJ) to import.";
    } else {
        $file      = $_FILES['allocation_file']['tmp_name'];
        $monthYear = getCurrentMonthYear();
        $fileName  = $_FILES['allocation_file']['name'];

        // ── KEY FIX ──────────────────────────────────────────────────────────
        // Always store the plain target tag as review_cycle (e.g. "RF"),
        // NOT the raw composite cell value (e.g. "RF, NRI", "OldM, RF").
        // This ensures view_allocation_clients.php can filter with
        // WHERE review_cycle = 'RF' and correctly finds ALL 157 clients.
        // ─────────────────────────────────────────────────────────────────────
        $cleanReviewCycle = $targetTag;

        try {
            // Create allocation log entry BEFORE processing
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
            $sheet       = $spreadsheet->getActiveSheet();
            $allRows = $sheet->toArray(null, true, true, false);
            if (empty($allRows)) {
                throw new Exception("The uploaded file is empty.");
            }


            $headerRow = $allRows[0];
            $headerMap = buildHeaderMap($headerRow);

            if (!isset($headerMap['pan'])) {
                throw new Exception("PAN column not found in Excel.");
            }



            // Sync ALL rows to customer_list (no transaction needed, upsert is safe)
            $insertCustomerStmt = $pdo->prepare("
    INSERT INTO customer_list
    (name, pan, email, mobile, family_head, city, company,
     first_investment, aum, tags, rm)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name             = VALUES(name),
        email            = VALUES(email),
        mobile           = VALUES(mobile),
        family_head      = VALUES(family_head),
        city             = VALUES(city),
        company          = VALUES(company),
        first_investment = VALUES(first_investment),
        aum              = VALUES(aum),
        tags             = VALUES(tags),
        rm               = VALUES(rm)
");

            $customerSyncCount = 0;
            foreach ($allRows as $index => $row) {
                if ($index === 0) continue;

                $pan = getCol($row, $headerMap, 'pan')
                    ?? getCol($row, $headerMap, 'pan no')
                    ?? getCol($row, $headerMap, 'pan number');
                if (!$pan) continue;

                $rowTag = getCol($row, $headerMap, 'tags') ?? '';
                $rowTag = trim($rowTag);
                if ($rowTag === '' || strtolower($rowTag) === 'nil' || strtolower($rowTag) === 'null') continue;
                $cleanTagString = str_replace("\xC2\xA0", ' ', (string)$rowTag);
                $cleanTagString = preg_replace('/\s+/', ' ', $cleanTagString);

                $rowTagsArray = array_map(function ($v) {
                    $v = str_replace("\xC2\xA0", ' ', $v);
                    $v = trim($v);
                    return strtoupper($v);
                }, explode(',', $cleanTagString));
                if (!in_array($targetTag, $rowTagsArray)) {
                    continue;
                }

                $aumRaw = getCol($row, $headerMap, 'aum');
                $aum    = is_numeric($aumRaw) ? (float)$aumRaw : 0;

                $dateRaw = getCol($row, $headerMap, 'first investment date');
                $date    = ($dateRaw && strtotime($dateRaw)) ? date('Y-m-d', strtotime($dateRaw)) : null;

                try {
                    $insertCustomerStmt->execute([
                        getCol($row, $headerMap, 'name'),
                        $pan,
                        getCol($row, $headerMap, 'email'),
                        getCol($row, $headerMap, 'mobile'),
                        getCol($row, $headerMap, 'family head'),
                        getCol($row, $headerMap, 'city'),
                        getCol($row, $headerMap, 'model name'),
                        $date,
                        $aum,
                        $rowTag,  // use the already-fetched and validated tag value
                        getCol($row, $headerMap, 'relationship manager'),
                    ]);
                    $customerSyncCount++;
                } catch (Exception $e) {
                    $summary['errors'][] = "Customer sync row $index (PAN: $pan): " . $e->getMessage();
                }
            }



            // Row 0 is the header row

            // Verify required columns exist
            $requiredHeaders = [
                'name'                 => 'NAME',
                'tags'                 => 'TAGS',
                'aum'                  => 'AUM',
                'relationship manager' => 'RELATIONSHIP MANAGER',
            ];

            $missingCols = [];
            foreach ($requiredHeaders as $normKey => $label) {
                if (!isset($headerMap[$normKey])) {
                    $missingCols[] = $label;
                }
            }
            if (!empty($missingCols)) {
                throw new Exception(
                    "Missing required columns in uploaded file: " . implode(', ', $missingCols) .
                        ". Please check that the file contains these headers."
                );
            }

            // Prepare DB statements
            $checkPrevStmt = $pdo->prepare("
                SELECT id, email, assigned_to, review_cycle, aum, priority 
                FROM clients 
                WHERE name = :name 
                ORDER BY created_at DESC LIMIT 1
            ");

            $checkDuplicateStmt = $pdo->prepare("
                SELECT id FROM clients 
                WHERE name          = :name 
                  AND month_year    = :month_year 
                  AND allocation_id = :allocation_id 
                LIMIT 1
            ");

            $insertStmt = $pdo->prepare("
                INSERT INTO clients 
                    (name, email, assigned_to, review_assigned_to, total_amount, aum, priority, 
                     review_cycle, report_state, created_at, created_by, month_year, 
                     original_client_id, allocation_id, is_latest) 
                VALUES 
                    (:name, :email, :assign, :reviewer, :aum, :aum_val, :prio, 
                     :cycle, 'pending', NOW(), :creator, :month_year, 
                     :original_id, :allocation_id, 1)
            ");

            $updatePreviousLatestStmt = $pdo->prepare("
                UPDATE clients 
                SET is_latest  = 0,
                    updated_at = NOW()
                WHERE name      = :name 
                  AND is_latest = 1
            ");

            // Fetch all users once (for RM matching)
            $allUsers = [];
            $uStmt    = $pdo->query("SELECT id, username, name FROM users");
            while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
                $usernameKey = strtolower(trim($uRow['username']));
                $fullnameKey = strtolower(trim($uRow['name']));
                $allUsers[$usernameKey] = $uRow['id'];
                if (!empty($fullnameKey)) {
                    $allUsers[$fullnameKey] = $uRow['id'];
                }
            }

            // Process data rows (skip row index 0 = header)
            foreach ($allRows as $rowIndex => $row) {
                if ($rowIndex === 0) continue;

                // Extract fields by header name (column order does not matter)
                $rawName     = getCol($row, $headerMap, 'name');
                $rawEmail    = getCol($row, $headerMap, 'email');
                $rawMobile   = getCol($row, $headerMap, 'mobile');
                $rawTag      = getCol($row, $headerMap, 'tags');
                $rawAum      = getCol($row, $headerMap, 'aum', 0);
                $rawRM       = getCol($row, $headerMap, 'relationship manager'); // handles double-space via normaliser
                $rawReviewer = getCol($row, $headerMap, 'service r m');          // "SERVICE  R M" normalised
                // Priority: new file = "CLIENT RATING", old file = "PRIORITY"
                $rawPriority = getCol($row, $headerMap, 'priority')
                    ?? getCol($row, $headerMap, 'client rating');

                if (empty($rawName)) continue;

                // Filter by Tag/Quarter
                // Matches if the TAGS cell contains the target tag anywhere in the string.
                // Examples when target = "RF":
                //   "RF"              → match ✓
                //   "RF, NRI"         → match ✓  (treated as RF)
                //   "OldM, RF"        → match ✓  (treated as RF)
                //   "NewM, RF, Attention" → match ✓  (treated as RF)
                //   "RJ"              → no match ✗
                if (empty($rawTag)) {
                    $summary['skipped']++;
                    continue;
                }

                // Clean tag string first
                $cleanTagString = str_replace("\xC2\xA0", ' ', (string)$rawTag);
                $cleanTagString = preg_replace('/\s+/', ' ', $cleanTagString);

                $rowTagsArray = preg_split('/[,;|\/]+/', $cleanTagString);
                $rowTagsArray = array_map(function ($v) {
                    $v = str_replace("\xC2\xA0", ' ', $v);
                    $v = trim($v);
                    return strtoupper($v);
                }, $rowTagsArray);

                $rowTagsArray = array_filter($rowTagsArray);
                if (!in_array($targetTag, $rowTagsArray)) {
                    $summary['skipped']++;
                    continue;
                }

                $summary['processed']++;

                // RM Lookup
                $assignedToId = null;
                $reviewerId   = null;
                $rmKey        = strtolower((string)$rawRM);
                $revKey       = strtolower((string)$rawReviewer);

                if (!empty($rmKey) && isset($allUsers[$rmKey])) {
                    $assignedToId = $allUsers[$rmKey];
                    $summary['assigned']++;
                } else {
                    $summary['unassigned']++;
                }

                if (!empty($revKey) && isset($allUsers[$revKey])) {
                    $reviewerId = $allUsers[$revKey];
                }

                // AUM Cleaning & Crore Conversion
                $cleanAumVal = preg_replace('/[^-0-9.]/', '', (string)$rawAum);
                $numericAum  = (float)$cleanAumVal;
                $aumInCrores = $numericAum > 0 ? round($numericAum / 10000000, 4) : 0;

                // Duplicate check (same client + month + allocation)
                $checkDuplicateStmt->execute([
                    ':name'          => $rawName,
                    ':month_year'    => $monthYear,
                    ':allocation_id' => $allocationId
                ]);
                if ($checkDuplicateStmt->fetchColumn()) {
                    $summary['duplicates']++;
                    continue;
                }

                // Previous record lookup (for continuity)
                $checkPrevStmt->execute([':name' => $rawName]);
                $previousClient   = $checkPrevStmt->fetch(PDO::FETCH_ASSOC);
                $clientEmail      = null;
                $originalClientId = null;

                if ($previousClient) {
                    $clientEmail      = $previousClient['email'] ?: $rawEmail;
                    $originalClientId = $previousClient['id'] ?? null;
                    $updatePreviousLatestStmt->execute([':name' => $rawName]);
                } else {
                    $clientEmail = $rawEmail;
                }

                // Priority carry-forward
                $finalPriority = $rawPriority;
                if ($previousClient && !empty($previousClient['priority'])) {
                    $finalPriority = $previousClient['priority'];
                }
                if (empty($finalPriority)) {
                    $finalPriority = 'Normal';
                }

                // Insert new record
                // review_cycle = $cleanReviewCycle ("RF") — never the raw composite tag
                try {
                    $insertStmt->execute([
                        ':name'          => $rawName,
                        ':email'         => $clientEmail,
                        ':assign'        => $assignedToId,
                        ':reviewer'      => $reviewerId,
                        ':aum'           => $aumInCrores,
                        ':aum_val'       => $aumInCrores,
                        ':prio'          => $finalPriority,
                        ':cycle'         => $cleanReviewCycle,  // ← FIX: always "RF", not "RF, NRI"
                        ':creator'       => $currentUserId,
                        ':month_year'    => $monthYear,
                        ':original_id'   => $originalClientId,
                        ':allocation_id' => $allocationId
                    ]);
                    $summary['inserted']++;
                } catch (Exception $e) {
                    $summary['errors'][] = "Failed to insert client '{$rawName}': " . $e->getMessage();
                    $summary['skipped']++;
                }
            }

            // Update allocation log with final counts
            $updateAllocStmt = $pdo->prepare("
                UPDATE allocation_log 
                SET clients_count    = :clients_count,
                    assigned_count   = :assigned_count,
                    unassigned_count = :unassigned_count,
                    inserted_count   = :inserted_count,
                    updated_count    = :updated_count
                WHERE id = :id
            ");
            $updateAllocStmt->execute([
                ':clients_count'    => $summary['processed'],
                ':assigned_count'   => $summary['assigned'],
                ':unassigned_count' => $summary['unassigned'],
                ':inserted_count'   => $summary['inserted'],
                ':updated_count'    => $summary['updated'],
                ':id'               => $allocationId
            ]);
        } catch (Exception $e) {
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
    <div class="page-scroll">
        <div class="container">
            <div class="page-header">
                <h2>Bulk Client Allocation</h2>
                <?php if (isRelationshipManager($userDesignation)): ?>
                <?php endif; ?>
            </div>

            <p style="color:#666; margin-bottom: 25px;">
                Upload a Customer List Excel file for <strong><?php echo date('M Y'); ?></strong> to assign tasks.<br>
                <small style="color:#aaa;">Columns are matched by <strong>header name</strong> — column order does not matter.</small>
            </p>

            <div class="info-note">
                <i class="fas fa-info-circle"></i> <strong>Important:</strong> This import always creates new records.
                Existing clients get new entries while preserving old data.
                Duplicate records (same client in same allocation) are skipped.<br><br>
                <strong>Required columns in your file:</strong>
                <code>NAME</code>, <code>TAGS</code>, <code>AUM</code>, <code>RELATIONSHIP MANAGER</code><br>
                <strong>Optional columns (used when present):</strong>
                <code>EMAIL</code>, <code>MOBILE</code>, <code>PRIORITY</code> / <code>CLIENT RATING</code>, <code>SERVICE R M</code><br><br>
                <i class="fas fa-check-circle" style="color:#28a745;"></i>
                Composite tags like <code>RF, NRI</code> or <code>OldM, RF</code> are automatically treated as <code>RF</code> — all matching clients will be imported and shown correctly.
            </div>

            <form method="post" enctype="multipart/form-data">

                <div class="form-group">
                    <label>1. Enter Quarter/Tag to Import (Required)</label>
                    <input type="text" name="target_tag" placeholder="e.g. RJ, RM, or RF" required
                        value="<?php echo htmlspecialchars($_POST['target_tag'] ?? ''); ?>">
                    <small style="color:#888;">
                        Clients whose TAGS column <em>contains</em> this value will be imported.
                        e.g. <strong>RF</strong> will match <strong>RF</strong>, <strong>RF, NRI</strong>, <strong>OldM, RF</strong>, <strong>NewM, RF, Attention</strong>, etc.
                    </small>
                </div>

                <div class="form-group">
                    <label>2. Select Excel File (.xlsx / .xls)</label>
                    <input type="file" name="allocation_file" accept=".xlsx, .xls" required>
                </div>

                <button type="submit">Import &amp; Allocate</button>
            </form>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['allocation_file'])): ?>
                <div class="summary">
                    <h4>Import Result for Tag: "<?php echo htmlspecialchars($targetTag); ?>"</h4>
                    <div>
                        <strong>Processed:</strong> <?php echo (int)$summary['processed']; ?>
                        (Skipped other tags: <?php echo (int)$summary['skipped']; ?>,
                        Duplicates: <?php echo (int)$summary['duplicates']; ?>)
                    </div>
                    <div style="margin-top:5px;">
                        <strong>Assigned:</strong> <?php echo (int)$summary['assigned']; ?> |
                        <strong>Unassigned:</strong> <?php echo (int)$summary['unassigned']; ?>
                    </div>
                    <div style="margin-top:5px;">
                        <strong>New Clients Created:</strong> <?php echo (int)$summary['inserted']; ?>
                    </div>

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
    </div>

    <script>
        let allClients = [];
        let selectedClients = new Set();
        let searchTimeout = null;
        let totalClientsCount = 0;
        let allClientIdsFetched = false;

        function loadClients(searchTerm = '') {
            fetch('bulk_import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
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
                        document.getElementById('selectAllAll').style.display = (totalClientsCount > 50) ? 'inline' : 'none';
                        allClientIdsFetched = false;
                        renderClientList(data.clients);
                    } else {
                        showError(data.error || 'Failed to load clients');
                    }
                })
                .catch(error => showError('Network error: ' + error.message));
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
                            <div style="font-size:12px;color:#666;font-weight:600;margin-bottom:3px;">
                                ID: ${client.id}
                            </div>
                            ${client.updated_at
                                ? `<div style="font-size:11px;color:#888;">${formatDate(client.updated_at)}</div>`
                                : '<div style="font-size:11px;color:#888;">Never</div>'}
                        </div>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }

        function showSuccess(message) {
            const el = document.getElementById('successMessage');
            el.textContent = message;
            el.style.display = 'block';
            el.scrollIntoView({
                behavior: 'smooth'
            });
            setTimeout(() => {
                el.style.display = 'none';
            }, 5000);
        }

        function showError(message) {
            const el = document.getElementById('errorMessage');
            el.textContent = message;
            el.style.display = 'block';
            el.scrollIntoView({
                behavior: 'smooth'
            });
            setTimeout(() => {
                el.style.display = 'none';
            }, 5000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateString) {
            if (!dateString) return 'Never';
            const date = new Date(dateString);
            const day = date.getDate().toString().padStart(2, '0');
            const month = date.toLocaleString('en-GB', {
                month: 'short'
            });
            const year = date.getFullYear();
            const hours = date.getHours().toString().padStart(2, '0');
            const mins = date.getMinutes().toString().padStart(2, '0');
            return `${day} ${month} ${year}, ${hours}:${mins}`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('clientSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => loadClients(e.target.value), 300);
                });
            }
        });
    </script>
</body>

</html>
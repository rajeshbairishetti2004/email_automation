<?php
// signature.php
// Advanced Signature / Closing Note module with user dropdown and rationale-style UI
// Includes comprehensive user management and automatic signature saving

require_once 'db_config.php';
$pdo = getPdo();

/* =========================================================
   1. HANDLE AJAX REQUESTS (Multiple operations)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    $ajax_type = $_POST['ajax'];

    try {
        switch ($ajax_type) {

            // Auto-save signature block when dropdown changes
            case 'save_signature':
                $clientId      = (int)($_POST['client_id'] ?? 0);
                $userId        = (int)($_POST['user_id'] ?? 0);
                $signatureText = $_POST['signature_text'] ?? '';

                if ($clientId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
                    exit;
                }

                $pdo->prepare("UPDATE clients SET signature_block = :value WHERE id = :id")
                    ->execute([':value' => $signatureText, ':id' => $clientId]);

                if ($userId > 0) {
                    $pdo->prepare("UPDATE clients SET assigned_to = :user_id WHERE id = :id")
                        ->execute([':user_id' => $userId, ':id' => $clientId]);
                }

                echo json_encode(['success' => true, 'message' => 'Signature saved and user assigned']);
                exit;

            // Update user details
            case 'user_update':
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
                    exit;
                }

                $fields  = ['name', 'username', 'designation', 'mobile', 'email', 'status'];
                $updates = [];
                $params  = [':id' => $userId];

                foreach ($fields as $f) {
                    if (isset($_POST[$f])) {
                        $updates[]   = "$f = :$f";
                        $params[":$f"] = $_POST[$f];
                    }
                }

                if ($updates) {
                    $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id")
                        ->execute($params);
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'No fields to update']);
                }
                exit;

            // Add new user
            case 'user_add':
                $required = ['name', 'username', 'email', 'designation', 'mobile'];
                foreach ($required as $field) {
                    if (empty($_POST[$field])) {
                        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                        exit;
                    }
                }

                $defaultPassword = password_hash('Welcome@123', PASSWORD_DEFAULT);

                $pdo->prepare("
                    INSERT INTO users (name, username, email, designation, mobile, password_hash, status, created_at)
                    VALUES (:name, :username, :email, :designation, :mobile, :password, :status, NOW())
                ")->execute([
                    ':name'        => $_POST['name'],
                    ':username'    => $_POST['username'],
                    ':email'       => $_POST['email'],
                    ':designation' => $_POST['designation'],
                    ':mobile'      => $_POST['mobile'],
                    ':password'    => $defaultPassword,
                    ':status'      => $_POST['status'] ?? 'active'
                ]);

                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => 'User added successfully', 'id' => $newId]);
                exit;

            // Delete user
            case 'user_delete':
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
                    exit;
                }

                $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM clients WHERE assigned_to = :id");
                $checkStmt->execute([':id' => $userId]);
                $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($result['count'] > 0) {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete user assigned to clients. Reassign clients first.']);
                    exit;
                }

                $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                exit;

            // Fetch all users for management
            case 'get_all_users':
                $stmt  = $pdo->query("
                    SELECT id, username, name, email, mobile, designation, status,
                           DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as created_date
                    FROM users
                    ORDER BY
                        CASE
                            WHEN designation = 'Relationship Manager' THEN 1
                            WHEN designation = 'Associate Relationship Manager' THEN 2
                            ELSE 3
                        END, name
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'users' => $users]);
                exit;

            // Get client signature info
            case 'get_client_signature':
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
                    exit;
                }

                $stmt   = $pdo->prepare("SELECT signature_block, assigned_to FROM clients WHERE id = :id");
                $stmt->execute([':id' => $clientId]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success'         => true,
                    'signature_block' => $client['signature_block'] ?? '',
                    'assigned_to'     => $client['assigned_to'] ?? 0
                ]);
                exit;

            default:
                echo json_encode(['success' => false, 'error' => 'Invalid AJAX request']);
                exit;
        }
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

/* =========================================================
   2. NORMAL PAGE LOAD (RENDER SIGNATURE UI)
   ========================================================= */

$clientId        = (int)($clientId ?? 0);
$signatureStored = $signatureStored ?? '';

// Get client's current assigned user
$assignedUserId = 0;
if ($clientId > 0) {
    $stmt = $pdo->prepare("SELECT signature_block, assigned_to FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    $clientData      = $stmt->fetch(PDO::FETCH_ASSOC);
    $signatureStored = $clientData['signature_block'] ?? '';
    $assignedUserId  = $clientData['assigned_to'] ?? 0;
}

// Get all active RM / ARM users for dropdown
$userStmt = $pdo->query("
    SELECT id, name, username, designation, mobile, email, status
    FROM users
    WHERE designation IN ('Relationship Manager', 'Associate Relationship Manager')
      AND status = 'active'
    ORDER BY
        CASE
            WHEN designation = 'Relationship Manager' THEN 1
            WHEN designation = 'Associate Relationship Manager' THEN 2
            ELSE 3
        END, name
");
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// All users for management table
$allUsersStmt = $pdo->query("
    SELECT id, username, name, email, mobile, designation, status,
           DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as created_date
    FROM users
    ORDER BY
        CASE
            WHEN designation = 'Relationship Manager' THEN 1
            WHEN designation = 'Associate Relationship Manager' THEN 2
            ELSE 3
        END, name
");
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Default user (logged-in RM)
$defaultUser = [
    'name'        => $rmName        ?? '',
    'designation' => $rmDesignation ?? '',
    'mobile'      => $rmMobile      ?? '',
    'email'       => $rmEmail       ?? ''
];

/*
 * build_signature() — returns PLAIN TEXT with \n line breaks.
 * This is stored in the DB and shown in the textarea.
 * email_handler.php converts it to HTML at send time via nl2br().
 */
function build_signature($user) {
    return "Regards,\n\n"
        . $user['name'] . ",\n"
        . $user['designation'] . ",\n"
        . "Finance Doctor Private Limited.\n\n"
        . "Mobile - " . $user['mobile'] . "\n"
        . "Email - "  . $user['email']  . "\n"
        . "Url: www.financedoctor.in";
}

// Build signatures map for all users
$selectedUserId = $assignedUserId;
$signaturesMap  = [];

foreach ($users as $u) {
    $signaturesMap[$u['id']] = build_signature($u);

    // Try to match stored signature to a user if none is assigned
    if ($selectedUserId == 0 && !empty($signatureStored)) {
        if (trim(build_signature($u)) === trim($signatureStored)) {
            $selectedUserId = $u['id'];
        }
    }
}

// If assigned user is inactive/missing from the active list, fetch them anyway
if ($selectedUserId > 0) {
    $foundInList = false;
    foreach ($users as $u) {
        if ($u['id'] == $selectedUserId) { $foundInList = true; break; }
    }

    if (!$foundInList) {
        $stmt = $pdo->prepare("SELECT id, name, designation, mobile, email, status FROM users WHERE id = :id");
        $stmt->execute([':id' => $selectedUserId]);
        $assignedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($assignedUser) {
            $users[]                               = $assignedUser;
            $signaturesMap[$assignedUser['id']]    = build_signature($assignedUser);
        }
    }
}

// Determine what to show in the textarea
if (!empty($signatureStored)) {
    $signatureBlock = $signatureStored;
} elseif ($selectedUserId > 0 && isset($signaturesMap[$selectedUserId])) {
    $signatureBlock = $signaturesMap[$selectedUserId];
} else {
    $signatureBlock = build_signature($defaultUser);
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');
.sig-box {
    margin-top: 22px;
    padding: 18px 18px 14px 18px;
    border: 1.5px solid #b3e0fc;
    border-radius: 10px;
    background: linear-gradient(180deg, #fafdff 0%, #eaf6ff 100%);
    box-shadow: 0 2px 8px rgba(2,136,209,0.06);
    font-family: 'Roboto', Arial, sans-serif;
    color: #07394a;
    max-width: 650px;
}
.sig-controls {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.sig-select {
    min-width: 280px;
    padding: 9px 12px;
    border: 1.5px solid #b3e0fc;
    border-radius: 7px;
    background: #fff;
    color: #083744;
    font-size: 15px;
    font-family: 'Roboto', Arial, sans-serif;
    transition: border 0.15s;
}
.sig-select:focus { border-color: #0288d1; outline: none; }
.sig-fields {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.sig-field {
    display: flex;
    flex-direction: column;
    font-size: 13px;
    margin-bottom: 2px;
}
.sig-field label {
    font-weight: 500;
    color: #0288d1;
    margin-bottom: 2px;
    font-size: 12px;
}
.sig-field input, .sig-field select {
    padding: 7px 10px;
    border: 1px solid #b3e0fc;
    border-radius: 6px;
    font-size: 14px;
    font-family: 'Roboto', Arial, sans-serif;
    background: #fafdff;
    color: #07394a;
    transition: border 0.15s;
    min-width: 120px;
}
.sig-field input:focus, .sig-field select:focus { border-color: #0288d1; outline: none; }
.sig-field input[readonly], .sig-field select:disabled {
    background: #f0f4f8;
    color: #666;
    cursor: not-allowed;
}
.sig-textarea {
    width: 100%;
    padding: 13px;
    font-size: 15px;
    min-height: 160px;
    box-sizing: border-box;
    border: 1.5px solid #b3e0fc;
    border-radius: 7px;
    background: #fff;
    color: #052b36;
    resize: none;
    font-family: 'Roboto', Arial, sans-serif;
    margin-bottom: 4px;
    overflow: hidden;
}
.sig-flash { margin-top: 8px; min-height: 26px; font-size: 14px; }
.sig-btn {
    padding: 8px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    transition: background-color 0.12s ease, transform 0.06s ease;
    margin-left: 0;
}
.sig-btn.save  { background: #0288D1; color: #fff; }
.sig-btn.save:hover  { background: #2eb85c !important; transform: translateY(-1px); }
.sig-btn.edit  { background: #039be5; color: #fff; }
.sig-btn.edit:hover  { background: #0288d1; transform: translateY(-1px); }
.sig-btn.add {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; padding: 0;
    border-radius: 50%;
    background: #eaf7ff; border: 1px solid #cfeefc; color: #0288d1;
}
.sig-btn.add:hover { background: #b3e0fc; }
.sig-btn.del  { background: #0277bd; color: #fff; }
.sig-btn.del:hover   { background: #dc3545 !important; transform: translateY(-1px); }
.sig-btn[disabled]   { opacity: 0.65; cursor: not-allowed; transform: none !important; }

/* User Management Modal */
.user-modal {
    display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 1000;
    justify-content: center; align-items: center;
}
.user-modal-content {
    background: white; padding: 25px; border-radius: 10px;
    max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.user-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e9ecef;
}
.user-modal-header h3 { margin: 0; color: #07394a; font-size: 20px; }
.user-modal-close { background: none; border: none; font-size: 24px; color: #6c757d; cursor: pointer; line-height: 1; }
.user-modal-close:hover { color: #dc3545; }
.user-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.user-table th, .user-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
.user-table th { background: #eaf7ff; color: #0288d1; font-weight: 600; position: sticky; top: 0; }
.user-table tr:hover { background: #f8f9fa; }
.user-status-active   { color: #28a745; font-weight: 500; }
.user-status-inactive { color: #dc3545; font-weight: 500; }
.user-actions { display: flex; gap: 5px; }
.user-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
.form-group { display: flex; flex-direction: column; }
.form-group label { font-weight: 500; color: #495057; margin-bottom: 5px; font-size: 14px; }
.form-group input, .form-group select { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
.form-group input:focus, .form-group select:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25); }
.flash-success { background: #d4edda; color: #155724; padding: 8px 12px; border-radius: 4px; border: 1px solid #c3e6cb; }
.flash-error   { background: #f8d7da; color: #721c24; padding: 8px 12px; border-radius: 4px; border: 1px solid #f5c6cb; }
@media (max-width: 700px) {
    .sig-box { max-width: 100%; }
    .sig-controls, .sig-fields { flex-direction: column; align-items: stretch; }
    .sig-select, .sig-field input { width: 100%; min-width: 0; }
    .user-table { display: block; overflow-x: auto; }
    .user-form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="sig-box" id="signature_module">
    <label style="font-weight:700; display:block; margin-bottom:10px; font-size:17px;">Signature / Closing Note</label>
    <div class="sig-controls">
        <select id="signature_user_selector" class="sig-select">
            <option value="0">-- Select team member signature --</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"
                    <?= ($selectedUserId == $u['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['designation']) ?>)
                    <?= ($u['status'] == 'inactive') ? ' [Inactive]' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button id="signature_add_btn" class="sig-btn add" type="button" title="Add new user" aria-label="Add new user">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>

    <div class="sig-fields" id="sig_fields" style="display:none;">
        <div class="sig-field">
            <label for="sig_name">Name</label>
            <input type="text" id="sig_name" autocomplete="off" readonly>
        </div>
        <div class="sig-field">
            <label for="sig_designation">Designation</label>
            <input type="text" id="sig_designation" autocomplete="off" readonly>
        </div>
        <div class="sig-field">
            <label for="sig_mobile">Mobile</label>
            <input type="text" id="sig_mobile" autocomplete="off" readonly>
        </div>
        <div class="sig-field">
            <label for="sig_email">Email</label>
            <input type="text" id="sig_email" autocomplete="off" readonly>
        </div>
        <div class="sig-field">
            <label for="sig_status">Status</label>
            <select id="sig_status" autocomplete="off" disabled>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <button id="sig_edit_btn"   class="sig-btn edit" type="button" style="margin-top:8px;align-self:flex-start;">Edit Details</button>
        <button id="sig_save_btn"   class="sig-btn save" type="button" style="margin-top:8px;align-self:flex-start;display:none;">Save Details</button>
        <button id="sig_cancel_btn" class="sig-btn del"  type="button" style="margin-top:8px;align-self:flex-start;display:none;">Cancel</button>
    </div>

    <!--
        IMPORTANT: name="custom_signature" — this is what email_handler.php
        reads as $_POST['custom_signature'] to get the live textarea value
        at send time, bypassing whatever is stored in the DB.
    -->
    <textarea
        id="signature_block"
        name="custom_signature"
        class="sig-textarea"
        data-client-id="<?= (int)$clientId ?>"
        placeholder="Enter signature here..."
    ><?= htmlspecialchars(trim($signatureBlock)) ?></textarea>

    <div id="signature_flash_container" class="sig-flash"></div>
</div>

<!-- User Management Modal -->
<div id="userManagementModal" class="user-modal">
    <div class="user-modal-content">
        <div class="user-modal-header">
            <h3>User Management</h3>
            <button class="user-modal-close" id="userModalClose">&times;</button>
        </div>

        <div id="addUserForm" style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
            <h4 style="margin-top: 0; color: #07394a;">Add New User</h4>
            <div class="user-form-grid">
                <div class="form-group"><label for="new_name">Full Name *</label><input type="text" id="new_name" placeholder="Enter full name"></div>
                <div class="form-group"><label for="new_username">Username *</label><input type="text" id="new_username" placeholder="Enter username"></div>
                <div class="form-group"><label for="new_email">Email *</label><input type="email" id="new_email" placeholder="Enter email"></div>
                <div class="form-group"><label for="new_mobile">Mobile *</label><input type="text" id="new_mobile" placeholder="Enter mobile number"></div>
                <div class="form-group">
                    <label for="new_designation">Designation *</label>
                    <select id="new_designation">
                        <option value="">Select Designation</option>
                        <option value="Relationship Manager">Relationship Manager</option>
                        <option value="Associate Relationship Manager">Associate Relationship Manager</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="new_status">Status *</label>
                    <select id="new_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <button id="addUserBtn" class="sig-btn save" style="margin-top: 10px;">Add User</button>
            <div id="addUserMessage" style="margin-top: 10px;"></div>
        </div>

        <table class="user-table" id="usersTable">
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Username</th><th>Email</th>
                    <th>Mobile</th><th>Designation</th><th>Status</th><th>Created</th><th>Actions</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <?php foreach ($allUsers as $user): ?>
                <tr data-id="<?= $user['id'] ?>">
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['name']) ?></td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td><?= htmlspecialchars($user['mobile']) ?></td>
                    <td><?= htmlspecialchars($user['designation']) ?></td>
                    <td class="<?= $user['status'] === 'active' ? 'user-status-active' : 'user-status-inactive' ?>">
                        <?= ucfirst($user['status']) ?>
                    </td>
                    <td><?= $user['created_date'] ?></td>
                    <td class="user-actions">
                        <button class="sig-btn edit btn-sm edit-user" data-id="<?= $user['id'] ?>">Edit</button>
                        <button class="sig-btn del btn-sm delete-user" data-id="<?= $user['id'] ?>"
                                <?= $user['status'] === 'active' ? '' : 'style="display:none;"' ?>>Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const selector       = document.getElementById('signature_user_selector');
    const textarea       = document.getElementById('signature_block');
    const flash          = document.getElementById('signature_flash_container');
    const fieldsDiv      = document.getElementById('sig_fields');
    const inputName      = document.getElementById('sig_name');
    const inputDesg      = document.getElementById('sig_designation');
    const inputMobile    = document.getElementById('sig_mobile');
    const inputEmail     = document.getElementById('sig_email');
    const inputStatus    = document.getElementById('sig_status');
    const editDetailsBtn = document.getElementById('sig_edit_btn');
    const saveDetailsBtn = document.getElementById('sig_save_btn');
    const cancelBtn      = document.getElementById('sig_cancel_btn');
    const addBtn         = document.getElementById('signature_add_btn');
    const userModal      = document.getElementById('userManagementModal');
    const userModalClose = document.getElementById('userModalClose');
    const usersTableBody = document.getElementById('usersTableBody');
    const addUserBtn     = document.getElementById('addUserBtn');
    const addUserMessage = document.getElementById('addUserMessage');

    // Maps injected from PHP
    const userSignatures = <?php echo json_encode($signaturesMap); ?>;
    const userDetails    = <?php
        $details = [];
        foreach ($users as $u) {
            $details[$u['id']] = [
                'name'        => $u['name'],
                'designation' => $u['designation'],
                'mobile'      => $u['mobile'],
                'email'       => $u['email'],
                'status'      => $u['status']
            ];
        }
        echo json_encode($details);
    ?>;

    let originalDetails = {};
    let autoSaving      = false;
    const clientId      = <?= $clientId ?>;

    /* ── Helpers ── */
    function showFlash(type, msg) {
        flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">'
            + (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
        setTimeout(() => flash.innerHTML = '', 3000);
    }

    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    autoGrow(textarea);
    textarea.addEventListener('input', () => autoGrow(textarea));
    textarea.addEventListener('paste', () => setTimeout(() => autoGrow(textarea), 0));

    /* Build plain-text signature (mirrors PHP build_signature) */
    function buildSig(name, designation, mobile, email) {
        return "Regards,\n\n"
            + name        + ",\n"
            + designation + ",\n"
            + "Finance Doctor Private Limited.\n\n"
            + "Mobile - " + mobile + "\n"
            + "Email - "  + email  + "\n"
            + "Url: www.financedoctor.in";
    }

    /* Save plain-text signature to DB */
    function saveToDb(userId, signatureText) {
        if (autoSaving || clientId <= 0) return;
        autoSaving = true;

        fetch('signature.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                ajax:           'save_signature',
                client_id:      clientId,
                user_id:        userId,
                signature_text: signatureText
            })
        })
        .then(r => r.json())
        .then(res => {
            autoSaving = false;
            if (res.success) showFlash('success', 'Signature saved');
            else showFlash('error', res.error || 'Save failed');
        })
        .catch(() => { autoSaving = false; showFlash('error', 'Network error while saving'); });
    }

    /* ── Dropdown change: load selected user's signature ── */
    selector.addEventListener('change', function () {
        const uid = this.value;

        if (uid === '0' || !userSignatures[uid]) {
            fieldsDiv.style.display = 'none';
            [inputName, inputDesg, inputMobile, inputEmail].forEach(el => el.readOnly = true);
            inputStatus.disabled = true;
            [editDetailsBtn, saveDetailsBtn, cancelBtn].forEach(el => el.style.display = 'none');
            return;
        }

        inputName.value   = userDetails[uid].name        || '';
        inputDesg.value   = userDetails[uid].designation || '';
        inputMobile.value = userDetails[uid].mobile      || '';
        inputEmail.value  = userDetails[uid].email       || '';
        inputStatus.value = userDetails[uid].status      || 'active';

        fieldsDiv.style.display = '';
        [inputName, inputDesg, inputMobile, inputEmail].forEach(el => el.readOnly = true);
        inputStatus.disabled   = true;
        editDetailsBtn.style.display = '';
        saveDetailsBtn.style.display = 'none';
        cancelBtn.style.display      = 'none';

        textarea.value = userSignatures[uid];
        autoGrow(textarea);
        showFlash('success', 'Signature loaded from ' + userDetails[uid].name);
        saveToDb(uid, userSignatures[uid]);
    });

    /* ── Blur auto-save ── */
    textarea.addEventListener('blur', function () {
        const text   = textarea.value.trim();
        const userId = selector.value;
        if (text && clientId > 0) saveToDb(userId, text);
    });

    /* ── Edit / Save / Cancel details ── */
    editDetailsBtn.addEventListener('click', function () {
        [inputName, inputDesg, inputMobile, inputEmail].forEach(el => el.readOnly = false);
        inputStatus.disabled         = false;
        saveDetailsBtn.style.display = '';
        cancelBtn.style.display      = '';
        editDetailsBtn.style.display = 'none';

        const uid = selector.value;
        originalDetails = {
            name: inputName.value, designation: inputDesg.value,
            mobile: inputMobile.value, email: inputEmail.value, status: inputStatus.value
        };
    });

    cancelBtn.addEventListener('click', function () {
        inputName.value   = originalDetails.name;
        inputDesg.value   = originalDetails.designation;
        inputMobile.value = originalDetails.mobile;
        inputEmail.value  = originalDetails.email;
        inputStatus.value = originalDetails.status;
        [inputName, inputDesg, inputMobile, inputEmail].forEach(el => el.readOnly = true);
        inputStatus.disabled         = true;
        saveDetailsBtn.style.display = 'none';
        cancelBtn.style.display      = 'none';
        editDetailsBtn.style.display = '';
    });

    saveDetailsBtn.addEventListener('click', function () {
        const uid = selector.value;
        if (uid === '0' || !userDetails[uid]) return;

        const newDetails = {
            name:        inputName.value.trim(),
            designation: inputDesg.value.trim(),
            mobile:      inputMobile.value.trim(),
            email:       inputEmail.value.trim(),
            status:      inputStatus.value
        };

        for (let key in newDetails) {
            if (!newDetails[key]) { showFlash('error', key + ' cannot be empty'); return; }
        }

        const data = new URLSearchParams({ ajax: 'user_update', user_id: uid });
        for (let key in newDetails) data.append(key, newDetails[key]);

        saveDetailsBtn.disabled = true;
        fetch('signature.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data })
        .then(r => r.json())
        .then(res => {
            saveDetailsBtn.disabled = false;
            if (res.success) {
                userDetails[uid]    = { ...newDetails };
                const newSig        = buildSig(newDetails.name, newDetails.designation, newDetails.mobile, newDetails.email);
                userSignatures[uid] = newSig;
                textarea.value      = newSig;
                autoGrow(textarea);
                showFlash('success', 'User details updated.');
                saveToDb(uid, newSig);

                [inputName, inputDesg, inputMobile, inputEmail].forEach(el => el.readOnly = true);
                inputStatus.disabled         = true;
                saveDetailsBtn.style.display = 'none';
                cancelBtn.style.display      = 'none';
                editDetailsBtn.style.display = '';

                const opt = selector.querySelector(`option[value="${uid}"]`);
                if (opt) {
                    opt.textContent = newDetails.name + ' (' + newDetails.designation + ')'
                        + (newDetails.status === 'inactive' ? ' [Inactive]' : '');
                }
                loadAllUsers();
            } else {
                showFlash('error', res.error || 'Update failed');
            }
        })
        .catch(() => { saveDetailsBtn.disabled = false; showFlash('error', 'Network error while updating user'); });
    });

    /* ── Modal: open / close ── */
    addBtn.addEventListener('click', () => { userModal.style.display = 'flex'; document.getElementById('new_name').focus(); });
    userModalClose.addEventListener('click', () => userModal.style.display = 'none');
    window.addEventListener('click', e => { if (e.target === userModal) userModal.style.display = 'none'; });

    /* ── Load all users table ── */
    function loadAllUsers() {
        fetch('signature.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ ajax: 'get_all_users' }) })
        .then(r => r.json())
        .then(res => { if (res.success) updateUsersTable(res.users); })
        .catch(console.error);
    }

    function updateUsersTable(users) {
        usersTableBody.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.dataset.id = user.id;
            row.innerHTML = `
                <td>${user.id}</td>
                <td>${esc(user.name)}</td><td>${esc(user.username)}</td>
                <td>${esc(user.email)}</td><td>${esc(user.mobile)}</td>
                <td>${esc(user.designation)}</td>
                <td class="${user.status === 'active' ? 'user-status-active' : 'user-status-inactive'}">
                    ${user.status.charAt(0).toUpperCase() + user.status.slice(1)}
                </td>
                <td>${user.created_date}</td>
                <td class="user-actions">
                    <button class="sig-btn edit btn-sm edit-user" data-id="${user.id}">Edit</button>
                    <button class="sig-btn del btn-sm delete-user" data-id="${user.id}"
                            ${user.status === 'active' ? '' : 'style="display:none;"'}>Delete</button>
                </td>`;
            usersTableBody.appendChild(row);
        });
        attachTableListeners();
    }

    /* ── Add new user ── */
    addUserBtn.addEventListener('click', function () {
        const newUser = {
            name:        document.getElementById('new_name').value.trim(),
            username:    document.getElementById('new_username').value.trim(),
            email:       document.getElementById('new_email').value.trim(),
            mobile:      document.getElementById('new_mobile').value.trim(),
            designation: document.getElementById('new_designation').value,
            status:      document.getElementById('new_status').value
        };

        for (let key in newUser) {
            if (!newUser[key]) { showAddMsg('error', 'Please fill all required fields'); return; }
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newUser.email)) { showAddMsg('error', 'Invalid email'); return; }
        if (!/^[0-9]{10}$/.test(newUser.mobile)) { showAddMsg('error', 'Mobile must be 10 digits'); return; }

        addUserBtn.disabled = true;
        const data = new URLSearchParams({ ajax: 'user_add' });
        for (let key in newUser) data.append(key, newUser[key]);

        fetch('signature.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data })
        .then(r => r.json())
        .then(res => {
            addUserBtn.disabled = false;
            if (res.success) {
                showAddMsg('success', 'User added successfully');
                ['new_name','new_username','new_email','new_mobile'].forEach(id => document.getElementById(id).value = '');
                document.getElementById('new_designation').value = '';
                document.getElementById('new_status').value = 'active';
                loadAllUsers();

                if (newUser.status === 'active' &&
                    ['Relationship Manager','Associate Relationship Manager'].includes(newUser.designation)) {
                    const opt = document.createElement('option');
                    opt.value       = res.id;
                    opt.textContent = `${newUser.name} (${newUser.designation})`;
                    selector.appendChild(opt);
                    userDetails[res.id]    = { ...newUser };
                    userSignatures[res.id] = buildSig(newUser.name, newUser.designation, newUser.mobile, newUser.email);
                }
            } else {
                showAddMsg('error', res.error || 'Failed to add user');
            }
        })
        .catch(() => { addUserBtn.disabled = false; showAddMsg('error', 'Network error'); });
    });

    function showAddMsg(type, msg) {
        addUserMessage.innerHTML = `<div class="${type === 'success' ? 'flash-success' : 'flash-error'}">${msg}</div>`;
        setTimeout(() => addUserMessage.innerHTML = '', 3000);
    }

    function attachTableListeners() {
        document.querySelectorAll('.edit-user').forEach(btn =>
            btn.addEventListener('click', function () { editUser(this.dataset.id); }));
        document.querySelectorAll('.delete-user').forEach(btn =>
            btn.addEventListener('click', function () { deleteUser(this.dataset.id); }));
    }

    function editUser(userId) {
        selector.value = userId;
        if (userDetails[userId]) {
            selector.dispatchEvent(new Event('change'));
            setTimeout(() => editDetailsBtn.click(), 100);
        } else {
            alert('User not in signature selector. Edit via database.');
        }
        userModal.style.display = 'none';
    }

    function deleteUser(userId) {
        if (!confirm('Delete this user? This cannot be undone.')) return;

        fetch('signature.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ ajax: 'user_delete', user_id: userId }) })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.querySelector(`tr[data-id="${userId}"]`)?.remove();
                selector.querySelector(`option[value="${userId}"]`)?.remove();
                delete userDetails[userId];
                delete userSignatures[userId];
                showAddMsg('success', 'User deleted successfully');
            } else {
                showAddMsg('error', res.error || 'Failed to delete user');
            }
        })
        .catch(() => showAddMsg('error', 'Network error'));
    }

    function esc(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    attachTableListeners();

    // On page load, trigger change to populate fields if a user is already selected
    setTimeout(() => {
        if (selector.value !== '0') selector.dispatchEvent(new Event('change'));
    }, 100);
})();
</script>
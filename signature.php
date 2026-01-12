<?php
// signature.php
// Advanced Signature / Closing Note module with user dropdown and rationale-style UI
// Includes comprehensive user management

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
            // Auto-save signature block
            case '1':
                $clientId = (int)($_POST['client_id'] ?? 0);
                $field = trim($_POST['field'] ?? '');
                $value = $_POST['value'] ?? '';
                
                if ($clientId <= 0 || $field !== 'signature_block') {
                    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                    exit;
                }
                
                $stmt = $pdo->prepare("UPDATE clients SET signature_block = :value WHERE id = :id");
                $stmt->execute([':value' => $value, ':id' => $clientId]);
                
                echo json_encode(['success' => true, 'message' => 'Signature saved']);
                exit;
                
            // Update user details
            case 'user_update':
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
                    exit;
                }
                
                $fields = ['name', 'username', 'designation', 'mobile', 'email', 'status'];
                $updates = [];
                $params = [':id' => $userId];
                
                foreach ($fields as $f) {
                    if (isset($_POST[$f])) {
                        $updates[] = "$f = :$f";
                        $params[":$f"] = $_POST[$f];
                    }
                }
                
                if ($updates) {
                    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
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
                
                // Default password (you might want to change this)
                $defaultPassword = password_hash('Welcome@123', PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users 
                    (name, username, email, designation, mobile, password_hash, status, created_at) 
                    VALUES 
                    (:name, :username, :email, :designation, :mobile, :password, :status, NOW())
                ");
                
                $stmt->execute([
                    ':name' => $_POST['name'],
                    ':username' => $_POST['username'],
                    ':email' => $_POST['email'],
                    ':designation' => $_POST['designation'],
                    ':mobile' => $_POST['mobile'],
                    ':password' => $defaultPassword,
                    ':status' => $_POST['status'] ?? 'active'
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
                
                // Check if this is the last admin/manager (optional safety check)
                $checkStmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM users 
                    WHERE designation IN ('Relationship Manager', 'Associate Relationship Manager') 
                    AND status = 'active'
                ");
                $checkStmt->execute();
                $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] <= 1) {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete the last active manager']);
                    exit;
                }
                
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute([':id' => $userId]);
                
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                exit;
                
            // Fetch all users for management
            case 'get_all_users':
                $stmt = $pdo->query("
                    SELECT id, username, name, email, mobile, designation, status, 
                           DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as created_date
                    FROM users 
                    ORDER BY 
                        CASE 
                            WHEN designation = 'Relationship Manager' THEN 1
                            WHEN designation = 'Associate Relationship Manager' THEN 2
                            ELSE 3
                        END,
                        name
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'users' => $users]);
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

// Expected variables from view_report.php scope
$clientId        = (int)($clientId ?? 0);
$signatureStored = $signatureStored ?? '';

// FIXED LINE 198: Using correct column name 'status' instead of 'active'
$userStmt = $pdo->query("
    SELECT id, name, username, designation, mobile, email, status
    FROM users
    WHERE designation IN ('Relationship Manager', 'Associate Relationship Manager')
    AND status = 'active'  -- CORRECTED: Using 'status' column
    ORDER BY 
        CASE 
            WHEN designation = 'Relationship Manager' THEN 1
            WHEN designation = 'Associate Relationship Manager' THEN 2
            ELSE 3
        END,
        name
");
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Also get all users for management view
$allUsersStmt = $pdo->query("
    SELECT id, username, name, email, mobile, designation, status, 
           DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as created_date
    FROM users 
    ORDER BY 
        CASE 
            WHEN designation = 'Relationship Manager' THEN 1
            WHEN designation = 'Associate Relationship Manager' THEN 2
            ELSE 3
        END,
        name
");
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine default user (logged-in user or first user)
$defaultUser = [
    'name'        => $rmName ?? '',
    'designation' => $rmDesignation ?? '',
    'mobile'      => $rmMobile ?? '',
    'email'       => $rmEmail ?? ''
];

// Build signature for a user
function build_signature($user) {
    return "Regards,\n\n" .
        "{$user['name']},\n" .
        "{$user['designation']},\n" .
        "Finance Doctor Private Limited.\n\n" .
        "Mobile - {$user['mobile']}\n" .
        "Email - {$user['email']}\n" .
        "Url: www.financedoctor.in";
}

// Use stored signature if available, otherwise default
$DEFAULT_SIGNATURE = build_signature($defaultUser);
$signatureBlock = isset($signatureBlock) 
    ? $signatureBlock 
    : (trim($signatureStored) !== '' ? $signatureStored : $DEFAULT_SIGNATURE);
?>

<style>
/* filepath: c:\xampp\htdocs\email_automation\signature.php */
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
    box-shadow: 0 1px 0 rgba(2,136,209,0.02);
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
/* Remove scroll bar and always show all content in textarea */
.sig-textarea {
    width: 100%;
    padding: 13px;
    font-size: 15px;
    min-height: 200px;
    box-sizing: border-box;
    border: 1.5px solid #b3e0fc;
    border-radius: 7px;
    background: #fff;
    color: #052b36;
    resize: none; /* Prevent manual resizing */
    font-family: 'Roboto', Arial, sans-serif;
    margin-bottom: 4px;
    overflow: hidden; /* Hide scroll bar */
}
.sig-flash { margin-top: 8px; min-height: 26px; font-size: 14px; }
.sig-btn {
    padding: 8px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    box-shadow: 0 1px 0 rgba(0,0,0,0.02);
    transition: background-color 0.12s ease, transform 0.06s ease;
    margin-left: 0;
}
.sig-btn.save {
    background: #0288D1;
    color: #fff;
}
.sig-btn.save:hover { background: #2eb85c !important; transform: translateY(-1px); }
.sig-btn.edit {
    background: #039be5;
    color: #fff;
}
.sig-btn.edit:hover { background: #0288d1; transform: translateY(-1px); }
.sig-btn.add {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 50%;
    background: #eaf7ff;
    border: 1px solid #cfeefc;
    color: #0288d1;
}
.sig-btn.add:hover { background: #b3e0fc; }
.sig-btn.del {
    background: #0277bd;
    color: #fff;
}
.sig-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }
.sig-btn.manage {
    background: #6c757d;
    color: #fff;
}
.sig-btn.manage:hover { background: #5a6268; transform: translateY(-1px); }
.sig-btn[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none !important;
}

/* User Management Modal Styles */
.user-modal {
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
.user-modal-content {
    background: white;
    padding: 25px;
    border-radius: 10px;
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.user-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}
.user-modal-header h3 {
    margin: 0;
    color: #07394a;
    font-size: 20px;
}
.user-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #6c757d;
    cursor: pointer;
    line-height: 1;
}
.user-modal-close:hover { color: #dc3545; }
.user-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.user-table th, .user-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
}
.user-table th {
    background: #eaf7ff;
    color: #0288d1;
    font-weight: 600;
    position: sticky;
    top: 0;
}
.user-table tr:hover { background: #f8f9fa; }
.user-status-active { color: #28a745; font-weight: 500; }
.user-status-inactive { color: #dc3545; font-weight: 500; }
.user-actions {
    display: flex;
    gap: 5px;
}
.user-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
}
.form-group label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 5px;
    font-size: 14px;
}
.form-group input, .form-group select {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
}
.form-group input:focus, .form-group select:focus {
    border-color: #86b7fe;
    outline: 0;
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25);
}
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
                <option value="<?= (int)$u['id'] ?>">
                    <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['designation']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button id="signature_add_btn" class="sig-btn add" type="button" title="Add new user" aria-label="Add new user">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
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
        <button id="sig_edit_btn" class="sig-btn edit" type="button" style="margin-top:8px;align-self:flex-start;">Edit Details</button>
        <button id="sig_save_btn" class="sig-btn save" type="button" style="margin-top:8px;align-self:flex-start;display:none;">Save Details</button>
        <button id="sig_cancel_btn" class="sig-btn del" type="button" style="margin-top:8px;align-self:flex-start;display:none;">Cancel</button>
    </div>
    <textarea
        id="signature_block"
        name="signature_block"
        class="sig-textarea"
        data-client-id="<?= (int)$clientId ?>"
        data-field="signature_block"
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
        
        <!-- Add New User Form -->
        <div id="addUserForm" style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
            <h4 style="margin-top: 0; color: #07394a;">Add New User</h4>
            <div class="user-form-grid">
                <div class="form-group">
                    <label for="new_name">Full Name *</label>
                    <input type="text" id="new_name" placeholder="Enter full name">
                </div>
                <div class="form-group">
                    <label for="new_username">Username *</label>
                    <input type="text" id="new_username" placeholder="Enter username">
                </div>
                <div class="form-group">
                    <label for="new_email">Email *</label>
                    <input type="email" id="new_email" placeholder="Enter email">
                </div>
                <div class="form-group">
                    <label for="new_mobile">Mobile *</label>
                    <input type="text" id="new_mobile" placeholder="Enter mobile number">
                </div>
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
        
        <!-- Users Table -->
        <table class="user-table" id="usersTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Designation</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
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
                                <?= $user['status'] === 'active' ? '' : 'style="display:none;"' ?>>
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const selector = document.getElementById('signature_user_selector');
    const textarea = document.getElementById('signature_block');
    const flash    = document.getElementById('signature_flash_container');
    const fieldsDiv = document.getElementById('sig_fields');
    const inputName = document.getElementById('sig_name');
    const inputDesg = document.getElementById('sig_designation');
    const inputMobile = document.getElementById('sig_mobile');
    const inputEmail = document.getElementById('sig_email');
    const inputStatus = document.getElementById('sig_status');
    const editDetailsBtn = document.getElementById('sig_edit_btn');
    const saveDetailsBtn = document.getElementById('sig_save_btn');
    const cancelDetailsBtn = document.getElementById('sig_cancel_btn');
    const addBtn = document.getElementById('signature_add_btn');
    const saveBtn = document.getElementById('signature_save_btn');
    const editBtn = document.getElementById('signature_edit_btn');
    const manageBtn = document.getElementById('signature_manage_btn');
    
    // User Management Modal elements
    const userModal = document.getElementById('userManagementModal');
    const userModalClose = document.getElementById('userModalClose');
    const usersTableBody = document.getElementById('usersTableBody');
    const addUserBtn = document.getElementById('addUserBtn');
    const addUserMessage = document.getElementById('addUserMessage');

    // User signatures and details from PHP
    const userSignatures = <?php
        $signatures = [];
        foreach ($users as $u) {
            $signatures[$u['id']] = build_signature($u);
        }
        echo json_encode($signatures);
    ?>;
    
    const userDetails = <?php
        $details = [];
        foreach ($users as $u) {
            $details[$u['id']] = [
                'name' => $u['name'],
                'designation' => $u['designation'],
                'mobile' => $u['mobile'],
                'email' => $u['email'],
                'status' => $u['status']
            ];
        }
        echo json_encode($details);
    ?>;

    let originalDetails = {};

    function showFlash(type, msg) {
        flash.innerHTML =
            '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' +
            (type === 'success' ? '✓ ' : '✗ ') + msg +
            '</div>';
        setTimeout(() => flash.innerHTML = '', 3000);
    }

    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    autoGrow(textarea);
    textarea.addEventListener('input', () => autoGrow(textarea));
    textarea.addEventListener('paste', () => setTimeout(() => autoGrow(textarea), 0));

    // Helper: build signature from fields
    function buildSignatureFromFields(name, designation, mobile, email) {
        return "Regards,\n\n" +
            name + ",\n" +
            designation + ",\n" +
            "Finance Doctor Private Limited.\n\n" +
            "Mobile - " + mobile + "\n" +
            "Email - " + email + "\n" +
            "Url: www.financedoctor.in";
    }

    // Load selected user's signature and details
    selector.addEventListener('change', function () {
        const uid = this.value;
        if (uid === '0' || !userSignatures[uid]) {
            fieldsDiv.style.display = 'none';
            inputName.readOnly = true;
            inputDesg.readOnly = true;
            inputMobile.readOnly = true;
            inputEmail.readOnly = true;
            inputStatus.disabled = true;
            editDetailsBtn.style.display = 'none';
            saveDetailsBtn.style.display = 'none';
            cancelDetailsBtn.style.display = 'none';
            return;
        }
        // Fill fields
        inputName.value = userDetails[uid].name;
        inputDesg.value = userDetails[uid].designation;
        inputMobile.value = userDetails[uid].mobile;
        inputEmail.value = userDetails[uid].email;
        inputStatus.value = userDetails[uid].status;
        fieldsDiv.style.display = '';
        // Enable edit button, disable save/cancel
        inputName.readOnly = true;
        inputDesg.readOnly = true;
        inputMobile.readOnly = true;
        inputEmail.readOnly = true;
        inputStatus.disabled = true;
        editDetailsBtn.style.display = '';
        saveDetailsBtn.style.display = 'none';
        cancelDetailsBtn.style.display = 'none';
        // Fill signature
        textarea.value = userSignatures[uid];
        autoGrow(textarea);
        showFlash('success', 'Signature loaded.');
        textarea.dispatchEvent(new Event('blur'));
    });

    // Edit details: make fields editable, show save/cancel
    editDetailsBtn.addEventListener('click', function() {
        inputName.readOnly = false;
        inputDesg.readOnly = false;
        inputMobile.readOnly = false;
        inputEmail.readOnly = false;
        inputStatus.disabled = false;
        saveDetailsBtn.style.display = '';
        cancelDetailsBtn.style.display = '';
        editDetailsBtn.style.display = 'none';
        // Save original values for cancel
        const uid = selector.value;
        originalDetails = {
            name: inputName.value,
            designation: inputDesg.value,
            mobile: inputMobile.value,
            email: inputEmail.value,
            status: inputStatus.value
        };
    });

    // Cancel details: revert fields, disable editing
    cancelDetailsBtn.addEventListener('click', function() {
        inputName.value = originalDetails.name;
        inputDesg.value = originalDetails.designation;
        inputMobile.value = originalDetails.mobile;
        inputEmail.value = originalDetails.email;
        inputStatus.value = originalDetails.status;
        inputName.readOnly = true;
        inputDesg.readOnly = true;
        inputMobile.readOnly = true;
        inputEmail.readOnly = true;
        inputStatus.disabled = true;
        saveDetailsBtn.style.display = 'none';
        cancelDetailsBtn.style.display = 'none';
        editDetailsBtn.style.display = '';
    });

    // Save details: update DB, update UI, disable editing
    saveDetailsBtn.addEventListener('click', function() {
        const uid = selector.value;
        if (uid === '0' || !userDetails[uid]) return;
        
        const newDetails = {
            name: inputName.value.trim(),
            designation: inputDesg.value.trim(),
            mobile: inputMobile.value.trim(),
            email: inputEmail.value.trim(),
            status: inputStatus.value
        };
        
        // Validate
        for (let key in newDetails) {
            if (!newDetails[key]) {
                showFlash('error', `${key} cannot be empty`);
                return;
            }
        }
        
        // Save to DB
        const data = new URLSearchParams();
        data.append('ajax', 'user_update');
        data.append('user_id', uid);
        for (let key in newDetails) {
            data.append(key, newDetails[key]);
        }
        
        saveDetailsBtn.disabled = true;
        fetch('signature.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data
        })
        .then(r => r.json())
        .then(res => {
            saveDetailsBtn.disabled = false;
            if (res.success) {
                userDetails[uid] = {...newDetails};
                userSignatures[uid] = buildSignatureFromFields(
                    newDetails.name, newDetails.designation, newDetails.mobile, newDetails.email
                );
                textarea.value = userSignatures[uid];
                autoGrow(textarea);
                showFlash('success', 'User details updated.');
                textarea.dispatchEvent(new Event('blur'));
                inputName.readOnly = true;
                inputDesg.readOnly = true;
                inputMobile.readOnly = true;
                inputEmail.readOnly = true;
                inputStatus.disabled = true;
                saveDetailsBtn.style.display = 'none';
                cancelDetailsBtn.style.display = 'none';
                editDetailsBtn.style.display = '';
                
                // Refresh user management table
                loadAllUsers();
            } else {
                showFlash('error', res.error || 'Update failed');
            }
        })
        .catch(() => {
            saveDetailsBtn.disabled = false;
            showFlash('error', 'Network error while updating user');
        });
    });

    // Auto-save on blur for signature textarea
    textarea.addEventListener('blur', function () {
        const clientId = textarea.dataset.clientId;
        const field    = textarea.dataset.field;
        const value    = textarea.value;

        fetch('signature.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                ajax: '1',
                client_id: clientId,
                field: field,
                value: value
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showFlash('success', 'Signature saved');
            } else {
                showFlash('error', res.error || 'Save failed');
            }
        })
        .catch(() => showFlash('error', 'Network error while saving'));
    });

    // Add new user button
    addBtn.addEventListener('click', function() {
        userModal.style.display = 'flex';
        document.getElementById('new_name').focus();
    });

    // Manage users button
    manageBtn.addEventListener('click', function() {
        userModal.style.display = 'flex';
        loadAllUsers();
    });

    // Close modal
    userModalClose.addEventListener('click', function() {
        userModal.style.display = 'none';
    });

    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === userModal) {
            userModal.style.display = 'none';
        }
    });

    // Load all users for management table
    function loadAllUsers() {
        const data = new URLSearchParams();
        data.append('ajax', 'get_all_users');
        
        fetch('signature.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                updateUsersTable(res.users);
            }
        })
        .catch(console.error);
    }

    // Update users table
    function updateUsersTable(users) {
        usersTableBody.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.dataset.id = user.id;
            row.innerHTML = `
                <td>${user.id}</td>
                <td>${escapeHtml(user.name)}</td>
                <td>${escapeHtml(user.username)}</td>
                <td>${escapeHtml(user.email)}</td>
                <td>${escapeHtml(user.mobile)}</td>
                <td>${escapeHtml(user.designation)}</td>
                <td class="${user.status === 'active' ? 'user-status-active' : 'user-status-inactive'}">
                    ${user.status.charAt(0).toUpperCase() + user.status.slice(1)}
                </td>
                <td>${user.created_date}</td>
                <td class="user-actions">
                    <button class="sig-btn edit btn-sm edit-user" data-id="${user.id}">Edit</button>
                    <button class="sig-btn del btn-sm delete-user" data-id="${user.id}" 
                            ${user.status === 'active' ? '' : 'style="display:none;"'}>
                        Delete
                    </button>
                </td>
            `;
            usersTableBody.appendChild(row);
        });
        
        // Attach event listeners to new buttons
        attachTableEventListeners();
    }

    // Add new user
    addUserBtn.addEventListener('click', function() {
        const newUser = {
            name: document.getElementById('new_name').value.trim(),
            username: document.getElementById('new_username').value.trim(),
            email: document.getElementById('new_email').value.trim(),
            mobile: document.getElementById('new_mobile').value.trim(),
            designation: document.getElementById('new_designation').value,
            status: document.getElementById('new_status').value
        };
        
        // Validate
        for (let key in newUser) {
            if (!newUser[key]) {
                showAddMessage('error', `Please fill all required fields`);
                return;
            }
        }
        
        // Email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(newUser.email)) {
            showAddMessage('error', 'Please enter a valid email address');
            return;
        }
        
        // Mobile validation
        const mobileRegex = /^[0-9]{10}$/;
        if (!mobileRegex.test(newUser.mobile)) {
            showAddMessage('error', 'Please enter a valid 10-digit mobile number');
            return;
        }
        
        addUserBtn.disabled = true;
        const data = new URLSearchParams();
        data.append('ajax', 'user_add');
        for (let key in newUser) {
            data.append(key, newUser[key]);
        }
        
        fetch('signature.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data
        })
        .then(r => r.json())
        .then(res => {
            addUserBtn.disabled = false;
            if (res.success) {
                showAddMessage('success', 'User added successfully');
                // Clear form
                document.getElementById('new_name').value = '';
                document.getElementById('new_username').value = '';
                document.getElementById('new_email').value = '';
                document.getElementById('new_mobile').value = '';
                document.getElementById('new_designation').value = '';
                document.getElementById('new_status').value = 'active';
                
                // Refresh table
                loadAllUsers();
                
                // Add to selector if active and appropriate designation
                if (newUser.status === 'active' && 
                    (newUser.designation === 'Relationship Manager' || 
                     newUser.designation === 'Associate Relationship Manager')) {
                    const option = document.createElement('option');
                    option.value = res.id;
                    option.textContent = `${newUser.name} (${newUser.designation})`;
                    selector.appendChild(option);
                }
            } else {
                showAddMessage('error', res.error || 'Failed to add user');
            }
        })
        .catch(() => {
            addUserBtn.disabled = false;
            showAddMessage('error', 'Network error');
        });
    });

    function showAddMessage(type, msg) {
        addUserMessage.innerHTML = `<div class="${type === 'success' ? 'text-success' : 'text-danger'}" style="padding: 8px; background: ${type === 'success' ? '#d4edda' : '#f8d7da'}; border-radius: 4px;">
            ${type === 'success' ? '✓' : '✗'} ${msg}
        </div>`;
        setTimeout(() => addUserMessage.innerHTML = '', 3000);
    }

    // Attach event listeners to table buttons
    function attachTableEventListeners() {
        // Edit user buttons
        document.querySelectorAll('.edit-user').forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.dataset.id;
                editUser(userId);
            });
        });
        
        // Delete user buttons
        document.querySelectorAll('.delete-user').forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.dataset.id;
                deleteUser(userId);
            });
        });
    }

    function editUser(userId) {
        const row = document.querySelector(`tr[data-id="${userId}"]`);
        if (!row) return;
        
        const cells = row.querySelectorAll('td');
        const name = cells[1].textContent;
        const username = cells[2].textContent;
        const email = cells[3].textContent;
        const mobile = cells[4].textContent;
        const designation = cells[5].textContent;
        const status = cells[6].textContent.toLowerCase();
        
        // Populate signature fields and selector
        selector.value = userId;
        
        // If this user is in the selector, trigger change
        if (userDetails[userId]) {
            selector.dispatchEvent(new Event('change'));
            // Switch to edit mode
            editDetailsBtn.click();
        } else {
            alert('This user is not available in the signature selector. You can still edit in the database.');
        }
        
        // Close modal
        userModal.style.display = 'none';
    }

    function deleteUser(userId) {
        if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
            return;
        }
        
        const data = new URLSearchParams();
        data.append('ajax', 'user_delete');
        data.append('user_id', userId);
        
        fetch('signature.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                // Remove from table
                const row = document.querySelector(`tr[data-id="${userId}"]`);
                if (row) row.remove();
                
                // Remove from selector if present
                const option = selector.querySelector(`option[value="${userId}"]`);
                if (option) option.remove();
                
                showAddMessage('success', 'User deleted successfully');
            } else {
                showAddMessage('error', res.error || 'Failed to delete user');
            }
        })
        .catch(() => {
            showAddMessage('error', 'Network error');
        });
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initial attachment of event listeners
    attachTableEventListeners();
})();
</script>
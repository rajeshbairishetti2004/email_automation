<?php
// profile_management.php - Admin profile management interface
require_once 'db_config.php';
require_once 'auth.php';

// Check if user is admin
requireAuth();

$currentUser = getCurrentUser();

if (!isset($currentUser['designation']) || $currentUser['designation'] !== 'Admin') {
    header('Location: upload.php');
    exit;
}


$message = '';
$messageType = '';

// Handle Add/Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add New User
        if ($_POST['action'] === 'add') {
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $mobile = trim($_POST['mobile']);
            $name = trim($_POST['name']);
            $designation = trim($_POST['designation']);
            $password = $_POST['password'];
            $company_name = trim($_POST['company_name'] ?? 'Finance Doctor Private Limited');
            $website_url = trim($_POST['website_url'] ?? 'www.financedoctor.in');
            $status = 'active';

            try {
                $pdo = getPdo();
                
                // Check if username or email exists
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $checkStmt->execute([$username, $email]);
                
                if ($checkStmt->rowCount() > 0) {
                    $message = "Username or email already exists!";
                    $messageType = "error";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, mobile, name, designation, password_hash, company_name, website_url, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    if ($stmt->execute([$username, $email, $mobile, $name, $designation, $password_hash, $company_name, $website_url, $status])) {
                        $message = "User added successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error adding user";
                        $messageType = "error";
                    }
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = "error";
            }
        }
        
        // Edit User
        elseif ($_POST['action'] === 'edit') {
            $user_id = $_POST['user_id'];
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $mobile = trim($_POST['mobile']);
            $name = trim($_POST['name']);
            $designation = trim($_POST['designation']);
            $status = $_POST['status'];
            $company_name = trim($_POST['company_name']);
            $website_url = trim($_POST['website_url']);

            try {
                $pdo = getPdo();
                
                // Check if username/email exists for other users
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $checkStmt->execute([$username, $email, $user_id]);
                
                if ($checkStmt->rowCount() > 0) {
                    $message = "Username or email already exists for another user!";
                    $messageType = "error";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, mobile = ?, name = ?, designation = ?, status = ?, company_name = ?, website_url = ? WHERE id = ?");
                    
                    if ($stmt->execute([$username, $email, $mobile, $name, $designation, $status, $company_name, $website_url, $user_id])) {
                        $message = "User updated successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error updating user";
                        $messageType = "error";
                    }
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = "error";
            }
        }
        
        // Change Password
        elseif ($_POST['action'] === 'change_password') {
            $user_id = $_POST['user_id'];
            $new_password = $_POST['new_password'];
            
            try {
                $pdo = getPdo();
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                
                if ($stmt->execute([$password_hash, $user_id])) {
                    $message = "Password changed successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error changing password";
                    $messageType = "error";
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = "error";
            }
        }
        
        // Delete User
        elseif ($_POST['action'] === 'delete') {
            $user_id = $_POST['user_id'];
            
            try {
                $pdo = getPdo();
                
                // Don't allow deleting admin
                $checkStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $checkStmt->execute([$user_id]);
                $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && strtolower($user['username']) === 'admin') {
                    $message = "Cannot delete admin user!";
                    $messageType = "error";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    
                    if ($stmt->execute([$user_id])) {
                        $message = "User deleted successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error deleting user";
                        $messageType = "error";
                    }
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// Fetch all users
$users = [];
try {
    $pdo = getPdo();
    $usersQuery = "SELECT id, username, email, mobile, name, designation, status, company_name, website_url, created_at FROM users ORDER BY id DESC";
    $stmt = $pdo->query($usersQuery);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching users: " . $e->getMessage();
    $messageType = "error";
}

// Get designations list
$designations = [
    'Admin',
    'Relationship Manager',
    'Associate Relationship Manager',
    'Manager',
    'Associate',
    'Intern'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Management - Admin</title>
    <link rel="stylesheet" href="public/css/profile_management.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="main-scroll-container" style="height: calc(100vh - 72px); overflow-y: auto;">
    <div class="profile-management-container">
        <div class="management-header">
            <h1>User Profile Management</h1>
            <button class="btn-add" onclick="openAddUserModal()">
                <span class="plus-icon">+</span> Add New User
            </button>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
                <span class="close-btn" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="users-table-wrapper">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Designation</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['mobile']); ?></td>
                        <td>
                            <span class="designation-badge">
                                <?php echo htmlspecialchars($user['designation']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($user['company_name'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button onclick='openEditModal(<?php echo json_encode($user); ?>)' 
                                        class="btn-edit" 
                                        <?php echo (strtolower($user['username']) === 'admin') ? 'disabled' : ''; ?>>
                                    Edit
                                </button>
                                <button onclick="openPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                        class="btn-password">
                                    Password
                                </button>
                                <button onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                        class="btn-delete"
                                        <?php echo (strtolower($user['username']) === 'admin') ? 'disabled' : ''; ?>>
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <span class="close-modal" onclick="closeModal('addUserModal')">&times;</span>
            </div>
            <form method="POST" action="" class="user-form">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="mobile">Mobile *</label>
                        <input type="text" id="mobile" name="mobile" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="designation">Designation *</label>
                        <select id="designation" name="designation" required>
                            <option value="">Select Designation</option>
                            <?php foreach ($designations as $des): ?>
                                <option value="<?php echo $des; ?>"><?php echo $des; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name" value="Finance Doctor Private Limited">
                    </div>
                    
                    <div class="form-group">
                        <label for="website_url">Website URL</label>
                        <input type="text" id="website_url" name="website_url" value="www.financedoctor.in">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <span class="close-modal" onclick="closeModal('editUserModal')">&times;</span>
            </div>
            <form method="POST" action="" class="user-form">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_name">Full Name *</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_mobile">Mobile *</label>
                        <input type="text" id="edit_mobile" name="mobile" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_designation">Designation *</label>
                        <select id="edit_designation" name="designation" required>
                            <option value="">Select Designation</option>
                            <?php foreach ($designations as $des): ?>
                                <option value="<?php echo $des; ?>"><?php echo $des; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_company_name">Company Name</label>
                        <input type="text" id="edit_company_name" name="company_name">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_website_url">Website URL</label>
                        <input type="text" id="edit_website_url" name="website_url">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Update User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Change Password</h2>
                <span class="close-modal" onclick="closeModal('passwordModal')">&times;</span>
            </div>
            <form method="POST" action="" class="user-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="password_user_id">
                
                <div class="form-group">
                    <label for="username_display">Username</label>
                    <input type="text" id="username_display" disabled>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" required>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('passwordModal')">Cancel</button>
                    <button type="submit" class="btn-submit" onclick="return validatePassword()">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content delete-modal">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
            </div>
            <div class="delete-content">
                <p>Are you sure you want to delete user <strong id="deleteUsername"></strong>?</p>
                <p class="warning-text">This action cannot be undone!</p>
            </div>
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn-delete-confirm">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal Functions
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }

        function openEditModal(user) {
            if (user.username.toLowerCase() === 'admin') {
                alert('Cannot edit admin user!');
                return;
            }
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_mobile').value = user.mobile;
            document.getElementById('edit_designation').value = user.designation;
            document.getElementById('edit_status').value = user.status;
            document.getElementById('edit_company_name').value = user.company_name || '';
            document.getElementById('edit_website_url').value = user.website_url || '';
            document.getElementById('editUserModal').style.display = 'flex';
        }

        function openPasswordModal(userId, username) {
            document.getElementById('password_user_id').value = userId;
            document.getElementById('username_display').value = username;
            document.getElementById('passwordModal').style.display = 'flex';
        }

        function deleteUser(userId, username) {
            if (username.toLowerCase() === 'admin') {
                alert('Cannot delete admin user!');
                return;
            }
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function validatePassword() {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass !== confirmPass) {
                alert('Passwords do not match!');
                return false;
            }
            return true;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
        }

        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                message.style.display = 'none';
            });
        }, 5000);
    </script>
    </div>

</body>
</html>
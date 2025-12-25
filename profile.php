<?php
require_once 'auth.php'; 
require_once 'db_config.php';

requireAuth();

$pdo = getPdo();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$message = '';
$error = '';
$pwdMessage = '';

// 1. Show password changed message after redirect using Session
if (isset($_SESSION['pwd_success'])) {
    $pwdMessage = $_SESSION['pwd_success'];
    unset($_SESSION['pwd_success']);
}

// AJAX handler for real-time password verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_verify_password'])) {
    $oldPwd = $_POST['old_password'] ?? '';
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $storedHash = $stmt->fetchColumn();
    
    if (empty($oldPwd)) {
        echo "empty";
    } elseif (!password_verify($oldPwd, $storedHash)) {
        echo "invalid";
    } else {
        echo "valid";
    }
    exit;
}

// Handle Profile Update 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $designation = trim($_POST['designation']);

    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmtCheck->execute([$username, $userId]);
    if ($stmtCheck->fetch()) {
        $error = "Username already exists.";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, email = ?, mobile = ?, designation = ? WHERE id = ?");
        $stmt->execute([$username, $name, $email, $mobile, $designation, $userId]);
        $message = "Profile updated successfully!";
        $_SESSION['username'] = $username;
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $oldPwd = $_POST['old_password'] ?? '';
    $newPwd = $_POST['new_password'] ?? '';
    $confirmPwd = $_POST['confirm_password'] ?? '';

    $stmtPwd = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmtPwd->execute([$userId]);
    $hash = $stmtPwd->fetchColumn();

    if ($newPwd !== $confirmPwd) {
        // Mismatch handled by JS, but fallback for non-JS
        $_SESSION['pwd_success'] = 'Passwords do not match.';
        header("Location: profile.php");
        exit;
    }

    if ($hash && password_verify($oldPwd, $hash)) {
        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        $stmtUpdate = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmtUpdate->execute([$newHash, $userId]);
        $_SESSION['pwd_success'] = 'Password updated successfully!';
        header("Location: profile.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; padding-top: 20px; }
        .profile-container { max-width: 480px; margin: 48px auto; background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 36px 32px; position: relative; }
        .profile-title { font-size: 2rem; font-weight: 800; color: #2563eb; margin-bottom: 18px; text-align: center; }
        .form-group { margin-bottom: 18px; position: relative; }
        .form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 6px; font-size: 15px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; background: #f8fafc; box-sizing: border-box; }
        .nav-button { background: #2563eb; color: #fff; padding: 10px 22px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; width: 100%; margin-top: 10px; }
        .nav-button:disabled { background: #94a3b8; cursor: not-allowed; }
        .flash-success { background: #dcfce7; color: #166534; padding: 10px; border-radius: 8px; margin-bottom: 14px; text-align: center; font-weight: 600; }
        .flash-error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; margin-bottom: 14px; text-align: center; font-weight: 600; }
        .status-msg { font-size: 13px; margin-top: 5px; display: block; font-weight: 600; }
        .invalid { color: #dc2626; }
        .valid { color: #16a34a; }
        .divider { border: none; border-top: 1px solid #e2e8f0; margin: 32px 0 24px; }
        .pwd-toggle-btn { background: none; border: none; color: #2563eb; font-weight: 600; cursor: pointer; font-size: 15px; }
        .toggle-eye { position: absolute; right: 12px; top: 38px; cursor: pointer; color: #64748b; }
        .back-dashboard-btn { position: absolute; top: 20px; left: 20px; background: #64748b; color: #fff; padding: 8px 16px; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <a href="upload.php" class="back-dashboard-btn"><i class="fa fa-arrow-left"></i> Dashboard</a>
    
    <div class="profile-container">
        <div class="profile-title">My Profile</div>

        <?php if ($message): ?> <div class="flash-success"><?= $message ?></div> <?php endif; ?>
        <?php if ($error): ?> <div class="flash-error"><?= $error ?></div> <?php endif; ?>
        <?php if ($pwdMessage): ?> <div class="flash-success" id="pwdSuccessMsg"><?= $pwdMessage ?></div> <?php endif; ?>

        <form method="POST">
            <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= htmlspecialchars($currentUser['username']) ?>" required></div>
            <div class="form-group"><label>Full Name</label><input type="text" name="name" value="<?= htmlspecialchars($currentUser['name']) ?>" required></div>
            <div class="form-group"><label>Email Address</label><input type="email" name="email" value="<?= htmlspecialchars($currentUser['email']) ?>" required></div>
            <div class="form-group"><label>Mobile</label><input type="text" name="mobile" value="<?= htmlspecialchars($currentUser['mobile']) ?>" required></div>
            <div class="form-group"><label>Designation</label>
                <select name="designation">
                    <option value="Relationship Manager" <?= $currentUser['designation']=='Relationship Manager'?'selected':'' ?>>RM</option>
                    <option value="Associate Relationship Manager" <?= $currentUser['designation']=='Associate Relationship Manager'?'selected':'' ?>>ARM</option>
                </select>
            </div>
            <div class="form-group" style="border-top:1px solid #eee; padding-top:15px;">
            </div>
            <button type="submit" name="update_profile" class="nav-button">Update Details</button>
        </form>

        <hr class="divider">

        <button class="pwd-toggle-btn" type="button" onclick="togglePwdForm()">Change Password <i class="fa fa-key"></i></button>
        
        <form method="POST" id="pwdForm" style="display: <?= $pwdMessage ? 'block' : 'none' ?>; margin-top:15px;">
            <div class="form-group">
                <label>Old Password</label>
                <input type="password" id="old_pwd_input" name="old_password" required oninput="verifyOldPwd(this.value)">
                <span id="oldPwdStatus" class="status-msg"></span>
            </div>

            <div class="form-group">
                <label>New Password</label>
                <input type="password" id="new_pwd_input" name="new_password" required oninput="validateMatch()">
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" id="confirm_pwd_input" name="confirm_password" required oninput="validateMatch()">
                <span id="matchStatus" class="status-msg"></span>
            </div>

            <button type="submit" name="change_password" class="nav-button" id="pwdSubmitBtn" disabled>Update Password</button>
        </form>
    </div>

    <script>
        let isOldValid = false;
        let isMatchValid = false;

        function togglePwdForm() {
            var form = document.getElementById('pwdForm');
            form.style.display = (form.style.display === 'none') ? 'block' : 'none';
        }

        function updateSubmitButton() {
            document.getElementById('pwdSubmitBtn').disabled = !(isOldValid && isMatchValid);
        }

        // Verify Old Password via AJAX
        let oldTimeout = null;
        function verifyOldPwd(val) {
            clearTimeout(oldTimeout);
            const status = document.getElementById('oldPwdStatus');
            if(!val) { status.textContent = ""; isOldValid = false; updateSubmitButton(); return; }

            oldTimeout = setTimeout(() => {
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        const resp = xhr.responseText.trim();
                        if (resp === "valid") {
                            status.textContent = "✓ Correct"; status.className = "status-msg valid";
                            isOldValid = true;
                        } else {
                            status.textContent = "✗ Incorrect"; status.className = "status-msg invalid";
                            isOldValid = false;
                        }
                        updateSubmitButton();
                    }
                };
                xhr.send("ajax_verify_password=1&old_password=" + encodeURIComponent(val));
            }, 300);
        }

        // Validate if New and Confirm match
        function validateMatch() {
            const p1 = document.getElementById('new_pwd_input').value;
            const p2 = document.getElementById('confirm_pwd_input').value;
            const status = document.getElementById('matchStatus');

            if(!p1 || !p2) { status.textContent = ""; isMatchValid = false; }
            else if(p1 === p2) {
                status.textContent = "✓ Passwords match"; status.className = "status-msg valid";
                isMatchValid = true;
            } else {
                status.textContent = "✗ Passwords do not match"; status.className = "status-msg invalid";
                isMatchValid = false;
            }
            updateSubmitButton();
        }
    </script>
</body>
</html>

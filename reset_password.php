<?php
// reset_password.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (!isset($_SESSION['reset_user_id'])) {
    // Should only be accessed after successful OTP verification
    $_SESSION['message'] = 'Please request a password reset again.';
    header('Location: forgot_password.php');
    exit;
}

$error = '';
$userId = (int)$_SESSION['reset_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        if (resetPassword($userId, $password)) {
            unset($_SESSION['reset_user_id']); // Clear the reset token
            $_SESSION['message'] = 'Your password has been successfully reset. Please log in with your new password.';
            header('Location: login.php');
            exit;
        } else {
            $error = 'An error occurred while updating your password.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/reset_password.css">
    <script src="public/js/reset_password.js"></script>
</head>
<body>

<div class="container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Reset Password</h1>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="password">New Password (min 6 chars)</label>
        <input type="password" name="password" id="password" required>
        
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm_password" required>
        
        <button type="submit">Set New Password</button>
    </form>
</div>

</body>
</html>
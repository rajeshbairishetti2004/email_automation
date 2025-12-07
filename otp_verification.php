<?php
// otp_verification.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (!isset($_SESSION['otp_user_id']) || !isset($_SESSION['otp_type'])) {
    // Should only be accessed after register or forgot password
    header('Location: login.php');
    exit;
}

$error = '';
$otpType = $_SESSION['otp_type']; // 'register' or 'reset'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredOtp = trim($_POST['otp'] ?? '');
    $userId = (int)$_SESSION['otp_user_id'];

    if ($enteredOtp === STATIC_OTP) {
        if ($otpType === 'register') {
            // Activate the user and redirect to login
            if (activateUser($userId)) {
                unset($_SESSION['otp_user_id']);
                unset($_SESSION['otp_type']);
                $_SESSION['message'] = 'Your account is successfully activated. Please log in.';
                header('Location: login.php');
                exit;
            } else {
                $error = 'Failed to activate account. Please try registering again.';
            }
        } elseif ($otpType === 'reset') {
            // Proceed to the reset password page
            $_SESSION['reset_user_id'] = $userId;
            unset($_SESSION['otp_user_id']);
            unset($_SESSION['otp_type']);
            header('Location: reset_password.php');
            exit;
        }
    } else {
        $error = 'Invalid OTP. The static OTP is ' . STATIC_OTP . '.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>OTP Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/otp_verification.css">
    <script src="public/js/otp_verification.js"></script>
</head>
<body>

<div class="container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Verify OTP</h1>
    
    <?php if (isset($_SESSION['message'])): ?>
        <div class="flash-success"><?= htmlspecialchars($_SESSION['message']) ?></div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <p>Enter the 4-digit OTP sent to your email.</p>

    <form method="post">
        <label for="otp">One-Time Password</label>
        <input type="text" name="otp" id="otp" required maxlength="4" pattern="\d{4}">
        
        <button type="submit">Verify</button>
    </form>
    
    <p class="links" style="margin-top: 20px;">
        <a href="login.php">Cancel and Back to Login</a>
    </p>
</div>

</body>
</html>
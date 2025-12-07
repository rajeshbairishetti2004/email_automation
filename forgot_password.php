<?php
// forgot_password.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (isset($_SESSION['user_id'])) {
    header('Location: upload.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $result = startPasswordReset($email);
        
        if ($result === true) {
            $_SESSION['message'] = 'An OTP has been sent to your registered email (Static OTP: 1234). Please enter it to proceed.';
            header('Location: otp_verification.php');
            exit;
        } else {
            $error = $result; // Error message from startPasswordReset
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/forgot_password.css">
    <script src="public/js/forgot_password.js"></script>
</head>
<body>

<div class="container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Forgot Password</h1>
    <p>Enter your email address to receive a password reset OTP.</p>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="email">Email Address</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($email) ?>">
        
        <button type="submit">Send OTP</button>
    </form>
    
    <div class="links">
        <a href="login.php">Back to Login</a>
    </div>
</div>

</body>
</html>
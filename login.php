<?php
// login.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (isset($_SESSION['user_id'])) {
    // Already logged in, redirect to upload page
    header('Location: upload.php');
    exit;
}

$error = '';
$usernameOrEmail = ''; // Initialize variable for use in value attribute

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username_or_email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usernameOrEmail) || empty($password)) {
        $error = 'Please enter your username/email and password.';
    } elseif (attemptLogin($usernameOrEmail, $password)) {
        // Login successful. Redirect to intended page or upload.php.
        $redirectUrl = $_SESSION['intended_url'] ?? 'upload.php';
        unset($_SESSION['intended_url']);
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        $error = 'Invalid credentials or inactive account.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/login.css">
</head>
<body>

<div class="login-container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Client Report Generation Portal</h1>
    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['message'])): ?>
        <div class="flash-success"><?= htmlspecialchars($_SESSION['message']) ?></div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <form method="post">
        <label for="username_or_email">Username or Email</label>
        <input type="text" name="username_or_email" id="username_or_email" required value="<?= htmlspecialchars($usernameOrEmail) ?>">
        
        <label for="password">Password</label>
        <div class="password-container">
            <input type="password" name="password" id="password" required>
            <span class="toggle-password" onclick="togglePasswordVisibility('password')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.411A10.05 10.05 0 0 1 12 4c2.518 0 4.887.697 6.911 1.911.396.232.812.232 1.208 0A10.05 10.05 0 0 1 22.677 11.411c.232.396.232.812 0 1.208A10.05 10.05 0 0 1 12 20c-2.518 0-4.887-.697-6.911-1.911a.75.75 0 0 1-1.208 0A10.05 10.05 0 0 1 1.323 12.619c-.232-.396-.232-.812 0-1.208ZM12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z" clip-rule="evenodd"/></svg>
            </span>
        </div>
        
        <button type="submit">Log In</button>
    </form>
    
    <div class="links">
        <p>Don't have an account? <a href="register.php">Register</a></p>
        <p>Forgot password? <a href="forgot_password.php">Click here</a></p>
    </div>
</div>

<script>
    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        const toggle = field.nextElementSibling;
        if (field.type === 'password') {
            field.type = 'text';
            // Change SVG to unhidden state if necessary (simple SVG toggling omitted for brevity, stick to basic functionality)
        } else {
            field.type = 'password';
            // Change SVG back to hidden state
        }
    }
</script>

</body>
</html>
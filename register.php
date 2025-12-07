<?php
// register.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (isset($_SESSION['user_id'])) {
    header('Location: upload.php');
    exit;
}

$error = '';
$name = $username = $email = $mobile = $designation = ''; // Added $designation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $designation = trim($_POST['designation'] ?? ''); // Read designation
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Check AJAX availability status
    if (isset($_POST['username_available']) && $_POST['username_available'] === 'false') {
         $error = 'The chosen username is already taken. Please choose another.';
    } elseif (empty($name) || empty($username) || empty($email) || empty($mobile) || empty($designation) || empty($password) || empty($confirmPassword)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        // UPDATED: Pass designation to registerUser
        $result = registerUser($username, $email, $mobile, $password, $name, $designation);
        
        if ($result === true) {
            // Registration success, proceed to OTP verification
            $_SESSION['message'] = 'Registration successful! Please check your email for the OTP (Static OTP: 1234).';
            header('Location: otp_verification.php');
            exit;
        } else {
            // Registration failed (e.g., username/email exists)
            $error = $result;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/register.css">
    <script src="public/js/register.js"></script>
</head>
<body>

<div class="register-container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Register for an Account</h1>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="name">Full Name</label>
        <input type="text" name="name" id="name" required value="<?= htmlspecialchars($name) ?>">
        
        <label for="username">Username</label>
        <input type="text" name="username" id="username" required value="<?= htmlspecialchars($username) ?>">
        <div id="username-feedback"></div>
        <input type="hidden" name="username_available" id="username_available" value="true">

        <label for="email">Email Address</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($email) ?>">

        <label for="mobile">Mobile Number</label>
        <input type="tel" name="mobile" id="mobile" required value="<?= htmlspecialchars($mobile) ?>">

        <label for="designation">Designation</label>
        <select name="designation" id="designation" required>
            <option value="" disabled selected>Select your role</option>
            <option value="Relationship Manager" <?= ($designation === 'Relationship Manager' ? 'selected' : '') ?>>Relationship Manager</option>
            <option value="Associate Relationship Manager" <?= ($designation === 'Associate Relationship Manager' ? 'selected' : '') ?>>Associate Relationship Manager</option>
        </select>
        
        <label for="password">Password (min 6 chars)</label>
        <div class="password-container">
            <input type="password" name="password" id="password" required>
            <span class="toggle-password" onclick="togglePasswordVisibility('password')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.411A10.05 10.05 0 0 1 12 4c2.518 0 4.887.697 6.911 1.911.396.232.812.232 1.208 0A10.05 10.05 0 0 1 22.677 11.411c.232.396.232.812 0 1.208A10.05 10.05 0 0 1 12 20c-2.518 0-4.887-.697-6.911-1.911a.75.75 0 0 1-1.208 0A10.05 10.05 0 0 1 1.323 12.619c-.232-.396-.232-.812 0-1.208ZM12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z" clip-rule="evenodd"/></svg>
            </span>
        </div>
        
        <label for="confirm_password">Confirm Password</label>
        <div class="password-container">
            <input type="password" name="confirm_password" id="confirm_password" required>
            <span class="toggle-password" onclick="togglePasswordVisibility('confirm_password')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.411A10.05 10.05 0 0 1 12 4c2.518 0 4.887.697 6.911 1.911.396.232.812.232 1.208 0A10.05 10.05 0 0 1 22.677 11.411c.232.396.232.812 0 1.208A10.05 10.05 0 0 1 12 20c-2.518 0-4.887-.697-6.911-1.911a.75.75 0 0 1-1.208 0A10.05 10.05 0 0 1 1.323 12.619c-.232-.396-.232-.812 0-1.208ZM12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z" clip-rule="evenodd"/></svg>
            </span>
        </div>
        
        <button type="submit" id="register-button">Register</button>
    </form>
    
    <div class="links">
        Already have an account? <a href="login.php">Sign In</a>
    </div>
</div>

</body>
</html>
<?php
// register.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (isset($_SESSION['user_id'])) {
    header('Location: upload.php');
    exit;
}

$error = '';
$name = $username = $email = $mobile = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Check AJAX availability status
    if (isset($_POST['username_available']) && $_POST['username_available'] === 'false') {
         $error = 'The chosen username is already taken. Please choose another.';
    } elseif (empty($name) || empty($username) || empty($email) || empty($mobile) || empty($password) || empty($confirmPassword)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        $result = registerUser($username, $email, $mobile, $password, $name);
        
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
    <style>
        body {
            background-color: #f7f9fb;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', sans-serif;
        }
        .register-container {
            width: 100%;
            max-width: 450px;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .logo-img {
            width: 80px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            color: #0288D1;
            margin-bottom: 30px;
            font-family: 'Poppins', sans-serif;
        }
        label {
            display: block;
            margin-bottom: 5px;
            text-align: left;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        /* Password container style */
        .password-container {
            position: relative;
        }
        .password-container input[type="text"],
        .password-container input[type="password"] {
            padding-right: 40px; /* Make space for the icon */
            margin-bottom: 20px !important;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            width: 20px;
            height: 20px;
        }
        /* Username Feedback */
        #username-feedback {
            font-size: 12px;
            margin-top: -15px;
            margin-bottom: 10px;
            text-align: left;
            min-height: 15px;
        }
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 2px solid #E3F2FD;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input:focus {
            border-color: #4FC3F7;
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 195, 247, 0.1);
        }
        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4FC3F7 0%, #29B6F6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(41, 182, 246, 0.3);
        }
        button[type="submit"]:hover {
            background: linear-gradient(135deg, #29B6F6 0%, #0288D1 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(41, 182, 246, 0.4);
        }
        .links {
            margin-top: 25px;
            font-size: 14px;
        }
        .links a {
            color: #0288D1;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .links a:hover {
            color: #4FC3F7;
        }
        .flash-error {
            background-color: #ffdddd;
            color: #d8000c;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #d8000c;
            font-size: 14px;
            text-align: left;
        }
    </style>
</head>
<body>

<div class="register-container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Register for an Account</h1>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="name">Name</label>
        <input type="text" name="name" id="name" required value="<?= htmlspecialchars($name) ?>">
        
        <label for="username">Username</label>
        <input type="text" name="username" id="username" required value="<?= htmlspecialchars($username) ?>">
        <div id="username-feedback"></div>
        <input type="hidden" name="username_available" id="username_available" value="true">

        <label for="email">Email Address</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($email) ?>">

        <label for="mobile">Mobile Number</label>
        <input type="tel" name="mobile" id="mobile" required value="<?= htmlspecialchars($mobile) ?>">
        
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

<script>
    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        if (field.type === 'password') {
            field.type = 'text';
        } else {
            field.type = 'password';
        }
    }

    const usernameInput = document.getElementById('username');
    const feedbackDiv = document.getElementById('username-feedback');
    const availableInput = document.getElementById('username_available');
    let typingTimer;
    const doneTypingInterval = 500; // 0.5 seconds

    usernameInput.addEventListener('keyup', () => {
        clearTimeout(typingTimer);
        if (usernameInput.value) {
            typingTimer = setTimeout(checkUsernameAvailability, doneTypingInterval);
        } else {
            feedbackDiv.textContent = '';
            availableInput.value = 'false';
        }
    });

    function checkUsernameAvailability() {
        const username = usernameInput.value.trim();
        if (username.length < 3) {
            feedbackDiv.innerHTML = '<span class="text-danger">Username must be at least 3 characters.</span>';
            availableInput.value = 'false';
            return;
        }

        feedbackDiv.innerHTML = 'Checking...';

        // Send AJAX request to auth.php to check availability
        fetch(`auth.php?action=check_username&username=${encodeURIComponent(username)}`)
            .then(response => response.json())
            .then(data => {
                if (data.available) {
                    feedbackDiv.innerHTML = '<span class="text-success">' + data.message + '</span>';
                    availableInput.value = 'true';
                } else {
                    feedbackDiv.innerHTML = '<span class="text-danger">' + data.message + '</span>';
                    availableInput.value = 'false';
                }
            })
            .catch(error => {
                feedbackDiv.innerHTML = '<span class="text-danger">Error checking username.</span>';
                availableInput.value = 'false';
                console.error('AJAX Error:', error);
            });
    }
</script>

</body>
</html>
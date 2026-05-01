<?php
// login.php

require_once 'auth.php'; // Includes session_start() and db_config.php

if (isset($_SESSION['user_id'])) {
    // Already logged in, redirect to upload page
    header('Location: upload.php');
    exit;
}

$error = '';

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
    <link rel="icon" type="image/x-icon" href="image.png">
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

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .logo-img {
            width: 100px;
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
            margin-bottom: 20px;
        }

        .password-container input[type="text"],
        .password-container input[type="password"] {
            padding-right: 40px;
            /* Make space for the icon */
            margin-bottom: 0;
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

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
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
            margin-top: 20px;
            /* Space above login button */
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
            margin: 0 10px;
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

        .flash-success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            font-size: 14px;
            text-align: left;
        }
    </style>
</head>

<body>

    <div class="login-container">
        <img src="image.png" alt="Logo" class="logo-img">
        <h1>Review Automation</h1>

        <?php if ($error): ?>
            <div class="flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="flash-success"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <form method="post">
            <label for="username_or_email">Username or Email</label>
            <input type="text" name="username_or_email" id="username_or_email" required value="<?= htmlspecialchars($usernameOrEmail ?? '') ?>">

            <label for="password">Password</label>
            <div class="password-container">
                <input type="password" name="password" id="password" required>
                <span class="toggle-password" onclick="togglePasswordVisibility('password')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                        <path fill-rule="evenodd" d="M1.323 11.411A10.05 10.05 0 0 1 12 4c2.518 0 4.887.697 6.911 1.911.396.232.812.232 1.208 0A10.05 10.05 0 0 1 22.677 11.411c.232.396.232.812 0 1.208A10.05 10.05 0 0 1 12 20c-2.518 0-4.887-.697-6.911-1.911a.75.75 0 0 1-1.208 0A10.05 10.05 0 0 1 1.323 12.619c-.232-.396-.232-.812 0-1.208ZM12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z" clip-rule="evenodd" />
                    </svg>
                </span>
            </div>

            <button type="submit">Log In</button>
        </form>


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
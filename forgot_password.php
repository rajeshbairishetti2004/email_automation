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
        // If startPasswordReset() exists use it, otherwise fallback to a safe session-based OTP
        if (function_exists('startPasswordReset')) {
            $result = startPasswordReset($email);
        } else {
            // Fallback: set static OTP (for local/dev) and persist email in session to be verified on otp_verification.php
            // NOTE: replace with a real implementation in production.
            $_SESSION['reset_email'] = $email;
            $_SESSION['otp'] = '1234';
            $result = true;
        }

        if ($result === true) {
            // Persist email for the verification step (used by otp_verification.php)
            if (empty($_SESSION['reset_email'])) $_SESSION['reset_email'] = $email;
            $_SESSION['message'] = 'An OTP has been sent to your registered email (Static OTP: 1234). Please enter it to proceed.';
            header('Location: otp_verification.php');
            exit;
        } else {
            // If startPasswordReset returned an error string, show that
            $error = is_string($result) ? $result : 'Failed to send OTP. Please try again later.';
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
        .container {
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
        input[type="email"] {
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
    </style>
</head>
<body>

<div class="container">
    <img src="image.png" alt="Logo" class="logo-img">
    <h1>Forgot Password</h1>
    <p>Enter your email address to receive a password reset OTP.</p>

    <?php if ($error): ?>
        <div class="flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on" aria-describedby="emailHelp">
        <label for="email">Email Address</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($email) ?>" autocomplete="email" aria-label="Email address">
        
        <button type="submit">Send OTP</button>
    </form>
    
    <div class="links">
        <a href="login.php">Back to Login</a>
    </div>
</div>

</body>
</html>
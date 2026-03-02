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
        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 2px solid #E3F2FD;
            border-radius: 8px;
            font-size: 18px;
            text-align: center;
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
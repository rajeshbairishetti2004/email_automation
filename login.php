<?php
// login.php
// - Handles authentication for all pages
// - Simple session-based login

require_once 'env_loader.php';

session_start();

define('ADMIN_USER', $_ENV['ADMIN_USERNAME'] ?? 'admin');
define('ADMIN_PASS', $_ENV['ADMIN_PASSWORD'] ?? 'admin123');

function checkAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    return true;
}

function requireAuth() {
    if (!checkAuth()) {
        $current_url = $_SERVER['REQUEST_URI'];
        header('Location: login.php?redirect=' . urlencode($current_url));
        exit;
    }
}

// If already logged in, redirect to intended page
if (checkAuth() && basename($_SERVER['PHP_SELF']) === 'login.php') {
    $redirect = $_GET['redirect'] ?? 'upload.php';
    header('Location: ' . $redirect);
    exit;
}

// Handle login form submission
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'], $_POST['login_pass'])) {
    if ($_POST['login_user'] === ADMIN_USER && $_POST['login_pass'] === ADMIN_PASS) {
        $_SESSION['logged_in'] = true;
        $redirect = $_POST['redirect'] ?? 'upload.php';
        header('Location: ' . $redirect);
        exit;
    }
    $login_error = 'Invalid credentials';
}

// Show login form if not logged in
if (!checkAuth()) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Login - Client Reports</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            form { max-width: 320px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 6px; }
            label { display: block; margin-top: 10px; }
            input { width: 100%; padding: 6px; margin-top: 4px; }
            button { margin-top: 15px; padding: 8px 16px; }
            .error { color: #b30000; margin-top: 10px; }
        </style>
    </head>
    <body>
    <h1>Client Reports Login</h1>
    <form method="post">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect'] ?? 'upload.php'); ?>">
        <label>Username
            <input type="text" name="login_user" required>
        </label>
        <label>Password
            <input type="password" name="login_pass" required>
        </label>
        <button type="submit">Login</button>
        <?php if (!empty($login_error)): ?>
            <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
        <?php endif; ?>
    </form>
    </body>
    </html>
    <?php
    exit;
}
?>
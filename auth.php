<?php
// auth.php (Updated with designation in registration)

require_once 'db_config.php';
// Start the session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Static OTP as requested
const STATIC_OTP = '1234';

/**
 * Checks if a user is logged in.
 * If not, redirects to the login page.
 */
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        // Store the intended page to redirect back after login
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

/**
 * Retrieves the currently logged-in user's details.
 * @return array|null User details (id, username, name, email, mobile, designation) or null if not logged in.
 */
function getCurrentUser(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $pdo = getPdo();
    // Select all required fields for header display and RM defaults.
    $stmt = $pdo->prepare("SELECT id, username, name, email, mobile, designation FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    
    // FIX: Explicitly check for false return from fetch to prevent TypeError
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user === false) {
        return null;
    }
    
    // Safety Fallbacks
    $user['name'] = $user['name'] ?? $user['username'];
    // FIX: Fallback to 'Relationship Manager' (the most common professional title)
    $user['designation'] = $user['designation'] ?? 'Relationship Manager'; 
    $user['mobile'] = $user['mobile'] ?? 'N/A';
    $user['email'] = $user['email'] ?? 'N/A';
    
    return $user;
}


/**
 * Attempts to log in a user.
 * @return bool True on success, false otherwise.
 */
function attemptLogin(string $emailOrUsername, string $password): bool {
    $pdo = getPdo();
    
    // Check if the input is an email or username
    $field = filter_var($emailOrUsername, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    
    $stmt = $pdo->prepare("SELECT id, password_hash, status FROM users WHERE {$field} = :value");
    $stmt->execute([':value' => $emailOrUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
        // Successful login: Set the user ID in the session
        $_SESSION['user_id'] = $user['id'];
        return true;
    }
    
    return false;
}

/**
 * Registers a new user.
 * UPDATED: Added $designation parameter.
 * @return bool|string User ID on success, error message string otherwise.
 */
function registerUser(string $username, string $email, string $mobile, string $password, string $name, string $designation): bool|string {
    $pdo = getPdo();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // 1. Check for existing username or email
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
    $stmt->execute([':username' => $username, ':email' => $email]);
    if ($stmt->fetchColumn() > 0) {
        return "Username or email already exists.";
    }

    // 2. Insert new user with 'inactive' status and the provided designation
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, mobile, designation, password_hash, name, status)
        VALUES (:username, :email, :mobile, :designation, :password_hash, :name, 'inactive')
    ");

    try {
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':mobile' => $mobile,
            ':designation' => $designation, // <-- NEW PARAMETER
            ':password_hash' => $passwordHash,
            ':name' => $name,
        ]);
        
        $userId = $pdo->lastInsertId();
        
        // Store details in session for OTP verification
        $_SESSION['otp_user_id'] = $userId;
        $_SESSION['otp_type'] = 'register';
        
        return true;

    } catch (PDOException $e) {
        // Log the error for debugging
        error_log("Registration error: " . $e->getMessage());
        return "An internal error occurred during registration.";
    }
}

/**
 * Checks if a username is available. Used for AJAX validation.
 */
function isUsernameAvailable(string $username): bool {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Marks a user's status as 'active' after successful OTP verification.
 */
function activateUser(int $userId): bool {
    $pdo = getPdo();
    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id AND status = 'inactive'");
    return $stmt->execute([':id' => $userId]);
}

/**
 * Starts the password reset process.
 * @return bool|string True on success, error message string otherwise.
 */
function startPasswordReset(string $email): bool|string {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND status = 'active'");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // For the static OTP, we store the user ID in the session
        $_SESSION['otp_user_id'] = $user['id'];
        $_SESSION['otp_type'] = 'reset';
        return true;
    }
    
    return "No active account found with that email address.";
}

/**
 * Completes the password reset process.
 * @return bool True on success, false otherwise.
 */
function resetPassword(int $userId, string $newPassword): bool {
    $pdo = getPdo();
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
    return $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
}


/**
 * Logs out the current user.
 */
function logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // FIX: Clear all session variables
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle AJAX request for username availability
if (isset($_GET['action']) && $_GET['action'] === 'check_username' && isset($_GET['username'])) {
    header('Content-Type: application/json');
    $username = trim($_GET['username']);
    if (empty($username)) {
        echo json_encode(['available' => false, 'message' => 'Username cannot be empty.']);
    } else {
        $isAvailable = isUsernameAvailable($username);
        if ($isAvailable) {
            echo json_encode(['available' => true, 'message' => 'Username is available.']);
        } else {
            echo json_encode(['available' => false, 'message' => 'Username is already taken.']);
        }
    }
    exit;
}
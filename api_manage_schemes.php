<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();
$currentUser = getCurrentUser();

// Add scheme to master_schemes
if (isset($_POST['add_to_board'])) {
    $name = trim($_POST['name'] ?? '');
    $cat = trim($_POST['cat'] ?? '');
    if ($name && $cat) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO master_schemes (scheme_name, category, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$name, $cat, $_SESSION['user_id']]);
        echo 'success';
    } else {
        http_response_code(400);
        echo 'Invalid input';
    }
    exit;
}

// Delete scheme from master_schemes
if (isset($_POST['delete_scheme'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM master_schemes WHERE id = ?");
        $stmt->execute([$id]);
        echo 'deleted';
    } else {
        http_response_code(400);
        echo 'Invalid ID';
    }
    exit;
}

http_response_code(400);
echo 'No valid action';

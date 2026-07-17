<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();
$currentUser = getCurrentUser();

// ===== ADD SCHEME =====
if (isset($_POST['add_to_board'])) {
    $name   = trim($_POST['name'] ?? '');
    $cat    = trim($_POST['cat'] ?? '');
    $is_usa = isset($_POST['is_usa']) ? (int)$_POST['is_usa'] : 0;

    if (!$name || !$cat) {
        http_response_code(400);
        echo 'Invalid input';
        exit;
    }

    // Check if scheme already exists in same region
    $check = $pdo->prepare("SELECT id FROM master_schemes WHERE scheme_name = ? AND is_usa = ? LIMIT 1");
    $check->execute([$name, $is_usa]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Move to new category within same region
        $stmt = $pdo->prepare("UPDATE master_schemes SET category = ? WHERE id = ?");
        $stmt->execute([$cat, $existing['id']]);
        echo "success";
        exit;
    }

    // Insert new scheme
    $stmt = $pdo->prepare("INSERT INTO master_schemes (scheme_name, category, is_usa) VALUES (?, ?, ?)");
    $stmt->execute([$name, $cat, $is_usa]);
    echo 'success';
    exit;
}

// ===== DELETE SCHEME =====
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

// ===== MOVE SCHEME (Drag & Drop) =====
if (isset($_POST['move_scheme'])) {
    $id              = (int)($_POST['id'] ?? 0);
    $target_category = $_POST['target_category'] ?? '';

    if (!in_array($target_category, ['recommended', 'observation', 'drop'])) {
        http_response_code(400);
        echo "Invalid category";
        exit;
    }

    $stmt = $pdo->prepare("UPDATE master_schemes SET category = ? WHERE id = ?");
    if ($stmt->execute([$target_category, $id])) {
        echo "success";
    } else {
        http_response_code(500);
        echo "Failed to move scheme";
    }
    exit;
}

// ===== UPDATE SCHEME NAME =====
if (isset($_POST['update_scheme'])) {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if (!$id || !$name) {
        http_response_code(400);
        echo "Invalid data";
        exit;
    }

    $stmt = $pdo->prepare("UPDATE master_schemes SET scheme_name = ? WHERE id = ?");
    if ($stmt->execute([$name, $id])) {
        echo "success";
    } else {
        http_response_code(500);
        echo "Failed to update";
    }
    exit;
}

// ===== FETCH SCHEMES BY CATEGORY + REGION =====
if (isset($_POST['get_category'])) {
    $category = $_POST['category'] ?? '';
    $is_usa   = isset($_POST['is_usa']) ? (int)$_POST['is_usa'] : 0;

    if (!in_array($category, ['recommended', 'observation', 'drop'])) {
        http_response_code(400);
        echo "Invalid category";
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, scheme_name FROM master_schemes WHERE category = ? AND is_usa = ? ORDER BY scheme_name ASC");
    $stmt->execute([$category, $is_usa]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

http_response_code(400);
echo 'No valid action';
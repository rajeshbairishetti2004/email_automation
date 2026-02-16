<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();
$currentUser = getCurrentUser();

// Add scheme to master_schemes
if (isset($_POST['add_to_board'])) {
    $name = trim($_POST['name'] ?? '');
    $cat  = trim($_POST['cat'] ?? '');

    if (!$name || !$cat) {
        http_response_code(400);
        echo 'Invalid input';
        exit;
    }

    // 🔍 Check if scheme already exists (any category)
    $check = $pdo->prepare(
        "SELECT category FROM master_schemes WHERE scheme_name = ? LIMIT 1"
    );
    $check->execute([$name]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Map DB category to user-friendly label
        $labels = [
            'recommended' => 'Recommended',
            'observation' => 'Under Observation',
            'drop'        => 'Exit/Drop'
        ];
        $section = $labels[$existing['category']] ?? $existing['category'];

        if ($existing['category'] !== $cat) {
            http_response_code(409);
            echo "$name is already present in $section schemes.";
        } else {
            http_response_code(409);
            echo "$name is already present in $section schemes.";
        }
        exit;
    }

    // ✅ Insert if not exists at all
    $stmt = $pdo->prepare(
        "INSERT INTO master_schemes (scheme_name, category, created_by)
         VALUES (?, ?, ?)"
    );
    $stmt->execute([$name, $cat, $_SESSION['user_id']]);

    echo 'success';
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
// Handle Move Scheme (Drag & Drop)
if (isset($_POST['move_scheme'])) {
    $id = $_POST['id'];
    $target_category = $_POST['target_category'];
    
    // Validate category
    if (!in_array($target_category, ['recommended', 'observation', 'drop'])) {
        http_response_code(400);
        echo "Invalid category";
        exit;
    }
    
    // Update the scheme's category
    $stmt = $pdo->prepare("UPDATE master_schemes SET category = ? WHERE id = ?");
    if ($stmt->execute([$target_category, $id])) {
        echo "success";
    } else {
        http_response_code(500);
        echo "Failed to move scheme";
    }
    exit;
}
http_response_code(400);
echo 'No valid action';

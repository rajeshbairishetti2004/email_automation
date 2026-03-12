<?php
// api_search_schemes.php
require_once 'db_config.php';
require_once 'auth.php';

header('Content-Type: application/json');

try {
    requireAuth();

    $pdo   = getPdo();
    $query = trim($_GET['q'] ?? '');
    $isUsa = isset($_GET['is_usa']) ? (int)$_GET['is_usa'] : 0;

    if ($query === '') {
        $stmt = $pdo->prepare("
            SELECT scheme_name
            FROM master_schemes
            WHERE is_usa = ?
              AND category = 'recommended'
            ORDER BY scheme_name ASC
            LIMIT 100
        ");
        $stmt->execute([$isUsa]);
    } else {
        $stmt = $pdo->prepare("
            SELECT scheme_name
            FROM master_schemes
            WHERE is_usa = ?
              AND category = 'recommended'
              AND scheme_name LIKE ?
            ORDER BY scheme_name ASC
            LIMIT 50
        ");
        $stmt->execute([$isUsa, '%' . $query . '%']);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([]);
}
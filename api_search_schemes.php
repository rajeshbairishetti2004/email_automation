<?php
require_once 'db_config.php';

$pdo = getPdo();

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT scheme_name 
    FROM master_schemes 
    WHERE scheme_name LIKE ?
    ORDER BY scheme_name
    LIMIT 10
");

$stmt->execute(["%$q%"]);

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($data);
<?php
require_once 'db_config.php';

$pdo = getPdo();

$q = trim($_GET['q'] ?? '');

if ($q === '') {

    $stmt = $pdo->prepare("
        SELECT scheme_name
        FROM master_schemes
        ORDER BY scheme_name
        LIMIT 50
    ");
    $stmt->execute();

} else {

    $stmt = $pdo->prepare("
        SELECT scheme_name
        FROM master_schemes
        WHERE scheme_name LIKE ?
        ORDER BY scheme_name
        LIMIT 50
    ");
    $stmt->execute(["%$q%"]);

}

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($data);
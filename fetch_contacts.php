<?php
require_once 'auth.php';
require_once 'db_config.php';
requireAuth();
$pdo = getPdo();

$query = $_GET['q'] ?? '';
if (strlen($query) < 2) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("SELECT name, email_address FROM email_contacts WHERE name LIKE ? OR email_address LIKE ? LIMIT 10");
$searchTerm = "%$query%";
$stmt->execute([$searchTerm, $searchTerm]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

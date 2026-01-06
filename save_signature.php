<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();

$userId = $_SESSION['user_id'] ?? 0;
if ($userId <= 0) {
    header('Location: profile.php?error=auth');
    exit;
}

$name = trim($_POST['name'] ?? '');
$designation = trim($_POST['designation'] ?? '');
$company_name = trim($_POST['company_name'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$email = trim($_POST['email'] ?? '');

$stmt = $pdo->prepare("
    UPDATE users SET
        name = ?,
        designation = ?,
        company_name = ?,
        mobile = ?,
        email = ?
    WHERE id = ?
");
$stmt->execute([
    $name,
    $designation,
    $company_name,
    $mobile,
    $email,
    $userId
]);

header('Location: profile.php?saved=1');
exit;

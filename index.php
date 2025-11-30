<?php
// Auto-redirect to login.php (tries same dir, then report/)
$tries = [
    __DIR__ . '/login.php' => 'login.php',
    __DIR__ . '/report/login.php' => 'report/login.php',
];

foreach ($tries as $filePath => $redirectUrl) {
    if (file_exists($filePath)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// ...existing code...
?>
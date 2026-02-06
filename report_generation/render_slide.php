<?php
// report_generation/render_slide.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = (int)($_GET['page'] ?? 0);

if ($page === 2) {
    include __DIR__ . '/slides/page2.php';
    exit;
}


require_once __DIR__ . '/database.php';

/* ===============================
   GET PARAMETERS
================================ */
$client_id = $_GET['client_id'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

/* ===============================
   BASIC VALIDATION
================================ */
if (!$client_id) {
    echo "<div style='padding:40px;font-family:Segoe UI;color:#999;'>Client not specified</div>";
    exit;
}

/* ===============================
   FETCH SLIDE FROM DATABASE
================================ */
$pages = getClientPages($client_id);

if (!isset($pages[$page])) {
    echo "<div style='padding:40px;font-family:Segoe UI;color:#999;'>No slide content</div>";
    exit;
}

/* ===============================
   OUTPUT FINAL SLIDE HTML
   (NO WRAPPERS, NO CSS, NO JS)
================================ */
echo $pages[$page]['content'];

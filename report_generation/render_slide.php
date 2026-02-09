<?php
// report_generation/render_slide.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

$page = (int)($_GET['page'] ?? 0);
$client_id = $_GET['client_id'] ?? null;

if (!$client_id) {
    echo "<div style='padding:40px;color:#999;'>Client not specified</div>";
    exit;
}

/* ===============================
   DYNAMIC SLIDES (DIRECT RENDER)
================================ */

// ✅ Page 2 (already working)
if ($page === 2) {
    include __DIR__ . '/slides/page2.php';
    exit;
}

// ✅ Page 3 (FIX)
if ($page === 3) {
    include __DIR__ . '/slides/page3.php';
    exit;
}

/* ===============================
   STATIC SLIDES (DB RENDER)
================================ */

$pages = getClientPages($client_id);

if (!isset($pages[$page])) {
    echo "<div style='padding:40px;font-family:Segoe UI;color:#999;'>No slide content</div>";
    exit;
}

echo $pages[$page]['content'];

<?php
// report_generation/render_slide.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

$page = (int)($_GET['page'] ?? 0);
$client_id = $_GET['client_id'] ?? null;

if (!$client_id || $page <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client or page not specified</div>";
    exit;
}

/* ===============================
   DYNAMIC SLIDES (DIRECT RENDER)
================================ */

// Page 2 – Dynamic
if ($page === 2) {
    include __DIR__ . '/slides/page2.php';
    exit;
}

// Page 3 – Dynamic
if ($page === 3) {
    include __DIR__ . '/slides/page3.php';
    exit;
}

// Page 5 – Custom slide (self-managed edit/save)
if ($page === 5) {
    include __DIR__ . '/slides/page5.php';
    exit;
}

/* ===============================
   STATIC SLIDES (DB CONTENT)
================================ */

$pages = getClientPages($client_id);

if (!isset($pages[$page])) {
    echo "<div style='padding:40px;font-family:Segoe UI;color:#999;'>No slide content</div>";
    exit;
}

echo $pages[$page]['content'];

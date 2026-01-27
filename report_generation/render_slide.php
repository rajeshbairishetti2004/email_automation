<?php
// report_generator/render_slide.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

/* ===============================
   GET PARAMETERS
================================ */
$client_id = $_GET['client_id'] ?? null;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

/* ===============================
   BASIC VALIDATION
================================ */
if (!$client_id) {
    echo "<h3 style='text-align:center;margin-top:100px;color:#999;'>Client not specified</h3>";
    exit;
}

/* ===============================
   PAGE 1 → CUSTOM COVER SLIDE
   (IMPORTANT: must EXIT)
================================ */
if ($page === 1) {
    include __DIR__ . '/page1.php';
    exit;
}

/* ===============================
   PAGE 2+ → DATABASE SLIDES
================================ */
$pages = getClientPages($client_id);

$slideContent = isset($pages[$page]) ? $pages[$page]['content'] : '';
$slideTitle   = isset($pages[$page]) ? $pages[$page]['title']   : "Slide {$page}";

/* Default slide content if empty */
if (empty($slideContent)) {
    $slideContent = "
        <div class='ppt-slide-content'>
            <div class='ppt-title-area'>
                <h1 class='ppt-title'>{$slideTitle}</h1>
            </div>
            <div class='ppt-content-area'>
                <p class='ppt-placeholder'>Start editing this slide...</p>
            </div>
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Slide <?php echo $page; ?></title>

<style>
/* ===============================
   RESET
================================ */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* ===============================
   VIEWPORT
================================ */
body {
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', Calibri, Arial, sans-serif;
}

/* ===============================
   SLIDE WRAPPER
================================ */
.ppt-slide-container {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* ===============================
   SLIDE (4:3)
================================ */
.ppt-slide {
    width: 960px;
    height: 720px;
    background: #ffffff;
    position: relative;
    overflow: hidden;
    box-shadow:
        0 0 0 1px rgba(0,0,0,0.1),
        0 10px 30px rgba(0,0,0,0.3);
    transform-origin: center center;
}

/* ===============================
   CONTENT
================================ */
.ppt-slide-content {
    width: 100%;
    height: 100%;
    padding: 60px 80px;
}

.ppt-title-area {
    margin-bottom: 40px;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 20px;
}

.ppt-title {
    font-size: 36px;
    font-weight: 600;
    color: #2e75b6;
}

.ppt-content-area {
    font-size: 20px;
    line-height: 1.6;
    color: #333;
}

.ppt-placeholder {
    color: #999;
    font-style: italic;
}

/* ===============================
   SLIDE NUMBER
================================ */
.slide-number {
    position: absolute;
    bottom: 20px;
    right: 30px;
    font-size: 14px;
    color: #888;
}
</style>
</head>

<body>

<div class="ppt-slide-container">
    <div class="ppt-slide" id="pptSlide">
        <div class="ppt-slide-content">
            <?php echo $slideContent; ?>
            <div class="slide-number">Slide <?php echo $page; ?></div>
        </div>
    </div>
</div>

<script>
/* ===============================
   AUTO SCALE TO FIT
================================ */
let currentScale = 1;

function scaleToFit() {
    const container = document.querySelector('.ppt-slide-container');
    const slide = document.getElementById('pptSlide');

    if (!container || !slide) return;

    const scaleX = container.clientWidth / 960;
    const scaleY = container.clientHeight / 720;
    currentScale = Math.min(scaleX, scaleY) * 0.9;

    slide.style.transform = `scale(${currentScale})`;
}

window.addEventListener('load', scaleToFit);
window.addEventListener('resize', scaleToFit);

/* ===============================
   CTRL + MOUSE ZOOM
================================ */
document.addEventListener('wheel', function (e) {
    if (!e.ctrlKey) return;
    e.preventDefault();

    currentScale += e.deltaY < 0 ? 0.1 : -0.1;
    currentScale = Math.max(0.25, Math.min(currentScale, 3));

    document.getElementById('pptSlide').style.transform =
        `scale(${currentScale})`;
}, { passive: false });
</script>

</body>
</html>

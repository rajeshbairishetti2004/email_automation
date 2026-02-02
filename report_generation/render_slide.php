<?php
// report_generation/render_slide.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

// Complete slide registry (same as in index.php)
$SLIDE_REGISTRY = [
    1 => ['template' => 'page1.php'],
    2 => ['template' => 'page2.php'],
    3 => ['template' => 'page3.php'],
    4 => ['template' => 'page4.php'],
    5 => ['template' => 'page5.php'],
    6 => ['template' => 'page6.php'],
    7 => ['template' => 'page7.php'],
    8 => ['template' => 'page8.php'],
    9 => ['template' => 'page9.php'],
    10 => ['template' => 'page10.php'],
    11 => ['template' => 'page11.php'],
    12 => ['template' => 'page12.php'],
    13 => ['template' => 'page13.php'],
    14 => ['template' => 'page14.php'],
    15 => ['template' => 'page15.php'],
    16 => ['template' => 'page16.php'],
    17 => ['template' => 'page17.php'],
    18 => ['template' => 'page18.php'],
    19 => ['template' => 'page19.php'],
    20 => ['template' => 'page20.php'],
    21 => ['template' => 'page21.php'],
    22 => ['template' => 'page22.php'],
    23 => ['template' => 'page23.php'],
    24 => ['template' => 'page24.php']
];

$client_id = $_GET['client_id'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// Validate page number
if ($page < 1 || $page > 24) {
    $page = 1;
}

// Check if the requested slide exists in registry
if (isset($SLIDE_REGISTRY[$page])) {
    $template = $SLIDE_REGISTRY[$page]['template'];
    $template_path = __DIR__ . '/slides/' . $template;
    
    if (file_exists($template_path)) {
        // Include the slide template
        include $template_path;
        
        // Add a script to notify parent window when slide is loaded
        echo '<script>
            if (window.parent !== window) {
                // Notify parent that slide is loaded
                window.parent.postMessage({ type: "slide-loaded", slide: ' . $page . ' }, "*");
            }
        </script>';
    } else {
        echo '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#f9f9f9;">
                <div style="text-align:center;">
                    <i class="fas fa-file-alt fa-4x" style="color:#ccc;"></i>
                    <h3 style="color:#999;margin-top:20px;">Slide ' . $page . '</h3>
                    <p style="color:#aaa;">Template file not found</p>
                </div>
              </div>';
    }
} else {
    echo '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#f9f9f9;">
            <div style="text-align:center;">
                <i class="fas fa-exclamation-triangle fa-4x" style="color:#ff9800;"></i>
                <h3 style="color:#666;margin-top:20px;">Slide ' . $page . ' Not Configured</h3>
                <p style="color:#888;">This slide is not available in the system.</p>
            </div>
          </div>';
}
?>
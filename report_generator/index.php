<?php
$SLIDE_REGISTRY = [
    1 => [
        'title' => 'Portfolio Review',
        'template' => 'page1.php',
        'preview' => 'Client overview and portfolio summary'
    ],
    2 => [
        'title' => 'Portfolio Performance',
        'template' => 'page2.php',
        'preview' => 'Performance over time'
    ],
    3 => [
        'title' => 'Portfolio Allocation',
        'template' => 'page3.php',
        'preview' => 'Asset allocation and risk profile'
    ],
    4 => [
        'title' => 'Portfolio Analysis',
        'template' => 'page4.php',
        'preview' => 'Detailed analysis and insights'
    ],
    5 => [
        'title' => 'Portfolio at a Glance',
        'template' => 'page5.php',
        'preview' => 'Current portfolio value and goal report',
        'dynamic' => true // <-- Make slide 5 always editable/updatable
    ],
];
?>
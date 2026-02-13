<?php
// advanced_features.php
// This file contains helper functions for advanced PowerPoint features

function generateChartData($type = 'bar', $labels = [], $datasets = []) {
    $chartData = [
        'type' => $type,
        'data' => [
            'labels' => $labels,
            'datasets' => $datasets
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                ]
            ]
        ]
    ];
    
    return json_encode($chartData, JSON_PRETTY_PRINT);
}

function getSmartArtLayouts() {
    return [
        'basic_process' => [
            'name' => 'Basic Process',
            'layout' => 'vertical',
            'shapes' => 3
        ],
        'hierarchy' => [
            'name' => 'Hierarchy',
            'layout' => 'tree',
            'shapes' => 4
        ],
        'cycle' => [
            'name' => 'Cycle',
            'layout' => 'circular',
            'shapes' => 5
        ],
        'pyramid' => [
            'name' => 'Pyramid',
            'layout' => 'triangle',
            'shapes' => 4
        ],
        'matrix' => [
            'name' => 'Matrix',
            'layout' => 'grid',
            'shapes' => 4
        ]
    ];
}

function getSlideThemes() {
    return [
        'blue' => [
            'primary' => '#2e75b6',
            'secondary' => '#4a9eff',
            'accent' => '#10b981'
        ],
        'green' => [
            'primary' => '#10b981',
            'secondary' => '#34d399',
            'accent' => '#8b5cf6'
        ],
        'purple' => [
            'primary' => '#8b5cf6',
            'secondary' => '#a78bfa',
            'accent' => '#f59e0b'
        ],
        'orange' => [
            'primary' => '#f59e0b',
            'secondary' => '#fbbf24',
            'accent' => '#2e75b6'
        ],
        'dark' => [
            'primary' => '#1f2937',
            'secondary' => '#374151',
            'accent' => '#10b981'
        ]
    ];
}

function getAnimationEffects() {
    return [
        'entrance' => [
            'fade' => 'Fade In',
            'fly_in' => 'Fly In',
            'zoom' => 'Zoom In',
            'bounce' => 'Bounce',
            'flip' => 'Flip',
            'rotate' => 'Rotate'
        ],
        'emphasis' => [
            'pulse' => 'Pulse',
            'shake' => 'Shake',
            'wave' => 'Wave',
            'spin' => 'Spin'
        ],
        'exit' => [
            'fade_out' => 'Fade Out',
            'fly_out' => 'Fly Out',
            'zoom_out' => 'Zoom Out',
            'collapse' => 'Collapse'
        ]
    ];
}

function getTransitionEffects() {
    return [
        'none' => 'None',
        'fade' => 'Fade',
        'slide' => 'Slide',
        'push' => 'Push',
        'wipe' => 'Wipe',
        'split' => 'Split',
        'zoom' => 'Zoom',
        'flip' => 'Flip'
    ];
}
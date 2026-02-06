<?php
// report_generator/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

$SLIDE_REGISTRY = [
    1 => [
        'title' => 'Portfolio Review',
        'template' => 'page1.php',
        'preview' => 'Client overview and portfolio summary'
    ],
    2 => [
        'title' => 'Our Recommendations',
        'template' => 'page2.php',
        'preview' => 'Redeem & replace investment recommendations',
        'dynamic' => true
    ],
    3 => [
        'title' => 'Impact of our recommendations',
        'template' => 'page3.php',
        'preview' => 'Portfolio and Tax impact analysis of recommendations'
    ],
    4 => [
        'title' => 'Rationale',
        'template' => 'page4.php',
        'preview' => 'rationale behind our recommendations'
    ],
    5 => [
        'title' => 'Portfolio at a Glance',
        'template' => 'page5.php',
        'preview' => 'Current portfolio value and goal report'
    ],
    6 => [
        'title' => 'Investment Journey',
        'template' => 'page6.php',
        'preview' => 'Investment journey graph'
    ],
    7 => [
        'title' => 'Asset Allocation',
        'template' => 'page7.php',
        'preview' => 'Current vs recommended asset allocation'
    ],
    8 => [
        'title' => 'Equity MCAP allocation',
        'template' => 'page8.php',
        'preview' => 'Equity MCAP allocation'
    ],
    11 => [
        'title' => 'Fund Performance & Risk Metrics',
        'template' => 'page11.php',
        'preview' => 'Performance of Funds and its Risks'
    ],
    13 => [
        'title' => 'Strategic Rebalancing',
        'template' => 'page13.php',
        'preview' => 'Strategic rebalancing strategies'
    ],
    14 => [
        'title' => 'Tax-Smart Rebalancing',
        'template' => 'page14.php',
        'preview' => 'Tax efficient rebalancing strategies'
    ],
    16 => [
        'title' => 'Your Support Team',
        'template' => 'page16.php',
        'preview' => 'Meet your support team'
    ],
    21 => [
        'title' => 'Our recommendations this quarter',
        'template' => 'page21.php',
        'preview' => 'Our recommendations this quarter'
    ],
    22 => [
        'title' => 'Rationale',
        'template' => 'page22.php',
        'preview' => 'Rationale'
    ],
    23 => [
        'title' => 'Strategic & Tax-Smart Rebalancing',
        'template' => 'page23.php',
        'preview' => 'Strategic & Tax-Smart Rebalancing'
    ],

    // add up to 24 slides here later
];


// Get client_id from URL parameter or from referrer
$client_id = isset($_GET['client_id']) ? $_GET['client_id'] : '';

// If no client_id in URL, try to get it from session or referrer
if (empty($client_id)) {
    // Check if there's a referrer from view_report.php
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referrer, 'view_report.php') !== false) {
        // Parse the client ID from the referrer URL
        $url_parts = parse_url($referrer);
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $query_params);
            if (isset($query_params['id'])) {
                $client_id = 'CLIENT_' . $query_params['id'];
            }
        }
    }

    // Fallback: Get from session or default
    if (empty($client_id)) {
        $client_id = isset($_SESSION['current_client_id']) ? $_SESSION['current_client_id'] : 'CLIENT_1';
    }
}

// Store client_id in session for future use
$_SESSION['current_client_id'] = $client_id;

$current_page = isset($_GET['page']) ? max(1, min(24, intval($_GET['page']))) : 1;

// Get pages and client info using functions from database.php


// ===============================
// INITIALIZE STATIC TEMPLATE SLIDES
// ===============================


function storeTemplateSlideIfMissing($client_id, $page, $config)
{
    // ❌ Skip DB storage for dynamic slides
    if (!empty($config['dynamic'])) {
        return;
    }

    $pages = getClientPages($client_id);

    if (!isset($pages[$page])) {
        ob_start();
        include __DIR__ . "/slides/{$config['template']}";
        $html = ob_get_clean();

        savePageToDatabase(
            $client_id,
            $page,
            $html,
            $config['title'],
            $config['preview']
        );
    }
}

foreach ($SLIDE_REGISTRY as $page => $config) {
    storeTemplateSlideIfMissing($client_id, $page, $config);
}


$pages = getClientPages($client_id); // reload




$clientInfo = getClientInfo($client_id);

// Get client name from database - fetch from clients table instead
$client_name = 'Client';
$actual_client_id = 0;
$client_details = null;

if (strpos($client_id, 'CLIENT_') === 0) {
    $client_number = str_replace('CLIENT_', '', $client_id);
    if (is_numeric($client_number)) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT name, id FROM clients WHERE id = ?");
        $stmt->execute([$client_number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $client_name = $row['name'];
            $actual_client_id = (int)$row['id'];
            $clientInfo['client_name'] = $client_name;
        }
    }
}

// Provide safe defaults
$risk_profile = isset($clientInfo['risk_profile']) && $clientInfo['risk_profile'] !== null ? $clientInfo['risk_profile'] : '';
$investment_horizon = isset($clientInfo['investment_horizon']) && $clientInfo['investment_horizon'] !== null ? $clientInfo['investment_horizon'] : '';
$portfolio_value = isset($clientInfo['portfolio_value']) && $clientInfo['portfolio_value'] !== null ? $clientInfo['portfolio_value'] : '';

// Check if we have slides for this client, if not create first slide

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Slides - <?php echo htmlspecialchars($client_name); ?></title>
    <!-- Use absolute path for CSS to avoid path issues -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../public/css/navbar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>
    <style>
        /* Additional styles for better client display */
        .client-name-display {
            font-size: 22px;
            font-weight: 600;
            color: #2E75B6;
            margin-bottom: 5px;
        }

        .client-id-display {
            font-size: 12px;
            color: #666;
            background: #f5f5f5;
            padding: 3px 8px;
            border-radius: 10px;
            display: inline-block;
            margin-bottom: 10px;
        }

        .back-to-report {
            display: inline-block;
            background: #2E75B6;
            color: white;
            padding: 5px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            margin-top: 10px;
        }

        .back-to-report:hover {
            background: #1e5a96;
        }
    </style>
</head>

<body>
    <?php include '../navbar.php'; ?>

    <div class="powerpoint-container">
        <!-- LEFT: PowerPoint style slide thumbnails -->
        <div class="powerpoint-sidebar">
            <div class="sidebar-header">
                <div class="client-name-display"><?php echo htmlspecialchars($client_name); ?></div>
                <?php if ($actual_client_id): ?>
                    <div class="client-id-display">Client ID: <?php echo $actual_client_id; ?></div>
                <?php endif; ?>
                <div class="client-subtitle-sidebar">Portfolio Review - <?php echo date('F Y'); ?></div>
                <div class="client-meta-mini">
                    <?php if ($risk_profile): ?>
                        <div class="meta-mini-item">
                            <i class="fas fa-chart-line fa-xs"></i> <?php echo htmlspecialchars($risk_profile); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($investment_horizon): ?>
                        <div class="meta-mini-item">
                            <i class="fas fa-calendar-alt fa-xs"></i> <?php echo htmlspecialchars($investment_horizon); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($actual_client_id > 0): ?>
                    <a href="../view_report.php?id=<?php echo $actual_client_id; ?>" class="back-to-report">
                        <i class="fas fa-arrow-left"></i> Back to Report
                    </a>
                <?php endif; ?>
            </div>

            <div class="slide-thumbnails-container" id="slideThumbnails">
                <?php for ($i = 1; $i <= 24; $i++): ?>
                    <div class="slide-thumbnail <?php if ($i == $current_page) echo 'active'; ?>"
                        onclick="window.location.href='?client_id=<?php echo urlencode($client_id); ?>&page=<?php echo $i; ?>'">
                        <div class="slide-number"><?php echo $i; ?></div>
                        <div class="slide-preview-content">
                            <div class="slide-preview-title">
                                <?php
                                if (isset($staticSlides[$i])) {
                                    echo $staticSlides[$i];
                                } elseif (isset($pages[$i]) && !empty($pages[$i]['title'])) {
                                    echo htmlspecialchars($pages[$i]['title']);
                                } else {
                                    echo "Slide " . $i;
                                }

                                ?>
                            </div>
                            <div class="slide-preview-text">
                                <?php
                                if ($i == 2) {
                                    // Slide 2 is data-driven, not HTML-driven
                                    echo "Redeem & Replace investment recommendations";
                                } elseif ($i == 3) {
                                    echo "Portfolio & Tax impact of recommendations";
                                } elseif ($i == 4) {
                                    echo "Rationale behind our recommendations";
                                }elseif ($i == 6) {
                                    echo "investment journey graph";
                                }elseif ($i == 8) {
                                     echo "Equity MCAP allocation";
                                }elseif ($i == 11) {
                                    echo "Performance & Risk Metrics";
                                }elseif ($i == 13) {
                                    echo "Strategic Rebalancing";
                                }elseif ($i == 14) {
                                    echo "Tax -Smart Rebalancing";
                                }elseif ($i == 16) {
                                    echo "Your Support Team";
                                }elseif ($i == 21) {
                                    echo "Our Recommendations This Quater ";}
                                elseif ($i == 22) {
                                    echo "Rationale";
                                }elseif ($i == 23) {
                                    echo "Strategic & Tax -Smart Rebalancing";
                                }

                                elseif (isset($pages[$i]) && !empty(trim($pages[$i]['content']))) {
                                    if (!empty($pages[$i]['preview_text'])) {
                                        echo htmlspecialchars($pages[$i]['preview_text']);
                                    } else {
                                        echo 'No preview available';
                                    }
                                } elseif (isset($staticSlides[$i])) {
                                    echo "Template slide";
                                } else {
                                    echo "No content";
                                }


                                ?>
                            </div>
                        </div>
                        <?php if (isset($pages[$i])): ?>
                            <div class="slide-status" title="Saved">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- CENTER: Main slide area with iframe -->
        <div class="powerpoint-main">
            <!-- PowerPoint Style Toolbar -->
            <!-- Add this button to your toolbar groups -->
            <div class="ppt-toolbar-group">
                <button class="ppt-toolbar-btn btn-success" onclick="downloadPPT()">
                    <i class="fas fa-file-powerpoint"></i> Export PPT
                </button>
                <!-- Add this new button -->
                <!-- In your toolbar section, replace the PDF button with: -->
                <button class="ppt-toolbar-btn btn-primary" onclick="downloadPDF()">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button class="ppt-toolbar-btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="ppt-toolbar-btn" onclick="editCurrentSlide()">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="ppt-toolbar-btn btn-success" onclick="saveCurrentSlide()">
                    <i class="fas fa-save"></i> Save
                </button>


            </div>

            <div id="slide7Warning"
                style="
        display:none;
        background:#fff3cd;
        color:#856404;
        border-bottom:1px solid #ffeeba;
        padding:10px 16px;
        font-size:14px;
        font-weight:500;
     ">
                ⚠ Recommended allocation must total <b>100%</b> to be saved
            </div>

            <div class="ppt-slide-area">
                <div class="slide-frame">



                    <?php if (
                        $current_page === 1 ||
                        $current_page === 2 ||
                        $current_page === 3 ||
                        isset($pages[$current_page])
                    ): ?>
                        <iframe
                            id="slideIframe"
                            src="render_slide.php?client_id=<?php echo urlencode($client_id); ?>&page=<?php echo $current_page; ?>"
                            style="width:100%;height:100%;border:none;background:white;"
                            title="Slide <?php echo $current_page; ?>">
                        </iframe>
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column; background: #f9f9f9;">
                            <i class="fas fa-file-alt fa-4x" style="color: #ccc; margin-bottom: 20px;"></i>
                            <h3 style="color: #999;">No slide content yet</h3>
                            <p style="color: #aaa; margin-top: 10px;">Click Edit to create this slide</p>
                            <button class="btn btn-primary" onclick="toggleEditMode()" style="margin-top: 20px;">
                                <i class="fas fa-edit"></i> Edit Slide
                            </button>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- PowerPoint Style Status Bar -->

        </div>
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2e75b6;"></i>
            <p id="loadingMessage" style="margin-top: 15px;">Processing...</p>
        </div>
    </div>

    <!-- Client Info Modal -->
    <div id="clientInfoModal" class="modal-overlay" style="display: none;">
        <div class="modal-box" style="max-width: 500px;">
            <h3 style="margin-top: 0; color: #2e75b6;">Edit Client Information</h3>
            <div class="property-item">
                <label class="property-label">Client Name</label>
                <input type="text" class="property-input" id="modalClientName" value="<?php echo htmlspecialchars($client_name); ?>">
            </div>
            <div class="property-item">
                <label class="property-label">Risk Profile</label>
                <select class="property-input" id="modalRiskProfile">
                    <option value="Conservative" <?php echo $risk_profile == 'Conservative' ? 'selected' : ''; ?>>Conservative</option>
                    <option value="Moderate" <?php echo $risk_profile == 'Moderate' || empty($risk_profile) ? 'selected' : ''; ?>>Moderate</option>
                    <option value="Aggressive" <?php echo $risk_profile == 'Aggressive' ? 'selected' : ''; ?>>Aggressive</option>
                </select>
            </div>
            <div class="property-item">
                <label class="property-label">Investment Horizon</label>
                <select class="property-input" id="modalInvestmentHorizon">
                    <option value="Short-term (1-3 years)" <?php echo $investment_horizon == 'Short-term (1-3 years)' ? 'selected' : ''; ?>>Short-term (1-3 years)</option>
                    <option value="Medium-term (3-7 years)" <?php echo $investment_horizon == 'Medium-term (3-7 years)' ? 'selected' : ''; ?>>Medium-term (3-7 years)</option>
                    <option value="Long-term (7+ years)" <?php echo $investment_horizon == 'Long-term (7+ years)' || empty($investment_horizon) ? 'selected' : ''; ?>>Long-term (7+ years)</option>
                </select>
            </div>
            <div class="modal-buttons">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeClientInfoModal()">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" onclick="saveClientInfo()">Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        // --- POWERPOINT STYLE EDITOR LOGIC ---
        let editMode = false;
        let editor = null;
        let currentSlide = <?php echo $current_page; ?>;
        const CLIENT_ID = '<?php echo addslashes($client_id); ?>';
        const CLIENT_NAME = '<?php echo addslashes($client_name); ?>';
        const ACTUAL_CLIENT_ID = <?php echo $actual_client_id; ?>;
        let activeImageId = null;
        let messageCounter = 0;

        // Update current time in status bar

        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.getElementById('propertiesTab').style.display = 'none';
            document.getElementById('notesTab').style.display = 'none';
            document.getElementById('messagesTab').style.display = 'none';

            // Remove active class from all tabs
            document.querySelectorAll('.properties-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + 'Tab').style.display = 'block';

            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Navigation functions
        function goToSlide(slideNumber) {
            if (slideNumber >= 1 && slideNumber <= 24) {
                window.location.href = '?client_id=' + encodeURIComponent(CLIENT_ID) + '&page=' + slideNumber;
            }
        }

        function prevSlide() {
            if (currentSlide > 1) {
                goToSlide(currentSlide - 1);
            }
        }

        function nextSlide() {
            if (currentSlide < 24) {
                goToSlide(currentSlide + 1);
            }
        }

        // Edit mode toggle
        function toggleEditMode() {
            const hasContent = <?php echo isset($pages[$current_page]) ? 'true' : 'false'; ?>;

            if (!hasContent) {
                // Create new slide
                createNewSlide();
                return;
            }

            editMode = !editMode;
            const editToggleBtn = document.getElementById('editToggleBtn');
            const saveBtn = document.getElementById('saveBtn');
            const toolbar = document.getElementById('toolbar');

            if (editMode) {
                // Enter edit mode
                editToggleBtn.innerHTML = '<i class="fas fa-times"></i> Exit Edit';
                editToggleBtn.classList.add('active');
                saveBtn.disabled = false;
                toolbar.style.display = 'flex';

                showMessage('Edit Mode Enabled', 'You can now edit the slide content.', 'success');
            } else {
                // Exit edit mode
                editToggleBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
                editToggleBtn.classList.remove('active');
                saveBtn.disabled = true;
                toolbar.style.display = 'none';

                showMessage('Edit Mode Disabled', 'Slide editing is now disabled.', 'info');
            }
        }

        let slide7IsValid = true;
        let slide7Valid = true;

        window.addEventListener('message', function(event) {
            if (!event.data || event.data.type !== 'slide-validation') return;

            if (event.data.slide !== 7) return;

            slide7Valid = event.data.valid;
            updateSlide7Warning();
        });

        function updateSlide7Warning() {
            const bar = document.getElementById('slide7Warning');
            if (!bar) return;

            // show ONLY when slide 7 active AND invalid
            if (currentSlide === 7 && slide7Valid === false) {
                bar.style.display = 'block';
            } else {
                bar.style.display = 'none';
            }
        }

        // run once on page load
        document.addEventListener('DOMContentLoaded', updateSlide7Warning);



        document.addEventListener('DOMContentLoaded', () => {
            updateSlideWarning();
        });

        function updateSlideWarning() {
            const bar = document.getElementById('slideWarningBar');
            if (!bar) return;

            const activeSlide = <?= (int)$current_page ?>;

            // Show warning ONLY when slide 7 is active AND invalid
            if (activeSlide === 7 && slide7IsValid === false) {
                bar.style.display = 'block';
            } else {
                bar.style.display = 'none';
            }
        }

        // Save slide
        function saveSlide() {
            showLoading('Saving slide...');

            const title = document.getElementById('slideTitle').value;
            const bgColor = document.getElementById('slideBgColor').value;
            const notes = document.getElementById('slideNotes').value;

            fetch('save_page.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        page_number: currentSlide,
                        title: title,
                        bg_color: bgColor,
                        notes: notes
                    })
                })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showMessage('Slide Saved', 'Slide saved successfully!', 'success');
                        document.getElementById('saveBtn').disabled = true;
                        document.getElementById('saveStatus').textContent = 'Saved';
                        document.getElementById('saveStatus').style.color = '#10b981';

                        // Update thumbnail status
                        const currentThumb = document.querySelector(`.slide-thumbnail:nth-child(${currentSlide})`);
                        if (currentThumb) {
                            let statusIcon = currentThumb.querySelector('.slide-status');
                            if (!statusIcon) {
                                statusIcon = document.createElement('div');
                                statusIcon.className = 'slide-status';
                                statusIcon.title = 'Saved';
                                statusIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                                currentThumb.appendChild(statusIcon);
                            }

                            // Update title in thumbnail
                            const titleEl = currentThumb.querySelector('.slide-preview-title');
                            if (titleEl) {
                                titleEl.textContent = title;
                            }
                        }

                        // Refresh iframe
                        document.getElementById('slideIframe').src =
                            'render_slide.php?client_id=' + encodeURIComponent(CLIENT_ID) +
                            '&page=' + currentSlide + '&t=' + new Date().getTime();
                    } else {
                        showMessage('Save Failed', data.error || 'Failed to save slide.', 'error');
                    }
                })
                .catch(err => {
                    hideLoading();
                    showMessage('Error', 'Error saving slide: ' + err.message, 'error');
                });
        }

        // Save notes
        function saveNotes() {
            const notes = document.getElementById('slideNotes').value;

            fetch('save_page.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        page_number: currentSlide,
                        notes: notes
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showMessage('Notes Saved', 'Notes saved successfully!', 'success');
                    } else {
                        showMessage('Save Failed', data.error || 'Failed to save notes.', 'error');
                    }
                });
        }

        // Create new slide
        function createNewSlide() {
            showLoading('Creating new slide...');

            const title = document.getElementById('slideTitle').value || 'Slide ' + currentSlide;

            fetch('save_page.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        page_number: currentSlide,
                        title: title,
                        content: '<div class="slide-content"><h1>' + title + '</h1><p>Start editing this slide...</p></div>',
                        bg_color: '#ffffff'
                    })
                })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showMessage('Slide Created', 'New slide created successfully!', 'success');
                        // Reload the page to show the new slide
                        window.location.href = '?client_id=' + encodeURIComponent(CLIENT_ID) + '&page=' + currentSlide;
                    } else {
                        showMessage('Creation Failed', data.error || 'Failed to create slide.', 'error');
                    }
                });
        }

        // Duplicate current slide
        function duplicateCurrentSlide() {
            if (currentSlide >= 24) {
                showMessage('Error', 'Cannot duplicate - maximum slides reached.', 'error');
                return;
            }

            showLoading('Duplicating slide...');

            fetch('duplicate_slide.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        source_page: currentSlide,
                        target_page: currentSlide + 1
                    })
                })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showMessage('Slide Duplicated', 'Slide duplicated successfully!', 'success');
                        // Go to the duplicated slide
                        window.location.href = '?client_id=' + encodeURIComponent(CLIENT_ID) + '&page=' + (currentSlide + 1);
                    } else {
                        showMessage('Duplication Failed', data.error || 'Failed to duplicate slide.', 'error');
                    }
                });
        }

        // Export as PDF
        // Export as PDF
        // Export as PDF - FIXED WORKING VERSION
        function downloadPDF() {
            console.log('Starting PDF download for client:', CLIENT_ID);

            // Check if loading overlay exists before using it
            const loadingOverlay = document.getElementById('loadingOverlay');
            const loadingMessage = document.getElementById('loadingMessage');

            if (loadingOverlay && loadingMessage) {
                loadingMessage.textContent = 'Generating PDF document...';
                loadingOverlay.style.display = 'flex';
            }

            // Create a hidden iframe for PDF download (most reliable method)
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.position = 'absolute';

            // Add timestamp to prevent caching
            const timestamp = new Date().getTime();
            iframe.src = 'generate_pdf.php?client_id=' + encodeURIComponent(CLIENT_ID) + '&t=' + timestamp;

            document.body.appendChild(iframe);

            console.log('PDF generation iframe created');

            // Hide loading after 3 seconds (even if download hasn't finished)
            setTimeout(() => {
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }

                // Show a simple alert
                alert('PDF is being generated. It should start downloading automatically.\n\nIf download doesn\'t start, please:\n1. Check your browser downloads\n2. Allow pop-ups for this site\n3. Try again');

                // Remove iframe after 10 seconds
                setTimeout(() => {
                    if (iframe && iframe.parentNode) {
                        iframe.parentNode.removeChild(iframe);
                    }
                }, 10000);
            }, 3000);

            // Alternative: Also open in new tab as backup
            setTimeout(() => {
                window.open('generate_pdf.php?client_id=' + encodeURIComponent(CLIENT_ID), '_blank');
            }, 1000);
        }

        function deleteCurrentSlide() {
            if (!confirm('Are you sure you want to delete this slide? This action cannot be undone.')) {
                return;
            }

            showLoading('Deleting slide...');

            fetch('delete_slide.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        page_number: currentSlide
                    })
                })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showMessage('Slide Deleted', 'Slide deleted successfully!', 'success');
                        // Go to previous slide or first slide
                        const newPage = currentSlide > 1 ? currentSlide - 1 : 1;
                        window.location.href = '?client_id=' + encodeURIComponent(CLIENT_ID) + '&page=' + newPage;
                    } else {
                        showMessage('Deletion Failed', data.error || 'Failed to delete slide.', 'error');
                    }
                });
        }

        // Insert image
        function insertImage() {
            showMessage('Coming Soon', 'Image insertion feature will be available in the next update.', 'info');
        }

        // Insert table
        function insertTable() {
            showMessage('Coming Soon', 'Table insertion feature will be available in the next update.', 'info');
        }

        // Insert chart
        function insertChart() {
            showMessage('Coming Soon', 'Chart insertion feature will be available soon.', 'info');
        }

        // Text formatting functions
        function formatText(cmd) {
            showMessage('Coming Soon', 'Text formatting will be available in edit mode.', 'info');
        }

        function formatHeading(val) {
            showMessage('Coming Soon', 'Heading formatting will be available in edit mode.', 'info');
        }

        function changeColor(color) {
            showMessage('Coming Soon', 'Color formatting will be available in edit mode.', 'info');
        }

        // Preview slide
        function previewSlide() {
            window.open('render_slide.php?client_id=' + encodeURIComponent(CLIENT_ID) +
                '&page=' + currentSlide + '&preview=1', '_blank');
        }

        // Show client info modal
        function showClientInfo() {
            document.getElementById('clientInfoModal').style.display = 'flex';
        }

        function closeClientInfoModal() {
            document.getElementById('clientInfoModal').style.display = 'none';
        }

        function saveClientInfo() {
            const clientName = document.getElementById('modalClientName').value;
            const riskProfile = document.getElementById('modalRiskProfile').value;
            const investmentHorizon = document.getElementById('modalInvestmentHorizon').value;

            showLoading('Saving client information...');

            fetch('save_client_info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        client_name: clientName,
                        risk_profile: riskProfile,
                        investment_horizon: investmentHorizon
                    })
                })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showMessage('Client Info Saved', 'Client information updated successfully!', 'success');
                        closeClientInfoModal();
                        // Update display
                        document.querySelector('.client-name-display').textContent = clientName;
                        // Reload page to reflect changes
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showMessage('Save Failed', data.error || 'Failed to save client information.', 'error');
                    }
                });
        }

        // Update slide title
        function updateSlideTitle(title) {
            document.querySelector('.slide-header h1').textContent = title;
            document.getElementById('saveBtn').disabled = false;
        }

        // Message system
        function showMessage(title, content, type = 'info') {
            const messagesPanel = document.getElementById('messagesPanel');
            if (!messagesPanel) {
                console.warn('messagesPanel not found:', title, content);
                return;
            }

            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            messageDiv.innerHTML = `
        <strong>${title}</strong><br>${content}
    `;
            messagesPanel.prepend(messageDiv);
        }

        function clearMessages() {
            const messagesPanel = document.getElementById('messagesPanel');
            messagesPanel.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #999;">
                <i class="fas fa-comments fa-2x"></i>
                <p style="margin-top: 10px;">No messages yet</p>
            </div>
        `;
        }

        // Loading overlay
        function showLoading(msg = "Processing...") {
            document.getElementById('loadingMessage').textContent = msg;
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Export functions
        function downloadPPT() {
            showLoading('Generating PowerPoint presentation...');
            showMessage('Export Started', 'PowerPoint generation in progress...', 'info');

            // Simulate processing
            setTimeout(() => {
                hideLoading();
                showMessage('Export Complete', 'PowerPoint file has been generated successfully!', 'success');
                window.open('generate_ppt.php?client_id=' + encodeURIComponent(CLIENT_ID), '_blank');
            }, 2000);
        }


        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Welcome message
            showMessage('Welcome', `Editing ${CLIENT_NAME}'s portfolio slides. Slide ${currentSlide} of 24 loaded.`, 'info');

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Left arrow - previous slide
                if (e.key === 'ArrowLeft' && !e.ctrlKey && !e.altKey && !e.shiftKey) {
                    prevSlide();
                    e.preventDefault();
                }
                // Right arrow - next slide
                if (e.key === 'ArrowRight' && !e.ctrlKey && !e.altKey && !e.shiftKey) {
                    nextSlide();
                    e.preventDefault();
                }
                // Ctrl+S - save
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    if (!document.getElementById('saveBtn').disabled) {
                        saveSlide();
                    }
                }
            });
        });

        function getSlideIframe() {
            return document.getElementById('slideIframe');
        }

        function editCurrentSlide() {
            const iframe = getSlideIframe();
            if (iframe && iframe.contentWindow.enableEdit) {
                iframe.contentWindow.enableEdit();
            } else {
                alert('This slide is not editable');
            }
        }

        function saveCurrentSlide() {
            const iframe = getSlideIframe();
            if (iframe && iframe.contentWindow.saveSlide) {
                iframe.contentWindow.saveSlide();
            } else {
                alert('Nothing to save on this slide');
            }
        }
    </script>
</body>

</html>
<?php
// report_generator/index.php
$current_page = isset($_GET['page']) ? max(1, min(23, intval($_GET['page']))) : 1;

// Check if all page files exist
$missing_pages = [];
for ($i = 1; $i <= 23; $i++) {
    if (!file_exists("page{$i}.php")) {
        $missing_pages[] = $i;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quarterly Portfolio Review - Jan-Mar 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            display: <?php echo !empty($missing_pages) ? 'block' : 'none'; ?>;
        }
        
        .system-status {
            position: fixed;
            top: 10px;
            right: 10px;
            background: white;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
            font-size: 12px;
        }
        
        .status-good { color: #28a745; }
        .status-bad { color: #dc3545; }
    </style>
</head>

<body>
    <!-- System Status -->
    <div class="system-status">
        <i class="fas fa-circle <?php echo empty($missing_pages) ? 'status-good' : 'status-bad'; ?>"></i>
        Pages: <?php echo (23 - count($missing_pages)) . '/23'; ?>
        <?php if (!empty($missing_pages)): ?>
            <br><small>Missing: <?php echo implode(', ', $missing_pages); ?></small>
        <?php endif; ?>
    </div>
    
    <nav class="top-nav">
        <a href="../upload.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </nav>
    
    <div class="container">
        <!-- Alert for missing pages -->
        <?php if (!empty($missing_pages)): ?>
        <div class="alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Warning:</strong> Missing pages: <?php echo implode(', ', $missing_pages); ?>
            <br><small>Create page1.php, page2.php, etc. files for complete PDF</small>
        </div>
        <?php endif; ?>
        
        <!-- Header -->
        <div class="header">
            <div class="client-info">
                <div class="client-avatar">MT</div>
                <div>
                    <h1>Ms. Mukta Dutta Tomar</h1>
                    <p>Quarterly Portfolio Review | Jan - Mar 2026 | 23 Pages</p>
                </div>
            </div>
            
            <div class="controls">
                <button class="btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Current
                </button>
                <button class="btn btn-pdf" onclick="downloadFullPDF()">
                    <i class="fas fa-file-pdf"></i> Full PDF (23 Pages)
                </button>
                <button class="btn btn-secondary" onclick="downloadCurrentPDF()">
                    <i class="fas fa-file-pdf"></i> Current Page
                </button>
                <button class="btn btn-ppt" onclick="downloadPPT()">
                    <i class="fas fa-file-powerpoint"></i> Download PPT
                </button>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="navigation">
            <button class="nav-btn" onclick="prevPage()" <?php if($current_page == 1) echo 'disabled'; ?>>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
            
            <div class="page-indicator">
                Page <span id="currentPage"><?php echo $current_page; ?></span> of 23
            </div>
            
            <button class="nav-btn" onclick="nextPage()" <?php if($current_page == 23) echo 'disabled'; ?>>
                Next <i class="fas fa-chevron-right"></i>
            </button>
            
            <select class="page-selector" onchange="goToPage(this.value)">
                <?php for($i = 1; $i <= 23; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php if($i == $current_page) echo 'selected'; ?>>
                        Page <?php echo $i; ?> 
                        <?php if (in_array($i, $missing_pages)) echo '⚠️'; ?>
                    </option>
                <?php endfor; ?>
            </select>
            
            <button class="nav-btn btn-info" onclick="showHelp()">
                <i class="fas fa-question-circle"></i> Help
            </button>
        </div>
        
        <!-- Page Content -->
        <div class="page-container">
            <div class="page-content">
                <?php 
                if (file_exists("page{$current_page}.php")) {
                    include "page{$current_page}.php";
                } else {
                    echo '<div class="content-box">';
                    echo '<h3>Page ' . $current_page . '</h3>';
                    echo '<p>This page file is missing. Please create <strong>page' . $current_page . '.php</strong></p>';
                    echo '<p>For now, you can:</p>';
                    echo '<ol>';
                    echo '<li><a href="javascript:void(0)" onclick="createTemplate(' . $current_page . ')">Create template for this page</a></li>';
                    echo '<li><a href="?page=' . ($current_page - 1) . '">Go to previous page</a></li>';
                    echo '<li><a href="?page=' . ($current_page + 1) . '">Go to next page</a></li>';
                    echo '</ol>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer-nav">
            <div class="page-thumbnails">
                <?php for($i = 1; $i <= 23; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="thumbnail <?php if($i == $current_page) echo 'active'; ?> <?php if (in_array($i, $missing_pages)) echo 'missing'; ?>">
                        <?php echo $i; ?>
                        <?php if (in_array($i, $missing_pages)) echo '⚠️'; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    
    <!-- Help Modal -->
    <div id="helpModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeHelp()">&times;</span>
            <h2><i class="fas fa-question-circle"></i> Help & Instructions</h2>
            
            <div class="help-section">
                <h3>📄 PDF Generation</h3>
                <p><strong>Full PDF (23 Pages):</strong></p>
                <ol>
                    <li>Click "Full PDF" button</li>
                    <li>Wait for new window/tab to open</li>
                    <li>Click "Generate PDF" in that window</li>
                    <li>In print dialog, choose "Save as PDF"</li>
                    <li>Save the file</li>
                </ol>
                
                <p><strong>Troubleshooting:</strong></p>
                <ul>
                    <li>If popup blocked: Allow popups for this site</li>
                    <li>If pages missing: Check all page files exist (page1.php to page23.php)</li>
                    <li>If content cut off: Set margins to "None" in print dialog</li>
                </ul>
            </div>
            
            <div class="help-section">
                <h3>🔄 Page Navigation</h3>
                <ul>
                    <li><kbd>←</kbd> <kbd>→</kbd> Arrow keys to navigate</li>
                    <li><kbd>Home</kbd> Go to first page</li>
                    <li><kbd>End</kbd> Go to last page</li>
                    <li>Click page numbers at bottom</li>
                </ul>
            </div>
            
            <div class="help-section">
                <h3>⚡ Keyboard Shortcuts</h3>
                <ul>
                    <li><kbd>Ctrl</kbd> + <kbd>P</kbd> - Print current page</li>
                    <li><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>F</kbd> - Full PDF</li>
                    <li><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>P</kbd> - PowerPoint</li>
                    <li><kbd>Esc</kbd> - Close help/modal</li>
                </ul>
            </div>
            
            <div class="help-section">
                <h3>📊 System Status</h3>
                <p>Pages loaded: <?php echo (23 - count($missing_pages)); ?> of 23</p>
                <?php if (!empty($missing_pages)): ?>
                <p class="warning">⚠️ Missing pages: <?php echo implode(', ', $missing_pages); ?></p>
                <button class="btn" onclick="createAllTemplates()">
                    <i class="fas fa-plus"></i> Create Missing Page Templates
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2E75B6;"></i>
            <p id="loadingMessage" style="margin-top: 20px; font-size: 18px; color: #333;">Processing...</p>
            <div id="progressDetails" style="margin-top: 10px; font-size: 14px; color: #666;"></div>
        </div>
    </div>
    
    <script>
        window.missingPages = <?php echo json_encode($missing_pages ?? []); ?>;
    </script>
    <script src="script.js"></script>
    <script>
        // Help modal functions
        function showHelp() {
            document.getElementById('helpModal').style.display = 'block';
        }
        
        function closeHelp() {
            document.getElementById('helpModal').style.display = 'none';
        }
        
        // Create page template
        function createTemplate(pageNum) {
            fetch('create_page.php?page=' + pageNum)
                .then(response => response.text())
                .then(data => {
                    alert('Template created for page ' + pageNum + '. Refreshing...');
                    setTimeout(() => window.location.reload(), 1000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to create template. Check console for details.');
                });
        }
        
        // Create all missing templates
        function createAllTemplates() {
            const missing = <?php echo json_encode($missing_pages); ?>;
            if (missing.length === 0) return;
            
            showLoading('Creating ' + missing.length + ' page templates...');
            
            missing.forEach((pageNum, index) => {
                setTimeout(() => {
                    fetch('create_page.php?page=' + pageNum)
                        .then(() => {
                            const progress = Math.round((index + 1) / missing.length * 100);
                            document.getElementById('progressDetails').innerHTML = 
                                `Created ${index + 1} of ${missing.length} pages (${progress}%)`;
                            
                            if (index === missing.length - 1) {
                                setTimeout(() => {
                                    hideLoading();
                                    alert('All templates created! Refreshing...');
                                    window.location.reload();
                                }, 1000);
                            }
                        });
                }, index * 500);
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('helpModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
        
        // Check system status on load
        document.addEventListener('DOMContentLoaded', function() {
            const missing = <?php echo json_encode($missing_pages); ?>;
            if (missing.length > 0) {
                console.warn('Missing pages:', missing);
                // Auto-show help if many pages missing
                if (missing.length > 5) {
                    setTimeout(showHelp, 1000);
                }
            }
        });
    </script>
</body>
</html>
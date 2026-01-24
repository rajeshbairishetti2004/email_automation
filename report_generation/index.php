<?php
// report_generator/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

$current_page = isset($_GET['page']) ? max(1, min(23, intval($_GET['page']))) : 1;
$client_id = 'MS_MUKTA_DUTTA';

// Get pages and client info using functions from database.php
$pages = getClientPages($client_id);
$clientInfo = getClientInfo($client_id);

// Provide safe defaults to avoid undefined array key warnings
$client_name = isset($clientInfo['client_name']) && $clientInfo['client_name'] !== null ? $clientInfo['client_name'] : 'Client';
$risk_profile = isset($clientInfo['risk_profile']) && $clientInfo['risk_profile'] !== null ? $clientInfo['risk_profile'] : '';
$investment_horizon = isset($clientInfo['investment_horizon']) && $clientInfo['investment_horizon'] !== null ? $clientInfo['investment_horizon'] : '';
$portfolio_value = isset($clientInfo['portfolio_value']) && $clientInfo['portfolio_value'] !== null ? $clientInfo['portfolio_value'] : '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editable Portfolio Slides - <?php echo htmlspecialchars($client_name); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../public/css/navbar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>
    <!-- CSS moved to style.css -->
</head>
<body>
    <?php include '../navbar.php'; ?>
    <div class="container">
        <!-- Left Sidebar (20%) -->
        <div class="sidebar-main">
            <!-- Client Info Section -->
            <div class="sidebar-client-info">
                <div class="client-avatar"><?php echo htmlspecialchars(substr($client_name, 0, 2)); ?></div>
                <div class="client-details-sidebar">
                    <h2><?php echo htmlspecialchars($client_name); ?></h2>
                    <p class="client-subtitle">Quarterly Portfolio Review | Jan - Mar 2026</p>
                    <div class="client-meta-sidebar">
                        <div class="meta-item-sidebar">
                            <i class="fas fa-chart-line"></i>
                            <span>Risk: <strong><?php echo htmlspecialchars($risk_profile); ?></strong></span>
                        </div>
                        <div class="meta-item-sidebar">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Horizon: <strong><?php echo htmlspecialchars($investment_horizon); ?></strong></span>
                        </div>
                        <?php if ($portfolio_value !== ''): ?>
                        <div class="meta-item-sidebar">
                            <i class="fas fa-rupee-sign"></i>
                            <span>Value: <strong>₹<?php echo number_format($portfolio_value, 2); ?></strong></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Navigation Section -->
            <div class="sidebar-navigation">
                <div class="nav-controls">
                    <button class="nav-btn-sidebar" onclick="prevPage()" <?php if($current_page == 1) echo 'disabled'; ?>>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="page-info-sidebar">
                        <div class="current-page">Slide <?php echo $current_page; ?></div>
                        <div class="total-pages">of 23</div>
                    </div>
                    <button class="nav-btn-sidebar" onclick="nextPage()" <?php if($current_page == 23) echo 'disabled'; ?>>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div class="page-selector-sidebar">
                    <select class="page-selector" onchange="goToPage(this.value)">
                        <?php for($i = 1; $i <= 23; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php if($i == $current_page) echo 'selected'; ?>>
                                Slide <?php echo $i; ?> 
                                <?php if (isset($pages[$i])): ?>
                                    - <?php echo htmlspecialchars(substr($pages[$i]['title'], 0, 15)); ?>
                                <?php endif; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="sidebar-thumbnails">
                    <?php for($i = 1; $i <= 23; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="thumbnail-sidebar <?php if($i == $current_page) echo 'active'; ?>">
                            <span class="thumbnail-number"><?php echo $i; ?></span>
                            <?php if (isset($pages[$i])): ?>
                                <span class="thumbnail-status-sidebar" title="Saved">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Action Buttons - Only export buttons remain -->
            <div class="sidebar-actions">
                <div class="export-buttons">
                    <button class="btn-sidebar btn-export" onclick="downloadPPT()" title="Download PowerPoint">
                        <i class="fas fa-file-powerpoint"></i>
                    </button>
                    <button class="btn-sidebar btn-export" onclick="downloadPDF()" title="Download PDF">
                        <i class="fas fa-file-pdf"></i>
                    </button>
                    <button class="btn-sidebar btn-export" onclick="window.print()" title="Print">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Center Main Content (60%) -->
        <div class="main-content">
            <!-- Toolbar -->
            <div class="toolbar" id="toolbar" style="display: none;">
                <div class="toolbar-left">
                    <button class="toolbar-btn" onclick="insertImage()">
                        <i class="fas fa-image"></i> Insert Image
                    </button>
                    <button class="toolbar-btn" onclick="insertTable()">
                        <i class="fas fa-table"></i> Table
                    </button>
                    <button class="toolbar-btn" onclick="formatText('bold')">
                        <i class="fas fa-bold"></i>
                    </button>
                    <button class="toolbar-btn" onclick="formatText('italic')">
                        <i class="fas fa-italic"></i>
                    </button>
                    <button class="toolbar-btn" onclick="formatText('underline')">
                        <i class="fas fa-underline"></i>
                    </button>
                    <select class="toolbar-select" onchange="formatHeading(this.value)">
                        <option value="">Heading</option>
                        <option value="h1">Heading 1</option>
                        <option value="h2">Heading 2</option>
                        <option value="h3">Heading 3</option>
                    </select>
                    <input type="color" id="colorPicker" onchange="changeColor(this.value)" value="#2E75B6" title="Text Color">
                    <button class="toolbar-btn" onclick="adjustActiveImageSize('increase')" id="increaseSizeBtn" style="display: none;">
                        <i class="fas fa-expand-alt"></i> Enlarge
                    </button>
                    <button class="toolbar-btn" onclick="adjustActiveImageSize('decrease')" id="decreaseSizeBtn" style="display: none;">
                        <i class="fas fa-compress-alt"></i> Shrink
                    </button>
                    <button class="toolbar-btn" onclick="deleteActiveImage()" id="deleteImageBtn" style="display: none; background: #dc3545;">
                        <i class="fas fa-trash"></i> Delete Image
                    </button>
                </div>
                <div class="toolbar-right">
                    <button class="toolbar-btn" onclick="showClientInfo()">
                        <i class="fas fa-user"></i> Client Info
                    </button>
                    <button class="toolbar-btn btn-success" onclick="previewPage()">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                </div>
            </div>
            
            <!-- Page Content -->
            <div class="page-content-container">
                <div class="page-content" id="pageContent">
                    <?php if (isset($pages[$current_page])): ?>
                        <div class="editable-content" id="editableContent" contenteditable="false">
                            <?php 
                            // First, get the content without images
                            $content = htmlspecialchars_decode($pages[$current_page]['content']);
                            // Remove any existing image containers from content
                            $content = preg_replace('/<div class="image-container"[^>]*>.*?<\/div>/s', '', $content);
                            echo $content;
                            ?>
                            
                            <!-- Page Images - These will be positioned absolutely -->
                            <?php if (!empty($pages[$current_page]['images'])): ?>
                                <?php $images = json_decode($pages[$current_page]['images'], true); ?>
                                <?php foreach ($images as $index => $image): 
                                    $position = isset($image['position']) ? json_decode($image['position'], true) : ['top' => ($index * 20 + 20) . 'px', 'left' => ($index * 20 + 20) . 'px'];
                                    $zIndex = isset($image['zIndex']) ? $image['zIndex'] : 100 + $index;
                                    $width = isset($image['saved_width']) ? $image['saved_width'] . 'px' : ($image['width'] ?? '300px');
                                    $height = isset($image['saved_height']) ? $image['saved_height'] . 'px' : ($image['height'] ?? 'auto');
                                ?>
                                    <div class="image-container" 
                                         data-image-id="<?php echo $image['id']; ?>"
                                         style="position: absolute; 
                                                top: <?php echo $position['top'] ?? '20px'; ?>; 
                                                left: <?php echo $position['left'] ?? '20px'; ?>; 
                                                z-index: <?php echo $zIndex; ?>;">
                                        <div class="resizable-image" id="image-<?php echo $image['id']; ?>">
                                            <img src="uploads/<?php echo $image['filename']; ?>" 
                                                 alt="<?php echo $image['alt']; ?>"
                                                 style="width: <?php echo $width; ?>; height: <?php echo $height; ?>;">
                                            <div class="resize-handle bottom-right"></div>
                                            <div class="resize-handle bottom-left"></div>
                                            <div class="resize-handle top-right"></div>
                                            <div class="resize-handle top-left"></div>
                                            <button class="delete-btn" onclick="deleteImage(<?php echo $image['id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <div class="image-size-display" id="size-display-<?php echo $image['id']; ?>">
                                                <?php 
                                                $imgWidth = preg_replace('/[^0-9]/', '', $width);
                                                $imgHeight = preg_replace('/[^0-9]/', '', $height);
                                                echo $imgWidth . 'px × ' . ($imgHeight ?: 'auto');
                                                ?>
                                            </div>
                                            <div class="image-controls-bar">
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'width', 10, event)"><i class="fas fa-arrow-right"></i></button>
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'width', -10, event)"><i class="fas fa-arrow-left"></i></button>
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'height', 10, event)"><i class="fas fa-arrow-down"></i></button>
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'height', -10, event)"><i class="fas fa-arrow-up"></i></button>
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'both', 10, event)"><i class="fas fa-expand-alt"></i></button>
                                                <button class="size-btn" onclick="adjustImageSize(<?php echo $image['id']; ?>, 'both', -10, event)"><i class="fas fa-compress-alt"></i></button>
                                                <button class="size-btn" onclick="resetImageSize(<?php echo $image['id']; ?>, event)"><i class="fas fa-undo"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="editable-content" id="editableContent" contenteditable="false">
                            <div class="section-title">Slide <?php echo $current_page; ?></div>
                            <p>Click "Edit Mode" to start editing this slide.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer-main">
                <div class="footer-status">
                    <span id="autoSaveStatus">Ready</span>
                    <button class="btn btn-sm" onclick="showClientInfo()">
                        <i class="fas fa-user"></i> Client Info
                    </button>
                    <button class="btn btn-sm" onclick="showSlideManager()">
                        <i class="fas fa-th"></i> Slide Manager
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Right Messages Panel (20%) -->
        <div class="messages-panel">
            <!-- Edit Mode Buttons at the top -->
            <div class="edit-mode-buttons">
                <button class="btn-edit-panel btn-edit-mode" onclick="toggleEditMode()" id="editToggle">
                    <i class="fas fa-edit"></i> Edit Mode
                </button>
                <button class="btn-edit-panel btn-save-panel" onclick="savePage()" id="saveBtn" disabled>
                    <i class="fas fa-save"></i> Save Slide
                </button>
            </div>
            <!-- Messages & Status Section -->
            <div class="messages-header">
                <h3><i class="fas fa-comment-alt"></i> Messages & Status</h3>
            </div>
            <div class="messages-container" id="messagesContainer">
                <div class="no-messages" id="noMessages">
                    <i class="fas fa-comments"></i>
                    <p>No messages yet.<br>Your status messages will appear here.</p>
                </div>
            </div>
            <button class="clear-messages" onclick="clearAllMessages()">
                <i class="fas fa-trash-alt"></i> Clear All Messages
            </button>
        </div>
        
        <!-- Client Info Sidebar (Hidden by default) -->
        <div class="client-info-sidebar" id="clientInfoSidebar">
            <div class="sidebar-header">
                <h4><i class="fas fa-user"></i> Client Information</h4>
                <button onclick="closeClientInfo()">&times;</button>
            </div>
            <div class="sidebar-content">
                <div class="client-details">
                    <div class="detail-item">
                        <label>Client ID:</label>
                        <span><?php echo $clientInfo['client_id']; ?></span>
                    </div>
                    <div class="detail-item">
                        <label>Full Name:</label>
                        <span><?php echo $clientInfo['client_name']; ?></span>
                    </div>
                    <div class="detail-item">
                        <label>Email:</label>
                        <input type="email" id="clientEmail" value="<?php echo $clientInfo['client_email'] ?? ''; ?>" 
                               class="form-control" placeholder="client@email.com">
                    </div>
                    <div class="detail-item">
                        <label>Phone:</label>
                        <input type="tel" id="clientPhone" value="<?php echo $clientInfo['phone'] ?? ''; ?>" 
                               class="form-control" placeholder="+91 00000 00000">
                    </div>
                    <div class="detail-item">
                        <label>Risk Profile:</label>
                        <select id="clientRisk" class="form-control">
                            <option value="Conservative" <?php echo ($clientInfo['risk_profile'] ?? '') == 'Conservative' ? 'selected' : ''; ?>>Conservative</option>
                            <option value="Moderate" <?php echo ($clientInfo['risk_profile'] ?? '') == 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                            <option value="Moderate to Aggressive" <?php echo ($clientInfo['risk_profile'] ?? '') == 'Moderate to Aggressive' ? 'selected' : ''; ?>>Moderate to Aggressive</option>
                            <option value="Aggressive" <?php echo ($clientInfo['risk_profile'] ?? '') == 'Aggressive' ? 'selected' : ''; ?>>Aggressive</option>
                        </select>
                    </div>
                    <div class="detail-item">
                        <label>Investment Horizon:</label>
                        <select id="clientHorizon" class="form-control">
                            <option value="Short-term (1-3 years)" <?php echo ($clientInfo['investment_horizon'] ?? '') == 'Short-term (1-3 years)' ? 'selected' : ''; ?>>Short-term (1-3 years)</option>
                            <option value="Medium-term (3-7 years)" <?php echo ($clientInfo['investment_horizon'] ?? '') == 'Medium-term (3-7 years)' ? 'selected' : ''; ?>>Medium-term (3-7 years)</option>
                            <option value="Long-term (7+ years)" <?php echo ($clientInfo['investment_horizon'] ?? '') == 'Long-term (7+ years)' ? 'selected' : ''; ?>>Long-term (7+ years)</option>
                            <option value="Long-term (10+ years)" <?php echo ($clientInfo['investment_horizon'] ?? '') == 'Long-term (10+ years)' ? 'selected' : ''; ?>>Long-term (10+ years)</option>
                        </select>
                    </div>
                    <div class="detail-item">
                        <label>Portfolio Value (₹):</label>
                        <input type="number" id="portfolioValue" value="<?php echo $clientInfo['portfolio_value'] ?? ''; ?>" 
                               class="form-control" placeholder="1000000" step="10000">
                    </div>
                </div>
                <button class="btn btn-primary btn-block" onclick="saveClientInfo()">
                    <i class="fas fa-save"></i> Update Client Info
                </button>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <?php include 'modals.php'; ?>
    
    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p id="loadingMessage">Processing...</p>
            <div id="progressDetails"></div>
        </div>
    </div>
    
    <script>
    // --- ADVANCED EDITOR LOGIC ---
    let editMode = false;
    let editor = null;
    let currentPage = <?php echo $current_page; ?>;
    const CLIENT_ID = '<?php echo $client_id; ?>';
    const CLIENT_NAME = '<?php echo addslashes($clientInfo['client_name'] ?? 'Client'); ?>';
    let activeImageId = null;
    let activeImageContainer = null;
    let isResizing = false;
    let isDragging = false;
    let resizeHandle = null;
    let dragOffsetX = 0, dragOffsetY = 0;
    let startX, startY, startWidth, startHeight;
    let messageCounter = 0;
    const MAX_MESSAGES = 50;

    // Message System
    function showMessage(title, content, type = 'info') {
        messageCounter++;
        const messagesContainer = document.getElementById('messagesContainer');
        const noMessages = document.getElementById('noMessages');
        
        if (noMessages) {
            noMessages.style.display = 'none';
        }
        
        const message = document.createElement('div');
        message.className = `message ${type}`;
        message.id = `message-${messageCounter}`;
        
        const now = new Date();
        const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        
        message.innerHTML = `
            <div class="message-title">
                <i class="fas fa-${getMessageIcon(type)}"></i>
                ${title}
            </div>
            <div class="message-content">${content}</div>
            <div class="message-time">${timeString}</div>
        `;
        
        messagesContainer.prepend(message);
        
        // Auto-remove old messages if limit reached
        const allMessages = messagesContainer.querySelectorAll('.message');
        if (allMessages.length > MAX_MESSAGES) {
            for (let i = MAX_MESSAGES; i < allMessages.length; i++) {
                allMessages[i].remove();
            }
        }
        
        // Auto-remove after 30 seconds for non-error messages
        if (type !== 'error') {
            setTimeout(() => {
                const msg = document.getElementById(`message-${messageCounter}`);
                if (msg) {
                    msg.style.opacity = '0';
                    msg.style.transform = 'translateX(20px)';
                    setTimeout(() => msg.remove(), 300);
                }
            }, 30000);
        }
    }
    
    function getMessageIcon(type) {
        switch(type) {
            case 'success': return 'check-circle';
            case 'error': return 'exclamation-circle';
            case 'warning': return 'exclamation-triangle';
            default: return 'info-circle';
        }
    }
    
    function clearAllMessages() {
        const messagesContainer = document.getElementById('messagesContainer');
        const noMessages = document.getElementById('noMessages');
        
        messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
        
        if (noMessages) {
            noMessages.style.display = 'block';
        }
        
        showMessage('Messages Cleared', 'All messages have been cleared.', 'info');
    }

    // Loading overlay
    function showLoading(msg="Processing...") {
        document.getElementById('loadingMessage').textContent = msg;
        document.getElementById('loadingOverlay').style.display = 'flex';
    }
    
    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // Image Management Functions
    function setActiveImage(imageId) {
        // Deactivate all images
        document.querySelectorAll('.image-container').forEach(container => {
            container.classList.remove('active');
        });
        
        // Activate selected image
        activeImageContainer = document.querySelector(`.image-container[data-image-id="${imageId}"]`);
        if (activeImageContainer) {
            activeImageContainer.classList.add('active');
            activeImageId = imageId;
            
            // Bring to front
            const maxZIndex = Math.max(...Array.from(document.querySelectorAll('.image-container'))
                .map(el => parseInt(window.getComputedStyle(el).zIndex) || 100));
            activeImageContainer.style.zIndex = maxZIndex + 1;
            
            // Show image resize buttons in toolbar
            document.getElementById('increaseSizeBtn').style.display = 'inline-flex';
            document.getElementById('decreaseSizeBtn').style.display = 'inline-flex';
            document.getElementById('deleteImageBtn').style.display = 'inline-flex';
            
            showMessage('Image Selected', 'Image selected. Drag to move, use handles to resize, or use keyboard shortcuts.', 'info');
        }
    }

    function updateImageSizeDisplay(imageId) {
        const imageContainer = document.querySelector(`.image-container[data-image-id="${imageId}"]`);
        if (!imageContainer) return;
        
        const img = imageContainer.querySelector('img');
        const display = document.getElementById(`size-display-${imageId}`);
        if (img && display) {
            const width = Math.round(img.offsetWidth);
            const height = Math.round(img.offsetHeight);
            display.textContent = `${width}px × ${height}px`;
        }
    }

    function adjustImageSize(imageId, dimension, amount, event) {
        if (event) event.stopPropagation();
        
        const imageContainer = document.querySelector(`.image-container[data-image-id="${imageId}"]`);
        if (!imageContainer) return;
        
        const img = imageContainer.querySelector('img');
        if (!img) return;
        
        let currentWidth = img.offsetWidth;
        let currentHeight = img.offsetHeight;
        
        switch(dimension) {
            case 'width':
                img.style.width = Math.max(50, currentWidth + amount) + 'px';
                break;
            case 'height':
                img.style.height = Math.max(50, currentHeight + amount) + 'px';
                break;
            case 'both':
                img.style.width = Math.max(50, currentWidth + amount) + 'px';
                img.style.height = Math.max(50, currentHeight + amount) + 'px';
                break;
        }
        
        updateImageSizeDisplay(imageId);
        markContentAsChanged();
        showMessage('Image Adjusted', `Image size ${amount > 0 ? 'increased' : 'decreased'}. Don't forget to save!`, 'info');
    }

    function adjustActiveImageSize(action) {
        if (!activeImageId) {
            showMessage('No Image Selected', 'Please click on an image first.', 'warning');
            return;
        }
        
        const amount = action === 'increase' ? 20 : -20;
        adjustImageSize(activeImageId, 'both', amount);
    }

    function resetImageSize(imageId, event) {
        if (event) event.stopPropagation();
        
        const imageContainer = document.querySelector(`.image-container[data-image-id="${imageId}"]`);
        if (!imageContainer) return;
        
        const img = imageContainer.querySelector('img');
        if (!img) return;
        
        img.style.width = '300px';
        img.style.height = 'auto';
        updateImageSizeDisplay(imageId);
        markContentAsChanged();
        showMessage('Image Reset', 'Image size reset to default.', 'info');
    }

    function deleteImage(imageId, event) {
        if (event) event.stopPropagation();
        
        if (!confirm('Are you sure you want to delete this image?')) {
            return;
        }
        
        showLoading('Deleting image...');
        fetch('delete_image.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                image_id: imageId,
                client_id: CLIENT_ID
            })
        })
        .then(res => res.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                const imageContainer = document.querySelector(`.image-container[data-image-id="${imageId}"]`);
                if (imageContainer) {
                    imageContainer.remove();
                }
                
                // Reset active image
                activeImageId = null;
                activeImageContainer = null;
                document.getElementById('increaseSizeBtn').style.display = 'none';
                document.getElementById('decreaseSizeBtn').style.display = 'none';
                document.getElementById('deleteImageBtn').style.display = 'none';
                
                markContentAsChanged();
                showMessage('Image Deleted', 'Image deleted successfully!', 'success');
            } else {
                showMessage('Delete Failed', data.error || 'Failed to delete image.', 'error');
            }
        })
        .catch(err => {
            hideLoading();
            showMessage('Error', 'Error deleting image: ' + err.message, 'error');
        });
    }

    function deleteActiveImage() {
        if (!activeImageId) {
            showMessage('No Image Selected', 'No image selected to delete.', 'warning');
            return;
        }
        deleteImage(activeImageId);
    }

    // Initialize image dragging and resizing
    function initImageInteractions() {
        document.querySelectorAll('.image-container').forEach(container => {
            const img = container.querySelector('img');
            if (!img) return;
            
            const imageId = container.getAttribute('data-image-id');
            updateImageSizeDisplay(imageId);
            
            // Click to select image
            container.addEventListener('click', function(e) {
                if (e.target.classList.contains('resize-handle') || 
                    e.target.classList.contains('size-btn') ||
                    e.target.classList.contains('delete-btn')) {
                    return;
                }
                setActiveImage(imageId);
            });
            
            // Drag functionality
            container.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('resize-handle') || 
                    e.target.classList.contains('size-btn') ||
                    e.target.classList.contains('delete-btn')) {
                    return;
                }
                
                e.preventDefault();
                isDragging = true;
                container.classList.add('dragging');
                
                // Calculate offset from mouse to container
                const rect = container.getBoundingClientRect();
                dragOffsetX = e.clientX - rect.left;
                dragOffsetY = e.clientY - rect.top;
                
                // Set active image
                setActiveImage(imageId);
                
                document.addEventListener('mousemove', mouseDragMoveHandler);
                document.addEventListener('mouseup', mouseDragUpHandler);
            });
            
            // Initialize resize handles
            const handles = container.querySelectorAll('.resize-handle');
            handles.forEach(handle => {
                handle.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    isResizing = true;
                    resizeHandle = this.classList[1];
                    startX = e.clientX;
                    startY = e.clientY;
                    startWidth = img.offsetWidth;
                    startHeight = img.offsetHeight;
                    
                    // Set active image
                    setActiveImage(imageId);
                    
                    document.addEventListener('mousemove', mouseResizeMoveHandler);
                    document.addEventListener('mouseup', mouseResizeUpHandler);
                });
            });
        });
        
        function mouseDragMoveHandler(e) {
            if (!isDragging || !activeImageContainer) return;
            
            const editableContent = document.querySelector('.editable-content');
            const contentRect = editableContent.getBoundingClientRect();
            
            // Calculate new position
            let newLeft = e.clientX - dragOffsetX - contentRect.left;
            let newTop = e.clientY - dragOffsetY - contentRect.top;
            
            // Constrain within editable content
            const containerWidth = activeImageContainer.offsetWidth;
            const containerHeight = activeImageContainer.offsetHeight;
            
            newLeft = Math.max(0, Math.min(newLeft, contentRect.width - containerWidth));
            newTop = Math.max(0, Math.min(newTop, contentRect.height - containerHeight));
            
            activeImageContainer.style.left = newLeft + 'px';
            activeImageContainer.style.top = newTop + 'px';
        }
        
        function mouseDragUpHandler() {
            isDragging = false;
            if (activeImageContainer) {
                activeImageContainer.classList.remove('dragging');
                markContentAsChanged();
                showMessage('Image Moved', 'Image position updated. Don\'t forget to save!', 'info');
            }
            document.removeEventListener('mousemove', mouseDragMoveHandler);
            document.removeEventListener('mouseup', mouseDragUpHandler);
        }
        
        function mouseResizeMoveHandler(e) {
            if (!isResizing || !activeImageId) return;
            
            const imageContainer = document.querySelector(`.image-container[data-image-id="${activeImageId}"]`);
            if (!imageContainer) return;
            
            const img = imageContainer.querySelector('img');
            if (!img) return;
            
            let dx = e.clientX - startX;
            let dy = e.clientY - startY;
            
            let newWidth = startWidth;
            let newHeight = startHeight;
            
            switch(resizeHandle) {
                case 'bottom-right':
                    newWidth = Math.max(50, startWidth + dx);
                    newHeight = Math.max(50, startHeight + dy);
                    break;
                case 'bottom-left':
                    newWidth = Math.max(50, startWidth - dx);
                    newHeight = Math.max(50, startHeight + dy);
                    break;
                case 'top-right':
                    newWidth = Math.max(50, startWidth + dx);
                    newHeight = Math.max(50, startHeight - dy);
                    break;
                case 'top-left':
                    newWidth = Math.max(50, startWidth - dx);
                    newHeight = Math.max(50, startHeight - dy);
                    break;
            }
            
            img.style.width = newWidth + 'px';
            img.style.height = newHeight + 'px';
            updateImageSizeDisplay(activeImageId);
        }
        
        function mouseResizeUpHandler() {
            isResizing = false;
            resizeHandle = null;
            if (activeImageId) {
                markContentAsChanged();
                showMessage('Image Resized', 'Image size updated. Don\'t forget to save!', 'info');
            }
            document.removeEventListener('mousemove', mouseResizeMoveHandler);
            document.removeEventListener('mouseup', mouseResizeUpHandler);
        }
    }

    // Mark content as changed (enable save button)
    function markContentAsChanged() {
        if (editor) {
            // For CKEditor, we need to update the data
            const currentContent = getCurrentContent();
            editor.setData(currentContent);
        } else {
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = false;
        }
    }

    // Get current content including images with their positions and sizes
    function getCurrentContent() {
        let content = '';
        
        // Get text content from CKEditor or editable div
        if (editor) {
            content = editor.getData();
            // Remove any image containers that might be in the CKEditor content
            content = content.replace(/<div class="image-container"[^>]*>.*?<\/div>/gs, '');
        } else {
            const editable = document.getElementById('editableContent');
            // Clone to avoid modifying the original
            const clone = editable.cloneNode(true);
            // Remove image containers from clone
            clone.querySelectorAll('.image-container').forEach(el => el.remove());
            content = clone.innerHTML;
        }
        
        // Add image containers with their current positions and sizes
        document.querySelectorAll('.image-container').forEach(container => {
            const imageId = container.getAttribute('data-image-id');
            const img = container.querySelector('img');
            const alt = img ? img.alt : '';
            const src = img ? img.src : '';
            
            // Extract filename from src
            const filename = src.split('/').pop();
            
            // Get position and size
            const style = container.style;
            const imgStyle = img.style;
            
            // Create image HTML with all data
            const imageHtml = `<div class="image-container" 
                data-image-id="${imageId}"
                style="position: absolute; 
                       top: ${style.top || '20px'}; 
                       left: ${style.left || '20px'}; 
                       z-index: ${style.zIndex || '100'};">
                <div class="resizable-image" id="image-${imageId}">
                    <img src="${src}" alt="${alt}" 
                         style="width: ${imgStyle.width || '300px'}; height: ${imgStyle.height || 'auto'};">
                    <div class="resize-handle bottom-right"></div>
                    <div class="resize-handle bottom-left"></div>
                    <div class="resize-handle top-right"></div>
                    <div class="resize-handle top-left"></div>
                    <button class="delete-btn" onclick="deleteImage(${imageId})">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="image-size-display" id="size-display-${imageId}">
                        ${Math.round(img.offsetWidth)}px × ${Math.round(img.offsetHeight)}px
                    </div>
                    <div class="image-controls-bar">
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'width', 10, event)"><i class="fas fa-arrow-right"></i></button>
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'width', -10, event)"><i class="fas fa-arrow-left"></i></button>
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'height', 10, event)"><i class="fas fa-arrow-down"></i></button>
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'height', -10, event)"><i class="fas fa-arrow-up"></i></button>
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'both', 10, event)"><i class="fas fa-expand-alt"></i></button>
                        <button class="size-btn" onclick="adjustImageSize(${imageId}, 'both', -10, event)"><i class="fas fa-compress-alt"></i></button>
                        <button class="size-btn" onclick="resetImageSize(${imageId}, event)"><i class="fas fa-undo"></i></button>
                    </div>
                </div>
            </div>`;
            
            content += imageHtml;
        });
        
        return content;
    }

    // Enable paste image from clipboard in CKEditor
    function enablePasteImageFromClipboard(editorInstance) {
        editorInstance.editing.view.document.on('clipboardInput', (evt, data) => {
            const dataTransfer = data.dataTransfer;
            if (dataTransfer && dataTransfer._native && dataTransfer._native.files && dataTransfer._native.files.length) {
                for (let i = 0; i < dataTransfer._native.files.length; i++) {
                    const file = dataTransfer._native.files[i];
                    if (file.type.startsWith('image/')) {
                        evt.stop();
                        uploadAndInsertImage(file, editorInstance);
                        break;
                    }
                }
            }
        });
    }

    function uploadAndInsertImage(file, editorInstance) {
        const formData = new FormData();
        formData.append('image', file);
        formData.append('page_number', currentPage);
        formData.append('alt_text', file.name);
        showLoading('Uploading image...');
        
        fetch('upload_image.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    let imageUrl = data.path;
                    if (imageUrl.startsWith('../')) {
                        imageUrl = imageUrl.replace(/^\.\.\//, '/');
                    }
                    if (!imageUrl.startsWith('/')) {
                        imageUrl = '/' + imageUrl;
                    }
                    imageUrl = imageUrl.replace(/([^:]\/)\/+/g, "$1");
                    
                    // Calculate position (center of editable content)
                    const editableContent = document.querySelector('.editable-content');
                    const contentRect = editableContent.getBoundingClientRect();
                    const top = (contentRect.height / 2 - 100) + 'px';
                    const left = (contentRect.width / 2 - 150) + 'px';
                    
                    // Create image container
                    const imageHtml = `
                        <div class="image-container" 
                             data-image-id="${data.image_id}"
                             style="position: absolute; top: ${top}; left: ${left}; z-index: 1000;">
                            <div class="resizable-image" id="image-${data.image_id}">
                                <img src="${imageUrl}" alt="${file.name}" style="width: 300px; height: auto;">
                                <div class="resize-handle bottom-right"></div>
                                <div class="resize-handle bottom-left"></div>
                                <div class="resize-handle top-right"></div>
                                <div class="resize-handle top-left"></div>
                                <button class="delete-btn" onclick="deleteImage(${data.image_id})">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div class="image-size-display" id="size-display-${data.image_id}">300px × auto</div>
                                <div class="image-controls-bar">
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'width', 10, event)"><i class="fas fa-arrow-right"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'width', -10, event)"><i class="fas fa-arrow-left"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'height', 10, event)"><i class="fas fa-arrow-down"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'height', -10, event)"><i class="fas fa-arrow-up"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'both', 10, event)"><i class="fas fa-expand-alt"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'both', -10, event)"><i class="fas fa-compress-alt"></i></button>
                                    <button class="size-btn" onclick="resetImageSize(${data.image_id}, event)"><i class="fas fa-undo"></i></button>
                                </div>
                            </div>
                        </div>`;
                    
                    // Append to editable content
                    const editable = document.getElementById('editableContent');
                    editable.insertAdjacentHTML('beforeend', imageHtml);
                    
                    setTimeout(() => {
                        initImageInteractions();
                        updateImageSizeDisplay(data.image_id);
                        setActiveImage(data.image_id);
                    }, 100);
                    
                    markContentAsChanged();
                    showMessage('Image Uploaded', 'Image uploaded and inserted successfully!', 'success');
                } else {
                    showMessage('Upload Failed', data.error || 'Failed to upload image.', 'error');
                }
            });
    }

    // CKEditor integration
    function toggleEditMode() {
        editMode = !editMode;
        const editToggle = document.getElementById('editToggle');
        editToggle.classList.toggle('active', editMode);
        editToggle.innerHTML = editMode ?
            '<i class="fas fa-times"></i> Exit Edit Mode' :
            '<i class="fas fa-edit"></i> Edit Mode';
        // Update save button in right panel
        const saveBtn = document.getElementById('saveBtn');
        saveBtn.disabled = !editMode;
        document.getElementById('toolbar').style.display = editMode ? 'flex' : 'none';
        
        if (editMode) {
            ClassicEditor.create(document.getElementById('editableContent'), {
                toolbar: [
                    'undo', 'redo', '|', 'bold', 'italic', 'underline', 'fontColor', 'heading', '|',
                    'bulletedList', 'numberedList', 'insertTable', 'imageUpload', '|',
                    'link', 'blockQuote', 'code', 'removeFormat'
                ],
                heading: {
                    options: [
                        { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                        { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                        { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                        { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                    ]
                },
                simpleUpload: {
                    uploadUrl: 'upload_image.php',
                    headers: { 'X-CSRF-TOKEN': '' }
                }
            }).then(ed => {
                editor = ed;
                editor.model.document.on('change:data', () => {
                    document.getElementById('saveBtn').disabled = false;
                });
                enablePasteImageFromClipboard(editor);
            });
            
            showMessage('Edit Mode Enabled', 'You can now edit the slide content and images.', 'success');
        } else {
            if (editor) {
                editor.destroy().then(() => { editor = null; });
            }
            showMessage('Edit Mode Disabled', 'Slide editing is now disabled.', 'info');
        }
    }

    // AJAX save for slides (now includes image positions and sizes)
    function savePage() {
        showLoading("Saving slide with images...");
        const content = getCurrentContent();
        
        fetch('save_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                client_id: CLIENT_ID,
                page_number: currentPage,
                content: content,
                title: document.title,
                images: getImageData() // Send image data separately
            })
        })
        .then(res => res.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showMessage('Slide Saved', 'Slide saved successfully with image positions!', 'success');
                document.getElementById('saveBtn').disabled = true;
                checkPageStatus();
            } else {
                showMessage('Save Failed', data.error || 'Failed to save slide.', 'error');
            }
        })
        .catch(err => {
            hideLoading();
            showMessage('Error', 'Error saving slide: ' + err.message, 'error');
        });
    }

    // Get image data for saving
    function getImageData() {
        const images = [];
        document.querySelectorAll('.image-container').forEach(container => {
            const imageId = container.getAttribute('data-image-id');
            const img = container.querySelector('img');
            if (!img) return;
            
            images.push({
                id: imageId,
                position: {
                    top: container.style.top || '20px',
                    left: container.style.left || '20px',
                    zIndex: container.style.zIndex || '100'
                },
                size: {
                    width: img.style.width || '300px',
                    height: img.style.height || 'auto'
                },
                src: img.src,
                alt: img.alt
            });
        });
        return images;
    }

    // AJAX save for client info
    function saveClientInfo() {
        showLoading('Saving client info...');
        fetch('save_client_info.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                client_id: CLIENT_ID,
                client_name: document.querySelector('#clientInfoSidebar [name="client_name"]')?.value || CLIENT_NAME,
                client_email: document.getElementById('clientEmail').value,
                phone: document.getElementById('clientPhone').value,
                risk_profile: document.getElementById('clientRisk').value,
                investment_horizon: document.getElementById('clientHorizon').value,
                portfolio_value: document.getElementById('portfolioValue').value
            })
        })
        .then(res => res.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showMessage('Client Info Updated', 'Client information has been updated successfully.', 'success');
                closeClientInfo();
            } else {
                showMessage('Update Failed', data.error || 'Failed to update client info.', 'error');
            }
        });
    }

    // Toolbar handlers
    function insertImage() {
        if (!editMode) {
            showMessage('Edit Mode Required', 'Please enable Edit Mode first.', 'warning');
            return;
        }
        
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('image', file);
            formData.append('page_number', currentPage);
            formData.append('alt_text', file.name);
            showLoading('Uploading image...');
            fetch('upload_image.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    let imageUrl = data.path;
                    if (imageUrl.startsWith('../')) {
                        imageUrl = imageUrl.replace(/^\.\.\//, '/');
                    }
                    if (!imageUrl.startsWith('/')) {
                        imageUrl = '/' + imageUrl;
                    }
                    imageUrl = imageUrl.replace(/([^:]\/)\/+/g, "$1");
                    
                    // Calculate position
                    const editableContent = document.querySelector('.editable-content');
                    const contentRect = editableContent.getBoundingClientRect();
                    const top = (contentRect.height / 2 - 100) + 'px';
                    const left = (contentRect.width / 2 - 150) + 'px';
                    
                    const imageHtml = `
                        <div class="image-container" 
                             data-image-id="${data.image_id}"
                             style="position: absolute; top: ${top}; left: ${left}; z-index: 1000;">
                            <div class="resizable-image" id="image-${data.image_id}">
                                <img src="${imageUrl}" alt="${file.name}" style="width: 300px; height: auto;">
                                <div class="resize-handle bottom-right"></div>
                                <div class="resize-handle bottom-left"></div>
                                <div class="resize-handle top-right"></div>
                                <div class="resize-handle top-left"></div>
                                <button class="delete-btn" onclick="deleteImage(${data.image_id})">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div class="image-size-display" id="size-display-${data.image_id}">300px × auto</div>
                                <div class="image-controls-bar">
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'width', 10, event)"><i class="fas fa-arrow-right"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'width', -10, event)"><i class="fas fa-arrow-left"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'height', 10, event)"><i class="fas fa-arrow-down"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'height', -10, event)"><i class="fas fa-arrow-up"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'both', 10, event)"><i class="fas fa-expand-alt"></i></button>
                                    <button class="size-btn" onclick="adjustImageSize(${data.image_id}, 'both', -10, event)"><i class="fas fa-compress-alt"></i></button>
                                    <button class="size-btn" onclick="resetImageSize(${data.image_id}, event)"><i class="fas fa-undo"></i></button>
                                </div>
                            </div>
                        </div>`;
                    
                    const editable = document.getElementById('editableContent');
                    editable.insertAdjacentHTML('beforeend', imageHtml);
                    
                    setTimeout(() => {
                        initImageInteractions();
                        updateImageSizeDisplay(data.image_id);
                        setActiveImage(data.image_id);
                    }, 100);
                    
                    markContentAsChanged();
                    showMessage('Image Inserted', 'Image has been inserted into the slide.', 'success');
                } else {
                    showMessage('Upload Failed', data.error || 'Failed to upload image.', 'error');
                }
            });
        };
        input.click();
    }

    function insertTable() { 
        if (editor) editor.execute('insertTable', { rows: 2, columns: 2 }); 
    }

    function formatText(cmd) { 
        if (editor) editor.execute(cmd); 
    }

    function formatHeading(val) { 
        if (editor && val) editor.execute('heading', { value: val }); 
    }

    function changeColor(color) { 
        if (editor) editor.execute('fontColor', { value: color }); 
    }

    function previewPage() {
        const content = editor ? editor.getData() : document.getElementById('editableContent').innerHTML;
        const win = window.open('', '_blank');
        win.document.write(`<html><head><title>Preview</title></head><body>${content}</body></html>`);
        showMessage('Preview Opened', 'Slide preview opened in a new window.', 'info');
    }

    function showClientInfo() { 
        document.getElementById('clientInfoSidebar').style.display = 'flex';
        showMessage('Client Info', 'Client information panel opened.', 'info');
    }
    
    function closeClientInfo() {
        document.getElementById('clientInfoSidebar').style.display = 'none';
    }

    // Navigation functions
    function prevPage() {
        if (currentPage > 1) {
            showMessage('Navigating', `Moving to slide ${currentPage - 1}...`, 'info');
            setTimeout(() => {
                window.location.href = '?page=' + (currentPage - 1);
            }, 300);
        }
    }

    function nextPage() {
        if (currentPage < 23) {
            showMessage('Navigating', `Moving to slide ${currentPage + 1}...`, 'info');
            setTimeout(() => {
                window.location.href = '?page=' + (currentPage + 1);
            }, 300);
        }
    }

    function goToPage(page) {
        page = parseInt(page);
        if (page >= 1 && page <= 23 && page !== currentPage) {
            showMessage('Navigating', `Moving to slide ${page}...`, 'info');
            setTimeout(() => {
                window.location.href = '?page=' + page;
            }, 300);
        }
    }

    function showSlideManager() {
        const modal = document.getElementById('pageManagerModal');
        if (modal) {
            modal.classList.add('active');
            showMessage('Slide Manager', 'Slide management modal opened.', 'info');
        } else {
            showMessage('Not Implemented', 'Slide Manager modal not implemented.', 'warning');
        }
    }

    // Keyboard shortcuts for image resizing
    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Only process if edit mode is on and an image is selected
            if (!editMode || !activeImageId) return;
            
            // Prevent default behavior for arrow keys when resizing/moving
            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', '+', '-', 'Delete'].includes(e.key)) {
                e.preventDefault();
            }
            
            // Arrow keys for precise resizing (with Shift for larger steps)
            const step = e.shiftKey ? 20 : 5;
            const moveStep = e.shiftKey ? 10 : 5;
            
            switch(e.key) {
                case 'ArrowUp':
                    if (e.altKey) {
                        // Move image up
                        const container = document.querySelector(`.image-container[data-image-id="${activeImageId}"]`);
                        if (container) {
                            const currentTop = parseInt(container.style.top) || 0;
                            container.style.top = Math.max(0, currentTop - moveStep) + 'px';
                            markContentAsChanged();
                        }
                    } else {
                        adjustImageSize(activeImageId, 'height', -step);
                    }
                    break;
                case 'ArrowDown':
                    if (e.altKey) {
                        // Move image down
                        const container = document.querySelector(`.image-container[data-image-id="${activeImageId}"]`);
                        if (container) {
                            const currentTop = parseInt(container.style.top) || 0;
                            const editableContent = document.querySelector('.editable-content');
                            const maxTop = editableContent.offsetHeight - container.offsetHeight;
                            container.style.top = Math.min(maxTop, currentTop + moveStep) + 'px';
                            markContentAsChanged();
                        }
                    } else {
                        adjustImageSize(activeImageId, 'height', step);
                    }
                    break;
                case 'ArrowLeft':
                    if (e.altKey) {
                        // Move image left
                        const container = document.querySelector(`.image-container[data-image-id="${activeImageId}"]`);
                        if (container) {
                            const currentLeft = parseInt(container.style.left) || 0;
                            container.style.left = Math.max(0, currentLeft - moveStep) + 'px';
                            markContentAsChanged();
                        }
                    } else {
                        adjustImageSize(activeImageId, 'width', -step);
                    }
                    break;
                case 'ArrowRight':
                    if (e.altKey) {
                        // Move image right
                        const container = document.querySelector(`.image-container[data-image-id="${activeImageId}"]`);
                        if (container) {
                            const currentLeft = parseInt(container.style.left) || 0;
                            const editableContent = document.querySelector('.editable-content');
                            const maxLeft = editableContent.offsetWidth - container.offsetWidth;
                            container.style.left = Math.min(maxLeft, currentLeft + moveStep) + 'px';
                            markContentAsChanged();
                        }
                    } else {
                        adjustImageSize(activeImageId, 'width', step);
                    }
                    break;
                case '+':
                case '=':
                    adjustImageSize(activeImageId, 'both', step);
                    break;
                case '-':
                case '_':
                    adjustImageSize(activeImageId, 'both', -step);
                    break;
                case 'Delete':
                case 'Backspace':
                    deleteImage(activeImageId);
                    break;
                case 'Escape':
                    // Deselect image
                    document.querySelectorAll('.image-container').forEach(img => {
                        img.classList.remove('active');
                    });
                    activeImageId = null;
                    activeImageContainer = null;
                    document.getElementById('increaseSizeBtn').style.display = 'none';
                    document.getElementById('decreaseSizeBtn').style.display = 'none';
                    document.getElementById('deleteImageBtn').style.display = 'none';
                    showMessage('Image Deselected', 'Active image deselected.', 'info');
                    break;
            }
        });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize image interactions for existing images
        initImageInteractions();
        
        // Setup keyboard shortcuts
        setupKeyboardShortcuts();
        
        // Check page status
        checkPageStatus();
        
        // Show welcome message
        showMessage('Welcome', `Editing ${CLIENT_NAME}'s portfolio slides. Slide ${currentPage} of 23 loaded.`, 'info');
        
        // Setup click outside to deselect image
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.image-container') &&
                !e.target.closest('#increaseSizeBtn') &&
                !e.target.closest('#decreaseSizeBtn') &&
                !e.target.closest('#deleteImageBtn')) {
                
                document.querySelectorAll('.image-container').forEach(img => {
                    img.classList.remove('active');
                });
                activeImageId = null;
                activeImageContainer = null;
                document.getElementById('increaseSizeBtn').style.display = 'none';
                document.getElementById('decreaseSizeBtn').style.display = 'none';
                document.getElementById('deleteImageBtn').style.display = 'none';
            }
        });
    });

    function checkPageStatus() {
        const currentPageData = <?php echo json_encode($pages[$current_page] ?? null); ?>;
        
        if (currentPageData && currentPageData.content) {
            document.getElementById('autoSaveStatus').textContent = 'Saved';
            document.getElementById('autoSaveStatus').style.color = '#10b981';
        } else {
            document.getElementById('autoSaveStatus').textContent = 'New Slide';
            document.getElementById('autoSaveStatus').style.color = '#f59e0b';
        }
    }

    // Download functions
    function downloadPPT() {
        showLoading('Generating PowerPoint...');
        showMessage('PPT Generation', 'PowerPoint generation started. This may take a moment...', 'info');
        window.open('generate-ppt.php', '_blank');
        setTimeout(() => {
            hideLoading();
            showMessage('PPT Generated', 'PowerPoint file generation completed.', 'success');
        }, 2000);
    }

    function downloadPDF() {
        showLoading('Generating PDF...');
        showMessage('PDF Generation', 'PDF generation feature coming soon!', 'info');
        setTimeout(() => {
            hideLoading();
            showMessage('Feature Coming Soon', 'PDF generation will be available in the next update.', 'warning');
        }, 1000);
    }
    </script>
</body>
</html>
<?php
// report_generator/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

$current_page = isset($_GET['page']) ? max(1, min(24, intval($_GET['page']))) : 1;
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
    <title>Portfolio Slides - <?php echo htmlspecialchars($client_name); ?></title>
    <!-- Use absolute path for CSS to avoid path issues -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../public/css/navbar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>
</head>
<body>
    <?php include '../navbar.php'; ?>
    
    <div class="powerpoint-container">
        <!-- LEFT: PowerPoint style slide thumbnails -->
        <div class="powerpoint-sidebar">
            <div class="sidebar-header">
                <h2><?php echo htmlspecialchars($client_name); ?></h2>
                <div class="client-subtitle-sidebar">Portfolio Review Q1 2026</div>
                <div class="client-meta-mini">
                    <!-- <div class="meta-mini-item">
                        <i class="fas fa-chart-line fa-xs"></i> <?php echo htmlspecialchars($risk_profile); ?>
                    </div>
                    <div class="meta-mini-item">
                        <i class="fas fa-calendar-alt fa-xs"></i> <?php echo htmlspecialchars($investment_horizon); ?>
                    </div> -->
                </div>
            </div>
            
            <div class="slide-thumbnails-container" id="slideThumbnails">
                <?php for($i = 1; $i <= 24; $i++): ?>
                    <div class="slide-thumbnail <?php if($i == $current_page) echo 'active'; ?>" 
                         onclick="window.location.href='?page=<?php echo $i; ?>'">
                        <div class="slide-number"><?php echo $i; ?></div>
                        <div class="slide-preview-content">
                            <div class="slide-preview-title">Slide <?php echo $i; ?></div>
                            <div class="slide-preview-text">
                                <?php
                                $pageFile = "page{$i}.php";
                                if (file_exists($pageFile)) {
                                    $content = strip_tags(file_get_contents($pageFile));
                                    $preview = strlen($content) > 100 ? substr($content, 0, 100) . '...' : $content;
                                    echo htmlspecialchars($preview);
                                } else {
                                    echo "No content";
                                }
                                ?>
                            </div>
                        </div>
                        <?php if (file_exists($pageFile)): ?>
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
            <div class="ppt-toolbar" id="toolbar" style="display: none;">
                <div class="ppt-toolbar-group">
                    <button class="ppt-toolbar-btn" onclick="toggleEditMode()" id="editToggleBtn">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="ppt-toolbar-btn" onclick="saveSlide()" id="saveBtn" disabled>
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
                
                <div class="ppt-toolbar-group">
                    <button class="ppt-toolbar-btn" onclick="insertImage()">
                        <i class="fas fa-image"></i> Image
                    </button>
                    <button class="ppt-toolbar-btn" onclick="insertTable()">
                        <i class="fas fa-table"></i> Table
                    </button>
                    <button class="ppt-toolbar-btn" onclick="insertChart()">
                        <i class="fas fa-chart-bar"></i> Chart
                    </button>
                </div>
                
                <div class="ppt-toolbar-group">
                    <button class="ppt-toolbar-btn" onclick="formatText('bold')">
                        <i class="fas fa-bold"></i>
                    </button>
                    <button class="ppt-toolbar-btn" onclick="formatText('italic')">
                        <i class="fas fa-italic"></i>
                    </button>
                    <button class="ppt-toolbar-btn" onclick="formatText('underline')">
                        <i class="fas fa-underline"></i>
                    </button>
                    <select class="property-select" style="width: 120px;" onchange="formatHeading(this.value)">
                        <option value="">Text Style</option>
                        <option value="h1">Title</option>
                        <option value="h2">Heading</option>
                        <option value="h3">Subheading</option>
                        <option value="p">Normal</option>
                    </select>
                    <input type="color" id="colorPicker" onchange="changeColor(this.value)" value="#2E75B6" 
                           style="width: 30px; height: 30px; border: none; cursor: pointer;" title="Text Color">
                </div>
                
                <div class="ppt-toolbar-group" style="margin-left: auto;">
                    <button class="ppt-toolbar-btn" onclick="previewSlide()">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                    <button class="ppt-toolbar-btn btn-success" onclick="downloadPPT()">
                        <i class="fas fa-file-powerpoint"></i> Export PPT
                    </button>
                    <button class="ppt-toolbar-btn" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <div class="ppt-slide-area">
                <div class="slide-frame">
                    <div class="slide-header">
                        <h1>Slide <?php echo $current_page; ?></h1>
                        <div class="slide-header-controls">
                            <button class="slide-header-btn" onclick="goToSlide(<?php echo max(1, $current_page-1); ?>)" <?php if($current_page == 1) echo 'disabled'; ?>>
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="slide-header-btn" onclick="goToSlide(<?php echo min(24, $current_page+1); ?>)" <?php if($current_page == 24) echo 'disabled'; ?>>
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="slide-content-container" style="padding:0;height:calc(100% - 60px);">
                        <iframe
                            id="slideIframe"
                            src="page<?php echo $current_page; ?>.php?edit=1"
                            style="width:100%;height:100%;border:none;background:white;"
                            title="Slide <?php echo $current_page; ?>">
                        </iframe>
                    </div>
                </div>
            </div>
            
            <!-- PowerPoint Style Status Bar -->
            <div class="ppt-status-bar">
                <div class="status-bar-left">
                    <div class="status-item">
                        <i class="fas fa-user"></i>
                        <span><?php echo htmlspecialchars($client_name); ?></span>
                    </div>
                    <div class="status-separator"></div>
                    <div class="status-item">
                        <i class="fas fa-file-alt"></i>
                        <span>Portfolio Review</span>
                    </div>
                </div>
                <div class="status-bar-right">
                    <div class="status-item">
                        <span id="saveStatus">Ready</span>
                    </div>
                    <div class="status-separator"></div>
                    <div class="status-item">
                        <i class="fas fa-clock"></i>
                        <span id="currentTime"><?php echo date('H:i'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- RIGHT: Properties and Notes Panel -->
        <div class="powerpoint-properties">
            <div class="properties-tabs">
                <button class="properties-tab active" onclick="switchTab('properties')">Properties</button>
                <button class="properties-tab" onclick="switchTab('notes')">Notes</button>
                <button class="properties-tab" onclick="switchTab('messages')">Messages</button>
            </div>
            
            <div class="properties-content" id="propertiesTab">
                <div class="properties-section">
                    <h3>Slide Properties</h3>
                    <div class="property-item">
                        <label class="property-label">Slide Title</label>
                        <input type="text" class="property-input" id="slideTitle" 
                               value="Slide <?php echo $current_page; ?>" 
                               onchange="updateSlideTitle(this.value)">
                    </div>
                    <div class="property-item">
                        <label class="property-label">Background Color</label>
                        <input type="color" class="property-input" id="slideBgColor" 
                               value="#ffffff" style="height: 40px; padding: 2px;">
                    </div>
                </div>
                
                <div class="properties-section">
                    <h3>Image Properties</h3>
                    <div class="property-item">
                        <label class="property-label">Selected Image</label>
                        <div id="selectedImageInfo" style="font-size: 12px; color: #999; padding: 8px; background: #f5f5f5; border-radius: 3px;">
                            No image selected
                        </div>
                    </div>
                    <div class="property-item">
                        <label class="property-label">Image Size</label>
                        <div style="display: flex; gap: 5px;">
                            <input type="number" class="property-input" id="imageWidth" placeholder="Width" style="flex: 1;">
                            <input type="number" class="property-input" id="imageHeight" placeholder="Height" style="flex: 1;">
                        </div>
                    </div>
                    <button class="btn btn-primary" style="width: 100%; margin-top: 10px;" 
                            onclick="insertImage()">
                        <i class="fas fa-plus"></i> Insert Image
                    </button>
                </div>
                
                <div class="properties-section">
                    <h3>Client Information</h3>
                    <button class="btn" style="width: 100%;" onclick="showClientInfo()">
                        <i class="fas fa-user-edit"></i> Edit Client Info
                    </button>
                </div>
            </div>
            
            <div class="properties-content" id="notesTab" style="display: none;">
                <div class="properties-section">
                    <h3>Slide Notes</h3>
                    <textarea class="property-input" id="slideNotes" rows="10" 
                              placeholder="Add notes for this slide..."></textarea>
                    <button class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                        <i class="fas fa-save"></i> Save Notes
                    </button>
                </div>
            </div>
            
            <div class="properties-content" id="messagesTab" style="display: none;">
                <div class="properties-section">
                    <h3>Messages</h3>
                    <div class="messages-panel" id="messagesPanel">
                        <div style="text-align: center; padding: 20px; color: #999;">
                            <i class="fas fa-comments fa-2x"></i>
                            <p style="margin-top: 10px;">No messages yet</p>
                        </div>
                    </div>
                    <button class="btn" style="width: 100%; margin-top: 10px;" onclick="clearMessages()">
                        <i class="fas fa-trash"></i> Clear Messages
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2e75b6;"></i>
            <p id="loadingMessage" style="margin-top: 15px;">Processing...</p>
        </div>
    </div>
    
    <script>
    // --- POWERPOINT STYLE EDITOR LOGIC ---
    let editMode = false;
    let editor = null;
    let currentSlide = <?php echo $current_page; ?>;
    const CLIENT_ID = '<?php echo $client_id; ?>';
    const CLIENT_NAME = '<?php echo addslashes($clientInfo['client_name'] ?? 'Client'); ?>';
    let activeImageId = null;
    let messageCounter = 0;
    
    // Update current time in status bar
    function updateCurrentTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        document.getElementById('currentTime').textContent = timeString;
    }
    setInterval(updateCurrentTime, 60000);
    updateCurrentTime();
    
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
            window.location.href = '?page=' + slideNumber;
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
            document.getElementById('editableContent').contentEditable = 'true';
            
            // Initialize CKEditor
            ClassicEditor
                .create(document.querySelector('#editableContent'), {
                    toolbar: [
                        'heading', '|', 'bold', 'italic', 'underline', 'fontColor', '|',
                        'bulletedList', 'numberedList', '|', 'insertTable', 'link', '|',
                        'undo', 'redo'
                    ],
                    heading: {
                        options: [
                            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                            { model: 'heading1', view: 'h1', title: 'Title', class: 'ck-heading_heading1' },
                            { model: 'heading2', view: 'h2', title: 'Heading', class: 'ck-heading_heading2' },
                            { model: 'heading3', view: 'h3', title: 'Subheading', class: 'ck-heading_heading3' }
                        ]
                    }
                })
                .then(newEditor => {
                    editor = newEditor;
                    editor.model.document.on('change:data', () => {
                        document.getElementById('saveBtn').disabled = false;
                        document.getElementById('saveStatus').textContent = 'Unsaved changes';
                        document.getElementById('saveStatus').style.color = '#f59e0b';
                    });
                })
                .catch(error => {
                    console.error('CKEditor initialization error:', error);
                });
            
            showMessage('Edit Mode Enabled', 'You can now edit the slide content.', 'success');
        } else {
            // Exit edit mode
            editToggleBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
            editToggleBtn.classList.remove('active');
            saveBtn.disabled = true;
            toolbar.style.display = 'none';
            document.getElementById('editableContent').contentEditable = 'false';
            
            if (editor) {
                editor.destroy().then(() => {
                    editor = null;
                });
            }
            
            showMessage('Edit Mode Disabled', 'Slide editing is now disabled.', 'info');
        }
    }
    
    // Save slide
    function saveSlide() {
        showLoading('Saving slide...');
        
        const content = editor ? editor.getData() : document.getElementById('editableContent').innerHTML;
        
        fetch('save_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                client_id: CLIENT_ID,
                page_number: currentSlide,
                content: content,
                title: document.getElementById('slideTitle').value
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
                    const statusIcon = currentThumb.querySelector('.slide-status');
                    if (!statusIcon) {
                        const statusDiv = document.createElement('div');
                        statusDiv.className = 'slide-status';
                        statusDiv.title = 'Saved';
                        statusDiv.innerHTML = '<i class="fas fa-check-circle"></i>';
                        currentThumb.appendChild(statusDiv);
                    }
                }
            } else {
                showMessage('Save Failed', data.error || 'Failed to save slide.', 'error');
            }
        })
        .catch(err => {
            hideLoading();
            showMessage('Error', 'Error saving slide: ' + err.message, 'error');
        });
    }
    
    // Insert image
    function insertImage() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('image', file);
            formData.append('page_number', currentSlide);
            formData.append('alt_text', file.name);
            
            showLoading('Uploading image...');
            
            fetch('upload_image.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    const imageUrl = 'uploads/' + data.filename;
                    
                    // Create image element
                    const imageContainer = document.createElement('div');
                    imageContainer.className = 'slide-image-container';
                    imageContainer.setAttribute('data-image-id', data.image_id);
                    imageContainer.style.position = 'absolute';
                    imageContainer.style.top = '50px';
                    imageContainer.style.left = '50px';
                    imageContainer.style.width = '300px';
                    imageContainer.style.zIndex = '1000';
                    
                    imageContainer.innerHTML = `
                        <img src="${imageUrl}" alt="${file.name}" style="width: 100%; height: auto;">
                        <div class="image-controls">
                            <button class="image-control-btn" onclick="adjustImageSize(${data.image_id}, 'increase', event)">
                                <i class="fas fa-search-plus"></i>
                            </button>
                            <button class="image-control-btn" onclick="adjustImageSize(${data.image_id}, 'decrease', event)">
                                <i class="fas fa-search-minus"></i>
                            </button>
                            <button class="image-control-btn" onclick="deleteImage(${data.image_id}, event)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                    
                    document.getElementById('editableContent').appendChild(imageContainer);
                    
                    // Make image draggable
                    makeDraggable(imageContainer, data.image_id);
                    
                    showMessage('Image Inserted', 'Image added to slide. Drag to reposition.', 'success');
                    document.getElementById('saveBtn').disabled = false;
                } else {
                    showMessage('Upload Failed', data.error || 'Failed to upload image.', 'error');
                }
            });
        };
        input.click();
    }
    
    // Make image draggable
    function makeDraggable(element, imageId) {
        let isDragging = false;
        let offsetX, offsetY;
        
        element.addEventListener('mousedown', function(e) {
            if (e.target.classList.contains('image-control-btn')) return;
            
            isDragging = true;
            element.classList.add('active');
            
            const rect = element.getBoundingClientRect();
            offsetX = e.clientX - rect.left;
            offsetY = e.clientY - rect.top;
            
            document.addEventListener('mousemove', mouseMoveHandler);
            document.addEventListener('mouseup', mouseUpHandler);
            
            e.preventDefault();
        });
        
        function mouseMoveHandler(e) {
            if (!isDragging) return;
            
            const slideRect = document.querySelector('.slide-content-container').getBoundingClientRect();
            const elementRect = element.getBoundingClientRect();
            
            let newLeft = e.clientX - offsetX - slideRect.left;
            let newTop = e.clientY - offsetY - slideRect.top;
            
            // Constrain within slide
            newLeft = Math.max(0, Math.min(newLeft, slideRect.width - elementRect.width));
            newTop = Math.max(0, Math.min(newTop, slideRect.height - elementRect.height));
            
            element.style.left = newLeft + 'px';
            element.style.top = newTop + 'px';
        }
        
        function mouseUpHandler() {
            isDragging = false;
            element.classList.remove('active');
            document.removeEventListener('mousemove', mouseMoveHandler);
            document.removeEventListener('mouseup', mouseUpHandler);
            
            document.getElementById('saveBtn').disabled = false;
            document.getElementById('saveStatus').textContent = 'Unsaved changes';
            document.getElementById('saveStatus').style.color = '#f59e0b';
        }
    }
    
    // Adjust image size
    function adjustImageSize(imageId, action, event) {
        if (event) event.stopPropagation();
        
        const imageContainer = document.querySelector(`.slide-image-container[data-image-id="${imageId}"]`);
        if (!imageContainer) return;
        
        const img = imageContainer.querySelector('img');
        if (!img) return;
        
        const currentWidth = imageContainer.offsetWidth;
        let newWidth;
        
        if (action === 'increase') {
            newWidth = currentWidth + 50;
        } else {
            newWidth = Math.max(100, currentWidth - 50);
        }
        
        imageContainer.style.width = newWidth + 'px';
        
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('saveStatus').textContent = 'Unsaved changes';
        document.getElementById('saveStatus').style.color = '#f59e0b';
    }
    
    // Delete image
    function deleteImage(imageId, event) {
        if (event) event.stopPropagation();
        
        if (!confirm('Are you sure you want to delete this image?')) {
            return;
        }
        
        const imageContainer = document.querySelector(`.slide-image-container[data-image-id="${imageId}"]`);
        if (imageContainer) {
            imageContainer.remove();
        }
        
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('saveStatus').textContent = 'Unsaved changes';
        document.getElementById('saveStatus').style.color = '#f59e0b';
        
        showMessage('Image Deleted', 'Image removed from slide.', 'info');
    }
    
    // Text formatting functions
    function formatText(cmd) {
        if (editor) {
            editor.execute(cmd);
        } else {
            document.execCommand(cmd, false, null);
        }
    }
    
    function formatHeading(val) {
        if (editor && val) {
            editor.execute('heading', { value: val });
        }
    }
    
    function changeColor(color) {
        if (editor) {
            editor.execute('fontColor', { value: color });
        }
    }
    
    function insertTable() {
        if (editor) {
            editor.execute('insertTable', { rows: 3, columns: 3 });
        }
    }
    
    function insertChart() {
        showMessage('Coming Soon', 'Chart insertion feature will be available soon.', 'info');
    }
    
    // Preview slide
    function previewSlide() {
        const content = editor ? editor.getData() : document.getElementById('editableContent').innerHTML;
        const win = window.open('', '_blank');
        win.document.write(`
            <html>
            <head>
                <title>Slide ${currentSlide} Preview</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .slide-preview { max-width: 800px; margin: 0 auto; background: white; padding: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
                </style>
            </head>
            <body>
                <div class="slide-preview">${content}</div>
            </body>
            </html>
        `);
    }
    
    // Show client info
    function showClientInfo() {
        // This would open a modal with client information
        alert('Client Information Editor - To be implemented');
    }
    
    // Update slide title
    function updateSlideTitle(title) {
        document.querySelector('.slide-header h1').textContent = title;
        document.getElementById('saveBtn').disabled = false;
    }
    
    // Message system
    function showMessage(title, content, type = 'info') {
        messageCounter++;
        const messagesPanel = document.getElementById('messagesPanel');
        
        // Remove "no messages" placeholder if present
        const placeholder = messagesPanel.querySelector('.fa-comments');
        if (placeholder) {
            messagesPanel.innerHTML = '';
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}`;
        messageDiv.innerHTML = `
            <div class="message-title">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${title}
            </div>
            <div class="message-content">${content}</div>
        `;
        
        messagesPanel.prepend(messageDiv);
        
        // Auto-remove old messages
        const allMessages = messagesPanel.querySelectorAll('.message');
        if (allMessages.length > 10) {
            for (let i = 10; i < allMessages.length; i++) {
                allMessages[i].remove();
            }
        }
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.style.opacity = '0';
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.remove();
                    }
                }, 300);
            }
        }, 10000);
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
            window.open('generate-ppt.php', '_blank');
        }, 2000);
    }
    
    // Initialize existing image interactions
    document.addEventListener('DOMContentLoaded', function() {
        // Make existing images draggable
        document.querySelectorAll('.slide-image-container').forEach(container => {
            const imageId = container.getAttribute('data-image-id');
            makeDraggable(container, imageId);
        });
        
        // Set up image click to activate
        document.addEventListener('click', function(e) {
            if (e.target.closest('.slide-image-container')) {
                const imageContainer = e.target.closest('.slide-image-container');
                document.querySelectorAll('.slide-image-container').forEach(img => {
                    img.classList.remove('active');
                });
                imageContainer.classList.add('active');
                
                // Update properties panel
                const imageId = imageContainer.getAttribute('data-image-id');
                const img = imageContainer.querySelector('img');
                document.getElementById('selectedImageInfo').textContent = 
                    `Image: ${img.alt || 'Untitled'}`;
                document.getElementById('imageWidth').value = imageContainer.offsetWidth;
                document.getElementById('imageHeight').value = img.offsetHeight;
            }
        });
        
        // Initialize save status
        <?php if (isset($pages[$current_page])): ?>
            document.getElementById('saveStatus').textContent = 'Saved';
            document.getElementById('saveStatus').style.color = '#10b981';
        <?php else: ?>
            document.getElementById('saveStatus').textContent = 'Not saved';
            document.getElementById('saveStatus').style.color = '#ef4444';
        <?php endif; ?>
        
        // Welcome message
        showMessage('Welcome', `Editing ${CLIENT_NAME}'s portfolio slides. Slide ${currentSlide} of 24 loaded.`, 'info');
    });
    </script>
</body>
</html>
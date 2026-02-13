// report_generator/script.js - UPDATED

// Utility Functions
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <div>${message}</div>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: none; border: none; color: white; cursor: pointer; margin-left: auto;">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

function showLoading(message = 'Processing...') {
    const overlay = document.getElementById('loadingOverlay');
    const messageEl = document.getElementById('loadingMessage');
    
    if (overlay && messageEl) {
        messageEl.textContent = message;
        overlay.style.display = 'flex';
    }
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

// Sidebar Functions
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
}

// Modal Functions
function showPageManager() {
    document.getElementById('pageManagerModal').classList.add('active');
    loadSlidesGrid();
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function switchTab(tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    
    // Activate corresponding button
    event.target.classList.add('active');
}

// Slide Management
function loadSlidesGrid() {
    fetch('get_slides.php')
        .then(response => response.json())
        .then(slides => {
            const grid = document.getElementById('slidesGrid');
            grid.innerHTML = '';
            
            slides.forEach(slide => {
                const slideItem = document.createElement('div');
                slideItem.className = `slide-item ${slide.page_number == currentPage ? 'active' : ''}`;
                slideItem.innerHTML = `
                    <div class="slide-number">${slide.page_number}</div>
                    <div class="slide-title">${slide.title || 'Slide ' + slide.page_number}</div>
                    <small>${new Date(slide.updated_at).toLocaleDateString()}</small>
                `;
                slideItem.onclick = () => selectSlide(slide.page_number);
                grid.appendChild(slideItem);
            });
        });
}

function selectSlide(pageNumber) {
    if (editMode) {
        if (confirm('Switch to slide ' + pageNumber + '?')) {
            goToPage(pageNumber);
            closeModal('pageManagerModal');
        }
    } else {
        goToPage(pageNumber);
        closeModal('pageManagerModal');
    }
}

function duplicateSlide() {
    if (confirm('Duplicate current slide?')) {
        const content = editor ? editor.getData() : document.getElementById('editableContent').innerHTML;
        
        fetch('duplicate_slide.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                source_page: currentPage,
                content: content,
                title: document.getElementById('pageTitle').value + ' (Copy)'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Slide duplicated successfully!');
                loadSlidesGrid();
            }
        });
    }
}

function deleteSlide() {
    if (confirm('Delete this slide? This cannot be undone.')) {
        fetch(`delete_slide.php?page=${currentPage}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Slide deleted successfully!');
                // Reset current slide
                document.getElementById('editableContent').innerHTML = 
                    `<div class="section-title">Slide ${currentPage}</div><p>Content goes here</p>`;
                loadSlidesGrid();
            }
        });
    }
}

// Image Management
function insertImage() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.multiple = true;
    
    input.onchange = function(e) {
        Array.from(e.target.files).forEach(file => {
            uploadImage(file);
        });
    };
    
    input.click();
}

function uploadImage(file) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('page_number', currentPage);
    
    showLoading('Uploading image...');
    
    fetch('upload_image.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Insert image at cursor position
            const imgHtml = `<img src="uploads/${data.filename}" alt="${data.alt}" style="max-width: 100%;" data-image-id="${data.id}">`;
            
            if (editor) {
                editor.model.change(writer => {
                    const imageElement = writer.createElement('image', {
                        src: `uploads/${data.filename}`,
                        alt: data.alt
                    });
                    editor.model.insertContent(imageElement);
                });
            } else {
                // Insert at cursor position
                const contentEditable = document.getElementById('editableContent');
                const selection = window.getSelection();
                
                if (selection.rangeCount) {
                    const range = selection.getRangeAt(0);
                    const imgNode = document.createElement('img');
                    imgNode.src = `uploads/${data.filename}`;
                    imgNode.alt = data.alt;
                    imgNode.style.maxWidth = '100%';
                    
                    range.insertNode(imgNode);
                    range.setStartAfter(imgNode);
                    selection.removeAllRanges();
                    selection.addRange(range);
                } else {
                    contentEditable.innerHTML += imgHtml;
                }
            }
            
            showNotification('Image uploaded successfully!');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error uploading image!', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

function editImage(imageId) {
    fetch(`get_image.php?id=${imageId}`)
        .then(response => response.json())
        .then(image => {
            document.getElementById('imagePreview').src = `uploads/${image.filename}`;
            document.getElementById('imageAlt').value = image.alt_text;
            document.getElementById('imageEditorModal').classList.add('active');
        });
}

function deleteImage(imageId) {
    if (confirm('Delete this image?')) {
        fetch(`delete_image.php?id=${imageId}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove image from DOM
                document.querySelector(`[data-image-id="${imageId}"]`).remove();
                showNotification('Image deleted successfully!');
            }
        });
    }
}

// Template Functions
function applyTemplate(templateType) {
    let templateContent = '';
    
    switch (templateType) {
        case 'title':
            templateContent = `
                <div style="text-align: center; padding: 100px 20px;">
                    <h1 style="color: #2E75B6; font-size: 48px;">Slide Title</h1>
                    <h2 style="color: #1F4E79; font-size: 36px;">Subtitle</h2>
                    <p style="margin-top: 50px; color: #666;">Your content here</p>
                </div>
            `;
            break;
            
        case 'content':
            templateContent = `
                <div class="section-title">Content Slide</div>
                <ul style="font-size: 18px; line-height: 1.6;">
                    <li>First bullet point</li>
                    <li>Second bullet point</li>
                    <li>Third bullet point</li>
                    <li>Fourth bullet point</li>
                </ul>
            `;
            break;
            
        case 'chart':
            templateContent = `
                <div class="section-title">Chart Slide</div>
                <div style="display: flex; gap: 20px; margin-top: 40px;">
                    <div style="flex: 1; background: #f8f9fa; padding: 20px; border-radius: 8px;">
                        <h3 style="color: #2E75B6;">Chart Title</h3>
                        <div style="height: 200px; background: linear-gradient(90deg, #2E75B6, #FFC000); border-radius: 4px;"></div>
                    </div>
                    <div style="flex: 1; padding: 20px;">
                        <h3 style="color: #2E75B6;">Key Insights</h3>
                        <p>Add your analysis and insights here.</p>
                    </div>
                </div>
            `;
            break;
            
        case 'summary':
            templateContent = `
                <div class="section-title">Summary</div>
                <div style="background: #E6F2FF; padding: 30px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="color: #1F4E79;">Key Takeaways</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                        <div style="background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #2E75B6;">
                            <strong>Point 1</strong>
                            <p>Description of point 1</p>
                        </div>
                        <div style="background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #FFC000;">
                            <strong>Point 2</strong>
                            <p>Description of point 2</p>
                        </div>
                    </div>
                </div>
            `;
            break;
    }
    
    if (editor) {
        editor.setData(templateContent);
    } else {
        document.getElementById('editableContent').innerHTML = templateContent;
    }
    
    document.getElementById('saveBtn').disabled = false;
    showNotification(`Applied ${templateType} template!`);
}

// Text Formatting
function formatText(style) {
    if (editor) {
        editor.execute(style);
    } else {
        document.execCommand(style, false, null);
    }
}

function formatHeading(heading) {
    if (editor) {
        editor.execute('heading', { value: heading });
    } else {
        document.execCommand('formatBlock', false, `<${heading}>`);
    }
}

function changeColor(color) {
    if (editor) {
        editor.execute('fontColor', { value: color });
    } else {
        document.execCommand('foreColor', false, color);
    }
}

// Export Functions
function downloadPPT() {
    showLoading('Generating PowerPoint presentation...');
    
    fetch('generate-ppt.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            client_name: 'Ms. Mukta Dutta Tomar',
            period: 'January - March 2026'
        })
    })
    .then(response => response.blob())
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `Portfolio_Review_${new Date().toISOString().slice(0,10)}.pptx`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        showNotification('PPT download started!');
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error generating PPT!', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

function downloadPDF() {
    showLoading('Generating PDF document...');
    
    fetch('generate-pdf.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            page_numbers: Array.from({length: 23}, (_, i) => i + 1)
        })
    })
    .then(response => response.blob())
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `Portfolio_Report_${new Date().toISOString().slice(0,10)}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        showNotification('PDF download started!');
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error generating PDF!', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

// Additional Helper Functions
function previewPage() {
    const content = editor ? editor.getData() : document.getElementById('editableContent').innerHTML;
    const previewWindow = window.open('', '_blank');
    previewWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Preview - Slide ${currentPage}</title>
            <style>
                body { font-family: Arial; padding: 40px; }
                .section-title { color: #2E75B6; font-size: 28px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            ${content}
        </body>
        </html>
    `);
}

function resetPage() {
    if (confirm('Reset this slide to default? All changes will be lost.')) {
        fetch(`get_default_page.php?page=${currentPage}`)
            .then(response => response.json())
            .then(data => {
                if (editor) {
                    editor.setData(data.content);
                } else {
                    document.getElementById('editableContent').innerHTML = data.content;
                }
                document.getElementById('saveBtn').disabled = false;
                showNotification('Slide reset to default!');
            });
    }
}

function saveProperties() {
    const title = document.getElementById('pageTitle').value;
    const bgColor = document.getElementById('pageBgColor').value;
    const fontSize = document.getElementById('pageFontSize').value;
    const tags = document.getElementById('pageTags').value;
    const notes = document.getElementById('pageNotes').value;
    
    // Apply background color
    document.getElementById('pageContent').style.backgroundColor = bgColor;
    
    // Apply font size
    document.getElementById('editableContent').style.fontSize = fontSize + 'px';
    
    showNotification('Properties saved!');
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Enable image drag & drop
    const dropZone = document.getElementById('pageContent');
    
    if (dropZone) {
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                uploadImage(files[0]);
            }
        });
    }
    
    // Auto-save indicator
    setInterval(() => {
        const now = new Date();
        document.getElementById('autoSaveStatus').textContent = 
            `Auto-save: ${now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
    }, 60000);
});
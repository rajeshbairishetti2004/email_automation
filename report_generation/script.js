// script.js - PPT-ONLY VERSION

// ========== LOADING FUNCTIONS ==========
function showLoading(message = 'Processing...', details = '') {
    let loading = document.getElementById('loadingOverlay');
    if (!loading) {
        loading = document.createElement('div');
        loading.id = 'loadingOverlay';
        loading.className = 'loading-overlay';
        loading.innerHTML = `
            <div class="loading-content">
                <i class="fas fa-file-powerpoint fa-spin fa-3x" style="color: #C43E1C;"></i>
                <p id="loadingMessage" style="margin-top: 20px; font-size: 18px; color: #333;">${message}</p>
                <div id="progressDetails" style="margin-top: 10px; font-size: 14px; color: #666;">${details}</div>
            </div>
        `;
        document.body.appendChild(loading);
        const style = document.createElement('style');
        style.textContent = `
            .loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.85);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            }
            .loading-content {
                background: white;
                padding: 40px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 0 30px rgba(0,0,0,0.3);
                min-width: 300px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    } else {
        loading.style.display = 'flex';
        document.getElementById('loadingMessage').textContent = message;
        document.getElementById('progressDetails').innerHTML = details;
    }
}

function hideLoading() {
    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.style.display = 'none';
    }
}

// ========== SUCCESS MESSAGE ==========
function showSuccess(message) {
    const successDiv = document.createElement('div');
    successDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #28a745;
        color: white;
        padding: 15px 20px;
        border-radius: 5px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 9999;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;
    successDiv.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 20px;"></i>
            <div>
                <strong>Success!</strong>
                <div style="font-size: 14px; margin-top: 5px;">${message}</div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" 
            <div>
                <strong>Success!</strong>
                <div style="font-size: 14px; margin-top: 5px;">${message}</div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: none; border: none; color: white; cursor: pointer; margin-left: auto;">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(successDiv);
    
    // Add animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (successDiv.parentElement) {
            successDiv.remove();
        }
    }, 5000);
}


// ========== PPT DOWNLOAD ==========
function downloadPPT() {
    showLoading('Creating PowerPoint', 'Generating 25 slides...');
    
    try {
        // Create a progress indicator
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += 2;
            document.getElementById('progressDetails').innerHTML = 
                `Progress: ${progress}% - Creating slides...`;
            if (progress >= 98) clearInterval(progressInterval);
        }, 100);
        
        // Create form for POST request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate-ppt.php';
        form.target = '_blank';
        form.style.display = 'none';
        
        // Add CSRF token if needed
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo bin2hex(random_bytes(32)); ?>';
        
        // Add data
        const clientInput = document.createElement('input');
        clientInput.type = 'hidden';
        clientInput.name = 'client_name';
        clientInput.value = 'Ms. Mukta Dutta Tomar';
        
        const periodInput = document.createElement('input');
        periodInput.type = 'hidden';
        periodInput.name = 'period';
        periodInput.value = 'January - March 2026';
        
        form.appendChild(csrfToken);
        form.appendChild(clientInput);
        form.appendChild(periodInput);
        document.body.appendChild(form);
        
        // Submit form
        form.submit();
        
        // Clean up
        setTimeout(() => {
            document.body.removeChild(form);
            clearInterval(progressInterval);
            hideLoading();
            
            // Show success message
            showSuccess(
                '✅ PowerPoint is being generated!\n\n' +
                'File: Portfolio_Review_' + new Date().toISOString().split('T')[0] + '.pptx\n\n' +
                'If download doesn\'t start:\n' +
                '1. Check browser downloads\n' +
                '2. Look for .pptx file\n' +
                '3. Allow downloads if prompted'
            );
        }, 3000);
        
    } catch (error) {
        hideLoading();
        console.error('PPT Generation Error:', error);
        alert('❌ Error generating PPT:\n' + error.message);
    }
}

// ========== PAGE NAVIGATION ==========
function nextPage() {
    const current = parseInt(document.getElementById('currentPage').textContent);
    if (current < 23) {
        showLoading('Loading page ' + (current + 1), 'Please wait...');
        setTimeout(() => {
            window.location.href = `?page=${current + 1}`;
        }, 300);
    }
}

function prevPage() {
    const current = parseInt(document.getElementById('currentPage').textContent);
    if (current > 1) {
        showLoading('Loading page ' + (current - 1), 'Please wait...');
        setTimeout(() => {
            window.location.href = `?page=${current - 1}`;
        }, 300);
    }
}

function goToPage(page) {
    if (page >= 1 && page <= 23) {
        showLoading('Loading page ' + page, 'Please wait...');
        setTimeout(() => {
            window.location.href = `?page=${page}`;
        }, 300);
    }
}


// ========== HELP MODAL FUNCTIONS ==========
function showHelp() {
    const modal = document.getElementById('helpModal');
    if (modal) {
        modal.style.display = 'block';
    } else {
        // Create modal if it doesn't exist
        const helpModal = document.createElement('div');
        helpModal.id = 'helpModal';
        helpModal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        `;
        
        helpModal.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 10px; max-width: 600px; max-height: 80vh; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; color: #2E75B6;"><i class="fas fa-question-circle"></i> Help & Instructions</h2>
                    <button onclick="closeHelp()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
                </div>
                <div id="helpContent"></div>
            </div>
        `;
        
        document.body.appendChild(helpModal);
        setTimeout(() => {
            document.getElementById('helpContent').innerHTML = getHelpContent();
        }, 100);
    }
}

function closeHelp() {
    const modal = document.getElementById('helpModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function getHelpContent() {
    return `
        <div style="margin-bottom: 20px;">
            
            <p><strong>Troubleshooting:</strong></p>
            <ul style="padding-left: 20px;">
                <li><strong>If popup blocked:</strong> Allow popups for this site</li>
                <li><strong>If pages missing:</strong> Check all page files exist (page1.php to page23.php)</li>
                <li><strong>If content cut off:</strong> Set margins to "None" in print dialog</li>
            </ul>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h3 style="color: #1F4E79;">🔄 Page Navigation</h3>
            <ul style="padding-left: 20px;">
                <li><kbd>←</kbd> <kbd>→</kbd> Arrow keys to navigate</li>
                <li><kbd>Home</kbd> Go to first page</li>
                <li><kbd>End</kbd> Go to last page</li>
                <li>Click page numbers at bottom</li>
            </ul>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h3 style="color: #1F4E79;">⚡ Keyboard Shortcuts</h3>
            <ul style="padding-left: 20px;">
                <li><kbd>Ctrl</kbd> + <kbd>P</kbd> - Print current page</li>

                <li><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>P</kbd> - PowerPoint</li>
                <li><kbd>F1</kbd> - Show help</li>
                <li><kbd>Esc</kbd> - Close help/modal</li>
            </ul>
        </div>
        
        <div>
            <h3 style="color: #1F4E79;">📊 System Status</h3>
            <p id="systemStatusInfo">Checking system status...</p>
            <button onclick="createAllTemplates()" style="
                background: #2E75B6;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                margin-top: 10px;
            ">
                <i class="fas fa-plus"></i> Create Missing Page Templates
            </button>
        </div>
    `;
}

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    console.log('Portfolio Review System Loaded');
    console.log('Available pages: 1-23');
    
    // Check for print parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('print')) {
        showLoading('Opening print dialog', 'Please wait...');
        setTimeout(() => {
            window.print();
            hideLoading();
        }, 1000);
    }
    
    // Update dates
    const now = new Date();
    document.querySelectorAll('[data-current-date]').forEach(el => {
        el.textContent = now.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // Navigation
        if (e.key === 'ArrowRight' || e.key === ' ') {
            e.preventDefault();
            nextPage();
        }
        else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            prevPage();
        }
        else if (e.key === 'Home') {
            e.preventDefault();
            goToPage(1);
        }
        else if (e.key === 'End') {
            e.preventDefault();
            goToPage(23);
        }
        
        // Downloads with Ctrl/Cmd + Shift
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'P') {
            e.preventDefault();
            downloadPPT();
        }

        else if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
        
        // Help
        else if (e.key === 'F1' || (e.key === '?' && e.shiftKey)) {
            e.preventDefault();
            showHelp();
        }
        
        // Close help with Escape
        else if (e.key === 'Escape') {
            closeHelp();
        }
    });
    
    // Add image to all pages (if needed)
    addImageToAllPages();
    
    // Show system status
    setTimeout(() => {
        const missingPages = window.missingPages || [];
        if (missingPages.length > 0) {
            console.warn('Missing page files:', missingPages);
            
            // Also show a user-friendly warning
            if (missingPages.length > 5) {
                const warning = document.createElement('div');
                warning.style.cssText = `
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: #f8d7da;
                    color: #721c24;
                    padding: 15px;
                    border-radius: 5px;
                    border: 1px solid #f5c6cb;
                    z-index: 1000;
                    max-width: 300px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                `;
                warning.innerHTML = `
                    <strong>⚠️ Missing Pages</strong>
                    <p>${missingPages.length} page files are missing</p>
                    <button onclick="showHelp()" style="
                        background: #dc3545;
                        color: white;
                        border: none;
                        padding: 5px 10px;
                        border-radius: 3px;
                        cursor: pointer;
                        margin-top: 5px;
                    ">
                        Show Help
                    </button>
                    <button onclick="this.parentElement.remove()" style="
                        background: none;
                        border: none;
                        color: #721c24;
                        float: right;
                        cursor: pointer;
                    ">
                        ×
                    </button>
                `;
                document.body.appendChild(warning);
            }
        } else {
            console.log('✅ All 23 page files are present');
            
            // Show success indicator
            const successIndicator = document.createElement('div');
            successIndicator.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: #d4edda;
                color: #155724;
                padding: 10px 15px;
                border-radius: 5px;
                border: 1px solid #c3e6cb;
                z-index: 1000;
                animation: fadeIn 0.3s;
            `;
            successIndicator.innerHTML = `
                <span>✅ All pages ready</span>
                <button onclick="this.parentElement.remove()" style="
                    background: none;
                    border: none;
                    color: #155724;
                    margin-left: 10px;
                    cursor: pointer;
                ">
                    ×
                </button>
            `;
            
            // Add fade animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            `;
            document.head.appendChild(style);
            
            document.body.appendChild(successIndicator);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (successIndicator.parentElement) {
                    successIndicator.remove();
                }
            }, 5000);
        }
    }, 500);
});

// ========== IMAGE TO PAGES ==========
function addImageToAllPages() {
    const pages = document.querySelectorAll('.page-content');
    
    pages.forEach(page => {
        // Check if this is page 1 (skip logo)
        const pageText = page.textContent || '';
        const isPage1 = pageText.includes('Page 1') || 
                       pageText.includes('Executive Summary') ||
                       (page.querySelector('h1') && page.querySelector('h1').textContent.includes('1'));
        
        if (!isPage1) {
            // Add logo to other pages
            if (!page.querySelector('.global-image')) {
                const img = document.createElement('img');
                img.src = 'image.png';
                img.className = 'global-image';
                img.alt = 'Finance Doctor Logo';
                img.style.cssText = `
                    position: absolute;
                    top: 10px;
                    right: 10px;
                    max-height: 50px;
                    max-width: 150px;
                    z-index: 100;
                    opacity: 0.9;
                `;
                page.style.position = 'relative';
                page.appendChild(img);
            }
        }
    });
}

// ========== ERROR HANDLING ==========
window.onerror = function(message, source, lineno, colno, error) {
    console.error('Global Error:', { message, source, lineno, colno, error });
    
    // Show user-friendly error
    const errorDiv = document.createElement('div');
    errorDiv.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 5px;
        border: 1px solid #f5c6cb;
        z-index: 9999;
        max-width: 500px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    errorDiv.innerHTML = `
        <strong>⚠️ Application Error</strong>
        <p>Please refresh the page and try again.</p>
        <button onclick="this.parentElement.remove()" style="
            background: none;
            border: none;
            color: #721c24;
            float: right;
            cursor: pointer;
        ">
            ×
        </button>
    `;
    document.body.appendChild(errorDiv);
    
    return false;
};

// ========== PRINT ENHANCEMENTS ==========
function enhancePrint() {
    // Add print-specific styles
    const printStyle = document.createElement('style');
    printStyle.media = 'print';
    printStyle.textContent = `
        body { margin: 0; padding: 0; }
        .no-print, nav, .controls, .navigation, .footer-nav { display: none !important; }
        .page-content { padding: 20mm; }
    `;
    document.head.appendChild(printStyle);
}

// ========== CREATE ALL TEMPLATES ==========
function createAllTemplates() {
    const missingPages = window.missingPages || [];
    if (missingPages.length === 0) {
        alert('No missing pages found!');
        return;
    }
    
    showLoading('Creating page templates', `Creating ${missingPages.length} missing pages...`);
    
    let createdCount = 0;
    
    // Create each page sequentially
    missingPages.forEach((pageNum, index) => {
        setTimeout(() => {
            fetch('create_page.php?page=' + pageNum)
                .then(response => {
                    if (response.ok) {
                        createdCount++;
                        const progress = Math.round((createdCount / missingPages.length) * 100);
                        document.getElementById('progressDetails').innerHTML = 
                            `Created ${createdCount} of ${missingPages.length} pages (${progress}%)`;
                        
                        if (createdCount === missingPages.length) {
                            setTimeout(() => {
                                hideLoading();
                                showSuccess(`✅ Created ${missingPages.length} page templates!`);
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            }, 1000);
                        }
                    }
                })
                .catch(error => {
                    console.error('Failed to create page ' + pageNum, error);
                });
        }, index * 300); // Stagger requests to avoid server overload
    });
}
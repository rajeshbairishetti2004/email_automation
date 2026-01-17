// script.js - COMPLETE WORKING VERSION

// ========== LOADING FUNCTIONS ==========
function showLoading(message = 'Processing...') {
    // Create loading overlay if doesn't exist
    let loading = document.getElementById('loadingOverlay');
    if (!loading) {
        loading = document.createElement('div');
        loading.id = 'loadingOverlay';
        loading.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            color: white;
            font-family: Arial, sans-serif;
        `;
        
        const spinner = document.createElement('div');
        spinner.style.cssText = `
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #2E75B6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        `;
        
        const messageDiv = document.createElement('div');
        messageDiv.id = 'loadingMessage';
        messageDiv.style.fontSize = '18px';
        messageDiv.textContent = message;
        
        loading.appendChild(spinner);
        loading.appendChild(messageDiv);
        document.body.appendChild(loading);
        
        // Add animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    } else {
        loading.style.display = 'flex';
        document.getElementById('loadingMessage').textContent = message;
    }
}

function hideLoading() {
    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.style.display = 'none';
    }
}

// ========== PDF DOWNLOAD ==========
function downloadFullPDF() {
    showLoading('Opening PDF generator...');
    
    // Create a new window for PDF generation
    const pdfUrl = 'pdf-all-in-one.php';
    const pdfWindow = window.open(pdfUrl, '_blank');
    
    // Check if popup was blocked
    setTimeout(() => {
        if (!pdfWindow || pdfWindow.closed) {
            hideLoading();
            alert('⚠️ Popup was blocked!\n\nPlease:\n1. Allow popups for this site\n2. Click the PDF button again\n3. Or go directly to: ' + pdfUrl);
        } else {
            // Hide loading after PDF window opens
            setTimeout(hideLoading, 1000);
        }
    }, 1000);
}

// ========== PPT DOWNLOAD ==========
function downloadPPT() {
    showLoading('Creating PowerPoint presentation...');
    
    try {
        // Create form
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate-ppt.php';
        form.target = '_blank';
        form.style.display = 'none';
        
        // Add data
        const clientInput = document.createElement('input');
        clientInput.type = 'hidden';
        clientInput.name = 'client_name';
        clientInput.value = 'Ms. Mukta Dutta Tomar';
        
        const periodInput = document.createElement('input');
        periodInput.type = 'hidden';
        periodInput.name = 'period';
        periodInput.value = 'January - March 2026';
        
        form.appendChild(clientInput);
        form.appendChild(periodInput);
        document.body.appendChild(form);
        
        // Submit form
        form.submit();
        
        // Remove form after submission
        setTimeout(() => {
            document.body.removeChild(form);
            hideLoading();
            
            // Show success message
            setTimeout(() => {
                alert('✅ PowerPoint is being generated!\n\n' +
                      'File: Portfolio_Review_' + new Date().toISOString().split('T')[0] + '.pptx\n\n' +
                      'If download doesn\'t start:\n' +
                      '1. Check browser downloads\n' +
                      '2. Look for the .pptx file\n' +
                      '3. Try clicking the PPT button again');
            }, 500);
        }, 500);
        
    } catch (error) {
        hideLoading();
        alert('❌ Error generating PPT: ' + error.message + '\n\nPlease check console for details.');
        console.error('PPT Generation Error:', error);
    }
}

// ========== PAGE NAVIGATION ==========
function nextPage() {
    const current = parseInt(document.getElementById('currentPage').textContent);
    if (current < 23) {
        window.location.href = `?page=${current + 1}`;
    }
}

function prevPage() {
    const current = parseInt(document.getElementById('currentPage').textContent);
    if (current > 1) {
        window.location.href = `?page=${current - 1}`;
    }
}

function goToPage(page) {
    if (page >= 1 && page <= 23) {
        window.location.href = `?page=${page}`;
    }
}

// ========== CURRENT PAGE PDF ==========
function downloadCurrentPDF() {
    const currentPage = document.getElementById('currentPage').textContent;
    window.open(`?page=${currentPage}&print=1`, '_blank');
}

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    console.log('Portfolio Review System Loaded');
    
    // Check for print parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('print')) {
        setTimeout(() => window.print(), 500);
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
        if (e.key === 'ArrowRight' || e.key === ' ') nextPage();
        else if (e.key === 'ArrowLeft') prevPage();
        else if (e.key === 'Home') goToPage(1);
        else if (e.key === 'End') goToPage(23);
        
        // Downloads
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'P') {
            e.preventDefault();
            downloadPPT();
        }
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'F') {
            e.preventDefault();
            downloadFullPDF();
        }
    });
    
    // Add image to all pages
    addImageToAllPages();
});

// ========== IMAGE TO PAGES ==========
function addImageToAllPages() {
    const pages = document.querySelectorAll('.page-content');
    
    pages.forEach(page => {
        // Try to find page/slide number text
        const pageText = page.textContent || '';
        const pageNumberMatch = pageText.match(/Page\s*1\b|Slide\s*1\b|^Page 1$|^Slide 1$/mi);
        const hasPage1Title = page.querySelector('h1, h2, h3')?.textContent.includes('Page 1');
        
        // Skip if it's page 1
        if (pageNumberMatch || hasPage1Title) {
            // Remove image if exists
            const img = page.querySelector('.global-image');
            if (img) img.remove();
            return;
        }
        
        // Add image to other pages
        if (!page.querySelector('.global-image')) {
            const img = document.createElement('img');
            img.src = 'image.png';
            img.className = 'global-image';
            img.style.cssText = `
                position: absolute;
                top: 10px;
                right: 10px; 
                max-height: 60px;
                z-index: 1000;
                opacity: 0.8;
            `;
            page.style.position = 'relative';
            page.appendChild(img);
        }
    });
}
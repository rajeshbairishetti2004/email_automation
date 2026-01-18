<?php
// pdf-all-in-one.php - UPDATED VERSION
ob_start();

// Get all page content first
$pagesContent = [];
for ($i = 1; $i <= 23; $i++) {
    if (file_exists("page{$i}.php")) {
        ob_start();
        include "page{$i}.php";
        $content = ob_get_clean();
        $pagesContent[$i] = $content;
    } else {
        $pagesContent[$i] = "<div class='content-box'><h3>Page $i</h3><p>Content not found for page $i</p></div>";
    }
}

// Set proper headers for PDF
if (isset($_GET['force_download'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Portfolio_Review_Complete.pdf"');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Portfolio Review - Complete PDF</title>
    <style>
        /* Print styles - IMPROVED */
        @media print {
            body {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .pdf-page-container {
                page-break-after: always;
                page-break-inside: avoid;
                break-after: page;
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 20mm;
                box-sizing: border-box;
                position: relative;
            }
            
            /* Ensure content fits on A4 */
            @page {
                size: A4;
                margin: 20mm;
                marks: crop;
            }
            
            /* Remove screen-only elements */
            .screen-only {
                display: none;
            }
        }
        
        /* Screen styles */
        @media screen {
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 20px;
                background: #f5f5f5;
            }
            
            .instructions {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                max-width: 800px;
                margin: 0 auto 30px auto;
                text-align: center;
            }
            
            .button {
                background: #2E75B6;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                margin: 10px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s;
            }
            
            .button:hover {
                background: #1F4E79;
                transform: translateY(-2px);
            }
            
            .button.secondary {
                background: #666;
            }
            
            .pdf-page-container {
                background: white;
                padding: 40px;
                margin: 20px auto;
                width: 210mm; /* A4 width */
                min-height: 297mm; /* A4 height */
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                position: relative;
                page-break-inside: avoid;
            }
            
            .watermark {
                position: absolute;
                opacity: 0.05;
                font-size: 100px;
                transform: rotate(-45deg);
                top: 40%;
                left: 20%;
                z-index: -1;
                color: #2E75B6;
            }
        }
        
        /* Common styles for both print and screen */
        h1 { 
            color: #2E75B6; 
            margin-top: 0;
            font-size: 24pt;
        }
        
        h2 { 
            color: #1F4E79;
            font-size: 18pt;
        }
        
        h3 { 
            color: #333;
            font-size: 16pt;
        }
        
        .content-box {
            background: #f8f9fa;
            border-left: 4px solid #FFC000;
            padding: 15px;
            margin: 15px 0;
            page-break-inside: avoid;
        }
        
        .highlight {
            background: #FFF2CC;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            page-break-inside: avoid;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2E75B6;
        }
        
        .page-footer {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 10pt;
        }
        
        /* Table styles for printing */
        table {
            width: 100%;
            border-collapse: collapse;
            page-break-inside: avoid;
        }
        
        table, th, td {
            border: 1px solid #ddd;
        }
        
        th, td {
            padding: 8px;
            text-align: left;
        }
        
        /* Image styles */
        img {
            max-width: 100%;
            height: auto;
            page-break-inside: avoid;
        }
        
        /* Avoid breaking inside important elements */
        .no-break {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <!-- Instructions -->
    <div class="instructions no-print">
        <h1>📄 Complete Portfolio Review PDF</h1>
        <p><strong>Client:</strong> Ms. Mukta Dutta Tomar</p>
        <p><strong>Period:</strong> January - March 2026</p>
        <p><strong>Total Pages:</strong> 23</p>
        
        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left;">
            <h3>📋 How to Save as PDF:</h3>
            <ol>
                <li>Click <strong>"Generate PDF"</strong> button below</li>
                <li>In print dialog, choose <strong>"Save as PDF"</strong></li>
                <li>Set margins to <strong>"Default"</strong> or <strong>"Minimum"</strong></li>
                <li>Check <strong>"Background graphics"</strong> option</li>
                <li>Select save location and click <strong>"Save"</strong></li>
                <li>All 23 pages will be included automatically</li>
            </ol>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-top: 15px;">
                <h4>🛠️ Troubleshooting:</h4>
                <ul>
                    <li><strong>If pages are cut off:</strong> Set margins to "None" in print dialog</li>
                    <li><strong>If missing content:</strong> Check "Background graphics" option</li>
                    <li><strong>If stuck:</strong> Use <kbd>Ctrl</kbd> + <kbd>P</kbd> directly</li>
                </ul>
            </div>
            
            <p style="margin-top: 15px;"><strong>💡 Keyboard Shortcut:</strong> Press <kbd>Ctrl</kbd> + <kbd>P</kbd> (Windows) or <kbd>Cmd</kbd> + <kbd>P</kbd> (Mac) anytime</p>
        </div>
        
        <div>
            <button class="button" onclick="generatePDF()">
                <span style="font-size: 18px;">📄</span> Generate PDF (23 Pages)
            </button>
            <button class="button secondary" onclick="testPrint()">
                <span style="font-size: 18px;">🖨️</span> Test Print
            </button>
            <button class="button secondary" onclick="window.close()">
                <span style="font-size: 18px;">❌</span> Close Window
            </button>
        </div>
        
        <div id="statusMessage" style="margin-top: 20px; padding: 10px; border-radius: 5px; display: none;"></div>
    </div>
    
    <?php
    // Display all pages
    foreach ($pagesContent as $pageNum => $content) {
        echo '<div class="pdf-page-container">';
        echo '<div class="watermark">Finance Doctor</div>';
        echo '<div class="page-header">';
        echo '<h2>Portfolio Review - Page ' . $pageNum . '</h2>';
        echo '<p><strong>Ms. Mukta Dutta Tomar</strong> | January - March 2026</p>';
        echo '</div>';
        
        echo $content;
        
        echo '<div class="page-footer">';
        echo 'Page ' . $pageNum . ' of 23 • Finance Doctor Wealth Management • Generated: ' . date('F d, Y');
        echo '</div>';
        echo '</div>';
    }
    ?>
    
    <!-- Final instructions -->
    <div class="instructions no-print" style="margin-top: 40px;">
        <div id="completionMessage" style="display: none;">
            <h2 style="color: #28a745;">✅ PDF Ready!</h2>
            <p>Your 23-page PDF document has been prepared.</p>
            <p>Check your downloads folder for <strong>Portfolio_Review_Complete.pdf</strong></p>
            <button class="button" onclick="generatePDF()">
                <span style="font-size: 18px;">📄</span> Generate Another Copy
            </button>
        </div>
    </div>
    
    <script>
        // Improved PDF generation
        function generatePDF() {
            const statusDiv = document.getElementById('statusMessage');
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px;">
                    <h4>⏳ Preparing PDF...</h4>
                    <p>Generating 23-page document. This may take a moment.</p>
                    <div class="progress-bar" style="height: 5px; background: #ccc; border-radius: 3px; margin: 10px 0;">
                        <div id="progress" style="height: 100%; width: 0%; background: #28a745; transition: width 2s;"></div>
                    </div>
                    <p><small>Please wait for the print dialog to appear...</small></p>
                </div>
            `;
            
            // Simulate progress
            let progress = 0;
            const interval = setInterval(() => {
                progress += 5;
                const progressBar = document.getElementById('progress');
                if (progressBar) progressBar.style.width = progress + '%';
                if (progress >= 100) clearInterval(interval);
            }, 100);
            
            // Add print stylesheet
            const printStyle = document.createElement('style');
            printStyle.textContent = `
                @media print {
                    body > div:not(.pdf-page-container) {
                        display: none !important;
                    }
                    .pdf-page-container {
                        display: block !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                }
            `;
            document.head.appendChild(printStyle);
            
            // Open print dialog after delay
            setTimeout(() => {
                window.print();
                
                // Show completion message
                setTimeout(() => {
                    clearInterval(interval);
                    statusDiv.innerHTML = `
                        <div style="background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px;">
                            <h4>✅ PDF Generation Complete!</h4>
                            <p>If the print dialog didn't appear:</p>
                            <ol style="text-align: left;">
                                <li>Press <kbd>Ctrl</kbd> + <kbd>P</kbd> manually</li>
                                <li>Choose "Save as PDF" as printer</li>
                                <li>Click Save</li>
                            </ol>
                        </div>
                    `;
                    
                    document.getElementById('completionMessage').style.display = 'block';
                }, 3000);
            }, 2000);
            
            // Prevent default print behavior issues
            window.addEventListener('beforeprint', () => {
                document.querySelectorAll('.no-print').forEach(el => {
                    el.style.display = 'none';
                });
            });
            
            window.addEventListener('afterprint', () => {
                document.querySelectorAll('.no-print').forEach(el => {
                    el.style.display = 'block';
                });
            });
        }
        
        // Test print function
        function testPrint() {
            const testWindow = window.open('', '_blank');
            testWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Print Test</title>
                    <style>
                        body { font-family: Arial; padding: 20px; }
                        h1 { color: #2E75B6; }
                    </style>
                </head>
                <body>
                    <h1>✅ Print Test Successful</h1>
                    <p>If you can see this, printing works correctly.</p>
                    <p>Now try the full PDF generation.</p>
                    <script>
                        window.print();
                        setTimeout(() => window.close(), 1000);
                    <\/script>
                </body>
                </html>
            `);
            testWindow.document.close();
        }
        
        // Auto-generate if parameter is set
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('auto') || urlParams.has('force_download')) {
            setTimeout(generatePDF, 1000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                generatePDF();
            }
            if (e.key === 'Escape') {
                window.close();
            }
        });
        
        // Show page count
        document.addEventListener('DOMContentLoaded', () => {
            console.log('Total PDF Pages:', document.querySelectorAll('.pdf-page-container').length);
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
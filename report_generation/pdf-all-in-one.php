<?php
// pdf-all-in-one.php - WORKING VERSION
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Portfolio Review - Complete PDF</title>
    <style>
        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            
            .pdf-page-container, .pdf-page-container * {
                visibility: visible;
            }
            
            .pdf-page-container {
                position: absolute;
                left: 0;
                top: 0;
                page-break-after: always;
                width: 100%;
            }
            
            .no-print {
                display: none !important;
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
            }
            
            .button:hover {
                background: #1F4E79;
            }
            
            .button.secondary {
                background: #666;
            }
            
            .pdf-page-container {
                background: white;
                padding: 40px;
                margin: 20px auto;
                max-width: 800px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                position: relative;
                min-height: 1123px; /* A4 height in pixels */
            }
            
            .pdf-page-container::after {
                content: "";
                display: block;
                height: 0;
                clear: both;
            }
        }
        
        /* Common styles */
        h1 { color: #2E75B6; margin-top: 0; }
        h2 { color: #1F4E79; }
        h3 { color: #333; }
        
        .content-box {
            background: #f8f9fa;
            border-left: 4px solid #FFC000;
            padding: 15px;
            margin: 15px 0;
        }
        
        .highlight {
            background: #FFF2CC;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2E75B6;
        }
        
        .page-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <!-- Instructions -->
    <div class="instructions no-print">
        <h1>📄 Complete Portfolio Review PDF</h1>
        <p><strong>Client:</strong> Ms. Mukta Dutta Tomar</p>
        <p><strong>Period:</strong> January - March 2026</p>
        
        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left;">
            <h3>📋 How to Save as PDF:</h3>
            <ol>
                <li>Click <strong>"Generate PDF"</strong> button below</li>
                <li>In print dialog, choose <strong>"Save as PDF"</strong></li>
                <li>Select save location and click <strong>"Save"</strong></li>
                <li>All 23 pages will be included automatically</li>
            </ol>
            <p><strong>💡 Tip:</strong> Press <kbd>Ctrl</kbd> + <kbd>P</kbd> (Windows) or <kbd>Cmd</kbd> + <kbd>P</kbd> (Mac) anytime</p>
        </div>
        
        <button class="button" onclick="generatePDF()">
            <span style="font-size: 18px;">📄</span> Generate PDF
        </button>
        <button class="button secondary" onclick="window.close()">
            <span style="font-size: 18px;">❌</span> Close
        </button>
    </div>
    
    <?php
    // Include all pages
    for ($i = 1; $i <= 23; $i++) {
        echo '<div class="pdf-page-container">';
        echo '<div class="page-header">';
        echo '<h2>Page ' . $i . ' - Portfolio Review</h2>';
        echo '<p>Ms. Mukta Dutta Tomar | January - March 2026</p>';
        echo '</div>';
        
        if (file_exists("page{$i}.php")) {
            include "page{$i}.php";
        } else {
            echo '<div class="content-box">';
            echo '<h3>Page ' . $i . '</h3>';
            echo '<p>Content will appear here. Make sure page' . $i . '.php exists.</p>';
            echo '</div>';
        }
        
        echo '<div class="page-footer">';
        echo 'Page ' . $i . ' of 23 • Finance Doctor Wealth Management • Generated: ' . date('F d, Y');
        echo '</div>';
        echo '</div>';
    }
    ?>
    
    <!-- Final instructions -->
    <div class="instructions no-print" style="margin-top: 40px;">
        <p><strong>✅ All pages loaded!</strong> Ready to generate PDF.</p>
        <button class="button" onclick="generatePDF()">
            <span style="font-size: 18px;">📄</span> Generate PDF Now
        </button>
    </div>
    
    <script>
        function generatePDF() {
            // Show loading message
            const instructions = document.querySelector('.instructions');
            if (instructions) {
                instructions.innerHTML = `
                    <div style="text-align: center; padding: 20px;">
                        <h2 style="color: #2E75B6;">⏳ Generating PDF...</h2>
                        <p>Please wait while we prepare your document.</p>
                        <p>The print dialog will open in a moment.</p>
                        <div style="margin: 20px; font-size: 14px; color: #666;">
                            <p>If print dialog doesn't open:</p>
                            <p>1. Check popup blocker</p>
                            <p>2. Press <kbd>Ctrl</kbd> + <kbd>P</kbd></p>
                            <p>3. Choose "Save as PDF"</p>
                        </div>
                    </div>
                `;
            }
            
            // Wait a bit then print
            setTimeout(() => {
                window.print();
                
                // Restore button after 3 seconds
                setTimeout(() => {
                    if (instructions) {
                        instructions.innerHTML = `
                            <h2>✅ PDF Generated!</h2>
                            <p>Your PDF should have been saved.</p>
                            <button class="button" onclick="generatePDF()">
                                <span style="font-size: 18px;">📄</span> Generate Another Copy
                            </button>
                            <button class="button secondary" onclick="window.close()">
                                <span style="font-size: 18px;">✔️</span> Done
                            </button>
                        `;
                    }
                }, 3000);
            }, 1000);
        }
        
        // Auto-generate if auto=1 parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('auto')) {
            setTimeout(generatePDF, 500);
        }
        
        // Keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                generatePDF();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
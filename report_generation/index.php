<?php
// report_generator/index.php
$current_page = isset($_GET['page']) ? max(1, min(23, intval($_GET['page']))) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quarterly Portfolio Review - Jan-Mar 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="client-info">
                <div class="client-avatar">MT</div>
                <div>
                    <h1>Ms. Mukta Dutta Tomar</h1>
                    <p>Quarterly Portfolio Review | Jan - Mar 2026</p>
                </div>
            </div>
            
            <div class="controls">
                <button class="btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn" onclick="downloadFullPDF()">
                    <i class="fas fa-file-pdf"></i> Full PDF
                </button>
                <button class="btn" onclick="downloadCurrentPDF()">
                    <i class="fas fa-file-pdf"></i> Current
                </button>
                <!-- ADD PPT BUTTON DIRECTLY HERE -->
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
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        
        <!-- Page Content -->
        <div class="page-container">
            <div class="page-content">
                <?php include "page{$current_page}.php"; ?>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer-nav">
            <div class="page-thumbnails">
                <?php for($i = 1; $i <= 23; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="thumbnail <?php if($i == $current_page) echo 'active'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    
    <!-- Loading overlay -->
    <div id="pdfLoading" class="pdf-loading">
        <div style="text-align: center; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.3);">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2E75B6;"></i>
            <p class="loading-message" style="margin-top: 20px; font-size: 18px; color: #333;">Processing...</p>
            <p style="font-size: 14px; margin-top: 10px; color: #666;">This may take a few moments</p>
        </div>
    </div>
    
    <!-- Load script -->
    <script src="script.js"></script>
</body>
</html>
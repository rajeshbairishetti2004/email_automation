<?php
// navbar.php - enhanced version
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug mode - set to true to see session data
$debug_navbar = false;
if ($debug_navbar) {
    echo "<!-- NAVBAR SESSION DEBUG START -->\n";
    echo "<!-- Session ID: " . session_id() . " -->\n";
    echo "<!-- Session Data: " . json_encode($_SESSION) . " -->\n";
    echo "<!-- NAVBAR SESSION DEBUG END -->\n";
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['REQUEST_URI'];

// Detect if we're in report_generation folder
$isReportGeneration = strpos($currentPath, 'report_generation') !== false;

// Check if we're in a subdirectory (report_generation folder)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$isInReportGenFolder = strpos($scriptPath, 'report_generation/') !== false;

// Function to get correct path based on current location
function getNavLink($file) {
    global $isInReportGenFolder;
    return $isInReportGenFolder ? '../' . $file : $file;
}

// Try multiple sources for username with better debugging
$navUser = 'User'; // Default

// Check session variables in order of preference
if (!empty($_SESSION['username'])) {
    $navUser = $_SESSION['username'];
} elseif (!empty($_SESSION['user_name'])) {
    $navUser = $_SESSION['user_name'];
} elseif (!empty($_SESSION['name'])) {
    $navUser = $_SESSION['name'];
}

// For designation
$userDesignation = $_SESSION['designation'] ?? '';
?>

<nav class="navbar">
    <div class="nav-left">
        <div class="top-bar">
            <!-- Correct image path based on location -->
            <img src="<?php echo $isInReportGenFolder ? '../image.png' : 'image.png'; ?>" alt="Logo">
            <!-- Correct home link based on location -->
            <a href="<?php echo $isInReportGenFolder ? '../upload.php' : 'upload.php'; ?>" class="nav-brand">Finance Doctor</a>
        </div>
        <div class="nav-links">
            <!-- Use getNavLink() function for proper paths -->
            <a href="<?php echo getNavLink('upload.php'); ?>" 
               class="<?= ($currentPage === 'upload.php') ? 'active' : '' ?>">
                Dashboard
            </a>
            <a href="<?php echo getNavLink('view_saved_reports.php'); ?>" 
               class="<?= ($currentPage === 'view_saved_reports.php') ? 'active' : '' ?>">
                All Reports
            </a>
            <a href="<?php echo getNavLink('bulk_import.php'); ?>" 
               class="<?= ($currentPage === 'bulk_import.php') ? 'active' : '' ?>">
                Bulk Allocate
            </a>
            <a href="<?php echo getNavLink('allocation_log.php'); ?>" 
               class="<?= ($currentPage === 'allocation_log.php') ? 'active' : '' ?>">
                Allocation Log
            </a>
            <!-- Report Generation link: if we're already in report_generation folder, link to index.php, otherwise link to report_generation/index.php -->
            <a href="<?php echo $isInReportGenFolder ? 'index.php' : 'report_generation/index.php'; ?>" 
               class="<?= $isReportGeneration ? 'active' : '' ?>">
                Report Generation
            </a>

            <a href="<?php echo getNavLink('schemes.php'); ?>" 
               class="<?= ($currentPage === 'schemes.php') ? 'active' : '' ?>">
                Manage Schemes
            </a>

            
        </div>
    </div>
    <div class="nav-user" style="position:relative;">
        <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
        <div id="profileDropdown" class="profile-dropdown" style="display:none; position:absolute; right:0; top:45px; background:#fff; border:1px solid #eee; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.07); min-width:180px; z-index:1001;">
            <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding: 8px 12px 5px;">
                <?= htmlspecialchars($userDesignation) ?>
            </div>
            <a href="<?php echo getNavLink('profile.php'); ?>" 
               style="display:block; padding:8px 12px; text-align:right; color:#0288D1; font-weight:600;">
                My Profile
            </a>
            <a href="<?php echo getNavLink('logout.php'); ?>" 
               class="logout-link" 
               style="display:block; padding:8px 12px; text-align:right;">
                Logout
            </a>
        </div>
    </div>
    <script>
        // Simple dropdown toggle
        const profilePic = document.getElementById('profilePic');
        const profileDropdown = document.getElementById('profileDropdown');
        document.addEventListener('click', function(e) {
            if (profilePic.contains(e.target)) {
                profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
            } else if (!profileDropdown.contains(e.target)) {
                profileDropdown.style.display = 'none';
            }
        });
    </script>
</nav>
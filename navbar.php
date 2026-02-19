<?php
// navbar.php - enhanced version
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';

$currentUser = getCurrentUser();
$isAdmin = isset($currentUser['designation'])
    && $currentUser['designation'] === 'Admin';


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
function getNavLink($file)
{
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
            <?php if ($isAdmin): ?>

                <a href="<?php echo getNavLink('bulk_import.php'); ?>"
                    class="<?= ($currentPage === 'bulk_import.php') ? 'active' : '' ?>">
                    Bulk Allocate
                </a>

                <a href="<?php echo getNavLink('allocation_log.php'); ?>"
                    class="<?= ($currentPage === 'allocation_log.php') ? 'active' : '' ?>">
                    Allocation Log
                </a>

                <a href="<?php echo getNavLink('schemes.php'); ?>"
                    class="<?= ($currentPage === 'schemes.php') ? 'active' : '' ?>">
                    Manage Schemes
                </a>

            <?php endif; ?>
            <!-- Report Generation link: if we're already in report_generation folder, link to index.php, otherwise link to report_generation/index.php -->
            <a href="<?php echo $isInReportGenFolder ? 'coming_soon.php' : 'report_generation/coming_soon.php'; ?>"
                class="<?= $isReportGeneration ? 'active' : '' ?>">
                Report Generation
            </a>


        </div>
    </div>
    <!-- Replace the nav-user div section in your navbar.php with this: -->

    <!-- Replace the nav-user div section in your navbar.php with this: -->

    <div class="nav-user" id="navUserBtn">
        <span id="profilePic">👤 <?php echo htmlspecialchars($navUser); ?></span>
        <div id="profileDropdown" class="profile-dropdown">
            <div class="dropdown-header">
                <?php echo htmlspecialchars($userDesignation ?: 'User'); ?>
            </div>
            <a href="profile.php" class="dropdown-item">
                <span class="item-icon">👤</span>
                My Profile
            </a>
            <?php if ($isAdmin): ?>
                <a href="<?php echo getNavLink('profile_management.php'); ?>" class="dropdown-item">
                    <span class="item-icon">⚙️</span>
                    Profile Management
                </a>
            <?php endif; ?>

            <a href="logout.php" class="dropdown-item logout-item">
                <span class="item-icon">🚪</span>
                Logout
            </a>
        </div>
    </div>

    <script>
        const navUserBtn = document.getElementById('navUserBtn');
        const profileDropdown = document.getElementById('profileDropdown');
        let hideTimeout;

        navUserBtn.addEventListener('mouseenter', function() {
            clearTimeout(hideTimeout);
            profileDropdown.style.display = 'block';
            setTimeout(() => {
                profileDropdown.style.opacity = '1';
                profileDropdown.style.transform = 'translateY(0)';
            }, 10);
        });

        navUserBtn.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                profileDropdown.style.opacity = '0';
                profileDropdown.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    profileDropdown.style.display = 'none';
                }, 200);
            }, 100);
        });

        profileDropdown.addEventListener('mouseenter', function() {
            clearTimeout(hideTimeout);
        });

        profileDropdown.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                profileDropdown.style.opacity = '0';
                profileDropdown.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    profileDropdown.style.display = 'none';
                }, 200);
            }, 100);
        });
    </script>
</nav>
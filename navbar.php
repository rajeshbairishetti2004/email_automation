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

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Side Drawer -->
<aside class="sidebar-drawer" id="sidebarDrawer">
    <div class="sidebar-header">
        <img src="<?php echo $isInReportGenFolder ? '../image.png' : 'image.png'; ?>" alt="Logo" class="sidebar-logo">
        <span class="sidebar-brand">Finance Doctor</span>
        <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
        </button>
    </div>

    <div class="sidebar-user-card">
        <div class="sidebar-avatar">👤</div>
        <div class="sidebar-user-info">
            <div class="sidebar-username"><?php echo htmlspecialchars($navUser); ?></div>
            <div class="sidebar-role"><?php echo htmlspecialchars($userDesignation ?: 'User'); ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Navigation</div>

        <a href="<?php echo getNavLink('upload.php'); ?>" class="sidebar-link <?= ($currentPage === 'upload.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" />
                    <rect x="14" y="3" width="7" height="7" />
                    <rect x="14" y="14" width="7" height="7" />
                    <rect x="3" y="14" width="7" height="7" />
                </svg>
            </span>
            Dashboard
        </a>

        <a href="<?php echo getNavLink('view_saved_reports.php'); ?>" class="sidebar-link <?= ($currentPage === 'view_saved_reports.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14 2 14 8 20 8" />
                    <line x1="16" y1="13" x2="8" y2="13" />
                    <line x1="16" y1="17" x2="8" y2="17" />
                    <polyline points="10 9 9 9 8 9" />
                </svg>
            </span>
            All Reports
        </a>

        <a href="<?php echo getNavLink('customer_list.php'); ?>" class="sidebar-link <?= ($currentPage === 'customer_list.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            </span>
            Customer List
        </a>

        <a href="<?php echo $isInReportGenFolder ? 'coming_soon.php' : 'report_generation/coming_soon.php'; ?>" class="sidebar-link <?= $isReportGeneration ? 'active' : '' ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                </svg>
            </span>
            Report Generation
        </a>

        <?php if ($isAdmin): ?>
            <div class="sidebar-section-label" style="margin-top: 16px;">Admin</div>

            <a href="<?php echo getNavLink('bulk_import.php'); ?>" class="sidebar-link <?= ($currentPage === 'bulk_import.php') ? 'active' : '' ?>">
                <span class="sidebar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="17 8 12 3 7 8" />
                        <line x1="12" y1="3" x2="12" y2="15" />
                    </svg>
                </span>
                Bulk Allocate
            </a>

            <a href="<?php echo getNavLink('allocation_log.php'); ?>" class="sidebar-link <?= ($currentPage === 'allocation_log.php') ? 'active' : '' ?>">
                <span class="sidebar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                    </svg>
                </span>
                Allocation Log
            </a>

            <a href="<?php echo getNavLink('schemes.php'); ?>" class="sidebar-link <?= ($currentPage === 'schemes.php') ? 'active' : '' ?>">
                <span class="sidebar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M20 12h2M2 12h2M17.66 17.66l-1.41-1.41M6.34 17.66l1.41-1.41" />
                    </svg>
                </span>
                Manage Schemes
            </a>

            <a href="<?php echo getNavLink('profile_management.php'); ?>" class="sidebar-link <?= ($currentPage === 'profile_management.php') ? 'active' : '' ?>">
                <span class="sidebar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                    </svg>
                </span>
                Profile Management
            </a>
             <a href="<?php echo getNavLink('followup_mails.php'); ?>" class="sidebar-link <?= ($currentPage === 'profile_management.php') ? 'active' : '' ?>">
                <span class="sidebar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                    </svg>
                </span>
                Send Emails
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="profile.php" class="sidebar-footer-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </svg>
            My Profile
        </a>
        <a href="logout.php" class="sidebar-footer-link logout">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" />
                <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            Logout
        </a>
    </div>
</aside>

<nav class="navbar">
    <div class="nav-left">
        <!-- Hamburger Button -->
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Open menu">
            <span class="ham-line"></span>
            <span class="ham-line"></span>
            <span class="ham-line"></span>
        </button>

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

            <a href="<?php echo getNavLink('customer_list.php'); ?>"
                class="<?= ($currentPage === 'customer_list.php') ? 'active' : '' ?>">
                Customer List
            </a>

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
        // ===== PROFILE DROPDOWN =====
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

        // ===== SIDEBAR DRAWER =====
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const sidebarDrawer = document.getElementById('sidebarDrawer');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarClose = document.getElementById('sidebarClose');

        function openSidebar() {
            sidebarDrawer.classList.add('open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            hamburgerBtn.classList.add('active');

            // Stagger animate sidebar links
            const links = sidebarDrawer.querySelectorAll('.sidebar-link');
            links.forEach((link, i) => {
                link.style.transitionDelay = `${0.05 + i * 0.04}s`;
                link.classList.add('visible');
            });
        }

        function closeSidebar() {
            sidebarDrawer.classList.remove('open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
            hamburgerBtn.classList.remove('active');

            const links = sidebarDrawer.querySelectorAll('.sidebar-link');
            links.forEach(link => {
                link.style.transitionDelay = '0s';
                link.classList.remove('visible');
            });
        }

        hamburgerBtn.addEventListener('click', () => {
            sidebarDrawer.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        sidebarOverlay.addEventListener('click', closeSidebar);
        sidebarClose.addEventListener('click', closeSidebar);

        // Close on Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && sidebarDrawer.classList.contains('open')) {
                closeSidebar();
            }
        });
    </script>
</nav>
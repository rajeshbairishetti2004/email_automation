<?php
// navbar.php - Include this file in all your pages

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Debug session variables
// echo "<!-- DEBUG SESSION: ";
// print_r($_SESSION);
// echo " -->";

// Try multiple sources for username
$navUser = $_SESSION['username'] ??
    $_SESSION['user_name'] ??
    ($currentUser['username'] ??
        ($currentUser['name'] ??
            'User'));

$userDesignation = $_SESSION['designation'] ??
    ($currentUser['designation'] ??
        '');

require_once 'auth.php';

$currentUser = getCurrentUser();
$isAdmin = isset($currentUser['username'])
    && strtolower($currentUser['username']) === 'admin';


?>

<style>
    /* Navbar Styles Only */
    .navbar {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #f5f8f946;
        backdrop-filter: blur(12px);
        border-bottom: 2px solid #e2e8f0;
        padding: 25px 25px 25px 25px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        margin: 0;
        border-bottom: 0.5px solid #19bdf9;
    }

    .nav-left {
        display: flex;
        align-items: center;
        gap: 24px;
    }

    .top-bar {
        display: flex;
        align-items: center;
        padding: 12px 28px;
        background: rgba(148, 227, 241, 0.319);
        margin-bottom: 18px;
        margin-right: 470px;
    }

    .top-bar img {
        height: 40px;
        vertical-align: middle;
        margin-right: 10px;
    }

    .nav-brand {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: 0.01em;
        text-decoration: none;
        border-bottom: none;
    }

    .nav-links {
        display: flex;
        gap: 24px;
        align-items: center;
    }

    .nav-links a {
        text-decoration: none;
        color: #64748b;
        font-weight: 600;
        font-size: 14px;
        padding: 8px 0;
        border-bottom: 2px solid transparent;
        transition: all 0.2s ease;
    }

    .nav-links a:hover {
        color: #0288D1;
    }

    .nav-links a.active {
        color: #0288D1;
        border-bottom: 2px solid #0288D1;
    }

    .nav-user {
        color: #0288D1 !important;
        font-weight: 600;
        font-size: 1rem;
        padding: 0 24px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        transition: color 0.2s, background 0.2s, box-shadow 0.2s;
        background: #e3f2fd;
        border-radius: 24px;
        box-shadow: 0 2px 8px rgba(41, 182, 246, 0.10);
        position: relative;
        min-height: 40px;
        border: 2px solid #b3e5fc;
    }

    .nav-user:hover {
        color: #fff !important;
        background: #4FC3F7;
        box-shadow: 0 4px 16px rgba(41, 182, 246, 0.18);
        border-color: #4FC3F7;
    }

    .profile-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: 50px;
        width: 240px;
        /* Increased width */
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
        padding: 10px 0;
        z-index: 100;
        animation: fadeSlide 0.2s ease-in-out;
        border: 1px solid #e3f2fd;
    }

    /* Smooth animation */
    @keyframes fadeSlide {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .profile-dropdown div {
        font-size: 13px;
        color: #64748b;
        padding: 10px 18px;
        font-weight: 600;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 6px;
    }

    .profile-dropdown a {
        display: block;
        padding: 12px 18px;
        font-size: 14px;
        color: #0f172a;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .profile-dropdown a:hover {
        background: #e3f2fd;
        color: #0288D1;
        padding-left: 22px;
    }

    .profile-dropdown a.logout-link {
        color: #e53935;
    }

    .profile-dropdown a.logout-link:hover {
        background: #ffebee;
        color: #b71c1c;
    }
</style>

<nav class="navbar">
    <div class="nav-left">
        <div class="top-bar">
            <img src="image.png" alt="Logo">
            <a href="upload.php" class="nav-brand">Finance Doctor</a>
        </div>
        <div class="nav-links">
            <a href="upload.php" class="<?= ($currentPage === 'upload.php') ? 'active' : '' ?>">Dashboard</a>
            <a href="view_saved_reports.php" class="<?= ($currentPage === 'view_saved_reports.php') ? 'active' : '' ?>">All Reports</a>
            <?php if ($isAdmin): ?>
                <a href="bulk_import.php"
                    class="<?= ($currentPage === 'bulk_import.php') ? 'active' : '' ?>">
                    Bulk Allocate
                </a>

                <a href="allocation_log.php"
                    class="<?= ($currentPage === 'allocation_log.php') ? 'active' : '' ?>">
                    Allocation Log
                </a>
            <?php endif; ?>

        </div>
    </div>
    <div class="nav-user" id="navUserBtn" style="position:relative;">
        <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
        <div id="profileDropdown" class="profile-dropdown" style="display:none; position:absolute; right:0; top:36px; background:#fff; border:1px solid #eee; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.07); min-width:180px; z-index:100;">
            <div>
                <?= htmlspecialchars($userDesignation) ?>
            </div>

            <a href="profile.php" style="display:block; padding:8px 12px; text-align:right; color:#0288D1; font-weight:600;">My Profile</a>
            <?php if ($isAdmin): ?>
                <a href="profile_management.php">Profile Management</a>
            <?php endif; ?>
            <a href="logout.php" class="logout-link" style="display:block; padding:8px 12px; text-align:right;">Logout</a>
        </div>
    </div>
    <script>
        // Show dropdown on hover, hide on mouseleave
        const navUserBtn = document.getElementById('navUserBtn');
        const profileDropdown = document.getElementById('profileDropdown');
        navUserBtn.addEventListener('mouseenter', function() {
            profileDropdown.style.display = 'block';
        });
        navUserBtn.addEventListener('mouseleave', function() {
            profileDropdown.style.display = 'none';
        });
    </script>
</nav>
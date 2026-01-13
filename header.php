<?php
// header.php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Doctor</title>
    <link rel="stylesheet" href="public/css/upload.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<style>
    body {
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f9f9f9;
    }
    .navbar {
        background-color: #ffffff;
        border-bottom: 1px solid #e0e0e0;
        padding: 10px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .nav-left {
        display: flex;
        align-items: center;
    }
    .nav-brand {
        font-size: 24px;
        font-weight: 800;
        color: #0288D1;
        text-decoration: none;
        margin-left: 10px;
    }
    .nav-links a {
        margin-left: 20px;
        text-decoration: none;
        color: #333333;
        font-weight: 600;
    }
    .nav-links a.active {
        color: #0288D1;
        border-bottom: 2px solid #0288D1;
        padding-bottom: 4px;
    }
    .nav-user {
        font-size: 16px;
        color: #333333;
    }
    .top-bar img {
        height: 40px;
        vertical-align: middle;
    }
    .top-bar .brand-text {
        font-size: 24px;
        font-weight: 800;
        color: #0288D1;
        vertical-align: middle;
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
</style>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="top-bar">
                <img src="image.png" alt="Logo">
                <a href="upload.php" class="nav-brand">Finance Doctor</a>
            </div>
            
        </div>
        <!-- Rest of your navbar code -->
         <div class="nav-user" style="position:relative;">
            <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
            <div id="profileDropdown" class="profile-dropdown" style="display:none; position:absolute; right:0; top:36px; background:#fff; border:1px solid #eee; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.07); min-width:180px; z-index:100;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding: 8px 12px 5px;">
                    <?= htmlspecialchars($userDesignation) ?>
                </div>
                <a href="profile.php" style="display:block; padding:8px 12px; text-align:right; color:#0288D1; font-weight:600;">My Profile</a>
                <a href="logout.php" class="logout-link" style="display:block; padding:8px 12px; text-align:right;">Logout</a>
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





        

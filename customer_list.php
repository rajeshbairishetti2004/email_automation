<?php
// customer_list.php
// Purpose: Show customer list page with navbar + access control only

require_once 'auth.php';
requireAuth(); // 🔐 ensures logged-in users only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Navbar styles -->
    <link rel="stylesheet" href="public/css/navbar.css">

    <!-- Page-specific styles (optional) -->
    <link rel="stylesheet" href="public/css/customer_list.css">

    <!-- Fonts / icons if needed later -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
</head>
<body>

<?php require_once 'navbar.php'; ?>

<div class="page-container">


 
</div>

</body>
</html>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../database.php';

$client_id = $_GET['client_id'] ?? null;
$clientInfo = $client_id ? getClientInfo($client_id) : [];
$client_name = htmlspecialchars($clientInfo['client_name'] ?? 'Client');

$month = date('n');
$year = date('Y');
$quarterMap = [
    1 => 'Jan - Mar',
    2 => 'Apr - Jun',
    3 => 'Jul - Sep',
    4 => 'Oct - Dec'
];
$quarter = $quarterMap[ceil($month / 3)] . ' ' . $year;
?>

<style>
html, body {
    margin: 0;
    padding: 0;
    overflow: hidden;
}
.slide {
    width: 100%;
    height: 100vh;
    background: #fff;
    font-family: 'Segoe UI', Arial, sans-serif;
    box-sizing: border-box;
    padding: clamp(20px, 5vw, 80px) clamp(20px, 6vw, 60px);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* lines */
.line-thick { height: 6px; background:#4DB6AC; }
.line-thin { height: 1px; background:#4DB6AC; }

/* text */
.client-name {
    text-align:center;
    font-size: clamp(24px, 5vw, 52px);
    font-weight:700;
    color:#4F7DF3;
    margin-bottom: 20px;
}

.subtitle-row {
    display:flex;
    justify-content:center;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
}

.subtitle {
    font-size: clamp(16px, 4vw, 36px);
    font-weight:600;
    color:#4F7DF3;
}

.gold-line {
    height:4px;
    width:70px;
    background:#B8A46A;
}

.quarter {
    text-align:center;
    font-size: clamp(14px, 3vw, 22px);
    color:#4F7DF3;
    margin-top:10px;
}

/* logo */
.logo {
    display:flex;
    justify-content:flex-end;
}

.logo img {
    width: clamp(70px, 15vw, 140px);
}

/* small screens */
@media (max-width:600px){
    .gold-line{display:none;}
    .logo{justify-content:center;}
}
</style>

<div class="slide">

    <!-- TOP -->
    <div>
        <div class="line-thick" style="margin-top:10px;"></div>
         <div class="line-thin" style="margin-top:2vh;"></div>
        
    </div>

    <!-- CENTER -->
    <div>
        <div class="client-name"><?php echo $client_name; ?></div>
        <div class="subtitle-row">
            <div class="gold-line"></div>
            <div class="subtitle">Quarterly Portfolio Review</div>
            <div class="gold-line"></div>
        </div>
        <div class="quarter"><?php echo $quarter; ?></div>
    </div>

    <!-- BOTTOM -->
    <div>
        <div class="line-thin" style="margin-top:4vh;"></div>
        <div class="line-thick" style="margin-top:10px;"></div>
        <div class="logo" style="margin-top:3vh;">
            <img src="/email_automation/image.png" alt="Finance Doctor">
        </div>
    </div>

</div>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';


$client_id = $_GET['client_id'] ?? null;
$clientInfo = $client_id ? getClientInfo($client_id) : [];

$client_name = htmlspecialchars($clientInfo['client_name'] ?? 'Client');

// Quarter logic
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

<div style="
    width: 100%;
    height: 100%;
    background: #ffffff;
    font-family: 'Segoe UI', Arial, sans-serif;
    position: relative;
    box-sizing: border-box;
    padding: 80px 60px;
">

    <!-- Top teal line -->
    <div style="height: 6px; background: #4DB6AC; width: 100%; margin-bottom: 10px;"></div>
    <div style="height: 1px; background: #4DB6AC; width: 100%; margin-bottom: 80px;"></div>

    <!-- Client Name -->
    <div style="text-align: center;">
        <div style="
            font-size: 52px;
            font-weight: 700;
            color: #4F7DF3;
            margin-bottom: 35px;
        ">
            <?php echo $client_name; ?>
        </div>

        <!-- Subtitle with gold lines -->
        <div style="
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 12px;
        ">
            <div style="height: 4px; width: 70px; background: #B8A46A;"></div>

            <div style="
                font-size: 36px;
                font-weight: 600;
                color: #4F7DF3;
            ">
                Quarterly Portfolio Review
            </div>

            <div style="height: 4px; width: 70px; background: #B8A46A;"></div>
        </div>

        <!-- Quarter -->
        <div style="
            font-size: 22px;
            color: #4F7DF3;
            margin-bottom: 8px;
        ">
            <?php echo $quarter; ?>
        </div>
    </div>

    <!-- Bottom teal lines -->
     <div style="height: 1px; background: #4DB6AC; width: 100%; margin-top: 8px;"></div>
    <div style="height: 6px; background: #4DB6AC; width: 100%; margin-top: 10px;"></div>
    

    <!-- Logo bottom-right -->
    <div style="
    position:absolute;
    bottom:13%;
    right:60px;
">

<img 
    src="/email_automation/image.png"
    alt="Finance Doctor"
    style="
        width: 140px;
        height: auto;
        display: block;
        margin-left: 10px;
    "
>
</div>

    </div>
</div>

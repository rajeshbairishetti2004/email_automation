<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function cr($v) {
    return 'Rs.' . number_format(($v ?? 0) / 10000000, 2) . ' Cr';
}
function pct($v) {
    return number_format((float)($v ?? 0), 2) . '%';
}

/* =========================
   CLIENT ID
========================= */
$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId = (int)str_replace('CLIENT_', '', $clientKey);
if ($clientId <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client not found</div>";
    return;
}

$pdo = getDbConnection();

/* =========================
   DATA (same source as tables)
========================= */
$stmt = $pdo->prepare("SELECT total_amount, profit, xirr FROM clients WHERE id=?");
$stmt->execute([$clientId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT goal, current_amount, target_amount, goal_date, status
    FROM client_goals
    WHERE client_id=?
    ORDER BY id ASC
");
$stmt->execute([$clientId]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
html,body{
    margin:0;
    padding:0;
    width:100%;
    height:100%;
    background:#fff;
    font-family: Calibri, "Segoe UI", Arial, sans-serif;
}
.slide{
    position:relative;
    width:100%;
    height:100%;
}
.content{
    padding:80px 90px;
    box-sizing:border-box;
}
.title{
    text-align:center;
    font-size:42px;
    color:#3B73E8;
    font-weight:600;
    margin-bottom:45px;
}
.section{
    color:#0A3DBA;
    font-size:20px;
    font-weight:600;
    margin:25px 0 10px;
}

/* ===== TABLE BASE ===== */
table{
    border-collapse:collapse;
    font-size:15px;
}
td,th{
    padding:6px 10px;
    border:1px solid #dcdcdc;
    white-space:nowrap;
}

/* ===== CURRENT VALUE TABLE ===== */
.cv-table{
    width:520px;
}
.cv-table td:first-child{
    background:#3B73E8;
    color:#fff;
    font-weight:600;
    width:300px;
}
.cv-table td:last-child{
    text-align:right;
    font-weight:600;
}

/* ===== GOAL TABLE ===== */
.goal-table{
    width:760px;
    margin-top:6px;
}
.goal-table th{
    background:#3B73E8;
    color:#fff;
    font-weight:600;
    text-align:left;
}
.goal-table td{
    background:#fff;
}

/* STATUS */
.dot{
    width:12px;
    height:12px;
    border-radius:50%;
    display:inline-block;
    margin-right:6px;
}
.green{background:#2ecc71;}
.red{background:#e74c3c;}

/* FOOTER */
.footer{
    position:absolute;
    left:0;right:0;bottom:0;
    height:10px;
    background:#21B6A8;
}
.logo{
    position:absolute;
    right:40px;
    bottom:28px;
}
.logo img{
    width:120px;
}
</style>
</head>

<body>
<div class="slide">
    <div class="content">

        <!-- TITLE -->
        <div class="title">Portfolio at a glance</div>

        <!-- CURRENT PORTFOLIO VALUE -->
        <div class="section">Current Portfolio Value</div>
        <table class="cv-table">
            <tr>
                <td>Total Amount as on date</td>
                <td><?= cr($p['total_amount']) ?></td>
            </tr>
            <tr>
                <td>XIRR of all schemes</td>
                <td><?= pct($p['xirr']) ?></td>
            </tr>
            <tr>
                <td>Profit since inception</td>
                <td><?= cr($p['profit']) ?></td>
            </tr>
        </table>

        <!-- GOAL REPORT -->
        <div class="section" style="margin-top:35px;">Goal Report</div>
        <table class="goal-table">
            <tr>
                <th>Goal</th>
                <th>Current Value</th>
                <th>Target Value</th>
                <th>Target Date</th>
                <th>Status</th>
            </tr>
            <?php foreach ($goals as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['goal']) ?></td>
                <td><?= cr($g['current_amount']) ?></td>
                <td><?= cr($g['target_amount']) ?></td>
                <td><?= htmlspecialchars($g['goal_date'] ?? '-') ?></td>
                <td>
                    <span class="dot <?= ($g['status']==='On Track')?'green':'red' ?>"></span>
                    <?= htmlspecialchars($g['status']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    </div>

    <!-- LOGO -->
    <div class="logo">
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

    <!-- FOOTER -->
    <div class="footer"></div>
</div>
</body>
</html>

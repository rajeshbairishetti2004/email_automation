<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =====================================================
   CLIENT CONTEXT
===================================================== */

$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId  = (int) str_replace('CLIENT_', '', $clientKey);

if ($clientId <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client not found</div>";
    exit;
}

/* =====================================================
   FIND CLIENT PDF
===================================================== */

$clientAttachDir = __DIR__ . "/../../uploads/attachments/client_$clientId";

if (!is_dir($clientAttachDir)) {
    echo "<div style='padding:40px;color:#999;'>No attachment folder found</div>";
    exit;
}

$pdfFiles = glob($clientAttachDir . '/*.pdf');
$pdfFile  = $pdfFiles[0] ?? null;

if (!$pdfFile) {
    echo "<div style='padding:40px;color:#999;'>Investment PDF not found</div>";
    exit;
}

/* =====================================================
   GRAPH EXTRACTION (PAGE 1)
===================================================== */

$imgDir = __DIR__ . '/../extracted';
if (!is_dir($imgDir)) {
    mkdir($imgDir, 0777, true);
}

// cleanup old graphs
foreach (glob("$imgDir/client{$clientId}_graph_*.png") as $old) {
    @unlink($old);
}

$version   = time();
$imageBase = "$imgDir/client{$clientId}_graph_$version";
$imagePath = "$imageBase-1.png";

$pdftoppm = "C:\\poppler\\Library\\bin\\pdftoppm.exe";

$cmd = "\"$pdftoppm\" -png -r 340 -f 1 -l 1 -x 0 -y 600 -W 2850 -H 1100 \"$pdfFile\" \"$imageBase\"";
exec($cmd);

if (!file_exists($imagePath)) {
    echo "<div style='padding:40px;color:#999;'>Graph extraction failed</div>";
    exit;
}

$imageUrl = "/email_automation/report_generation/extracted/" . basename($imagePath);

/* =====================================================
   SNAPSHOT OCR (PYTHON)
===================================================== */

$pythonExe    = $_ENV['PYTHON_EXE'] ?? '';
$pythonScript = $_ENV['SNAPSHOT_OCR_SCRIPT'] ?? '';

if (!file_exists($pythonScript)) {
    echo "<pre>Python script not found:\n$pythonScript</pre>";
    exit;
}

$cmd    = "\"$pythonExe\" \"$pythonScript\" \"$pdfFile\" 2>&1";
$output = shell_exec($cmd);

$data = json_decode($output, true);

if (!is_array($data)) {
    echo "<pre>OCR ERROR:\n$output</pre>";
    exit;
}

/* =====================================================
   HELPERS
===================================================== */

function money($v)
{
    if ($v === null || $v === '' || $v === '-') {
        return '₹ -';
    }
    return '₹' . number_format((int)$v);
}

/* =====================================================
   SNAPSHOT DATA (FINAL & CORRECT)
===================================================== */

$snapshot = [
    'Investment (A)'                => money($data['investment']     ?? '-'),
    'Switch In (B)'                 => money($data['switch_in']      ?? '-'),
    'Switch Out (C)'                => money($data['switch_out']     ?? '-'),
    'Redemption (D)'                => money($data['redemption']     ?? '-'),
    'Div. Payout / FD Interest (E)' => money($data['div_payout']     ?? '-'),
    'Current Value (F)'             => money($data['current_value']  ?? '-'),
    'Net Gain'                      => money($data['net_gain']       ?? '-'),
];

$xirr = $data['xirr'] ?? '-';
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Investment Journey</title>

<style>
    html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}
body {
    margin: 0;
    background: #fff;
    font-family: Calibri, "Segoe UI", Arial, sans-serif;
}

.slide {
    position: relative;
    width: 100%;
    height: 100vh;   /* 👈 KEY FIX */
    overflow: hidden;
}

.content {
    padding: 25px 180px 25px 90px;
}

.title {
    text-align: center;
    font-size: 42px;
    color: #3B73E8;
    font-weight: 600;
}

.section {
    color: #0A3DBA;
    font-size: 20px;
    font-weight: 600;
    margin-top: 10px;
}

.graph-box {
    width: 100%;
    height: 320px;
    border: 1px solid #dcdcdc;
    overflow: hidden;
}

.graph-box img {
    width: 100%;
}

.snapshot-table {
    width: calc(100% - 20px);
    margin-top: 15px;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 12px;
}

.snapshot-table th,
.snapshot-table td {
    border: 1px solid #dcdcdc;
    padding: 6px 5px;
    text-align: center;
}

.snapshot-table th {
    background: #F4F6FA;
    font-weight: 600;
}

.snapshot-table td {
    font-weight: 600;
    text-align: right;
}

.logo {
    position: absolute;
    right: 40px;
    bottom: 28px;
}

.logo img {
    width: 120px;
}

.footer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 10px;
    background: #21B6A8;
}
</style>
</head>

<body>
<div class="slide">
<div class="content">

<div class="title">Investment Journey</div>

<div class="section">Portfolio Growth</div>
<div class="graph-box">
    <img src="<?= $imageUrl ?>" alt="Investment Graph">
</div>

<div class="section">Investment Snapshot</div>

<table class="snapshot-table">
<tr>
<?php foreach ($snapshot as $label => $_): ?>
    <th><?= htmlspecialchars($label) ?></th>
<?php endforeach; ?>
<th>XIRR</th>
</tr>

<tr>
<?php foreach ($snapshot as $value): ?>
    <td><?= $value ?></td>
<?php endforeach; ?>
<td><?= htmlspecialchars($xirr) ?></td>
</tr>
</table>

</div>

<div class="logo">
    <img src="/email_automation/image.png" alt="Logo">
</div>

<div class="footer"></div>
</div>
</body>
</html>
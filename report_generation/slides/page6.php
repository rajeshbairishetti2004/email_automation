<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Smalot\PdfParser\Parser;

/* =========================
   CLIENT CONTEXT
========================= */

$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId  = (int)str_replace('CLIENT_', '', $clientKey);

if ($clientId <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client not found</div>";
    return;
}

/* =========================
   FIND CLIENT PDF
========================= */
$clientAttachDir = __DIR__ . '/../../uploads/attachments/client_' . $clientId;

if (!is_dir($clientAttachDir)) {
    echo "<div style='padding:40px;color:#999;'>No attachment folder found</div>";
    return;
}

$pdfFile = null;
$parser  = new Parser();

foreach (glob($clientAttachDir . '/*.pdf') as $file) {
    try {
        $pdf  = $parser->parseFile($file);
        $text = strtolower(preg_replace('/\s+/', ' ', $pdf->getText()));

        if (
            strpos($text, 'investment journey report') !== false &&
            strpos($text, 'investment snapshot') !== false
        ) {
            $pdfFile = $file;
            break;
        }
    } catch (Throwable $e) {
    }
}

if (!$pdfFile) {
    echo "<div style='padding:40px;color:#999;'>Investment PDF not found</div>";
    return;
}

/* =========================
   HARD-CODED GRAPH EXTRACTION
   (same as crop.php)
========================= */
$imgDir = __DIR__ . '/../extracted';
if (!is_dir($imgDir)) mkdir($imgDir, 0777, true);

// remove old graph images for this client
foreach (glob($imgDir . "/client{$clientId}_graph_*.png") as $old) {
    @unlink($old);
}

$version   = time();
$imageBase = $imgDir . "/client{$clientId}_graph_$version";
$imagePath = $imageBase . "-1.png";

$pdftoppm = "C:\\poppler\\Library\\bin\\pdftoppm.exe";

/*
  VERIFIED HARD-CODED VALUES
  -------------------------
  DPI = 340
  y   = 600   → below black line
  H   = 950   → till x-axis dates
  W   = 2750
*/
$cmd = "\"$pdftoppm\" -png -r 340 -f 1 -l 1 -x 0 -y 600 -W 2750 -H 950 \"$pdfFile\" \"$imageBase\"";
exec($cmd);

if (!file_exists($imagePath)) {
    echo "<div style='padding:40px;color:#999;'>Graph extraction failed</div>";
    return;
}

/* =========================
   TABLE DATA EXTRACTION
========================= */
$pdf  = $parser->parseFile($pdfFile);
$text = preg_replace('/\s+/', ' ', $pdf->getText());

function extractVal($text, $label)
{
    if (preg_match('/' . $label . '\s*\([A-Z\-+]+\)\s*([0-9,]+)/i', $text, $m)) {
        return '₹' . number_format((int)str_replace(',', '', $m[1]));
    }
    return '-';
}

$tableData = [
    'Investment (A)'    => extractVal($text, 'Investment'),
    'Current Value (F)' => extractVal($text, 'Current Value'),
    'Net Gain'          => extractVal($text, 'Net Gain'),
];

$xirr = '-';
if (preg_match('/XIRR\s*([0-9.]+)\s*%/i', $text, $m)) {
    $xirr = number_format($m[1], 2) . '%';
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Investment Journey</title>

    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: #fff;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
        }

        .slide {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .content {
            padding: 80px 90px;
        }

        .title {
            text-align: center;
            font-size: 42px;
            color: #3B73E8;
            font-weight: 600;
        }

        .section {
            margin-top: 25px;
            color: #0A3DBA;
            font-size: 20px;
            font-weight: 600;
        }

        .graph-box {
            width: 85%;
            height: 320px;
            border: 1px solid #dcdcdc;
            overflow: hidden;
        }

        .graph-box img {
            width: 100%;
            display: block;
        }

        table {
            margin-top: 15px;
            border-collapse: collapse;
            font-size: 15px;
        }

        td {
            padding: 6px 10px;
            border: 1px solid #dcdcdc;
        }

        td:first-child {
            background: #3B73E8;
            color: #fff;
            font-weight: 600;
            width: 300px;
        }

        td:last-child {
            text-align: right;
            font-weight: 600;
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
                <?php
                $imageUrl = "/report_generation/extracted/" . basename($imagePath);
                ?>
                <img src="<?= $imageUrl ?>" alt="Investment Graph">
            </div>

            <div class="section">Investment Snapshot</div>
            <table>
                <?php foreach ($tableData as $k => $v): ?>
                    <tr>
                        <td><?= htmlspecialchars($k) ?></td>
                        <td><?= htmlspecialchars($v) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>XIRR</td>
                    <td><?= $xirr ?></td>
                </tr>
            </table>

        </div>
        <div class="footer"></div>
    </div>
</body>

</html>
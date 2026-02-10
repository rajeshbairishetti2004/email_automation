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
    echo "Client not found";
    exit;
}

/* =====================================================
   HANDLE SAVE (AJAX from index.php Save button)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $pdo = getSlidesPdo();

    $stmt = $pdo->prepare("
        INSERT INTO slide6
        (client_id, investment, switch_in, switch_out, redemption, div_payout, current_value, net_gain, xirr)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            investment     = VALUES(investment),
            switch_in      = VALUES(switch_in),
            switch_out     = VALUES(switch_out),
            redemption     = VALUES(redemption),
            div_payout     = VALUES(div_payout),
            current_value  = VALUES(current_value),
            net_gain       = VALUES(net_gain),
            xirr           = VALUES(xirr)
    ");

    $stmt->execute([
        $clientId,
        $data['investment']     ?? null,
        $data['switch_in']      ?? null,
        $data['switch_out']     ?? null,
        $data['redemption']     ?? null,
        $data['div_payout']     ?? null,
        $data['current_value']  ?? null,
        $data['net_gain']       ?? null,
        $data['xirr']           ?? null,
    ]);

    echo json_encode(['success' => true]);
    exit;
}

/* =====================================================
   FETCH SAVED SNAPSHOT (DB FIRST)
===================================================== */
$pdo = getSlidesPdo();
$stmt = $pdo->prepare("SELECT * FROM slide6 WHERE client_id = ?");
$stmt->execute([$clientId]);
$saved = $stmt->fetch(PDO::FETCH_ASSOC);

/* =====================================================
   FIND CLIENT PDF
===================================================== */
$clientAttachDir = __DIR__ . "/../../uploads/attachments/client_$clientId";
$pdfFiles = is_dir($clientAttachDir) ? glob($clientAttachDir . '/*.pdf') : [];
$pdfFile  = $pdfFiles[0] ?? null;

if (!$pdfFile) {
    echo "Investment PDF not found";
    exit;
}

/* =====================================================
   GRAPH EXTRACTION
===================================================== */
$imgDir = __DIR__ . '/../extracted';
if (!is_dir($imgDir)) mkdir($imgDir, 0777, true);

foreach (glob("$imgDir/client{$clientId}_graph_*.png") as $old) {
    @unlink($old);
}

$version   = time();
$imageBase = "$imgDir/client{$clientId}_graph_$version";
$imagePath = "$imageBase-1.png";

$pdftoppm = "C:\\poppler\\Library\\bin\\pdftoppm.exe";
exec("\"$pdftoppm\" -png -r 340 -f 1 -l 1 -x 0 -y 600 -W 2850 -H 1100 \"$pdfFile\" \"$imageBase\"");

$imageUrl = "/email_automation/report_generation/extracted/" . basename($imagePath);

/* =====================================================
   OCR SNAPSHOT (PDF FALLBACK)
===================================================== */
$pythonExe    = $_ENV['PYTHON_EXE'] ?? '';
$pythonScript = $_ENV['SNAPSHOT_OCR_SCRIPT'] ?? '';

$output = shell_exec("\"$pythonExe\" \"$pythonScript\" \"$pdfFile\"");
$data   = json_decode($output, true) ?? [];

/* =====================================================
   FINAL SNAPSHOT VALUES (DB → PDF)
===================================================== */
$fields = [
    'investment',
    'switch_in',
    'switch_out',
    'redemption',
    'div_payout',
    'current_value',
    'net_gain'
];

$snapshot = [];
foreach ($fields as $f) {
    $snapshot[$f] = $saved[$f] ?? $data[$f] ?? null;
}

$xirr = $saved['xirr'] ?? $data['xirr'] ?? '-';

function money($v) {
    if ($v === null || $v === '') return '₹ -';
    return '₹' . number_format((int)$v);
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Investment Journey</title>

<style>
body { margin:0; font-family:Calibri, Arial; }
.slide { height:100vh; position:relative; }
.content { padding:25px 180px 25px 90px; }
.title { text-align:center; font-size:42px; color:#3B73E8; }
.section { font-size:20px; color:#0A3DBA; margin-top:10px; }
.graph-box { height:320px; border:1px solid #ddd; overflow:hidden; }
.snapshot-table { width:100%; border-collapse:collapse; margin-top:15px; }
.snapshot-table th, .snapshot-table td {
    border:1px solid #ddd;
    padding:6px;
    font-size:12px;
}
.snapshot-table th { background:#F4F6FA; }
.snapshot-table td { text-align:right; font-weight:600; }
.edit-input { display:none; width:100%; text-align:right; }
.footer { position:absolute; bottom:0; height:10px; width:100%; background:#21B6A8; }
</style>
</head>

<body>
<div class="slide">
<div class="content">

<div class="title">Investment Journey</div>

<div class="section">Portfolio Growth</div>
<div class="graph-box">
    <img src="<?= $imageUrl ?>" style="width:100%">
</div>

<div class="section">Investment Snapshot</div>

<table class="snapshot-table">
<tr>
    <th>Investment (A)</th>
    <th>Switch In (B)</th>
    <th>Switch Out (C)</th>
    <th>Redemption (D)</th>
    <th>Div / FD (E)</th>
    <th>Current Value (F)</th>
    <th>Net Gain</th>
    <th>XIRR</th>
</tr>
<tr>
<?php foreach ($snapshot as $k => $v): ?>
<td>
    <span class="view"><?= money($v) ?></span>
    <input class="edit-input" data-key="<?= $k ?>" type="number" value="<?= htmlspecialchars($v) ?>">
</td>
<?php endforeach; ?>
<td>
    <span class="view"><?= htmlspecialchars($xirr) ?></span>
    <input class="edit-input" data-key="xirr" type="text" value="<?= htmlspecialchars($xirr) ?>">
</td>
</tr>
</table>

</div>
<div class="footer"></div>
</div>

<script>
function enableEdit() {
    document.querySelectorAll('.view').forEach(v => v.style.display='none');
    document.querySelectorAll('.edit-input').forEach(i => i.style.display='block');
}

function saveSlide() {
    const payload = {};
    document.querySelectorAll('.edit-input').forEach(i => {
        payload[i.dataset.key] = i.value;
    });

    fetch('slides/page6.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(res=>{
        if(res.success){
            alert('Investment Snapshot Saved');
            location.reload();
        } else {
            alert('Save failed');
        }
    });
}
</script>
</body>
</html>

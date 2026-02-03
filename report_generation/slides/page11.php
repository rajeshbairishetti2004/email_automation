<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function showText11($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide11Template(array $data = [])
{
?>
<style>
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
}

.editable {
    cursor: pointer;
}
.editable.editing {
    background: #f4f8ff;
    border-bottom: 1px dashed #4F7DF3;
}

.slide-root {
    position: relative;
    width: 100%;
    height: 100%;
}

.slide-content {
    padding: 30px 60px;
    box-sizing: border-box;
}

.slide-title {
    text-align: center;
    color: #4F7DF3;
    font-size: 42px;
    font-weight: 600;
    margin-bottom: 30px;
}

/* TABLES */
.tables-wrap {
    display: flex;
    gap: 40px;
    justify-content: center;
}

.metrics-table {
    border-collapse: collapse;
    font-size: 16px;
    min-width: 420px;
}

.metrics-table th {
    background: #4F7DF3;
    color: #fff;
    padding: 10px;
    border: 1px solid #3b66d4;
    text-align: center;
}

.metrics-table td {
    border: 1px solid #cfd8ff;
    padding: 8px 10px;
    text-align: center;
    color: #0A3DBA;
}

.metrics-table td.label {
    font-weight: 600;
}

/* NOTE */
.note {
    margin-top: 30px;
    font-size: 15px;
    color: #0A3DBA;
}

.interpretation {
    margin-top: 30px;
    font-size: 17px;
    color: #0A3DBA;
    line-height: 1.6;
}

/* BRANDING */
.slide-logo {
    position: absolute;
    right: 40px;
    bottom: 28px;
}
.slide-logo img {
    width: 130px;
}

.slide-footer-bar {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 10px;
    background: #4DB6AC;
}
</style>

<div class="slide-root">
<div class="slide-content">

<h1 class="slide-title">Performance &amp; Risk Metrics</h1>

<div class="tables-wrap">

    <!-- LEFT TABLE -->
    <table class="metrics-table">
        <thead>
        <tr>
            <th>Period</th>
            <th>Portfolio Return<br>XIRR</th>
            <th>Benchmark Nifty 50<br>XIRR</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="label">3 Year</td>
            <td class="editable" data-f="xirr_3y_portfolio">
                <?= showText11($data['xirr_3y_portfolio'], '15.49%') ?>
            </td>
            <td class="editable" data-f="xirr_3y_benchmark">
                <?= showText11($data['xirr_3y_benchmark'], '14.57%') ?>
            </td>
        </tr>
        <tr>
            <td class="label">FY 2025-26 to date</td>
            <td class="editable" data-f="xirr_fy_portfolio">
                <?= showText11($data['xirr_fy_portfolio'], '14.82%*') ?>
            </td>
            <td class="editable" data-f="xirr_fy_benchmark">
                <?= showText11($data['xirr_fy_benchmark'], '16.43%*') ?>
            </td>
        </tr>
        </tbody>
    </table>

    <!-- RIGHT TABLE -->
    <table class="metrics-table" style="min-width:320px">
        <thead>
        <tr>
            <th>Metric</th>
            <th>Portfolio Risk</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="label">Volatility / Standard Deviation</td>
            <td class="editable" data-f="volatility">
                <?= showText11($data['volatility'], '13.66') ?>
            </td>
        </tr>
        <tr>
            <td class="label">Sharpe Ratio</td>
            <td class="editable" data-f="sharpe_ratio">
                <?= showText11($data['sharpe_ratio'], '0.7') ?>
            </td>
        </tr>
        </tbody>
    </table>

</div>

<div class="note">(*) Absolute Return</div>

<div class="interpretation">
<strong>Finance Doctor’s interpretation:</strong><br>
<span class="editable" data-f="interpretation">
<?= showText11(
    $data['interpretation'],
    'You have earned superior returns with lower volatility – a strong outcome from disciplined portfolio construction. These risk measures are based on averages of 4 top portfolio holdings.'
) ?>
</span>
</div>

</div>

<div class="slide-logo">
    <img src="/email_automation/image.png" alt="Finance Doctor">
</div>
<div class="slide-footer-bar"></div>
</div>

<script>
window.enableEdit = () => {
    document.querySelectorAll('.editable').forEach(e => {
        e.contentEditable = true;
        e.classList.add('editing');
    });
};

window.saveSlide = () => {
    const form = new FormData();
    form.append('ajax', 'save');
    form.append(
        'client_id',
        '<?= $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '' ?>'
    );

    document.querySelectorAll('.editable').forEach(e => {
        form.append(e.dataset.f, e.innerText.trim());
        e.contentEditable = false;
        e.classList.remove('editing');
    });

    fetch('/report_generation/slides/page11.php', {
        method: 'POST',
        body: form
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Slide saved');
            const iframe = window.frameElement;
            if (iframe) {
                const u = new URL(iframe.src);
                u.searchParams.set('t', Date.now());
                iframe.src = u.toString();
            }
        } else {
            alert(res.error || 'Save failed');
        }
    });
};
</script>
<?php }

/* =========================
   AJAX SAVE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'save') {
    $pdo = getSlidesPdo();

    $fields = [
        'xirr_3y_portfolio','xirr_3y_benchmark',
        'xirr_fy_portfolio','xirr_fy_benchmark',
        'volatility','sharpe_ratio','interpretation'
    ];

    $client_id = $_POST['client_id'] ?? '';
    if (!$client_id) {
        echo json_encode(['success' => false, 'error' => 'Client ID missing']);
        exit;
    }

    $data = [];
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $data[$f] = ($v === '') ? null : $v;
    }

    $sql = "
    INSERT INTO slide11 (client_id," . implode(',', $fields) . ",updated_at)
    VALUES (:client_id, :" . implode(',:', $fields) . ", NOW())
    ON DUPLICATE KEY UPDATE
    " . implode(',', array_map(fn($f) => "$f=VALUES($f)", $fields)) . ",
    updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(['client_id' => $client_id], $data));

    echo json_encode(['success' => true]);
    exit;
}

/* =========================
   LOAD DATA
========================= */
$client_id = $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '';

$fields = [
    'xirr_3y_portfolio','xirr_3y_benchmark',
    'xirr_fy_portfolio','xirr_fy_benchmark',
    'volatility','sharpe_ratio','interpretation'
];

$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide11 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide11Template($data);

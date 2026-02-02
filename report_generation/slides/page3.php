<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function showText($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

function showNumber($v)
{
    return ($v === null || $v === '') ? 'XXX' : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide3Template(array $data = [])
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

.section-title {
    color: #4F7DF3;
    font-size: 24px;
    font-weight: 600;
    margin-top: 10px;
}

.bullet-list {
    margin-left: 80px;
    font-size: 18px;
    color: #0A3DBA;
    line-height: 1.8;
}

.tax-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-size: 16px;
}
.tax-table th {
    background: #4F7DF3;
    color: #fff;
    padding: 10px;
    border: 1px solid #3b66d4;
    text-align: center;
}
.tax-table td {
    border: 1px solid #cfd8ff;
    padding: 8px;
    text-align: center;
}

.note {
    margin-top: 14px;
    font-size: 18px;
    color: #0A3DBA;
}

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

<h1 class="slide-title">Impact of our recommendations</h1>

<div class="section-title">Portfolio impact</div>
<ul class="bullet-list">
    <li>
        <span class="editable" data-f="impact_point_1">
            <?= showText(
                $data['impact_point_1'],
                'Initiation global wealth allocation'
            ) ?>
        </span>
    </li>
    <li>
        <span class="editable" data-f="impact_point_2">
            <?= showText(
                $data['impact_point_2'],
                'Small diversification towards multi assets & precious metals'
            ) ?>
        </span>
    </li>
</ul>

<div class="section-title">Tax impact</div>

<table class="tax-table">
<thead>
<tr>
    <th>Scheme to be Redeemed</th>
    <th>Amount to be Redeemed</th>
    <th>Taxable Gain</th>
    <th>Tax Amount</th>
    <th>Tax Efficiency vs Normal Tax Rate</th>
    <th>Cumulative Tax FY 2025-26</th>
</tr>
</thead>
<tbody>
<tr>
    <td class="editable" data-f="scheme">
        <?= showText($data['scheme'], 'Quant Flexicap') ?>
    </td>
    <td class="editable" data-f="redeem_amount">
        <?= showNumber($data['redeem_amount']) ?>
    </td>
    <td class="editable" data-f="taxable_gain">
        <?= showNumber($data['taxable_gain']) ?>
    </td>
    <td class="editable" data-f="tax_amount">
        <?= showNumber($data['tax_amount']) ?>
    </td>
    <td class="editable" data-f="tax_efficiency">
        <?= showText($data['tax_efficiency'], '0.55% vs 12.5%') ?>
    </td>
    <td class="editable" data-f="cumulative_tax">
        <?= showText($data['cumulative_tax'], 'NIL') ?>
    </td>
</tr>
</tbody>
</table>

<div class="note">
<strong>Note:</strong>
<span class="editable" data-f="note_text">
<?= showText(
    $data['note_text'],
    'Equity LTCG is exempt up to Rs. 1.25 lakhs per financial year; gains above this are taxed at 12.5%. The proposed redemption is within this limit for FY 2025-26, hence no tax is payable if exemption unused elsewhere.'
) ?>
</span>
</div>

</div>

<div class="slide-logo">
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

    fetch('/report_generation/slides/page3.php', {
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
        'impact_point_1','impact_point_2',
        'scheme','redeem_amount','taxable_gain',
        'tax_amount','tax_efficiency','cumulative_tax',
        'note_text'
    ];

    $client_id = $_POST['client_id'] ?? '';
    if (!$client_id) {
        echo json_encode(['success' => false, 'error' => 'Client ID missing']);
        exit;
    }

    $data = [];
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $data[$f] = ($v === '' || $v === 'XXX') ? null : $v;
    }

    $sql = "
    INSERT INTO slide3 (client_id," . implode(',', $fields) . ",updated_at)
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
    'impact_point_1','impact_point_2',
    'scheme','redeem_amount','taxable_gain',
    'tax_amount','tax_efficiency','cumulative_tax',
    'note_text'
];

$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide3 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide3Template($data);

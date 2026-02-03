<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function showText21($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

function showNumber21($v)
{
    return ($v === null || $v === '') ? 'XXX' : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide21Template(array $data = [])
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
    padding: 40px 60px;
    box-sizing: border-box;
}

.slide-title {
    text-align: center;
    color: #4F7DF3;
    font-size: 42px;
    font-weight: 600;
    margin-bottom: 30px;
}

.section {
    margin-bottom: 20px;
}

.section-title {
    color: #4F7DF3;
    font-size: 22px;
    font-weight: 600;
    margin-bottom: 8px;
}

.recommendations {
    margin-left: 30px;
    font-size: 18px;
    color: #0A3DBA;
    line-height: 1.8;
}

.tax-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
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
    margin-top: 8px;
    font-size: 14px;
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

<h1 class="slide-title">Our recommendations this quarter</h1>

<div class="section">
    <div class="section-title">Recommendations</div>
    <ol class="recommendations">
        <li class="editable" data-f="rec_1">
            <?= showText21(
                $data['rec_1'],
                'Redeem Quant Flexicap Rs.10 lakhs and replace it with Parag Parikh Nasdaq 100 ETF FoF Rs.4.5 lakhs, S&P 500 ETF FoF Rs.4.5 lakhs and Rs. 1 lakh in Motilal Oswal Gold & Silver ETF FoF'
            ) ?>
        </li>
        <li class="editable" data-f="rec_2">
            <?= showText21(
                $data['rec_2'],
                'New Rs. 10 lakhs investment in Rs.6 lakhs in HDFC Multi Asset and Rs.4 lakhs in SBI Gold Savings ETF'
            ) ?>
        </li>
    </ol>
</div>

<div class="section">
    <div class="section-title">Portfolio impact</div>
    <div class="editable" data-f="portfolio_impact" style="font-size:18px;color:#0A3DBA;">
        <?= showText21(
            $data['portfolio_impact'],
            'Initiating global wealth and a small diversification towards multi assets & precious metals'
        ) ?>
    </div>
</div>

<div class="section">
    <div class="section-title">Tax impact</div>

    <table class="tax-table">
        <thead>
        <tr>
            <th>Scheme to be Redeemed</th>
            <th>Amount to be Redeemed</th>
            <th>Taxable Gain</th>
            <th>Tax Amount</th>
            <th>Tax Efficiency</th>
            <th>Cumulative Tax FY 2025-26</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="editable" data-f="scheme">
                <?= showText21($data['scheme'], 'Quant Flexicap') ?>
            </td>
            <td class="editable" data-f="redeem_amount">
                <?= showText21($data['redeem_amount'], '10.45 lakhs') ?>
            </td>
            <td class="editable" data-f="taxable_gain">
                <?= showNumber21($data['taxable_gain']) ?>
            </td>
            <td class="editable" data-f="tax_amount">
                <?= showNumber21($data['tax_amount']) ?>
            </td>
            <td class="editable" data-f="tax_efficiency">
                <?= showText21($data['tax_efficiency'], '0.55%') ?>
            </td>
            <td class="editable" data-f="cumulative_tax">
                <?= showText21($data['cumulative_tax'], 'NIL') ?>
            </td>
        </tr>
        </tbody>
    </table>

    <div class="note editable" data-f="note_text">
        <?= showText21(
            $data['note_text'],
            'Note: Equity LTCG is exempt up to Rs. 1.25 lakhs per financial year; gains above this are taxed at 12.5%. The proposed redemption is within this limit for FY 2025-26, hence no tax is payable if unused elsewhere.'
        ) ?>
    </div>
</div>

</div>

<div class="slide-logo">
    <img src="/email_automation/image.png" alt="Finance Doctor">
</div>
<div class="slide-footer-bar"></div>
</div>

<script>
window.enableEdit = () => {
    document.querySelectorAll('.editable').forEach(el => {
        el.contentEditable = true;
        el.classList.add('editing');
    });
};

window.saveSlide = () => {
    const form = new FormData();
    form.append('ajax', 'save');
    form.append(
        'client_id',
        '<?= $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '' ?>'
    );

    document.querySelectorAll('.editable').forEach(el => {
        form.append(el.dataset.f, el.innerText.trim());
        el.contentEditable = false;
        el.classList.remove('editing');
    });

    fetch('/report_generation/slides/page21.php', {
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
        'rec_1','rec_2',
        'portfolio_impact',
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
        $data[$f] = ($v === '') ? null : $v;
    }

    $sql = "
    INSERT INTO slide21 (client_id," . implode(',', $fields) . ",updated_at)
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
$data = [
    'rec_1'=>null,'rec_2'=>null,
    'portfolio_impact'=>null,
    'scheme'=>null,'redeem_amount'=>null,'taxable_gain'=>null,
    'tax_amount'=>null,'tax_efficiency'=>null,'cumulative_tax'=>null,
    'note_text'=>null
];

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide21 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide21Template($data);

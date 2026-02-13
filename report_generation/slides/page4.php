<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function showText4($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide4Template(array $data = [])
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
    margin-bottom: 20px;
}

.bullet-list {
    margin-left: 60px;
    font-size: 18px;
    color: #0A3DBA;
    line-height: 1.9;
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

<h1 class="slide-title">Rationale</h1>

<ul class="bullet-list">
    <li><span class="editable" data-f="point_1"><?= showText4($data['point_1'], 'India is expected to maintain a healthy GDP growth of 6.5–7% in 2026.') ?></span></li>
    <li><span class="editable" data-f="point_2"><?= showText4($data['point_2'], 'Inflation remains well controlled, allowing RBI to cut interest rates and inject liquidity into the system.') ?></span></li>
    <li><span class="editable" data-f="point_3"><?= showText4($data['point_3'], 'Government measures, including tax reforms, GST reductions, and labour reforms, are aimed at supporting consumption.') ?></span></li>
    <li><span class="editable" data-f="point_4"><?= showText4($data['point_4'], 'India’s external balance remains robust, providing macroeconomic stability.') ?></span></li>
    <li><span class="editable" data-f="point_5"><?= showText4($data['point_5'], 'Geopolitical uncertainties continue, with recent developments in Venezuela and potential unrest in Iran adding to global volatility.') ?></span></li>
    <li><span class="editable" data-f="point_6"><?= showText4($data['point_6'], 'Indian equities are expected to perform better in 2026 after underperforming in the previous year.') ?></span></li>
    <li><span class="editable" data-f="point_7"><?= showText4($data['point_7'], 'The Indian Rupee has depreciated by 5%, increasing the relevance of global exposure.') ?></span></li>
    <li><span class="editable" data-f="point_8"><?= showText4($data['point_8'], 'In an increasingly uncertain world, alongside AI-driven global disruption, initiating global wealth creation is prudent.') ?></span></li>
    <li><span class="editable" data-f="point_9"><?= showText4($data['point_9'], 'The GIFT City route enables USD asset exposure under Indian regulations, without direct exposure to overseas inheritance tax regimes.') ?></span></li>
    <li><span class="editable" data-f="point_10"><?= showText4($data['point_10'], 'Given heightened geopolitical risks, safe-haven assets merit higher allocation.') ?></span></li>
    <li><span class="editable" data-f="point_11"><?= showText4($data['point_11'], 'Multi-asset allocation funds are recommended as they help reduce portfolio risk without compromising return potential.') ?></span></li>
</ul>

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

    fetch('/email_automation/report_generation/slides/page4.php', {
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
        'point_1','point_2','point_3','point_4','point_5',
        'point_6','point_7','point_8','point_9','point_10','point_11'
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
    INSERT INTO slide4 (client_id," . implode(',', $fields) . ",updated_at)
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
    'point_1','point_2','point_3','point_4','point_5',
    'point_6','point_7','point_8','point_9','point_10','point_11'
];

$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide4 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide4Template($data);

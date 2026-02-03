<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText19($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide19Template(array $data = [])
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
    padding: 45px 70px;
    box-sizing: border-box;
}

.slide-title {
    text-align: center;
    color: #4F7DF3;
    font-size: 42px;
    font-weight: 600;
    margin-bottom: 35px;
}

.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 80px;
}

.section-title {
    color: #4F7DF3;
    font-size: 22px;
    font-weight: 600;
    margin-bottom: 14px;
}

.recommendations {
    margin-left: 28px;
    font-size: 18px;
    color: #0A3DBA;
    line-height: 1.9;
}

.portfolio-impact {
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

<h1 class="slide-title">Our recommendations this quarter</h1>

<div class="two-col">

    <!-- LEFT -->
    <div>
        <div class="section-title">Recommendations</div>
        <ol class="recommendations">
            <li class="editable" data-f="rec_1">
                <?= showText19(
                    $data['rec_1'],
                    'Redeem Quant Flexicap Rs.10 lakhs and replace it with Parag Parikh Nasdaq 100 ETF FoF Rs.4.5 lakhs, S&P 500 ETF FoF Rs.4.5 lakhs and Rs. 1 lakh in Motilal Oswal Gold & Silver ETF FoF'
                ) ?>
            </li>
            <li class="editable" data-f="rec_2">
                <?= showText19(
                    $data['rec_2'],
                    'New Rs. 10 lakhs investment in Rs.6 lakhs in HDFC Multi Asset and Rs.4 lakhs in SBI Gold Savings ETF'
                ) ?>
            </li>
        </ol>
    </div>

    <!-- RIGHT -->
    <div>
        <div class="section-title">Portfolio Impact</div>
        <div class="portfolio-impact editable" data-f="portfolio_impact">
            <?= showText19(
                $data['portfolio_impact'],
                'Initiating global wealth and a small diversification towards multi assets & precious metals'
            ) ?>
        </div>
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

    fetch('/email_automation/report_generation/slides/page19.php', {
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

    $fields = ['rec_1', 'rec_2', 'portfolio_impact'];

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
    INSERT INTO slide19 (client_id," . implode(',', $fields) . ",updated_at)
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
$data = ['rec_1'=>null, 'rec_2'=>null, 'portfolio_impact'=>null];

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide19 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide19Template($data);

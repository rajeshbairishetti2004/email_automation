<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText23($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide23Template(array $data = [])
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
    padding: 40px 70px;
    box-sizing: border-box;
}

.slide-title {
    text-align: center;
    color: #4F7DF3;
    font-size: 42px;
    font-weight: 600;
    margin-bottom: 30px;
}

.paragraph {
    font-size: 18px;
    line-height: 1.9;
    color: #0A3DBA;
    margin-bottom: 22px;
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

<h1 class="slide-title">Strategic & Tax -Smart Rebalancing</h1>

<div class="paragraph editable" data-f="para_1">
<?= showText23(
    $data['para_1'],
    'All rebalancing recommendations are optimized in relation to portfolio objectives, risks, market conditions and efficient taxation planning.'
) ?>
</div>

<div class="paragraph editable" data-f="para_2">
<?= showText23(
    $data['para_2'],
    'We are advocating redemption of Quant Flexicap Rs.10.40 lakhs because Quant had started on a very strong note in the year 2021, but has not been able to maintain that strong momentum during the last 1.5 years. We are suggesting reinvestment in Parag Parikh Nasdaq 100 ETF FoF Rs.4.5 lakhs and S&P 500 ETF FoF Rs.4.5 lakhs and Rs.1 lakh in Motilal Oswal Gold & Silver ETF FoF, because we want to build global wealth & precious metals. Guidance for new Rs.10 lakhs allocation is also based on the above rationale.'
) ?>
</div>

<div class="paragraph editable" data-f="para_3">
<?= showText23(
    $data['para_3'],
    'We also minimize the taxation impact while maintaining portfolio objectives. We achieve this by ranking all schemes based on tax efficiency. Such an approach enables us to change the prescribed FIFO to an engineered and more favourable LIFO for the same scheme. Many times, the target recommendation can be achieved gradually and therefore to reduce the tax, we can utilize multiple financial years. For example, some of the transactions can be done in FY 2025-26 till Mar 31, 2026 the next transaction can be done after this date and its taxation impact will be felt only in FY 2026-27. Also TCS (Tax collected at source) for the global allocation can be avoided if we keep the invested amount below Rs 10 lakhs annually.'
) ?>
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

    fetch('/email_automation/report_generation/slides/page23.php', {
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

    $fields = ['para_1', 'para_2', 'para_3'];

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
    INSERT INTO slide23 (client_id," . implode(',', $fields) . ",updated_at)
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
$data = ['para_1' => null, 'para_2' => null, 'para_3' => null];

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide23 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide23Template($data);

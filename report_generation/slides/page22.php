<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText22($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide22Template(array $data = [])
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

<h1 class="slide-title">Rationale</h1>

<div class="paragraph editable" data-f="para_1">
<?= showText22(
    $data['para_1'],
    'In the year 2026, we expect Indian GDP to maintain its healthy growth rate of 6.5–7%. Inflation is well controlled and RBI has cut interest rates aggressively and has also injected liquidity in the system. The government has tried to support the consumption with taxation reforms, GST reduction and labour reforms. Indian external balance is also robust. However, geopolitical uncertainties persist with Venezuela action being the latest one. Iran unrest could be another difficult point.'
) ?>
</div>

<div class="paragraph editable" data-f="para_2">
<?= showText22(
    $data['para_2'],
    'Indian equities are expected to perform better in 2026 after underperforming last year. Indian Rupee also depreciated by 5%. However, in a world with more uncertainties and facing AI disruption, it will be a good idea to initiate building global wealth. Now through the Gift City route, it is possible to build your USD wealth, though with Indian regulations and without any exposure to, for example, USA rules including their tough inheritance tax. Similarly, safe haven assets, given the present volatile geopolitical situation should be enhanced. Some allocation to multi asset allocation funds is also indicated since it reduces the portfolio risk without affecting the return potential.'
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

    fetch('/report_generation/slides/page22.php', {
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

    $fields = ['para_1', 'para_2'];

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
    INSERT INTO slide22 (client_id," . implode(',', $fields) . ",updated_at)
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
$data = ['para_1' => null, 'para_2' => null];

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide22 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide22Template($data);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText14($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide14Template(array $data = [])
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

.bullet-list {
    margin-left: 70px;
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

<h1 class="slide-title">Tax-Smart Rebalancing</h1>

<ul class="bullet-list">
    <li>
        <span class="editable" data-f="point_1">
            <?= showText14(
                $data['point_1'],
                'We also aim to minimize the taxation impact while maintaining portfolio objectives by ranking schemes based on tax efficiency.'
            ) ?>
        </span>
    </li>
    <li>
        <span class="editable" data-f="point_2">
            <?= showText14(
                $data['point_2'],
                'This approach allows us to move away from the prescribed FIFO method to a more favourable, engineered LIFO approach for the same scheme.'
            ) ?>
        </span>
    </li>
    <li>
        <span class="editable" data-f="point_3">
            <?= showText14(
                $data['point_3'],
                'Wherever feasible, the target recommendation can be achieved gradually across multiple financial years to reduce tax impact.'
            ) ?>
        </span>
    </li>
    <li>
        <span class="editable" data-f="point_4">
            <?= showText14(
                $data['point_4'],
                'For example, part of the transaction can be executed in FY 2025-26 (up to 31 March 2026), with the balance executed thereafter and taxed in FY 2026-27.'
            ) ?>
        </span>
    </li>
    <li>
        <span class="editable" data-f="point_5">
            <?= showText14(
                $data['point_5'],
                'TCS on global allocation can also be avoided by keeping the annual investment amount below Rs. 10 lakhs.'
            ) ?>
        </span>
    </li>
</ul>

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

    fetch('/report_generation/slides/page14.php', {
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

    $fields = ['point_1','point_2','point_3','point_4','point_5'];

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
    INSERT INTO slide14 (client_id," . implode(',', $fields) . ",updated_at)
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

$fields = ['point_1','point_2','point_3','point_4','point_5'];
$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide14 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide14Template($data);

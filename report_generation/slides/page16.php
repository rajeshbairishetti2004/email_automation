<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText16($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide16Template(array $data = [])
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
    padding: 40px 80px;
    box-sizing: border-box;
}

.slide-title {
    text-align: center;
    color: #4F7DF3;
    font-size: 42px;
    font-weight: 600;
    margin-bottom: 40px;
}

.section {
    margin-top: 20px;
    color: #0A3DBA;
    font-size: 18px;
    line-height: 1.9;
}

.section strong {
    font-weight: 600;
}

.spacer {
    height: 26px;
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

<h1 class="slide-title">Your Support Team</h1>

<div class="section">
    <strong>
        <span class="editable" data-f="rm_title">
            <?= showText16($data['rm_title'], 'Relationship Manager') ?>
        </span>
    </strong><br>

    <span class="editable" data-f="rm_name">
        <?= showText16(
            $data['rm_name'],
            'Sailesh Kumar Mulleti, Head of Relationship Team'
        ) ?>
    </span><br>

    <span class="editable" data-f="rm_phone">
        <?= showText16($data['rm_phone'], '9949700435') ?>
    </span><br>

    <span class="editable" data-f="rm_email">
        <?= showText16($data['rm_email'], 'sailesh.mulleti@financedoctor.in') ?>
    </span>
</div>

<div class="spacer"></div>

<div class="section">
    <strong>
        <span class="editable" data-f="alt_title">
            <?= showText16($data['alt_title'], 'If required, you may also contact') ?>
        </span>
    </strong><br>

    <span class="editable" data-f="alt_name">
        <?= showText16(
            $data['alt_name'],
            'Dr. Sanjiv Mehta, MD & Founder'
        ) ?>
    </span><br>

    <span class="editable" data-f="alt_email">
        <?= showText16($data['alt_email'], 'sanjivmehtadr@gmail.com') ?>
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

    fetch('/report_generation/slides/page16.php', {
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
        'rm_title','rm_name','rm_phone','rm_email',
        'alt_title','alt_name','alt_email'
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
    INSERT INTO slide16 (client_id," . implode(',', $fields) . ",updated_at)
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
    'rm_title','rm_name','rm_phone','rm_email',
    'alt_title','alt_name','alt_email'
];

$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo();
    $stmt = $pdo->prepare("SELECT * FROM slide16 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide16Template($data);

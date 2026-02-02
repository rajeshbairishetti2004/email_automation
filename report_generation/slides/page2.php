<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function show($v)
{
    return ($v === null || $v === '') ? 'X' : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide2Template(array $data = [])
{
?>
    <style>
        html,
        body {
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

        .slide-toolbar {
            position: absolute;
            top: 20px;
            right: 40px;
            z-index: 10;
        }

        .slide-toolbar button {
            padding: 6px 14px;
            margin-left: 6px;
            cursor: pointer;
        }

        .slide-root {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }


        .slide-content {
            padding: 80px 60px;
            box-sizing: border-box;
        }

        .slide-title {
            text-align: center;
            color: #4F7DF3;
            font-size: 44px;
            /* 🔥 INCREASED */
            font-weight: 600;
            margin-bottom: 40px;
            letter-spacing: 0.3px;
        }


        .slide-footer-bar {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 10px;
            background: #4DB6AC;
        }


        .slide-logo {
            position: absolute;
            right: 40px;
            bottom: 28px;
            z-index: 5;
        }

        .slide-logo img {
            width: 130px;
            height: auto;
        }
    </style>

    <div class="slide-toolbar">
        <button onclick="enableEdit()">Edit</button>
        <button onclick="saveSlide()">Save</button>
    </div>


    <div class="slide-root">
        <div class="slide-content">


            <h1 class="slide-title">
                Our recommendations this quarter
            </h1>


            <ul style="margin-left:80px;color:#0A3DBA;font-size:18px;line-height:1.8;">

                <li>
                    Redeem
                    <span class="editable" data-f="redeem_fund"><?= show($data['redeem_fund']) ?></span>
                    Rs.
                    <span class="editable" data-f="redeem_amount"><?= show($data['redeem_amount']) ?></span> lakhs
                </li>

                <li>Replace it with:
                    <ul>
                        <li>
                            <span class="editable" data-f="replace_fund_1_name"><?= show($data['replace_fund_1_name']) ?></span>
                            Rs.
                            <span class="editable" data-f="replace_fund_1_amount"><?= show($data['replace_fund_1_amount']) ?></span> lakhs
                        </li>
                        <li>
                            <span class="editable" data-f="replace_fund_2_name"><?= show($data['replace_fund_2_name']) ?></span>
                            Rs.
                            <span class="editable" data-f="replace_fund_2_amount"><?= show($data['replace_fund_2_amount']) ?></span> lakhs
                        </li>
                        <li>
                            <span class="editable" data-f="replace_fund_3_name"><?= show($data['replace_fund_3_name']) ?></span>
                            Rs.
                            <span class="editable" data-f="replace_fund_3_amount"><?= show($data['replace_fund_3_amount']) ?></span> lakhs
                        </li>
                    </ul>
                </li>

                <li>
                    New Rs.
                    <span class="editable" data-f="new_total_amount"><?= show($data['new_total_amount']) ?></span>
                    investment:
                    <ul>
                        <li>
                            <span class="editable" data-f="new_fund_1_name"><?= show($data['new_fund_1_name']) ?></span>
                            Rs.
                            <span class="editable" data-f="new_fund_1_amount"><?= show($data['new_fund_1_amount']) ?></span> lakhs
                        </li>
                        <li>
                            <span class="editable" data-f="new_fund_2_name"><?= show($data['new_fund_2_name']) ?></span>
                            Rs.
                            <span class="editable" data-f="new_fund_2_amount"><?= show($data['new_fund_2_amount']) ?></span> lakhs
                        </li>
                    </ul>
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
            form.append('client_id', '<?= $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '' ?>');

            document.querySelectorAll('.editable').forEach(e => {
                form.append(e.dataset.f, e.innerText.trim());
                e.contentEditable = false;
                e.classList.remove('editing');
            });

            fetch('/report_generation/slides/page2.php', {
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
                    } else alert(res.error || 'Save failed');
                })
                .catch(e => alert(e.message));
        };
    </script>
<?php }

/* =========================
   AJAX SAVE (SLIDES DB)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'save') {

    $pdo = getSlidesPdo(); // ✅ IMPORTANT FIX

    $fields = [
        'redeem_fund',
        'redeem_amount',
        'new_total_amount',
        'replace_fund_1_name',
        'replace_fund_1_amount',
        'replace_fund_2_name',
        'replace_fund_2_amount',
        'replace_fund_3_name',
        'replace_fund_3_amount',
        'new_fund_1_name',
        'new_fund_1_amount',
        'new_fund_2_name',
        'new_fund_2_amount'
    ];

    $client_id = $_POST['client_id'] ?? '';
    if (!$client_id) {
        echo json_encode(['success' => false, 'error' => 'Client ID missing']);
        exit;
    }

    $data = [];
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $data[$f] = ($v === '' || $v === 'X') ? null : $v;
    }

    $sql = "
    INSERT INTO slide2 (
        client_id," . implode(',', $fields) . ",updated_at
    ) VALUES (
        :client_id, :" . implode(',:', $fields) . ", NOW()
    )
    ON DUPLICATE KEY UPDATE
    " . implode(',', array_map(fn($f) => "$f=VALUES($f)", $fields)) . ",
    updated_at=NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(['client_id' => $client_id], $data));

    echo json_encode(['success' => true]);
    exit;
}

/* =========================
   LOAD DATA (SLIDES DB)
========================= */
$client_id = $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '';
$fields = [
    'redeem_fund',
    'redeem_amount',
    'new_total_amount',
    'replace_fund_1_name',
    'replace_fund_1_amount',
    'replace_fund_2_name',
    'replace_fund_2_amount',
    'replace_fund_3_name',
    'replace_fund_3_amount',
    'new_fund_1_name',
    'new_fund_1_amount',
    'new_fund_2_name',
    'new_fund_2_amount'
];

$data = array_fill_keys($fields, null);

if ($client_id) {
    $pdo = getSlidesPdo(); // ✅ IMPORTANT FIX
    $stmt = $pdo->prepare("SELECT * FROM slide2 WHERE client_id = ?");
    $stmt->execute([$client_id]);
    if ($row = $stmt->fetch()) {
        $data = array_merge($data, $row);
    }
}

renderSlide2Template($data);

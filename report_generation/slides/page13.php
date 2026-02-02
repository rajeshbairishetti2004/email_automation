<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPER
========================= */
function showText13($v, $default)
{
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide13Template(array $d = [])
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
    margin-bottom: 30px;
}

ul {
    font-size: 18px;
    color: #0A3DBA;
    line-height: 1.9;
}

ul.main {
    margin-left: 40px;
}

ul.sub {
    margin-left: 40px;
    list-style-type: circle;
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

<h1 class="slide-title">Strategic Rebalancing</h1>

<ul class="main">
    <li class="editable" data-f="bullet_1">
        <?= showText13(
            $d['bullet_1'],
            'All rebalancing recommendations are optimized in relation to portfolio objectives, risks, market conditions, and efficient taxation planning.'
        ) ?>
    </li>

    <li class="editable" data-f="bullet_2">
        <?= showText13(
            $d['bullet_2'],
            'We are advocating redemption of Quant Flexicap (Rs. 10.40 lakhs) as the scheme started on a very strong note in 2021 but has not been able to maintain that momentum over the last 1.5 years.'
        ) ?>
    </li>

    <li>
        <span class="editable" data-f="bullet_3">
            <?= showText13(
                $d['bullet_3'],
                'We are suggesting reinvestment into:'
            ) ?>
        </span>

        <ul class="sub">
            <li class="editable" data-f="sub_1">
                <?= showText13(
                    $d['sub_1'],
                    'Parag Parikh Nasdaq 100 ETF FoF – Rs. 4.5 lakhs'
                ) ?>
            </li>
            <li class="editable" data-f="sub_2">
                <?= showText13(
                    $d['sub_2'],
                    'Parag Parikh S&P 500 ETF FoF – Rs. 4.5 lakhs'
                ) ?>
            </li>
            <li class="editable" data-f="sub_3">
                <?= showText13(
                    $d['sub_3'],
                    'Motilal Oswal Gold & Silver ETF FoF – Rs. 1 lakh with the objective of building global wealth and precious metals exposure'
                ) ?>
            </li>
        </ul>
    </li>

    <li class="editable" data-f="bullet_4">
        <?= showText13(
            $d['bullet_4'],
            'Guidance for the new Rs. 10 lakhs allocation is also based on the above rationale.'
        ) ?>
    </li>
</ul>

</div>

<div class="slide-logo">
    <img src="/image.png" alt="Finance Doctor">
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
    const f = new FormData();
    f.append('ajax','save');
    f.append(
        'client_id',
        '<?= $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '' ?>'
    );

    document.querySelectorAll('.editable').forEach(el => {
        f.append(el.dataset.f, el.innerText.trim());
        el.contentEditable = false;
        el.classList.remove('editing');
    });

    fetch('/report_generation/slides/page13.php',{
        method:'POST',
        body:f
    })
    .then(r=>r.json())
    .then(res=>{
        if(res.success){
            const i = window.frameElement;
            if(i){
                const u = new URL(i.src);
                u.searchParams.set('t', Date.now());
                i.src = u.toString();
            }
            alert('Slide saved');
        } else {
            alert(res.error || 'Save failed');
        }
    });
};
</script>
<?php }

/* =========================
   SAVE
========================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='save') {
    $pdo = getSlidesPdo();

    $fields = [
        'bullet_1','bullet_2','bullet_3',
        'sub_1','sub_2','sub_3',
        'bullet_4'
    ];

    $cid = $_POST['client_id'] ?? '';
    if(!$cid){
        echo json_encode(['success'=>false,'error'=>'Client ID missing']);
        exit;
    }

    $data=[];
    foreach($fields as $f){
        $v = trim($_POST[$f] ?? '');
        $data[$f] = ($v==='') ? null : $v;
    }

    $sql = "
    INSERT INTO slide13 (client_id,".implode(',',$fields).",updated_at)
    VALUES (:client_id,:".implode(',:',$fields).",NOW())
    ON DUPLICATE KEY UPDATE
    ".implode(',',array_map(fn($f)=>"$f=VALUES($f)",$fields)).",
    updated_at=NOW()
    ";

    $pdo->prepare($sql)->execute(array_merge(['client_id'=>$cid],$data));
    echo json_encode(['success'=>true]);
    exit;
}

/* =========================
   LOAD
========================= */
$cid = $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '';
$data = [];

if($cid){
    $pdo = getSlidesPdo();
    $s = $pdo->prepare("SELECT * FROM slide13 WHERE client_id=?");
    $s->execute([$cid]);
    if($r=$s->fetch()) $data=$r;
}

renderSlide13Template($data);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   HELPERS
========================= */
function num8($v, $default) {
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}
function txt8($v, $default) {
    return ($v === null || $v === '') ? $default : htmlspecialchars($v);
}

/* =========================
   TEMPLATE
========================= */
function renderSlide8Template(array $d = [])
{
    $cl = num8($d['curr_large'], 53.4);
    $cm = num8($d['curr_mid'],   25.8);
    $cs = num8($d['curr_small'], 20.8);

    $rl = num8($d['rec_large'], 50.0);
    $rm = num8($d['rec_mid'],   30.0);
    $rs = num8($d['rec_small'], 20.0);
?>
<style>
html,body{margin:0;width:100%;height:100%}
.editable{cursor:pointer}
.editable.editing{background:#f4f8ff;border-bottom:1px dashed #4F7DF3}

.slide-root{position:relative;width:100%;height:100%}
.slide-content{padding:40px 60px;box-sizing:border-box}

.slide-title{
    text-align:center;
    color:#4F7DF3;
    font-size:42px;
    font-weight:600;
    margin-bottom:30px;
}

.charts{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:80px;
    align-items:center;
}

.chart-box{text-align:center}

.chart-title{
    color:#0A3DBA;
    font-weight:600;
    margin-bottom:10px;
}

.donut{
    width:220px;
    height:220px;
    border-radius:50%;
    margin:0 auto 10px;
    position:relative;
}
.donut::after{
    content:'';
    position:absolute;
    inset:55px;
    background:#fff;
    border-radius:50%;
}

.legend{
    font-size:14px;
    color:#0A3DBA;
    line-height:1.6;
}

.legend span{display:block}

.interpretation{
    margin-top:35px;
    font-size:18px;
    color:#0A3DBA;
}

.slide-logo{
    position:absolute;
    right:40px;
    bottom:28px;
}
.slide-logo img{width:130px}

.slide-footer-bar{
    position:absolute;
    left:0;right:0;bottom:0;
    height:10px;
    background:#4DB6AC;
}
</style>

<div class="slide-root">
<div class="slide-content">

<h1 class="slide-title">Equity MCAP allocation</h1>

<div class="charts">

    <!-- CURRENT -->
    <div class="chart-box">
        <div class="chart-title">Current</div>
        <div class="donut"
             style="background:
             conic-gradient(
                #1aff00 0 <?= $cl ?>%,
                #4d88ff <?= $cl ?>% <?= $cl+$cm ?>%,
                #ff7a2f <?= $cl+$cm ?>% 100%
             )">
        </div>

        <div class="legend">
            <span class="editable" data-f="curr_large">Large Cap <?= $cl ?>%</span>
            <span class="editable" data-f="curr_mid">Mid Cap <?= $cm ?>%</span>
            <span class="editable" data-f="curr_small">Small Cap <?= $cs ?>%</span>
        </div>
    </div>

    <!-- RECOMMENDED -->
    <div class="chart-box">
        <div class="chart-title">Recommended</div>
        <div class="donut"
             style="background:
             conic-gradient(
                #1aff00 0 <?= $rl ?>%,
                #4d88ff <?= $rl ?>% <?= $rl+$rm ?>%,
                #ff7a2f <?= $rl+$rm ?>% 100%
             )">
        </div>

        <div class="legend">
            <span class="editable" data-f="rec_large">Large Cap <?= $rl ?>%</span>
            <span class="editable" data-f="rec_mid">Mid Cap <?= $rm ?>%</span>
            <span class="editable" data-f="rec_small">Small Cap <?= $rs ?>%</span>
        </div>
    </div>

</div>

<div class="interpretation">
    <strong>Finance Doctor’s interpretation:</strong><br>
    <span class="editable" data-f="interpretation">
        <?= txt8(
            $d['interpretation'],
            'Different caps have the right percentage ranges. So, no change is recommended.'
        ) ?>
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
    document.querySelectorAll('.editable').forEach(e=>{
        e.contentEditable=true;
        e.classList.add('editing');
    });
};

window.saveSlide = () => {
    const f = new FormData();
    f.append('ajax','save');
    f.append('client_id','<?= $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '' ?>');

    document.querySelectorAll('.editable').forEach(e=>{
        const val = e.innerText.replace(/[^\d.]/g,'') || e.innerText;
        f.append(e.dataset.f,val.trim());
        e.contentEditable=false;
        e.classList.remove('editing');
    });

    fetch('/email_automation/report_generation/slides/page8.php',{method:'POST',body:f})
    .then(r=>r.json())
    .then(res=>{
        if(res.success){
            const i=window.frameElement;
            if(i){const u=new URL(i.src);u.searchParams.set('t',Date.now());i.src=u}
            alert('Slide saved');
        } else alert(res.error||'Save failed');
    });
};
</script>
<?php }

/* =========================
   SAVE
========================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='save') {
    $pdo = getSlidesPdo();
    $fields=[
        'curr_large','curr_mid','curr_small',
        'rec_large','rec_mid','rec_small',
        'interpretation'
    ];

    $cid=$_POST['client_id']??'';
    if(!$cid){echo json_encode(['success'=>false]);exit;}

    $data=[];
    foreach($fields as $f){
        $data[$f]=trim($_POST[$f]??null);
    }

    $sql="
    INSERT INTO slide8 (client_id,".implode(',',$fields).",updated_at)
    VALUES (:client_id,:".implode(',:',$fields).",NOW())
    ON DUPLICATE KEY UPDATE
    ".implode(',',array_map(fn($f)=>"$f=VALUES($f)",$fields)).",
    updated_at=NOW()
    ";
    $pdo->prepare($sql)->execute(array_merge(['client_id'=>$cid],$data));
    echo json_encode(['success'=>true]);exit;
}

/* =========================
   LOAD
========================= */
$cid=$_GET['client_id']??$_SESSION['current_client_id']??'';
$data=[];
if($cid){
    $pdo=getSlidesPdo();
    $s=$pdo->prepare("SELECT * FROM slide8 WHERE client_id=?");
    $s->execute([$cid]);
    if($r=$s->fetch()) $data=$r;
}

renderSlide8Template($data);

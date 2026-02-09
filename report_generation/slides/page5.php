<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   SAVE HANDLER (UPDATE CORE TABLES)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contentType = $_SERVER['CONTENT_TYPE']
        ?? $_SERVER['HTTP_CONTENT_TYPE']
        ?? '';

    if (stripos($contentType, 'application/json') !== false) {

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            echo json_encode(['success'=>false,'error'=>'Invalid JSON']);
            exit;
        }

        $clientId = (int)($data['client_id'] ?? 0);
        if ($clientId <= 0) {
            echo json_encode(['success'=>false,'error'=>'Invalid client']);
            exit;
        }

        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction();

            /* =========================
               UPDATE CLIENTS TABLE
            ========================= */
            $stmt = $pdo->prepare("
                UPDATE clients
                SET total_amount = ?, xirr = ?, profit = ?
                WHERE id = ?
            ");
            $stmt->execute([
                (int)$data['total_amount'],
                (float)$data['xirr'],
                (int)$data['profit'],
                $clientId
            ]);

            /* =========================
               UPDATE CLIENT_GOALS TABLE
            ========================= */

            // Remove old goals
            $pdo->prepare("DELETE FROM client_goals WHERE client_id=?")
                ->execute([$clientId]);

            // Insert updated goals
            $stmt = $pdo->prepare("
                INSERT INTO client_goals
                (client_id, goal, current_amount, target_amount, goal_date, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach (($data['goals'] ?? []) as $g) {
                $stmt->execute([
                    $clientId,
                    trim($g['goal'] ?? ''),
                    (int)($g['current_amount'] ?? 0),
                    (int)($g['target_amount'] ?? 0),
                    !empty($g['goal_date']) ? $g['goal_date'] : null,
                    trim($g['status'] ?? '')
                ]);
            }

            $pdo->commit();

            echo json_encode(['success'=>true]);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('PAGE5 CORE SAVE ERROR: '.$e->getMessage());
            echo json_encode(['success'=>false,'error'=>'Database error']);
            exit;
        }
    }
}

/* =========================
   DISPLAY HELPERS
========================= */
function cr_disp($v) {
    return 'Rs.' . number_format(($v ?? 0) / 10000000, 2) . ' Cr';
}
function pct_disp($v) {
    return number_format((float)($v ?? 0), 2) . '%';
}

/* =========================
   CLIENT
========================= */
$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId = (int)str_replace('CLIENT_', '', $clientKey);

if ($clientId <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client not found</div>";
    return;
}

$pdo = getDbConnection();

/* =========================
   LOAD DATA (CORE TABLES ONLY)
========================= */
$stmt = $pdo->prepare("
    SELECT total_amount, profit, xirr
    FROM clients
    WHERE id=?
");
$stmt->execute([$clientId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC)
    ?: ['total_amount'=>0,'profit'=>0,'xirr'=>0];

$stmt = $pdo->prepare("
    SELECT goal, current_amount, target_amount, goal_date, status
    FROM client_goals
    WHERE client_id=?
    ORDER BY id ASC
");
$stmt->execute([$clientId]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
html,body{margin:0;padding:0;width:100%;height:100%;background:#fff;font-family:Calibri,"Segoe UI",Arial;}
.slide{width:100%;height:100%;position:relative;}
.content{padding:80px 90px;}
.title{text-align:center;font-size:42px;color:#3B73E8;font-weight:600;margin-bottom:45px;}
.section{color:#0A3DBA;font-size:20px;font-weight:600;margin:25px 0 10px;}

table{border-collapse:collapse;font-size:15px;}
td,th{padding:6px 10px;border:1px solid #dcdcdc;white-space:nowrap;}

.cv-table{width:520px;}
.cv-table td:first-child{background:#3B73E8;color:#fff;font-weight:600;width:300px;}
.cv-table td:last-child{text-align:right;font-weight:600;}

.goal-table{width:760px;margin-top:6px;}
.goal-table th{background:#3B73E8;color:#fff;text-align:left;}

input{
    width:100%;
    border:none;
    background:transparent;
    font-family:inherit;
    font-size:15px;
    display:none;
}
.editing input{display:block;}
.editing span.val{display:none;}

.footer{position:absolute;left:0;right:0;bottom:0;height:10px;background:#21B6A8;}
.logo{position:absolute;right:40px;bottom:28px;}
.logo img{width:120px;}
</style>
</head>

<body>
<div class="slide" id="slide5">
<div class="content">

<div class="title">Portfolio at a glance</div>

<div class="section">Current Portfolio Value</div>
<table class="cv-table">
<tr>
<td>Total Amount as on date</td>
<td>
<span class="val"><?= cr_disp($p['total_amount']) ?></span>
<input id="total_amount" type="number" value="<?= (int)$p['total_amount'] ?>">
</td>
</tr>
<tr>
<td>XIRR of all schemes</td>
<td>
<span class="val"><?= pct_disp($p['xirr']) ?></span>
<input id="xirr" type="number" step="0.01" value="<?= (float)$p['xirr'] ?>">
</td>
</tr>
<tr>
<td>Profit since inception</td>
<td>
<span class="val"><?= cr_disp($p['profit']) ?></span>
<input id="profit" type="number" value="<?= (int)$p['profit'] ?>">
</td>
</tr>
</table>

<div class="section" style="margin-top:35px;">Goal Report</div>
<table class="goal-table" id="goalTable">
<tr>
<th>Goal</th>
<th>Current Value</th>
<th>Target Value</th>
<th>Target Date</th>
<th>Status</th>
</tr>

<?php foreach ($goals as $g): ?>
<tr>
<td><span class="val"><?= htmlspecialchars($g['goal']) ?></span><input value="<?= htmlspecialchars($g['goal']) ?>"></td>
<td><span class="val"><?= cr_disp($g['current_amount']) ?></span><input type="number" value="<?= (int)$g['current_amount'] ?>"></td>
<td><span class="val"><?= cr_disp($g['target_amount']) ?></span><input type="number" value="<?= (int)$g['target_amount'] ?>"></td>
<td><span class="val"><?= htmlspecialchars($g['goal_date'] ?? '-') ?></span><input type="date" value="<?= htmlspecialchars($g['goal_date']) ?>"></td>
<td><span class="val"><?= htmlspecialchars($g['status']) ?></span><input value="<?= htmlspecialchars($g['status']) ?>"></td>
</tr>
<?php endforeach; ?>
</table>

</div>

<div class="logo"><img src="/email_automation/image.png" alt=""></div>
<div class="footer"></div>
</div>

<script>
function enableEdit(){
    document.getElementById('slide5').classList.add('editing');
}

function saveSlide(){
    const goals = [];
    document.querySelectorAll('#goalTable tr:not(:first-child)').forEach(tr=>{
        const i = tr.querySelectorAll('input');
        goals.push({
            goal: i[0].value,
            current_amount: i[1].value,
            target_amount: i[2].value,
            goal_date: i[3].value,
            status: i[4].value
        });
    });

    fetch('slides/page5.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            client_id: <?= $clientId ?>,
            total_amount: total_amount.value,
            xirr: xirr.value,
            profit: profit.value,
            goals: goals
        })
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.success){
            alert('Page 5 updated successfully');
            location.reload();
        }else{
            alert(d.error || 'Save failed');
        }
    });
}
</script>

</body>
</html>

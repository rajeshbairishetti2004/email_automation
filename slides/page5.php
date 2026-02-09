<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

function cr($v) {
    return 'Rs.' . number_format(($v ?? 0) / 10000000, 2) . ' Cr';
}
function pct($v) {
    return number_format((float)($v ?? 0), 2) . '%';
}

$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId = (int)str_replace('CLIENT_', '', $clientKey);
if ($clientId <= 0) {
    echo "<div style='padding:40px;color:#999;'>Client not found</div>";
    return;
}

$pdo = getDbConnection();

// --- Step 4: Handle SAVE (update logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_portfolio'])) {

    // ---- Update CLIENTS table ----
    $stmt = $pdo->prepare("
        UPDATE clients 
        SET total_amount = ?, profit = ?, xirr = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $_POST['total_amount'],
        $_POST['profit'],
        $_POST['xirr'],
        $clientId
    ]);

    // ---- Update GOALS ----
    if (!empty($_POST['goal_id'])) {
        foreach ($_POST['goal_id'] as $i => $goalId) {
            $stmt = $pdo->prepare("
                UPDATE client_goals
                SET 
                    goal = ?,
                    current_amount = ?,
                    target_amount = ?,
                    goal_date = ?,
                    status = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->execute([
                $_POST['goal'][$i],
                $_POST['current_amount'][$i],
                $_POST['target_amount'][$i],
                $_POST['goal_date'][$i],
                $_POST['status'][$i],
                $goalId,
                $clientId
            ]);
        }
    }

    // Reload to show saved data
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// --- Handle POST (update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update clients table
    $stmt = $pdo->prepare("UPDATE clients SET total_amount=?, profit=?, xirr=? WHERE id=?");
    $stmt->execute([
        $_POST['total_amount'] ?? 0,
        $_POST['profit'] ?? 0,
        $_POST['xirr'] ?? 0,
        $clientId
    ]);

    // Update goals (delete all and re-insert for simplicity)
    $pdo->prepare("DELETE FROM client_goals WHERE client_id=?")->execute([$clientId]);
    if (!empty($_POST['goal'])) {
        foreach ($_POST['goal'] as $i => $goal) {
            if (trim($goal) === '') continue;
            $stmt = $pdo->prepare("INSERT INTO client_goals (client_id, goal, current_amount, target_amount, goal_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $clientId,
                $goal,
                $_POST['current_amount'][$i] ?? 0,
                $_POST['target_amount'][$i] ?? 0,
                $_POST['goal_date'][$i] ?? '',
                $_POST['status'][$i] ?? 'On Track'
            ]);
        }
    }
    // Redirect to avoid resubmission
    header("Location: {$_SERVER['REQUEST_URI']}");
    exit;
}

// --- Fetch data ---
$stmt = $pdo->prepare("SELECT total_amount, profit, xirr FROM clients WHERE id=?");
$stmt->execute([$clientId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

// Step 3: Add id to goal query
$stmt = $pdo->prepare("
    SELECT id, goal, current_amount, target_amount, goal_date, status
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
/* ...existing code... */
</style>
</head>
<body>
<div class="slide">
    <div class="content">
        <!-- Step 1: Wrap editable part in form -->
        <form method="post">
        <!-- TITLE -->
        <div class="title">Portfolio at a glance</div>

        <!-- CURRENT PORTFOLIO VALUE -->
        <div class="section">Current Portfolio Value</div>
        <table class="cv-table">
            <tr>
                <td>Total Amount as on date</td>
                <td>
                    <!-- Step 2: Editable input -->
                    <input type="number" name="total_amount" value="<?= htmlspecialchars($p['total_amount'] ?? 0) ?>" style="width:140px;text-align:right;">
                </td>
            </tr>
            <tr>
                <td>XIRR of all schemes</td>
                <td>
                    <input type="number" step="0.01" name="xirr" value="<?= htmlspecialchars($p['xirr'] ?? 0) ?>" style="width:140px;text-align:right;">
                </td>
            </tr>
            <tr>
                <td>Profit since inception</td>
                <td>
                    <input type="number" name="profit" value="<?= htmlspecialchars($p['profit'] ?? 0) ?>" style="width:140px;text-align:right;">
                </td>
            </tr>
        </table>

        <!-- GOAL REPORT -->
        <div class="section" style="margin-top:35px;">Goal Report</div>
        <table class="goal-table">
            <tr>
                <th>Goal</th>
                <th>Current Value</th>
                <th>Target Value</th>
                <th>Target Date</th>
                <th>Status</th>
            </tr>
            <!-- Step 3: Editable goals with id -->
            <?php foreach ($goals as $g): ?>
            <tr>
                <td>
                    <input type="hidden" name="goal_id[]" value="<?= $g['id'] ?>">
                    <input type="text" name="goal[]" value="<?= htmlspecialchars($g['goal']) ?>">
                </td>
                <td>
                    <input type="number" name="current_amount[]" value="<?= htmlspecialchars($g['current_amount']) ?>">
                </td>
                <td>
                    <input type="number" name="target_amount[]" value="<?= htmlspecialchars($g['target_amount']) ?>">
                </td>
                <td>
                    <input type="date" name="goal_date[]" value="<?= htmlspecialchars($g['goal_date']) ?>">
                </td>
                <td>
                    <select name="status[]">
                        <option value="On Track" <?= $g['status']=='On Track'?'selected':'' ?>>On Track</option>
                        <option value="Off Track" <?= $g['status']=='Off Track'?'selected':'' ?>>Off Track</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div style="margin-top:25px;">
            <button type="submit" name="save_portfolio" style="
                padding:10px 22px;
                background:#3B73E8;
                color:#fff;
                border:none;
                font-size:15px;
                cursor:pointer;
            ">
                Save Changes
            </button>
        </div>
        </form>
    </div>
    <div class="logo">
        <img src="/email_automation/image.png" alt="Finance Doctor">
    </div>
    <div class="footer"></div>
</div>
<script>
function addGoalRow() {
    var table = document.querySelector('.goal-table');
    var row = table.insertRow(-1);
    row.innerHTML = `<td><input type="text" name="goal[]" style="width:120px;"></td>
        <td><input type="number" name="current_amount[]" step="0.01" style="width:100px;"></td>
        <td><input type="number" name="target_amount[]" step="0.01" style="width:100px;"></td>
        <td><input type="date" name="goal_date[]"></td>
        <td>
            <select name="status[]">
                <option value="On Track">On Track</option>
                <option value="Off Track">Off Track</option>
            </select>
        </td>
        <td><button type="button" onclick="removeGoalRow(this)">Remove</button></td>`;
}
function removeGoalRow(btn) {
    var row = btn.closest('tr');
    row.parentNode.removeChild(row);
}
</script>
</body>
</html>
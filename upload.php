<?php
// upload.php
// Clean rebuild: dashboard + upload/parse/save pipeline

require_once 'auth.php';
require_once 'db_config.php';
require_once 'parsers.php';
require_once 'renderers.php';
require_once 'env_loader.php';

requireAuth();

$currentReviewPeriod = date('F Y');
$pdo           = getPdo();
$currentUser   = getCurrentUser();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

const DEFAULT_GREETING  = 'Dear Mr.';
const DEFAULT_INTRO     = 'Introduction';
const DEFAULT_CLOSING   = 'Closing remarks';
const DEFAULT_RATIONALE = 'Rationale for recommendations';

function safePercent($num, $den)
{
    if ($den <= 0) return 0;
    return (int)round(($num / $den) * 100);
}

function fetchDashboardStats(PDO $pdo, string $context, int $userId, string $cycleFilter = ''): array
{
    $where  = '1=1';
    $params = [];
    if ($context === 'mine') {
        // Use positional placeholders to avoid duplicate named-param issues on some PDO drivers
        $where = '(assigned_to = ? OR review_assigned_to = ?)';
        $params = [$userId, $userId];
        // Use positional placeholders to avoid duplicate named-param issues on some PDO drivers
        $where = '(assigned_to = ? OR review_assigned_to = ?)';
        $params = [$userId, $userId];
    } elseif (ctype_digit($context)) {
        $where = '(assigned_to = ? OR review_assigned_to = ?)';
        $params = [(int)$context, (int)$context];
        $where = '(assigned_to = ? OR review_assigned_to = ?)';
        $params = [(int)$context, (int)$context];
    }
    // Add cycle filter if set
    if ($cycleFilter !== '') {
        $where .= ' AND review_cycle = ?';
        $params[] = $cycleFilter;
    }

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(IF(report_state = 'pending', 1, 0))  AS count_pending,
                SUM(IF(report_state = 'draft', 1, 0))    AS count_draft,
                SUM(IF(report_state = 'ready', 1, 0))    AS count_ready,
                SUM(IF(report_state = 'reviewed', 1, 0)) AS count_reviewed,
                SUM(IF(report_state = 'sent', 1, 0))     AS count_sent
            FROM clients
            WHERE {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'          => (int)($row['total'] ?? 0),
        'count_pending'  => (int)($row['count_pending'] ?? 0),
        'count_draft'    => (int)($row['count_draft'] ?? 0),
        'count_ready'    => (int)($row['count_ready'] ?? 0),
        'count_reviewed' => (int)($row['count_reviewed'] ?? 0),
        'count_sent'     => (int)($row['count_sent'] ?? 0),
    ];
}

function fetchTeamStats(PDO $pdo): array
{
    $sql = "SELECT
                u.id,
                u.username,
                u.designation,
                SUM(IF(c.report_state = 'pending', 1, 0)) AS pending_count,
                SUM(IF(c.report_state = 'sent', 1, 0)) AS sent_count,
                SUM(IF(c.priority = 'high', 1, 0)) AS high_pri,
                COUNT(c.id) AS total_assigned
            FROM users u
            LEFT JOIN clients c ON c.assigned_to = u.id
            GROUP BY u.id
            ORDER BY u.username";

    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Rename high_pri back to high_priority for compatibility
    foreach ($result as &$row) {
        $row['high_priority'] = $row['high_pri'];
        unset($row['high_pri']);
    }
    
    return $result;
}

function mergeClientArrays(array &$target, array $source): void
{
    foreach ($source as $client => $data) {
        if (!isset($target[$client])) {
            $target[$client] = $data;
            continue;
        }
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                if (!isset($target[$client][$key])) {
                    $target[$client][$key] = $val;
                } else {
                    $target[$client][$key] = array_merge($target[$client][$key], $val);
                }
            } else {
                $target[$client][$key] = $val;
            }
        }
    }
}

$cycleFilter = isset($_GET['cycle_filter']) ? $_GET['cycle_filter'] : '';

$viewContext = $_GET['view_context'] ?? 'mine';
$targetName  = 'My';
if ($viewContext === 'all') {
    $targetName = 'Global';
} elseif (ctype_digit($viewContext)) {
    $targetName = 'User';
}

  // --- AUM CALCULATION LOGIC ---
                    // This follows the same context/cycle filtering as the dashboard stats
                    $aumWhere  = '1=1';
                    $aumParams = [];
                    if ($viewContext === 'mine') {
                        $aumWhere = '(assigned_to = ? OR review_assigned_to = ?)';
                        $aumParams = [$currentUserId, $currentUserId];
                    } elseif (ctype_digit($viewContext)) {
                        $aumWhere = '(assigned_to = ? OR review_assigned_to = ?)';
                        $aumParams = [(int)$viewContext, (int)$viewContext];
                    }
                    
                    if ($cycleFilter !== '') {
                        $aumWhere .= ' AND review_cycle = ?';
                        $aumParams[] = $cycleFilter;
                    }
                    
                    $stmtAum = $pdo->prepare("SELECT SUM(aum) FROM clients WHERE {$aumWhere}");
                    $stmtAum->execute($aumParams);
                    $totalAum = $stmtAum->fetchColumn() ?: 0;

$usersStmt = $pdo->query('SELECT id, username FROM users ORDER BY username ASC');
$allUsers  = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$viewStats       = fetchDashboardStats($pdo, $viewContext, $currentUserId, $cycleFilter);
$completionRate  = safePercent($viewStats['count_sent'], max(1, $viewStats['total']));
$uploadError     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['client_files'])) {
            throw new Exception('No files were uploaded.');
        }

        $baseUploadDir = __DIR__ . '/uploads';
        if (!is_dir($baseUploadDir)) {
            mkdir($baseUploadDir, 0777, true);
        }

        $pv  = [];
        $aa  = [];
        $rst = [];
        $ps  = [];
        $pdfGoal     = [];
        $attachments = [];

        $fileCount = count($_FILES['client_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $error = $_FILES['client_files']['error'][$i];
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $name     = $_FILES['client_files']['name'][$i];
            $tmpPath  = $_FILES['client_files']['tmp_name'][$i];
            $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $destName = uniqid('upload_', true) . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);
            $destPath = $baseUploadDir . '/' . $destName;
            if (!move_uploaded_file($tmpPath, $destPath)) {
                continue;
            }

            $nameLower = strtolower($name);

            try {
                if ($ext === 'pdf') {
                    if (strpos($nameLower, 'goalstatusreport') !== false) {
                        $pdfGoal = parseGoalStatusPdf($destPath);
                    } else {
                        $attachments[] = ['path' => $destPath, 'name' => $name];
                    }
                } else {
                    if (strpos($nameLower, 'valuation') !== false) {
                        mergeClientArrays($pv, parsePortfolioValuation($destPath));
                    } elseif (strpos($nameLower, 'allocation') !== false) {
                        mergeClientArrays($aa, parseAllocationAnalysis($destPath));
                    } elseif (strpos($nameLower, 'running') !== false || strpos($nameLower, 'systematic') !== false || strpos($nameLower, 'sip') !== false) {
                        mergeClientArrays($rst, parseRunningSystematicTransactions($destPath));
                    } elseif (strpos($nameLower, 'summary') !== false) {
                        mergeClientArrays($ps, parsePortfolioSummary($destPath));
                    } else {
                        $attachments[] = ['path' => $destPath, 'name' => $name];
                    }
                }
            } catch (Throwable $parseErr) {
                $attachments[] = ['path' => $destPath, 'name' => $name];
            }
        }

        $allClientReports = buildClientReports($pv, $aa, $rst, $ps, $pdfGoal);
        if (!$allClientReports) {
            throw new Exception('No client data could be parsed from the uploaded files.');
        }

        $checkClient = $pdo->prepare('SELECT id FROM clients WHERE name = :name LIMIT 1');
        $updateClient = $pdo->prepare('UPDATE clients SET
                as_on = :as_on,
                email = :email,
                total_amount = :total_amount,
                profit = :profit,
                cagr = :cagr,
                xirr = :xirr,
                absolute_return = :absolute_return,
                total_goal_current = :total_goal_current,
                total_goal_target = :total_goal_target,
                total_sip = :total_sip,
                greeting_prefix = :greeting_prefix,
                intro_text = :intro_text,
                closing_text = :closing_text,
                rationale_text = :rationale_text,
            report_state = :report_state,
            created_by = :created_by,
            updated_at = NOW()
            WHERE id = :id');

        $insertClient = $pdo->prepare('INSERT INTO clients
            (name, email, as_on, total_amount, profit, cagr, xirr, absolute_return,
             total_goal_current, total_goal_target, total_sip,
             greeting_prefix, intro_text, closing_text, rationale_text,
             created_by, report_state, assigned_to)
            VALUES
            (:name, :email, :as_on, :total_amount, :profit, :cagr, :xirr, :absolute_return,
             :total_goal_current, :total_goal_target, :total_sip,
             :greeting_prefix, :intro_text, :closing_text, :rationale_text,
             :created_by, :report_state, :assigned_to)');

        $wipeGoals   = $pdo->prepare('DELETE FROM client_goals WHERE client_id = :cid');
        $wipeAlloc   = $pdo->prepare('DELETE FROM client_allocations WHERE client_id = :cid');
        $wipeSchemes = $pdo->prepare('DELETE FROM client_schemes WHERE client_id = :cid');
        $wipeAnnex   = $pdo->prepare('DELETE FROM client_annexures WHERE client_id = :cid');

        $stmtGoal = $pdo->prepare('INSERT INTO client_goals
            (client_id, goal, goal_date, current_amount, sip_swp, target_amount, projected, shortfall, completion, status)
            VALUES
            (:client_id, :goal, :goal_date, :current_amount, :sip_swp, :target_amount, :projected, :shortfall, :completion, :status)');

        $stmtAlloc = $pdo->prepare('INSERT INTO client_allocations (client_id, asset, share_pct)
            VALUES (:client_id, :asset, :share_pct)');

        $stmtScheme = $pdo->prepare('INSERT INTO client_schemes
            (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount)
            VALUES
            (:client_id, :scheme_name, :sip_swp, :current_value, :action_step, :recommended_scheme, :recommended_amount)');

        $stmtAnnex = $pdo->prepare('INSERT INTO client_annexures (client_id, line_text) VALUES (:client_id, :line_text)');

        $pdo->beginTransaction();

        $firstClientId = 0;
        foreach ($allClientReports as $clientData) {

            $email = $clientData['email'] ?? '';
            $clientName = trim($clientData['name'] ?? '');
            if ($clientName === '') {
                continue;
            }

            $totals  = $clientData['current']['totals'] ?? ['purchase' => 0, 'current' => 0, 'profit' => 0, 'cagr_weighted' => 0, 'xirr_weighted' => 0, 'absolute_return' => 0];
            $summary = $clientData['current']['summary'] ?? null;

            $totalAmount    = $totals['current'] ?? 0;
            $profit         = $summary['profit'] ?? ($totals['profit'] ?? 0);
            $cagr           = $totals['cagr_weighted'] ?? 0;
            $xirr           = $summary['xirr'] ?? ($totals['xirr_weighted'] ?? 0);
            $absoluteReturn = $totals['absolute_return'] ?? 0;

            $goals      = $clientData['goals'] ?? [];
            $allocation = $clientData['allocation'] ?? [];
            $schemes    = $clientData['schemes'] ?? [];
            $asOn       = $clientData['as_on'] ?? '';

            $totalSip         = 0;
            $totalGoalCurrent = 0;
            $totalGoalTarget  = 0;
            foreach ($goals as $g) {
                $totalSip         += (float)($g['running_sip'] ?? 0);
                $totalGoalCurrent += (float)($g['current_value'] ?? 0);
                $totalGoalTarget  += (float)($g['target_amount'] ?? 0);
            }

            $checkClient->execute([':name' => $clientName]);
            $existingId = (int)($checkClient->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $updateClient->execute([
                    ':email' => $email,
                    ':as_on'              => $asOn,
                    ':total_amount'       => $totalAmount,
                    ':profit'             => $profit,
                    ':cagr'               => $cagr,
                    ':xirr'               => $xirr,
                    ':absolute_return'    => $absoluteReturn,
                    ':total_goal_current' => $totalGoalCurrent,
                    ':total_goal_target'  => $totalGoalTarget,
                    ':total_sip'          => $totalSip,
                    ':greeting_prefix'    => DEFAULT_GREETING,
                    ':intro_text'         => DEFAULT_INTRO,
                    ':closing_text'       => DEFAULT_CLOSING,
                    ':rationale_text'     => DEFAULT_RATIONALE,
                    ':report_state'       => 'draft',
                    ':created_by'         => $currentUserId,
                    ':id'                 => $existingId,
                ]);

                $wipeGoals->execute([':cid' => $existingId]);
                $wipeAlloc->execute([':cid' => $existingId]);
                $wipeSchemes->execute([':cid' => $existingId]);
                $wipeAnnex->execute([':cid' => $existingId]);

                $clientId = $existingId;
            } else {
                $insertClient->execute([
                    ':name'               => $clientName,
                    ':email' => $email,
                    ':as_on'              => $asOn,
                    ':total_amount'       => $totalAmount,
                    ':profit'             => $profit,
                    ':cagr'               => $cagr,
                    ':xirr'               => $xirr,
                    ':absolute_return'    => $absoluteReturn,
                    ':total_goal_current' => $totalGoalCurrent,
                    ':total_goal_target'  => $totalGoalTarget,
                    ':total_sip'          => $totalSip,
                    ':greeting_prefix'    => DEFAULT_GREETING,
                    ':intro_text'         => DEFAULT_INTRO,
                    ':closing_text'       => DEFAULT_CLOSING,
                    ':rationale_text'     => DEFAULT_RATIONALE,
                    ':created_by'         => $currentUserId,
                    ':report_state'       => 'draft',
                    ':assigned_to'        => $currentUserId,
                ]);

                $clientId = (int)$pdo->lastInsertId();
            }

            if ($firstClientId === 0 && $clientId > 0) {
                $firstClientId = $clientId;
            }

            foreach ($goals as $g) {
                $projectedVal = (float)($g['projected'] ?? 0);
                $targetVal    = (float)($g['target_amount'] ?? 0);
                $statusCalc   = ($projectedVal < $targetVal) ? 'Invest More' : 'On Track';

                $stmtGoal->execute([
                    ':client_id'      => $clientId,
                    ':goal'           => $g['goal'] ?? '',
                    ':goal_date'      => $g['goal_date'] ?? '',
                    ':current_amount' => $g['current_value'] ?? 0,
                    ':sip_swp'        => $g['running_sip'] ?? 0,
                    ':target_amount'  => $targetVal,
                    ':projected'      => $projectedVal,
                    ':shortfall'      => $g['shortfall'] ?? 0,
                    ':completion'     => $g['completion'] ?? 0,
                    ':status'         => $statusCalc,
                ]);
            }

            foreach ($allocation as $asset => $share) {
                $stmtAlloc->execute([
                    ':client_id' => $clientId,
                    ':asset'     => $asset,
                    ':share_pct' => $share,
                ]);
            }

            foreach ($schemes as $schemeData) {
                $stmtScheme->execute([
                    ':client_id'          => $clientId,
                    ':scheme_name'        => $schemeData['scheme'] ?? '',
                    ':sip_swp'            => $schemeData['sip_swp'] ?? 0,
                    ':current_value'      => $schemeData['current_value'] ?? 0,
                    ':action_step'        => 'Continue',
                    ':recommended_scheme' => null,
                    ':recommended_amount' => 0,
                ]);
            }

            $clientAttachmentsDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            if (!is_dir($clientAttachmentsDir)) {
                mkdir($clientAttachmentsDir, 0777, true);
            }

            $annexLines = [];
            foreach ($attachments as $att) {
                $annexLines[] = $att['name'];
                $newPath = $clientAttachmentsDir . '/' . basename($att['name']);
                $counter = 1;
                while (file_exists($newPath)) {
                    $newPath = $clientAttachmentsDir . '/' . $counter . '_' . basename($att['name']);
                    $counter++;
                }
                rename($att['path'], $newPath);
            }

            foreach ($annexLines as $line) {
                $stmtAnnex->execute([
                    ':client_id' => $clientId,
                    ':line_text' => $line,
                ]);
            }
        }

        $pdo->commit();

        if ($firstClientId > 0) {
            header('Location: view_report.php?id=' . $firstClientId . '&initial_save=1');
            exit;
        }

        header('Location: view_saved_reports.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $uploadError = $e->getMessage();
    }
}

$navUser     = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);
$filterParam = ($viewContext === 'all') ? 'all' : (($viewContext === 'mine') ? 'mine' : $viewContext);
$userDesignation = $currentUser['designation'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center</title>
    <link rel="stylesheet" href="public/css/upload.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-3hT1sJQdT9v+0kz+1vZ1tcHTul3e8DqRL3OjaxAg/P6MqxsVXni4eWh05rq6ArtyTcwxH8333Adxpv8vS1TukA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="top-bar">
                <img src="image.png" alt="Logo">
                <a href="upload.php" class="nav-brand">Finance Doctor</a>
            </div>
            <div class="nav-links">
                <a href="upload.php" class="<?php echo $currentPage === 'upload.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="view_saved_reports.php" class="<?php echo $currentPage === 'view_saved_reports.php' ? 'active' : ''; ?>">All Reports</a>
                <a href="bulk_import.php" class="<?php echo $currentPage === 'bulk_import.php' ? 'active' : ''; ?>">Bulk Allocate</a>
            </div>
        </div>
        <div class="nav-user" style="position:relative;">
            <span id="profilePic" style="cursor:pointer;">👤 <?php echo htmlspecialchars($navUser); ?></span>
            <div id="profileDropdown" class="profile-dropdown" style="display:none; position:absolute; right:0; top:36px; background:#fff; border:1px solid #eee; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.07); min-width:180px; z-index:100;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding: 8px 12px 5px;">
                    <?= htmlspecialchars($userDesignation) ?>
                </div>
                <a href="profile.php" style="display:block; padding:8px 12px; text-align:right; color:#0288D1; font-weight:600;">My Profile</a>
                <a href="logout.php" class="logout-link" style="display:block; padding:8px 12px; text-align:right;">Logout</a>
            </div>
        </div>
        <script>
            // Simple dropdown toggle
            const profilePic = document.getElementById('profilePic');
            const profileDropdown = document.getElementById('profileDropdown');
            document.addEventListener('click', function(e) {
                if (profilePic.contains(e.target)) {
                    profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
                } else if (!profileDropdown.contains(e.target)) {
                    profileDropdown.style.display = 'none';
                }
            });
        </script>
    </nav>

    <div class="wrap">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width:100%; margin-bottom:20px;">
                <h1 style="margin:0;">Quarterly Review of <?php echo date('F Y'); ?></h1>
                <div style="display: flex; align-items: center; gap: 0;">
                    <form method="get" id="cycleForm" style="margin:15px;">
                        <select name="cycle_filter" onchange="this.form.submit()" style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                            <option value="" <?php if($cycleFilter==='') echo 'selected'; ?>>All Cycles</option>
                            <option value="RJ" <?php if($cycleFilter==='RJ') echo 'selected'; ?>>RJ</option>
                            <option value="RM" <?php if($cycleFilter==='RM') echo 'selected'; ?>>RM</option>
                            <option value="RF" <?php if($cycleFilter==='RF') echo 'selected'; ?>>RF</option>
                        </select>
                        <!-- Preserve view_context in the form if present -->
                        <?php if (isset($_GET['view_context'])): ?>
                            <input type="hidden" name="view_context" value="<?php echo htmlspecialchars($_GET['view_context']); ?>">
                        <?php endif; ?>
                    </form>
                    <?php $cycleParam = $cycleFilter !== '' ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?>
                    <div class="aum-box" style="text-align: right; border-left: 2px solid #e2e8f0; padding-left: 20px;">
                        <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">AUM Handled</div>
                        <div style="font-size: 22px; font-weight: 800; color: #1e293b;">
                            ₹<?= number_format($totalAum, 2); ?> <span style="font-size: 13px;">Cr</span>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="context-navbar">
                    <a href="?view_context=all<?php echo $cycleParam; ?>" class="context-link <?= ($viewContext === 'all') ? 'active' : '' ?>">All Reviews</a>
                    <a href="?view_context=mine<?php echo $cycleParam; ?>" class="context-link <?= ($viewContext === 'mine') ? 'active' : '' ?>">My Reviews</a>
                    <?php foreach ($allUsers as $user): ?>
                        <?php if ((int)$user['id'] === $currentUserId) continue; // Skip logged-in user ?>
                        <a href="?view_context=<?= (int)$user['id'] . $cycleParam ?>" class="context-link <?= ($viewContext == $user['id']) ? 'active' : '' ?>"><?php echo htmlspecialchars($user['username']); ?></a>
                    <?php endforeach; ?>
            </nav>
        </div>

        <div class="kpi-grid">
            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>" class="stats-card card-blue">
                <span class="card-icon"><i class="fa-solid fa-layer-group"></i></span>
                <div class="label">Total Assigned</div>
                <div class="number"><?php echo (int)$viewStats['total']; ?></div>
            </a>

            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=pending" class="stats-card card-red-outline">
                <span class="card-icon"><i class="fa-solid fa-hourglass-half"></i></span>
                <div class="label">Review Not Started</div>
                <div class="number"><?php echo (int)$viewStats['count_pending']; ?></div>
            </a>

            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=draft" class="stats-card card-grey">
                <span class="card-icon"><i class="fa-regular fa-pen-to-square"></i></span>
                <div class="label">Draft</div>
                <div class="number"><?php echo (int)$viewStats['count_draft']; ?></div>
            </a>

            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=ready" class="stats-card card-yellow">
                <span class="card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                <div class="label">Ready</div>
                <div class="number"><?php echo (int)$viewStats['count_ready']; ?></div>
            </a>

            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=reviewed" class="stats-card card-teal">
                <span class="card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
                <div class="label">Reviewed</div>
                <div class="number"><?php echo (int)$viewStats['count_reviewed']; ?></div>
            </a>

            <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=sent" class="stats-card card-green">
                <span class="card-icon"><i class="fa-solid fa-paper-plane"></i></span>
                <div class="label">Sent</div>
                <div class="number"><?php echo (int)$viewStats['count_sent']; ?></div>
            </a>
        </div>

       
       
        <!-- Review not started block removed as requested -->
        <!-- Review not started block removed as requested -->

        <div class="upload-section" style="position:relative;">
            <?php if (!empty($_GET['auto_search'])): ?>
                <div class="client-static-header" style="margin-bottom: 15px;">
                    <h3 style="margin: 0; color: #0288D1;">
                        Client Name: <span style="color: #333;"><?php echo htmlspecialchars($_GET['auto_search']); ?></span>
                    </h3>
                    <input type="hidden" id="clientSearch" value="<?php echo htmlspecialchars($_GET['auto_search']); ?>">
                </div>
            <?php else: ?>
                
            <?php endif; ?>
                        <button type="button" id="refreshFiles" class="refresh-icon-btn" title="Clear selected files">
                                <span class="refresh-svg-icon" id="refreshSvgIcon">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M23.5 8.5A11 11 0 1 0 27 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
                                        <polygon points="27,16 23,13.5 23,18.5" fill="#0288D1"/>
                                        <path d="M8.5 23.5A11 11 0 1 0 5 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
                                        <polygon points="5,16 9,18.5 9,13.5" fill="#0288D1"/>
                                    </svg>
                                </span>
                        </button>
            <h3>Upload & Generate Reports</h3>
            <p>Attach Excel and PDF files. We will parse and build the reports.</p>
            <?php if ($uploadError !== ''): ?>
                <div class="flash-error">Error: <?php echo htmlspecialchars($uploadError); ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="upload-zone" id="uploadZone">
                    <div class="upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <p style="margin: 0; font-weight: 600; color: var(--text-strong);">Drag & drop files here</p>
                    <!-- Hide the default file input completely -->
                    <input type="file" name="client_files[]" id="client_files" class="file-input" multiple required style="display:none;">
                    <label for="client_files" class="btn-ash" style="margin-top:10px; display:inline-block; cursor:pointer;">
                        <i class="fa-solid fa-file-import"></i> Choose Files
                    </label>
                    <div id="fileList" style="margin-top: 16px; display: none; text-align: left; width: 100%;">
                        <h4 style="margin: 0 0 8px; font-size: 14px; color: var(--text-strong);">Selected Files:</h4>
                        <ul id="selectedFiles" style="margin: 0; padding: 0; list-style: none; font-size: 13px; color: var(--text);"></ul>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="btn-primary">Generate Reports</button>
                </div>
            </form>
            <script>
                // Auto-fill client search input from URL param 'auto_search'
                (function() {
                    function getQueryParam(name) {
                        const url = new URL(window.location.href);
                        return url.searchParams.get(name) || '';
                    }
                    var autoSearch = getQueryParam('auto_search');
                    if (autoSearch) {
                        var input = document.getElementById('clientSearchInput');
                        if (input) input.value = autoSearch;
                    }
                })();
                // Only one button will be visible now
                const fileInput = document.getElementById('client_files');
                const fileList = document.getElementById('fileList');
                const selectedFiles = document.getElementById('selectedFiles');
                const refreshBtn = document.getElementById('refreshFiles');
                const refreshSvgIcon = document.getElementById('refreshSvgIcon');

                fileInput.addEventListener('change', function() {
                    selectedFiles.innerHTML = '';
                    const files = Array.from(this.files);
                    if (files.length > 0) {
                        files.forEach((file, index) => {
                            const li = document.createElement('li');
                            li.style.padding = '6px 0';
                            li.style.borderBottom = '1px solid #e2e8f0';
                            li.textContent = (index + 1) + '. ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                            selectedFiles.appendChild(li);
                        });
                        fileList.style.display = 'block';
                    } else {
                        fileList.style.display = 'none';
                    }
                });

                refreshBtn.addEventListener('click', function() {
                    // Start rotation
                    refreshSvgIcon.classList.add('rotating');
                    // Simulate clearing files
                    setTimeout(function() {
                        fileInput.value = '';
                        selectedFiles.innerHTML = '';
                        fileList.style.display = 'none';
                        // Stop rotation
                        refreshSvgIcon.classList.remove('rotating');
                    }, 600); // Duration of rotation (ms)
                });
            </script>
        </div> <!-- end .wrap main content -->

    <div class="section">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3>Scheme Overview</h3>
            <a href="schemes.php" class="btn-primary" style="font-size:12px; padding:6px 12px;">Manage Schemes</a>
        </div>
    </div>
</body>
</html>
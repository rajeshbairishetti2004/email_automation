<?php
// upload.php
// Clean rebuild: dashboard + upload/parse/save pipeline

require_once 'auth.php';
require_once 'db_config.php';
require_once 'parsers.php';
require_once 'renderers.php';
require_once 'env_loader.php';

requireAuth();

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

function fetchDashboardStats(PDO $pdo, string $context, int $userId): array
{
    $where  = '1=1';
    $params = [];
    if ($context === 'mine') {
        $where = 'assigned_to = :uid';
        $params[':uid'] = $userId;
    } elseif (ctype_digit($context)) {
        $where = 'assigned_to = :uid';
        $params[':uid'] = (int)$context;
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

$viewContext = $_GET['view_context'] ?? 'mine';
$targetName  = 'My';
if ($viewContext === 'all') {
    $targetName = 'Global';
} elseif (ctype_digit($viewContext)) {
    $targetName = 'User';
}

$usersStmt = $pdo->query('SELECT id, username FROM users ORDER BY username ASC');
$allUsers  = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$viewStats       = fetchDashboardStats($pdo, $viewContext, $currentUserId);
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
            (name, as_on, total_amount, profit, cagr, xirr, absolute_return,
             total_goal_current, total_goal_target, total_sip,
             greeting_prefix, intro_text, closing_text, rationale_text,
             created_by, report_state, assigned_to)
            VALUES
            (:name, :as_on, :total_amount, :profit, :cagr, :xirr, :absolute_return,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center</title>
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-3hT1sJQdT9v+0kz+1vZ1tcHTul3e8DqRL3OjaxAg/P6MqxsVXni4eWh05rq6ArtyTcwxH8333Adxpv8vS1TukA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #334155;
            --text-strong: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); }
        a { color: var(--primary); text-decoration: none; }
        a:hover { color: var(--primary-dark); }

        .navbar { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,0.94); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow); }
        .nav-left { display: flex; align-items: center; gap: 24px; }
        .nav-brand { font-size: 1.1rem; font-weight: 700; color: var(--text-strong); letter-spacing: 0.01em; }
        .nav-links a { margin-right: 14px; font-weight: 600; color: var(--muted); padding: 6px 0; border-bottom: 2px solid transparent; }
        .nav-links a.active { color: var(--primary); border-color: var(--primary); }
        .nav-user { display: flex; align-items: center; gap: 10px; font-size: 0.95rem; color: var(--muted); }
        .btn-logout { padding: 8px 12px; background: #fee2e2; color: #b91c1c; border-radius: 8px; font-weight: 700; }
        .btn-logout:hover { background: #fecdd3; }

        .wrap { max-width: 1260px; margin: 0 auto; padding: 28px 20px 60px; }
        h1 { margin: 6px 0 6px; font-size: 32px; font-weight: 800; color: var(--text-strong); letter-spacing: -0.01em; }
        p.lead { margin: 0 0 26px; color: var(--muted); font-size: 15px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
        .context-switch { display: inline-flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); }
        .context-switch label { font-size: 13px; font-weight: 600; color: var(--text-strong); }
        .context-select { appearance: none; padding: 10px 34px 10px 12px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface) url('data:image/svg+xml,%3csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2220%22 height=%2220%22 fill=%22%234475a2%22 viewBox=%220 0 24 24%22%3e%3cpath d=%22M7 10l5 5 5-5H7z%22/%3e%3c/svg%3e') no-repeat right 10px center; font-size: 14px; color: var(--text); font-weight: 600; cursor: pointer; }

        .kpi-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        @media (max-width: 1080px) { .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 720px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .stats-card { position: relative; display: block; padding: 16px 16px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); transition: transform 0.16s ease, box-shadow 0.16s ease; color: var(--text); }
        .stats-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(15,23,42,0.08); }
        .stats-card .label { font-size: 12px; font-weight: 600; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; }
        .stats-card .number { margin-top: 6px; font-size: 26px; font-weight: 800; color: var(--text-strong); }
        .card-icon { position: absolute; top: 10px; right: 12px; color: var(--text); opacity: 0.15; font-size: 20px; }
        .card-blue { border-left: 4px solid #2563eb; }
        .card-red-outline { border-left: 4px solid #ef4444; }
        .card-grey { border-left: 4px solid #94a3b8; }
        .card-yellow { border-left: 4px solid #f59e0b; }
        .card-teal { border-left: 4px solid #0ea5e9; }
        .card-green { border-left: 4px solid #22c55e; }

        .completion-card { margin-top: 8px; padding: 18px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .completion-title { font-weight: 700; color: var(--text-strong); font-size: 16px; margin: 0; }
        .completion-sub { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
        .progress-shell { flex: 1; min-width: 240px; height: 12px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #2563eb 0%, #1d4ed8 100%); }
        .completion-value { font-size: 22px; font-weight: 800; color: var(--text-strong); }

        .upload-section { margin-top: 26px; padding: 24px; background: var(--surface); border: 1px dashed #cbd5e1; border-radius: 16px; box-shadow: var(--shadow); text-align: center; }
        .upload-section h3 { margin: 0 0 6px; font-size: 18px; color: var(--text-strong); }
        .upload-section p { margin: 0 0 16px; color: var(--muted); font-size: 14px; }
        .upload-zone { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 28px; background: #f8fafc; display: flex; flex-direction: column; align-items: center; gap: 12px; }
        .upload-icon { font-size: 36px; color: var(--primary); opacity: 0.85; }
        .btn-primary { display: inline-block; padding: 12px 22px; background: var(--primary); color: white; border-radius: 10px; font-weight: 700; box-shadow: var(--shadow); border: none; cursor: pointer; }
        .btn-primary:hover { background: var(--primary-dark); }
        .file-input { display: none; }

        .pending-alert { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 12px; background: #fff7ed; border: 1px solid #fed7aa; margin-bottom: 18px; }
        .pending-pill { background: #f97316; color: #fff; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .section { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); padding: 16px; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { font-weight: 700; color: #475569; }
        .progress { width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #0ea5e9, #2563eb); }

        .context-navbar {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .context-link {
            padding: 10px 16px;
            border-radius: 999px;
            font-weight: 600;
            color: var(--muted);
            transition: background 0.2s ease, color 0.2s ease;
        }
        .context-link:hover {
            background: rgba(37,99,235,0.1);
            color: var(--text-strong);
        }
        .context-link.active {
            background: var(--primary);
            color: white;
        }

        .top-bar {
            display: flex;
            align-items: center;
            padding: 10px 28px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            margin-bottom: 18px;
        }
        .top-bar img {
            height: 40px;
            vertical-align: middle;
            margin-right: 10px;
        }
        .brand-text {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-strong);
            letter-spacing: 0.01em;
        }
    </style>
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
        <div class="nav-user">
            <span>👤 <?php echo htmlspecialchars($navUser); ?></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="wrap">
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <p class="lead">Overview of performance, workload, and delivery status.</p>
            </div>

            <nav class="context-navbar">
                <a href="?view_context=all" class="context-link <?= ($viewContext === 'all') ? 'active' : '' ?>">All Reviews</a>
                <a href="?view_context=mine" class="context-link <?= ($viewContext === 'mine') ? 'active' : '' ?>">My Workspace</a>
                
                <?php foreach ($allUsers as $u): ?>
                    <?php if ($u['id'] != $currentUserId): ?>
                        <a href="?view_context=<?= $u['id'] ?>" 
                           class="context-link <?= ($viewContext == $u['id']) ? 'active' : '' ?>">
                           <?= htmlspecialchars($u['username']) ?>
                        </a>
                    <?php endif; ?>
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

        <div class="completion-card">
            <div>
                <p class="completion-title">Completion rate</p>
                <p class="completion-sub">Sent vs total for <?php echo strtolower($targetName); ?> reports.</p>
            </div>
            <div class="progress-shell" aria-label="Completion progress">
                <div class="progress-fill" style="width: <?php echo $completionRate; ?>%;"></div>
            </div>
            <div class="completion-value"><?php echo $completionRate; ?>%</div>
            <div style="color: var(--muted); font-size: 13px; font-weight: 600;">
                <?php echo (int)$viewStats['count_sent']; ?> sent / <?php echo (int)$viewStats['total']; ?> total
            </div>
        </div>

        <?php if ($viewStats['count_pending'] > 0): ?>
            <div class="pending-alert">
                <span class="pending-pill">Review Not Started</span>
                <div>
                    <h4 style="margin: 0; color: #9a3412;">Review not started</h4>
                    <p style="margin: 4px 0 0; color: #7c2d12; font-size: 13px;"><?php echo (int)$viewStats['count_pending']; ?> client(s) still need their first review pass.</p>
                </div>
                <div style="margin-left:auto;">
                    <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=pending">View not started</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="upload-section">
            <h3>Upload &amp; Generate Reports</h3>
            <p>Attach Excel and PDF files. We will parse, assign, and build the client reports for you.</p>
            <?php if ($uploadError !== ''): ?>
                <div style="margin-bottom:12px; padding: 10px 12px; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b; font-weight:600;">Error: <?php echo htmlspecialchars($uploadError); ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="upload-zone" id="uploadZone">
                    <div class="upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <p style="margin: 0; font-weight: 600; color: var(--text-strong);">Drag &amp; drop files here</p>
                    <p style="margin: 0; color: var(--muted);">or click to select from your computer</p>
                    <input type="file" name="client_files[]" id="client_files" class="file-input" multiple required>
                    <label for="client_files" class="btn-primary" style="cursor:pointer;">Select Files</label>
                    <div id="fileList" style="margin-top: 16px; display: none;">
                        <h4 style="margin: 0 0 8px; font-size: 14px; color: var(--text-strong);">Selected Files:</h4>
                        <ul id="selectedFiles" style="margin: 0; padding: 0; list-style: none; font-size: 13px; color: var(--text);"></ul>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="btn-primary">Generate Reports</button>
                </div>
            </form>
            <script>
                const fileInput = document.getElementById('client_files');
                const fileList = document.getElementById('fileList');
                const selectedFiles = document.getElementById('selectedFiles');
                
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
            </script>
        </div>
    </div>
</body>
</html>

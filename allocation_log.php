<?php
// allocation_log.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);

$username = strtolower($currentUser['username'] ?? '');

if ($username !== 'admin') {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$pdo = getPdo();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// ── AJAX: Dashboard KPI stats for a specific allocation_log entry ─────────
if (isset($_GET['ajax_alloc_dashboard']) && isset($_GET['allocation_log_id'])) {
    header('Content-Type: application/json');
    $logId = (int)$_GET['allocation_log_id'];

    if (!$logId) {
        echo json_encode(['success' => false, 'error' => 'Invalid id']);
        exit;
    }

    try {
        // Fetch allocation_log meta
        $logStmt = $pdo->prepare("
            SELECT al.*, u.username AS uploader_name
            FROM allocation_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.id = ?
            LIMIT 1
        ");
        $logStmt->execute([$logId]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            exit;
        }

        // KPI stats for clients linked to this allocation.
        // Strategy: for each distinct client name in this allocation_id,
        // pick the single row that actually belongs to this allocation
        // (highest id with this allocation_id) so we never double-count
        // clients who were re-uploaded and got a new allocation_id later.
        $kpiStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT c.name)                                            AS total,
                SUM(CASE WHEN c.report_state = 'pending'  THEN 1 ELSE 0 END)     AS count_pending,
                SUM(CASE WHEN c.report_state = 'draft'    THEN 1 ELSE 0 END)     AS count_draft,
                SUM(CASE WHEN c.report_state = 'ready'    THEN 1 ELSE 0 END)     AS count_ready,
                SUM(CASE WHEN c.report_state = 'reviewed' THEN 1 ELSE 0 END)     AS count_reviewed,
                SUM(CASE WHEN c.report_state = 'sent'     THEN 1 ELSE 0 END)     AS count_sent,
                ROUND(SUM(c.aum), 2)                                              AS total_aum,
                SUM(CASE WHEN c.meeting_date IS NOT NULL  THEN 1 ELSE 0 END)     AS meetings_fixed
            FROM clients c
            INNER JOIN (
                SELECT name, MAX(id) AS max_id
                FROM clients
                WHERE allocation_id = ?
                GROUP BY name
            ) AS latest_in_alloc ON c.id = latest_in_alloc.max_id
        ");
        $kpiStmt->execute([$logId]);
        $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

        // Per-RM breakdown — same strategy: one row per client name,
        // using the record that belongs to this allocation_id.
        $rmStmt = $pdo->prepare("
            SELECT
                u.username                                                        AS rm_name,
                COUNT(DISTINCT c.name)                                            AS total,
                SUM(CASE WHEN c.report_state = 'pending'  THEN 1 ELSE 0 END)     AS count_pending,
                SUM(CASE WHEN c.report_state = 'draft'    THEN 1 ELSE 0 END)     AS count_draft,
                SUM(CASE WHEN c.report_state = 'ready'    THEN 1 ELSE 0 END)     AS count_ready,
                SUM(CASE WHEN c.report_state = 'reviewed' THEN 1 ELSE 0 END)     AS count_reviewed,
                SUM(CASE WHEN c.report_state = 'sent'     THEN 1 ELSE 0 END)     AS count_sent,
                ROUND(SUM(c.aum), 2)                                              AS total_aum
            FROM clients c
            INNER JOIN (
                SELECT name, MAX(id) AS max_id
                FROM clients
                WHERE allocation_id = ?
                GROUP BY name
            ) AS latest_in_alloc ON c.id = latest_in_alloc.max_id
            LEFT JOIN users u ON u.id = c.assigned_to
            GROUP BY c.assigned_to, u.username
            ORDER BY total DESC
        ");
        $rmStmt->execute([$logId]);
        $rmBreakdown = $rmStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'log'          => $log,
            'kpi'          => $kpi,
            'rm_breakdown' => $rmBreakdown,
        ]);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// Handle delete actions
$successMessage = '';
$errorMessage = '';

// Handle single delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_allocation'])) {
    $allocationId = (int)$_POST['allocation_id'];
    if ($allocationId > 0) {
        try {
            $deleteStmt = $pdo->prepare("DELETE FROM allocation_log WHERE id = ?");
            $deleteStmt->execute([$allocationId]);
            $successMessage = "Allocation record deleted successfully!";
        } catch (Exception $e) {
            $errorMessage = "Error deleting allocation: " . $e->getMessage();
        }
    }
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
    if (!empty($selectedIds)) {
        try {
            $selectedIds = array_filter(array_map('intval', $selectedIds));
            if (!empty($selectedIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM allocation_log WHERE id IN ($placeholders)");
                $deleteStmt->execute($selectedIds);
                $affectedRows = $deleteStmt->rowCount();
                $successMessage = "Successfully deleted $affectedRows allocation record(s).";
            }
        } catch (Exception $e) {
            $errorMessage = "Error deleting allocations: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please select at least one allocation to delete.";
    }
}

$deleteMode = isset($_GET['delete_mode']) && $_GET['delete_mode'] === '1';

$monthStmt = $pdo->query("SELECT DISTINCT month_year FROM allocation_log ORDER BY month_year DESC");
$months = $monthStmt->fetchAll(PDO::FETCH_COLUMN);

$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-t');
$selectedMonth = $_GET['month'] ?? '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$whereClauses = [];
$params = [];

if (!empty($selectedMonth)) {
    $whereClauses[] = "al.month_year = ?";
    $params[] = $selectedMonth;
} else {
    $whereClauses[] = "DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
}

if (!empty($q)) {
    $whereClauses[] = '('
        . "DATE(al.created_at) LIKE ? "
        . "OR u.name LIKE ? "
        . "OR u.username LIKE ? "
        . "OR al.month_year LIKE ? "
        . "OR al.target_tag LIKE ? "
        . "OR al.clients_count LIKE ? "
        . "OR al.assigned_count LIKE ? "
        . "OR al.inserted_count LIKE ? "
        . "OR al.updated_count LIKE ? "
        . "OR al.file_name LIKE ? "
    . ')';
    for ($i = 0; $i < 10; $i++) {
        $params[] = "%$q%";
    }
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$query = "SELECT al.*, u.name as user_name, u.username 
          FROM allocation_log al 
          LEFT JOIN users u ON al.user_id = u.id 
          $whereSQL 
          ORDER BY al.created_at DESC";

$stmt = $pdo->prepare($query);

try {
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Query error: " . $e->getMessage());
    $logs = [];
    $errorMessage = "Error loading allocation logs: " . $e->getMessage();
}

function getPeriodStatistics($pdo, $fromDate, $toDate, $selectedMonth = '') {
    $stats = [
        'total_allocations' => 0,
        'total_clients_processed' => 0,
        'total_clients_assigned' => 0,
        'total_clients_inserted' => 0,
        'total_clients_updated' => 0,
        'unique_importers' => 0,
        'unique_tags' => 0,
        'monthly_breakdown' => []
    ];

    $whereClauses = [];
    $params = [];

    if (!empty($selectedMonth)) {
        $whereClauses[] = "month_year = ?";
        $params[] = $selectedMonth;
    } else {
        $whereClauses[] = "DATE(created_at) BETWEEN ? AND ?";
        $params[] = $fromDate;
        $params[] = $toDate;
    }

    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM allocation_log $whereSQL");
        $countStmt->execute($params);
        $stats['total_allocations'] = $countStmt->fetchColumn();

        $sumStmt = $pdo->prepare("SELECT 
            SUM(clients_count) as total_clients,
            SUM(assigned_count) as total_assigned,
            SUM(inserted_count) as total_inserted,
            SUM(updated_count) as total_updated
            FROM allocation_log $whereSQL");
        $sumStmt->execute($params);
        $sums = $sumStmt->fetch(PDO::FETCH_ASSOC);

        $stats['total_clients_processed'] = $sums['total_clients'] ?? 0;
        $stats['total_clients_assigned']  = $sums['total_assigned'] ?? 0;
        $stats['total_clients_inserted']  = $sums['total_inserted'] ?? 0;
        $stats['total_clients_updated']   = $sums['total_updated'] ?? 0;

        $userStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM allocation_log $whereSQL");
        $userStmt->execute($params);
        $stats['unique_importers'] = $userStmt->fetchColumn();

        $tagStmt = $pdo->prepare("SELECT COUNT(DISTINCT target_tag) FROM allocation_log $whereSQL");
        $tagStmt->execute($params);
        $stats['unique_tags'] = $tagStmt->fetchColumn();

        if (empty($selectedMonth)) {
            $monthlyStmt = $pdo->prepare("
                SELECT month_year,
                    COUNT(*) as allocation_count,
                    SUM(clients_count) as total_clients,
                    SUM(assigned_count) as assigned_clients,
                    SUM(inserted_count) as inserted_clients,
                    SUM(updated_count) as updated_clients
                FROM allocation_log 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY month_year 
                ORDER BY month_year DESC
            ");
            $monthlyStmt->execute([$fromDate, $toDate]);
            $stats['monthly_breakdown'] = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Statistics error: " . $e->getMessage());
    }

    return $stats;
}

$periodStats = getPeriodStatistics($pdo, $fromDate, $toDate, $selectedMonth);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Allocation Log & Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="public/css/allocation_log.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    <style>
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            display: <?php echo !$deleteMode ? 'block' : 'none'; ?>;
        }

        /* ── Dashboard Button ────────────────────────────────────────── */
        .btn-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: #e8f4fd;
            color: #0277bd;
            border: 1px solid #b3d9f5;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, color .15s;
        }
        .btn-dashboard:hover {
            background: #0288d1;
            color: #fff;
            border-color: #0288d1;
        }

        /* ══════════════════════════════════════════════════════════
           ALLOCATION DASHBOARD MODAL  — full redesign
        ══════════════════════════════════════════════════════════ */

        /* Backdrop */
        #allocDashModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(8, 16, 36, .55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        #allocDashModal.open { display: flex; }

        /* Modal shell */
        .adm-box {
            background: #f8fafc;
            border-radius: 20px;
            width: min(900px, 100%);
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 32px 80px rgba(0,0,0,.28), 0 0 0 1px rgba(255,255,255,.06);
            font-family: 'Inter', sans-serif;
            animation: admIn .28s cubic-bezier(.34,1.4,.64,1);
        }
        @keyframes admIn {
            from { opacity:0; transform:translateY(28px) scale(.96); }
            to   { opacity:1; transform:translateY(0)    scale(1);   }
        }
        .adm-box::-webkit-scrollbar { width: 4px; }
        .adm-box::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }

        /* ── Header strip ───────────────────────────── */
        .adm-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 22px 26px 20px;
            background: #fff;
            border-radius: 20px 20px 0 0;
            border-bottom: 1px solid #edf2f7;
            position: sticky; top: 0; z-index: 5;
        }
        .adm-title {
            font-size: 17px; font-weight: 800;
            color: #0f172a; margin: 0; letter-spacing: -.4px;
        }
        .adm-subtitle {
            font-size: 12px; color: #94a3b8;
            margin-top: 3px; font-weight: 500;
        }
        .adm-close {
            flex-shrink: 0;
            width: 30px; height: 30px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 8px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 12px;
            transition: all .15s;
        }
        .adm-close:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

        /* ── Body ───────────────────────────────────── */
        .adm-body { padding: 22px 26px 30px; }

        /* ── Meta info strip ────────────────────────── */
        .adm-meta-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 14px 16px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #edf2f7;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .adm-meta-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 99px;
            font-size: 12px; font-weight: 600; color: #475569;
            white-space: nowrap;
        }
        .adm-meta-pill i { font-size: 11px; color: #0288d1; }

        /* ── Section label ──────────────────────────── */
        .adm-section-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .8px; text-transform: uppercase;
            color: #94a3b8;
            margin: 0 0 12px;
        }
        .adm-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        /* ── KPI grid — 4 cols, cards fill evenly ───── */
        .adm-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }
        @media (max-width: 620px) {
            .adm-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* Card base */
        .adm-kpi-card {
            position: relative;
            background: #fff;
            border-radius: 10px;
            padding: 11px 10px 10px 12px;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            min-height: 72px;
            border: 1px solid #e8eef4;
            overflow: hidden;
            transition: box-shadow .18s, transform .18s, border-color .18s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .adm-kpi-card:hover {
            box-shadow: 0 4px 14px rgba(0,0,0,0.10);
            transform: translateY(-2px);
            border-color: transparent;
        }

        /* Faint background icon */
        .adm-kpi-icon {
            position: absolute;
            top: 9px; right: 10px;
            font-size: 22px;
            line-height: 1;
            pointer-events: none;
            opacity: 0.09;
        }

        /* Label */
        .adm-kpi-label {
            position: relative; z-index: 1;
            font-size: 8.5px; font-weight: 700;
            letter-spacing: .9px; text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 5px; line-height: 1.3;
        }

        /* Number */
        .adm-kpi-val {
            position: relative; z-index: 1;
            font-size: 24px; font-weight: 800;
            line-height: 1; letter-spacing: -1px;
        }

        /* Colour variants */
        .adm-c-blue   { border-left: 3px solid #0288d1; }
        .adm-c-blue   .adm-kpi-val  { color: #0277bd; }
        .adm-c-blue   .adm-kpi-icon { color: #0288d1; }

        .adm-c-red    { border-left: 3px solid #e53935; }
        .adm-c-red    .adm-kpi-val  { color: #c62828; }
        .adm-c-red    .adm-kpi-icon { color: #e53935; }

        .adm-c-grey   { border-left: 3px solid #78909c; }
        .adm-c-grey   .adm-kpi-val  { color: #546e7a; }
        .adm-c-grey   .adm-kpi-icon { color: #78909c; }

        .adm-c-yellow { border-left: 3px solid #f59e0b; }
        .adm-c-yellow .adm-kpi-val  { color: #b45309; }
        .adm-c-yellow .adm-kpi-icon { color: #f59e0b; }

        .adm-c-teal   { border-left: 3px solid #00897b; }
        .adm-c-teal   .adm-kpi-val  { color: #00695c; }
        .adm-c-teal   .adm-kpi-icon { color: #00897b; }

        .adm-c-green  { border-left: 3px solid #43a047; }
        .adm-c-green  .adm-kpi-val  { color: #2e7d32; }
        .adm-c-green  .adm-kpi-icon { color: #43a047; }

        .adm-c-indigo { border-left: 3px solid #5c6bc0; }
        .adm-c-indigo .adm-kpi-val  { color: #3949ab; }
        .adm-c-indigo .adm-kpi-icon { color: #5c6bc0; }

        /* ── Progress bar ───────────────────────────── */
        .adm-progress-wrap {
            background: #fff;
            border: 1px solid #edf2f7;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .adm-progress-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 10px;
        }
        .adm-progress-title { font-size: 12px; font-weight: 700; color: #334155; }
        .adm-progress-pct {
            font-size: 15px; font-weight: 800; color: #2e7d32;
        }
        .adm-progress-bar {
            height: 9px; border-radius: 99px;
            background: #e2e8f0; overflow: hidden;
        }
        .adm-progress-fill {
            height: 100%; border-radius: 99px;
            background: linear-gradient(90deg, #43a047, #2e7d32);
            transition: width .7s cubic-bezier(.4,0,.2,1);
            position: relative;
        }
        .adm-progress-fill::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255,255,255,.25) 0%, transparent 100%);
            border-radius: 99px;
        }

        /* ── RM Breakdown table ─────────────────────── */
        .adm-rm-wrap {
            background: #fff;
            border: 1px solid #edf2f7;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        .adm-rm-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .adm-rm-table th {
            background: #f8fafc; color: #64748b;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: .5px; text-transform: uppercase;
            padding: 10px 14px; text-align: left;
            border-bottom: 1px solid #edf2f7;
        }
        .adm-rm-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b; vertical-align: middle;
        }
        .adm-rm-table tbody tr:last-child td { border-bottom: none; }
        .adm-rm-table tbody tr:hover td { background: #f8fafc; }
        .adm-rm-name { font-weight: 700; color: #0f172a; }

        /* Inline count pills for the RM table */
        .sp {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 28px; padding: 3px 8px;
            border-radius: 99px; font-size: 11px; font-weight: 700;
        }
        .sp-0 { background: #f1f5f9; color: #94a3b8; }
        .sp-pending  { background: #fee2e2; color: #b91c1c; }
        .sp-draft    { background: #f1f5f9; color: #475569; }
        .sp-ready    { background: #fef9c3; color: #854d0e; }
        .sp-reviewed { background: #ccfbf1; color: #065f46; }
        .sp-sent     { background: #dcfce7; color: #166534; }

        /* ── Footer actions ─────────────────────────── */
        .adm-footer {
            display: flex; align-items: center; justify-content: flex-end;
            padding-top: 4px;
        }
        .adm-view-all {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 22px;
            background: linear-gradient(135deg, #0288d1, #0277bd);
            color: #fff;
            border-radius: 10px; font-size: 13px; font-weight: 700;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(2,136,209,.30);
            transition: all .18s;
        }
        .adm-view-all:hover {
            background: linear-gradient(135deg, #039be5, #0277bd);
            box-shadow: 0 4px 16px rgba(2,136,209,.40);
            transform: translateY(-1px);
        }

        /* Loading state */
        .adm-loading { text-align:center; padding:60px 20px; color:#94a3b8; }
        .adm-loading i {
            font-size: 32px; color: #0288d1;
            animation: adm-spin 1s linear infinite;
            display: block; margin-bottom: 14px;
        }
        @keyframes adm-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="<?php echo $deleteMode ? 'delete-mode-active' : ''; ?>">
    <?php include 'navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2><i class="fas fa-chart-line"></i> Allocation Log & Analytics</h2>
            <div>
                <?php if (!$deleteMode): ?>
                    <?php
                    $deleteModeParams = [];
                    if ($fromDate != date('Y-m-01')) $deleteModeParams['from_date'] = $fromDate;
                    if ($toDate != date('Y-m-t')) $deleteModeParams['to_date'] = $toDate;
                    if ($selectedMonth) $deleteModeParams['month'] = $selectedMonth;
                    $deleteModeQuery = !empty($deleteModeParams) ? '?' . http_build_query($deleteModeParams) . '&delete_mode=1' : '?delete_mode=1';
                    ?>
                    <a href="allocation_log.php<?php echo $deleteModeQuery; ?>" class="delete-mode-btn">
                        <i class="fa-solid fa-trash"></i> Enable Delete Mode
                    </a>
                <?php else: ?>
                    <?php
                    $paramString = '';
                    $firstParam = true;
                    function addParam(&$paramString, &$firstParam, $name, $value) {
                        if (!empty($value)) {
                            $paramString .= $firstParam ? '?' : '&';
                            $paramString .= $name . '=' . urlencode($value);
                            $firstParam = false;
                        }
                    }
                    addParam($paramString, $firstParam, 'from_date', $fromDate);
                    addParam($paramString, $firstParam, 'to_date', $toDate);
                    addParam($paramString, $firstParam, 'month', $selectedMonth);
                    ?>
                    <a href="allocation_log.php<?php echo $paramString; ?>" class="cancel-delete-btn">
                        <i class="fa-solid fa-times"></i> Cancel Delete Mode
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success" id="successMessage">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($deleteMode): ?>
            <div class="alert alert-warning">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Delete Mode Active</strong>
                <p style="margin: 5px 0 0 0;">Select allocations using checkboxes, then click "Delete Selected" to remove them.</p>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filters">
            <form method="get" id="filterForm">
                <?php if ($deleteMode): ?>
                    <input type="hidden" name="delete_mode" value="1">
                <?php endif; ?>
                <?php if (!empty($q)): ?>
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                <?php endif; ?>

                <div class="filter-row">
                    <div class="filter-group">
                        <label for="month">Filter by Month:</label>
                        <select name="month" id="month" onchange="this.form.submit()">
                            <option value="">-- Select Month --</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?php echo htmlspecialchars($month); ?>" <?php echo $selectedMonth == $month ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($month); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Date Range (if month not selected):</label>
                        <div class="date-range-picker">
                            <input type="text" name="from_date" class="datepicker date-input"
                                   value="<?php echo htmlspecialchars($fromDate); ?>"
                                   placeholder="From Date"
                                   onchange="document.getElementById('month').value='';">
                            <span>to</span>
                            <input type="text" name="to_date" class="datepicker date-input"
                                   value="<?php echo htmlspecialchars($toDate); ?>"
                                   placeholder="To Date"
                                   onchange="document.getElementById('month').value='';">
                        </div>
                    </div>

                    <div class="filter-group" style="display:flex; align-items:flex-end; gap:10px;">
                        <button type="submit" class="btn-apply">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="allocation_log.php<?php echo $deleteMode ? '?delete_mode=1' : ''; ?>" class="btn-reset">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk Actions Bar -->
        <?php if ($deleteMode && !empty($logs)): ?>
        <form method="post" id="bulkDeleteForm">
            <input type="hidden" name="bulk_delete" value="1">
            <div class="bulk-actions-bar">
                <span class="bulk-selection-info">With Selected:</span>
                <button type="button" onclick="confirmDelete()" class="btn-delete" style="margin-left:0;">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
                <span id="selectedCount" style="color:#666; font-size:13px;">0 items selected</span>
            </div>
        <?php endif; ?>

        <!-- Table -->
        <?php if (!empty($logs)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php if ($deleteMode): ?>
                        <th style="width:40px;" class="select-all-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="client-checkbox" onclick="toggleSelectAll(this)">
                            <span class="select-all-label">All</span>
                        </th>
                        <?php endif; ?>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Month</th>
                        <th>Tag</th>
                        <th>Clients</th>
                        <th>Assigned</th>
                        <th>Details</th>
                        <th>File</th>
                        <th>Actions</th>
                        <th>Dashboard</th><!-- ← NEW COLUMN -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr class="clickable-row" onclick="!<?php echo $deleteMode ? 'true' : 'false'; ?> && viewAllocationDetails(<?php echo $log['id']; ?>)">
                        <?php if ($deleteMode): ?>
                        <td>
                            <input type="checkbox" class="client-checkbox delete-checkbox" name="selected_ids[]" value="<?php echo (int)$log['id']; ?>" onchange="updateSelectedCount()">
                        </td>
                        <?php endif; ?>
                        <td>
                            <div><?php echo date('d M Y', strtotime($log['created_at'])); ?></div>
                            <div class="timestamp"><?php echo date('h:i A', strtotime($log['created_at'])); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($log['user_name'] ?: $log['username']); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($log['month_year']); ?></span></td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($log['target_tag']); ?></span></td>
                        <td><strong><?php echo (int)$log['clients_count']; ?></strong></td>
                        <td>
                            <?php
                            $assignedPercent = $log['clients_count'] > 0
                                ? round(($log['assigned_count'] / $log['clients_count']) * 100) : 0;
                            echo '<span class="badge badge-success">' . (int)$log['assigned_count'] . ' (' . $assignedPercent . '%)</span>';
                            ?>
                        </td>
                        <td>
                            <small>
                                +<?php echo (int)$log['inserted_count']; ?> new,
                                ↑<?php echo (int)$log['updated_count']; ?> updated
                            </small>
                        </td>
                        <td>
                            <?php if (!empty($log['file_name'])): ?>
                                <small><?php echo htmlspecialchars($log['file_name']); ?></small>
                            <?php else: ?>
                                <small class="timestamp">N/A</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-view" onclick="event.stopPropagation(); viewAllocationDetails(<?php echo $log['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (!$deleteMode): ?>
                                <button class="btn-delete" onclick="event.stopPropagation(); confirmSingleDelete(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars(addslashes($log['month_year'] . ' - ' . $log['target_tag'])); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <!-- ← NEW DASHBOARD CELL -->
                        <td>
                            <button
                                class="btn-dashboard"
                                onclick="event.stopPropagation(); openAllocDashboard(<?php echo (int)$log['id']; ?>, '<?php echo htmlspecialchars(addslashes($log['month_year'] . ' — ' . $log['target_tag'])); ?>')"
                                title="View KPI dashboard for this allocation"
                            >
                                <i class="fas fa-chart-bar"></i> Dashboard
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($deleteMode): ?>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-chart-bar"></i>
            <h3>No allocation records found for selected period</h3>
            <p>Try selecting a different date range or month.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="delete-confirm-modal">
        <div class="delete-confirm-content">
            <div class="warning-icon">
                <i class="fa-solid fa-exclamation-triangle"></i>
            </div>
            <h3 style="color:#e53935; text-align:center; margin-bottom:15px;">Confirm Deletion</h3>
            <p id="deleteConfirmMessage" style="text-align:center; margin-bottom:25px;">
                Are you sure you want to delete <span id="deleteCount">0</span> selected allocation(s)?
            </p>
            <p style="text-align:center; color:#666; font-size:14px; margin-bottom:25px;">
                <i class="fa-solid fa-exclamation-circle"></i> This action cannot be undone.
            </p>
            <div style="display:flex; justify-content:center; gap:15px;">
                <button type="button" onclick="closeDeleteModal()" style="padding:10px 24px; border:1px solid #ced4da; background:#fff; color:#555; border-radius:6px; cursor:pointer; font-weight:500;">Cancel</button>
                <button type="button" onclick="submitDelete()" style="padding:10px 24px; border:none; background:#e53935; color:white; border-radius:6px; cursor:pointer; font-weight:600;">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
            </div>
        </div>
    </div>

    <!-- Single Delete Form -->
    <form id="singleDeleteForm" method="post" style="display:none;">
        <input type="hidden" name="delete_allocation" value="1">
        <input type="hidden" name="allocation_id" id="deleteAllocationId" value="">
    </form>

    <!-- ═══════════════════════════════════════════════════════════
         ALLOCATION DASHBOARD MODAL
    ════════════════════════════════════════════════════════════ -->
    <div id="allocDashModal">
        <div class="adm-box">
            <div class="adm-header">
                <div>
                    <div class="adm-title" id="admTitle">Allocation Dashboard</div>
                    <div class="adm-subtitle" id="admSubtitle"></div>
                </div>
                <button class="adm-close" onclick="closeAllocDashboard()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="adm-body" id="admBody">
                <div class="adm-loading">
                    <i class="fas fa-circle-notch"></i>
                    Loading dashboard…
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // ── Flatpickr ─────────────────────────────────────────────────
        flatpickr('.datepicker', { dateFormat: 'Y-m-d', allowInput: true });

        // ── Allocation table helpers ──────────────────────────────────
        function viewAllocationDetails(id) {
            window.open('view_allocation_clients.php?id=' + id, '_blank');
        }
        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.delete-checkbox').forEach(cb => cb.checked = checkbox.checked);
            updateSelectedCount();
        }
        function updateSelectedCount() {
            const cbs = document.querySelectorAll('.delete-checkbox');
            const n = Array.from(cbs).filter(c => c.checked).length;
            const el = document.getElementById('selectedCount');
            if (el) el.textContent = n + ' item' + (n !== 1 ? 's' : '') + ' selected';
            const sa = document.getElementById('selectAllCheckbox');
            if (!sa) return;
            sa.checked = n > 0 && Array.from(cbs).every(c => c.checked);
            sa.indeterminate = Array.from(cbs).some(c => c.checked) && !sa.checked;
        }
        function confirmDelete() {
            const n = Array.from(document.querySelectorAll('.delete-checkbox')).filter(c => c.checked).length;
            if (!n) { alert('Please select at least one allocation to delete.'); return; }
            document.getElementById('deleteCount').textContent = n;
            document.getElementById('deleteConfirmModal').style.display = 'flex';
        }
        function confirmSingleDelete(id, name) {
            if (confirm('Are you sure you want to delete allocation: ' + name + '?\n\nThis action cannot be undone.')) {
                document.getElementById('deleteAllocationId').value = id;
                document.getElementById('singleDeleteForm').submit();
            }
        }
        function closeDeleteModal() { document.getElementById('deleteConfirmModal').style.display = 'none'; }
        function submitDelete()     { document.getElementById('bulkDeleteForm').submit(); }

        document.addEventListener('DOMContentLoaded', function () {
            if (document.querySelector('.delete-checkbox')) updateSelectedCount();
            document.querySelectorAll('.delete-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
            const sm = document.getElementById('successMessage');
            if (sm) {
                setTimeout(() => {
                    sm.style.transition = 'opacity .5s ease';
                    sm.style.opacity = '0';
                    setTimeout(() => sm.style.display = 'none', 500);
                }, 3000);
            }
        });

        // ══════════════════════════════════════════════════════════════
        //  DASHBOARD MODAL
        // ══════════════════════════════════════════════════════════════
        function openAllocDashboard(logId, label) {
            const modal = document.getElementById('allocDashModal');
            document.getElementById('admTitle').textContent    = 'Allocation Dashboard';
            document.getElementById('admSubtitle').textContent = label;
            document.getElementById('admBody').innerHTML = `
                <div class="adm-loading">
                    <i class="fas fa-circle-notch"></i>
                    Loading dashboard…
                </div>`;
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            fetch('allocation_log.php?ajax_alloc_dashboard=1&allocation_log_id=' + logId)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Unknown error');
                    renderAllocDashboard(data);
                })
                .catch(err => {
                    document.getElementById('admBody').innerHTML =
                        `<div class="adm-loading" style="color:#ef4444;">
                            <i class="fas fa-exclamation-circle" style="color:#ef4444;animation:none;"></i>
                            Failed to load: ${escHtml(err.message)}
                        </div>`;
                });
        }

        function closeAllocDashboard() {
            document.getElementById('allocDashModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        // Close on backdrop or Escape
        document.getElementById('allocDashModal').addEventListener('click', function(e) {
            if (e.target === this) closeAllocDashboard();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeAllocDashboard();
        });

        function renderAllocDashboard(data) {
            const log = data.log;
            const kpi = data.kpi;
            const rms = data.rm_breakdown;

            const total    = parseInt(kpi.total          ?? 0);
            const pending  = parseInt(kpi.count_pending  ?? 0);
            const draft    = parseInt(kpi.count_draft    ?? 0);
            const ready    = parseInt(kpi.count_ready    ?? 0);
            const reviewed = parseInt(kpi.count_reviewed ?? 0);
            const sent     = parseInt(kpi.count_sent     ?? 0);
            const aum      = parseFloat(kpi.total_aum    ?? 0);
            const meetings = parseInt(kpi.meetings_fixed ?? 0);
            const sentPct  = total > 0 ? Math.round((sent / total) * 100) : 0;

            const createdDate = new Date(log.created_at).toLocaleString('en-IN', {
                day:'2-digit', month:'short', year:'numeric',
                hour:'2-digit', minute:'2-digit'
            });

            document.getElementById('admTitle').textContent =
                log.month_year + ' — ' + log.target_tag + ' Allocation';
            document.getElementById('admSubtitle').textContent =
                'Uploaded by ' + escHtml(log.uploader_name || 'Unknown') + ' on ' + createdDate;

            let html = '';

            // ── Meta strip ──────────────────────────────────────────────
            html += `<div class="adm-meta-strip">
                <span class="adm-meta-pill"><i class="fas fa-calendar-alt"></i>${escHtml(log.month_year)}</span>
                <span class="adm-meta-pill"><i class="fas fa-tag"></i>${escHtml(log.target_tag)}</span>
                <span class="adm-meta-pill"><i class="fas fa-users"></i>${log.clients_count} clients in import</span>
                <span class="adm-meta-pill"><i class="fas fa-file-excel"></i>${escHtml(log.file_name || 'N/A')}</span>
                <span class="adm-meta-pill"><i class="fas fa-indian-rupee-sign"></i>₹${aum.toFixed(2)} Cr AUM</span>
            </div>`;

            // ── KPI section label ───────────────────────────────────────
            html += `<div class="adm-section-label">Review Progress</div>`;

            // Row 1 — 4 cards
            html += `<div class="adm-kpi-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:10px;">
                ${admCard('fa-layer-group',      'Total Reviews',   total,    'adm-c-blue',   log.id, '')}
                ${admCard('fa-hourglass-half',   'Not Started',     pending,  'adm-c-red',    log.id, 'pending')}
                ${admCard('fa-pen-to-square',    'Draft',           draft,    'adm-c-grey',   log.id, 'draft')}
                ${admCard('fa-clipboard-check',  'Review Prepared', ready,    'adm-c-yellow', log.id, 'ready')}
            </div>`;

            // Row 2 — 3 cards (natural width)
            html += `<div class="adm-kpi-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:20px;">
                ${admCard('fa-circle-check',     'Consent Given',   reviewed, 'adm-c-teal',   log.id, 'reviewed')}
                ${admCard('fa-paper-plane',      'Sent',            sent,     'adm-c-green',  log.id, 'sent')}
                ${admCard('fa-handshake',        'Meetings Fixed',  meetings, 'adm-c-indigo', log.id, '')}
                <div></div>
            </div>`;

            // ── Progress bar ────────────────────────────────────────────
            const progressColor = sentPct >= 75 ? '#2e7d32' : sentPct >= 40 ? '#0277bd' : '#b45309';
            html += `<div class="adm-progress-wrap">
                <div class="adm-progress-header">
                    <span class="adm-progress-title">📤 Sent Progress</span>
                    <span class="adm-progress-pct" style="color:${progressColor};">${sentPct}%</span>
                </div>
                <div class="adm-progress-bar">
                    <div class="adm-progress-fill" style="width:${sentPct}%; background:linear-gradient(90deg,${progressColor},${progressColor}dd);"></div>
                </div>
            </div>`;

            // ── RM Breakdown ────────────────────────────────────────────
            if (rms && rms.length > 0) {
                html += `<div class="adm-section-label" style="margin-top:0;">Breakdown by Relationship Manager</div>
                <div class="adm-rm-wrap">
                <table class="adm-rm-table">
                    <thead><tr>
                        <th>RM</th>
                        <th>Total</th>
                        <th>Pending</th>
                        <th>Draft</th>
                        <th>Ready</th>
                        <th>Reviewed</th>
                        <th>Sent</th>
                        <th>AUM (Cr)</th>
                    </tr></thead>
                    <tbody>`;
                rms.forEach(rm => {
                    const spCls = (n, cls) => n > 0
                        ? `<span class="sp ${cls}">${n}</span>`
                        : `<span class="sp sp-0">${n}</span>`;
                    html += `<tr>
                        <td><span class="adm-rm-name">${escHtml(rm.rm_name || 'Unassigned')}</span></td>
                        <td><strong>${rm.total}</strong></td>
                        <td>${spCls(rm.count_pending,  'sp-pending')}</td>
                        <td>${spCls(rm.count_draft,    'sp-draft')}</td>
                        <td>${spCls(rm.count_ready,    'sp-ready')}</td>
                        <td>${spCls(rm.count_reviewed, 'sp-reviewed')}</td>
                        <td>${spCls(rm.count_sent,     'sp-sent')}</td>
                        <td style="font-weight:600;">₹${parseFloat(rm.total_aum).toFixed(2)}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            }

            // ── Footer ──────────────────────────────────────────────────
            html += `<div class="adm-footer">
                <a class="adm-view-all"
                   href="view_saved_reports.php?allocation_id=${log.id}"
                   target="_blank">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    View all clients in this allocation
                </a>
            </div>`;

            document.getElementById('admBody').innerHTML = html;
        }

        function admCard(icon, label, value, cls, logId, state) {
            const href = state
                ? `view_saved_reports.php?allocation_id=${logId}&filter=${state}`
                : `view_saved_reports.php?allocation_id=${logId}`;
            return `<a href="${href}" target="_blank" class="adm-kpi-card ${cls}">
                <span class="adm-kpi-icon"><i class="fas ${icon}"></i></span>
                <div class="adm-kpi-label">${label}</div>
                <div class="adm-kpi-val">${value}</div>
            </a>`;
        }

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }
    </script>
</body>
</html>
<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();

$pdo = getPdo();
$currentUser = getCurrentUser();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$isAdmin = strtolower($currentUser['username'] ?? '') === 'admin';

$cycleFilter = $_GET['cycle_filter'] ?? '';
$monthFilter = $_GET['month_filter'] ?? '';
$yearFilter  = $_GET['year_filter'] ?? '';
$viewContext = $_GET['view_context'] ?? 'mine';

if (!$isAdmin) {
    $viewContext = 'mine';
}

/* ---------------- WHERE BUILDER ---------------- */

$where = "is_latest = TRUE";
$params = [];

/* Context */
/* Context */
if ($viewContext === 'mine') {
    $where .= " AND assigned_to = ?";
    $params[] = $currentUserId;
}
elseif ($viewContext === 'all') {
    // no filter for global
}
elseif (ctype_digit($viewContext)) {
    $where .= " AND assigned_to = ?";
    $params[] = (int)$viewContext;
}

/* Cycle */
/* Cycle */
if ($cycleFilter === 'RJ') {
    $where .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Jan','Apr','Jul','Oct')";
}
elseif ($cycleFilter === 'RF') {
    $where .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Feb','May','Aug','Nov')";
}
elseif ($cycleFilter === 'RM') {
    $where .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Mar','Jun','Sep','Dec')";
}


/* Month */
if ($monthFilter !== '') {
    $where .= " AND SUBSTRING_INDEX(month_year, ' ', 1) = ?";
    $params[] = $monthFilter;
}

/* Year */
if ($yearFilter !== '') {
    $where .= " AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";
    $params[] = $yearFilter;
}

/* ---------------- KPI STATS ---------------- */

$sql = "SELECT
            COUNT(DISTINCT name) AS total,
            SUM(CASE WHEN report_state = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN report_state = 'draft' THEN 1 ELSE 0 END) AS draft,
            SUM(CASE WHEN report_state = 'ready' THEN 1 ELSE 0 END) AS ready,
            SUM(CASE WHEN report_state = 'reviewed' THEN 1 ELSE 0 END) AS reviewed,
            SUM(CASE WHEN report_state = 'sent' THEN 1 ELSE 0 END) AS sent,
            SUM(aum) AS total_aum
        FROM clients
        WHERE $where";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'total'    => (int)($data['total'] ?? 0),
    'pending'  => (int)($data['pending'] ?? 0),
    'draft'    => (int)($data['draft'] ?? 0),
    'ready'    => (int)($data['ready'] ?? 0),
    'reviewed' => (int)($data['reviewed'] ?? 0),
    'sent'     => (int)($data['sent'] ?? 0),
    'aum'      => (float)($data['total_aum'] ?? 0)
]);

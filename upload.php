<?php
// upload.php
// Clean rebuild: dashboard + upload/parse/save pipeline
// UPDATED: AUM carry-forward logic - clients keep same AUM across reviews

require_once 'auth.php';
require_once 'db_config.php';
// require_once 'parsers.php';
// require_once 'renderers.php';
// require_once 'env_loader.php';

// use PhpOffice\PhpSpreadsheet\IOFactory;


requireAuth();
$currentReviewPeriod = date('F Y');
$pdo           = getPdo();
$currentUser   = getCurrentUser();
// ---------------- ADMIN CHECK (USERNAME BASED) ----------------
$isAdmin = (
    isset($currentUser['username']) &&
    strtolower($currentUser['username']) === 'admin'
);

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

/**
 * Get the latest AUM for a client from previous reviews
 */
$pdoSlides = getSlidesPdo();
function getLatestAumForClient(PDO $pdo, string $clientName): float
{
    $stmt = $pdo->prepare("
        SELECT aum 
        FROM clients 
        WHERE name = :name 
        AND aum > 0 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':name' => $clientName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($result['aum'] ?? 0);
}



function fetchDashboardStats(PDO $pdo, string $context, int $userId, string $cycleFilter = ''): array
{
    // Build the base WHERE clause for filtering
    $baseWhere = "1=1";
    $params = [];

    // Apply context filters
    if ($context === 'mine') {
        $baseWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
        $params[] = $userId;
        $params[] = $userId;
    } elseif (ctype_digit($context)) {
        $baseWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
        $params[] = (int)$context;
        $params[] = (int)$context;
    }

    // Add cycle filter if set
    if ($cycleFilter !== '') {
        $baseWhere .= " AND review_cycle = ?";
        $params[] = $cycleFilter;
    }

    // Get the latest record for each client and count by status
    // Using is_latest column for efficiency
    $sql = "SELECT
                COUNT(DISTINCT name) AS total,
                SUM(CASE WHEN report_state = 'pending' THEN 1 ELSE 0 END) AS count_pending,
                SUM(CASE WHEN report_state = 'draft' THEN 1 ELSE 0 END) AS count_draft,
                SUM(CASE WHEN report_state = 'ready' THEN 1 ELSE 0 END) AS count_ready,
                SUM(CASE WHEN report_state = 'reviewed' THEN 1 ELSE 0 END) AS count_reviewed,
                SUM(CASE WHEN report_state = 'sent' THEN 1 ELSE 0 END) AS count_sent
            FROM clients
            WHERE is_latest = TRUE AND {$baseWhere}";

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
                COUNT(DISTINCT c.name) AS total_assigned,
                SUM(CASE WHEN c.report_state = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN c.report_state = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN c.priority = 'high' THEN 1 ELSE 0 END) AS high_pri
            FROM users u
            LEFT JOIN clients c ON c.assigned_to = u.id AND c.is_latest = TRUE
            GROUP BY u.id
            ORDER BY u.username";

    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as &$row) {
        $row['high_priority'] = $row['high_pri'];
        unset($row['high_pri']);
    }

    return $result;
}


$cycleFilter = isset($_GET['cycle_filter']) ? $_GET['cycle_filter'] : '';

$requestedContext = $_GET['view_context'] ?? 'mine';

// 🔐 HARD RULE
if ($isAdmin) {
    // Admin can see everything
    $viewContext = $requestedContext;
} else {
    // Everyone else → ONLY their own data
    $viewContext = 'mine';
}

$targetName  = 'My';
if ($viewContext === 'all') {
    $targetName = 'Global';
} elseif (ctype_digit($viewContext)) {
    $targetName = 'User';
}

// --- AUM CALCULATION LOGIC ---
$aumWhere = "is_latest = TRUE";
$aumParams = [];

if ($viewContext === 'mine') {
    $aumWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
    $aumParams = [$currentUserId, $currentUserId];
} elseif (ctype_digit($viewContext)) {
    $aumWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
    $aumParams = [(int)$viewContext, (int)$viewContext];
}

if ($cycleFilter !== '') {
    $aumWhere .= " AND review_cycle = ?";
    $aumParams[] = $cycleFilter;
}

// Get AUM from the latest records only
$stmtAum = $pdo->prepare("
    SELECT SUM(aum) 
    FROM clients
    WHERE {$aumWhere}
");
$stmtAum->execute($aumParams);
$totalAum = $stmtAum->fetchColumn() ?: 0;

$usersStmt = $pdo->query('SELECT id, username FROM users ORDER BY username ASC');
$allUsers  = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$viewStats       = fetchDashboardStats($pdo, $viewContext, $currentUserId, $cycleFilter);
$completionRate  = safePercent($viewStats['count_sent'], max(1, $viewStats['total']));
$uploadError     = '';

$navUser     = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);
$filterParam = ($viewContext === 'all') ? 'all' : (($viewContext === 'mine') ? 'mine' : $viewContext);
// 🔐 Force KPI links for non-admin
if (!$isAdmin) {
    $filterParam = 'mine';
}

$userDesignation = $currentUser['designation'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="public/css/upload.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-3hT1sJQdT9v+0kz+1vZ1tcHTul3e8DqRL3OjaxAg/P6MqxsVXni4eWh05rq6ArtyTcwxH8333Adxpv8vS1TukA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="main-scroll-container" style="height: calc(100vh - 72px); overflow-y: auto;">
        <div class="wrap">
            <div class="page-header">
                <div style="display: flex; justify-content: space-between; align-items: center; width:100%; margin-bottom:20px;">
                    <h1 style="margin:0;">Quarterly Review of <?php echo date('F Y'); ?></h1>
                    <div style="display: flex; align-items: center; gap: 0;">
                        <form method="get" id="cycleForm" style="margin:15px;">
                            <select name="cycle_filter" onchange="this.form.submit()" style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="" <?php if ($cycleFilter === '') echo 'selected'; ?>>All Cycles</option>
                                <option value="RJ" <?php if ($cycleFilter === 'RJ') echo 'selected'; ?>>RJ</option>
                                <option value="RM" <?php if ($cycleFilter === 'RM') echo 'selected'; ?>>RM</option>
                                <option value="RF" <?php if ($cycleFilter === 'RF') echo 'selected'; ?>>RF</option>
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
                                ₹<?= number_format($totalAum / 10000000, 2); ?>
                                <span style="font-size: 13px;">Cr</span>
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="context-navbar">
                    <a href="?view_context=all<?php echo $cycleParam; ?>" class="context-link <?= ($viewContext === 'all') ? 'active' : '' ?>">All Reviews</a>
                    <a href="?view_context=mine<?php echo $cycleParam; ?>" class="context-link <?= ($viewContext === 'mine') ? 'active' : '' ?>">My Reviews</a>
                    <?php foreach ($allUsers as $user): ?>
                        <?php if ((int)$user['id'] === $currentUserId) continue; // Skip logged-in user 
                        ?>
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

        </div> <!-- end .wrap -->
    </div> <!-- end .main-scroll-container -->
</body>

</html>
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

// Ensure correct timezone
date_default_timezone_set('Asia/Kolkata');

$currentMonthShort = date('M');   // Jan, Feb, Mar...
$currentYear       = date('Y');   // 2026

// Detect current cycle based on month
if (in_array($currentMonthShort, ['Jan', 'Apr', 'Jul', 'Oct'])) {
    $currentCycle = 'RJ';
} elseif (in_array($currentMonthShort, ['Feb', 'May', 'Aug', 'Nov'])) {
    $currentCycle = 'RF';
} else {
    $currentCycle = 'RM';
}

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

$cycleFilter = $_GET['cycle_filter'] ?? $currentCycle;
$monthFilter = $_GET['month_filter'] ?? $currentMonthShort;
$yearFilter  = $_GET['year_filter']  ?? $currentYear;

$isFilterApplied = !empty($_GET['cycle_filter'])
    || !empty($_GET['month_filter'])
    || !empty($_GET['year_filter']);


$requestedContext = $_GET['view_context'] ?? 'mine';

if ($isAdmin) {
    $viewContext = $requestedContext;
} else {
    $viewContext = 'mine';
}


// Get previous month from DB based on current month
if (!$isFilterApplied) {

    // Get actual previous calendar month
    $previousDate = date('M Y', strtotime('-1 month'));

    $availableMonths = [$previousDate];

    
} else {

    // Only get immediate previous calendar month
    if ($monthFilter !== '' && $yearFilter !== '') {

        $selectedDate = DateTime::createFromFormat('M Y', $monthFilter . ' ' . $yearFilter);
        $selectedDate->modify('-1 month');

        $previousMonth = $selectedDate->format('M Y');

        $availableMonths = [$previousMonth];
    } else {
        $availableMonths = [];
    }
}




function fetchDashboardStats(PDO $pdo, string $context, int $userId, string $cycleFilter = '', string $monthFilter = '', string $yearFilter = ''): array
{
    // Build the base WHERE clause for filtering
    $baseWhere = "1=1";
    $params = [];

    // Apply context filters (RM ownership only)
    if ($context === 'mine') {
        $baseWhere .= " AND assigned_to = ?";
        $params[] = $userId;
    } elseif (ctype_digit($context)) {
        $baseWhere .= " AND assigned_to = ?";
        $params[] = (int)$context;
    }

    // Add cycle filter if set
    if ($cycleFilter === 'RJ') {
        $baseWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Jan','Apr','Jul','Oct')";
    } elseif ($cycleFilter === 'RF') {
        $baseWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Feb','May','Aug','Nov')";
    } elseif ($cycleFilter === 'RM') {
        $baseWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Mar','Jun','Sep','Dec')";
    }

    if ($monthFilter !== '') {
        $baseWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) = ?";
        $params[] = $monthFilter;
    }

    if ($yearFilter !== '') {
        $baseWhere .= " AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";
        $params[] = $yearFilter;
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
    $aumWhere .= " AND assigned_to = ?";
    $aumParams = [$currentUserId];
} elseif (ctype_digit($viewContext)) {
    $aumWhere .= " AND assigned_to = ?";
    $aumParams = [$currentUserId];
}

if ($cycleFilter === 'RJ') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Jan','Apr','Jul','Oct')";
} elseif ($cycleFilter === 'RF') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Feb','May','Aug','Nov')";
} elseif ($cycleFilter === 'RM') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) IN ('Mar','Jun','Sep','Dec')";
}

if ($monthFilter !== '') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', 1) = ?";
    $aumParams[] = $monthFilter;
}


if ($yearFilter !== '') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";
    $aumParams[] = $yearFilter;
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

$viewStats       = fetchDashboardStats($pdo, $viewContext, $currentUserId, $cycleFilter, $monthFilter, $yearFilter);
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

$yearsStmt = $pdo->query("
    SELECT DISTINCT SUBSTRING_INDEX(month_year, ' ', -1) AS year
    FROM clients
    WHERE month_year IS NOT NULL
    ORDER BY year DESC
");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

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
                <div>
                    <h1>
                        Quarterly Review of
                        <span id="reviewHeading">
                            <?php
                            if (!$isFilterApplied) {
                                echo date('F Y');
                            } else {
                                echo date('F', strtotime($monthFilter . " 1")) . " " . $yearFilter;
                            }
                            ?>
                        </span>

                    </h1>
                    <div style="display: flex; align-items: center; gap: 0;">
                        <form method="get" id="filterForm" style="margin:15px; display:flex; gap:10px; align-items:center;">

                            <!-- Cycle Dropdown -->
                            <select name="cycle_filter" id="cycleFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="" <?= ($cycleFilter === '') ? 'selected' : '' ?>>All Cycles</option>
                                <option value="RJ" <?= ($cycleFilter === 'RJ') ? 'selected' : '' ?>>RJ</option>
                                <option value="RM" <?= ($cycleFilter === 'RM') ? 'selected' : '' ?>>RM</option>
                                <option value="RF" <?= ($cycleFilter === 'RF') ? 'selected' : '' ?>>RF</option>
                            </select>

                            <!-- Month Dropdown -->
                            <select name="month_filter" id="monthFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="">All Months</option>

                                <option value="Jan" <?= ($monthFilter === 'Jan') ? 'selected' : '' ?>>January</option>
                                <option value="Feb" <?= ($monthFilter === 'Feb') ? 'selected' : '' ?>>February</option>
                                <option value="Mar" <?= ($monthFilter === 'Mar') ? 'selected' : '' ?>>March</option>
                                <option value="Apr" <?= ($monthFilter === 'Apr') ? 'selected' : '' ?>>April</option>
                                <option value="May" <?= ($monthFilter === 'May') ? 'selected' : '' ?>>May</option>
                                <option value="Jun" <?= ($monthFilter === 'Jun') ? 'selected' : '' ?>>June</option>
                                <option value="Jul" <?= ($monthFilter === 'Jul') ? 'selected' : '' ?>>July</option>
                                <option value="Aug" <?= ($monthFilter === 'Aug') ? 'selected' : '' ?>>August</option>
                                <option value="Sep" <?= ($monthFilter === 'Sep') ? 'selected' : '' ?>>September</option>
                                <option value="Oct" <?= ($monthFilter === 'Oct') ? 'selected' : '' ?>>October</option>
                                <option value="Nov" <?= ($monthFilter === 'Nov') ? 'selected' : '' ?>>November</option>
                                <option value="Dec" <?= ($monthFilter === 'Dec') ? 'selected' : '' ?>>December</option>

                            </select>

                            <select name="year_filter" id="yearFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="">All Years</option>

                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?= $year ?>" <?= ($yearFilter == $year) ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>


                            <button type="button" id="resetFilters"
                                style="padding:8px 14px; font-weight:600; border-radius:8px; 
                                border:1px solid #e2e8f0; background:#f1f5f9; cursor:pointer;" onmouseover="this.style.background='#c5eaf5'" onmouseout="this.style.background='#f8fafc'">

                                Reset
                            </button>




                            <!-- Preserve view_context -->
                            <?php if (isset($_GET['view_context'])): ?>
                                <input type="hidden" name="view_context" value="<?= htmlspecialchars($_GET['view_context']); ?>">
                            <?php endif; ?>

                        </form>
                        <?php $cycleParam = '';
                        if ($cycleFilter !== '') {
                            $cycleParam .= '&cycle_filter=' . urlencode($cycleFilter);
                        }
                        if ($monthFilter !== '') {
                            $cycleParam .= '&month_filter=' . urlencode($monthFilter);
                        }
                        if ($yearFilter !== '') {
                            $cycleParam .= '&year_filter=' . urlencode($yearFilter);
                        } ?>
                        <div class="aum-box">
                            <div>AUM Handled</div>
                            <div id="aumValue">
                                ₹<?= number_format($totalAum / 10000000, 2); ?> <span style="font-size: 13px;">Cr</span>
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
                    <div class="label">Total Reviews</div>
                    <div class="number" id="totalCount"><?= (int)$viewStats['total']; ?></div>
                </a>


                <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=pending" class="stats-card card-red-outline">
                    <span class="card-icon"><i class="fa-solid fa-hourglass-half"></i></span>
                    <div class="label">Review Not Started</div>
                    <div class="number" id="pendingCount"><?= (int)$viewStats['count_pending']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=draft" class="stats-card card-grey">
                    <span class="card-icon"><i class="fa-regular fa-pen-to-square"></i></span>
                    <div class="label">Draft</div>
                    <div class="number" id="draftCount"><?= (int)$viewStats['count_draft']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=ready" class="stats-card card-yellow">
                    <span class="card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                    <div class="label">Review Prepared</div>
                    <div class="number" id="readyCount"><?= (int)$viewStats['count_ready']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=reviewed" class="stats-card card-teal">
                    <span class="card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
                    <div class="label">Concerned Given</div>
                    <div class="number" id="reviewedCount"><?= (int)$viewStats['count_reviewed']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?php echo $filterParam; ?>&filter=sent" class="stats-card card-green">
                    <span class="card-icon"><i class="fa-solid fa-paper-plane"></i></span>
                    <div class="label">Sent</div>
                    <div class="number" id="sentCount"><?= (int)$viewStats['count_sent']; ?></div>
                </a>
            </div>
            <?php if (!empty($availableMonths)):
                $monthYear = $availableMonths[0];
                list($mShort, $y) = explode(' ', $monthYear);

                $prevMonthShort = $mShort;

                if (in_array($prevMonthShort, ['Jan', 'Apr', 'Jul', 'Oct'])) {
                    $prevCycle = 'RJ';
                } elseif (in_array($prevMonthShort, ['Feb', 'May', 'Aug', 'Nov'])) {
                    $prevCycle = 'RF';
                } else {
                    $prevCycle = 'RM';
                }

                $stats = fetchDashboardStats(
                    $pdo,
                    $viewContext,
                    $currentUserId,
                    '',
                    $mShort,
                    $y
                );

                // ✅ NOW add Previous AUM calculation BELOW this
                $prevAumWhere = "is_latest = TRUE 
    AND SUBSTRING_INDEX(month_year, ' ', 1) = ?
    AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";

                $prevAumParams = [$mShort, $y];

                if ($viewContext === 'mine') {
                    $prevAumWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
                    $prevAumParams[] = $currentUserId;
                    $prevAumParams[] = $currentUserId;
                } elseif (ctype_digit($viewContext)) {
                    $prevAumWhere .= " AND (assigned_to = ? OR review_assigned_to = ?)";
                    $prevAumParams[] = (int)$viewContext;
                    $prevAumParams[] = (int)$viewContext;
                }

                $stmtPrevAum = $pdo->prepare("SELECT SUM(aum) FROM clients WHERE {$prevAumWhere}");
                $stmtPrevAum->execute($prevAumParams);
                $prevTotalAum = $stmtPrevAum->fetchColumn() ?: 0;

            ?>

                <div class="dashboard-section" style="margin-top:60px;">

                    <div>

                        <h1>
                            Quarterly Review of
                            <span id="prevReviewHeading">
                                <?= date('F', strtotime($mShort . " 1")) . " " . $y ?>
                            </span>
                        </h1>

                        <div style="display:flex; align-items:center; gap:10px;">

                            <!-- Cycle Dropdown -->
                            <select id="prevCycleFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="">All Cycles</option>
                                <option value="RJ">RJ</option>
                                <option value="RM">RM</option>
                                <option value="RF">RF</option>
                            </select>

                            <!-- Month Dropdown -->
                            <select id="prevMonthFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="">All Months</option>
                                <option value="Jan">January</option>
                                <option value="Feb">February</option>
                                <option value="Mar">March</option>
                                <option value="Apr">April</option>
                                <option value="May">May</option>
                                <option value="Jun">June</option>
                                <option value="Jul">July</option>
                                <option value="Aug">August</option>
                                <option value="Sep">September</option>
                                <option value="Oct">October</option>
                                <option value="Nov">November</option>
                                <option value="Dec">December</option>
                            </select>

                            <!-- Year Dropdown -->
                            <select id="prevYearFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">

                                <option value="">All Years</option>

                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?= $year ?>" <?= ($year == $y) ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <button type="button" id="prevResetFilters" style="padding:8px 14px; font-weight:600; border-radius:8px; 
                                border:1px solid #e2e8f0; background:#f1f5f9; cursor:pointer;" onmouseover="this.style.background='#c5eaf5'" onmouseout="this.style.background='#f8fafc'">

                                Reset
                            </button>
                            <div class="aum-box">
                                <div>
                                    AUM Handled
                                </div>


                                <div id="prevAumValue">
                                    ₹<?= number_format($prevTotalAum / 10000000, 2); ?>
                                    <span style="font-size: 13px;">Cr</span>
                                </div>
                            </div>

                        </div>
                    </div>

                    <?php
                    $prevParams = [
                        'owner_filter' => $filterParam,
                        'cycle_filter' => $prevCycle,
                        'month_filter' => $mShort,
                        'year_filter'  => $y
                    ];

                    $prevQuery = http_build_query($prevParams);
                    ?>


                    <div class="kpi-grid">

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>"
                            class="stats-card card-blue">
                            <span class="card-icon"><i class="fa-solid fa-layer-group"></i></span>
                            <div class="label">Total Reviewa</div>
                            <div class="number" id="prevTotalCount"><?= $stats['total']; ?></div>
                        </a>

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>&filter=pending"
                            class="stats-card card-red-outline">
                            <span class="card-icon"><i class="fa-solid fa-hourglass-half"></i></span>
                            <div class="label">Review Not Started</div>
                            <div class="number" id="prevPendingCount"><?= $stats['count_pending']; ?></div>
                        </a>

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>&filter=draft"
                            class="stats-card card-grey">
                            <span class="card-icon"><i class="fa-regular fa-pen-to-square"></i></span>
                            <div class="label">Draft</div>
                            <div class="number" id="prevDraftCount"><?= $stats['count_draft']; ?></div>
                        </a>

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>&filter=ready"
                            class="stats-card card-yellow">
                            <span class="card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                            <div class="label">Review Prepared</div>
                            <div class="number" id="prevReadyCount"><?= $stats['count_ready']; ?></div>
                        </a>

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>&filter=reviewed"
                            class="stats-card card-teal">
                            <span class="card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
                            <div class="label">Concerned Given</div>
                            <div class="number" id="prevReviewedCount"><?= $stats['count_reviewed']; ?></div>
                        </a>

                        <a href="view_saved_reports.php?<?= $prevQuery; ?>&filter=sent"
                            class="stats-card card-green">
                            <span class="card-icon"><i class="fa-solid fa-paper-plane"></i></span>
                            <div class="label">Sent</div>
                            <div class="number" id="prevSentCount"><?= $stats['count_sent']; ?></div>
                        </a>

                    </div>

                </div> <!-- end .wrap -->
        </div>


    <?php endif; ?>

    </div>

</body>
<script>
    const defaultPrevCycle = "<?= $prevCycle ?? '' ?>";
    const defaultPrevMonth = "<?= $mShort ?? '' ?>";
    const defaultPrevYear = "<?= $y ?? '' ?>";

    document.addEventListener("DOMContentLoaded", function() {

        const cycle = document.getElementById("cycleFilter");
        const month = document.getElementById("monthFilter");
        const year = document.getElementById("yearFilter");
        const resetBtn = document.getElementById("resetFilters");

        const prevCycle = document.getElementById("prevCycleFilter");
        const prevMonth = document.getElementById("prevMonthFilter");
        const prevYear = document.getElementById("prevYearFilter");

        const currentCycle = "<?= $currentCycle ?>";
        const currentMonth = "<?= $currentMonthShort ?>";
        const currentYear = "<?= $currentYear ?>";

        const cycleMap = {
            "RJ": ["Jan", "Apr", "Jul", "Oct"],
            "RF": ["Feb", "May", "Aug", "Nov"],
            "RM": ["Mar", "Jun", "Sep", "Dec"]
        };
        const prevResetBtn = document.getElementById("prevResetFilters");
        // 🔥 Auto select previous dropdown defaults on load
        if (prevCycle && defaultPrevCycle) {
            prevCycle.value = defaultPrevCycle;
        }

        if (prevMonth && defaultPrevMonth) {
            prevMonth.value = defaultPrevMonth;
        }

        if (prevYear && defaultPrevYear) {
            prevYear.value = defaultPrevYear;
        }

        filterPrevMonthDropdown();


        if (prevResetBtn) {
            prevResetBtn.addEventListener("click", function() {

                prevCycle.value = defaultPrevCycle;
                prevMonth.value = defaultPrevMonth;
                prevYear.value = defaultPrevYear;

                filterPrevMonthDropdown();
                loadPreviousDashboard();
            });
        }

        function filterMonthDropdown() {
            const cycleValue = cycle.value;

            for (let option of month.options) {
                if (!cycleValue || option.value === "" || cycleMap[cycleValue]?.includes(option.value)) {
                    option.style.display = "block";
                } else {
                    option.style.display = "none";
                }
            }

            if (cycleValue && !cycleMap[cycleValue]?.includes(month.value)) {
                month.value = "";
            }
        }

        function filterPrevMonthDropdown() {
            if (!prevCycle || !prevMonth) return;

            const cycleValue = prevCycle.value;

            for (let option of prevMonth.options) {
                if (!cycleValue || option.value === "" || cycleMap[cycleValue]?.includes(option.value)) {
                    option.style.display = "block";
                } else {
                    option.style.display = "none";
                }
            }
        }

        function loadDashboard() {
            const params = new URLSearchParams({
                cycle_filter: cycle.value,
                month_filter: month.value,
                year_filter: year.value,
                view_context: "<?= $viewContext ?>"
            });

            fetch("ajax_dashboard_stats.php?" + params.toString())
                .then(res => res.json())
                .then(data => {

                    document.getElementById("totalCount").innerText = data.total;
                    document.getElementById("pendingCount").innerText = data.pending;
                    document.getElementById("draftCount").innerText = data.draft;
                    document.getElementById("readyCount").innerText = data.ready;
                    document.getElementById("reviewedCount").innerText = data.reviewed;
                    document.getElementById("sentCount").innerText = data.sent;

                    let crore = (data.aum / 10000000).toFixed(2);
                    document.getElementById("aumValue").innerHTML =
                        "₹" + crore + " <span style='font-size:13px;'>Cr</span>";

                    if (month.value) {
                        document.getElementById("reviewHeading").innerText =
                            month.options[month.selectedIndex].text + " " + year.value;
                    }
                });
        }

        function loadPreviousDashboard() {
            if (!prevCycle || !prevMonth || !prevYear) return;

            const params = new URLSearchParams({
                cycle_filter: prevCycle.value,
                month_filter: prevMonth.value,
                year_filter: prevYear.value,
                view_context: "<?= $viewContext ?>"
            });


            fetch("ajax_dashboard_stats.php?" + params.toString())
                .then(res => res.json())
                .then(data => {

                    document.getElementById("prevTotalCount").innerText = data.total;
                    document.getElementById("prevPendingCount").innerText = data.pending;
                    document.getElementById("prevDraftCount").innerText = data.draft;
                    document.getElementById("prevReadyCount").innerText = data.ready;
                    document.getElementById("prevReviewedCount").innerText = data.reviewed;
                    document.getElementById("prevSentCount").innerText = data.sent;

                    let crore = (data.aum / 10000000).toFixed(2);
                    document.getElementById("prevAumValue").innerHTML =
                        "₹" + crore + " <span style='font-size:13px;'>Cr</span>";

                    if (prevMonth.value) {
                        document.getElementById("prevReviewHeading").innerText =
                            prevMonth.options[prevMonth.selectedIndex].text + " " + prevYear.value;
                    }
                });
        }

        /* Event Listeners */

        if (cycle) {
            cycle.addEventListener("change", function() {
                filterMonthDropdown();
                loadDashboard();
            });
        }

        if (month) month.addEventListener("change", loadDashboard);
        if (year) year.addEventListener("change", loadDashboard);

        if (prevCycle) {
            prevCycle.addEventListener("change", function() {
                filterPrevMonthDropdown();
                loadPreviousDashboard();
            });
        }

        if (prevMonth) prevMonth.addEventListener("change", loadPreviousDashboard);
        if (prevYear) prevYear.addEventListener("change", loadPreviousDashboard);

        if (resetBtn) {
            resetBtn.addEventListener("click", function() {
                cycle.value = currentCycle;
                month.value = currentMonth;
                year.value = currentYear;
                filterMonthDropdown();
                loadDashboard();
            });
        }

        filterMonthDropdown();
    });
</script>

</html>
<?php
// upload.php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$currentReviewPeriod = date('F Y');
$pdo           = getPdo();
$currentUser   = getCurrentUser();

date_default_timezone_set('Asia/Kolkata');

$currentMonthShort = date('M');
$currentYear       = date('Y');

if (in_array($currentMonthShort, ['Jan', 'Apr', 'Jul', 'Oct'])) {
    $currentCycle = 'RJ';
} elseif (in_array($currentMonthShort, ['Feb', 'May', 'Aug', 'Nov'])) {
    $currentCycle = 'RF';
} else {
    $currentCycle = 'RM';
}

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

$pdoSlides = getSlidesPdo();
function getLatestAumForClient(PDO $pdo, string $clientName): float
{
    $stmt = $pdo->prepare("
        SELECT aum FROM clients
        WHERE name = :name AND aum > 0
        ORDER BY created_at DESC LIMIT 1
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

$requestedContext = $_GET['view_context'] ?? 'all';
$viewContext = $isAdmin ? $requestedContext : 'mine';

if (!$isFilterApplied) {
    $previousDate    = date('M Y', strtotime('-1 month'));
    $availableMonths = [$previousDate];
} else {
    if ($monthFilter !== '' && $yearFilter !== '') {
        $selectedDate = DateTime::createFromFormat('M Y', $monthFilter . ' ' . $yearFilter);
        $selectedDate->modify('-1 month');
        $availableMonths = [$selectedDate->format('M Y')];
    } else {
        $availableMonths = [];
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `followup_emails` (
        `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name`      VARCHAR(255) NOT NULL,
        `email`     VARCHAR(255) NOT NULL,
        `sent_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_email` (`email`),
        KEY `idx_sent_date` (`sent_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── AJAX: Get meeting KPIs for a given month (for JS filter refresh) ──
if (isset($_GET['ajax_meeting_kpi'])) {
    header('Content-Type: application/json');
    $mk_month = trim($_GET['month_year'] ?? '');
    if ($mk_month) {
        $stmtE = $pdo->prepare("SELECT COUNT(*) FROM followup_emails WHERE DATE_FORMAT(sent_date, '%b %Y') = ?");
        $stmtE->execute([$mk_month]);
        $emails_sent = (int)$stmtE->fetchColumn();

        list($mShort, $y) = explode(' ', $mk_month);

        $stmtM = $pdo->prepare("
    SELECT COUNT(*)
    FROM clients
    WHERE meeting_date IS NOT NULL
      AND MONTH(meeting_date) = ?
AND YEAR(meeting_date) = ?
      AND is_latest = TRUE
");
        $stmtM->execute([$mShort, $y]);
        $meetings_fixed = (int)$stmtM->fetchColumn();
        echo json_encode(['emails_sent' => $emails_sent, 'meetings_fixed' => $meetings_fixed]);
    } else {
        echo json_encode(['emails_sent' => 0, 'meetings_fixed' => 0]);
    }
    exit;
}

// ── CURRENT MONTH MEETING KPIS ────────────────────────────────
$currentMonthYear = $currentMonthShort . ' ' . $currentYear;

$stmtFE = $pdo->prepare("
    SELECT COUNT(*) FROM followup_emails
    WHERE DATE_FORMAT(sent_date, '%b %Y') = ?
");
$stmtFE->execute([$currentMonthYear]);
$currentEmailsSent = (int)$stmtFE->fetchColumn();

$stmtMK = $pdo->prepare("
    SELECT COUNT(*) 
    FROM clients
    WHERE meeting_date IS NOT NULL
     AND MONTH(meeting_date) = ?
AND YEAR(meeting_date) = ?
      AND is_latest = TRUE
");

$stmtMK->execute([
    date('n'),   // numeric month (1–12)
    date('Y')
]);
$currentMeetingsFixed = (int)$stmtMK->fetchColumn();

// ── DASHBOARD STATS FUNCTIONS ─────────────────────────────────
function fetchDashboardStats(PDO $pdo, string $context, int $userId, string $cycleFilter = '', string $monthFilter = '', string $yearFilter = ''): array
{
    $baseWhere = "1=1";
    $params = [];

    if ($context === 'mine') {
        $baseWhere .= " AND assigned_to = ?";
        $params[] = $userId;
    } elseif (ctype_digit($context)) {
        $baseWhere .= " AND assigned_to = ?";
        $params[] = (int)$context;
    }

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

    $sql = "SELECT
                COUNT(DISTINCT name) AS total,
                SUM(CASE WHEN report_state = 'pending'  THEN 1 ELSE 0 END) AS count_pending,
                SUM(CASE WHEN report_state = 'draft'    THEN 1 ELSE 0 END) AS count_draft,
                SUM(CASE WHEN report_state = 'ready'    THEN 1 ELSE 0 END) AS count_ready,
                SUM(CASE WHEN report_state = 'reviewed' THEN 1 ELSE 0 END) AS count_reviewed,
                SUM(CASE WHEN report_state = 'sent'     THEN 1 ELSE 0 END) AS count_sent
            FROM clients
            WHERE is_latest = TRUE AND {$baseWhere}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'          => (int)($row['total']          ?? 0),
        'count_pending'  => (int)($row['count_pending']  ?? 0),
        'count_draft'    => (int)($row['count_draft']    ?? 0),
        'count_ready'    => (int)($row['count_ready']    ?? 0),
        'count_reviewed' => (int)($row['count_reviewed'] ?? 0),
        'count_sent'     => (int)($row['count_sent']     ?? 0),
    ];
}

// ── AUM ───────────────────────────────────────────────────────
$aumWhere  = "is_latest = TRUE";
$aumParams = [];

if ($viewContext === 'mine') {
    $aumWhere .= " AND assigned_to = ?";
    $aumParams = [$currentUserId];
} elseif (ctype_digit($viewContext)) {
    $aumWhere .= " AND assigned_to = ?";
    $aumParams = [(int)$viewContext];
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
if ($yearFilter  !== '') {
    $aumWhere .= " AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";
    $aumParams[] = $yearFilter;
}

$stmtAum = $pdo->prepare("SELECT SUM(aum) FROM clients WHERE {$aumWhere}");
$stmtAum->execute($aumParams);
$totalAum = $stmtAum->fetchColumn() ?: 0;

$usersStmt = $pdo->query("
    SELECT id, username FROM users
    ORDER BY FIELD(username, 'Sanjiv Mehta', 'Sailesh Kumar', 'Sajid', 'Tanmay', 'Akshay') ASC
");
$allUsers  = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$viewStats      = fetchDashboardStats($pdo, $viewContext, $currentUserId, $cycleFilter, $monthFilter, $yearFilter);
$completionRate = safePercent($viewStats['count_sent'], max(1, $viewStats['total']));
$uploadError    = '';

$targetName  = 'My';
if ($viewContext === 'all') {
    $targetName = 'Global';
} elseif (ctype_digit($viewContext)) {
    $targetName = 'User';
}

$navUser         = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
$currentPage     = basename($_SERVER['PHP_SELF']);
$filterParam     = ($viewContext === 'all') ? 'all' : (($viewContext === 'mine') ? 'mine' : $viewContext);
if (!$isAdmin) $filterParam = 'mine';

$userDesignation = $currentUser['designation'] ?? '';

$yearsStmt = $pdo->query("
    SELECT DISTINCT SUBSTRING_INDEX(month_year, ' ', -1) AS year
    FROM clients WHERE month_year IS NOT NULL ORDER BY year DESC
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" />
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

                            <select name="cycle_filter" id="cycleFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="" <?= ($cycleFilter === '')   ? 'selected' : '' ?>>All Cycles</option>
                                <option value="RJ" <?= ($cycleFilter === 'RJ') ? 'selected' : '' ?>>RJ</option>
                                <option value="RM" <?= ($cycleFilter === 'RM') ? 'selected' : '' ?>>RM</option>
                                <option value="RF" <?= ($cycleFilter === 'RF') ? 'selected' : '' ?>>RF</option>
                            </select>

                            <select name="month_filter" id="monthFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="">All Months</option>
                                <?php
                                $months = ['Jan' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Apr' => 'April', 'May' => 'May', 'Jun' => 'June', 'Jul' => 'July', 'Aug' => 'August', 'Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dec' => 'December'];
                                foreach ($months as $k => $v):
                                ?>
                                    <option value="<?= $k ?>" <?= ($monthFilter === $k) ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="year_filter" id="yearFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="">All Years</option>
                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?= $year ?>" <?= ($yearFilter == $year) ? 'selected' : '' ?>><?= $year ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" id="resetFilters"
                                style="padding:8px 14px; font-weight:600; border-radius:8px; border:1px solid #e2e8f0; background:#f1f5f9; cursor:pointer;"
                                onmouseover="this.style.background='#c5eaf5'" onmouseout="this.style.background='#f8fafc'">
                                Reset
                            </button>

                            <?php if (isset($_GET['view_context'])): ?>
                                <input type="hidden" name="view_context" value="<?= htmlspecialchars($_GET['view_context']); ?>">
                            <?php endif; ?>
                        </form>

                        <?php
                        $cycleParam = '';
                        if ($cycleFilter !== '') $cycleParam .= '&cycle_filter=' . urlencode($cycleFilter);
                        if ($monthFilter !== '') $cycleParam .= '&month_filter=' . urlencode($monthFilter);
                        if ($yearFilter  !== '') $cycleParam .= '&year_filter='  . urlencode($yearFilter);
                        ?>
                        <div class="aum-box">
                            <div>AUM Handled</div>
                            <div id="aumValue">₹<?= number_format($totalAum , 2); ?> <span style="font-size:13px;">Cr</span></div>
                        </div>
                    </div>
                </div>

                <nav class="context-navbar">
                    <a href="?view_context=all<?= $cycleParam ?>" class="context-link <?= ($viewContext === 'all')  ? 'active' : '' ?>">All Reviews</a>
                    <?php if (!$isAdmin): ?>
<a href="?view_context=mine<?= $cycleParam ?>" class="context-link <?= ($viewContext === 'mine') ? 'active' : '' ?>">My Reviews</a>
<?php endif; ?>
                    <?php foreach ($allUsers as $user): ?>
                        <?php if ((int)$user['id'] === $currentUserId) continue; ?>
<?php if (strtolower($user['username']) === 'admin') continue; ?>
                        <a href="?view_context=<?= (int)$user['id'] . $cycleParam ?>"
                            class="context-link <?= ($viewContext == $user['id']) ? 'active' : '' ?>">
                            <?= htmlspecialchars($user['username']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- ═══════════════ CURRENT MONTH KPI GRID ═══════════════ -->
            <div class="kpi-grid">
                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>" class="stats-card card-blue">
                    <span class="card-icon"><i class="fa-solid fa-layer-group"></i></span>
                    <div class="label">Total Reviews</div>
                    <div class="number" id="totalCount"><?= (int)$viewStats['total']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>&filter=pending" class="stats-card card-red-outline">
                    <span class="card-icon"><i class="fa-solid fa-hourglass-half"></i></span>
                    <div class="label">Not Started</div>
                    <div class="number" id="pendingCount"><?= (int)$viewStats['count_pending']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>&filter=draft" class="stats-card card-grey">
                    <span class="card-icon"><i class="fa-regular fa-pen-to-square"></i></span>
                    <div class="label">Draft</div>
                    <div class="number" id="draftCount"><?= (int)$viewStats['count_draft']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>&filter=ready" class="stats-card card-yellow">
                    <span class="card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                    <div class="label">Review Prepared</div>
                    <div class="number" id="readyCount"><?= (int)$viewStats['count_ready']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>&filter=reviewed" class="stats-card card-teal">
                    <span class="card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
                    <div class="label">Consent Given</div>
                    <div class="number" id="reviewedCount"><?= (int)$viewStats['count_reviewed']; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=<?= $filterParam ?>&filter=sent" class="stats-card card-green">
                    <span class="card-icon"><i class="fa-solid fa-paper-plane"></i></span>
                    <div class="label">Sent</div>
                    <div class="number" id="sentCount"><?= (int)$viewStats['count_sent']; ?></div>
                </a>

                <a href="followup_mails.php?cycle=<?= $currentCycle ?>"
                    class="stats-card card-blue">
                    <span class="card-icon"><i class="fa-solid fa-envelope-circle-check"></i></span>
                    <div class="label">Meeting Emails Sent</div>
                    <div class="number" id="emailsSentCount"><?= $currentEmailsSent; ?></div>
                </a>

                <a href="view_saved_reports.php?owner_filter=all
&cycle_filter=<?= urlencode($cycleFilter) ?>
&meeting_filter=fixed"
                    class="stats-card card-teal">

                    <span class="card-icon"><i class="fa-solid fa-handshake"></i></span>
                    <div class="label">Meetings Fixed</div>
                    <div class="number"><?= $currentMeetingsFixed; ?></div>

                </a>
            </div><!-- /kpi-grid -->

            <?php if (!empty($availableMonths)):
                $monthYear = $availableMonths[0];
                list($mShort, $y) = explode(' ', $monthYear);

                if (in_array($mShort, ['Jan', 'Apr', 'Jul', 'Oct'])) {
                    $prevCycle = 'RJ';
                } elseif (in_array($mShort, ['Feb', 'May', 'Aug', 'Nov'])) {
                    $prevCycle = 'RF';
                } else {
                    $prevCycle = 'RM';
                }

                $stats = fetchDashboardStats($pdo, $viewContext, $currentUserId, '', $mShort, $y);

                // Previous AUM
                $prevAumWhere  = "is_latest = TRUE AND SUBSTRING_INDEX(month_year, ' ', 1) = ? AND SUBSTRING_INDEX(month_year, ' ', -1) = ?";
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

                // Previous month meeting KPIs
                $prevMonthYear = $mShort . ' ' . $y;

                $stmtPrevFE = $pdo->prepare("SELECT COUNT(*) FROM followup_emails WHERE DATE_FORMAT(sent_date, '%b %Y') = ?");
                $stmtPrevFE->execute([$prevMonthYear]);
                $prevEmailsSent = (int)$stmtPrevFE->fetchColumn();

                $stmtPrevMK = $pdo->prepare("
    SELECT COUNT(*)
    FROM clients
    WHERE meeting_date IS NOT NULL
      AND MONTH(meeting_date) = ?
AND YEAR(meeting_date) = ?
      AND is_latest = TRUE
");
                $prevMonthNum = date('n', strtotime($mShort));
                $stmtPrevMK->execute([$prevMonthNum, $y]);
                $prevMeetingsFixed = (int)$stmtPrevMK->fetchColumn();            ?>

                <div class="page-header prev-header">
                    <div>
                        <h1>
                            Quarterly Review of
                            <span id="prevReviewHeading">
                                <?= date('F', strtotime($mShort . " 1")) . " " . $y ?>
                            </span>
                        </h1>

                        <div style="display:flex; align-items:center; gap:10px;">
                            <select id="prevCycleFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="">All Cycles</option>
                                <option value="RJ">RJ</option>
                                <option value="RM">RM</option>
                                <option value="RF">RF</option>
                            </select>

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

                            <select id="prevYearFilter"
                                style="padding:8px 18px 8px 10px; font-size:15px; font-weight:600; color:#0288D1; border-radius:8px; border:1px solid #e2e8f0; background:#fff;">
                                <option value="">All Years</option>
                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?= $year ?>" <?= ($year == $y) ? 'selected' : '' ?>><?= $year ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" id="prevResetFilters"
                                style="padding:8px 14px; font-weight:600; border-radius:8px; border:1px solid #e2e8f0; background:#f1f5f9; cursor:pointer;"
                                onmouseover="this.style.background='#c5eaf5'" onmouseout="this.style.background='#f8fafc'">
                                Reset
                            </button>

                            <div class="aum-box">
                                <div>AUM Handled</div>
                                <div id="prevAumValue">
                                    ₹<?= number_format($prevTotalAum , 2); ?>
                                    <span style="font-size:13px;">Cr</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $prevParams = ['owner_filter' => $filterParam, 'cycle_filter' => $prevCycle, 'month_filter' => $mShort, 'year_filter' => $y];
                    $prevQuery  = http_build_query($prevParams);
                    ?>

                    <!-- ═══════════════ PREVIOUS MONTH KPI GRID ═══════════════ -->

                </div><!-- /.prev-header -->
                <div class="kpi-grid">
                    <a href="view_saved_reports.php?<?= $prevQuery ?>" class="stats-card card-blue">
                        <span class="card-icon"><i class="fa-solid fa-layer-group"></i></span>
                        <div class="label">Total Reviews</div>
                        <div class="number" id="prevTotalCount"><?= $stats['total']; ?></div>
                    </a>

                    <a href="view_saved_reports.php?<?= $prevQuery ?>&filter=pending" class="stats-card card-red-outline">
                        <span class="card-icon"><i class="fa-solid fa-hourglass-half"></i></span>
                        <div class="label">Not Started</div>
                        <div class="number" id="prevPendingCount"><?= $stats['count_pending']; ?></div>
                    </a>

                    <a href="view_saved_reports.php?<?= $prevQuery ?>&filter=draft" class="stats-card card-grey">
                        <span class="card-icon"><i class="fa-regular fa-pen-to-square"></i></span>
                        <div class="label">Draft</div>
                        <div class="number" id="prevDraftCount"><?= $stats['count_draft']; ?></div>
                    </a>

                    <a href="view_saved_reports.php?<?= $prevQuery ?>&filter=ready" class="stats-card card-yellow">
                        <span class="card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                        <div class="label">Review Prepared</div>
                        <div class="number" id="prevReadyCount"><?= $stats['count_ready']; ?></div>
                    </a>

                    <a href="view_saved_reports.php?<?= $prevQuery ?>&filter=reviewed" class="stats-card card-teal">
                        <span class="card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
                        <div class="label">Consent Given</div>
                        <div class="number" id="prevReviewedCount"><?= $stats['count_reviewed']; ?></div>
                    </a>

                    <a href="view_saved_reports.php?<?= $prevQuery ?>&filter=sent" class="stats-card card-green">
                        <span class="card-icon"><i class="fa-solid fa-paper-plane"></i></span>
                        <div class="label">Sent</div>
                        <div class="number" id="prevSentCount"><?= $stats['count_sent']; ?></div>
                    </a>

                    <a href="followup_mails.php?cycle=<?= $prevCycle ?>"
                        class="stats-card card-blue">
                        <span class="card-icon"><i class="fa-solid fa-envelope-circle-check"></i></span>
                        <div class="label">Meeting Emails Sent</div>
                        <div class="number" id="prevEmailsSentCount"><?= $prevEmailsSent; ?></div>
                    </a>

                    <a href="view_saved_reports.php?owner_filter=all
&cycle_filter=<?= urlencode($prevCycle) ?>
&meeting_filter=fixed"
                        class="stats-card card-teal">

                        <span class="card-icon"><i class="fa-solid fa-handshake"></i></span>
                        <div class="label">Meetings Fixed</div>
                        <div class="number"><?= $prevMeetingsFixed; ?></div>

                    </a>
                </div><!-- /kpi-grid prev -->



            <?php endif; ?>

        </div><!-- /.wrap -->
    </div><!-- /.main-scroll-container -->

</body>


<script>
    // ── Meetings Fixed: inline editable, saves via AJAX ──────────



    // ── Dashboard AJAX filters (unchanged from original) ─────────
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
        const prevResetBtn = document.getElementById("prevResetFilters");

        const currentCycle = "<?= $currentCycle ?>";
        const currentMonth = "<?= $currentMonthShort ?>";
        const currentYear = "<?= $currentYear ?>";

        const cycleMap = {
            "RJ": ["Jan", "Apr", "Jul", "Oct"],
            "RF": ["Feb", "May", "Aug", "Nov"],
            "RM": ["Mar", "Jun", "Sep", "Dec"]
        };

        if (prevCycle && defaultPrevCycle) prevCycle.value = defaultPrevCycle;
        if (prevMonth && defaultPrevMonth) prevMonth.value = defaultPrevMonth;
        if (prevYear && defaultPrevYear) prevYear.value = defaultPrevYear;

        filterPrevMonthDropdown();

        function filterMonthDropdown() {
            const cv = cycle.value;
            for (let o of month.options) {
                o.style.display = (!cv || o.value === "" || cycleMap[cv]?.includes(o.value)) ? "block" : "none";
            }
            if (cv && !cycleMap[cv]?.includes(month.value)) month.value = "";
        }

        function filterPrevMonthDropdown() {
            if (!prevCycle || !prevMonth) return;
            const cv = prevCycle.value;
            for (let o of prevMonth.options) {
                o.style.display = (!cv || o.value === "" || cycleMap[cv]?.includes(o.value)) ? "block" : "none";
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
                .then(r => r.json())
                .then(data => {
                    document.getElementById("totalCount").innerText = data.total;
                    document.getElementById("pendingCount").innerText = data.pending;
                    document.getElementById("draftCount").innerText = data.draft;
                    document.getElementById("readyCount").innerText = data.ready;
                    document.getElementById("reviewedCount").innerText = data.reviewed;
                    document.getElementById("sentCount").innerText = data.sent;
                    let crore = (data.aum ).toFixed(2);
                    document.getElementById("aumValue").innerHTML = "₹" + crore + " <span style='font-size:13px;'>Cr</span>";
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
                .then(r => r.json())
                .then(data => {
                    document.getElementById("prevTotalCount").innerText = data.total;
                    document.getElementById("prevPendingCount").innerText = data.pending;
                    document.getElementById("prevDraftCount").innerText = data.draft;
                    document.getElementById("prevReadyCount").innerText = data.ready;
                    document.getElementById("prevReviewedCount").innerText = data.reviewed;
                    document.getElementById("prevSentCount").innerText = data.sent;
                    let crore = (data.aum ).toFixed(2);
                    document.getElementById("prevAumValue").innerHTML = "₹" + crore + " <span style='font-size:13px;'>Cr</span>";
                    if (prevMonth.value) {
                        document.getElementById("prevReviewHeading").innerText =
                            prevMonth.options[prevMonth.selectedIndex].text + " " + prevYear.value;
                    }

                    // Also refresh prev meeting KPIs when filter changes
                    const my = prevMonth.value + ' ' + prevYear.value;
                    if (prevMonth.value && prevYear.value) {
                        fetch('upload.php?ajax_meeting_kpi=1&month_year=' + encodeURIComponent(my))
                            .then(r => r.json())
                            .then(mk => {
                                document.getElementById("prevEmailsSentCount").innerText = mk.emails_sent ?? 0;
                                document.getElementById("prevMeetingsFixedCount").innerText = mk.meetings_fixed ?? 0;
                                document.getElementById("prevMeetingsFixedCount").dataset.month = my;
                            });
                    }
                });
        }

        if (cycle) cycle.addEventListener("change", function() {
            filterMonthDropdown();
            loadDashboard();
        });
        if (month) month.addEventListener("change", loadDashboard);
        if (year) year.addEventListener("change", loadDashboard);

        if (prevCycle) prevCycle.addEventListener("change", function() {
            filterPrevMonthDropdown();
            loadPreviousDashboard();
        });
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
        if (prevResetBtn) {
            prevResetBtn.addEventListener("click", function() {
                prevCycle.value = defaultPrevCycle;
                prevMonth.value = defaultPrevMonth;
                prevYear.value = defaultPrevYear;
                filterPrevMonthDropdown();
                loadPreviousDashboard();
            });
        }

        filterMonthDropdown();
    });
</script>

</html>
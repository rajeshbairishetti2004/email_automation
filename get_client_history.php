<?php
// get_client_history.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();
header('Content-Type: application/json');

$pdo = getPdo();

/* -----------------------------------------------------------
   INPUT HANDLING
----------------------------------------------------------- */
$clientId   = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$clientName = trim($_GET['client_name'] ?? '');

if ($clientId <= 0 && $clientName === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Client ID or Client Name is required'
    ]);
    exit;
}

/* -----------------------------------------------------------
   IF CLIENT ID GIVEN → GET NAME FROM DB
----------------------------------------------------------- */
if ($clientName === '' && $clientId > 0) {
    $stmt = $pdo->prepare("
        SELECT name, email
        FROM clients
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        echo json_encode([
            'success' => false,
            'error'   => 'Client not found'
        ]);
        exit;
    }

    $clientName  = $client['name'];
    $clientEmail = $client['email'] ?? null;
} else {
    $clientEmail = null;
}

/* -----------------------------------------------------------
   FETCH ALL HISTORY BY CLIENT NAME
----------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.name              AS client_name,
        c.email,
        c.month_year,
        c.review_cycle,
        c.total_amount      AS aum,
        c.priority,
        c.report_state,
        c.allocation_id,
        c.created_at,
        c.updated_at,
        c.draft_at,
        c.ready_at,
        c.reviewed_at,
        c.sent_at,
        rm.name             AS rm_name,
        reviewer.name       AS reviewer_name
    FROM clients c
    LEFT JOIN users rm       ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    WHERE TRIM(c.name) = TRIM(:client_name)
    ORDER BY c.created_at DESC
");

$stmt->execute([':client_name' => $clientName]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------
   CALCULATE STATS
----------------------------------------------------------- */
$totalReviews = count($history);
$years        = [];
$dates        = [];

foreach ($history as $row) {
    if (!empty($row['month_year'])) {
        if (preg_match('/\b(20\d{2})\b/', $row['month_year'], $m)) {
            $years[$m[1]] = true;
        }
    }
    if (!empty($row['created_at'])) {
        $dates[] = $row['created_at'];
    }
}

$yearsCovered = $years ? implode(', ', array_keys($years)) : 'N/A';
$latestReview = $dates ? date('d M Y', strtotime(max($dates))) : 'N/A';
$firstReview  = $dates ? date('d M Y', strtotime(min($dates))) : 'N/A';

/* -----------------------------------------------------------
   RESPONSE
----------------------------------------------------------- */
echo json_encode([
    'success'            => true,
    'total_reviews'      => $totalReviews,
    'years_covered'      => $yearsCovered,
    'latest_review_date' => $latestReview,
    'first_review_date'  => $firstReview,
    'history'            => $history,
    'client_info'        => [
        'name'  => $clientName,
        'email' => $clientEmail
    ]
]);

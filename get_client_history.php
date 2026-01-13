<?php
// get_client_history.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($clientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

$pdo = getPdo();

// Get current client details to identify by name and email
$stmt = $pdo->prepare("
    SELECT name, email 
    FROM clients 
    WHERE id = :client_id
");
$stmt->execute([':client_id' => $clientId]);
$currentClient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentClient) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit;
}

// Find all reviews for this client (matching by name and email)
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.name as client_name,
        c.email,
        c.month_year,
        c.review_cycle,
        c.total_amount as aum,
        c.report_state,
        c.draft_at,
        c.ready_at,
        c.reviewed_at,
        c.sent_at,
        c.created_at,
        c.updated_at,
        rm.name as rm_name,
        reviewer.name as reviewer_name
    FROM clients c
    LEFT JOIN users rm ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    WHERE 
        (c.name = :client_name OR c.email = :client_email)
        AND c.id != :client_id
    ORDER BY c.month_year DESC, c.created_at DESC
");
$stmt->execute([
    ':client_name' => $currentClient['name'],
    ':client_email' => $currentClient['email'],
    ':client_id' => $clientId
]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalReviews = count($history);
$years = [];
$reviewDates = [];

foreach ($history as $review) {
    if ($review['month_year']) {
        $year = date('Y', strtotime($review['month_year'] . '-01'));
        $years[$year] = true;
    }
    
    // Collect dates for first/latest review
    if ($review['created_at']) {
        $reviewDates[] = $review['created_at'];
    }
}

sort($years);
$yearsCovered = count($years) ? min($years) . ' - ' . max($years) : 'N/A';
$latestReviewDate = !empty($reviewDates) ? date('d M Y', strtotime(max($reviewDates))) : 'N/A';
$firstReviewDate = !empty($reviewDates) ? date('d M Y', strtotime(min($reviewDates))) : 'N/A';

// Add current client to history as well
$currentClientStmt = $pdo->prepare("
    SELECT 
        c.id,
        c.name as client_name,
        c.email,
        c.month_year,
        c.review_cycle,
        c.total_amount as aum,
        c.report_state,
        c.draft_at,
        c.ready_at,
        c.reviewed_at,
        c.sent_at,
        c.created_at,
        c.updated_at,
        rm.name as rm_name,
        reviewer.name as reviewer_name
    FROM clients c
    LEFT JOIN users rm ON c.assigned_to = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to = reviewer.id
    WHERE c.id = :client_id
");
$currentClientStmt->execute([':client_id' => $clientId]);
$currentReview = $currentClientStmt->fetch(PDO::FETCH_ASSOC);

if ($currentReview) {
    array_unshift($history, $currentReview);
    $totalReviews++;
    
    // Update statistics with current review
    if ($currentReview['month_year']) {
        $year = date('Y', strtotime($currentReview['month_year'] . '-01'));
        $years[$year] = true;
    }
    
    if ($currentReview['created_at']) {
        $reviewDates[] = $currentReview['created_at'];
    }
    
    sort($years);
    $yearsCovered = count($years) ? min($years) . ' - ' . max($years) : 'N/A';
    $latestReviewDate = !empty($reviewDates) ? date('d M Y', strtotime(max($reviewDates))) : 'N/A';
    $firstReviewDate = !empty($reviewDates) ? date('d M Y', strtotime(min($reviewDates))) : 'N/A';
}

echo json_encode([
    'success' => true,
    'total_reviews' => $totalReviews,
    'years_covered' => $yearsCovered,
    'latest_review_date' => $latestReviewDate,
    'first_review_date' => $firstReviewDate,
    'history' => $history
]);
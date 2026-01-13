<?php
// review_history.php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();

$clientName = $_GET['client_name'] ?? '';
$monthYear = $_GET['month_year'] ?? date('F Y');

$pdo = getPdo();

// Get all review attempts for a client in a specific month
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        u.username as created_by_name,
        DATE_FORMAT(c.created_at, '%d %b %Y %h:%i %p') as formatted_date
    FROM clients c
    LEFT JOIN users u ON c.created_by = u.id
    WHERE c.name = :client_name 
    AND c.month_year = :month_year
    ORDER BY c.review_attempt DESC
");
$stmt->execute([':client_name' => $clientName, ':month_year' => $monthYear]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Review History - <?php echo htmlspecialchars($clientName); ?></title>
    <style>
        .history-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .history-table th { background: #f5f5f5; padding: 12px; text-align: left; }
        .history-table td { padding: 12px; border-bottom: 1px solid #eee; }
        .latest-badge { background: #28a745; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; }
        .old-badge { background: #6c757d; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; }
        .diff-highlight { background: #fff3cd; }
    </style>
</head>
<body>
    <h2>Review History: <?php echo htmlspecialchars($clientName); ?> - <?php echo htmlspecialchars($monthYear); ?></h2>
    
    <table class="history-table">
        <thead>
            <tr>
                <th>Attempt</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>AUM</th>
                <th>Profit</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reviews as $review): ?>
            <tr>
                <td>
                    <?php echo $review['review_attempt']; ?>
                    <?php if ($review['is_latest']): ?>
                        <span class="latest-badge">Latest</span>
                    <?php else: ?>
                        <span class="old-badge">Old Version</span>
                    <?php endif; ?>
                </td>
                <td><?php echo ucfirst($review['report_state']); ?></td>
                <td><?php echo htmlspecialchars($review['created_by_name']); ?></td>
                <td><?php echo $review['formatted_date']; ?></td>
                <td>₹<?php echo number_format($review['total_amount'], 2); ?> Cr</td>
                <td>₹<?php echo number_format($review['profit'], 2); ?> Cr</td>
                <td>
                    <a href="view_report.php?id=<?php echo $review['id']; ?>">View Report</a>
                    <?php if ($review['previous_version_id']): ?>
                        | <a href="compare_reviews.php?id1=<?php echo $review['previous_version_id']; ?>&id2=<?php echo $review['id']; ?>">Compare with Previous</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear

require_once 'auth.php';
require_once 'db_config.php';

requireAuth(); // Ensure login

$pdo = getPdo();

$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = '';
$params = [];

if ($q !== '') {
    $where = "WHERE name LIKE :q OR as_on LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

// 1. Count Total Rows
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients {$where}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

// 2. Fetch Data (INCLUDING NEW WORKFLOW COLUMNS)
$stmt = $pdo->prepare("
    SELECT id, name, as_on, created_at, total_amount, profit, 
           report_state, review_not_ok, review_comment
    FROM clients
    {$where}
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Stored Client Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/view_saved_reports.css">
</head>
<body>

<div class="container">
    <div class="nav-bar">
        <a href="upload.php" class="nav-button">Upload New Files</a>
        <a href="view_saved_reports.php" class="nav-button">View Saved Reports</a>
    </div>

    <h1>Stored Client Reports</h1>

    <form method="get" class="search-box">
        <input type="text" name="q" placeholder="Search by name or date..." value="<?php echo htmlspecialchars($q); ?>">
        <button type="submit">Search</button>
    </form>

    <?php if (!$clients): ?>
        <p style="margin-top: 20px;">No reports found. Try uploading from <a href="upload.php">Upload Page</a>.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Client Name</th>
                <th>Status</th> <th>As On</th>
                <th>Total Amount</th>
                <th>Profit</th>
                <th>Created At</th>
                <th>Action</th>
            </tr>
            <?php foreach ($clients as $c): 
                // --- WORKFLOW BADGE LOGIC ---
                $statusHtml = '';
                
                // 1. Check for Rejection first
                if (isset($c['review_not_ok']) && $c['review_not_ok'] == 1) {
                    $comment = htmlspecialchars($c['review_comment'] ?? '');
                    $statusHtml = "<span class='badge badge-rejected' title='RM Comment: $comment'>NOT OK</span>";
                } 
                // 2. Otherwise check state
                else {
                    $state = $c['report_state'] ?? 'draft'; // Default to draft if null
                    $badgeClass = 'badge-' . $state;
                    
                    // Display "Email Sent" for sent state, otherwise capitalize first letter
                    $displayText = ($state === 'sent') ? 'Email Sent' : ucfirst($state);
                    
                    $statusHtml = "<span class='badge $badgeClass'>" . $displayText . "</span>";
                }

                // Check for attachments
                $hasAttachments = false;
                $cDir = __DIR__ . '/uploads/attachments/client_' . $c['id'];
                if (is_dir($cDir)) {
                    // Check if directory has any files (ignoring . and ..)
                    $files = array_diff(scandir($cDir), ['.', '..']);
                    if (count($files) > 0) {
                        $hasAttachments = true;
                    }
                }
            ?>
                <tr>
                    <td><?php echo (int)$c['id']; ?></td>
                    <td>
                        <?php echo htmlspecialchars($c['name']); ?>
                        <?php if($hasAttachments): ?>
                            <span title="Has Attachments">📎</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $statusHtml; ?></td> <td><?php echo htmlspecialchars($c['as_on'] ?? ''); ?></td>
                    <td><?php echo formatRupeesLakhs((float)$c['total_amount']); ?></td>
                    <td><?php echo formatRupeesLakhs((float)$c['profit']); ?></td>
                    <td><?php echo htmlspecialchars(date('d-M-Y', strtotime($c['created_at']))); ?></td>
                    <td><a href="view_report.php?id=<?php echo (int)$c['id']; ?>" style="font-weight: 600; color:#0288D1;">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="pagination">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>:
            <?php
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p == $page) {
                    echo "<strong>{$p}</strong> ";
                } else {
                    $params = ['page' => $p];
                    if ($q !== '') $params['q'] = $q;
                    $url = 'view_saved_reports.php?' . http_build_query($params);
                    echo "<a href=\"{$url}\">{$p}</a> ";
                }
            }
            ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
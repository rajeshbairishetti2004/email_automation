<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear

require_once 'auth.php';
require_once 'db_config.php';

requireAuth(); // Ensure login

$pdo = getPdo();

$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = '';
$params = [];

if ($q !== '') {
    $where = "WHERE (name LIKE :q OR as_on LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$validStates = ['draft', 'ready', 'reviewed', 'sent'];
if ($filter !== '' && in_array($filter, $validStates, true)) {
    if ($where === '') {
        $where = "WHERE report_state = :filter_state";
    } else {
        $where .= " AND report_state = :filter_state";
    }
    $params[':filter_state'] = $filter;
}

// 1. Count Total Rows
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients {$where}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

// 2. Fetch Data (INCLUDING NEW WORKFLOW COLUMNS AND CREATOR INFO)
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.as_on, c.created_at, c.total_amount, c.profit, 
           c.report_state, c.review_not_ok, c.review_comment, c.created_by,
           u.username as creator_username
    FROM clients c
    LEFT JOIN users u ON c.created_by = u.id
    {$where}
    ORDER BY c.created_at DESC, c.id DESC
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
    <style>
        body { font-family: 'Inter', sans-serif; margin: 0; padding: 0; background: #f7f9fb; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        h1 { margin-bottom: 20px; font-family: 'Poppins', sans-serif; color: #0288D1; }
        
        table { border-collapse: collapse; width: 100%; margin-top: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        th, td { padding: 12px 15px; font-size: 14px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f1f8e9; color: #333; font-weight: 600; }
        tr:hover { background: #f9f9f9; }
        
        a { text-decoration: none; color: #0056b3; }
        a:hover { text-decoration: underline; }

        .nav-bar { margin-bottom: 20px; }
        .nav-button { display: inline-block; margin-right: 10px; padding: 8px 16px; background-color: #0288D1; color: #fff; border-radius: 5px; text-decoration: none; font-size: 14px; font-weight: 500; }
        .nav-button:hover { background-color: #01579B; }

        .search-box { margin-top: 10px; }
        .search-box input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 250px; }
        .search-box button { padding: 8px 15px; background: #0288D1; color: white; border: none; border-radius: 4px; cursor: pointer; }

        .pagination { margin-top: 20px; font-size: 14px; }
        .pagination a { margin-right: 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; color: #333; }
        .pagination strong { padding: 5px 10px; background: #0288D1; color: white; border-radius: 4px; margin-right: 5px; }
        
        /* Workflow Status Badges */
        .badge { padding: 5px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; border: 1px solid transparent; display: inline-block; min-width: 60px; text-align: center;}
        .badge-draft { background: #e0e0e0; color: #555; border-color: #ccc; }
        .badge-ready { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .badge-reviewed { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .badge-sent { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .badge-rejected { background: #f8d7da; color: #721c24; border-color: #f5c6cb; cursor: help; }
    </style>
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
                <th>Created By</th>
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
                    <td>
                        <?php if (!empty($c['creator_username'])): ?>
                            <span class="badge" style="background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; padding: 5px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                <?php echo htmlspecialchars($c['creator_username']); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999; font-size: 0.85em;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars(date('d-M-Y h:i A', strtotime($c['created_at']))); ?></td>
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
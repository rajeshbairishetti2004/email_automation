<?php
// view_saved_reports.php
// - Lists all stored clients (with search + paging)

require_once 'login.php';
require_once 'db_config.php';

requireAuth();

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

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients {$where}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$stmt = $pdo->prepare("
    SELECT id, name, as_on, created_at, total_amount, profit
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
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; font-size: 13px; }
        th { background-color: #f0f0f0; }
        a { text-decoration: none; color: #0056b3; }
        a:hover { text-decoration: underline; }

        .nav-bar { margin-bottom: 20px; }
        .nav-button {
            display: inline-block;
            margin-right: 10px;
            padding: 6px 12px;
            background-color: #0056b3;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
        }
        .nav-button:hover { background-color: #003f82; }

        .search-box { margin-top: 10px; }
        .pagination { margin-top: 10px; font-size: 13px; }
        .pagination a { margin-right: 5px; }
    </style>
</head>
<body>

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
    <p>No reports found. Try uploading from <a href="upload.php">Upload Page</a>.</p>
<?php else: ?>
    <table>
        <tr>
            <th>ID</th>
            <th>Client Name</th>
            <th>As On</th>
            <th>Total Amount</th>
            <th>Profit</th>
            <th>Created At</th>
            <th>View</th>
        </tr>
        <?php foreach ($clients as $c): ?>
            <tr>
                <td><?php echo (int)$c['id']; ?></td>
                <td><?php echo htmlspecialchars($c['name']); ?></td>
                <td><?php echo htmlspecialchars($c['as_on']); ?></td>
                <td><?php echo formatRupeesLakhs((float)$c['total_amount']); ?></td>
                <td><?php echo formatRupeesLakhs((float)$c['profit']); ?></td>
                <td><?php echo htmlspecialchars($c['created_at']); ?></td>
                <td><a href="view_report.php?id=<?php echo (int)$c['id']; ?>">Open</a></td>
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

</body>
</html>
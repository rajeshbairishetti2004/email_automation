<?php
// customer_list.php

require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();

use PhpOffice\PhpSpreadsheet\IOFactory;

/* ===============================
   EXCEL UPLOAD HANDLER (ADMIN)
================================ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_excel']) &&
    ($_SESSION['designation'] ?? '') === 'Admin'
) {
    require_once 'vendor/autoload.php';

    if (!isset($_FILES['customer_excel']) || $_FILES['customer_excel']['error'] !== UPLOAD_ERR_OK) {
        die("Excel upload failed");
    }

    $originalName = $_FILES['customer_excel']['name'];
    if (stripos($originalName, 'customer_list') === false) {
        die("Invalid file. Please upload customer_list.xlsx");
    }

    $spreadsheet = IOFactory::load($_FILES['customer_excel']['tmp_name']);
    $rows = $spreadsheet->getActiveSheet()->toArray();
    unset($rows[0]); // header row

    $stmt = $pdo->prepare("
        INSERT INTO customer_list
        (name, pan, email, mobile, family_head, city, company, first_investment, aum, tags, rm)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            email = VALUES(email),
            mobile = VALUES(mobile),
            family_head = VALUES(family_head),
            city = VALUES(city),
            company = VALUES(company),
            first_investment = VALUES(first_investment),
            aum = VALUES(aum),
            tags = VALUES(tags),
            rm = VALUES(rm)
    ");

    foreach ($rows as $row) {
        $name   = trim($row[0] ?? '');
        $pan    = trim($row[1] ?? '');
        if ($pan === '') continue;

        $email  = trim($row[2] ?? '');
        $mobile = trim($row[3] ?? '');
        $fh     = trim($row[4] ?? '');
        $city   = trim($row[5] ?? '');
        $company= trim($row[6] ?? '');
        $dateIn = trim($row[7] ?? '');
        $aumTxt = trim($row[8] ?? '');
        $tags   = trim($row[9] ?? '');
        $rm     = trim($row[10] ?? '');

        $date = $dateIn ? date('Y-m-d', strtotime($dateIn)) : null;

        $aum = 0;
        if (preg_match('/([\d.]+)\s*Lakhs/i', $aumTxt, $m)) {
            $aum = $m[1] * 100000;
        } elseif (preg_match('/([\d.]+)\s*Cr/i', $aumTxt, $m)) {
            $aum = $m[1] * 10000000;
        }

        $stmt->execute([
            $name, $pan, $email, $mobile, $fh,
            $city, $company, $date, $aum, $tags, $rm
        ]);
    }

    header("Location: customer_list.php");
    exit;
}

/* ===============================
   FETCH CUSTOMERS
================================ */
$customers = $pdo->query("
    SELECT name, pan, email, mobile, family_head, city,
           company, first_investment, aum, tags, rm
    FROM customer_list
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Dropdown values */
$cities = array_unique(array_column($customers, 'city'));
$rms    = array_unique(array_column($customers, 'rm'));
sort($cities);
sort($rms);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer List</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="public/css/navbar.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">

<style>
.page-container { padding: 50px; font-family: 'Inter', sans-serif; }
.page-title { font-size: 22px; font-weight: 700; margin-bottom: 12px; }

/* Toolbar */
.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.search-group, .upload-form {
    display: flex;
    gap: 10px;
    align-items: center;
}

 select {
    height: 40px;
    padding: 0 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
}
.search-box {
    width: 420px;          /* ⬅ increase width here */
    min-width: 420px;
    height: 40px;
    padding: 0 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
}


.action-btn {
    height: 40px;
    padding: 0 16px;
    font-size: 13px;
    border: 1px solid #ccc;
    background: #f3f4f6;
    border-radius: 6px;
    cursor: pointer;
}

.action-btn:hover { background: #e5e7eb; }

.file-label {
    height: 40px;
    padding: 0 16px;
    display: flex;
    align-items: center;
    border: 1px solid #ccc;
    border-radius: 6px;
    cursor: pointer;
}
.file-label input { display: none; }

#fileText {
    max-width: 160px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

/* Table */
.table-wrapper {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow-x: auto;
}
.customer-table {
    width: 100%;
    border-collapse: collapse;
}
.customer-table th, .customer-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #eaeaea;
    font-size: 14px;
}
.customer-table th {
    background: #f8f9fb;
    font-weight: 600;
}
.customer-table tr:hover { background: #fafafa; }

@media (max-width: 900px) {
    .toolbar { flex-direction: column; align-items: stretch; }
}
</style>
</head>

<body>
<?php require_once 'navbar.php'; ?>

<div class="page-container">

<h2 class="page-title">Customer List</h2>

<div class="toolbar">

    <!-- SEARCH + FILTERS -->
    <div class="search-group">
        <input type="text" id="searchInput" class="search-box" placeholder="Search..." onkeyup="applyFilters()">

        <select id="cityFilter" onchange="applyFilters()">
            <option value="">All Cities</option>
            <?php foreach ($cities as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="rmFilter" onchange="applyFilters()">
            <option value="">All RM</option>
            <?php foreach ($rms as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="tagFilter" onchange="applyFilters()">
            <option value="">All Tags</option>
            <option value="RJ">RJ</option>
            <option value="RF">RF</option>
            <option value="RM">RM</option>
        </select>

        <button class="action-btn" onclick="resetFilters()">Reset</button>
    </div>

    <!-- ADMIN UPLOAD -->
    <?php if (($_SESSION['designation'] ?? '') === 'Admin'): ?>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
        <label class="file-label">
            <input type="file" name="customer_excel" accept=".xlsx" onchange="showFileName(this)" required>
            <span id="fileText">Choose File</span>
        </label>
        <button type="submit" name="upload_excel" class="action-btn">Upload Excel</button>
    </form>
    <?php endif; ?>

</div>

<div class="table-wrapper">
<table class="customer-table" id="customerTable">
<thead>
<tr>
<th>Name</th><th>PAN</th><th>Email</th><th>Mobile</th>
<th>Family Head</th><th>City</th><th>Company</th>
<th>First Investment</th><th>AUM</th><th>Tag</th><th>RM</th>
</tr>
</thead>
<tbody>
<?php foreach ($customers as $c): ?>
<tr>
<td><?= htmlspecialchars($c['name']) ?></td>
<td><?= htmlspecialchars($c['pan']) ?></td>
<td><?= htmlspecialchars($c['email']) ?></td>
<td><?= htmlspecialchars($c['mobile']) ?></td>
<td><?= htmlspecialchars($c['family_head']) ?></td>
<td><?= htmlspecialchars($c['city']) ?></td>
<td><?= htmlspecialchars($c['company']) ?></td>
<td><?= $c['first_investment'] ? date('d M Y', strtotime($c['first_investment'])) : '-' ?></td>
<td>
<?php
$a = (float)$c['aum'];
if ($a >= 10000000) echo '₹'.number_format($a/10000000,2).' Cr';
elseif ($a >= 100000) echo '₹'.number_format($a/100000,2).' Lakhs';
else echo '₹'.number_format($a,2);
?>
</td>
<td><?= htmlspecialchars($c['tags']) ?></td>
<td><?= htmlspecialchars($c['rm']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>

<script>
function applyFilters() {
    const s = document.getElementById('searchInput').value.toLowerCase();
    const city = document.getElementById('cityFilter').value.toLowerCase();
    const rm = document.getElementById('rmFilter').value.toLowerCase();
    const tag = document.getElementById('tagFilter').value.toLowerCase();

    document.querySelectorAll('#customerTable tbody tr').forEach(r => {
        const t = r.innerText.toLowerCase();
        const c = r.children[5].innerText.toLowerCase();
        const tg = r.children[9].innerText.toLowerCase();
        const m = r.children[10].innerText.toLowerCase();

        r.style.display =
            t.includes(s) &&
            (!city || c === city) &&
            (!tag || tg === tag) &&
            (!rm || m === rm)
            ? '' : 'none';
    });
}

function resetFilters() {
    searchInput.value = '';
    cityFilter.value = '';
    rmFilter.value = '';
    tagFilter.value = '';
    applyFilters();
}

function showFileName(i) {
    fileText.textContent = i.files.length ? i.files[0].name : 'Choose File';
}
</script>

</body>
</html>

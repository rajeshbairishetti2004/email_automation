<?php
// customer_list.php

require_once 'auth.php';
require_once 'db_config.php';
requireAuth();

use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = getPdo();

/* ===============================
   DELETE ALL CUSTOMERS (ADMIN)
================================ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_all_customers']) &&
    ($_SESSION['designation'] ?? '') === 'Admin'
) {
    try {
        // Safer than DELETE for full wipe (resets auto_increment)
        $pdo->exec("TRUNCATE TABLE customer_list");

        header("Location: customer_list.php?deleted=1");
        exit;
    } catch (Exception $e) {
        die("Failed to delete customers: " . $e->getMessage());
    }
}

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

    /* 🔒 Strict filename check (NEW FORMAT ONLY) */
    $originalName = $_FILES['customer_excel']['name'];
    if (stripos($originalName, 'Client details') === false) {
        die("Invalid file. Please upload the latest Client details Excel only.");
    }

    $spreadsheet = IOFactory::load($_FILES['customer_excel']['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows  = $sheet->toArray(null, true, true, true);

    /* ---- Header mapping ---- */
    $header = array_shift($rows);
    $map = [];
    foreach ($header as $col => $name) {
        $map[trim($name)] = $col;
    }

    $get = fn($row, $key) => trim($row[$map[$key]] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO customer_list
        (name, pan, email, mobile, family_head, city, company,
         first_investment, aum, tags, rm)
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
        $pan = $get($row, 'PAN');
        if ($pan === '') continue;

        $name    = $get($row, 'NAME');
        $email   = $get($row, 'EMAIL');
        $mobile  = $get($row, 'MOBILE');
        $fh      = $get($row, 'FAMILY HEAD');
        $city    = $get($row, 'CITY');
        $company = $get($row, 'MODEL NAME');
        $tags    = $get($row, 'TAGS');
        $rm      = $get($row, 'RELATIONSHIP  MANAGER');

        $dateRaw = $get($row, 'First Investment Date');
        $date = $dateRaw ? date('Y-m-d', strtotime($dateRaw)) : null;

        $aumRaw = $get($row, 'AUM');
        $aum = is_numeric($aumRaw) ? (float)$aumRaw : 0;

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

$cities = array_unique(array_filter(array_column($customers, 'city')));
$rms    = array_unique(array_filter(array_column($customers, 'rm')));
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
html, body {
    height: 100%;
    overflow-y: hidden; /* ✅ restore scrollbar */
}

.page-container {
    padding: 50px;
    font-family: 'Inter', sans-serif;
    min-height: calc(100vh - 60px);
}

.page-title {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 12px;
}

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

select, .search-box {
    height: 40px;
    padding: 0 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
}

.search-box {
    width: 420px;
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
    overflow-y: auto;
    max-height: 70vh; /* ✅ table scroll */
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

.client-link {
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
}

.client-link:hover {
    text-decoration: underline;
}

@media (max-width: 900px) {
    .toolbar { flex-direction: column; align-items: stretch; }
}
</style>
</head>

<body>
<?php require_once 'navbar.php'; ?>

<div class="page-container">

<h2 class="page-title">Customer List</h2>

<?php if (isset($_GET['deleted'])): ?>
    <div style="margin-bottom:12px;color:#15803d;font-weight:600;">
        ✅ All customers deleted successfully.
    </div>
<?php endif; ?>

<div class="toolbar">

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

    <?php if (($_SESSION['designation'] ?? '') === 'Admin'): ?>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
        <label class="file-label">
            <input type="file" name="customer_excel" accept=".xlsx" onchange="showFileName(this)" required>
            <span id="fileText">Choose File</span>
        </label>
        <button type="submit" name="upload_excel" class="action-btn">Upload Excel</button>
    </form>
<form method="POST"
      id="deleteAllForm"
      style="margin-left:12px;">
<button
    type="button"
    onclick="openDeleteModal()"
    class="action-btn"
    style="background:#fee2e2;color:#b91c1c;border-color:#fecaca;">
    🗑 Delete All Customers
</button>
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
<td>
<a class="client-link"
   href="view_saved_reports.php?from_customer_list=1
        &q=<?= urlencode($c['name']) ?>
        &cycle=all
        &owner=all
        &state=all"
   target="_blank">
    <?= htmlspecialchars($c['name']) ?>
</a>
</td>
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
<!-- Delete All Confirmation Modal -->
<div id="deleteAllModal"
     style="display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.5);
            z-index:9999;
            align-items:center;
            justify-content:center;">

    <div style="background:#fff;
                padding:24px;
                border-radius:10px;
                width:420px;
                max-width:90%;
                box-shadow:0 20px 40px rgba(0,0,0,0.25);">

        <h3 style="margin-top:0;color:#b91c1c;">
            ⚠️ Confirm Delete
        </h3>

        <p style="font-size:14px;color:#333;line-height:1.5;">
            This will <strong>permanently delete ALL customers</strong>
            from the system.<br><br>
            <strong>This action cannot be undone.</strong>
        </p>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button type="button"
                    onclick="closeDeleteModal()"
                    class="action-btn">
                ❌ No, Cancel
            </button>

            <button type="button"
                    onclick="submitDeleteAll()"
                    class="action-btn"
                    style="background:#fee2e2;color:#b91c1c;border-color:#fecaca;">
                ✅ Yes, Delete All
            </button>
        </div>
    </div>
</div>
<script>
function applyFilters() {
    const s = searchInput.value.toLowerCase();
    const city = cityFilter.value.toLowerCase();
    const rm = rmFilter.value.toLowerCase();
    const tag = tagFilter.value.toLowerCase();

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

function openDeleteModal() {
    document.getElementById('deleteAllModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteAllModal').style.display = 'none';
}

function submitDeleteAll() {
    const form = document.getElementById('deleteAllForm');

    // Add hidden input dynamically
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_all_customers';
    input.value = '1';
    form.appendChild(input);

    form.submit();
}
</script>

</body>
</html>

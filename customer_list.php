<?php
// customer_list.php

require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();

use PhpOffice\PhpSpreadsheet\IOFactory;

/* ===============================
   EXCEL UPLOAD HANDLER
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_excel'])) {

    require_once 'vendor/autoload.php';

    if (!isset($_FILES['customer_excel']) || $_FILES['customer_excel']['error'] !== UPLOAD_ERR_OK) {
        die("Excel upload failed");
    }

    // ✅ filename validation
    $originalName = $_FILES['customer_excel']['name'];
    if (stripos($originalName, 'customer_list') === false) {
        die("Invalid file. Please upload a customer_list Excel file.");
    }

    $spreadsheet = IOFactory::load($_FILES['customer_excel']['tmp_name']);
    $rows = $spreadsheet->getActiveSheet()->toArray();

    unset($rows[0]); // skip header row

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

        // ✅ SAFE COLUMN MAPPING (NO WARNINGS)
        $name            = trim($row[0] ?? '');
        $pan             = trim($row[1] ?? '');
        $email           = trim($row[2] ?? '');
        $mobile          = trim($row[3] ?? '');
        $familyHead      = trim($row[4] ?? '');
        $city            = trim($row[5] ?? '');
        $company         = trim($row[6] ?? '');
        $firstInvestment = trim($row[7] ?? '');
        $aumText         = trim($row[8] ?? '');
        $tags            = trim($row[9] ?? '');
        $rm              = trim($row[10] ?? '');

        // ✅ skip empty rows
        if ($pan === '') {
            continue;
        }

        // Date conversion
        $date = $firstInvestment ? date('Y-m-d', strtotime($firstInvestment)) : null;

        // AUM conversion (Lakhs / Cr → rupees)
        $aum = 0;
        if (preg_match('/([\d.]+)\s*Lakhs/i', $aumText, $m)) {
            $aum = $m[1] * 100000;
        } elseif (preg_match('/([\d.]+)\s*Cr/i', $aumText, $m)) {
            $aum = $m[1] * 10000000;
        }

        $stmt->execute([
            $name,
            $pan,
            $email,
            $mobile,
            $familyHead,
            $city,
            $company,
            $date,
            $aum,
            $tags,
            $rm
        ]);
    }

    header("Location: customer_list.php");
    exit;
}

/* ===============================
   FETCH CUSTOMERS
================================ */
$stmt = $pdo->query("
    SELECT name, pan, email, mobile, family_head, city,
           company, first_investment, aum, tags, rm
    FROM customer_list
    ORDER BY name ASC
");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

/* ===== TOOLBAR ===== */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 20px;
}

.search-container,
.upload-form {
    display: flex;
    align-items: center;
    gap: 10px;
}

.search-box,
.action-btn,
.file-label {
    height: 40px;
    display: flex;
    align-items: center;
    box-sizing: border-box;
}

/* Search */
.search-box {
    width: 320px;
    padding: 0 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
}

/* Buttons */
.action-btn {
    padding: 0 16px;
    font-size: 13px;
    border: 1px solid #ccc;
    background: #f3f4f6;
    border-radius: 6px;
    cursor: pointer;
}
.action-btn:hover { background: #e5e7eb; }

/* File input */
.file-label {
    padding: 0 16px;
    border: 1px solid #ccc;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
}
.file-label input { display: none; }

/* ===== TABLE ===== */
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
.customer-table th,
.customer-table td {
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

#fileText {
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

</style>
</head>

<body>
<?php require_once 'navbar.php'; ?>

<div class="page-container">

<h2 class="page-title">Customer List</h2>

<div class="toolbar">
    <div class="search-container">
        <input type="text" id="customerSearch" class="search-box" placeholder="Search..." onkeyup="filterCustomers()">
        <button class="action-btn" onclick="resetSearch()">Reset</button>
    </div>

<?php if ($_SESSION['designation'] === 'Admin'): ?>
<form method="POST" enctype="multipart/form-data" class="upload-form">
    <label class="file-label">
        <input type="file" name="customer_excel" accept=".xlsx" required onchange="showFileName(this)">
        <span id="fileText">Choose File</span>
    </label>

    <button type="submit" name="upload_excel" class="action-btn">
        Upload Excel
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
$aum = (float)$c['aum'];
if ($aum >= 10000000) echo '₹' . number_format($aum/10000000,2) . ' Cr';
elseif ($aum >= 100000) echo '₹' . number_format($aum/100000,2) . ' Lakhs';
else echo '₹' . number_format($aum,2);
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
function filterCustomers() {
    const v = document.getElementById("customerSearch").value.toLowerCase();
    document.querySelectorAll("#customerTable tbody tr").forEach(r =>
        r.style.display = r.innerText.toLowerCase().includes(v) ? "" : "none"
    );
}
function resetSearch() {
    document.getElementById("customerSearch").value = "";
    filterCustomers();
}


function showFileName(input) {
    const fileText = document.getElementById('fileText');
    if (input.files && input.files.length > 0) {
        fileText.textContent = input.files[0].name;
    } else {
        fileText.textContent = 'Choose File';
    }
}

</script>

</body>
</html>

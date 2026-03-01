    <?php
    // customer_list.php

    require_once 'auth.php';
    require_once 'db_config.php';
    requireAuth();

    use PhpOffice\PhpSpreadsheet\IOFactory;

    $pdo = getPdo();

    /* ===============================
   AJAX HANDLER FOR COMPANY EDIT
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'update_company') {
        $pan = $_POST['pan'] ?? '';
        $company = trim($_POST['company'] ?? '');

        if (empty($pan)) {
            echo json_encode(['success' => false, 'error' => 'PAN required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE customer_list SET company = ? WHERE pan = ?");
            $stmt->execute([$company, $pan]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

   
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
    FETCH CUSTOMERS
    ================================ */

$statusFilter = $_GET['status'] ?? 'active';

$where = [];

if ($statusFilter === 'active') {
    $where[] = "aum > 0";
} elseif ($statusFilter === 'inactive') {
    $where[] = "aum = 0";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$customers = $pdo->query("
    SELECT name, pan, email, mobile, family_head, city,
           company, first_investment, aum, tags, rm
    FROM customer_list
    $whereSql
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

    $cities = array_unique(array_filter(array_column($customers, 'city')));
    $rms    = array_unique(array_filter(array_column($customers, 'rm')));
    sort($cities);
    sort($rms);

    // Count active/inactive for display
    $activeCount = count(array_filter($customers, fn($c) => (float)$c['aum'] > 0));
    $inactiveCount = count($customers) - $activeCount;
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
        overflow-y: hidden;
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
        flex-wrap: wrap;
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

    /* Stats badge */
    .filter-stats {
        font-size: 13px;
        color: #6b7280;
        margin-left: 8px;
    }

    /* Table */
    .table-wrapper {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow-x: auto;
        overflow-y: auto;
        max-height: 70vh;
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
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .customer-table tr:hover { background: #fafafa; }

    /* Status indicators */
    .status-active {
        border-left: 3px solid #10b981;
    }

    .status-inactive {
        border-left: 3px solid #ef4444;
        opacity: 0.8;
    }

    /* Editable company field */
    .editable-company {
        cursor: pointer;
        position: relative;
        padding: 4px 8px;
        border-radius: 4px;
        transition: background-color 0.2s;
        min-width: 120px;
    }

    .editable-company:hover {
        background-color: #f0f9ff;
    }

    .editable-company:hover::after {
        content: '✎';
        position: absolute;
        right: 4px;
        top: 50%;
        transform: translateY(-50%);
        color: #2563eb;
        font-size: 12px;
    }

    .company-input {
        width: 100%;
        padding: 6px 8px;
        border: 2px solid #2563eb;
        border-radius: 4px;
        font-size: 14px;
        outline: none;
        background: white;
    }

    .company-input:focus {
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .saving-indicator {
        color: #2563eb;
        font-size: 12px;
        margin-left: 8px;
        display: inline-block;
    }

    .client-link {
        color: #2563eb;
        font-weight: 600;
        text-decoration: none;
    }

    .client-link:hover {
        text-decoration: underline;
    }

    /* AUM badge */
    .aum-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 500;
    }

    .aum-positive {
        background: #e6f7e6;
        color: #0a5c0a;
    }

    .aum-zero {
        background: #fee2e2;
        color: #b91c1c;
    }

    @media (max-width: 900px) {
        .toolbar { flex-direction: column; align-items: stretch; }
    }
    /* =========================
   THIN VERTICAL SCROLLBAR
   ========================= */

/* Chrome, Edge, Safari */
.table-wrapper::-webkit-scrollbar {
    width: 6px;   /* 👈 reduce width here */
}

.table-wrapper::-webkit-scrollbar-track {
    background: transparent;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 10px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background-color: #94a3b8;
}

/* Firefox */
.table-wrapper {
    scrollbar-width: thin;
    scrollbar-color: lightgray;
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

            <!-- NEW: Active/Inactive Filter -->
<select id="statusFilter" onchange="onStatusChange()">
    <option value="active" <?= ($statusFilter==='active')?'selected':'' ?>>Active</option>
    <option value="inactive" <?= ($statusFilter==='inactive')?'selected':'' ?>>Inactive</option>
    <option value="all" <?= ($statusFilter==='all')?'selected':'' ?>>All</option>
</select>
            <!-- <span class="filter-stats" id="statsDisplay"></span> -->

            <button class="action-btn" onclick="resetFilters()">Reset</button>
        </div>

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
    <?php foreach ($customers as $c): 
        $isActive = (float)$c['aum'] > 0;
        $statusClass = $isActive ? 'status-active' : 'status-inactive';
    ?>
    <tr class="<?= $statusClass ?>" data-pan="<?= htmlspecialchars($c['pan']) ?>" data-aum="<?= (float)$c['aum'] ?>">
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
        <td>
            <span class="editable-company" 
                onclick="editCompany(this, '<?= htmlspecialchars($c['pan']) ?>')"
                data-original="<?= htmlspecialchars($c['company']) ?>">
                <?= htmlspecialchars($c['company'] ?: '—') ?>
            </span>
            <span class="saving-indicator" style="display:none;">saving...</span>
        </td>
        <td><?= $c['first_investment'] ? date('d M Y', strtotime($c['first_investment'])) : '-' ?></td>
        <td>
            <?php
            $a = (float)$c['aum'];
            $aumClass = $isActive ? 'aum-positive' : 'aum-zero';
            if ($a >= 10000000) echo '<span class="aum-badge ' . $aumClass . '">₹'.number_format($a/10000000,2).' Cr</span>';
            elseif ($a >= 100000) echo '<span class="aum-badge ' . $aumClass . '">₹'.number_format($a/100000,2).' Lakhs</span>';
            else echo '<span class="aum-badge ' . $aumClass . '">₹'.number_format($a,2).'</span>';
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
<script>
// Store all customer rows data for filtering
const customerRows = [];
let currentlyEditing = null;  // Track currently editing input
let autosaveTimer = null;     // Timer for debounced save

// Debounce function to limit API calls
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    // Populate customer data array for efficient filtering
    document.querySelectorAll('#customerTable tbody tr').forEach((row, index) => {
        const cells = row.cells;
        customerRows.push({
            element: row,
            name: cells[0]?.innerText.toLowerCase() || '',
            pan: cells[1]?.innerText.toLowerCase() || '',
            email: cells[2]?.innerText.toLowerCase() || '',
            mobile: cells[3]?.innerText.toLowerCase() || '',
            familyHead: cells[4]?.innerText.toLowerCase() || '',
            city: cells[5]?.innerText.toLowerCase() || '',
            company: cells[6]?.innerText.toLowerCase() || '',
            tag: cells[9]?.innerText.toLowerCase() || '',
            rm: cells[10]?.innerText.toLowerCase() || '',
            aum: parseFloat(row.dataset.aum || 0)
        });
    });
    
    updateStats();
    applyFilters(); // Initial filter with "active" selected by default
});

function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const city = document.getElementById('cityFilter').value.toLowerCase();
    const rm = document.getElementById('rmFilter').value.toLowerCase();
    const tag = document.getElementById('tagFilter').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    
    let visibleCount = 0;
    
    customerRows.forEach(row => {
        // Search in multiple fields
        const matchesSearch = searchTerm === '' || 
            row.name.includes(searchTerm) ||
            row.pan.includes(searchTerm) ||
            row.email.includes(searchTerm) ||
            row.mobile.includes(searchTerm) ||
            row.familyHead.includes(searchTerm) ||
            row.city.includes(searchTerm) ||
            row.company.includes(searchTerm);
        
        const matchesCity = !city || row.city === city;
        const matchesRm = !rm || row.rm === rm;
        const matchesTag = !tag || row.tag === tag;
        
        // Status filter logic
        let matchesStatus = true;
        if (status === 'active') {
            matchesStatus = row.aum > 0;
        } else if (status === 'inactive') {
            matchesStatus = row.aum === 0;
        } // 'all' matches everything
        
        const isVisible = matchesSearch && matchesCity && matchesRm && matchesTag && matchesStatus;
        row.element.style.display = isVisible ? '' : 'none';
        
        if (isVisible) visibleCount++;
    });
    
    updateStats(visibleCount);
}

function updateStats(visibleCount) {
    const total = customerRows.length;
    const active = customerRows.filter(r => r.aum > 0).length;
    const inactive = total - active;
    const status = document.getElementById('statusFilter').value;
    
    let statusText = '';
    if (status === 'active') statusText = 'Active';
    else if (status === 'inactive') statusText = 'Inactive';
    else statusText = 'All';
    
    // Uncomment if you have a statsDisplay element
    // document.getElementById('statsDisplay').innerHTML = 
    //     `Showing ${visibleCount || 0}/${total} · ${active} Active · ${inactive} Inactive`;
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('cityFilter').value = '';
    document.getElementById('rmFilter').value = '';
    document.getElementById('tagFilter').value = '';
    document.getElementById('statusFilter').value = 'active'; // Default to active
    applyFilters();
}

function showFileName(i) {
    document.getElementById('fileText').textContent = i.files.length ? i.files[0].name : 'Choose File';
}

function openDeleteModal() {
    document.getElementById('deleteAllModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteAllModal').style.display = 'none';
}

function submitDeleteAll() {
    const form = document.getElementById('deleteAllForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_all_customers';
    input.value = '1';
    form.appendChild(input);
    form.submit();
}

function onStatusChange() {
    const v = document.getElementById('statusFilter').value;
    window.location.href = `customer_list.php?status=${v}`;
}

function editCompany(span, pan) {
    if (currentlyEditing) return;

    const originalValue = span.dataset.original || '';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'company-input';
    input.value = originalValue;
    input.autocomplete = 'off';

    const savingIndicator = span.nextElementSibling;

    span.style.display = 'none';
    span.after(input);
    input.focus();
    currentlyEditing = input;

    // 🔁 AUTOSAVE ON TYPING (debounced)
    input.addEventListener('input', () => {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            saveCompany(input, pan, span, savingIndicator);
        }, 1000);
    });

    // ✅ Save immediately on blur
    input.addEventListener('blur', () => {
        clearTimeout(autosaveTimer);
        saveCompany(input, pan, span, savingIndicator);
    });

    // ✅ Save immediately on Enter
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(autosaveTimer);
            saveCompany(input, pan, span, savingIndicator);
        }
    });
}

function saveCompany(input, pan, span, indicator) {
    const newValue = input.value.trim();
    const oldValue = span.dataset.original || '';

    if (newValue === oldValue) {
        cleanup(input, span);
        return;
    }

    indicator.style.display = 'inline-block';
    indicator.innerText = 'saving...';
    indicator.style.color = '#2563eb';

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            ajax_action: 'update_company',
            pan: pan,
            company: newValue
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            span.innerText = newValue || '—';
            span.dataset.original = newValue;

            indicator.innerText = '✓ saved';
            indicator.style.color = '#10b981';

            // keep search/filter working
            const row = input.closest('tr');
            const idx = [...document.querySelectorAll('#customerTable tbody tr')].indexOf(row);
            if (idx >= 0 && customerRows[idx]) {
                customerRows[idx].company = newValue.toLowerCase();
            }
        } else {
            indicator.innerText = '✗ failed';
            indicator.style.color = '#ef4444';
        }

        setTimeout(() => {
            indicator.style.display = 'none';
            indicator.innerText = 'saving...'; // Reset for next time
        }, 2000);
    })
    .catch(() => {
        indicator.innerText = '✗ error';
        indicator.style.color = '#ef4444';
        setTimeout(() => {
            indicator.style.display = 'none';
            indicator.innerText = 'saving...'; // Reset for next time
        }, 2000);
    });

    cleanup(input, span);
}

function cleanup(input, span) {
    span.style.display = 'inline';
    input.remove();
    currentlyEditing = null;
}
</script>
    </body>
    </html>
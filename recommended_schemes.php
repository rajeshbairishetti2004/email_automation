<?php
// recommended_schemes.php
// Handles both display and AJAX saving for recommended schemes
// Can be included in view_report.php or called directly for AJAX

// ==============================================
// 1. AJAX HANDLING (when called directly)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once 'db_config.php';
    require_once 'auth.php';

    header('Content-Type: application/json');

    try {
        requireAuth();

        $ajax_action = $_POST['ajax_action'];

        if ($ajax_action === 'save_recommended_schemes') {
            $clientId = (int)($_POST['client_id'] ?? 0);

            if ($clientId <= 0) {
                throw new Exception('Invalid client ID');
            }

            $schemeNames   = $_POST['new_scheme_name']   ?? [];
            $schemeAmounts = $_POST['new_scheme_amount'] ?? [];

            $pdo = getPdo();
            $pdo->beginTransaction();

            $delStmt = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
            $delStmt->execute([$clientId]);

            $insStmt = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");

            foreach ($schemeNames as $index => $name) {
                $name   = trim($name);
                $amount = trim($schemeAmounts[$index] ?? '');
                if ($name !== '') {
                    $insStmt->execute([$clientId, $name, $amount]);
                }
            }

            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => 'Schemes saved successfully']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            exit;
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Save schemes error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// 2. DISPLAY SECTION (when included in view_report.php)
// ==============================================
if (!isset($clientId)) {
    throw new Exception('clientId must be set before including recommended_schemes.php');
}

// If newSchemes is not passed, fetch it
if (!isset($newSchemes)) {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_new_schemes WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $newSchemes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch client's region (is_usa) so the search dropdown is filtered correctly
if (!isset($clientIsUsa)) {
    if (!isset($pdo)) { require_once 'db_config.php'; $pdo = getPdo(); }
    $regionStmt = $pdo->prepare("SELECT is_usa FROM clients WHERE id = ? LIMIT 1");
    $regionStmt->execute([$clientId]);
    $clientIsUsa = (int)($regionStmt->fetchColumn() ?? 0);
}

if (!isset($isLocked)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock  = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state']  ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked    = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}
?>

<style>
    /* --- Recommended Schemes Table Styles --- */
    .recommended-schemes-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        overflow: visible;
    }

    .recommended-schemes-table th,
    .recommended-schemes-table td {
        border: 1px solid #e0e0e0;
        padding: 8px 10px;
        text-align: center;
        font-size: 14px;
    }

    .recommended-schemes-table th {
        background: #f8fafc;
        font-weight: 600;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
    }

    .scheme-search-wrapper {
        position: relative;
        width: 100%;
    }

    .scheme-search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        z-index: 9999;
        max-height: 240px;
        overflow-y: auto;
        display: none;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.08);
        padding: 6px 0;
        animation: dropdownFade .15s ease-in-out;
    }

    .scheme-search-item:not(:last-child) {
        border-bottom: 1px solid #f1f5f9;
    }

    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .scheme-search-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        cursor: pointer;
        font-size: 14px;
        color: #1f2937;
        transition: all .15s ease;
        border-left: 3px solid transparent;
    }

    .scheme-search-item:hover {
        background: #f8fafc;
        border-left: 3px solid #0288D1;
        color: #0288D1;
    }

    .scheme-search-results {
        scrollbar-width: thin;
    }

    .scheme-search-results::-webkit-scrollbar { width: 6px; }
    .scheme-search-results::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }

    .recommended-scheme-input {
        width: 100%;
        padding: 9px 10px;
        font-size: 14px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        transition: border .15s ease, box-shadow .15s ease;
    }

    .recommended-scheme-input:focus {
        border-color: #0288D1;
        box-shadow: 0 0 0 2px rgba(2, 136, 209, 0.15);
        background: #fff;
    }

    .recommended-schemes-actions {
        margin: 10px 0;
        display: flex;
        gap: 10px;
    }

    .add-scheme-btn {
        background: #0288D1;
        color: white;
        border: none;
        padding: 9px 16px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all .15s ease;
    }

    .add-scheme-btn:hover {
        background: #0277bd;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
    }

    .delete-row-btn {
        background: #ef4444;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        transition: all .15s ease;
    }

    .delete-row-btn:hover {
        background: #dc2626;
        transform: scale(1.05);
    }

    .wf-btn.btn-add {
        background: #27ae60;
        color: #fff;
        border: none;
        border-radius: 5px;
        font-weight: 600;
        padding: 8px 16px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .wf-btn.btn-add:hover { background: #219150; }

    .wf-btn.btn-delete {
        background: #f39c12;
        color: #fff;
        border: none;
        border-radius: 5px;
        font-weight: 600;
        padding: 8px 16px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .scheme-search-item mark {
        background: #dbeafe;
        color: #1d4ed8;
        padding: 1px 4px;
        border-radius: 4px;
        font-weight: 600;
    }

    .wf-btn.btn-delete:hover { background: #c0392b; }

    .recommended-schemes-table td { position: relative; }
</style>

<div class="section-card" style="margin-top: 20px; margin-bottom: 20px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <h3 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px;">
        Recommended Schemes
        <?php if ($isLocked): ?>
            <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
        <?php endif; ?>
    </h3>
    <table class="table recommended-schemes-table" id="newSchemesTable" style="width: 100%; margin-bottom: 15px;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="padding: 10px;">Scheme Name</th>
                <th style="padding: 10px;">Amount (₹)</th>
                <th style="width: 50px;"></th>
            </tr>
        </thead>
        <tbody id="newSchemesBody">
            <?php if (!empty($newSchemes)): ?>
                <?php foreach ($newSchemes as $ns): ?>
                    <tr>
                        <td>
                            <div class="scheme-search-wrapper">
                                <input type="text"
                                    name="new_scheme_name[]"
                                    value="<?php echo htmlspecialchars($ns['scheme_name']); ?>"
                                    class="form-control scheme-input recommended-scheme-input scheme-search"
                                    placeholder="Search scheme..."
                                    autocomplete="off"
                                    oninput="debouncedSave()"
                                    <?php echo $isLocked ? 'readonly' : ''; ?>>
                                <div class="scheme-search-results"></div>
                            </div>
                        </td>
                        <td>
                            <input type="text"
                                name="new_scheme_amount[]"
                                value="<?php echo htmlspecialchars($ns['amount']); ?>"
                                class="form-control scheme-input recommended-scheme-input"
                                placeholder="Enter amount"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                <?php echo $isLocked ? 'readonly' : ''; ?>>
                        </td>
                        <td>
                            <button type="button"
                                onclick="removeRowAndSave(this)"
                                class="delete-row-btn"
                                <?php echo $isLocked ? 'disabled' : ''; ?>>
                                &times;
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <button type="button"
        onclick="addNewSchemeRow()"
        class="add-scheme-btn"
        <?php echo $isLocked ? 'disabled' : ''; ?>>
        + Add Scheme
    </button>
</div>

<script>
    const currentClientId            = <?php echo isset($client['id']) ? (int)$client['id'] : (isset($clientId) ? (int)$clientId : 0); ?>;
    const recommendedSchemesLocked   = <?php echo $isLocked ? 'true' : 'false'; ?>;
    // Pass client region so the search only returns matching schemes
    const CLIENT_IS_USA              = <?php echo (int)$clientIsUsa; ?>;

    function addNewSchemeRow() {
        if (recommendedSchemesLocked) return;

        const tbody = document.getElementById('newSchemesBody');
        const row = `
        <tr>
            <td>
                <div class="scheme-search-wrapper">
                    <input type="text"
                        name="new_scheme_name[]"
                        class="form-control scheme-input recommended-scheme-input scheme-search"
                        placeholder="Search scheme..."
                        autocomplete="off"
                        oninput="debouncedSave()"
                        onfocus="loadSchemes(this, '')">
                    <div class="scheme-search-results"></div>
                </div>
            </td>
            <td>
                <input type="text"
                    name="new_scheme_amount[]"
                    class="form-control scheme-input recommended-scheme-input"
                    placeholder="Enter amount"
                    oninput="debouncedSave()"
                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </td>
            <td>
                <button type="button" onclick="removeRowAndSave(this)" class="delete-row-btn">&times;</button>
            </td>
        </tr>`;
        tbody.insertAdjacentHTML('beforeend', row);
    }

    function removeRowAndSave(btn) {
        if (recommendedSchemesLocked) return;
        btn.closest('tr').remove();
        document.querySelectorAll('.scheme-search-results').forEach(e => e.style.display = 'none');
        saveSchemesNow();
    }

    let saveTimer;

    function debouncedSave() {
        if (recommendedSchemesLocked) return;

        const title = document.querySelector('.section-card h3');
        if (title && !title.innerHTML.includes('Saving')) {
            title.innerHTML = "Recommended Schemes <span style='color:orange; font-size:12px;'>(Saving...)</span>";
        }

        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveSchemesNow, 1000);
    }

    function loadSchemes(input, query = "") {
        const resultsBox = input.parentElement.querySelector('.scheme-search-results');
        resultsBox.style.display = "block";
        resultsBox.innerHTML = "<div style='padding:10px;font-size:13px;color:#777;'>Loading...</div>";

        // Pass is_usa so only the correct region's schemes are returned
        fetch('api_search_schemes.php?q=' + encodeURIComponent(query) + '&is_usa=' + CLIENT_IS_USA)
            .then(res => res.json())
            .then(data => {
                resultsBox.innerHTML = '';

                if (data.length === 0) {
                    resultsBox.style.display = 'none';
                    return;
                }

                data.forEach(row => {
                    const item = document.createElement('div');
                    item.className = 'scheme-search-item';

                    let schemeName = row.scheme_name;
                    if (query) {
                        const regex = new RegExp("(" + query.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + ")", "ig");
                        schemeName = schemeName.replace(regex, "<mark>$1</mark>");
                    }

                    item.innerHTML = `<span style="font-size:13px;opacity:.6;">📈</span><span>${schemeName}</span>`;

                    item.onclick = function() {
                        const existing = [...document.getElementsByName('new_scheme_name[]')]
                            .map(i => i.value.trim().toLowerCase());

                        if (existing.includes(row.scheme_name.toLowerCase())) {
                            alert("Scheme already added");
                            return;
                        }

                        input.value = row.scheme_name;
                        resultsBox.style.display = 'none';
                        debouncedSave();
                    };

                    resultsBox.appendChild(item);
                });

                resultsBox.style.display = 'block';
            });
    }

    function saveSchemesNow() {
        if (recommendedSchemesLocked) return;

        if (currentClientId <= 0) {
            console.error("Cannot save: Invalid Client ID");
            return;
        }

        const names   = document.getElementsByName('new_scheme_name[]');
        const amounts = document.getElementsByName('new_scheme_amount[]');

        const formData = new FormData();
        formData.append('ajax_action', 'save_recommended_schemes');
        formData.append('client_id', currentClientId);

        for (let i = 0; i < names.length; i++) {
            formData.append('new_scheme_name[]',   names[i].value);
            formData.append('new_scheme_amount[]', amounts[i].value);
        }

        fetch('recommended_schemes.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                const title = document.querySelector('.section-card h3');
                if (data.status === 'success') {
                    title.innerHTML = "Recommended Schemes <span style='color:green; font-size:12px;'>✓ Saved</span>";
                    setTimeout(() => { title.innerHTML = "Recommended Schemes"; }, 2000);
                } else {
                    title.innerHTML = "Recommended Schemes <span style='color:red; font-size:12px;'>⚠ Error</span>";
                    console.error('Server Error:', data.message);
                }
            })
            .catch(error => console.error('Network Error:', error));
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (!recommendedSchemesLocked) {
            document.querySelectorAll('.scheme-input').forEach(input => {
                input.addEventListener('input', debouncedSave);
            });
        }
    });

    // Search on input
    document.addEventListener('input', function(e) {
        if (!e.target.classList.contains('scheme-search')) return;
        const input = e.target;
        const query = input.value.trim();
        loadSchemes(input, query.length < 1 ? "" : query);
    });

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.scheme-search-results').forEach(box => {
            if (!box.parentElement.contains(e.target)) {
                box.style.display = 'none';
            }
        });
    });

    // Load all on focus
    document.addEventListener('focus', function(e) {
        if (!e.target.classList.contains('scheme-search')) return;
        loadSchemes(e.target, "");
    }, true);
</script>
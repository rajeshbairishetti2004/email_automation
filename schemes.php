<?php
// schemes.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'parsers.php';

requireAuth();
$pdo = getPdo();
$currentUser = getCurrentUser();


$uploadError = '';


// --- A. Handle XLSX File Upload ---
if (isset($_POST['import_schemes'])) {

    if ($_FILES['scheme_file']['name']) {

        $filename = $_FILES['scheme_file']['tmp_name'];
        $schemes = [];   // ✅ ALWAYS initialize

        try {
            $schemes = parse_scheme_xlsx($filename);
        } catch (Exception $e) {
            $uploadError = $e->getMessage();
        }

        // ✅ Only run insert if no error
        if (empty($uploadError) && !empty($schemes)) {

            foreach ($schemes as $schemeData) {

                $stmt = $pdo->prepare("
                    INSERT INTO master_schemes (scheme_name, category)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE category = VALUES(category)
                ");

                $stmt->execute([
                    $schemeData['name'],
                    $schemeData['category']
                ]);
            }
        }
    }
}

// --- 2. FETCH AND CATEGORIZE DATA ---
$allSchemes = [
    'recommended' => [],
    'observation' => [],
    'drop'        => []
];
$stmt = $pdo->query("SELECT category, id, scheme_name FROM master_schemes ORDER BY scheme_name ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($allSchemes[$row['category']])) {
        $allSchemes[$row['category']][] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheme Strategy Board | Finance Doctor</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text-main: #334155;
            --text-dark: #0f172a;
            --border: #e2e8f0;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        body {
            background-color: var(--bg);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }

        /* Top Navigation */
        .top-nav {
            padding: 20px 40px;
            display: flex;
            align-items: center;
            background: transparent;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: #64748b;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-back:hover {
            background: #475569;
        }

        .content-wrap {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 40px 60px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            color: var(--text-dark);
            margin: 0 0 8px;
        }

        /* Grid Layout */
        .scheme-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            align-items: start;
        }

        .scheme-col {
            background: var(--surface);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 24px;
            border-top: 6px solid var(--primary);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .scheme-col.drag-over {
            transform: scale(1.02);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            background: #f0f9ff;
        }

        .col-recommended {
            border-top-color: var(--success);
        }

        .col-observation {
            border-top-color: var(--warning);
        }

        .col-drop {
            border-top-color: var(--danger);
        }

        .scheme-col h3 {
            font-family: 'Poppins', sans-serif;
            margin: 0 0 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 19px;
            color: var(--text-dark);
        }

        /* Forms */
        .add-form {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .add-form input {
    flex: 1;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 16px; /* increase input text size */
    outline: none;
    background: #fcfcfc;
}

/* 🔥 Increase placeholder text size */
.add-form input::placeholder {
    font-size: 16px;
    color: #94a3b8; /* optional softer color */
}


        .add-form input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-add {
            background: var(--primary);
            color: white;
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .btn-add:hover {
            
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }

        /* ===== ENHANCED LIST STYLES ===== */
        .scheme-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 500px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
            min-height: 100px;
        }

        .scheme-list::-webkit-scrollbar {
            width: 6px;
        }

        .scheme-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .scheme-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .scheme-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .scheme-item {
            background: #ffffff;
            border-radius: 10px;
            margin-bottom: 8px;
            padding: 12px 14px;
            cursor: grab;
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .scheme-item:last-child {
            margin-bottom: 0;
        }

        .scheme-item:hover {
            border-color: #94a3b8;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }

        .scheme-item:active {
            cursor: grabbing;
            opacity: 0.8;
            transform: scale(0.99);
        }

        .scheme-item.dragging {
            opacity: 0.5;
            background: #e2e8f0;
            border-color: #94a3b8;
        }

        .display-mode {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .scheme-name {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            word-break: break-word;
            padding: 2px 0;
            flex: 1;
        }

        .scheme-count {
            margin-left: 6px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
        }


        .action-btns {
            display: flex;
            gap: 6px;
            opacity: 0.6;
            transition: opacity 0.2s;
        }

        .scheme-item:hover .action-btns {
            opacity: 1;
        }

        .btn-icon {
            cursor: pointer;
            border: none;
            background: #f8fafc;
            font-size: 14px;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
            color: #64748b;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
        }

        .btn-edit:hover {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        .btn-del:hover {
            background: #ef4444;
            color: white;
            border-color: #ef4444;
        }

        .edit-mode {
            display: none;
            gap: 8px;
            width: 100%;
        }

        .edit-mode input {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #2563eb;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
        }

        .edit-mode input:focus {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-save {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }

        .btn-cancel {
            background: #64748b;
            color: white;
            border-color: #64748b;
        }

        .empty-msg {
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            font-style: italic;
            padding: 30px 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px dashed #e2e8f0;
        }

        /* 🔥 Modal Input + Dropdown Styling */
#modalSchemeName,
#modalCategory {
    width: 100%;
    height: 48px;                 /* same height */
    padding: 0 14px;              /* horizontal padding only */
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    font-size: 16px;              /* bigger text */
    margin-bottom: 18px;
    box-sizing: border-box;
}

/* Placeholder styling */
#modalSchemeName::placeholder {
    font-size: 16px;
    color: #94a3b8;
}

/* Focus effect */
#modalSchemeName:focus,
#modalCategory:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    outline: none;
}


        /* Dropdown results (unchanged) */


        .search-result-item {
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        @media (max-width: 1024px) {
            .scheme-grid {
                grid-template-columns: 1fr;
            }

            .content-wrap {
                padding: 0 20px;
            }

            .top-nav {
                padding: 20px;
            }

            .action-btns {
                opacity: 1;
            }
        }
    </style>
</head>

<body>

    <nav class="top-nav">
        <a href="upload.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </nav>

    <div class="content-wrap">
        <div class="page-header">
            <h1>Scheme Strategy Board</h1>
            <p>Categorize and manage fund recommendations for client reports. <strong>Drag & Drop</strong> schemes between columns.</p>
        </div>
        <?php if (!empty($uploadError)): ?>
            <div style="
        background:#fef2f2;
        border:1px solid #fecaca;
        color:#dc2626;
        padding:14px 18px;
        border-radius:12px;
        margin-bottom:25px;
        font-weight:500;
    ">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($uploadError) ?>
            </div>
        <?php endif; ?>


        <!-- XLSX Upload Form -->
        <div style="display:flex; gap:15px; align-items:center; margin-bottom:32px;">

            <form action="" method="post" enctype="multipart/form-data" class="add-form" style="margin:0;">
                <input type="file" name="scheme_file" accept=".xlsx" required
                    style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:#fff; font-size:14px;">
                <button type="submit" name="import_schemes"
                    class="btn-add"
                    style="width:auto; min-width:140px; background:var(--primary); font-size:14px; padding:0 18px; border-radius:8px;">
                    Upload Schemes
                </button>
            </form>

            <!-- 🔥 NEW BUTTON -->
            <button onclick="openAddModal()"
                style="padding:10px 20px; border:none; border-radius:8px; background:#22c55e; color:white; font-weight:600; cursor:pointer;" onmouseover="this.style.background='#16a34a'" onmouseout="this.style.background='#22c55e'">
                + Add Scheme
            </button>

        </div>


        <div class="scheme-grid">
            <?php
            $config = [
                'recommended' => ['title' => 'Recommended', 'icon' => 'circle-check', 'color' => 'success', 'class' => 'col-recommended'],
                'observation' => ['title' => 'Observation', 'icon' => 'eye', 'color' => 'warning', 'class' => 'col-observation'],
                'drop'        => ['title' => 'Exit / Drop', 'icon' => 'circle-xmark', 'color' => 'danger', 'class' => 'col-drop']
            ];
            foreach ($config as $key => $sec): ?>
                <div class="scheme-col <?= $sec['class'] ?>" data-category="<?= $key ?>" ondrop="dropHandler(event)" ondragover="dragOverHandler(event)" ondragleave="dragLeaveHandler(event)">
                    <h3>
                        <i class="fa-solid fa-<?= $sec['icon'] ?>" style="color:var(--<?= $sec['color'] ?>)"></i>
                        <?= $sec['title'] ?>
                        <span class="scheme-count" id="count-<?= $key ?>">
                            (<?= count($allSchemes[$key]) ?>)
                        </span>
                    </h3>

                    <div class="add-form" style="margin-bottom:18px; position:relative;">
                        <input type="text" placeholder="Enter Scheme name to search..." onkeyup="handleSearch(this)" autocomplete="off">
                        <button type="button"
                            class="btn-add"
                            style="background:var(--<?= $sec['color'] ?>);"
                            onclick="addSchemeFromInput(this)">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <ul class="scheme-list">
                        <?php if (empty($allSchemes[$key])): ?>
                            <li class="empty-msg">No schemes in this list yet.</li>
                        <?php else: ?>
                            <?php foreach ($allSchemes[$key] as $scheme): ?>
                                <li class="scheme-item" id="item-<?= $scheme['id'] ?>" draggable="true" ondragstart="dragStartHandler(event)" ondragend="dragEndHandler(event)" data-id="<?= $scheme['id'] ?>" data-name="<?= htmlspecialchars($scheme['scheme_name']) ?>" data-category="<?= $key ?>">
                                    <div class="display-mode">
                                        <span class="scheme-name"><?= htmlspecialchars($scheme['scheme_name']) ?></span>
                                        <div class="action-btns">
                                            <button class="btn-icon btn-edit" onclick="toggleEdit(<?= $scheme['id'] ?>, true)" title="Edit Name"><i class="fa-solid fa-pen-to-square"></i></button>
                                            <button class="btn-icon btn-del" onclick="deleteScheme(<?= $scheme['id'] ?>)" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                        </div>
                                    </div>
                                    <form class="edit-mode" style="display:none;" onsubmit="return false;">
                                        <input type="text" value="<?= htmlspecialchars($scheme['scheme_name']) ?>" required>
                                        <button class="btn-icon btn-save" title="Save Changes"><i class="fa-solid fa-check"></i></button>
                                        <button type="button" class="btn-icon btn-cancel" onclick="toggleEdit(<?= $scheme['id'] ?>, false)" title="Cancel"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- ADD SCHEME MODAL -->
<div id="addSchemeModal" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.4);
    justify-content:center;
    align-items:center;
    z-index:1000;
">

    <div style="
        background:white;
        width:400px;
        padding:30px;
        border-radius:16px;
        box-shadow:0 10px 40px rgba(0,0,0,0.15);
    ">

        <h3 style="margin-bottom:20px;">Add New Scheme</h3>

        <input type="text" id="modalSchemeName"
            placeholder="Enter Scheme Name"
            >

        <select id="modalCategory"
            >

            <option value="recommended">Recommended</option>
            <option value="observation">Observation</option>
            <option value="drop">Exit / Drop</option>

        </select>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button onclick="closeAddModal()"
                style="padding:8px 14px;cursor:pointer; border:none; border-radius:6px; background:#64748b; color:white;"onmouseover="this.style.background='#7e848b'"onmouseout="this.style.background='#64748b'">
                Cancel
            </button>

            <button onclick="submitModalScheme()"
                style="padding:8px 14px; cursor:pointer; border:none; border-radius:6px; background:#2563eb; color:white;" onmouseover="this.style.background='#0c48ca'"onmouseout="this.style.background='#2563eb'">
                
                Submit
            </button>
        </div>

    </div>
</div>


    <script>
        // Drag and Drop Variables

        function openAddModal() {
    document.getElementById('addSchemeModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addSchemeModal').style.display = 'none';
}

function submitModalScheme() {

    const name = document.getElementById('modalSchemeName').value.trim();
    const category = document.getElementById('modalCategory').value;

    if (!name) {
        alert("Please enter scheme name");
        return;
    }

    let formData = new FormData();
    formData.append('add_to_board', true);
    formData.append('name', name);
    formData.append('cat', category);

    fetch('api_manage_schemes.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(response => {

        if (response === 'success') {

            closeAddModal();

            document.getElementById('modalSchemeName').value = '';

            // 🔥 Refresh only selected category
            refreshCategory(category);

        } else {
            alert(response);
        }
    });
}
document.getElementById('addSchemeModal').addEventListener('click', function(e) {
    if (e.target.id === 'addSchemeModal') {
        closeAddModal();
    }
});
function openAddModal() {
    const modal = document.getElementById('addSchemeModal');
    modal.style.display = 'flex';
    document.getElementById('modalSchemeName').focus();
}
document.getElementById('modalSchemeName')
.addEventListener('keypress', function(e){
    if(e.key === 'Enter'){
        submitModalScheme();
    }
});


        let draggedItem = null;

        // Drag Handlers
        function dragStartHandler(event) {
            draggedItem = event.target.closest('.scheme-item');
            if (!draggedItem) return;

            // Store data for drag
            event.dataTransfer.setData('text/plain', JSON.stringify({
                id: draggedItem.dataset.id,
                name: draggedItem.dataset.name,
                category: draggedItem.dataset.category
            }));

            draggedItem.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
        }

        function dragEndHandler(event) {
            const item = event.target.closest('.scheme-item');
            if (item) {
                item.classList.remove('dragging');
            }

            // Remove drag-over effect from all columns
            document.querySelectorAll('.scheme-col').forEach(col => {
                col.classList.remove('drag-over');
            });

            draggedItem = null;
        }

        function dragOverHandler(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            event.currentTarget.classList.add('drag-over');
        }

        function dragLeaveHandler(event) {
            event.currentTarget.classList.remove('drag-over');
        }

        function addSchemeFromInput(button) {

            const column = button.closest('.scheme-col');
            const input = column.querySelector('input[type="text"]');
            const category = column.dataset.category;
            const schemeName = input.value.trim();

            if (!schemeName) {
                alert("Please enter scheme name");
                return;
            }

            let formData = new FormData();
            formData.append('add_to_board', true);
            formData.append('name', schemeName);
            formData.append('cat', category);

            fetch('api_manage_schemes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.text())
                .then(response => {

                    if (response === 'success') {

                        input.value = "";

                        // 🔥 THIS IS THE IMPORTANT PART
                        refreshCategory(category);

                    } else {
                        alert(response);
                    }
                });
        }

        function dropHandler(event) {
            event.preventDefault();
            const targetCol = event.currentTarget;
            targetCol.classList.remove('drag-over');

            // Get target category
            const targetCategory = targetCol.dataset.category;

            // Get dragged data
            let dragData;
            try {
                dragData = JSON.parse(event.dataTransfer.getData('text/plain'));
            } catch (e) {
                console.error('Invalid drag data');
                return;
            }

            // If same category, do nothing
            if (dragData.category === targetCategory) {
                return;
            }

            // Move the scheme via AJAX
            moveScheme(dragData.id, dragData.name, targetCategory);
        }

        // Function to move scheme between categories
        function moveScheme(schemeId, schemeName, targetCategory) {
            let formData = new FormData();
            formData.append('move_scheme', true);
            formData.append('id', schemeId);
            formData.append('name', schemeName);
            formData.append('target_category', targetCategory);

            fetch('api_manage_schemes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async res => {
                    let text = await res.text();
                    if (res.ok && text === 'success') {

                        const draggedElement = document.getElementById(`item-${schemeId}`);
                        const targetColumn = document.querySelector(`.scheme-col[data-category="${targetCategory}"] .scheme-list`);

                        draggedElement.dataset.category = targetCategory;
                        targetColumn.appendChild(draggedElement);

                        updateCounts();
                    } else if (res.status === 409) {
                        showErrorAlert(text);
                    } else {
                        showErrorAlert("An unexpected error occurred while moving the scheme.");
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showErrorAlert("Network error occurred. Please try again.");
                });
        }

        function updateCounts() {
            document.querySelectorAll('.scheme-col').forEach(col => {
                const category = col.dataset.category;
                const count = col.querySelectorAll('.scheme-item').length;
                const countElement = document.getElementById(`count-${category}`);
                if (countElement) {
                    countElement.innerText = `(${count})`;
                }
            });
        }


        // Live search for schemes
        function handleSearch(input) {

            const filter = input.value.toLowerCase().trim();
            const column = input.closest('.scheme-col');
            const list = column.querySelector('.scheme-list');
            const items = list.querySelectorAll('.scheme-item');

            let visibleCount = 0;

            items.forEach(item => {
                const name = item.querySelector('.scheme-name').innerText.toLowerCase();

                // ✅ Match from starting letter only
                if (filter === '' || name.startsWith(filter)) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            // 🔥 Remove existing search message if any
            let oldMsg = list.querySelector('.search-empty-msg');
            if (oldMsg) oldMsg.remove();

            // ✅ If search text exists AND no matches → show message
            if (filter !== '' && visibleCount === 0) {

                const msg = document.createElement('li');
                msg.className = 'search-empty-msg';
                msg.style.cssText = `
            text-align:center;
            font-size:13px;
            color:#94a3b8;
            padding:20px;
            font-style:italic;
        `;

                msg.innerHTML = `
            Searched scheme is not available.<br>
            Click <strong>+</strong> to add.
        `;

                list.appendChild(msg);
            }

            updateCounts();
        }

        // Show error alert
        function showErrorAlert(message) {
            let oldAlert = document.getElementById('error-alert');
            if (oldAlert) oldAlert.remove();

            let alertDiv = document.createElement('div');
            alertDiv.id = 'error-alert';
            alertDiv.style = "background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 15px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; font-size: 14px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);";
            alertDiv.innerHTML = `<span><i class="fa-solid fa-circle-exclamation" style="margin-right: 10px;"></i> ${message}</span>
            <button onclick="this.parentElement.remove()" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 20px; line-height: 1;">&times;</button>`;
            let contentWrap = document.querySelector('.content-wrap');
            contentWrap.insertBefore(alertDiv, contentWrap.firstChild);
        }

        // Add scheme to master_schemes (from dropdown)
        function selectScheme(name, category) {
            let formData = new FormData();
            formData.append('add_to_board', true);
            formData.append('name', name);
            formData.append('cat', category);
            fetch('api_manage_schemes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async res => {
                    let text = await res.text();
                    if (res.ok && text === 'success') {
                        refreshCategory(category);
                    } else if (res.status === 409) {
                        showErrorAlert(text);
                    } else {
                        showErrorAlert("An unexpected error occurred. Please try again.");
                    }
                });
        }

        function refreshCategory(category) {

            let formData = new FormData();
            formData.append('get_category', true);
            formData.append('category', category);

            fetch('api_manage_schemes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {

                    const list = document.querySelector(
                        `.scheme-col[data-category="${category}"] .scheme-list`
                    );

                    list.innerHTML = '';

                    if (data.length === 0) {
                        list.innerHTML = '<li class="empty-msg">No schemes in this list yet.</li>';
                    } else {
                        data.forEach(scheme => {

                            const li = document.createElement('li');
                            li.className = 'scheme-item';
                            li.id = `item-${scheme.id}`;
                            li.draggable = true;
                            li.dataset.id = scheme.id;
                            li.dataset.name = scheme.scheme_name;
                            li.dataset.category = category;

                            li.innerHTML = `
                    <div class="display-mode">
                        <span class="scheme-name">${scheme.scheme_name}</span>
                        <div class="action-btns">
                            <button class="btn-icon btn-edit" onclick="toggleEdit(${scheme.id}, true)">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button class="btn-icon btn-del" onclick="deleteScheme(${scheme.id})">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                `;

                            list.appendChild(li);
                        });
                    }

                    updateCounts();
                });
        }

        // Delete scheme from master_schemes
        function deleteScheme(id) {
            if (!confirm('Are you sure you want to delete this scheme?')) return;
            let formData = new FormData();
            formData.append('delete_scheme', true);
            formData.append('id', id);
            fetch('api_manage_schemes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(() => location.reload());
        }

        // Edit mode toggle (you'll need to implement save functionality)
        function toggleEdit(id, show) {
            const item = document.getElementById(`item-${id}`);
            if (!item) return;

            const displayMode = item.querySelector('.display-mode');
            const editMode = item.querySelector('.edit-mode');

            if (show) {
                displayMode.style.display = 'none';
                editMode.style.display = 'flex';
                // Make item non-draggable while editing
                item.draggable = false;
            } else {
                displayMode.style.display = 'flex';
                editMode.style.display = 'none';
                // Restore draggable
                item.draggable = true;
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.add-form')) {
                document.querySelectorAll('.search-results-dropdown').forEach(d => {
                    d.style.display = 'none';
                });
            }
        });

        document.addEventListener('click', function(e) {
            if (e.target.closest('.btn-save')) {
                const item = e.target.closest('.scheme-item');
                const id = item.dataset.id;
                const input = item.querySelector('.edit-mode input');
                const newName = input.value.trim();

                if (!newName) {
                    alert("Scheme name cannot be empty");
                    return;
                }

                let formData = new FormData();
                formData.append('update_scheme', true);
                formData.append('id', id);
                formData.append('name', newName);

                fetch('api_manage_schemes.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.text())
                    .then(response => {
                        if (response === 'success') {
                            item.querySelector('.scheme-name').innerText = newName;
                            item.dataset.name = newName;
                            toggleEdit(id, false);

                        } else {
                            alert(response);
                        }
                    });
            }
        });
    </script>
</body>

</html>
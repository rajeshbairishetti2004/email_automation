<?php
// schemes.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'parsers.php';

requireAuth();
$pdo = getPdo();
$currentUser = getCurrentUser();

// --- A. Handle XLSX File Upload ---
if (isset($_POST['import_schemes'])) {
    if ($_FILES['scheme_file']['name']) {
        $filename = $_FILES['scheme_file']['tmp_name'];
        $schemeNames = parse_scheme_xlsx($filename);
        foreach ($schemeNames as $scheme) {
            $scheme = trim($scheme);
            if ($scheme) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO schemes (scheme_name) VALUES (?)");
                $stmt->execute([$scheme]);
            }
        }
    }
}

// --- B. Handle AJAX Live Search ---
if (isset($_GET['search_query'])) {
    $search = trim($_GET['search_query']);
    $cat = trim($_GET['category']);
    $stmt = $pdo->prepare("SELECT scheme_name FROM schemes WHERE scheme_name LIKE ? ORDER BY scheme_name ASC LIMIT 20");
    $stmt->execute(["%$search%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($results) {
        foreach ($results as $row) {
            $name = htmlspecialchars($row['scheme_name']);
            echo "<div class='search-result-item' onclick=\"selectScheme('{$name}', '{$cat}')\"><span>{$name}</span><i class='fas fa-plus-circle text-success'></i></div>";
        }
    } else {
        echo "<div class='p-2 text-muted'>No schemes found</div>";
    }
    exit;
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

        .btn-back:hover { background: #475569; }

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

        .col-recommended { border-top-color: var(--success); }
        .col-observation { border-top-color: var(--warning); }
        .col-drop { border-top-color: var(--danger); }

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
            font-size: 14px;
            outline: none;
            background: #fcfcfc;
        }

        .add-form input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

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

        /* Scheme List Items */
        .scheme-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 500px;
            overflow-y: auto;
            scrollbar-width: thin;
            min-height: 100px;
        }

        .scheme-item {
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
            cursor: grab;
            transition: background 0.2s, opacity 0.2s;
        }

        .scheme-item:active {
            cursor: grabbing;
            opacity: 0.7;
        }

        .scheme-item.dragging {
            opacity: 0.5;
            background: #e2e8f0;
        }

        .scheme-item:last-child { border-bottom: none; }

        .display-mode {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .scheme-name {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-dark);
            word-break: break-word;
            padding-right: 10px;
            pointer-events: none; /* Allow drag to work through text */
        }

        .edit-mode {
            display: none;
            gap: 10px;
            width: 100%;
        }

        .edit-mode input {
            flex: 1;
            padding: 8px 10px;
            border: 2px solid var(--primary);
            border-radius: 6px;
            font-size: 13px;
        }

        /* Icons & Buttons */
        .action-btns { 
            display: flex; 
            gap: 8px;
            pointer-events: auto; /* Ensure buttons remain clickable */
        }

        .btn-icon {
            cursor: pointer;
            border: none;
            background: none;
            font-size: 15px;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
            color: #94a3b8;
        }

        .btn-edit:hover { color: var(--primary); background: #eff6ff; }
        .btn-del:hover { color: var(--danger); background: #fef2f2; }
        .btn-save { color: var(--success); }
        .btn-cancel { color: #94a3b8; }

        .empty-msg {
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            font-style: italic;
            padding: 20px 0;
        }

        /* Dropdown results */
        .search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            z-index: 1050;
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .search-result-item {
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .search-result-item:hover { background-color: #f8f9fa; }

        @media (max-width: 1024px) {
            .scheme-grid { grid-template-columns: 1fr; }
            .content-wrap { padding: 0 20px; }
            .top-nav { padding: 20px; }
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

        <!-- XLSX Upload Form -->
        <form action="" method="post" enctype="multipart/form-data" class="add-form" style="margin-bottom: 32px;">
            <input type="file" name="scheme_file" accept=".xlsx" required style="flex:unset; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:#fff; font-size:14px;">
            <button type="submit" name="import_schemes" class="btn-add" style="width:auto; min-width:140px; background:var(--primary); font-size:14px; padding:0 18px; border-radius:8px;">Upload Schemes</button>
        </form>

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
                </h3>
                <div class="add-form" style="margin-bottom:18px; position:relative;">
                    <input type="text" placeholder="Search or enter fund name..." onkeyup="handleSearch(this, '<?= $key ?>')" autocomplete="off">
                    <button type="button" class="btn-add" style="background:var(--<?= $sec['color'] ?>); pointer-events:none; opacity:0.7;"><i class="fa-solid fa-plus"></i></button>
                    <div class="search-results-dropdown shadow-sm border" style="display:none; position:absolute; width:100%;"></div>
                </div>
                <ul class="scheme-list">
                <?php if (empty($allSchemes[$key])): ?>
                    <li class="empty-msg">No schemes in this list yet.</li>
                <?php else: ?>
                    <?php foreach($allSchemes[$key] as $scheme): ?>
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

    <script>
    // Drag and Drop Variables
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
        
        fetch('api_manage_schemes.php', { method: 'POST', body: formData })
            .then(async res => {
                let text = await res.text();
                if (res.ok && text === 'success') {
                    location.reload(); // Reload to show updated categories
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

    // Live search for schemes
    function handleSearch(input, category) {
        let query = input.value;
        let dropdown = input.parentElement.querySelector('.search-results-dropdown');
        if (query.length < 1) {
            dropdown.style.display = 'none';
            return;
        }
        fetch(`schemes.php?search_query=${encodeURIComponent(query)}&category=${encodeURIComponent(category)}`)
            .then(res => res.text())
            .then(data => {
                dropdown.innerHTML = data;
                dropdown.style.display = 'block';
            });
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
        fetch('api_manage_schemes.php', { method: 'POST', body: formData })
            .then(async res => {
                let text = await res.text();
                if (res.ok && text === 'success') {
                    location.reload();
                } else if (res.status === 409) {
                    showErrorAlert(text);
                } else {
                    showErrorAlert("An unexpected error occurred. Please try again.");
                }
            });
    }

    // Delete scheme from master_schemes
    function deleteScheme(id) {
        if (!confirm('Are you sure you want to delete this scheme?')) return;
        let formData = new FormData();
        formData.append('delete_scheme', true);
        formData.append('id', id);
        fetch('api_manage_schemes.php', { method: 'POST', body: formData })
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
    </script>
</body>
</html>
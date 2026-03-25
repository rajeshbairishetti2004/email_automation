<?php
// template_management.php
// Admin-only template management page

require_once 'auth.php';
require_once 'db_config.php';

requireAuth();

$pdo = getPdo();
$currentUser = getCurrentUser();

// --- ADMIN GUARD ---
$userDesignation = strtolower($currentUser['designation'] ?? '');
$isAdmin = ($userDesignation === 'admin');

if (!$isAdmin) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied</title>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'DM Sans', sans-serif; background: #f0f7ff; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
            .box { background: #fff; border-radius: 16px; padding: 56px 64px; text-align: center; box-shadow: 0 8px 40px rgba(2,136,209,0.10); border-top: 4px solid #dc3545; }
            .box h2 { color: #dc3545; font-size: 22px; margin: 16px 0 10px; }
            .box p { color: #666; font-size: 14px; margin-bottom: 24px; }
            .box a { background: #0288D1; color: #fff; padding: 11px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class="box">
            <div style="font-size:48px">🔒</div>
            <h2>Access Denied</h2>
            <p>This page is restricted to administrators only.</p>
            <a href="view_saved_reports.php">← Go Back</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- ALLOWED SECTIONS ---
$allowedSections = ['greeting', 'intro', 'closing', 'rationale'];

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_start();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'] ?? '';

    try {
        if ($action === 'add_template') {
            $section   = $_POST['section_type'] ?? '';
            $name      = trim($_POST['template_name'] ?? '');
            $content   = $_POST['template_content'] ?? '';
            $isDefault = isset($_POST['is_default']) ? (int)$_POST['is_default'] : 0;

            if (!in_array($section, $allowedSections)) throw new Exception('Invalid section.');
            if ($name === '') throw new Exception('Template name is required.');
            if (trim(strip_tags($content)) === '') throw new Exception('Template content cannot be empty.');

            if ($isDefault) {
                $pdo->prepare("UPDATE report_templates SET is_default = 0 WHERE section_type = ?")->execute([$section]);
            }
            $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content, is_default) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $section, $content, $isDefault]);
            $response = ['success' => true, 'new_id' => (int)$pdo->lastInsertId(), 'message' => 'Template added successfully.'];
        }
        elseif ($action === 'edit_template') {
            $id        = (int)($_POST['template_id'] ?? 0);
            $name      = trim($_POST['template_name'] ?? '');
            $content   = $_POST['template_content'] ?? '';
            $isDefault = isset($_POST['is_default']) ? (int)$_POST['is_default'] : 0;

            if ($id <= 0) throw new Exception('Invalid template ID.');
            if ($name === '') throw new Exception('Template name is required.');
            if (trim(strip_tags($content)) === '') throw new Exception('Template content cannot be empty.');

            $sectionRow = $pdo->prepare("SELECT section_type FROM report_templates WHERE id = ?");
            $sectionRow->execute([$id]);
            $section = $sectionRow->fetchColumn();
            if (!$section) throw new Exception('Template not found.');

            if ($isDefault) {
                $pdo->prepare("UPDATE report_templates SET is_default = 0 WHERE section_type = ?")->execute([$section]);
            }
            $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ?, is_default = ? WHERE id = ?");
            $stmt->execute([$name, $content, $isDefault, $id]);
            $response = ['success' => true, 'message' => 'Template updated successfully.'];
        }
        elseif ($action === 'delete_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid template ID.');
            $pdo->prepare("DELETE FROM report_templates WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Template deleted.'];
        }
        elseif ($action === 'set_default') {
            $id = (int)($_POST['template_id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid template ID.');
            $sectionRow = $pdo->prepare("SELECT section_type FROM report_templates WHERE id = ?");
            $sectionRow->execute([$id]);
            $section = $sectionRow->fetchColumn();
            if (!$section) throw new Exception('Template not found.');
            $pdo->prepare("UPDATE report_templates SET is_default = 0 WHERE section_type = ?")->execute([$section]);
            $pdo->prepare("UPDATE report_templates SET is_default = 1 WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Default template updated.'];
        }
        elseif ($action === 'load_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid template ID.');
            $stmt = $pdo->prepare("SELECT id, name, section_type, content, is_default FROM report_templates WHERE id = ?");
            $stmt->execute([$id]);
            $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) throw new Exception('Template not found.');
            $response = ['success' => true, 'template' => $tpl];
        }
        else {
            throw new Exception('Unknown action.');
        }
    } catch (Throwable $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
    }

    ob_end_clean();
    echo json_encode($response);
    exit;
}

// --- LOAD ALL TEMPLATES ---
$allTemplates = [];
foreach ($allowedSections as $sec) {
    $stmt = $pdo->prepare("SELECT id, name, section_type, content, is_default, created_at FROM report_templates WHERE section_type = ? ORDER BY is_default DESC, created_at DESC");
    $stmt->execute([$sec]);
    $allTemplates[$sec] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sectionMeta = [
    'greeting'  => ['label' => 'Greeting',      'icon' => '👋'],
    'intro'     => ['label' => 'Introduction',   'icon' => '📝'],
    'closing'   => ['label' => 'Closing',        'icon' => '✉️'],
    'rationale' => ['label' => 'Rationale',      'icon' => '💡'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template Management — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/navbar.css">
    <style>
        :root {
            --blue-900: #083744;
            --blue-700: #0a5470;
            --blue-500: #0288D1;
            --blue-400: #29a5e8;
            --blue-200: #b3d8f5;
            --blue-100: #dbeefb;
            --blue-50:  #f0f7ff;
            --blue-25:  #f8fbff;
            --text-primary: #0d2d3a;
            --text-secondary: #4a7a92;
            --text-muted: #8bb3c5;
            --border: #e2eef8;
            --border-light: #eef6fc;
            --gold: #b45309;
            --gold-bg: #fffbeb;
            --gold-border: #fde68a;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 3px rgba(2,136,209,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 12px rgba(2,136,209,0.08), 0 1px 4px rgba(0,0,0,0.04);
            --shadow-lg: 0 8px 28px rgba(2,136,209,0.12), 0 2px 8px rgba(0,0,0,0.06);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--blue-50);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 28px 80px;
        }

        .tabs-wrapper { margin-bottom: 24px; }

        .tabs {
            display: inline-flex;
            gap: 4px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 5px;
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 20px;
            border-radius: var(--radius-md);
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-family: 'DM Sans', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
            position: relative;
        }

        .tab-btn:hover:not(.active) {
            background: var(--blue-50);
            color: var(--text-primary);
        }

        .tab-btn.active {
            background: var(--blue-500);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(2,136,209,0.35);
        }

        .tab-count {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            background: rgba(0,0,0,0.08);
            color: inherit;
            min-width: 22px;
            text-align: center;
        }

        .tab-btn.active .tab-count {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 22px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .content-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            position: sticky;
            top: 24px;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #fff 0%, var(--blue-25) 100%);
        }

        .card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title-count {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-left: 2px;
        }

        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--blue-500);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.15s;
            box-shadow: 0 2px 6px rgba(2,136,209,0.25);
        }
        .btn-add:hover {
            background: #0277bd;
            box-shadow: 0 4px 12px rgba(2,136,209,0.35);
            transform: translateY(-1px);
        }
        .btn-add:active { transform: translateY(0); }

        .tpl-list {
            list-style: none;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .tpl-list::-webkit-scrollbar { width: 5px; }
        .tpl-list::-webkit-scrollbar-track { background: transparent; }
        .tpl-list::-webkit-scrollbar-thumb { background: var(--blue-200); border-radius: 99px; }
        .tpl-list::-webkit-scrollbar-thumb:hover { background: var(--blue-500); }

        .tpl-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 20px;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
            transition: background 0.12s;
            position: relative;
        }

        .tpl-item:last-child { border-bottom: none; }
        .tpl-item:hover { background: var(--blue-25); }
        .tpl-item.active { background: #eef7ff; }

        .tpl-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--blue-500);
            border-radius: 0 2px 2px 0;
        }

        .tpl-icon-box {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            background: var(--blue-50);
            border: 1px solid var(--border-light);
        }

        .tpl-item.active .tpl-icon-box {
            background: #d4edfa;
            border-color: var(--blue-200);
        }

        .tpl-info { flex: 1; min-width: 0; }

        .tpl-name {
            font-weight: 600;
            font-size: 13.5px;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }

        .tpl-item.active .tpl-name { color: var(--blue-500); }

        .tpl-date {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .badge-default {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 99px;
            background: var(--gold-bg);
            color: var(--gold);
            border: 1px solid var(--gold-border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        .tpl-btns {
            display: flex;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.15s;
            flex-shrink: 0;
        }

        .tpl-item:hover .tpl-btns { opacity: 1; }

        .tpl-btn {
            width: 30px;
            height: 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: all 0.12s;
        }

        .tpl-btn:hover { background: var(--blue-50); color: var(--blue-500); border-color: var(--blue-200); }
        .tpl-btn.del:hover { background: #fef2f2; color: #dc3545; border-color: #fca5a5; }
        .tpl-btn.star:hover { background: var(--gold-bg); color: var(--gold); border-color: var(--gold-border); }

        .empty-state {
            padding: 56px 24px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-state .e-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.6; }
        .empty-state p { font-size: 13px; line-height: 1.7; }
        .empty-state strong { color: var(--text-secondary); }

        .editor-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 120px);
            position: sticky;
            top: 24px;
        }

        .editor-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            background: linear-gradient(180deg, #fff 0%, var(--blue-25) 100%);
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-shrink: 0;
        }

        .editor-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.1px;
        }

        .editor-card-sub {
            font-size: 12px;
            color: var(--text-muted);
        }

        .editor-body {
            padding: 20px;
            overflow-y: auto;
            overscroll-behavior: contain;
            flex: 1;
        }

        .editor-body::-webkit-scrollbar { width: 5px; }
        .editor-body::-webkit-scrollbar-track { background: transparent; }
        .editor-body::-webkit-scrollbar-thumb { background: var(--blue-200); border-radius: 99px; }
        .editor-body::-webkit-scrollbar-thumb:hover { background: var(--blue-500); }

        .form-group { margin-bottom: 18px; }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            background: var(--blue-25);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: 'DM Sans', sans-serif;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }

        .form-input::placeholder { color: var(--text-muted); }

        .form-input:focus {
            border-color: var(--blue-500);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(2,136,209,0.10);
        }

        .quill-wrap {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: box-shadow 0.15s, border-color 0.15s;
        }

        .quill-wrap:focus-within {
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(2,136,209,0.10);
        }

        .quill-wrap .ql-toolbar.ql-snow {
            border: none;
            border-bottom: 1px solid var(--border-light);
            background: var(--blue-25);
            padding: 8px 12px;
        }

        .quill-wrap .ql-container.ql-snow {
            border: none;
            background: #fff;
        }

        .quill-wrap .ql-editor {
            min-height: 260px;
            max-height: 460px;
            overflow-y: auto;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text-primary);
            line-height: 1.7;
            padding: 14px 16px;
        }

        .quill-wrap .ql-editor.ql-blank::before {
            color: var(--text-muted);
            font-style: normal;
            font-size: 13px;
        }

        .quill-wrap .ql-toolbar .ql-stroke { stroke: var(--text-secondary); }
        .quill-wrap .ql-toolbar .ql-fill   { fill:   var(--text-secondary); }
        .quill-wrap .ql-toolbar .ql-picker-label { color: var(--text-secondary); }
        .quill-wrap .ql-toolbar button:hover .ql-stroke { stroke: var(--blue-500); }
        .quill-wrap .ql-toolbar button:hover .ql-fill   { fill:   var(--blue-500); }
        .quill-wrap .ql-toolbar .ql-active  .ql-stroke  { stroke: var(--blue-500); }
        .quill-wrap .ql-toolbar .ql-active  .ql-fill    { fill:   var(--blue-500); }
        .quill-wrap .ql-snow .ql-picker { color: var(--text-secondary); }
        .quill-wrap .ql-snow .ql-picker-options {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: var(--blue-25);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
        }

        .toggle-label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .toggle-switch {
            position: relative;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--blue-100);
            border-radius: 24px;
            transition: background 0.2s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            left: 3px; top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.18);
        }

        .toggle-switch input:checked + .toggle-slider { background: var(--blue-500); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }

        .btn-row { display: flex; gap: 10px; }

        .btn {
            flex: 1;
            padding: 11px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-family: 'DM Sans', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .btn-primary {
            background: var(--blue-500);
            color: #fff;
            box-shadow: 0 2px 8px rgba(2,136,209,0.25);
        }
        .btn-primary:hover {
            background: #0277bd;
            box-shadow: 0 4px 14px rgba(2,136,209,0.35);
            transform: translateY(-1px);
        }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-ghost {
            background: #fff;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover {
            background: var(--blue-50);
            color: var(--text-primary);
            border-color: var(--blue-200);
        }

        .flash {
            padding: 10px 14px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 500;
            display: none;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .flash.show { display: flex; }
        .flash.success { background: #edf9f0; color: #1b8a45; border: 1px solid #a7f3c0; }
        .flash.error   { background: #fef2f2; color: #c0392b; border: 1px solid #fca5a5; }

        .ql-snow .ql-color-picker .ql-picker-options .ql-picker-item {
            width: 20px !important; height: 20px !important;
            border-radius: 3px !important;
            border: 1px solid rgba(0,0,0,0.12) !important;
        }
        .ql-snow .ql-color-picker.ql-color .ql-picker-options,
        .ql-snow .ql-color-picker.ql-background .ql-picker-options {
            width: 172px !important; padding: 6px !important;
        }
        .ql-snow .ql-color-picker .ql-picker-options { display: none; }
        .ql-snow .ql-color-picker.ql-expanded .ql-picker-options { display: block !important; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">

    <!-- TABS -->
    <div class="tabs-wrapper">
        <div class="tabs">
            <?php foreach ($sectionMeta as $sec => $meta):
                $cnt = count($allTemplates[$sec]);
            ?>
            <button class="tab-btn" data-section="<?= $sec ?>">
                <?= $meta['icon'] ?> <?= $meta['label'] ?>
                <span class="tab-count"><?= $cnt ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SECTION PANELS -->
    <?php foreach ($sectionMeta as $sec => $meta): ?>
    <div class="section-panel" id="panel-<?= $sec ?>" style="display:none;">
        <div class="content-grid">

            <!-- LEFT: Template List -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <?= $meta['icon'] ?> <?= $meta['label'] ?>
                        <span class="card-title-count">(<?= count($allTemplates[$sec]) ?>)</span>
                    </div>
                    <button class="btn-add" onclick="openNewEditor('<?= $sec ?>')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        New
                    </button>
                </div>

                <?php if (empty($allTemplates[$sec])): ?>
                <div class="empty-state">
                    <div class="e-icon"><?= $meta['icon'] ?></div>
                    <p>No <?= strtolower($meta['label']) ?> templates yet.<br>Click <strong>+ New</strong> to get started.</p>
                </div>
                <?php else: ?>
                <ul class="tpl-list" id="list-<?= $sec ?>">
                    <?php foreach ($allTemplates[$sec] as $tpl): ?>
                    <li class="tpl-item"
                        data-id="<?= $tpl['id'] ?>"
                        onclick="selectTemplate(<?= $tpl['id'] ?>, '<?= $sec ?>')">
                        <div class="tpl-icon-box"><?= $meta['icon'] ?></div>
                        <div class="tpl-info">
                            <div class="tpl-name"><?= htmlspecialchars($tpl['name']) ?></div>
                            <div class="tpl-date"><?= date('M j, Y', strtotime($tpl['created_at'])) ?></div>
                        </div>
                        <?php if ($tpl['is_default']): ?>
                            <span class="badge-default">Default</span>
                        <?php endif; ?>
                        <div class="tpl-btns">
                            <button class="tpl-btn star" title="Set as default"
                                onclick="event.stopPropagation(); setDefault(<?= $tpl['id'] ?>, '<?= $sec ?>')">⭐</button>
                            <button class="tpl-btn del" title="Delete"
                                onclick="event.stopPropagation(); deleteTemplate(<?= $tpl['id'] ?>, '<?= $sec ?>', '<?= htmlspecialchars(addslashes($tpl['name'])) ?>')">🗑</button>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Editor -->
            <div class="editor-card" id="editor-<?= $sec ?>">
                <div class="editor-card-header">
                    <div class="editor-card-title" id="editor-title-<?= $sec ?>">Select or create a template</div>
                    <div class="editor-card-sub" id="editor-sub-<?= $sec ?>">Pick a template from the list, or click New to start fresh</div>
                </div>

                <div class="editor-body">
                    <div class="flash" id="flash-<?= $sec ?>"></div>

                    <div class="form-group">
                        <input type="text" id="tpl-name-<?= $sec ?>" class="form-input" placeholder="Template name…">
                    </div>

                    <input type="hidden" id="tpl-id-<?= $sec ?>" value="0">

                    <div class="form-group">
                        <div class="quill-wrap">
                            <div id="quill-editor-<?= $sec ?>"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="toggle-row">
                            <span class="toggle-label">Set as default</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="tpl-default-<?= $sec ?>">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button class="btn btn-ghost" onclick="resetEditor('<?= $sec ?>')">Cancel</button>
                        <button class="btn btn-primary" id="save-btn-<?= $sec ?>" onclick="saveTemplate('<?= $sec ?>')">
                            💾 Save Template
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
const SECTIONS = ['greeting', 'intro', 'closing', 'rationale'];
const quillInstances = {};

// ── ACTIVE TAB MANAGEMENT ──────────────────────────────────────────────────
// Reads/writes the current section to the URL hash so that after a reload
// the user lands back on the same tab (e.g. #rationale stays on Rationale).

function getActiveSection() {
    const hash = window.location.hash.replace('#', '');
    return SECTIONS.includes(hash) ? hash : SECTIONS[0]; // default to first
}

function switchTab(sec) {
    // Update URL hash without triggering a page scroll
    history.replaceState(null, '', '#' + sec);

    // Toggle tab button active state
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.section === sec);
    });

    // Toggle panel visibility
    SECTIONS.forEach(s => {
        document.getElementById('panel-' + s).style.display = s === sec ? 'block' : 'none';
    });

    // Let Quill recalculate layout (needed when switching from hidden panel)
    setTimeout(() => quillInstances[sec] && quillInstances[sec].update(), 50);
}

// ── INIT QUILL FOR ALL SECTIONS ───────────────────────────────────────────
SECTIONS.forEach(sec => {
    quillInstances[sec] = new Quill('#quill-editor-' + sec, {
        theme: 'snow',
        placeholder: 'Write your template content here…',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline'],
                [{ color: [
                    '#F59E0B', '#16a34a', '#2563eb', '#dc2626', '#9333ea',
                    '#0288D1', '#000000', '#64748b', false
                ]}, { background: [
                    '#FEF08A', '#BBF7D0', '#BFDBFE', '#FECACA', '#E9D5FF',
                    '#BAE6FD', false
                ]}],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['clean']
            ]
        }
    });
});

// ── RESTORE TAB ON PAGE LOAD ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    switchTab(getActiveSection());
});

// ── TAB CLICK ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        switchTab(this.dataset.section);
    });
});

// ── HELPERS ───────────────────────────────────────────────────────────────
function showFlash(sec, type, msg) {
    const el = document.getElementById('flash-' + sec);
    el.className = 'flash show ' + type;
    el.innerHTML = (type === 'success' ? '✓ ' : '✗ ') + msg;
    setTimeout(() => { el.className = 'flash'; }, 3500);
}

function resetEditor(sec) {
    document.getElementById('tpl-id-' + sec).value = '0';
    document.getElementById('tpl-name-' + sec).value = '';
    document.getElementById('tpl-default-' + sec).checked = false;
    quillInstances[sec].setContents([]);
    document.getElementById('editor-title-' + sec).textContent = 'Select or create a template';
    document.getElementById('editor-sub-' + sec).textContent = 'Click a template on the left to edit it';
    document.getElementById('save-btn-' + sec).textContent = '💾 Save Template';
    document.querySelectorAll('#list-' + sec + ' .tpl-item').forEach(li => li.classList.remove('active'));
}

function openNewEditor(sec) {
    resetEditor(sec);
    document.getElementById('editor-title-' + sec).textContent = 'New Template';
    document.getElementById('editor-sub-' + sec).textContent = 'Fill in the details below';
    document.getElementById('tpl-name-' + sec).focus();
}

// ── RELOAD KEEPING CURRENT TAB ────────────────────────────────────────────
// All reloads go through this function so the hash is always set first.
function reloadKeepingTab() {
    // Hash is already set in the URL by switchTab() / any tab click,
    // so a plain reload will restore it via getActiveSection().
    window.location.reload();
}

// ── SELECT / LOAD TEMPLATE ────────────────────────────────────────────────
function selectTemplate(id, sec) {
    document.querySelectorAll('#list-' + sec + ' .tpl-item').forEach(li => li.classList.remove('active'));
    const li = document.querySelector('#list-' + sec + ' .tpl-item[data-id="' + id + '"]');
    if (li) li.classList.add('active');

    fetch('template_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ajax_action: 'load_template', template_id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Load failed');
        const tpl = data.template;
        document.getElementById('tpl-id-' + sec).value = tpl.id;
        document.getElementById('tpl-name-' + sec).value = tpl.name;
        document.getElementById('tpl-default-' + sec).checked = parseInt(tpl.is_default) === 1;
        quillInstances[sec].clipboard.dangerouslyPasteHTML(tpl.content || '');
        document.getElementById('editor-title-' + sec).textContent = 'Editing: ' + tpl.name;
        document.getElementById('editor-sub-' + sec).textContent = 'Make changes below and click Save';
        document.getElementById('save-btn-' + sec).textContent = '💾 Update Template';
    })
    .catch(err => showFlash(sec, 'error', err.message));
}

// ── SAVE TEMPLATE ─────────────────────────────────────────────────────────
function saveTemplate(sec) {
    const id        = document.getElementById('tpl-id-' + sec).value;
    const name      = document.getElementById('tpl-name-' + sec).value.trim();
    const isDefault = document.getElementById('tpl-default-' + sec).checked ? 1 : 0;
    const content   = quillInstances[sec].root.innerHTML.trim();
    const textOnly  = quillInstances[sec].getText().trim();

    if (!name)     { showFlash(sec, 'error', 'Template name is required.'); return; }
    if (!textOnly) { showFlash(sec, 'error', 'Content cannot be empty.'); return; }

    const isEdit = id && id !== '0';
    const btn = document.getElementById('save-btn-' + sec);
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const body = new URLSearchParams({
        ajax_action:      isEdit ? 'edit_template' : 'add_template',
        template_name:    name,
        template_content: content,
        section_type:     sec,
        is_default:       isDefault
    });
    if (isEdit) body.append('template_id', id);

    fetch('template_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Save failed');
        showFlash(sec, 'success', data.message || 'Saved successfully.');
        // Reload after short delay — hash already contains current section
        setTimeout(reloadKeepingTab, 900);
    })
    .catch(err => {
        showFlash(sec, 'error', err.message);
        btn.disabled = false;
        btn.textContent = isEdit ? '💾 Update Template' : '💾 Save Template';
    });
}

// ── DELETE ────────────────────────────────────────────────────────────────
function deleteTemplate(id, sec, name) {
    if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
    fetch('template_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ajax_action: 'delete_template', template_id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showFlash(sec, 'success', 'Template deleted.');
        setTimeout(reloadKeepingTab, 700);
    })
    .catch(err => showFlash(sec, 'error', err.message));
}

// ── SET DEFAULT ───────────────────────────────────────────────────────────
function setDefault(id, sec) {
    fetch('template_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ajax_action: 'set_default', template_id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Failed');
        showFlash(sec, 'success', 'Default template updated.');
        setTimeout(reloadKeepingTab, 700);
    })
    .catch(err => showFlash(sec, 'error', err.message));
}
</script>
</body>
</html>
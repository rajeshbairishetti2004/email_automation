<?php
// template_actions.php
ob_start();
header('Content-Type: application/json');
require_once 'db_config.php';

$action = $_POST['ajax_action'] ?? '';
$response = ['success' => false];

try {
    $pdo = getPdo();
    
    if ($action === 'edit_template') {
        $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ?");
        $stmt->execute([
            $_POST['template_name'] ?? '',
            $_POST['template_content'] ?? '',
            (int)$_POST['template_id']
        ]);
        $response['success'] = true;
    } 
    elseif ($action === 'delete_template') {
        // Delete the template
        $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
        $stmt->execute([(int)$_POST['template_id']]);
        
        // Generate updated dropdown HTML for ONLY the affected section
        $section = $_POST['section_type'];
        $stmtList = $pdo->prepare("SELECT id, name, content FROM report_templates WHERE section_type = ? ORDER BY name ASC");
        $stmtList->execute([$section]);
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
        
        $html = '<option value="0">-- Select --</option>';
        foreach($rows as $r) {
            $html .= sprintf(
                '<option value="%d" data-content="%s">%s</option>',
                (int)$r['id'],
                htmlspecialchars($r['content'] ?? ''),
                htmlspecialchars($r['name'] ?? 'Untitled')
            );
        }
        $response['html_update'] = $html;
        $response['success'] = true;
    }
    elseif ($action === 'save_template') {
        $section = $_POST['section_type'] ?? '';
        $name = trim($_POST['template_name'] ?? '');
        $content = $_POST['template_content'] ?? '';
        
        if ($name === '' || $content === '') {
            throw new Exception('Template name and content are required');
        }
        
        $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
        $stmt->execute([$name, $section, $content]);
        $newId = $pdo->lastInsertId();

        // Generate updated dropdown HTML for ONLY the affected section
        $stmtList = $pdo->prepare("SELECT id, name, content FROM report_templates WHERE section_type = ? ORDER BY name ASC");
        $stmtList->execute([$section]);
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
        
        $html = '<option value="0">-- Select --</option>';
        foreach($rows as $r) {
            $html .= sprintf(
                '<option value="%d" data-content="%s">%s</option>',
                (int)$r['id'],
                htmlspecialchars($r['content'] ?? ''),
                htmlspecialchars($r['name'] ?? 'Untitled')
            );
        }
        $response['html_update'] = $html;
        $response['success'] = true;
        $response['new_id'] = $newId;
    } else {
        throw new Exception('Invalid action');
    }

    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
exit;
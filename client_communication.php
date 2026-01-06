<?php
// client_communication.php
// Refactored to match Rationale module styling and functionality

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_start();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'] ?? '';
    $response = ['success' => false];
    
    try {
        require_once 'db_config.php';
        $pdo = getPdo();
        
        if ($action === 'save_user_template') {
            $section = $_POST['section_type'] ?? '';
            $name = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            $idToUpdate = $_POST['template_id_to_update'] ?? null;

            if ($idToUpdate) {
                $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ?");
                $stmt->execute([$name, $content, (int)$idToUpdate]);
                $response['template_id'] = $idToUpdate;
            } else {
                $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
                $stmt->execute([$name, $section, $content]);
                $response['template_id'] = $pdo->lastInsertId();
            }
            $response['success'] = true;
        } 
        elseif ($action === 'delete_user_template') {
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([(int)$_POST['template_id']]);
            $response['success'] = true;
        }

        ob_end_clean();
        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>

<style>
/* Synchronized Rationale Styles */
.comm-module-container { font-family: Inter, Arial, sans-serif; }

.rat-box {
    margin-top: 18px;
    padding: 14px;
    border: 1px solid #e6f2fb;
    border-radius: 8px;
    background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
    box-shadow: 0 1px 0 rgba(2,136,209,0.03);
    margin-bottom: 20px;
}

.rat-label { font-weight: 700; display: block; margin-bottom: 8px; color: #083744; }

.rat-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.rat-select {
    flex: 1;
    min-width: 250px;
    padding: 8px 10px;
    border: 1px solid #dbeefb;
    border-radius: 6px;
    background: #fff;
    color: #083744;
    font-size: 14px;
}

.rat-btn {
    padding: 8px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.12s ease;
}

.rat-btn.save { background: #0288D1; color: #fff; }
.rat-btn.save:hover { background: #2eb85c !important; transform: translateY(-1px); }

.rat-btn.edit { background: #039be5; color: #fff; }
.rat-btn.edit:hover { background: #0288d1; transform: translateY(-1px); }

.rat-btn.del { background: #0277bd; color: #fff; }
.rat-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }

.rat-btn.add {
    display: flex; align-items: center; justify-content: center; 
    width: 36px; height: 36px; padding: 0; border-radius: 50%; 
    background: #eaf7ff; border: 1px solid #cfeefc; color: #0288d1;
}

.rat-textarea {
    width: 100%;
    padding: 12px;
    font-size: 14px;
    min-height: 100px;
    box-sizing: border-box;
    border: 1px solid #dbeefb;
    border-radius: 6px;
    background: #fff;
    color: #052b36;
    resize: vertical;
}

.rat-flash { margin-top: 8px; min-height: 20px; font-size: 13px; }

@media (max-width: 640px) {
    .rat-controls { flex-direction: column; align-items: stretch; }
    .rat-btn { width: 100%; }
}
</style>

<div class="comm-module-container">
    <?php
    $sections = [
        'greeting' => ['label' => 'Greeting', 'val' => $greetingStored ?? ''],
        'intro'    => ['label' => 'Introduction', 'val' => $introTextStored ?? ''],
        'closing'  => ['label' => 'Closing', 'val' => $closingTextStored ?? '']
    ];

    foreach ($sections as $key => $data): ?>
    <div class="rat-box" id="section_<?= $key ?>">
        <label class="rat-label"><?= $data['label'] ?></label>
        <div class="rat-controls">
            <select class="rat-select section-selector">
                <option value="0">-- Select saved <?= strtolower($data['label']) ?> template --</option>
                <?php if (!empty($templates[$key])): ?>
                    <?php foreach ($templates[$key] as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" data-content="<?= htmlspecialchars($t['content'] ?? '') ?>">
                            <?= htmlspecialchars($t['name'] ?? 'Untitled') ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <button class="rat-btn add section-add-btn" type="button" title="Add new template">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>

            <button class="rat-btn save section-save-btn" type="button">Save</button>
            <button class="rat-btn edit section-edit-btn" type="button">Edit</button>
            <button class="rat-btn del section-del-btn" type="button">Delete</button>
        </div>

        <textarea class="rat-textarea section-textarea"><?= htmlspecialchars($data['val']) ?></textarea>
        <div class="rat-flash section-flash"></div>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function() {
    function initSection(sectionKey) {
        const container = document.getElementById('section_' + sectionKey);
        const selector = container.querySelector('.section-selector');
        const textarea = container.querySelector('.section-textarea');
        const saveBtn = container.querySelector('.section-save-btn');
        const editBtn = container.querySelector('.section-edit-btn');
        const delBtn = container.querySelector('.section-del-btn');
        const addBtn = container.querySelector('.section-add-btn');
        const flash = container.querySelector('.section-flash');

        function showFlash(type, msg) {
            flash.innerHTML = `<span style="color:${type === 'success' ? '#2eb85c' : '#dc3545'}">${type === 'success' ? '✅' : '❌'} ${msg}</span>`;
            setTimeout(() => { flash.innerHTML = ''; }, 3000);
        }

        function setDisabled(state) {
            [selector, textarea, saveBtn, editBtn, delBtn, addBtn].forEach(el => el.disabled = state);
        }

        selector.addEventListener('change', function() {
            const opt = selector.options[selector.selectedIndex];
            if (selector.value !== '0') {
                textarea.value = opt.getAttribute('data-content') || '';
                showFlash('success', 'Template loaded.');
            }
        });

        editBtn.addEventListener('click', () => {
            if (selector.value === '0') return showFlash('error', 'Select a template first.');
            textarea.focus();
        });

        addBtn.addEventListener('click', () => {
            const content = textarea.value.trim();
            if (!content) return showFlash('error', 'Content is empty.');
            const name = prompt('Enter a name for this new template:');
            if (!name) return;

            saveAjax(name, content, null);
        });

        saveBtn.addEventListener('click', () => {
            const id = selector.value;
            const content = textarea.value.trim();
            if (!content) return showFlash('error', 'Content is empty.');

            if (id !== '0') {
                const name = selector.options[selector.selectedIndex].text;
                saveAjax(name, content, id);
            } else {
                const name = prompt('Enter name for new template:');
                if (name) saveAjax(name, content, null);
            }
        });

        delBtn.addEventListener('click', () => {
            const id = selector.value;
            if (id === '0') return showFlash('error', 'Select a template to delete.');
            if (!confirm('Delete this template?')) return;

            setDisabled(true);
            const body = new URLSearchParams({ ajax_action: 'delete_user_template', template_id: id });

            fetch('client_communication.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    selector.remove(selector.selectedIndex);
                    selector.value = '0';
                    textarea.value = '';
                    showFlash('success', 'Deleted.');
                }
            })
            .finally(() => setDisabled(false));
        });

        function saveAjax(name, content, idToUpdate) {
            setDisabled(true);
            const body = new URLSearchParams({
                ajax_action: 'save_user_template',
                section_type: sectionKey,
                template_name: name,
                template_content: content
            });
            if (idToUpdate) body.append('template_id_to_update', idToUpdate);

            fetch('client_communication.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (!idToUpdate) {
                        const opt = document.createElement('option');
                        opt.value = data.template_id;
                        opt.text = name;
                        opt.setAttribute('data-content', content);
                        selector.add(opt);
                        selector.value = data.template_id;
                    } else {
                        selector.options[selector.selectedIndex].setAttribute('data-content', content);
                    }
                    showFlash('success', 'Saved.');
                }
            })
            .finally(() => setDisabled(false));
        }
    }

    // Initialize all sections
    ['greeting', 'intro', 'closing'].forEach(initSection);
})();
</script>
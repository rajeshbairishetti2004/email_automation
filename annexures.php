<?php
// annexures.php
// Requires: $clientId (int) — set by view_report.php

if (!isset($clientId) || $clientId <= 0) {
    echo "<p>Error: Missing client ID</p>";
    return;
}

if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename, $clientName = '') {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return $name ?: $filename;
    }
}

if (!function_exists('getAnnexureUrl')) {
    function getAnnexureUrl($clientId, $filename) {
        return 'uploads/attachments/client_' . (int)$clientId . '/' . rawurlencode($filename);
    }
}

require_once 'db_config.php';
$pdo = getPdo();

// Primary: query report_attachments table
$stmt = $pdo->prepare(
    "SELECT file_name FROM report_attachments
     WHERE client_id = :client_id
     ORDER BY uploaded_at DESC, id DESC"
);
$stmt->execute([':client_id' => $clientId]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fallback: filesystem scan (backward compatibility)
// IMPORTANT: must run BEFORE $hasFiles is evaluated
if (empty($existingFiles)) {
    $attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;

    if (is_dir($attDir)) {
        $fileSystemFiles = [];
        foreach (scandir($attDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
                $fileSystemFiles[] = $file;

                // Sync to DB only if not already present
                try {
                    $checkStmt = $pdo->prepare(
                        "SELECT id FROM report_attachments
                         WHERE client_id = :client_id AND file_name = :file_name"
                    );
                    $checkStmt->execute([':client_id' => $clientId, ':file_name' => $file]);
                    if (!$checkStmt->fetch()) {
                        $pdo->prepare(
                            "INSERT INTO report_attachments (client_id, file_name)
                             VALUES (:client_id, :file_name)"
                        )->execute([':client_id' => $clientId, ':file_name' => $file]);
                    }
                } catch (Exception $e) {
                    error_log("annexures.php DB sync error: " . $e->getMessage());
                }
            }
        }
        $existingFiles = $fileSystemFiles;
    }
}

// CRITICAL: evaluate $hasFiles only AFTER all data sources checked
$hasFiles = !empty($existingFiles);

// Sort: inception file first, rest follow
$sortedFiles   = [];
$inceptionFile = null;
foreach ($existingFiles as $file) {
    if (preg_match('/portfolio.*performance.*inception/i', $file)) {
        $inceptionFile = $file;
    } else {
        $sortedFiles[] = $file;
    }
}
if ($inceptionFile) {
    array_unshift($sortedFiles, $inceptionFile);
}
?>

<div class="comm-section" id="annexures_module">
    <div class="comm-header">
        <div class="comm-title">Annexures</div>
    </div>

    <ul id="annexures_list" style="list-style:none; padding:0; margin:0;">
        <?php if ($hasFiles): ?>
            <?php foreach ($sortedFiles as $file): ?>
                <?php
                    $label = formatAnnexureLabel($file);
                    $url   = getAnnexureUrl($clientId, $file);
                ?>
                <li style="margin-bottom:8px; padding:8px 0; border-bottom:1px solid #eee; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:18px;">📎</span>
                    <a href="<?= htmlspecialchars($url) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       style="color:#0288d1; font-weight:600; text-decoration:none;">
                        <?= htmlspecialchars($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php else: ?>
            <li style="color:#777; padding:8px 0;">No annexures available.</li>
        <?php endif; ?>
    </ul>
</div>
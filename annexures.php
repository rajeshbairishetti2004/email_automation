<?php
// annexures.php
// Requires: $clientId and $client

if (!isset($clientId) || !isset($client)) {
    echo "<p>Error: Missing client data</p>";
    return;
}

// Format annexure label
if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return $name ?: $filename;
    }
}

// Database connection
require_once 'db_config.php';
$pdo = getPdo();

// Get files from database
$stmt = $pdo->prepare("SELECT file_name FROM report_attachments WHERE client_id = :client_id ORDER BY uploaded_at DESC, id DESC");
$stmt->execute([':client_id' => $clientId]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no files in database, check filesystem for backward compatibility
if (empty($existingFiles)) {
    $attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;

    if (is_dir($attDir)) {
        $files = scandir($attDir);
        $fileSystemFiles = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
                $fileSystemFiles[] = $file;

                // Insert into database for future consistency
                try {
                    $checkStmt = $pdo->prepare("SELECT id FROM report_attachments WHERE client_id = :client_id AND file_name = :file_name");
                    $checkStmt->execute([':client_id' => $clientId, ':file_name' => $file]);

                    if (!$checkStmt->fetch()) {
                        $insertStmt = $pdo->prepare("INSERT INTO report_attachments (client_id, file_name) VALUES (:client_id, :file_name)");
                        $insertStmt->execute([':client_id' => $clientId, ':file_name' => $file]);
                    }
                } catch (Exception $e) {
                    // Silently continue if there's an error
                }
            }
        }

        if (!empty($fileSystemFiles)) {
            $existingFiles = $fileSystemFiles;
        }
    }
}

$hasFiles = !empty($existingFiles);
?>

<h3>Annexures</h3>

<ul id="annexures_list" style="list-style:none; padding:0;">
<?php
if ($hasFiles) {
    $sortedFiles  = [];
    $inceptionFile = null;

    foreach ($existingFiles as $file) {
        if (preg_match('/portfolio.*performance.*inception/i', $file)) {
            $inceptionFile = $file;
        } else {
            $sortedFiles[] = $file;
        }
    }

    // Inception file first
    if ($inceptionFile) {
        $label = formatAnnexureLabel($inceptionFile);
        echo '<li style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        echo '</li>';
    }

    foreach ($sortedFiles as $file) {
        $label = formatAnnexureLabel($file);
        echo '<li style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        echo '</li>';
    }
} else {
    echo '<li style="color:#777;">No annexures available.</li>';
}
?>
</ul>
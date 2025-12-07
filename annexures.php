<?php
$attDir = $attDir ?? '';
// ...existing code for $client...
?>
<h3>Annexures</h3>
<ul class="annexures-list">
    <?php
    $hasFiles = false;
    if (is_dir($attDir)) {
        $files = scandir($attDir);
        $sortedFiles = [];
        $inceptionFile = null;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $hasFiles = true;
            $nameLower = strtolower($file);
            if (preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) || 
                preg_match('/portfolio.*performance.*inception/i', $nameLower)) {
                $inceptionFile = $file;
            } else {
                $sortedFiles[] = $file;
            }
        }
        if ($inceptionFile) {
            $label = formatAnnexureLabel($inceptionFile, $client['name'] ?? '');
            echo "<li>" . htmlspecialchars($label) . "</li>";
        }
        foreach ($sortedFiles as $file) {
            $label = formatAnnexureLabel($file, $client['name'] ?? '');
            echo "<li>" . htmlspecialchars($label) . "</li>";
        }
    }
    if (!$hasFiles) {
        echo "<li>No documents attached.</li>";
    }
    ?>
</ul>
<script src="public/js/annexures.js"></script>

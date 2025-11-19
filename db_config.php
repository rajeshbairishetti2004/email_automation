<?php
// db_config.php
// - Database configuration and connection
// - Common helper functions

require_once 'env_loader.php';

const DB_HOST = 'localhost';
const DB_NAME = 'client_reports';
const DB_USER = 'root';
const DB_PASS = ''; // your password

function getPdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

/* ---------- GENERIC HELPERS ---------- */

function normalize_label(string $label): string {
    $label = strtolower($label);
    $label = preg_replace('/[^a-z0-9]+/', ' ', $label);
    return trim($label);
}

function findColumnByKeywords(array $headerRow, array $keywords) {
    $normKeywords = array_map('normalize_label', $keywords);
    foreach ($headerRow as $col => $labelRaw) {
        $label = normalize_label((string)$labelRaw);
        foreach ($normKeywords as $kw) {
            if ($kw !== '' && strpos($label, $kw) !== false) {
                return $col;
            }
        }
    }
    return null;
}

function findHeaderRowForPV(array $rows): ?int {
    $maxScan = min(40, count($rows));
    for ($i = 0; $i < $maxScan; $i++) {
        if (!isset($rows[$i]) || !is_array($rows[$i])) continue;
        $row       = $rows[$i];
        $colScheme = findColumnByKeywords($row, ['scheme name', 'scheme']);
        $colCurr   = findColumnByKeywords($row, ['current value', 'current']);
        if ($colScheme !== null && $colCurr !== null) {
            return $i;
        }
    }
    return null;
}

function findHeaderRowGeneric(array $rows, array $mustKeywords): ?int {
    $maxScan = min(40, count($rows));
    for ($i = 0; $i < $maxScan; $i++) {
        if (!isset($rows[$i]) || !is_array($rows[$i])) continue;
        $row = $rows[$i];
        $ok  = true;
        foreach ($mustKeywords as $kwList) {
            if (findColumnByKeywords($row, $kwList) === null) {
                $ok = false;
                break;
            }
        }
        if ($ok) return $i;
    }
    return null;
}

function parseIndianNumber(string $s): float {
    $s = preg_replace('/[^\d\.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '--') return 0.0;
    return (float)$s;
}

function formatRupeesLakhs(float $amount, bool $lakhs = true): string {
    if ($lakhs) {
        $v = $amount / 100000;
        return 'Rs.' . number_format($v, 2) . ' lakhs';
    }
    return 'Rs.' . number_format($amount, 2);
}

function formatPercent(float $v): string {
    return number_format($v, 2) . '%';
}

/* ✅ Universal Indian Format Function */
function formatAmount($value) {
    $num = floatval($value);

    // 1 Crore = 1,00,00,000 (Indian system: 100 Lakhs)
    if ($num >= 10000000) {
        return "Rs." . round($num / 10000000, 2) . " Cr";
    }

    // Lakhs = 1,00,000 to < 1,00,00,000
    if ($num >= 100000) {
        return "Rs." . round($num / 100000, 2) . " lakhs";
    }

    // Thousands = 1,000 to < 1,00,000
    if ($num >= 1000) {
        return "Rs." . round($num / 1000, 1) . "k";
    }

    return "Rs." . number_format($num, 2);
}

function clientNameFromFilename(string $path): ?string {
    $base = basename($path);
    $base = preg_replace('/\.(xlsx|xls|csv|pdf)$/i', '', $base);

    if (preg_match('/PortfolioValuation-([^-]+)-/i', $base, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/Portfolio Summary-([^-]+)-/i', $base, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/Allocation Analysis-([^-]+)-/i', $base, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/GoalStatusReport_([^_]+)_/i', $base, $m)) {
        return trim(str_replace('_', ' ', $m[1]));
    }
    return null;
}

function filterClientArrayForTarget(array $dataset, ?string $targetName): array {
    if (empty($dataset) || !$targetName) return $dataset;

    if (isset($dataset[$targetName])) {
        return [$targetName => $dataset[$targetName]];
    }

    if (count($dataset) === 1) {
        $onlyKey = array_key_first($dataset);
        return [$targetName => $dataset[$onlyKey]];
    }

    if (isset($dataset['Client'])) {
        return [$targetName => $dataset['Client']];
    }

    return $dataset;
}
?>
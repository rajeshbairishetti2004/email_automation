<?php
// db_config.php
require_once 'env_loader.php';

// Get database credentials from environment variables
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'client_reports');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

function getPdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function getDefaultRelationshipManager() {
    $pdo = getPdo();
    // Fetch RM set as default (is_default = 1)
    $stmt = $pdo->prepare("SELECT * FROM relationship_managers WHERE is_default = 1 LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllRelationshipManagers() {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT id, name, designation, mobile, email, is_default FROM relationship_managers ORDER BY name ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateSignatureBlock(array $rm): string {
    $rmName        = $rm['name'] ?? 'Relationship Manager';
    $rmDesignation = $rm['designation'] ?? 'Relationship Manager';
    $rmMobile      = $rm['mobile'] ?? 'N/A';
    $rmEmail       = $rm['email'] ?? 'N/A';

    return "Regards,\n\n{$rmName},\n{$rmDesignation},\nFinance Doctor Private Limited.\n\nMobile - {$rmMobile}.\nEmail - {$rmEmail}\nUrl: www.financedoctor.in";
}

function getRelationshipManagerCount(): int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM relationship_managers");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function addNewRelationshipManager(string $name, string $designation, string $mobile, string $email, bool $is_default = false): ?int {
    $pdo = getPdo();
    
    // Ensure all current RMs are set to not default if this new one is default
    if ($is_default) {
        $pdo->exec("UPDATE relationship_managers SET is_default = 0");
    }

    $stmt = $pdo->prepare("
        INSERT INTO relationship_managers (name, designation, mobile, email, is_default)
        VALUES (:name, :designation, :mobile, :email, :is_default)
    ");
    $stmt->execute([
        ':name' => $name,
        ':designation' => $designation,
        ':mobile' => $mobile,
        ':email' => $email,
        ':is_default' => $is_default ? 1 : 0
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetches the ID, Name, and Email of all registered users.
 * Used to populate the "From" dropdown list.
 * @return array Array of user records.
 */
function getAllActiveUserEmails(): array {
    $pdo = getPdo();
    // FIX: Removed 'WHERE status = "active"' to return all emails and fix visibility issue.
    $stmt = $pdo->prepare("SELECT id, name, username, email FROM users ORDER BY name ASC, username ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Template Functions (Generic) ---

function getReportTemplates(string $section_type): array {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT id, name, content FROM report_templates WHERE section_type = :section_type ORDER BY name ASC");
    $stmt->execute([':section_type' => $section_type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deleteTemplate(int $template_id): bool {
    $pdo = getPdo();
    $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = :id");
    return $stmt->execute([':id' => $template_id]);
}

function getTemplateContent(int $template_id): ?string {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT content FROM report_templates WHERE id = :id");
    $stmt->execute([':id' => $template_id]);
    return $stmt->fetchColumn();
}

function addNewTemplate(string $name, string $section_type, string $content): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("
        INSERT INTO report_templates (name, section_type, content)
        VALUES (:name, :section_type, :content)
    ");
    $stmt->execute([
        ':name' => $name,
        ':section_type' => $section_type,
        ':content' => $content,
    ]);
    return (int)$pdo->lastInsertId();
}


// --- USER SPECIFIC TEMPLATE FUNCTIONS (using new table name user_rationale_templates) ---

/**
 * Fetches Rationale templates specific to a logged-in user.
 * @param int $userId The ID of the currently logged-in user.
 * @return array Array of user-specific rationale templates.
 */
function getUserRationaleTemplates(int $userId): array {
    $pdo = getPdo();
    $stmt = $pdo->prepare("
        SELECT id, template_name AS name, content 
        FROM user_rationale_templates  -- UPDATED TABLE NAME
        WHERE user_id = :user_id AND section_type = 'rationale' 
        ORDER BY template_name ASC
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Saves or updates a user-specific rationale template.
 */
function saveUserRationaleTemplate(int $userId, string $templateName, string $content, ?int $templateId = null): bool {
    $pdo = getPdo();

    if ($templateId) {
        // Update existing template
        $stmt = $pdo->prepare("
            UPDATE user_rationale_templates -- UPDATED TABLE NAME
            SET template_name = :name, content = :content 
            WHERE id = :id AND user_id = :user_id
        ");
        return $stmt->execute([
            ':name' => $templateName,
            ':content' => $content,
            ':id' => $templateId,
            ':user_id' => $userId
        ]);
    } else {
        // Insert new template
        $stmt = $pdo->prepare("
            INSERT INTO user_rationale_templates (user_id, template_name, content, section_type) -- UPDATED TABLE NAME
            VALUES (:user_id, :name, :content, 'rationale')
        ");
        return $stmt->execute([
            ':user_id' => $userId,
            ':name' => $templateName,
            ':content' => $content
        ]);
    }
}

/**
 * Deletes a user-specific rationale template.
 */
function deleteUserRationaleTemplate(int $userId, int $templateId): bool {
    $pdo = getPdo();
    $stmt = $pdo->prepare("DELETE FROM user_rationale_templates WHERE id = :id AND user_id = :user_id"); // UPDATED TABLE NAME
    return $stmt->execute([
        ':id' => $templateId,
        ':user_id' => $userId
    ]);
}


/* ---------- EMAIL CONTACT FUNCTIONS (For Dropdowns Persistence) ---------- */
function getEmailContacts(string $list_type, ?int $clientId = null): array {
    $pdo = getPdo();
    
    $params = [':list_type' => $list_type];
    $sql = "SELECT email_address FROM email_contacts WHERE list_type = :list_type";

    if ($list_type === 'CLIENT') {
         $sql .= " AND client_id = :client_id";
         $params[':client_id'] = $clientId;
    } else {
        $sql .= " AND client_id IS NULL"; 
    }
    
    $stmt = $pdo->prepare($sql . " ORDER BY email_address ASC");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function saveNewEmailContact(string $email, string $list_type, ?int $clientId = null): bool {
    $pdo = getPdo();
    
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO email_contacts (email_address, list_type, client_id)
        VALUES (:email, :list_type, :client_id)
    ");
    
    $params = [
        ':email' => $email,
        ':list_type' => $list_type,
        ':client_id' => ($list_type === 'CLIENT') ? $clientId : null
    ];
    
    return $stmt->execute($params);
}

function deleteEmailContacts(array $emailsToDelete, string $list_type, ?int $clientId = null): int {
    if (empty($emailsToDelete)) return 0;
    
    $pdo = getPdo();
    // Create placeholders for the IN clause (e.g., ?, ?, ?)
    $placeholders = implode(',', array_fill(0, count($emailsToDelete), '?'));
    
    $sql = "DELETE FROM email_contacts WHERE email_address IN ({$placeholders}) AND list_type = ?";
    
    $params = $emailsToDelete;
    $params[] = $list_type;

    if ($list_type === 'CLIENT') {
        $sql .= " AND client_id = ?";
        $params[] = $clientId;
    } else {
        $sql .= " AND client_id IS NULL";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
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

/* Universal Indian Format Function */
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

    // Thousands = 1,000 to < 1,00,00,000
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

/* ---------- DATABASE VALIDATION ---------- */

function validateDatabaseConnection(): bool {
    try {
        $pdo = getPdo();
        $pdo->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return false;
    }
}

/* ---------- ENVIRONMENT CHECK ---------- */

function checkDatabaseEnvironment(): array {
    $errors = [];
    
    if (empty(DB_HOST)) {
        $errors[] = 'DB_HOST is not set in environment variables';
    }
    
    if (empty(DB_NAME)) {
        $errors[] = 'DB_NAME is not set in environment variables';
    }
    
    if (empty(DB_USER)) {
        $errors[] = 'DB_USER is not set in environment variables';
    }
    
    // DB_PASS can be empty if no password is set
    
    return $errors;
}
// NO CLOSING PHP TAG
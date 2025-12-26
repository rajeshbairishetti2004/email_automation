<?php
// db_config.php
require_once 'env_loader.php';

// Helper to read environment variables from $_ENV or getenv with a fallback default
function getEnvVar(string $key, $default) {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

// Define connection constants so the rest of the file can rely on them
define('DB_HOST', getEnvVar('DB_HOST', 'localhost'));
define('DB_NAME', getEnvVar('DB_NAME', 'client_reports'));
define('DB_USER', getEnvVar('DB_USER', 'root'));
define('DB_PASS', getEnvVar('DB_PASS', ''));
define('DB_PORT', getEnvVar('DB_PORT', '3306'));

function getPdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $portSegment = DB_PORT ? ';port=' . DB_PORT : '';
        $dsn = 'mysql:host=' . DB_HOST . $portSegment . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // [PATCH START] Force IST Timezone
        date_default_timezone_set('Asia/Kolkata');
        $pdo->exec("SET time_zone = '+05:30';");
        // [PATCH END]
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

// NEW: update existing report template
function updateReportTemplate(int $id, string $name, string $content): bool {
    $pdo = getPdo();
    $stmt = $pdo->prepare("UPDATE report_templates SET name = :name, content = :content, created_at = created_at WHERE id = :id");
    // leave created_at as-is; updated_at column doesn't exist in original schema
    return $stmt->execute([':name' => $name, ':content' => $content, ':id' => $id]);
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
    // 1. Remove Rupee symbols and trim whitespace
    $s = trim(str_ireplace(['Rs.', 'Rs', '₹'], '', $s));
    
    // 2. Handle shorthand formats (30k, 1lakh, 2cr) BEFORE removing other text
    $multiplier = 1;
    if (preg_match('/(\d+\.?\d*)\s*k$/i', $s, $matches)) {
        $multiplier = 1000;
        $s = $matches[1];
    } elseif (preg_match('/(\d+\.?\d*)\s*lakhs?$/i', $s, $matches)) {
        $multiplier = 100000;
        $s = $matches[1];
    } elseif (preg_match('/(\d+\.?\d*)\s*crs?$/i', $s, $matches)) {
        $multiplier = 10000000;
        $s = $matches[1];
    } elseif (preg_match('/(\d+\.?\d*)\s*crores?$/i', $s, $matches)) {
        $multiplier = 10000000;
        $s = $matches[1];
    } else {
        // Remove text markers if not using shorthand
        $s = str_ireplace(['Cr', 'lakhs', 'lakh', 'crore', 'k'], '', $s);
    }
    
    // 3. Remove commas (Indian thousand separators).
    $s = str_replace(',', '', $s);
    
    // 4. Handle cases where negative sign might be outside the parentheses, e.g., "-583102.00 *"
    $s = preg_replace('/[^\d\.\-]/', '', $s);

    if ($s === '' || $s === '-' || $s === '.') return 0.0;
    
    // Convert to float and apply multiplier
    $value = (float)$s * $multiplier;
    
    // Safety check: If value is unreasonably large (> 10 trillion), it's likely a parsing error
    // This handles cases where commas are misread as extra digits
    // Typical max value: 1000 Crores = 10,000,000,000 (10 billion)
    if ($value > 10000000000000) {
        // Divide by 1000000 to bring it back to reasonable range
        $value = $value / 1000000;
    }
    
    return $value;
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

    $clientName = null;
    
    // More flexible patterns that capture full client names with spaces/hyphens
    if (preg_match('/PortfolioValuation-(.+?)(?:-\d{8}|-\d{4})?$/i', $base, $m)) {
        $clientName = trim($m[1]);
    } elseif (preg_match('/Portfolio\s+Summary-(.+?)(?:-\d{8}|-\d{4})?$/i', $base, $m)) {
        $clientName = trim($m[1]);
    } elseif (preg_match('/Allocation\s+Analysis-(.+?)(?:-\d{8}|-\d{4})?$/i', $base, $m)) {
        $clientName = trim($m[1]);
    } elseif (preg_match('/GoalStatusReport[_-](.+?)(?:[_-]\d{8})?$/i', $base, $m)) {
        $clientName = trim(str_replace('_', ' ', $m[1]));
    }
    
    // Remove trailing numbers (like phone numbers) from the client name
    if ($clientName) {
        $clientName = preg_replace('/\s+\d+$/', '', $clientName);
    }
    
    return $clientName;
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

// ---------- User Rationale Templates (user_rationale_templates) ----------
if (!function_exists('ensureRationaleTableExists')) {
	// Creates a dedicated table for user-specific rationale templates (MEDIUMTEXT content)
	function ensureRationaleTableExists(PDO $pdo): bool {
		$sql = "
			CREATE TABLE IF NOT EXISTS `user_rationale_templates` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				`user_id` INT UNSIGNED NULL,
				`name` VARCHAR(255) NOT NULL,
				`content` MEDIUMTEXT NOT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NULL DEFAULT NULL,
				KEY (`user_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		try {
			$pdo->exec($sql);
			return true;
		} catch (Exception $e) {
			error_log("ensureRationaleTableExists error: " . $e->getMessage());
			return false;
		}
	}
}

if (!function_exists('getUserRationaleTemplates')) {
	function getUserRationaleTemplates(?int $userId = null): array {
		$pdo = getPdo();
		ensureRationaleTableExists($pdo);
		// Return user's templates first (if any), then templates with NULL user_id (shared)
		$stmt = $pdo->prepare("
			SELECT id, user_id, name, content
			FROM user_rationale_templates
			" . ($userId !== null ? "WHERE user_id = :uid OR user_id IS NULL" : "") . "
			ORDER BY user_id DESC, name ASC
		");
		$params = [];
		if ($userId !== null) $params[':uid'] = (int)$userId;
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}

if (!function_exists('getUserRationaleTemplateById')) {
	function getUserRationaleTemplateById(int $id): ?array {
		$pdo = getPdo();
		ensureRationaleTableExists($pdo);
		$stmt = $pdo->prepare("SELECT id, user_id, name, content FROM user_rationale_templates WHERE id = :id LIMIT 1");
		$stmt->execute([':id' => $id]);
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		return $r ?: null;
	}
}

if (!function_exists('saveUserRationaleTemplate')) {
	// returns inserted id (int) on insert, true on successful update, false on failure
	function saveUserRationaleTemplate(?int $userId, string $name, string $content, ?int $updateId = null) {
		try {
			$pdo = getPdo();
			if (!ensureRationaleTableExists($pdo)) return false;
			$name = mb_substr(trim($name), 0, 255);
			$content = trim($content);
			if ($updateId !== null && $updateId > 0) {
				$stmt = $pdo->prepare("UPDATE user_rationale_templates SET name = :name, content = :content, updated_at = NOW() WHERE id = :id");
				$ok = $stmt->execute([':name' => $name, ':content' => $content, ':id' => (int)$updateId]);
				return $ok ? (int)$updateId : false;
			}
			$stmt = $pdo->prepare("INSERT INTO user_rationale_templates (user_id, name, content, created_at) VALUES (:uid, :name, :content, NOW())");
			$ok = $stmt->execute([':uid' => $userId ?: null, ':name' => $name, ':content' => $content]);
			return $ok ? (int)$pdo->lastInsertId() : false;
		} catch (Exception $e) {
			error_log("saveUserRationaleTemplate error: " . $e->getMessage());
			return false;
		}
	}
}

if (!function_exists('deleteUserRationaleTemplate')) {
	function deleteUserRationaleTemplate(int $templateId, ?int $userId = null): bool {
		try {
			$pdo = getPdo();
			if (!ensureRationaleTableExists($pdo)) return false;
			// Only delete if belongs to user (preferred). If userId null, allow deletion by id.
			if ($userId !== null) {
				$stmt = $pdo->prepare("DELETE FROM user_rationale_templates WHERE id = :id AND user_id = :uid");
				$stmt->execute([':id' => $templateId, ':uid' => $userId]);
				return true;
			}
			$stmt = $pdo->prepare("DELETE FROM user_rationale_templates WHERE id = :id");
			$stmt->execute([':id' => $templateId]);
			return true;
		} catch (Exception $e) {
			error_log("deleteUserRationaleTemplate error: " . $e->getMessage());
			return false;
		}
	}
}


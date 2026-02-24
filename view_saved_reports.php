<?php
// view_saved_reports.php
// - Lists all stored clients with STATUS Workflow badges
// - FIX: explicitly selects report_state to ensure badges appear
// - Added: Bulk reassignment functionality and split owner columns
// - Added: Delete mode with checkboxes that appear only when clicking action icons
// - Added: Last Review columns (review_sent_date, meeting_date, modifications_action,
//           meeting_comments, sip_amount_lakhs) + previous review data pulled via JOIN
// - Added: View Previous Review column with modal to display stored HTML content
// - FIX: Sticky ID + Client Name columns (stop at viewport edge)
// - FIX: "Last Updated" now shows created_at of each row independently;
//         rows with report_state='sent' are read-only (locked)
// - FIX: markPreviousAsNotLatest no longer corrupts updated_at of old rows
// - FIX: last_review_date uses created_at (not updated_at) for sent rows to avoid
//         timestamp corruption caused by is_latest flag update
// - FIX: last_meeting_date now sourced from the SAME record as last_review_date
//         (both use identical ORDER BY so they always come from the same row)

require_once 'auth.php';
requireAuth();
require_once 'env_loader.php';
require_once 'parsers.php';
require_once 'renderers.php';


use PhpOffice\PhpSpreadsheet\IOFactory;

const DEFAULT_GREETING  = 'Dear Mr.';
const DEFAULT_INTRO     = 'Introduction';
const DEFAULT_CLOSING   = 'Closing remarks';
const DEFAULT_RATIONALE = 'Rationale for recommendations';


function extractSubCategoryAllocation(string $filePath): array
{
    $spreadsheet = IOFactory::load($filePath);

    foreach ($spreadsheet->getSheetNames() as $i => $name) {
        if (stripos($name, 'sub') !== false && stripos($name, 'category') !== false) {

            $sheet = $spreadsheet->getSheet($i);
            $rows  = $sheet->toArray(null, true, true, true);

            $headerRow = null;
            foreach ($rows as $rIdx => $row) {
                foreach ($row as $cell) {
                    if (stripos($cell, 'category') !== false) {
                        $headerRow = $rIdx;
                        break 2;
                    }
                }
            }

            if (!$headerRow) return [];

            $header = $rows[$headerRow];
            $catCol = null;
            $pctCol = null;

            foreach ($header as $col => $val) {
                if (stripos($val, 'category') !== false) $catCol = $col;
                if (stripos($val, 'share') !== false || stripos($val, '%') !== false) $pctCol = $col;
            }

            if (!$catCol || !$pctCol) return [];

            $out = [];
            for ($i = $headerRow + 1; $i <= count($rows); $i++) {
                $cat = trim($rows[$i][$catCol] ?? '');
                $pct = trim($rows[$i][$pctCol] ?? '');

                if ($cat === '' || !is_numeric($pct)) continue;

                $out[] = [
                    'asset' => $cat,
                    'pct'   => (float)$pct
                ];
            }

            return $out;
        }
    }

    return [];
}


function extractGlobalEquityFromScriptSheet(string $filePath): float
{
    $spreadsheet = IOFactory::load($filePath);

    foreach ($spreadsheet->getSheetNames() as $i => $sheetName) {

        if (
            stripos($sheetName, 'scheme') !== false &&
            stripos($sheetName, 'scrip') !== false
        ) {

            $sheet = $spreadsheet->getSheet($i);
            $rows  = $sheet->toArray(null, true, true, true);

            $shareCol = null;

            foreach ($rows as $row) {
                foreach ($row as $col => $val) {
                    if (stripos($val, 'share') !== false) {
                        $shareCol = $col;
                        break 2;
                    }
                }
            }

            if (!$shareCol) return 0;

            foreach ($rows as $row) {

                $rowText = implode(' ', $row);

                if (
                    stripos($rowText, 'equity') !== false &&
                    stripos($rowText, 'global') !== false &&
                    stripos($rowText, 'total') !== false
                ) {
                    return (float)($row[$shareCol] ?? 0);
                }
            }
        }
    }

    return 0;
}



$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$myId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0);
$currentUserId = $myId;
$filter      = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$ownerFilter = isset($_GET['owner_filter']) ? trim($_GET['owner_filter']) : 'all';

// ===============================
// REVIEW CYCLE RESOLUTION (SINGLE SOURCE OF TRUTH)
// ===============================

if (isset($_GET['reset'])) {
    $_GET = [];
}


function getCurrentReviewCycle(): string
{
    $month = (int)date('n');
    if (in_array($month, [1, 4, 7, 10])) return 'RJ';
    if (in_array($month, [2, 5, 8, 11])) return 'RF';
    return 'RM';
}

$systemCurrentCycle = getCurrentReviewCycle();

if (isset($_GET['reset'])) {
    $cycleFilter = '';
} elseif (isset($_GET['from_customer_list'])) {
    $cycleFilter = '';
} else {
    $cycleFilter = $_GET['cycle_filter'] ?? $systemCurrentCycle;
}



$successMessage = '';
$errorMessage   = '';



require_once 'db_config.php';
$pdo = getPdo();
$pdoSlides = getSlidesPdo();

// ============================================================
// ONE-TIME BACKFILL: For any existing client rows that have
// NULL in last_review_date / last_meeting_date /
// prev_modifications_action / prev_meeting_comments,
// populate them from the most recent prior record for that
// client name. This fixes records created before the snapshot
// logic was added. Runs only on GET (page load), not on POST.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['search_client'])) {
    try {
        // Find all rows missing snapshot data that have a prior record
        $backfillStmt = $pdo->query("
            SELECT c.id, c.name, c.created_at
            FROM clients c
            WHERE (
                c.last_review_date IS NULL
                OR c.last_meeting_date IS NULL
                OR c.prev_modifications_action IS NULL
                OR c.prev_meeting_comments IS NULL
            )
            AND EXISTS (
                SELECT 1 FROM clients p
                WHERE p.name = c.name
                  AND p.id  != c.id
                  AND p.report_state != 'pending'
                  AND p.created_at < c.created_at
            )
            LIMIT 50
        ");
        $toBackfill = $backfillStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($toBackfill)) {
            // FIX: Both last_review_datetime and last_meeting_datetime are derived
            // from the SAME row (same ORDER BY), so they always stay in sync.
            $findPrevStmt = $pdo->prepare("
                SELECT
                    review_sent_date,
                    meeting_date,
                    modifications_action,
                    meeting_comments,
                    created_at,
                    updated_at,
                    CASE
                        WHEN report_state = 'sent' AND review_sent_date IS NOT NULL
                            THEN CONCAT(review_sent_date, ' ', TIME(created_at))
                        WHEN report_state = 'sent'
                            THEN created_at
                        WHEN review_sent_date IS NOT NULL
                            THEN CONCAT(review_sent_date, ' ', TIME(COALESCE(updated_at, created_at)))
                        WHEN updated_at IS NOT NULL
                            THEN updated_at
                        ELSE created_at
                    END AS last_review_datetime,
                    CASE
                        WHEN meeting_date IS NOT NULL AND report_state = 'sent'
                            THEN CONCAT(meeting_date, ' ', TIME(created_at))
                        WHEN meeting_date IS NOT NULL
                            THEN CONCAT(meeting_date, ' ', TIME(COALESCE(updated_at, created_at)))
                        ELSE NULL
                    END AS last_meeting_datetime
                FROM clients
                WHERE name = ?
                  AND id  != ?
                  AND report_state != 'pending'
                  AND id < ?
                ORDER BY
                    (report_state = 'sent') DESC,
                    (review_sent_date IS NOT NULL) DESC,
                    id DESC
                LIMIT 1
            ");

            $updateSnapshotStmt = $pdo->prepare("
                UPDATE clients
                SET last_review_date          = ?,
                    last_meeting_date         = ?,
                    prev_modifications_action = ?,
                    prev_meeting_comments     = ?
                WHERE id = ?
            ");

            foreach ($toBackfill as $row) {
                $findPrevStmt->execute([$row['name'], $row['id'], $row['id']]);
                $prev = $findPrevStmt->fetch(PDO::FETCH_ASSOC);
                if ($prev) {
                    $updateSnapshotStmt->execute([
                        $prev['last_review_datetime']  ?? null,
                        $prev['last_meeting_datetime'] ?? null,
                        $prev['modifications_action']  ?? null,
                        $prev['meeting_comments']      ?? null,
                        $row['id'],
                    ]);
                }
            }
        }
    } catch (Throwable $bfe) {
        // Backfill is non-critical; swallow errors silently
    }
}

// ============================================================
// AJAX: Save inline fields (sip, review_sent_date, meeting_date,
//       modifications_action, meeting_comments)
//       BLOCKED if report_state = 'sent'
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_review_fields') {
    header('Content-Type: application/json');
    try {
        $clientId          = (int)($_POST['client_id'] ?? 0);
        $field             = $_POST['field'] ?? '';
        $value             = $_POST['value'] ?? '';

        $allowedFields = [
            'sip_amount_lakhs',
            'review_sent_date',
            'meeting_date',
            'modifications_action',
            'meeting_comments',
        ];

        if (!$clientId || !in_array($field, $allowedFields, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field or client id']);
            exit;
        }

        // Block saves for sent reviews
        $checkState = $pdo->prepare("SELECT report_state FROM clients WHERE id = ? LIMIT 1");
        $checkState->execute([$clientId]);
        $stateRow = $checkState->fetch(PDO::FETCH_ASSOC);
        if ($stateRow && $stateRow['report_state'] === 'sent') {
            echo json_encode(['success' => false, 'error' => 'This review has been sent and cannot be edited.']);
            exit;
        }

        // For date fields, allow empty → NULL
        $bindValue = ($value === '') ? null : $value;

        $stmt = $pdo->prepare("UPDATE clients SET `$field` = ? WHERE id = ?");
        $stmt->execute([$bindValue, $clientId]);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// EXISTING: File upload handler
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['client_files'])) {

    try {


        $baseUploadDir = __DIR__ . '/uploads/tmp_' . session_id();
        if (!is_dir($baseUploadDir)) {
            mkdir($baseUploadDir, 0777, true);
        }

        $pv  = [];
        $aa  = [];
        $rst = [];
        $ps  = [];
        $pdfGoal     = [];
        $attachments = [];
        $allocationExcelPath = null;



        $fileCount = count($_FILES['client_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $error = $_FILES['client_files']['error'][$i];
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $name     = $_FILES['client_files']['name'][$i];
            $tmpPath  = $_FILES['client_files']['tmp_name'][$i];
            $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'xls', 'xlsx', 'csv'];
            if (!in_array($ext, $allowedExts, true)) {
                continue;
            }
            $destName = uniqid('upload_', true) . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);
            $destPath = $baseUploadDir . '/' . $destName;
            if (!move_uploaded_file($tmpPath, $destPath)) {
                continue;
            }

            $nameLower = strtolower($name);

            try {
                if ($ext === 'pdf') {
                    if (strpos($nameLower, 'goalstatusreport') !== false) {
                        $pdfGoal = parseGoalStatusPdf($destPath);
                    } else {
                        $attachments[] = ['path' => $destPath, 'name' => $name];
                    }
                } else {
                    if (strpos($nameLower, 'valuation') !== false) {
                        mergeClientArrays($pv, parsePortfolioValuation($destPath));
                    } elseif (strpos($nameLower, 'allocation') !== false) {
                        $allocationExcelPath = $destPath;
                        mergeClientArrays($aa, parseAllocationAnalysis($destPath));
                    } elseif (strpos($nameLower, 'running') !== false || strpos($nameLower, 'systematic') !== false || strpos($nameLower, 'sip') !== false) {
                        mergeClientArrays($rst, parseRunningSystematicTransactions($destPath));
                    } elseif (strpos($nameLower, 'summary') !== false) {
                        mergeClientArrays($ps, parsePortfolioSummary($destPath));
                    } else {
                        $attachments[] = ['path' => $destPath, 'name' => $name];
                    }
                }
            } catch (Throwable $parseErr) {
                $attachments[] = ['path' => $destPath, 'name' => $name];
            }
        }

        $allClientReports = buildClientReports($pv, $aa, $rst, $ps, $pdfGoal);

        if (empty($allClientReports)) {
            throw new Exception('No client data could be parsed from the uploaded files.');
        }

        $expectedClientName = trim($_POST['expected_client_name'] ?? '');

        $postedCycle = $_POST['review_cycle'] ?? '';

        if ($postedCycle !== $systemCurrentCycle) {
            throw new Exception(
                "Uploads are allowed only for the current review cycle."
            );
        }



        $uploadedNames = array_values(array_unique(array_filter(array_map(
            fn($c) => trim($c['name'] ?? ''),
            $allClientReports
        ))));

        if (empty($uploadedNames)) {
            throw new Exception('Unable to detect client name from uploaded files.');
        }

        if (count($uploadedNames) > 1) {
            throw new Exception("Please upload {$expectedClientName} files only.");
        }

        if (strcasecmp($uploadedNames[0], $expectedClientName) !== 0) {
            throw new Exception("Please upload {$expectedClientName} files only.");
        }


        $currentMonthYear = date('F Y');

        $checkExistingReview = $pdo->prepare('
            SELECT id, review_attempt 
            FROM clients 
            WHERE name = :name 
            AND month_year = :month_year 
            ORDER BY review_attempt DESC 
            LIMIT 1
        ');

        // =====================================================================
        // FIX: Use updated_at = updated_at (no-op) so that marking the previous
        // row as non-latest does NOT change its updated_at timestamp.
        // Previously this UPDATE was omitting updated_at which caused MySQL's
        // ON UPDATE CURRENT_TIMESTAMP to fire and corrupt the old row's timestamp.
        // =====================================================================
        $markPreviousAsNotLatest = $pdo->prepare('
            UPDATE clients 
            SET is_latest = FALSE,
                updated_at = updated_at
            WHERE name = :name 
            AND month_year = :month_year
        ');

        $insertClient = $pdo->prepare('INSERT INTO clients
            (name, email, as_on, total_amount, aum, profit, cagr, xirr, absolute_return,
             total_goal_current, total_goal_target, total_sip,
             greeting_prefix, intro_text, closing_text, rationale_text,
             created_by, report_state, assigned_to, month_year, review_cycle,
             is_latest, previous_version_id, review_attempt,
             last_review_date, last_meeting_date, prev_modifications_action, prev_meeting_comments)
            VALUES
            (:name, :email, :as_on, :total_amount, :aum, :profit, :cagr, :xirr, :absolute_return,
             :total_goal_current, :total_goal_target, :total_sip,
             :greeting_prefix, :intro_text, :closing_text, :rationale_text,
             :created_by, :report_state, :assigned_to, :month_year, :review_cycle,
             :is_latest, :previous_version_id, :review_attempt,
             :last_review_date, :last_meeting_date, :prev_modifications_action, :prev_meeting_comments)');

        $stmtGoal = $pdo->prepare('INSERT INTO client_goals
            (client_id, goal, goal_date, current_amount, sip_swp, target_amount, projected, shortfall, completion, status)
            VALUES
            (:client_id, :goal, :goal_date, :current_amount, :sip_swp, :target_amount, :projected, :shortfall, :completion, :status)');

        $stmtAlloc = $pdo->prepare('INSERT INTO client_allocations (client_id, asset, share_pct)
            VALUES (:client_id, :asset, :share_pct)');

        $stmtScheme = $pdo->prepare('INSERT INTO client_schemes
            (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount)
            VALUES
            (:client_id, :scheme_name, :sip_swp, :current_value, :action_step, :recommended_scheme, :recommended_amount)');

        $stmtAnnex = $pdo->prepare('INSERT INTO client_annexures (client_id, line_text) VALUES (:client_id, :line_text)');

        $pdo->beginTransaction();

        $firstClientId = 0;
        foreach ($allClientReports as $clientData) {
            $email = trim($clientData['email'] ?? '');
            $clientName = trim($clientData['name'] ?? '');
            if ($clientName === '') {
                continue;
            }

            $checkExistingReview->execute([
                ':name' => $clientName,
                ':month_year' => $currentMonthYear
            ]);
            $existingReview = $checkExistingReview->fetch(PDO::FETCH_ASSOC);

            $reviewAttempt = 1;
            $previousVersionId = null;

            if ($existingReview) {
                $reviewAttempt = (int)$existingReview['review_attempt'] + 1;
                $previousVersionId = $existingReview['id'];

                $markPreviousAsNotLatest->execute([
                    ':name' => $clientName,
                    ':month_year' => $currentMonthYear
                ]);
            }

            $totals  = $clientData['current']['totals'] ?? ['purchase' => 0, 'current' => 0, 'profit' => 0, 'cagr_weighted' => 0, 'xirr_weighted' => 0, 'absolute_return' => 0];
            $summary = $clientData['current']['summary'] ?? null;

            $totalAmount    = $totals['current'] ?? 0;
            $aum = $totalAmount > 0 ? ($totalAmount / 10000000) : 0;

            $profit         = $summary['profit'] ?? ($totals['profit'] ?? 0);
            $cagr           = $totals['cagr_weighted'] ?? 0;
            $xirr           = $summary['xirr'] ?? ($totals['xirr_weighted'] ?? 0);
            $absoluteReturn = $totals['absolute_return'] ?? 0;

            $goals      = $clientData['goals'] ?? [];
            $allocation = $clientData['allocation'] ?? [];
            $schemes    = $clientData['schemes'] ?? [];
            $asOn       = $clientData['as_on'] ?? '';

            $reviewCycle = $_POST['review_cycle'] ?? 'RJ';

            $totalSip         = 0;
            $totalGoalCurrent = 0;
            $totalGoalTarget  = 0;
            foreach ($goals as $g) {
                $totalSip         += (float)($g['running_sip'] ?? 0);
                $totalGoalCurrent += (float)($g['current_value'] ?? 0);
                $totalGoalTarget  += (float)($g['target_amount'] ?? 0);
            }

            // =====================================================================
            // FIX: Both last_review_datetime and last_meeting_datetime are derived
            // from the SAME single row (identical ORDER BY), so they always stay
            // in sync and come from the same review record.
            // =====================================================================
            $stmtLastReview = $pdo->prepare("
                SELECT
                    review_sent_date,
                    meeting_date,
                    modifications_action,
                    meeting_comments,
                    updated_at,
                    created_at,
                    CASE
                        WHEN report_state = 'sent' AND review_sent_date IS NOT NULL
                            THEN CONCAT(review_sent_date, ' ', TIME(created_at))
                        WHEN report_state = 'sent'
                            THEN created_at
                        WHEN review_sent_date IS NOT NULL
                            THEN CONCAT(review_sent_date, ' ', TIME(COALESCE(updated_at, created_at)))
                        WHEN updated_at IS NOT NULL
                            THEN updated_at
                        ELSE created_at
                    END AS last_review_datetime,
                    CASE
                        WHEN meeting_date IS NOT NULL AND report_state = 'sent'
                            THEN CONCAT(meeting_date, ' ', TIME(created_at))
                        WHEN meeting_date IS NOT NULL
                            THEN CONCAT(meeting_date, ' ', TIME(COALESCE(updated_at, created_at)))
                        ELSE NULL
                    END AS last_meeting_datetime
                FROM clients
                WHERE name = ?
                  AND report_state != 'pending'
                  AND id != IFNULL(?, 0)
                ORDER BY
                    (report_state = 'sent') DESC,
                    (review_sent_date IS NOT NULL) DESC,
                    id DESC
                LIMIT 1
            ");
            $pendingRowId = (int)($_POST['expected_client_id'] ?? 0);
            $stmtLastReview->execute([$clientName, $pendingRowId]);
            $lastReview = $stmtLastReview->fetch(PDO::FETCH_ASSOC);

            $lastReviewDate          = $lastReview['last_review_datetime']  ?? null;
            $lastMeetingDate         = $lastReview['last_meeting_datetime'] ?? null;
            $prevModificationsAction = $lastReview['modifications_action']  ?? null;
            $prevMeetingComments     = $lastReview['meeting_comments']      ?? null;

            $insertClient->execute([
                ':name'               => $clientName,
                ':email'              => $email,
                ':as_on'              => $asOn,
                ':total_amount'       => $totalAmount,
                ':aum'                => $aum,
                ':profit'             => $profit,
                ':cagr'               => $cagr,
                ':xirr'               => $xirr,
                ':absolute_return'    => $absoluteReturn,
                ':total_goal_current' => $totalGoalCurrent,
                ':total_goal_target'  => $totalGoalTarget,
                ':total_sip'          => $totalSip,
                ':greeting_prefix'    => DEFAULT_GREETING,
                ':intro_text'         => DEFAULT_INTRO,
                ':closing_text'       => DEFAULT_CLOSING,
                ':rationale_text'     => DEFAULT_RATIONALE,
                ':created_by'         => $currentUserId,
                ':report_state'       => 'draft',
                ':assigned_to'        => $currentUserId,
                ':month_year'         => $currentMonthYear,
                ':review_cycle'       => $reviewCycle,
                ':is_latest'          => true,
                ':previous_version_id'       => $previousVersionId,
                ':review_attempt'            => $reviewAttempt,
                ':last_review_date'          => $lastReviewDate,
                ':last_meeting_date'         => $lastMeetingDate,
                ':prev_modifications_action' => $prevModificationsAction,
                ':prev_meeting_comments'     => $prevMeetingComments,
            ]);

            $clientId = (int)$pdo->lastInsertId();

            if ($allocationExcelPath && file_exists($allocationExcelPath)) {

                $pdoSlides->prepare("DELETE FROM slide8 WHERE client_id = ?")
                    ->execute([$clientId]);

                $subCats = extractSubCategoryAllocation($allocationExcelPath);

                foreach ($subCats as $row) {

                    $assetRaw = trim($row['asset']);

                    if (stripos($assetRaw, 'grand total') !== false) {
                        continue;
                    }

                    $asset = ucwords($row['asset']);
                    $pct   = (float)$row['pct'];

                    $stmt = $pdoSlides->prepare("
                        INSERT INTO slide8
                        (client_id, asset, current_pct, recommended_pct, updated_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");

                    $stmt->execute([
                        $clientId,
                        $asset,
                        $pct,
                        $pct
                    ]);
                }

                $globalPct = extractGlobalEquityFromScriptSheet($allocationExcelPath);

                $pdoSlides->prepare("DELETE FROM slide10 WHERE client_id = ?")
                    ->execute([$clientId]);

                $pdoSlides->prepare("
                    INSERT INTO slide10
                    (client_id, current_pct, recommended_pct, updated_at)
                    VALUES (?, ?, ?, NOW())
                ")->execute([
                    $clientId,
                    $globalPct,
                    0
                ]);
            }




            if ($firstClientId === 0 && $clientId > 0) {
                $firstClientId = $clientId;
            }

            foreach ($goals as $g) {
                $projectedVal = (float)($g['projected'] ?? 0);
                $targetVal    = (float)($g['target_amount'] ?? 0);
                $shortfallVal = (float)($g['shortfall'] ?? 0);
                $statusCalc   = ($shortfallVal > 0) ? 'Invest More' : 'On Track';

                $stmtGoal->execute([
                    ':client_id'      => $clientId,
                    ':goal'           => $g['goal'] ?? '',
                    ':goal_date'      => $g['goal_date'] ?? '',
                    ':current_amount' => $g['current_value'] ?? 0,
                    ':sip_swp'        => $g['running_sip'] ?? 0,
                    ':target_amount'  => $targetVal,
                    ':projected'      => $projectedVal,
                    ':shortfall'      => $g['shortfall'] ?? 0,
                    ':completion'     => $g['completion'] ?? 0,
                    ':status'         => $statusCalc,
                ]);
            }

            foreach ($allocation as $asset => $share) {
                $stmtAlloc->execute([
                    ':client_id' => $clientId,
                    ':asset'     => $asset,
                    ':share_pct' => $share,
                ]);
            }

            foreach ($schemes as $schemeData) {
                $stmtScheme->execute([
                    ':client_id'          => $clientId,
                    ':scheme_name'        => $schemeData['scheme'] ?? '',
                    ':sip_swp'            => $schemeData['sip_swp'] ?? 0,
                    ':current_value'      => $schemeData['current_value'] ?? 0,
                    ':action_step'        => 'Continue',
                    ':recommended_scheme' => null,
                    ':recommended_amount' => 0,
                ]);
            }

            $clientAttachmentsDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            if (!is_dir($clientAttachmentsDir)) {
                mkdir($clientAttachmentsDir, 0777, true);
            }

            $annexLines = [];
            foreach ($attachments as $att) {
                $annexLines[] = $att['name'];
                $newPath = $clientAttachmentsDir . '/' . basename($att['name']);
                $counter = 1;
                while (file_exists($newPath)) {
                    $newPath = $clientAttachmentsDir . '/' . $counter . '_' . basename($att['name']);
                    $counter++;
                }
                rename($att['path'], $newPath);
            }

            foreach ($annexLines as $line) {
                $stmtAnnex->execute([
                    ':client_id' => $clientId,
                    ':line_text' => $line,
                ]);
            }
        }

        $pdo->commit();
        if (is_dir($baseUploadDir)) {
            $files = glob("$baseUploadDir/*");
            if ($files) array_map('unlink', $files);
            @rmdir($baseUploadDir);
        }


        if ($firstClientId > 0) {
            header('Location: view_report.php?id=' . $firstClientId . '&initial_save=1');
            exit;
        }

        $successMessage = 'Reports uploaded successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = $e->getMessage();
    }
}



$deleteMode = false;
$reassignMode = false;
$showCheckboxes = false;

if (isset($_GET['mode'])) {
    if ($_GET['mode'] === 'delete') {
        $deleteMode = true;
        $showCheckboxes = true;
    } elseif ($_GET['mode'] === 'reassign') {
        $reassignMode = true;
        $showCheckboxes = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    if ($_POST['action_type'] === 'reassign') {
        try {
            $newOwnerId = 0;
            if (isset($_POST['new_owner_id'])) {
                $newOwnerId = (int)$_POST['new_owner_id'];
            } elseif (isset($_POST['new_owner'])) {
                $newOwnerId = (int)$_POST['new_owner'];
            }
            $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];

            if ($newOwnerId <= 0) {
                $errorMessage = "Please select a valid user to assign to.";
            } elseif (empty($selectedIds)) {
                $errorMessage = "Please select at least one client to reassign.";
            } else {
                $selectedIds = array_filter(array_map('intval', $selectedIds));

                if (!empty($selectedIds)) {
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $updateStmt = $pdo->prepare("UPDATE clients SET review_assigned_to = ?, updated_at = NOW() WHERE id IN ($placeholders)");

                    $params = array_merge([$newOwnerId], $selectedIds);
                    $updateStmt->execute($params);

                    $affectedRows = $updateStmt->rowCount();
                    $successMessage = "Successfully assigned reviewer for $affectedRows client(s).";
                }
            }
        } catch (Exception $e) {
            $errorMessage = "Error during reassignment: " . $e->getMessage();
        }
    }

    if ($_POST['action_type'] === 'delete') {
        try {
            $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];

            if (empty($selectedIds)) {
                $errorMessage = "Please select at least one client to delete.";
            } else {
                $selectedIds = array_filter(array_map('intval', $selectedIds));

                if (!empty($selectedIds)) {
                    $pdo->beginTransaction();

                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

                    $tables = ['client_goals', 'client_allocations', 'client_schemes', 'client_annexures'];
                    foreach ($tables as $table) {
                        $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE client_id IN ($placeholders)");
                        $deleteStmt->execute($selectedIds);
                    }

                    $deleteClientStmt = $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
                    $deleteClientStmt->execute($selectedIds);

                    $affectedRows = $deleteClientStmt->rowCount();

                    $pdo->commit();
                    $successMessage = "Successfully deleted $affectedRows client(s).";
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = "Error during deletion: " . $e->getMessage();
        }
    }
}

// --- BEGIN: Reassigned summary logic ---
$showReassignedSummary = false;
$reassignedSummary = [];
$isAdmin = (strtolower($currentUser['username'] ?? '') === strtolower(getenv('ADMIN_USERNAME') ?: 'admin'));



$summaryUserId = $myId;
if ($isAdmin && isset($_GET['owner_filter']) && ctype_digit($_GET['owner_filter'])) {
    $summaryUserId = (int)$_GET['owner_filter'];
}

if ($isAdmin || $myId) {
    $showReassignedSummary = true;

    $summaryWhereParts = [];
    $summaryParams = [];

    $summaryWhereParts[] = "c.assigned_to <> c.review_assigned_to";

    if ($isAdmin) {
        if ($ownerFilter === 'mine') {
            $summaryWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
            $summaryParams[] = $myId;
            $summaryParams[] = $myId;
        } elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
            $summaryWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
            $summaryParams[] = (int)$ownerFilter;
            $summaryParams[] = (int)$ownerFilter;
        }
    } else {
        $summaryWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
        $summaryParams[] = $myId;
        $summaryParams[] = $myId;
    }

    if ($cycleFilter !== '') {
        $summaryWhereParts[] = "c.review_cycle = ?";
        $summaryParams[] = $cycleFilter;
    }

    if ($filter !== '' && in_array($filter, ['pending', 'draft', 'ready', 'reviewed', 'sent'])) {
        $summaryWhereParts[] = "c.report_state = ?";
        $summaryParams[] = $filter;
    }

    $summaryWhereClause = $summaryWhereParts ? 'WHERE ' . implode(' AND ', $summaryWhereParts) : '';

    $stmtReassigned = $pdo->prepare("
        SELECT u.username, COUNT(c.id) as total
        FROM clients c
        INNER JOIN users u ON u.id = c.review_assigned_to
        $summaryWhereClause
        GROUP BY u.username
        ORDER BY u.username
    ");
    $stmtReassigned->execute($summaryParams);
    $reassignedSummary = $stmtReassigned->fetchAll(PDO::FETCH_ASSOC);
}
// --- END: Reassigned summary logic ---

$q           = isset($_GET['q']) ? trim($_GET['q']) : '';


$sortBy      = isset($_GET['sort']) ? trim($_GET['sort']) : 'updated_at';
$sortOrder   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit       = 20;
$offset      = ($page - 1) * $limit;

$whereParts = [];
$params = [];


if (!$isAdmin) {
    $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $params[] = $myId;
    $params[] = $myId;
}

if ($q !== '') {
    $whereParts[] = "(c.name LIKE ? OR c.as_on LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($filter !== '' && in_array($filter, ['pending', 'draft', 'ready', 'reviewed', 'sent'])) {
    $whereParts[] = "c.report_state = ?";
    $params[] = $filter;
}
$monthFilter = $_GET['month_filter'] ?? '';
$yearFilter  = $_GET['year_filter'] ?? '';
$meetingFilter = $_GET['meeting_filter'] ?? 'all';

if ($cycleFilter !== '') {
    $whereParts[] = "c.review_cycle = ?";
    $params[] = $cycleFilter;
}

if ($monthFilter !== '') {
    $whereParts[] = "SUBSTRING_INDEX(c.month_year, ' ', 1) = ?";
    $params[] = $monthFilter;
}

if ($yearFilter !== '') {
    $whereParts[] = "SUBSTRING_INDEX(c.month_year, ' ', -1) = ?";
    $params[] = $yearFilter;
}

// ───────────── Meeting Fixed Filter ─────────────
if ($meetingFilter === 'fixed') {
    $whereParts[] = "c.meeting_date IS NOT NULL";
} elseif ($meetingFilter === 'not_fixed') {
    $whereParts[] = "c.meeting_date IS NULL";
}

if ($isAdmin) {
    if ($ownerFilter === 'mine') {
        $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
        $params[] = $myId;
        $params[] = $myId;
    } elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
        $whereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
        $params[] = (int)$ownerFilter;
        $params[] = (int)$ownerFilter;
    }
}
$whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// --- CONTEXTUAL COUNTS FOR DROPDOWNS ---
$cycleTotals = [];
$cycleCountStmt = $pdo->prepare("SELECT c.review_cycle, COUNT(*) as total FROM clients c $whereClause GROUP BY c.review_cycle");
$cycleCountStmt->execute($params);
foreach ($cycleCountStmt as $row) {
    $cycleTotals[$row['review_cycle']] = (int)$row['total'];
}
$allCyclesTotal = array_sum($cycleTotals);

$ownerWhereParts = [];
$ownerParams = [];
if ($cycleFilter !== '') {
    $ownerWhereParts[] = "c.review_cycle = ?";
    $ownerParams[] = $cycleFilter;
}
$whereOwner = $ownerWhereParts ? 'WHERE ' . implode(' AND ', $ownerWhereParts) : '';

$ownerTotals = [];
$ownerCountStmt = $pdo->prepare("SELECT u.id, u.username, COUNT(c.id) as total 
    FROM users u 
    INNER JOIN clients c ON (c.assigned_to = u.id OR c.review_assigned_to = u.id) $whereOwner 
    GROUP BY u.id, u.username HAVING total > 0");
$ownerCountStmt->execute($ownerParams);
foreach ($ownerCountStmt as $row) {
    $ownerTotals[$row['id']] = [
        'username' => $row['username'],
        'total' => (int)$row['total']
    ];
}

$stateWhereParts = [];
$stateParams = [];
if ($cycleFilter !== '') {
    $stateWhereParts[] = "c.review_cycle = ?";
    $stateParams[] = $cycleFilter;
}
if ($ownerFilter === 'mine') {
    $stateWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $stateParams[] = $myId;
    $stateParams[] = $myId;
} elseif ($ownerFilter !== 'all' && ctype_digit($ownerFilter)) {
    $stateWhereParts[] = "(c.assigned_to = ? OR c.review_assigned_to = ?)";
    $stateParams[] = (int)$ownerFilter;
    $stateParams[] = (int)$ownerFilter;
}
$whereState = $stateWhereParts ? 'WHERE ' . implode(' AND ', $stateWhereParts) : '';

$statusTotals = [];
$statusCountStmt = $pdo->prepare("
    SELECT c.report_state, COUNT(DISTINCT c.name) as total 
    FROM clients c $whereState 
    GROUP BY c.report_state HAVING total > 0
");
$statusCountStmt->execute($stateParams);
foreach ($statusCountStmt as $row) {
    $statusTotals[$row['report_state']] = (int)$row['total'];
}
$allStatesTotal = array_sum($statusTotals);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM clients c {$whereClause}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$stmtDistinctNames = $pdo->prepare("SELECT COUNT(DISTINCT c.name) FROM clients c {$whereClause}");
$stmtDistinctNames->execute($params);
$totalDistinctNames = (int)$stmtDistinctNames->fetchColumn();

// Validate sort column to prevent SQL injection
$allowedSorts = ['id', 'name', 'updated_at', 'priority', 'report_state', 'aum'];
$sortColumn = in_array($sortBy, $allowedSorts) ? $sortBy : 'updated_at';

if ($sortColumn === 'priority') {
    $orderByClause = "ORDER BY CASE c.priority 
        WHEN 'High' THEN 1 
        WHEN 'Normal' THEN 2 
        WHEN 'Low' THEN 3 
        ELSE 4 END {$sortOrder}, c.id DESC";
} elseif ($sortColumn === 'aum') {
    $orderByClause = "ORDER BY CAST(c.aum AS DECIMAL(15,2)) {$sortOrder}, c.id DESC";
} else {
    $orderByClause = "ORDER BY c.{$sortColumn} {$sortOrder}, c.id DESC";
}

// ============================================================
// MAIN QUERY
// - updated_at: shows the actual last-updated timestamp of each row
//   (not shared between reviews)
// - FIX: For 'sent' rows, last_review_date subquery uses created_at
//   for the time component, not updated_at, to avoid corruption from
//   the is_latest flag UPDATE touching updated_at via ON UPDATE CURRENT_TIMESTAMP
// - FIX: last_meeting_date subquery now uses IDENTICAL ORDER BY as
//   last_review_date so both columns always come from the SAME row.
//   Previously last_meeting_date ordered by (meeting_date IS NOT NULL)
//   which could select a DIFFERENT record than last_review_date.
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        c.id, c.name, c.as_on, c.created_at, c.updated_at, c.total_amount, c.profit,
        c.aum,
        c.report_state, c.review_not_ok, c.review_comment, c.created_by, c.assigned_to, c.review_assigned_to,
        c.priority, c.meeting_status, c.meeting_remarks,
        c.review_cycle,
        c.sip_amount_lakhs,
        c.review_sent_date,
        c.meeting_date,
        c.modifications_action,
        c.meeting_comments,
        c.previous_version_id,
        -- Fetch the report_state of the previous version
        (
            SELECT p.report_state
            FROM clients p
            WHERE p.id = c.previous_version_id
            LIMIT 1
        ) AS prev_version_state,

        -- ── LAST REVIEW DATE ────────────────────────────────────────────────
        -- Uses: sent > review_sent_date present > id DESC
        COALESCE(
            c.last_review_date,
            (
                SELECT
                    CASE
                        WHEN p.report_state = 'sent' AND p.review_sent_date IS NOT NULL
                            THEN CONCAT(p.review_sent_date, ' ', TIME(p.created_at))
                        WHEN p.report_state = 'sent'
                            THEN p.created_at
                        WHEN p.review_sent_date IS NOT NULL
                            THEN CONCAT(p.review_sent_date, ' ', TIME(COALESCE(p.updated_at, p.created_at)))
                        WHEN p.updated_at IS NOT NULL
                            THEN p.updated_at
                        ELSE p.created_at
                    END
                FROM clients p
                WHERE p.name = c.name
                  AND p.id != c.id
                  AND p.report_state != 'pending'
                  AND p.id < c.id
                ORDER BY
                    (p.report_state = 'sent') DESC,
                    (p.review_sent_date IS NOT NULL) DESC,
                    p.id DESC
                LIMIT 1
            )
        ) AS last_review_date,

        -- ── LAST MEETING DATE ────────────────────────────────────────────────
        -- FIX: Uses IDENTICAL ORDER BY as last_review_date (sent > review_sent_date > id DESC)
        -- so that both columns are ALWAYS sourced from the SAME review record.
        -- Previously this used (meeting_date IS NOT NULL) in ORDER BY which could
        -- select a different record, causing last_review_date and last_meeting_date
        -- to show data from two different reviews.
        COALESCE(
            c.last_meeting_date,
            (
                SELECT
                    CASE
                        WHEN p.meeting_date IS NOT NULL AND p.report_state = 'sent'
                            THEN CONCAT(p.meeting_date, ' ', TIME(p.created_at))
                        WHEN p.meeting_date IS NOT NULL
                            THEN CONCAT(p.meeting_date, ' ', TIME(COALESCE(p.updated_at, p.created_at)))
                        ELSE NULL
                    END
                FROM clients p
                WHERE p.name = c.name
                  AND p.id != c.id
                  AND p.report_state != 'pending'
                  AND p.id < c.id
                ORDER BY
                    (p.report_state = 'sent') DESC,
                    (p.review_sent_date IS NOT NULL) DESC,
                    p.id DESC
                LIMIT 1
            )
        ) AS last_meeting_date,

        COALESCE(
            c.prev_modifications_action,
            (
                SELECT p.modifications_action
                FROM clients p
                WHERE p.name = c.name
                  AND p.id != c.id
                  AND p.report_state != 'pending'
                  AND p.id < c.id
                ORDER BY
                    (p.report_state = 'sent') DESC,
                    p.id DESC
                LIMIT 1
            )
        ) AS prev_modifications_action,

        COALESCE(
            c.prev_meeting_comments,
            (
                SELECT p.meeting_comments
                FROM clients p
                WHERE p.name = c.name
                  AND p.id != c.id
                  AND p.report_state != 'pending'
                  AND p.id < c.id
                ORDER BY
                    (p.report_state = 'sent') DESC,
                    p.id DESC
                LIMIT 1
            )
        ) AS prev_meeting_comments,

        creator.username  AS created_by_username,
        rm.username       AS rm_username,
        reviewer.username AS reviewer_username

    FROM clients c
    LEFT JOIN users creator  ON c.created_by          = creator.id
    LEFT JOIN users rm       ON c.assigned_to          = rm.id
    LEFT JOIN users reviewer ON c.review_assigned_to   = reviewer.id

    {$whereClause}
    {$orderByClause}
    LIMIT ? OFFSET ?
");

$paramsData = $params;
$paramsData[] = $limit;
$paramsData[] = $offset;

$stmt->execute($paramsData);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users for reassignment dropdown
$allUsersStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// --- AJAX endpoint for client name search ---
if (isset($_GET['search_client']) && isset($_GET['q'])) {
    require_once 'db_config.php';
    $pdo = getPdo();
    $q = trim($_GET['q']);
    $stmt = $pdo->prepare("SELECT DISTINCT name FROM clients WHERE name LIKE ? ORDER BY name ASC LIMIT 10");
    $stmt->execute(["%$q%"]);
    $clients = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clients[] = $row['name'];
    }
    header('Content-Type: application/json');
    echo json_encode($clients);
    exit;
}

// Helper: format a date/datetime value for display
function fmtDate(?string $d, string $fmt = 'd-M-Y'): string
{
    if (empty($d)) return '—';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : '—';
}

// Helper: format datetime showing both date and time
function fmtDateTime(?string $d): string
{
    if (empty($d)) return '—';
    $ts = strtotime($d);
    if (!$ts) return '—';
    return date('d-M-Y', $ts) . '<br><span style="color:#999;font-size:11px;">' . date('h:i A', $ts) . '</span>';
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Stored Client Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/view_saved_reports.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body class="<?php echo ($deleteMode || $reassignMode) ? 'action-mode-active' : ''; ?>">
    <?php include 'navbar.php'; ?>

    <!-- Save toast notification -->
    <div id="saveToast">✓ Saved</div>

    <div class="main-scroll-container" style="height: calc(100vh - 72px); overflow-y: auto;">

        <div class="container">

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">

                <!-- LEFT: Reassigned Summary -->
                <?php if ($showReassignedSummary && !empty($reassignedSummary)): ?>
                    <div class="dashboard-summary">
                        <div class="dashboard-summary-inner">
                            <span class="dashboard-summary-title">Reassigned Summary</span>

                            <?php foreach ($reassignedSummary as $row): ?>
                                <?php
                                $uid = null;
                                foreach ($allUsers as $u) {
                                    if ($u['username'] === $row['username']) {
                                        $uid = $u['id'];
                                        break;
                                    }
                                }
                                $filterParams = [];
                                if ($q !== '') $filterParams['q'] = $q;
                                if ($filter !== '') $filterParams['filter'] = $filter;
                                if ($cycleFilter !== '') $filterParams['cycle_filter'] = $cycleFilter;
                                if ($sortBy !== 'updated_at') $filterParams['sort'] = $sortBy;
                                if ($sortOrder !== 'DESC') $filterParams['order'] = strtolower($sortOrder);
                                $filterParams['owner_filter'] = $uid;
                                $filterUrl = 'view_saved_reports.php?' . http_build_query($filterParams);
                                ?>
                                <a
                                    href="<?= htmlspecialchars($filterUrl) ?>"
                                    class="dashboard-summary-user"
                                    style="color:#1976d2; font-weight:600; text-decoration:none; cursor:pointer; margin:0 16px;">
                                    <?= htmlspecialchars($row['username']) ?> - <b><?= (int)$row['total'] ?></b>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- RIGHT: Action buttons -->
                <div class="action-icons-container">
                    <?php if ($isAdmin && !$deleteMode && !$reassignMode): ?>
                        <a href="?mode=reassign<?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?><?php echo $sortBy ? '&sort=' . urlencode($sortBy) : ''; ?><?php echo $sortOrder !== 'DESC' ? '&order=' . strtolower($sortOrder) : ''; ?>"
                            class="action-btn reassign-btn" title="Reassign Clients">
                            <i class="fa-solid fa-user-group"></i>
                            <span>Reassign</span>
                        </a>
                        <a href="view_saved_reports.php?reset=1" class="btn btn-reset">Reset Filters</a>
                    <?php elseif ($deleteMode || $reassignMode): ?>
                        <a href="view_saved_reports.php" class="cancel-action-btn">
                            <i class="fa-solid fa-times"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <div style="margin-bottom: 8px; font-weight:600; color:#1976d2;">
                <?php
                echo "Showing $totalDistinctNames client" . ($totalDistinctNames !== 1 ? "s" : "") . " for current filters.";
                ?>
            </div>

            <?php if ($successMessage): ?>
                <div class="alert alert-success" id="successMessage">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <?php if ($deleteMode): ?>
                <div class="alert alert-warning">
                    <strong><i class="fa-solid fa-exclamation-triangle"></i> Delete Mode Active</strong>
                    <p style="margin: 5px 0 0 0;">Select clients using checkboxes, then click "Delete Selected" to remove them.</p>
                </div>
            <?php elseif ($reassignMode): ?>
                <div class="alert alert-info">
                    <strong><i class="fa-solid fa-user-group"></i> Reassign Mode Active</strong>
                    <p style="margin: 5px 0 0 0;">Select clients using checkboxes, choose a user from dropdown, then click "Reassign".</p>
                </div>
            <?php endif; ?>


            <form method="get" class="search-box" id="filterForm" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                <div style="position:relative; flex:1; max-width:60%;">
                    <input type="text"
                        name="q"
                        id="client-search"
                        placeholder="Search..."
                        value="<?php echo htmlspecialchars($q); ?>"
                        autocomplete="off"
                        style="width:60%; padding:10px 14px; font-size:15px; box-sizing:border-box;">

                    <div id="client-search-dropdown"
                        style="display:none;
                            position:absolute;
                            top:100%;
                            left:0;
                            width:60%;
                            background:#fff;
                            z-index:1000;
                            border:1px solid #e2e8f0;
                            border-top:none;
                            max-height:200px;
                            overflow-y:auto;
                            box-sizing:border-box;">
                    </div>
                </div>

                <select id="cycle-filter" name="cycle_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:140px;">
                    <option value="">All Cycles</option>
                    <?php foreach (['RJ', 'RM', 'RF'] as $c): ?>
                        <option value="<?= $c ?>" <?= $cycleFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($isAdmin): ?>
                    <select id="owner-filter" name="owner_filter"
                        style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
                        <option value="all">All Owners / Global View</option>
                        <option value="mine" <?= ($ownerFilter === 'mine') ? 'selected' : '' ?>>My Reports</option>

                        <?php foreach ($ownerTotals as $uid => $info): ?>
                            <?php if ((int)$uid === (int)$myId) continue; ?>
                            <option value="<?= $uid ?>" <?= (string)$ownerFilter === (string)$uid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($info['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <select id="stateFilter" name="filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:160px;">
                    <option value="">All States (<?php echo $allStatesTotal; ?>)</option>
                    <option value="pending" <?= ($filter === 'pending')  ? 'selected' : '' ?>>Review Not Started (<?= $statusTotals['pending']  ?? 0 ?>)</option>
                    <option value="draft" <?= ($filter === 'draft')    ? 'selected' : '' ?>>Draft (<?= $statusTotals['draft']    ?? 0 ?>)</option>
                    <option value="ready" <?= ($filter === 'ready')    ? 'selected' : '' ?>>Ready (<?= $statusTotals['ready']    ?? 0 ?>)</option>
                    <option value="reviewed" <?= ($filter === 'reviewed') ? 'selected' : '' ?>>Reviewed (<?= $statusTotals['reviewed'] ?? 0 ?>)</option>
                    <option value="sent" <?= ($filter === 'sent')     ? 'selected' : '' ?>>Sent (<?= $statusTotals['sent']     ?? 0 ?>)</option>
                </select>

                <select name="sort" class="sort-dropdown">
                    <option value="updated_at" <?php echo $sortBy === 'updated_at' ? 'selected' : ''; ?>>Sort by: Last Updated</option>
                    <option value="id" <?php echo $sortBy === 'id'         ? 'selected' : ''; ?>>Sort by: ID</option>
                    <option value="priority" <?php echo $sortBy === 'priority'   ? 'selected' : ''; ?>>Sort by: Priority</option>
                    <option value="aum" <?php echo $sortBy === 'aum'        ? 'selected' : ''; ?>>Sort by: AUM</option>
                    <option value="name" <?php echo $sortBy === 'name'       ? 'selected' : ''; ?>>Sort by: Client Name</option>
                    <option value="report_state" <?php echo $sortBy === 'report_state' ? 'selected' : ''; ?>>Sort by: Status</option>
                </select>

                <select name="order" style="padding:8px; border:1px solid #ccc; border-radius:4px; font-size:14px;">
                    <option value="desc" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="asc" <?php echo $sortOrder === 'ASC'  ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <select name="meeting_filter"
                    style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:160px;">
                    <option value="all" <?= ($meetingFilter === 'all') ? 'selected' : '' ?>>
                        All Meetings
                    </option>
                    <option value="fixed" <?= ($meetingFilter === 'fixed') ? 'selected' : '' ?>>
                        Meetings Fixed
                    </option>
                    <option value="not_fixed" <?= ($meetingFilter === 'not_fixed') ? 'selected' : '' ?>>
                        Meetings Not Fixed
                    </option>
                </select>

                <input type="hidden" name="mode" value="<?php echo $deleteMode ? 'delete' : ($reassignMode ? 'reassign' : ''); ?>">



            </form>

            <?php if (!$clients): ?>
                <p>No reports found. Use the Upload button next to a client.</p>
            <?php else: ?>

                <!-- ── BULK ACTION FORMS ───────────────────────────── -->
                <?php if ($deleteMode): ?>
                    <form method="post" id="bulkDeleteForm">
                        <input type="hidden" name="action_type" value="delete">
                        <div class="bulk-actions-bar">
                            <span class="bulk-selection-info">With Selected:</span>
                            <button type="button" onclick="confirmDelete()" class="delete-btn">
                                <i class="fa-solid fa-trash"></i> Delete Selected
                            </button>
                            <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
                        </div>
                    <?php elseif ($reassignMode): ?>
                        <form method="post" id="bulkReassignForm">
                            <input type="hidden" name="action_type" value="reassign">
                            <div class="bulk-actions-bar">
                                <span class="bulk-selection-info">With Selected:</span>
                                <select name="new_owner_id" class="reassign-select" required>
                                    <option value="">-- Assign to... --</option>
                                    <?php foreach ($allUsers as $user): ?>
                                        <option value="<?php echo (int)$user['id']; ?>">
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="reassign-submit-btn">Reassign</button>
                                <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
                            </div>
                        <?php else: ?>
                            <!-- No bulk-action form needed in normal view -->
                        <?php endif; ?>

                        <!-- ── MAIN TABLE inside scroll wrapper ───────────── -->
                        <div class="table-scroll-wrapper">
                            <table>
                                <thead>
                                    <!-- ROW 1: section group labels -->
                                    <tr class="group-header">
                                        <?php
                                        $baseColCount = 9;
                                        if ($deleteMode || $reassignMode) $baseColCount++;
                                        ?>
                                        <th colspan="<?= $baseColCount ?>" style="background:#f9f9f9; border:none;"></th>
                                        <th colspan="5" class="th-section-current">Current Review</th>
                                        <th colspan="4" class="th-section-prev">Last Review</th>
                                        <th colspan="1" class="th-section-prev-review">Prev Review</th>
                                        <th colspan="3" style="background:#f9f9f9; border:none;"></th>
                                    </tr>

                                    <!-- ROW 2: actual column headers -->
                                    <tr class="col-header">
                                        <!-- checkbox / empty -->
                                        <?php if ($deleteMode || $reassignMode): ?>
                                            <th style="width: 40px;" class="select-all-cell">
                                                <input type="checkbox" id="selectAllCheckbox" class="action-checkbox" onclick="toggleSelectAll(this)">
                                                <span class="select-all-label">All</span>
                                            </th>
                                        <?php else: ?>
                                            <th style="width: 40px;" class="action-icon-cell"></th>
                                        <?php endif; ?>

                                        <!-- Base columns -->
                                        <th>
                                            <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=id&order=<?= ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter !== 'all' ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                ID <?= $sortBy === 'id' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=name&order=<?= ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter !== 'all' ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                Client Name <?= $sortBy === 'name' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=aum&order=<?= ($sortBy === 'aum' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter !== 'all' ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                AUM (Cr) <?= $sortBy === 'aum' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>Drafted By</th>
                                        <th>RM</th>
                                        <th>Review Assigned to</th>
                                        <th>
                                            <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=updated_at&order=<?= ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter !== 'all' ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                Last Updated <?= $sortBy === 'updated_at' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=report_state&order=<?= ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter !== 'all' ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                Status <?= $sortBy === 'report_state' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>

                                        <!-- CURRENT REVIEW columns -->
                                        <th class="col-current">SIP (Lakhs)</th>
                                        <th class="col-current">Review Sent</th>
                                        <th class="col-current">Mtg Date</th>
                                        <th class="col-current">Modifications / Action</th>
                                        <th class="col-current">Mtg Comments</th>

                                        <!-- LAST REVIEW columns (read-only) -->
                                        <th class="col-prev">Last Review</th>
                                        <th class="col-prev">Last Meeting</th>
                                        <th class="col-prev">Prev Modifications</th>
                                        <th class="col-prev">Prev Mtg Comments</th>

                                        <!-- Previous Review HTML view -->
                                        <th class="col-prev-review" style="text-align:center; min-width:110px;">View Prev Review</th>

                                        <!-- Meeting status / remarks / action -->
                                        <th style="text-align:center; width:120px;">Meeting Status</th>
                                        <th style="text-align:center; width:140px;">Meeting Remarks</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $c): ?>
                                        <?php
                                        $clientAttachDir = __DIR__ . '/uploads/attachments/client_' . (int)$c['id'];
                                        $hasAttachments = is_dir($clientAttachDir) && count(glob($clientAttachDir . '/*')) > 0;

                                        // Determine if this row is sent (locked)
                                        $isSent = (($c['report_state'] ?? '') === 'sent');
                                        $rowClass = $isSent ? 'row-sent' : '';
                                        ?>
                                        <tr class="<?= $rowClass ?>">
                                            <!-- Checkbox / empty -->
                                            <?php if ($deleteMode || $reassignMode): ?>
                                                <td>
                                                    <input type="checkbox"
                                                        class="action-checkbox client-checkbox"
                                                        name="selected_ids[]"
                                                        value="<?php echo (int)$c['id']; ?>"
                                                        onchange="updateSelectedCount()">
                                                </td>
                                            <?php else: ?>
                                                <td class="action-icon-cell"></td>
                                            <?php endif; ?>

                                            <!-- ID -->
                                            <td><?php echo (int)$c['id']; ?></td>

                                            <!-- Client Name -->
                                            <td>
                                                <div style="font-weight:600; color:#333; display:flex; align-items:center; gap:8px;">
                                                    <span><?php echo htmlspecialchars($c['name']); ?></span>
                                                    <?php if ($hasAttachments): ?>
                                                        <span title="Has Attachments">📎</span>
                                                    <?php endif; ?>
                                                    <?php if ($isSent): ?>
                                                        <span class="sent-lock-icon" title="Sent — read only"><i class="fa-solid fa-lock"></i></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- AUM -->
                                            <td>
                                                <span style="font-weight:600; color:#1976d2;">
                                                    ₹<?= number_format((float)($c['aum'] ?? 0), 2); ?> Cr
                                                </span>
                                            </td>

                                            <!-- Drafted By -->
                                            <td>
                                                <?php $currState = strtolower($c['report_state'] ?? 'draft'); ?>
                                                <?php if ($currState === 'pending'): ?>
                                                    <span style="color:#999; font-size:0.85em; font-weight:600;">Not Drafted</span>
                                                <?php else: ?>
                                                    <?php if (!empty($c['created_by_username'])): ?>
                                                        <span class="badge" style="background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:700;">
                                                            <?php echo htmlspecialchars($c['created_by_username']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color:#999; font-size:0.85em;">System</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>

                                            <!-- RM -->
                                            <td>
                                                <span style="color:#333; font-weight:600;">
                                                    <?php echo !empty($c['rm_username']) ? htmlspecialchars($c['rm_username']) : '—'; ?>
                                                </span>
                                            </td>

                                            <!-- Reviewer -->
                                            <td>
                                                <?php
                                                $isReviewer = ((int)($c['review_assigned_to'] ?? 0) === $myId);
                                                $reviewerStyle = 'background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:700;';
                                                if ($isReviewer) $reviewerStyle .= ' font-weight:800; border-color:#1565c0;';
                                                ?>
                                                <?php if (!empty($c['reviewer_username'])): ?>
                                                    <span class="badge" style="<?php echo $reviewerStyle; ?>">
                                                        <?php echo htmlspecialchars($c['reviewer_username']); ?>
                                                        <?php if ($isReviewer): ?><span style="margin-left:6px; color:#0d47a1; font-weight:800;">You</span><?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#999; font-size:0.85em;">Unassigned</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Last Updated
                                     FIX: For 'sent' rows, always use created_at since updated_at
                                     can be corrupted by the is_latest flag UPDATE on new upload.
                                     For non-sent rows, use updated_at if it's newer than created_at. -->
                                            <td>
                                                <?php
                                                if ($isSent) {
                                                    // Sent rows: always show created_at (immutable, reflects when row was created/sent)
                                                    $displayTs = !empty($c['created_at']) ? strtotime($c['created_at']) : 0;
                                                } else {
                                                    // Non-sent rows: show updated_at if newer, else created_at
                                                    $tsUpdated = !empty($c['updated_at']) ? strtotime($c['updated_at']) : 0;
                                                    $tsCreated = !empty($c['created_at']) ? strtotime($c['created_at']) : 0;
                                                    $displayTs = ($tsUpdated > $tsCreated) ? $tsUpdated : $tsCreated;
                                                }
                                                ?>
                                                <?php if ($displayTs): ?>
                                                    <span style="color:#555; font-size:0.9em;">
                                                        <?php echo date('d-M-Y', $displayTs); ?>
                                                        <span style="color:#999; font-size:0.85em;">&nbsp;<?php echo date('h:i A', $displayTs); ?></span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#999; font-size:0.85em;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status badge -->
                                            <?php
                                            $state = $c['report_state'] ?? 'draft';
                                            $statusMap = [
                                                'pending'  => '<span class="badge badge-grey">Pending</span>',
                                                'draft'    => '<span class="badge badge-yellow">Draft</span>',
                                                'ready'    => '<span class="badge badge-blue">Ready</span>',
                                                'reviewed' => '<span class="badge badge-purple">Reviewed</span>',
                                                'sent'     => '<span class="badge badge-green">Sent &#x1F512;</span>',
                                            ];
                                            $statusHtml = $statusMap[$state] ?? '<span class="badge badge-grey">Unknown</span>';
                                            ?>
                                            <td><?php echo $statusHtml; ?></td>

                                            <!-- ════════════════════════════════════════
                                     CURRENT REVIEW — inline-editable (locked for sent)
                                     ════════════════════════════════════════ -->

                                            <!-- SIP (Lakhs) -->
                                            <?php if ($isSent): ?>
                                                <td class="col-current" style="font-size:13px; color:#555;">
                                                    <?= !empty($c['sip_amount_lakhs']) ? number_format((float)$c['sip_amount_lakhs'], 2) : '—' ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="sip_amount_lakhs" data-type="number">
                                                    <span class="display-val <?= empty($c['sip_amount_lakhs']) ? 'placeholder-text' : '' ?>">
                                                        <?= !empty($c['sip_amount_lakhs']) ? number_format((float)$c['sip_amount_lakhs'], 2) : 'click to edit' ?>
                                                    </span>
                                                    <input type="number" step="0.01" value="<?= htmlspecialchars($c['sip_amount_lakhs'] ?? '') ?>">
                                                </td>
                                            <?php endif; ?>

                                            <!-- Review Sent Date -->
                                            <?php if ($isSent): ?>
                                                <td class="col-current" style="font-size:13px; color:#555; white-space:nowrap;">
                                                    <?= fmtDate($c['review_sent_date'], 'd-M') ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="review_sent_date" data-type="date">
                                                    <span class="display-val <?= empty($c['review_sent_date']) ? 'placeholder-text' : '' ?>">
                                                        <?= fmtDate($c['review_sent_date'], 'd-M') ?>
                                                    </span>
                                                    <input type="date" value="<?= htmlspecialchars($c['review_sent_date'] ?? '') ?>">
                                                </td>
                                            <?php endif; ?>

                                            <!-- Meeting Date -->
                                            <?php if ($isSent): ?>
                                                <td class="col-current" style="font-size:13px; color:#555; white-space:nowrap;">
                                                    <?= fmtDate($c['meeting_date'], 'd-M') ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="meeting_date" data-type="date">
                                                    <span class="display-val <?= empty($c['meeting_date']) ? 'placeholder-text' : '' ?>">
                                                        <?= fmtDate($c['meeting_date'], 'd-M') ?>
                                                    </span>
                                                    <input type="date" value="<?= htmlspecialchars($c['meeting_date'] ?? '') ?>">
                                                </td>
                                            <?php endif; ?>

                                            <!-- Modifications / Action -->
                                            <?php if ($isSent): ?>
                                                <td class="col-current" style="max-width:180px; font-size:12px; color:#555;">
                                                    <?php $ma = $c['modifications_action'] ?? ''; ?>
                                                    <?= $ma ? htmlspecialchars(mb_strimwidth($ma, 0, 80, '…')) : '—' ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="modifications_action" data-type="textarea" style="max-width:180px;">
                                                    <span class="display-val <?= empty($c['modifications_action']) ? 'placeholder-text' : '' ?>">
                                                        <?= !empty($c['modifications_action']) ? htmlspecialchars($c['modifications_action']) : 'click to edit' ?>
                                                    </span>
                                                    <textarea><?= htmlspecialchars($c['modifications_action'] ?? '') ?></textarea>
                                                </td>
                                            <?php endif; ?>

                                            <!-- Meeting Comments -->
                                            <?php if ($isSent): ?>
                                                <td class="col-current" style="max-width:180px; font-size:12px; color:#555;">
                                                    <?php $mc = $c['meeting_comments'] ?? ''; ?>
                                                    <?= $mc ? htmlspecialchars(mb_strimwidth($mc, 0, 80, '…')) : '—' ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="meeting_comments" data-type="textarea" style="max-width:180px;">
                                                    <span class="display-val <?= empty($c['meeting_comments']) ? 'placeholder-text' : '' ?>">
                                                        <?= !empty($c['meeting_comments']) ? htmlspecialchars($c['meeting_comments']) : 'click to edit' ?>
                                                    </span>
                                                    <textarea><?= htmlspecialchars($c['meeting_comments'] ?? '') ?></textarea>
                                                </td>
                                            <?php endif; ?>

                                            <!-- ════════════════════════════════════════
                                     LAST REVIEW — read-only, from previous record
                                     ════════════════════════════════════════ -->

                                            <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                <?= fmtDateTime($c['last_review_date'] ?? null) ?>
                                            </td>

                                            <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                <?= fmtDateTime($c['last_meeting_date'] ?? null) ?>
                                            </td>

                                            <td class="col-prev" style="max-width:160px; font-size:12px;">
                                                <?php $prevMod = $c['prev_modifications_action'] ?? ''; ?>
                                                <?php if ($prevMod): ?>
                                                    <span title="<?= htmlspecialchars($prevMod) ?>">
                                                        <?= htmlspecialchars(mb_strimwidth($prevMod, 0, 60, '…')) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="col-prev" style="max-width:160px; font-size:12px;">
                                                <?php $prevCmt = $c['prev_meeting_comments'] ?? ''; ?>
                                                <?php if ($prevCmt): ?>
                                                    <span title="<?= htmlspecialchars($prevCmt) ?>">
                                                        <?= htmlspecialchars(mb_strimwidth($prevCmt, 0, 60, '…')) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- View Previous Review -->
                                            <td class="col-prev-review">
                                                <?php
                                                $prevId    = (int)($c['previous_version_id'] ?? 0);
                                                $prevState = $c['prev_version_state'] ?? '';
                                                $hasPrev   = $prevId > 0 && $prevState !== '' && $prevState !== 'pending';
                                                ?>
                                                <?php if ($hasPrev): ?>
                                                    <a href="view_report.php?id=<?= $prevId ?>"
                                                        target="_blank"
                                                        class="btn-prev-review"
                                                        title="Open previous review report (ID <?= $prevId ?>)">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </a>
                                                <?php else: ?>
                                                    <span class="btn-prev-review no-data" title="No previous review available">
                                                        <i class="fa-solid fa-eye-slash"></i> None
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Meeting Status dropdown -->
                                            <td style="text-align:center;">
                                                <select
                                                    onchange="handleListMeetingChange(this, <?php echo $c['id']; ?>)"
                                                    class="meet-select"
                                                    id="meet_select_<?php echo $c['id']; ?>"
                                                    <?= $isSent ? 'disabled title="Sent — read only"' : '' ?>>
                                                    <option value="pending" <?php echo ($c['meeting_status'] === 'pending') ? 'selected' : ''; ?>>⏳ Pending</option>
                                                    <option value="yes" <?php echo ($c['meeting_status'] === 'yes')     ? 'selected' : ''; ?>>✅ Yes</option>
                                                    <option value="no" <?php echo ($c['meeting_status'] === 'no')      ? 'selected' : ''; ?>>❌ No</option>
                                                </select>
                                            </td>

                                            <!-- Meeting Remarks button -->
                                            <td style="text-align:center;">
                                                <?php if (!$isSent): ?>
                                                    <button type="button"
                                                        id="meet_btn_<?php echo $c['id']; ?>"
                                                        class="meet-btn"
                                                        onclick="openListMeetingModal(<?php echo $c['id']; ?>)"
                                                        style="display: <?php echo ($c['meeting_status'] !== 'pending') ? 'inline-block' : 'none'; ?>;">
                                                        Remarks <?php echo !empty($c['meeting_remarks']) ? '(Edit)' : '(Add)'; ?>
                                                    </button>
                                                <?php elseif (!empty($c['meeting_remarks'])): ?>
                                                    <span style="font-size:12px; color:#555;" title="<?= htmlspecialchars($c['meeting_remarks']) ?>">
                                                        <?= htmlspecialchars(mb_strimwidth($c['meeting_remarks'], 0, 40, '…')) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#ccc; font-size:12px;">—</span>
                                                <?php endif; ?>
                                                <input type="hidden" id="remarks_store_<?php echo $c['id']; ?>" value="<?php echo htmlspecialchars($c['meeting_remarks'] ?? ''); ?>">
                                            </td>

                                            <!-- Action links -->
                                            <td>
                                                <?php
                                                $hasReport = ($c['report_state'] !== 'pending');
                                                $isUploadAllowed = !$hasReport && isset($c['review_cycle']) && $c['review_cycle'] === $systemCurrentCycle;
                                                ?>

                                                <?php if ($hasReport): ?>
                                                    <a href="view_report.php?id=<?= (int)$c['id']; ?>" class="action-link open-link">Open</a>
                                                <?php endif; ?>

                                                <?php if ($isUploadAllowed): ?>
                                                    <button type="button" class="action-link upload-link" onclick="triggerUpload(<?= (int)$c['id']; ?>)">Upload</button>

                                                    <form id="uploadForm_<?= (int)$c['id']; ?>" method="post" enctype="multipart/form-data" style="display:none;">
                                                        <input type="hidden" name="expected_client_id" value="<?= (int)$c['id']; ?>">
                                                        <input type="hidden" name="expected_client_name" value="<?= htmlspecialchars($c['name']); ?>">
                                                        <input type="hidden" name="review_cycle" value="<?= htmlspecialchars($c['review_cycle']); ?>">
                                                        <input type="file" name="client_files[]" multiple onchange="submitUpload(<?= (int)$c['id']; ?>)">
                                                    </form>
                                                <?php endif; ?>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div><!-- /table-scroll-wrapper -->

                        <?php if ($deleteMode || $reassignMode): ?>
                        </form>
                    <?php endif; ?>

                    <div class="pagination">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>:
                        <?php
                        for ($p = 1; $p <= $totalPages; $p++) {
                            if ($p == $page) {
                                echo "<strong>{$p}</strong> ";
                            } else {
                                $params = ['page' => $p];
                                if ($deleteMode)   $params['mode'] = 'delete';
                                if ($reassignMode) $params['mode'] = 'reassign';
                                if ($q !== '')         $params['q']            = $q;
                                if ($filter !== '')    $params['filter']       = $filter;
                                if ($ownerFilter !== '') $params['owner_filter'] = $ownerFilter;
                                if ($cycleFilter !== '') $params['cycle_filter'] = $cycleFilter;
                                if ($meetingFilter !== 'all') $params['meeting_filter'] = $meetingFilter;
                                if ($sortBy !== 'updated_at') $params['sort'] = $sortBy;
                                if ($sortOrder !== 'DESC')    $params['order'] = strtolower($sortOrder);
                                $url = 'view_saved_reports.php?' . http_build_query($params);
                                echo "<a href=\"{$url}\">{$p}</a> ";
                            }
                        }
                        ?>
                    </div>
                <?php endif; ?>
        </div><!-- /container -->

        <!-- ────────────── Meeting Remarks Modal ────────────── -->
        <div id="listMeetingModal" class="modal-overlay" style="display:none;">
            <div class="modal-card">
                <div class="modal-header">
                    <div class="modal-icon">📝</div>
                    <div>
                        <h3>Meeting Remarks</h3>
                        <p>Enter details about the discussion</p>
                    </div>
                </div>
                <input type="hidden" id="current_modal_client_id">
                <textarea id="listModalRemarks" rows="5" placeholder="e.g., Client agreed to increase SIP, follow-up next month..."></textarea>
                <div class="modal-actions">
                    <button type="button" class="btn btn-reset" onclick="closeListMeetingModal()">Cancel</button>
                    <button type="button" class="btn btn-search" onclick="saveListMeetingRemarks()">Save Remarks</button>
                </div>
            </div>
        </div>

    </div><!-- /main-scroll-container -->

    <!-- ═══════════════════════════════════════════════════════════
         JAVASCRIPT
         ═══════════════════════════════════════════════════════════ -->
    <script>
        // ── INLINE EDIT ─────────────────────────────────────────────
        function showToast(msg) {
            const t = document.getElementById('saveToast');
            t.textContent = msg || '✓ Saved';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2200);
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.editable-cell').forEach(cell => {
                const displayVal = cell.querySelector('.display-val');
                const input = cell.querySelector('input, textarea');
                const clientId = cell.dataset.client;
                const field = cell.dataset.field;

                // Click to edit
                displayVal.addEventListener('click', function() {
                    cell.classList.add('editing');
                    input.focus();
                    if (input.tagName === 'TEXTAREA') {
                        input.selectionStart = input.selectionEnd = input.value.length;
                    }
                });

                // Save on blur
                input.addEventListener('blur', function() {
                    saveField(cell, clientId, field, input.value);
                });

                // Save on Enter (for single-line inputs), Escape to cancel
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        input.blur();
                    }
                    if (e.key === 'Escape') {
                        cell.classList.remove('editing');
                    }
                });
            });
        });

        function saveField(cell, clientId, field, value) {
            const input = cell.querySelector('input, textarea');
            const displayVal = cell.querySelector('.display-val');

            fetch('view_saved_reports.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'save_review_fields',
                        client_id: clientId,
                        field: field,
                        value: value
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        let displayText = value;
                        if (field === 'sip_amount_lakhs' && value !== '') {
                            displayText = parseFloat(value).toFixed(2);
                        } else if ((field === 'review_sent_date' || field === 'meeting_date') && value) {
                            const d = new Date(value);
                            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            displayText = d.getDate() + '-' + months[d.getMonth()];
                        }
                        displayVal.textContent = displayText || '—';
                        displayVal.classList.toggle('placeholder-text', !displayText);
                        showToast('✓ Saved');
                    } else {
                        alert('Save failed: ' + (data.error || 'Unknown error'));
                    }
                    cell.classList.remove('editing');
                })
                .catch(() => {
                    alert('Network error while saving.');
                    cell.classList.remove('editing');
                });
        }

        // ── BULK ACTIONS ────────────────────────────────────────────
        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.client-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.client-checkbox');
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            const elem = document.getElementById('selectedCount');
            if (elem) elem.textContent = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '') + ' selected';

            const selectAll = document.getElementById('selectAllCheckbox');
            if (!selectAll) return;
            selectAll.checked = selectedCount > 0 && Array.from(checkboxes).every(c => c.checked);
            selectAll.indeterminate = Array.from(checkboxes).some(c => c.checked) && !selectAll.checked;
        }

        function confirmDelete() {
            const selectedCount = Array.from(document.querySelectorAll('.client-checkbox')).filter(cb => cb.checked).length;
            if (selectedCount === 0) {
                alert('Please select at least one client to delete.');
                return;
            }
            if (confirm('Delete ' + selectedCount + ' selected client(s)? This cannot be undone.')) {
                document.getElementById('bulkDeleteForm').submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.client-checkbox')) updateSelectedCount();

            document.querySelectorAll('.client-checkbox').forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });

            const bulkReassignForm = document.getElementById('bulkReassignForm');
            if (bulkReassignForm) {
                bulkReassignForm.addEventListener('submit', function(e) {
                    const newOwner = bulkReassignForm.querySelector('select[name="new_owner_id"]').value;
                    const selectedCount = Array.from(document.querySelectorAll('.client-checkbox')).filter(cb => cb.checked).length;
                    if (!newOwner) {
                        e.preventDefault();
                        alert('Please select a user to assign to.');
                    } else if (selectedCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one client.');
                    }
                });
            }
        });

        // ── UPLOAD ──────────────────────────────────────────────────
        function triggerUpload(clientId) {
            const form = document.getElementById('uploadForm_' + clientId);
            if (!form) return;
            form.querySelector('input[type="file"]').click();
        }

        function submitUpload(clientId) {
            const form = document.getElementById('uploadForm_' + clientId);
            const fileInput = form.querySelector('input[type="file"]');
            if (!fileInput.files.length) return;
            form.submit();
        }

        // ── MEETING STATUS ───────────────────────────────────────────
        function handleListMeetingChange(select, clientId) {
            const status = select.value;
            const remarksBtn = document.getElementById('meet_btn_' + clientId);
            const storedRemarks = document.getElementById('remarks_store_' + clientId).value;

            if (status === 'yes') {
                openListMeetingModal(clientId);
                if (remarksBtn) remarksBtn.style.display = 'inline-block';
            } else {
                saveData(clientId, status, storedRemarks, false);
                if (remarksBtn) remarksBtn.style.display = (status === 'pending') ? 'none' : 'inline-block';
            }
        }

        function openListMeetingModal(clientId) {
            const remarks = document.getElementById('remarks_store_' + clientId).value;
            document.getElementById('current_modal_client_id').value = clientId;
            document.getElementById('listModalRemarks').value = remarks;
            document.getElementById('listMeetingModal').style.display = 'flex';
            document.getElementById('listModalRemarks').focus();
        }

        function closeListMeetingModal() {
            document.getElementById('listMeetingModal').style.display = 'none';
        }

        function saveListMeetingRemarks() {
            const clientId = document.getElementById('current_modal_client_id').value;
            const remarks = document.getElementById('listModalRemarks').value;
            const select = document.getElementById('meet_select_' + clientId);
            const status = select ? select.value : 'yes';
            saveData(clientId, status, remarks, true);
        }

        function saveData(clientId, status, remarks, isModal) {
            const formData = new URLSearchParams();
            formData.append('action', 'save_meeting_status');
            formData.append('client_id', clientId);
            formData.append('status', status);
            formData.append('remarks', remarks);

            fetch('meeting_tracker.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const store = document.getElementById('remarks_store_' + clientId);
                        if (store) store.value = remarks;
                        const btn = document.getElementById('meet_btn_' + clientId);
                        if (btn) btn.innerHTML = 'Remarks ' + (remarks ? '(Edit)' : '(Add)');
                        if (isModal) {
                            closeListMeetingModal();
                            showToast("Meeting remarks saved!");
                        }
                    } else {
                        alert("Error: " + data.error);
                    }
                });
        }

        // ── CLIENT SEARCH AUTOCOMPLETE ───────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const input = document.getElementById('client-search');
            const dropdown = document.getElementById('client-search-dropdown');
            if (!input) return;

            input.addEventListener('input', function() {
                const val = input.value.trim();
                if (val.length < 1) {
                    dropdown.style.display = 'none';
                    dropdown.innerHTML = '';
                    return;
                }
                fetch('view_saved_reports.php?search_client=1&q=' + encodeURIComponent(val))
                    .then(res => res.json())
                    .then(data => {
                        if (data.length > 0) {
                            dropdown.innerHTML = data.map(name =>
                                `<div style="padding:8px 12px;cursor:pointer;"
                                onmousedown="selectClientName('${name.replace(/'/g,"\\'")}')">${name}</div>`
                            ).join('');
                            dropdown.style.display = 'block';
                        } else {
                            dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">No clients found</div>';
                            dropdown.style.display = 'block';
                        }
                    });
            });

            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
            });
        });

        function selectClientName(name) {
            document.getElementById('client-search').value = name;
            document.getElementById('client-search-dropdown').style.display = 'none';
            document.getElementById('filterForm').submit();
        }

        // ── AUTO-SUBMIT FILTER DROPDOWNS ────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            if (!filterForm) return;
            filterForm.querySelectorAll('select').forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
                });
            });
        });

        // ── AUTO-HIDE SUCCESS MESSAGE ───────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.transition = 'opacity 0.5s ease';
                    successMessage.style.opacity = '0';
                    setTimeout(() => successMessage.style.display = 'none', 500);
                }, 3000);
            }
        });
    </script>

</body>

</html>
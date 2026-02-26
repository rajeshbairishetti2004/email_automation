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
    // - NEW: Mtg Comments from current review become Prev Mtg Comments in next review
    // - NEW: Modifications/Action from current review become Prev Modifications in next review
    // - NEW: Popup modal for Modifications/Action showing only schemes with non-Continue actions with checkboxes

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

    // Helper function to extract only actions from modifications string
    function extractActionsOnly($modificationsString)
    {
        if (empty($modificationsString)) return '';

        $items = explode(' | ', $modificationsString);
        $actions = array_map(function ($item) {
            $parts = explode('-', $item);
            return trim(end($parts)); // Get the last part after the last hyphen
        }, $items);

        return implode(' | ', $actions);
    }


    $currentUser = getCurrentUser();
    $userDesignation = $currentUser['designation'] ?? '';
    $navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
    $myId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0);
    $currentUserId = $myId;
    $filter      = isset($_GET['filter']) ? trim($_GET['filter']) : '';
    $ownerFilter = isset($_GET['owner_filter']) ? trim($_GET['owner_filter']) : 'all';
    $meetingFilter = isset($_GET['meeting_filter']) ? trim($_GET['meeting_filter']) : '';
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
                        meeting_remarks,
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
                            $prev['meeting_remarks']       ?? null,
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
    // NEW: Auto-fill SIP (Lakhs) from client_goals.sip_swp sum
    //      and Modifications/Action from client_schemes
    //      Runs on GET page load for non-sent rows where fields are empty
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['search_client'])) {
        try {
            // --- Auto-fill sip_amount_lakhs from SUM of client_goals.sip_swp ---
            // Find clients where sip_amount_lakhs is NULL/0 and they have goals with sip_swp
            $sipBackfillStmt = $pdo->query("
                SELECT c.id
                FROM clients c
                WHERE c.report_state != 'sent'
                AND (c.sip_amount_lakhs IS NULL OR c.sip_amount_lakhs = 0)
                AND EXISTS (
                    SELECT 1 FROM client_goals g
                    WHERE g.client_id = c.id AND g.sip_swp > 0
                )
                LIMIT 100
            ");
            $sipBackfillIds = $sipBackfillStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($sipBackfillIds)) {
                $sipSumStmt = $pdo->prepare("
                    SELECT SUM(sip_swp) AS total_sip
                    FROM client_goals
                    WHERE client_id = ?
                ");
                $sipUpdateStmt = $pdo->prepare("
                    UPDATE clients
                    SET sip_amount_lakhs = ?,
                        updated_at = updated_at
                    WHERE id = ?
                ");
                foreach ($sipBackfillIds as $cid) {
                    $sipSumStmt->execute([$cid]);
                    $totalSip = (float)($sipSumStmt->fetchColumn() ?? 0);
                    if ($totalSip > 0) {
                        // Convert to lakhs (sip_swp stored in raw rupees)
                        $sipLakhs = $totalSip / 100000;
                        $sipUpdateStmt->execute([$sipLakhs, $cid]);
                    }
                }
            }

            // --- Auto-fill modifications_action from client_schemes ---
            // Find clients where modifications_action is NULL/empty and report_state != 'sent'
            // and they have schemes with action_step != 'Continue'
            $modBackfillStmt = $pdo->query("
                SELECT c.id
                FROM clients c
                WHERE c.report_state != 'sent'
                AND (c.modifications_action IS NULL OR c.modifications_action = '')
                AND EXISTS (
                    SELECT 1 FROM client_schemes s
                    WHERE s.client_id = c.id
                        AND s.action_step != 'Continue'
                        AND s.action_step != ''
                        AND s.scheme_name != ''
                )
                LIMIT 100
            ");
            $modBackfillIds = $modBackfillStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($modBackfillIds)) {
                $modSchemesStmt = $pdo->prepare("
                    SELECT scheme_name, action_step
                    FROM client_schemes
                    WHERE client_id = ?
                    AND action_step != 'Continue'
                    AND action_step != ''
                    AND scheme_name != ''
                    ORDER BY id ASC
                ");
                $modUpdateStmt = $pdo->prepare("
                    UPDATE clients
                    SET modifications_action = ?,
                        updated_at = updated_at
                    WHERE id = ?
                ");
                foreach ($modBackfillIds as $cid) {
                    $modSchemesStmt->execute([$cid]);
                    $schemeRows = $modSchemesStmt->fetchAll(PDO::FETCH_ASSOC);
                    $parts = [];
                    foreach ($schemeRows as $sr) {
                        $parts[] = trim($sr['scheme_name']) . '-' . trim($sr['action_step']);
                    }
                    if (!empty($parts)) {
                        $modUpdateStmt->execute([implode(' | ', $parts), $cid]);
                    }
                }
            }
        } catch (Throwable $autoFillErr) {
            // Non-critical, swallow silently
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
    // AJAX: Fetch scheme changes for Modifications/Action modal
    // Fetch ONLY schemes with non-Continue actions
    // ============================================================
    // ============================================================
    // AJAX: Fetch scheme changes for Modifications/Action modal
    // Fetch ONLY schemes with non-Continue actions
    // ============================================================
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action'])
        && $_POST['action'] === 'fetch_scheme_changes'
    ) {

        header('Content-Type: application/json');

        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid client id']);
                exit;
            }

            // 1️⃣ Fetch ONLY schemes with non-Continue actions
            $stmt = $pdo->prepare("
                SELECT id, scheme_name, action_step, recommended_scheme, recommended_amount
                FROM client_schemes
                WHERE client_id = ?
                AND action_step != 'Continue'
                AND action_step != ''
                AND scheme_name != ''
                ORDER BY id ASC
            ");
            $stmt->execute([$clientId]);
            $schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2️⃣ Fetch completed scheme IDs
            $doneStmt = $pdo->prepare("
                SELECT completed_scheme_ids
                FROM clients
                WHERE id = ?
                LIMIT 1
            ");
            $doneStmt->execute([$clientId]);

            $completedIds = array_filter(array_map(
                'intval',
                explode(',', $doneStmt->fetchColumn() ?: '')
            ));

            // 3️⃣ Return BOTH datasets
            echo json_encode([
                'success' => true,
                'schemes' => $schemes,
                'completed_ids' => $completedIds
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    // ============================================================
    // AJAX: Save scheme changes selections from modal
    // ============================================================
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action'])
        && $_POST['action'] === 'save_scheme_selections'
    ) {

        header('Content-Type: application/json');

        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $selectedIds = $_POST['selected_ids'] ?? [];
            $selectedIds = array_filter(array_map('intval', (array)$selectedIds));

            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid client id']);
                exit;
            }



            $completedIdsStr = !empty($selectedIds)
                ? implode(',', $selectedIds)
                : null;

            $updateStmt = $pdo->prepare("
                UPDATE clients
                SET completed_scheme_ids = ?,
                    updated_at = updated_at
                WHERE id = ?
            ");
            $updateStmt->execute([$completedIdsStr, $clientId]);

            echo json_encode([
                'success' => true,
                'completed_ids' => $selectedIds
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
    // ============================================================
    // AJAX: Fetch historical meeting comments for Prev Mtg Comments modal
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_meeting_history') {
        header('Content-Type: application/json');
        try {
            $clientName = $_POST['client_name'] ?? '';
            $currentId = (int)($_POST['current_id'] ?? 0);

            if (empty($clientName)) {
                echo json_encode(['success' => false, 'error' => 'Invalid client name']);
                exit;
            }

            // Fetch previous reviews with meeting comments
            $stmt = $pdo->prepare("
                SELECT id, meeting_remarks AS meeting_comments, meeting_date, created_at, report_state
                FROM clients
                WHERE name = ?
                AND id != ?
AND meeting_remarks IS NOT NULL
                AND meeting_remarks != ''
                AND report_state != 'pending'
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$clientName, $currentId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'history' => $history
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================================
    // AJAX: Recompute SIP and Modifications/Action for a single client
    //       Called after goals/schemes are updated in view_report.php
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recompute_auto_fields') {
        header('Content-Type: application/json');
        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid client id']);
                exit;
            }


            // Recompute SIP
            $sipStmt = $pdo->prepare("SELECT SUM(sip_swp) FROM client_goals WHERE client_id = ?");
            $sipStmt->execute([$clientId]);
            $totalSip = (float)($sipStmt->fetchColumn() ?? 0);
            $sipLakhs = $totalSip / 100000;

            // Recompute Modifications
            $modStmt = $pdo->prepare("
                SELECT scheme_name, action_step
                FROM client_schemes
                WHERE client_id = ?
                AND action_step != 'Continue'
                AND action_step != ''
                AND scheme_name != ''
                ORDER BY id ASC
            ");
            $modStmt->execute([$clientId]);
            $schemeRows = $modStmt->fetchAll(PDO::FETCH_ASSOC);
            $parts = [];
            foreach ($schemeRows as $sr) {
                $parts[] = trim($sr['scheme_name']) . '-' . trim($sr['action_step']);
            }
            $modificationsAction = implode(' | ', $parts);

            // Update
            $updateStmt = $pdo->prepare("
                UPDATE clients
                SET sip_amount_lakhs = ?,
                    modifications_action = ?,
                    updated_at = updated_at
                WHERE id = ?
            ");
            $updateStmt->execute([$sipLakhs ?: null, $modificationsAction ?: null, $clientId]);

            echo json_encode([
                'success'              => true,
                'sip_amount_lakhs'     => $sipLakhs > 0 ? number_format($sipLakhs, 2) : null,
                'modifications_action' => $modificationsAction ?: null,
            ]);
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
                // CRITICAL FIX: Fetch the last review's data to populate prev_* fields
                // This ensures that Mtg Comments and Modifications/Action from the
                // current review become Prev Mtg Comments and Prev Modifications in the next review
                // =====================================================================
                $stmtLastReview = $pdo->prepare("
                    SELECT
                        review_sent_date,
                        meeting_date,
                        modifications_action,
meeting_comments,
                        meeting_remarks,
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

                // For the new review, we store the previous review's data in prev_* fields
                // This ensures that when this review becomes "sent" and a new review is created,
                // the prev_* fields will contain the data from this review
                $lastReviewDate          = $lastReview['last_review_datetime']  ?? null;
                $lastMeetingDate         = $lastReview['last_meeting_datetime'] ?? null;
                $prevModificationsAction = $lastReview['modifications_action']  ?? null;
                $prevMeetingComments     = $lastReview['meeting_remarks']       ?? null;

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
                    ':prev_modifications_action' => $prevModificationsAction,  // This will be the current review's modifications_action when this review becomes the previous one
                    ':prev_meeting_comments'     => $prevMeetingComments,      // This will be the current review's meeting_comments when this review becomes the previous one
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
    if ($meetingFilter === 'fixed') {
        $whereParts[] = "c.meeting_date IS NOT NULL";
    } elseif ($meetingFilter === 'not_fixed') {
        $whereParts[] = "c.meeting_date IS NULL";
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
    // NEW: Also fetch computed_sip_lakhs and computed_modifications
    //      directly from child tables, used as fallback if DB column is empty
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

            -- ── COMPUTED SIP FROM GOALS (fallback when sip_amount_lakhs is empty) ──
            (
                SELECT ROUND(SUM(g.sip_swp) / 100000, 2)
                FROM client_goals g
                WHERE g.client_id = c.id
            ) AS computed_sip_lakhs,

            -- ── COMPUTED MODIFICATIONS FROM SCHEMES (fallback when modifications_action is empty) ──
            (
                SELECT GROUP_CONCAT(
                    CONCAT(s.scheme_name, '-', s.action_step)
                    ORDER BY s.id ASC
                    SEPARATOR ' | '
                )
                FROM client_schemes s
                WHERE s.client_id = c.id
                AND s.action_step != 'Continue'
                AND s.action_step != ''
                AND s.scheme_name != ''
            ) AS computed_modifications,

            -- ── LAST REVIEW DATE ────────────────────────────────────────────────
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

-- ── PREV MODIFICATIONS ACTION (string snapshot) ─────────────────
COALESCE(
    c.prev_modifications_action,
    (
        SELECT p.modifications_action
        FROM clients p
        WHERE p.name = c.name
        AND p.id != c.id
        AND p.report_state != 'pending'
        AND p.id < c.id
        AND p.modifications_action IS NOT NULL
        AND p.modifications_action != ''
        ORDER BY
            (p.report_state = 'sent') DESC,
            p.id DESC
        LIMIT 1
    )
) AS prev_modifications_action,

-- ── PREV COMPLETED SCHEME IDS (for the modal checkboxes) ────────
(
    SELECT p.completed_scheme_ids
    FROM clients p
    WHERE p.name = c.name
    AND p.id != c.id
    AND p.report_state != 'pending'
    AND p.id < c.id
    AND p.completed_scheme_ids IS NOT NULL
    ORDER BY
        (p.report_state = 'sent') DESC,
        p.id DESC
    LIMIT 1
) AS prev_completed_scheme_ids,

COALESCE(
                c.prev_meeting_comments,
                (
                    SELECT p.meeting_remarks
                    FROM clients p
                    WHERE p.name = c.name
                    AND p.id != c.id
                    AND p.report_state != 'pending'
                    AND p.id < c.id
                    AND p.meeting_remarks IS NOT NULL
                    AND p.meeting_remarks != ''
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

    // Post-process: merge computed fallbacks into display values
    foreach ($clients as &$c) {
        // SIP: use stored value if set, else use computed from goals
        if (empty($c['sip_amount_lakhs']) || (float)$c['sip_amount_lakhs'] == 0) {
            if (!empty($c['computed_sip_lakhs']) && (float)$c['computed_sip_lakhs'] > 0) {
                $c['sip_amount_lakhs'] = $c['computed_sip_lakhs'];
            }
        }
        // Modifications: use stored value if set, else use computed from schemes
        if (empty($c['modifications_action'])) {
            if (!empty($c['computed_modifications'])) {
                $c['modifications_action'] = $c['computed_modifications'];
            }
        }
    }
    unset($c);

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
        <style>
            /* Modal Styles */
            .scheme-modal-overlay,
            .history-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
            }

            .scheme-modal-card,
            .history-modal-card {
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 700px;
                max-height: 80vh;
                overflow-y: auto;
                padding: 24px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            }

            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #0288D1;
            }

            .modal-header h3 {
                margin: 0;
                color: #0288D1;
                font-weight: 600;
            }

            .close-modal {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #666;
            }

            .close-modal:hover {
                color: #0288D1;
            }

            .scheme-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
                margin-bottom: 20px;
            }

            .scheme-item {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                background: #f8f9fa;
                border-radius: 8px;
                border-left: 4px solid #0288D1;
                transition: all 0.2s;
            }

            .scheme-item:hover {
                background: #e3f2fd;
                transform: translateX(5px);
            }

            .scheme-checkbox {
                width: 20px;
                height: 20px;
                margin-right: 15px;
                cursor: pointer;
                accent-color: #0288D1;
            }

            .scheme-details {
                flex: 1;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
            }

            .scheme-name {
                font-weight: 600;
                color: #0288D1;
                min-width: 200px;
                font-size: 14px;
            }

            .scheme-action {
                background: #ff9800;
                color: white;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .scheme-action.drop {
                background: #f44336;
            }

            .scheme-action.switch {
                background: #9c27b0;
            }

            .scheme-action.sip-cancellation {
                background: #ff5722;
            }

            .scheme-action.under-observation {
                background: #607d8b;
            }

            .scheme-action.partially-redeem {
                background: #795548;
            }

            .scheme-recommended {
                color: #666;
                font-size: 13px;
                margin-left: auto;
            }

            .modal-actions {
                margin-top: 20px;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                border-top: 1px solid #e0e0e0;
                padding-top: 20px;
            }

            .btn-primary {
                background: #0288D1;
                color: white;
                border: none;
                padding: 10px 24px;
                border-radius: 6px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }

            .btn-primary:hover {
                background: #0277bd;
            }

            .btn-secondary {
                background: #f5f5f5;
                color: #333;
                border: 1px solid #ddd;
                padding: 10px 24px;
                border-radius: 6px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }

            .btn-secondary:hover {
                background: #e0e0e0;
            }

            .clickable-cell {
                cursor: pointer;
                position: relative;
                transition: background-color 0.2s;
            }

            .clickable-cell:hover {
                background-color: #e3f2fd !important;
            }

            .clickable-cell:hover::after {
                content: '✎';
                position: absolute;
                right: 5px;
                top: 5px;
                color: #0288D1;
                font-size: 14px;
                font-weight: bold;
            }

            .history-item {
                padding: 15px;
                margin-bottom: 15px;
                border-radius: 8px;
                background: #f9f9f9;
                border-left: 4px solid #0288D1;
            }

            .history-item .date {
                font-weight: 600;
                color: #0288D1;
                margin-bottom: 8px;
                font-size: 13px;
            }

            .history-item .comments {
                color: #333;
                line-height: 1.5;
                font-size: 14px;
            }

            .no-schemes-message {
                text-align: center;
                padding: 40px;
                color: #666;
                font-size: 16px;
                background: #f5f5f5;
                border-radius: 8px;
            }
        </style>
    </head>

    <body class="<?php echo ($deleteMode || $reassignMode) ? 'action-mode-active' : ''; ?>">
        <?php include 'navbar.php'; ?>

        <!-- Save toast notification -->
        <div id="saveToast">✓ Saved</div>

        <div class="main-scroll-container" style="height: calc(100vh - 72px); overflow-y: auto;">

            <div class="container">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <!-- LEFT: Action buttons (reassign) -->
                    <div class="action-icons-container">
                        <?php if ($isAdmin && !$deleteMode && !$reassignMode): ?>
                            <a href="?mode=reassign<?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?><?php echo $sortBy ? '&sort=' . urlencode($sortBy) : ''; ?><?php echo $sortOrder !== 'DESC' ? '&order=' . strtolower($sortOrder) : ''; ?>"
                                class="action-btn reassign-btn" title="Reassign Clients">
                                <i class="fa-solid fa-user-group"></i>
                                <span>Reassign</span>
                            </a>
                        <?php elseif ($deleteMode || $reassignMode): ?>
                            <a href="view_saved_reports.php" class="cancel-action-btn">
                                <i class="fa-solid fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- CENTER: Reassigned Summary -->
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

                    <!-- RIGHT: Reset button -->
                    <a href="view_saved_reports.php?reset=1" class="btn btn-reset" >Reset Filters</a>
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
                    <select name="meeting_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
                        <option value="" <?= $meetingFilter === '' ? 'selected' : '' ?>>
                            All Meetings
                        </option>
                        <option value="fixed" <?= $meetingFilter === 'fixed' ? 'selected' : '' ?>>
                            Meetings Fixed
                        </option>
                        <option value="not_fixed" <?= $meetingFilter === 'not_fixed' ? 'selected' : '' ?>>
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
                                            <th colspan="6" class="th-section-current">Current Review</th>
                                            <th colspan="5" class="th-section-prev">Last Review</th>

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
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=id&order=<?= ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    ID <?= $sortBy === 'id' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=name&order=<?= ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Client Name <?= $sortBy === 'name' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=aum&order=<?= ($sortBy === 'aum' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    AUM (Cr) <?= $sortBy === 'aum' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>Drafted By</th>
                                            <th>RM</th>
                                            <th>Review Assigned to</th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=updated_at&order=<?= ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Last Updated <?= $sortBy === 'updated_at' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=report_state&order=<?= ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Status <?= $sortBy === 'report_state' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>

                                            <!-- CURRENT REVIEW columns -->
                                            <th class="col-current">SIP (Lakhs)</th>
                                            <th class="col-current">Review Sent</th>
                                            <th class="col-current">Mtg Date</th>
                                            <th class="col-current">Modifications / Action</th>
                                            <!-- Meeting status / remarks / action -->
                                            <th style="text-align:center; width:120px;">Meeting Status</th>
                                            <th style="text-align:center; width:140px;">Meeting Comments</th>
                                            <!-- <th class="col-current">Mtg Comments</th> -->

                                            <!-- LAST REVIEW columns (read-only) -->
                                            <th class="col-prev">Last Review</th>
                                            <th class="col-prev">Last Meeting</th>
                                            <th class="col-prev">Prev Modifications</th>
                                            <th class="col-prev">Prev Mtg Comments</th>

                                            <!-- Previous Review HTML view -->
                                            <th class="col-prev-review" style="text-align:center; min-width:110px;">View Prev Review</th>


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
                                            $rowClass = '';

                                            // Effective display values (stored takes priority, computed is fallback)
                                            $displaySip = $c['sip_amount_lakhs'] ?? '';
                                            $displayMod = $c['modifications_action'] ?? '';
                                            ?>
                                            <tr class="<?= $rowClass ?>" data-client-id="<?= (int)$c['id'] ?>" data-client-name="<?= htmlspecialchars($c['name']) ?>">
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

                                                <!-- Last Updated -->
                                                <td>
                                                    <?php
                                                    if ($isSent) {
                                                        $displayTs = !empty($c['created_at']) ? strtotime($c['created_at']) : 0;
                                                    } else {
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
                                                    'sent'     => '<span class="badge badge-green">Sent</span>',
                                                ];
                                                $statusHtml = $statusMap[$state] ?? '<span class="badge badge-grey">Unknown</span>';
                                                ?>
                                                <td><?php echo $statusHtml; ?></td>

                                                <!-- SIP (Lakhs) — auto-filled from goals total -->
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="sip_amount_lakhs" data-type="number">
                                                    <span class="display-val <?= (empty($displaySip) || (float)$displaySip == 0) ? 'placeholder-text' : '' ?>">
                                                        <?= (!empty($displaySip) && (float)$displaySip > 0) ? number_format((float)$displaySip, 2) . ' Lakh' : 'click to edit' ?>
                                                    </span>
                                                    <input type="number" step="0.01" value="<?= htmlspecialchars($displaySip ?? '') ?>">
                                                </td>


                                                <!-- ════════════════════════════════════════
                                        CURRENT REVIEW — inline-editable (locked for sent)
                                        ════════════════════════════════════════ -->

                                                <!-- Review Sent Date -->
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="review_sent_date" data-type="date">
                                                    <span class="display-val <?= empty($c['review_sent_date']) ? 'placeholder-text' : '' ?>">
                                                        <?= fmtDate($c['review_sent_date'], 'd-M') ?>
                                                    </span>
                                                    <input type="date" value="<?= htmlspecialchars($c['review_sent_date'] ?? '') ?>">
                                                </td>

                                                <!-- Meeting Date -->
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="meeting_date" data-type="date">
                                                    <span class="display-val <?= empty($c['meeting_date']) ? 'placeholder-text' : '' ?>">
                                                        <?= fmtDate($c['meeting_date'], 'd-M') ?>
                                                    </span>
                                                    <input type="date" value="<?= htmlspecialchars($c['meeting_date'] ?? '') ?>">
                                                </td>

                                                <!-- Modifications / Action — shows only non-Continue actions with clickable modal -->
                                                <!-- Modifications / Action — shows only non-Continue actions with clickable modal -->
                                                <td class="col-current clickable-cell" style="min-width: 300px; max-width: 400px; font-size:12px; cursor:pointer; white-space: normal; word-wrap: break-word;"
                                                    onclick="openSchemeModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($displayMod)) ?>')">
                                                    <?php if (!empty($displayMod)): ?>
                                                        <?= htmlspecialchars(extractActionsOnly($displayMod)) ?>
                                                        <span style="color:#0288D1; font-size:10px; margin-left:5px;"></span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Meeting Status dropdown -->
                                                <td style="text-align:center;">
                                                    <select
                                                        onchange="handleListMeetingChange(this, <?php echo $c['id']; ?>)"
                                                        class="meet-select"
                                                        id="meet_select_<?php echo $c['id']; ?>">
                                                        <option value="pending" <?php echo ($c['meeting_status'] === 'pending') ? 'selected' : ''; ?>>⏳ Pending</option>
                                                        <option value="yes" <?php echo ($c['meeting_status'] === 'yes')     ? 'selected' : ''; ?>>✅ Yes</option>
                                                        <option value="no" <?php echo ($c['meeting_status'] === 'no')      ? 'selected' : ''; ?>>❌ No</option>
                                                    </select>
                                                </td>

                                                <td style="text-align:center; height:auto;">

                                                    <button type="button"
                                                        id="meet_btn_<?php echo $c['id']; ?>"
                                                        class="meet-btn"
                                                        onclick="openListMeetingModal(<?php echo $c['id']; ?>)"
                                                        style="display: <?php echo ($c['meeting_status'] !== 'pending') ? 'inline-block' : 'none'; ?>;">
                                                        Comments <?php echo !empty($c['meeting_remarks']) ? '(Edit)' : '(Add)'; ?>
                                                    </button>

                                                    <?php if (!empty($c['meeting_remarks'])): ?>
                                                        <div style="margin-top:4px; font-size:12px; color:#555;"
                                                            title="<?= htmlspecialchars($c['meeting_remarks']) ?>">
                                                            <?= htmlspecialchars(mb_strimwidth($c['meeting_remarks'], 0, 40, '…')) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="margin-top:4px; color:#ccc; font-size:12px;">—</div>
                                                    <?php endif; ?>

                                                    <input type="hidden"
                                                        id="remarks_store_<?php echo $c['id']; ?>"
                                                        value="<?php echo htmlspecialchars($c['meeting_remarks'] ?? ''); ?>">

                                                </td>



                                                <!-- ════════════════════════════════════════
                                        LAST REVIEW — read-only, from previous record
                                        ════════════════════════════════════════ -->

                                                <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?= fmtDateTime($c['last_review_date'] ?? null) ?>
                                                </td>

                                                <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?= fmtDateTime($c['last_meeting_date'] ?? null) ?>
                                                </td>

                                                <!-- Prev Modifications — opens read-only scheme-style modal -->
                                                <td class="col-prev clickable-cell" style="min-width:300px; max-width:400px; font-size:12px; cursor:pointer; white-space:normal; word-wrap:break-word;"
                                                    onclick="openPrevModificationsModal('<?= htmlspecialchars(addslashes($c['prev_modifications_action'] ?? '')) ?>', '<?= htmlspecialchars($c['prev_completed_scheme_ids'] ?? '') ?>')">
                                                    <?php $prevMod = $c['prev_modifications_action'] ?? ''; ?>
                                                    <?php if ($prevMod): ?>
                                                        <?= htmlspecialchars(extractActionsOnly($prevMod)) ?>
                                                        <span style="color:#0288D1; font-size:10px; margin-left:5px;">🔍</span>
                                                    <?php else: ?>
                                                        <span style="color:#ccc;">—</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Prev Mtg Comments — same styling as current Meeting Comments cell -->
                                                <!-- Prev Mtg Comments — compact preview matching current Meeting Comments style -->
                                                <td class="col-prev" style="text-align:center; vertical-align:top; padding:8px;">
                                                    <?php $prevCmt = $c['prev_meeting_comments'] ?? ''; ?>
                                                    <?php if ($prevCmt): ?>
                                                        <button type="button"
                                                            class="meet-btn"
                                                            onclick="openMeetingHistoryModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                                            View History
                                                        </button>
                                                        <div style="margin-top:4px; font-size:12px; color:#555; text-align:left;"
                                                            title="<?= htmlspecialchars($prevCmt) ?>">
                                                            <?= htmlspecialchars(mb_strimwidth($prevCmt, 0, 40, '…')) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="margin-top:4px; color:#ccc; font-size:12px;">—</div>
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
                                    if ($meetingFilter !== '') $params['meeting_filter'] = $meetingFilter;
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
                            <h3>Meeting Comments</h3>
                            <p>Enter details about the discussion</p>
                        </div>
                    </div>
                    <input type="hidden" id="current_modal_client_id">
                    <textarea id="listModalRemarks"
                        placeholder="e.g., Client agreed to increase SIP, follow-up next month..."
                        style="width:100%; height:auto; min-height:80px; resize:none; box-sizing:border-box; overflow:hidden; border:1px solid #ddd; border-radius:6px; padding:10px; font-size:14px; line-height:1.6; font-family:inherit;"></textarea>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-reset" onclick="closeListMeetingModal()">Cancel</button>
                        <button type="button" class="btn btn-search" onclick="saveListMeetingRemarks()">Save Comments</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Scheme Selection Modal ────────────── -->
            <div id="schemeModal" class="scheme-modal-overlay" style="display:none;">
                <div class="scheme-modal-card">
                    <div class="modal-header">
                        <h3>Select Scheme Changes</h3>
                        <button class="close-modal" onclick="closeSchemeModal()">&times;</button>
                    </div>
                    <div id="schemeModalContent">
                        <!-- Content loaded dynamically -->
                        <div style="text-align:center; padding:20px;">Loading schemes...</div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeSchemeModal()">Cancel</button>
                        <button type="button" class="btn-primary" onclick="saveSchemeSelections()">Save Selections</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Meeting History Modal ────────────── -->
            <div id="meetingHistoryModal" class="history-modal-overlay" style="display:none;">
                <div class="history-modal-card">
                    <div class="modal-header">
                        <h3>Meeting Comments History</h3>
                        <button class="close-modal" onclick="closeMeetingHistoryModal()">&times;</button>
                    </div>
                    <div id="meetingHistoryContent">
                        <!-- Content loaded dynamically -->
                        <div style="text-align:center; padding:20px;">Loading history...</div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeMeetingHistoryModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Modifications History Modal ────────────── -->
            <div id="modificationsHistoryModal" class="history-modal-overlay" style="display:none;">
                <div class="history-modal-card">
                    <div class="modal-header">
                        <h3>Previous Modifications / Actions</h3>
                        <button class="close-modal" onclick="closeModificationsHistoryModal()">&times;</button>
                    </div>
                    <div id="modificationsHistoryContent" style="padding:20px;">
                        <!-- Content set dynamically -->
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeModificationsHistoryModal()">Close</button>
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

            // ── SCHEME MODAL FUNCTIONS ─────────────────────────────────
            let currentClientId = null;

            function openSchemeModal(clientId) {
                currentClientId = clientId;

                const modal = document.getElementById('schemeModal');
                const content = document.getElementById('schemeModalContent');

                // Show loader
                content.innerHTML = `
            <div style="text-align:center; padding:20px;">
                Loading scheme changes...
            </div>
        `;
                modal.style.display = 'flex';

                fetch('view_saved_reports.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'fetch_scheme_changes',
                            client_id: clientId
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            content.innerHTML = `
                    <div style="text-align:center; padding:20px; color:red;">
                        Error loading scheme changes
                    </div>
                `;
                            return;
                        }

                        if (!data.schemes || data.schemes.length === 0) {
                            content.innerHTML = `
                    <div class="no-schemes-message">
                        No scheme changes found for this client.
                    </div>
                `;
                            return;
                        }

                        // ✅ IMPORTANT:
                        // completed_ids comes from DB (clients.completed_scheme_ids)
                        const completedIds = Array.isArray(data.completed_ids) ?
                            data.completed_ids.map(id => parseInt(id, 10)) : [];

                        renderSchemeModal(data.schemes, completedIds);
                    })
                    .catch(() => {
                        content.innerHTML = `
                <div style="text-align:center; padding:20px; color:red;">
                    Network error loading schemes
                </div>
            `;
                    });
            }

            function renderSchemeModal(schemes, completedIds) {
                let html = '<div class="scheme-list">';
                schemes.forEach(scheme => {
                    const actionClass = scheme.action_step.toLowerCase().replace(/\s+/g, '-');
                    const isChecked = completedIds.includes(parseInt(scheme.id));
                    html += '<div class="scheme-item">';
                    html += `<input type="checkbox" class="scheme-checkbox" data-scheme-id="${scheme.id}" value="${scheme.scheme_name}-${scheme.action_step}" ${isChecked ? 'checked' : ''}>`;
                    html += '<div class="scheme-details">';
                    html += `<span class="scheme-name">${scheme.scheme_name}</span>`;
                    html += `<span class="scheme-action ${actionClass}">${scheme.action_step}</span>`;
                    if (scheme.recommended_scheme || scheme.recommended_amount) {
                        html += `<span class="scheme-recommended">→ ${scheme.recommended_scheme || ''} ${scheme.recommended_amount ? '(' + scheme.recommended_amount + ')' : ''}</span>`;
                    }
                    html += '</div></div>';
                });
                html += '</div>';
                document.getElementById('schemeModalContent').innerHTML = html;
            }

            function saveSchemeSelections() {
                const checkboxes = document.querySelectorAll('.scheme-checkbox:checked');
                const formData = new URLSearchParams();
                formData.append('action', 'save_scheme_selections');
                formData.append('client_id', currentClientId);
                checkboxes.forEach(cb => {
                    formData.append('selected_ids[]', cb.getAttribute('data-scheme-id'));
                });
                fetch('view_saved_reports.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const row = document.querySelector(`tr[data-client-id="${currentClientId}"]`);
                            if (row) {
                                const modCell = row.querySelector('td.col-current.clickable-cell');
                                if (modCell) {
                                    modCell.innerHTML = data.modifications_action ?
                                        data.modifications_action + ' <span style="color:#0288D1;font-size:10px;">✎</span>' :
                                        '';
                                }
                            }
                            closeSchemeModal();
                            showToast('Scheme selections saved!');
                        } else {
                            alert('Save failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(() => alert('Network error while saving selections'));
            }

            function closeSchemeModal() {
                document.getElementById('schemeModal').style.display = 'none';
                currentClientId = null;
            }

            // ── MEETING HISTORY MODAL ─────────────────────────────────
            function openMeetingHistoryModal(clientId, clientName) {
                const modal = document.getElementById('meetingHistoryModal');
                const content = document.getElementById('meetingHistoryContent');
                content.innerHTML = '<div style="text-align:center; padding:20px;">Loading meeting history...</div>';
                modal.style.display = 'flex';

                fetch('view_saved_reports.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'fetch_meeting_history',
                            client_name: clientName,
                            current_id: clientId
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.history.length > 0) {
                            let html = '';
                            data.history.forEach(item => {
                                const date = new Date(item.created_at).toLocaleDateString('en-IN', {
                                    day: '2-digit',
                                    month: 'short',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                });
                                html += '<div class="history-item">';
                                html += `<div class="date">${date} (Review ID: ${item.id})</div>`;
                                html += `<div class="comments">${item.meeting_comments}</div>`;
                                html += '</div>';
                            });
                            content.innerHTML = html;
                        } else {
                            content.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">No meeting history found for this client.</div>';
                        }
                    })
                    .catch(err => {
                        content.innerHTML = '<div style="text-align:center; padding:20px; color:red;">Error loading meeting history</div>';
                    });
            }

            function closeMeetingHistoryModal() {
                document.getElementById('meetingHistoryModal').style.display = 'none';
            }

            function openPrevModificationsModal(modifications, completedSchemeIds) {
                const modal = document.getElementById('modificationsHistoryModal');
                const content = document.getElementById('modificationsHistoryContent');

                if (!modifications || modifications.trim() === '') {
                    content.innerHTML = '<div class="no-schemes-message">No previous modifications found.</div>';
                    modal.style.display = 'flex';
                    return;
                }

                const actionColors = {
                    'drop': '#f44336',
                    'switch': '#9c27b0',
                    'sip cancellation': '#ff5722',
                    'under observation': '#607d8b',
                    'partially redeem': '#795548',
                };

                // Parse completed IDs - these are the scheme IDs from the previous review
                const completedIds = completedSchemeIds ?
                    completedSchemeIds.split(',').map(s => s.trim()).filter(Boolean) :
                    [];

                const items = modifications.split(' | ');
                let html = '<div class="scheme-list">';

                items.forEach((item, index) => {
                    const dashIdx = item.lastIndexOf('-');
                    const schemeName = dashIdx !== -1 ? item.substring(0, dashIdx).trim() : item.trim();
                    const actionStep = dashIdx !== -1 ? item.substring(dashIdx + 1).trim() : '';
                    const actionClass = actionStep.toLowerCase().replace(/\s+/g, '-');
                    const actionColor = actionColors[actionStep.toLowerCase()] || '#ff9800';

                    // Check if this scheme was completed - match by position since we don't have IDs
                    // The completed_scheme_ids from previous review should match the order
                    const isChecked = completedIds.length > index;

                    html += `<div class="scheme-item" style="${isChecked ? 'background:#e3f2fd;' : ''}">`;
                    html += `<input type="checkbox" class="scheme-checkbox" disabled ${isChecked ? 'checked' : ''} style="cursor:not-allowed; accent-color:#0288D1;">`;
                    html += '<div class="scheme-details">';
                    html += `<span class="scheme-name">${schemeName}</span>`;
                    html += `<span class="scheme-action ${actionClass}" style="background:${actionColor};">${actionStep}</span>`;

                    // Add recommended info if it exists in the string
                    if (item.includes('→')) {
                        const recParts = item.split('→');
                        if (recParts.length > 1) {
                            html += `<span class="scheme-recommended">→ ${recParts[1].trim()}</span>`;
                        }
                    }

                    html += '</div>';
                    html += '</div>';
                });

                html += '</div>';
                html += '<div style="margin-top:10px; font-size:12px; color:#999; text-align:right;">🔒 Read-only — previous review data</div>';

                content.innerHTML = html;
                modal.style.display = 'flex';
            }

            function closeModificationsHistoryModal() {
                document.getElementById('modificationsHistoryModal').style.display = 'none';
            }

            // ── RECOMPUTE AUTO FIELDS (called after scheme/goal changes) ─
            window.recomputeAutoFields = function(clientId) {
                fetch('view_saved_reports.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'recompute_auto_fields',
                            client_id: clientId
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Update SIP cell in the table row if present
                            const row = document.querySelector(`tr[data-client-id="${clientId}"]`);
                            if (!row) return;

                            if (data.sip_amount_lakhs) {
                                const sipCell = row.querySelector('.editable-cell[data-field="sip_amount_lakhs"]');
                                if (sipCell) {
                                    const dv = sipCell.querySelector('.display-val');
                                    const inp = sipCell.querySelector('input');
                                    if (dv) {
                                        dv.textContent = data.sip_amount_lakhs + ' Lakh';
                                        dv.classList.remove('placeholder-text');
                                    }
                                    if (inp) inp.value = data.sip_amount_lakhs;
                                }
                            }
                            if (data.modifications_action) {
                                const modCell = row.querySelector('.clickable-cell');
                                if (modCell) {
                                    const truncated = data.modifications_action.length > 80 ?
                                        data.modifications_action.substring(0, 80) + '…' :
                                        data.modifications_action;
                                    modCell.innerHTML = truncated + ' <span style="color:#0288D1; font-size:10px; margin-left:5px;">✎</span>';
                                }
                            }
                        }
                    })
                    .catch(err => console.warn('recomputeAutoFields failed:', err));
            };

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

                const textarea = document.getElementById('listModalRemarks');
                textarea.value = remarks;

                // Show modal FIRST so scrollHeight is measurable
                document.getElementById('listMeetingModal').style.display = 'flex';

                // Reset then expand to fit all content
                textarea.style.height = 'auto';
                textarea.style.height = textarea.scrollHeight + 'px';

                textarea.focus();
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
            }); // ← search autocomplete block ends here

            // ── TEXTAREA AUTO-RESIZE ─────────────────────────────────────
            document.addEventListener('DOMContentLoaded', function() {
                const textarea = document.getElementById('listModalRemarks');
                if (textarea) {
                    textarea.addEventListener('input', function() {
                        this.style.height = 'auto';
                        this.style.height = this.scrollHeight + 'px';
                    });
                }
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

            // ── CLOSE MODALS ON ESC ────────────────────────────────────
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSchemeModal();
                    closeMeetingHistoryModal();
                    closeModificationsHistoryModal();
                    closeListMeetingModal();
                }
            });
        </script>

    </body>

    </html>
<?php
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



    // Returns 1 if country is USA or Canada, 0 otherwise.
    function resolveIsUsa(?string $country): int
    {
        $c = strtolower(trim($country ?? ''));
        return in_array($c, ['usa', 'us', 'united states', 'united states of america', 'canada'], true) ? 1 : 0;
    }
    // ─────────────────────────────────────────────────────────────────────────


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
                        if (stripos((string)$cell, 'category') !== false) {
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
                    if (stripos((string)$val, 'category') !== false) $catCol = $col;
                    if (stripos((string)$val, 'share') !== false || stripos((string)$val, '%') !== false) $pctCol = $col;
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
                        if (stripos((string)$val, 'share') !== false) {
                            $shareCol = $col;
                            break 2;
                        }
                    }
                }

                if (!$shareCol) return 0;

                foreach ($rows as $row) {

                    $rowText = implode(' ', $row);

                    if (
                        stripos((string)$rowText, 'equity') !== false &&
                        stripos((string)$rowText, 'global') !== false &&
                        stripos((string)$rowText, 'total') !== false
                    ) {
                        return (float)($row[$shareCol] ?? 0);
                    }
                }
            }
        }

        return 0;
    }

    
    function extractActionsOnly($modificationsString)
    {
        if (empty($modificationsString)) return '';

        $items = explode(' | ', $modificationsString);
        $actions = array_map(function ($item) {
            $parts = explode('-', $item);
            return trim(end($parts)); 
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
    $countryFilter = isset($_GET['country_filter']) ? trim($_GET['country_filter']) : '';
    $isAdmin = (strtolower($currentUser['username'] ?? '') === strtolower(getenv('ADMIN_USERNAME') ?: 'admin'));

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

    // ── GLOBAL BACKFILL: Fix all existing NULL allocation_ids by inheriting
    //    from the nearest previous record of the same client name.
    //    Runs on every page load but is a no-op once all rows are fixed.
    // ─────────────────────────────────────────────────────────────────────────
    try {
        $pdo->exec("
            UPDATE clients c
            INNER JOIN (
                SELECT c2.id,
                       (SELECT p.allocation_id
                        FROM clients p
                        WHERE p.name = c2.name
                          AND p.allocation_id IS NOT NULL
                          AND p.id != c2.id
                        ORDER BY p.id DESC
                        LIMIT 1
                       ) AS inherited_alloc_id
                FROM clients c2
                WHERE c2.allocation_id IS NULL
            ) AS src ON c.id = src.id
            SET c.allocation_id = src.inherited_alloc_id
            WHERE src.inherited_alloc_id IS NOT NULL
              AND c.allocation_id IS NULL
        ");
    } catch (Throwable $e) {
        // Non-critical, swallow silently
    }
    // ─────────────────────────────────────────────────────────────────────────

   
    try {
        $triggerChecked = false;
        if (isset($_SESSION['trigger_checked']) && $_SESSION['trigger_checked'] === true) {
            $triggerChecked = true;
        } else {
            
            $stmt = $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'auto_set_review_sent_date_on_sent'");
            $triggerExists = $stmt->rowCount() > 0;

            $earlyIsAdmin = (strtolower($currentUser['username'] ?? '') === strtolower(getenv('ADMIN_USERNAME') ?: 'admin'));
            if (!$triggerExists && $earlyIsAdmin) {
                try {
                    $pdo->exec("DROP TRIGGER IF EXISTS auto_set_review_sent_date_on_sent");

                    $createSQL = "
                    CREATE TRIGGER auto_set_review_sent_date_on_sent
                    BEFORE UPDATE ON clients
                    FOR EACH ROW
                    BEGIN
                        IF NEW.report_state = 'sent' AND (OLD.report_state != 'sent' OR OLD.report_state IS NULL) THEN
                            IF NEW.review_sent_date IS NULL THEN
                                SET NEW.review_sent_date = CURDATE();
                            END IF;
                        END IF;
                    END
                ";

                    $pdo->exec($createSQL);
                    error_log("✅ Review sent date trigger installed successfully");
                } catch (Exception $e) {
                    error_log("Could not install trigger: " . $e->getMessage());
                    // Fallback will be handled in code
                }
            }

            // ── Install is_usa triggers if missing ────────────────────────────
            if ($earlyIsAdmin) {
                try {
                    $stmtIsUsaIns = $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'auto_set_is_usa_on_insert'");
                    if ($stmtIsUsaIns->rowCount() === 0) {
                        $pdo->exec("DROP TRIGGER IF EXISTS auto_set_is_usa_on_insert");
                        $pdo->exec("
                            CREATE TRIGGER auto_set_is_usa_on_insert
                            BEFORE INSERT ON clients
                            FOR EACH ROW
                            BEGIN
                                IF NEW.country IS NOT NULL AND TRIM(NEW.country) != '' THEN
                                    IF LOWER(TRIM(NEW.country)) IN ('usa', 'us', 'united states', 'united states of america', 'canada') THEN
                                        SET NEW.is_usa = 1;
                                    ELSE
                                        SET NEW.is_usa = 0;
                                    END IF;
                                END IF;
                                -- If country IS NULL, leave is_usa untouched (PHP already set the inherited value)
                            END
                        ");
                        error_log("✅ is_usa INSERT trigger installed successfully");
                    }

                    $stmtIsUsaUpd = $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'auto_set_is_usa_on_update'");
                    if ($stmtIsUsaUpd->rowCount() === 0) {
                        $pdo->exec("DROP TRIGGER IF EXISTS auto_set_is_usa_on_update");
                        $pdo->exec("
                            CREATE TRIGGER auto_set_is_usa_on_update
                            BEFORE UPDATE ON clients
                            FOR EACH ROW
                            BEGIN
                                IF NEW.country IS NOT NULL THEN
                                    IF LOWER(TRIM(NEW.country)) IN ('usa', 'us', 'united states', 'united states of america', 'canada') THEN
                                        SET NEW.is_usa = 1;
                                    ELSE
                                        SET NEW.is_usa = 0;
                                    END IF;
                                END IF;
                            END
                        ");
                        error_log("✅ is_usa UPDATE trigger installed successfully");
                    }
                } catch (Exception $e) {
                    error_log("Could not install is_usa triggers: " . $e->getMessage());
                }
            }
            // ─────────────────────────────────────────────────────────────────

            $_SESSION['trigger_checked'] = true;
        }
    } catch (Exception $e) {
        // Silently fail - trigger is optional
    }

    // ── BACKFILL: Inherit country + is_usa from previous records where country is NULL ──
    // Fixes clients re-uploaded without country in Excel (e.g. Canada clients whose
    // new draft gets country=NULL and is_usa incorrectly reset to 0).
    try {
        $pdo->exec("
            UPDATE clients c
            INNER JOIN (
                SELECT c2.id,
                       (SELECT p.country FROM clients p
                        WHERE p.name = c2.name
                          AND p.id  != c2.id
                          AND p.country IS NOT NULL
                          AND TRIM(p.country) != ''
                        ORDER BY (p.report_state = 'sent') DESC, p.id DESC
                        LIMIT 1) AS inherited_country,
                       (SELECT p.is_usa FROM clients p
                        WHERE p.name = c2.name
                          AND p.id  != c2.id
                          AND p.country IS NOT NULL
                          AND TRIM(p.country) != ''
                        ORDER BY (p.report_state = 'sent') DESC, p.id DESC
                        LIMIT 1) AS inherited_is_usa
                FROM clients c2
                WHERE (c2.country IS NULL OR TRIM(c2.country) = '')
            ) AS src ON c.id = src.id
            SET c.country = src.inherited_country,
                c.is_usa  = COALESCE(src.inherited_is_usa, 0)
            WHERE src.inherited_country IS NOT NULL
        ");
    } catch (Throwable $e) {
        // Non-critical, swallow silently
    }

    // ── BACKFILL: Sync is_usa from country for explicitly-set country values ──
    // NOTE: We do NOT reset is_usa=0 for NULL-country records — those may have
    // correctly inherited is_usa=1 from a previous record via the step above.
    try {
        $pdo->exec("
            UPDATE clients
            SET is_usa = 1
            WHERE LOWER(TRIM(country)) IN ('usa', 'us', 'united states', 'united states of america', 'canada')
            AND is_usa != 1
        ");
        $pdo->exec("
            UPDATE clients
            SET is_usa = 0
            WHERE country IS NOT NULL
            AND TRIM(country) != ''
            AND LOWER(TRIM(country)) NOT IN ('usa', 'us', 'united states', 'united states of america', 'canada')
            AND is_usa != 0
        ");
    } catch (Throwable $e) {
        // Non-critical, swallow silently
    }
    // ─────────────────────────────────────────────────────────────────────────

  
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action'])
        && $_POST['action'] === 'save_scheme_selections'
    ) {
        header('Content-Type: application/json');
        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $selectedIds = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];

            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid client id']);
                exit;
            }

            // Convert to comma-separated string of integers
            $completedIds = array_filter(array_map('intval', $selectedIds));
            $completedIdsStr = implode(',', $completedIds);

            // Update the clients table with completed_scheme_ids
            $updateStmt = $pdo->prepare("
            UPDATE clients 
            SET completed_scheme_ids = ?,
                updated_at = updated_at
            WHERE id = ?
        ");
            $updateStmt->execute([$completedIdsStr ?: null, $clientId]);

            // Recompute modifications_action from client_schemes
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

            // Update modifications_action
            $updateModStmt = $pdo->prepare("
            UPDATE clients
            SET modifications_action = ?,
                updated_at = updated_at
            WHERE id = ?
        ");
            $updateModStmt->execute([$modificationsAction ?: null, $clientId]);

            echo json_encode([
                'success' => true,
                'modifications_action' => $modificationsAction,
                'completed_ids' => $completedIds
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
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

   
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['search_client'])) {
        try {
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

            $modBackfillStmt = $pdo->query("
                SELECT c.id
                FROM clients c
                WHERE (c.modifications_action IS NULL OR c.modifications_action = '')
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
            
        }
    }

    // ── BACKFILL: Set review_sent_date for sent records that are missing it ──
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['search_client'])) {
        try {
            $pdo->exec("
                UPDATE clients
                SET review_sent_date = DATE(created_at)
                WHERE report_state = 'sent'
                AND (review_sent_date IS NULL OR review_sent_date = '')
            ");
        } catch (Throwable $e) {
            // Non-critical, swallow silently
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_review_fields') {
        header('Content-Type: application/json');
        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $field    = $_POST['field'] ?? '';
            $value    = $_POST['value'] ?? '';

            $allowedFields = [
                'sip_amount_lakhs',
                'review_sent_date',
                'meeting_date',
                'modifications_action',
                'meeting_comments',
                'country',              // ← allows updating country from the UI
            ];

            if (!$clientId || !in_array($field, $allowedFields, true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid field or client id']);
                exit;
            }

            // For date fields, allow empty → NULL
            $bindValue = ($value === '') ? null : $value;

            $stmt = $pdo->prepare("UPDATE clients SET `$field` = ? WHERE id = ?");
            $stmt->execute([$bindValue, $clientId]);

            // ── When country is updated, keep is_usa in sync ──────────────────
            if ($field === 'country') {
                $isUsaVal = resolveIsUsa($value);
                $pdo->prepare("UPDATE clients SET is_usa = ? WHERE id = ?")
                    ->execute([$isUsaVal, $clientId]);
            }
            // ─────────────────────────────────────────────────────────────────

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

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

   if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action'])
        && $_POST['action'] === 'fetch_prev_scheme_changes'
    ) {
        header('Content-Type: application/json');
        try {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid client id']);
                exit;
            }

            // Get client name and find previous record
            $nameStmt = $pdo->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1");
            $nameStmt->execute([$clientId]);
            $clientName = $nameStmt->fetchColumn();

            if (!$clientName) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }

            // Find the previous review record
            $prevStmt = $pdo->prepare("
            SELECT id, completed_scheme_ids
            FROM clients
            WHERE name = ?
            AND id < ?
            AND report_state != 'pending'
            ORDER BY (report_state = 'sent') DESC, id DESC
            LIMIT 1
        ");
            $prevStmt->execute([$clientName, $clientId]);
            $prevRecord = $prevStmt->fetch(PDO::FETCH_ASSOC);

            if (!$prevRecord) {
                echo json_encode(['success' => true, 'schemes' => [], 'completed_ids' => []]);
                exit;
            }

            // Fetch actual schemes from the previous record
            $schemesStmt = $pdo->prepare("
            SELECT id, scheme_name, action_step, recommended_scheme, recommended_amount
            FROM client_schemes
            WHERE client_id = ?
            AND action_step != 'Continue'
            AND action_step != ''
            AND scheme_name != ''
            ORDER BY id ASC
        ");
            $schemesStmt->execute([$prevRecord['id']]);
            $schemes = $schemesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse completed IDs
            $completedIds = array_filter(array_map(
                'intval',
                explode(',', $prevRecord['completed_scheme_ids'] ?: '')
            ));

            echo json_encode([
                'success' => true,
                'schemes' => $schemes,
                'completed_ids' => array_values($completedIds)
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_meeting_history') {
        header('Content-Type: application/json');
        try {
            $clientName = $_POST['client_name'] ?? '';
            $currentId = (int)($_POST['current_id'] ?? 0);

            if (empty($clientName)) {
                echo json_encode(['success' => false, 'error' => 'Invalid client name']);
                exit;
            }

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


            $currentMonthYear = date('M Y');

            $checkExistingReview = $pdo->prepare('
                SELECT id, review_attempt 
                FROM clients 
                WHERE name = :name 
                AND month_year = :month_year 
                ORDER BY review_attempt DESC 
                LIMIT 1
            ');

            $markPreviousAsNotLatest = $pdo->prepare('
                UPDATE clients 
                SET is_latest = FALSE,
                    updated_at = updated_at
                WHERE name = :name 
                AND month_year = :month_year
            ');

            // country and is_usa are explicitly inserted so the value is
            // correct even if the DB trigger hasn't been installed yet.
            $insertClient = $pdo->prepare('INSERT INTO clients
                (name, email, as_on, total_amount, aum, profit, cagr, xirr, absolute_return,
                total_goal_current, total_goal_target, total_sip,
                greeting_prefix, intro_text, closing_text, rationale_text,
                created_by, report_state, assigned_to, month_year, review_cycle,
                is_latest, previous_version_id, review_attempt,
                last_review_date, last_meeting_date, prev_modifications_action, prev_meeting_comments,
                country, is_usa)
                VALUES
                (:name, :email, :as_on, :total_amount, :aum, :profit, :cagr, :xirr, :absolute_return,
                :total_goal_current, :total_goal_target, :total_sip,
                :greeting_prefix, :intro_text, :closing_text, :rationale_text,
                :created_by, :report_state, :assigned_to, :month_year, :review_cycle,
                :is_latest, :previous_version_id, :review_attempt,
                :last_review_date, :last_meeting_date, :prev_modifications_action, :prev_meeting_comments,
                :country, :is_usa)');

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
                $email      = trim($clientData['email']   ?? '');
                $clientName = trim($clientData['name']    ?? '');
                $country    = trim($clientData['country'] ?? '');

                // ── Derive is_usa from the parsed country value ───────────────
                $isUsa = resolveIsUsa($country);
                // ─────────────────────────────────────────────────────────────

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
$aum            = $totalAmount; // store raw, display handles conversion

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

$stmtLastReview = $pdo->prepare("
    SELECT
        review_sent_date,
        meeting_date,
        modifications_action,
        meeting_comments,
        meeting_remarks,
        country,
        is_usa,
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
                    ORDER BY
                        (report_state = 'sent') DESC,
                        (report_state != 'pending') DESC,
                        (review_sent_date IS NOT NULL) DESC,
                        id DESC
                    LIMIT 1
                ");
                $stmtLastReview->execute([$clientName]);
                $lastReview = $stmtLastReview->fetch(PDO::FETCH_ASSOC);

                $lastReviewDate          = $lastReview['last_review_datetime']  ?? null;
                $lastMeetingDate         = $lastReview['last_meeting_datetime'] ?? null;
                $prevModificationsAction = $lastReview['modifications_action']  ?? null;
                $prevMeetingComments     = $lastReview['meeting_remarks']       ?? null;

                // ── If parsed files didn't provide a country, carry forward from previous record ──
                if (empty($country) && !empty($lastReview['country'])) {
                    $country = $lastReview['country'];
                    $isUsa   = (int)$lastReview['is_usa'];
                }
                // ─────────────────────────────────────────────────────────────

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
                    ':country'                   => $country ?: null,
                    ':is_usa'                    => $isUsa,
                ]);

                $clientId = (int)$pdo->lastInsertId();

                // ── BACKFILL allocation_id: inherit from nearest previous record of same client ──
                if ($clientId > 0) {
                    $stmtAllocId = $pdo->prepare("
                        SELECT allocation_id FROM clients
                        WHERE name = ?
                          AND allocation_id IS NOT NULL
                          AND id != ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmtAllocId->execute([$clientName, $clientId]);
                    $inheritedAllocId = $stmtAllocId->fetchColumn();
                    if ($inheritedAllocId) {
                        $pdo->prepare("
                            UPDATE clients SET allocation_id = ?
                            WHERE id = ? AND allocation_id IS NULL
                        ")->execute([$inheritedAllocId, $clientId]);
                    }
                }
                // ─────────────────────────────────────────────────────────────

                // ── POST-INSERT SAFETY: If country was inherited (not from Excel),
                //    immediately UPDATE to ensure country + is_usa are correct.
                //    The DB trigger sees country=NULL on insert and may reset is_usa=0;
                //    this UPDATE fires AFTER the trigger, so it wins.
                if ($clientId > 0 && !empty($country)) {
                    $pdo->prepare("UPDATE clients SET country = ?, is_usa = ?, updated_at = updated_at WHERE id = ?")
                        ->execute([$country, $isUsa, $clientId]);
                }
                // ─────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // KEY CHANGE: Detect whether user is doing a specific client name search.
    // When searching by name, show ALL records for that client (all months,
    // all versions), ordered by id DESC (latest first).
    // When NOT searching, show only the current month's latest records as before.
    // ─────────────────────────────────────────────────────────────────────────
    $isClientSearch = ($q !== '');

    $sortBy      = isset($_GET['sort']) ? trim($_GET['sort']) : 'updated_at';
    $sortOrder   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
    $page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit       = 20;
    $offset      = ($page - 1) * $limit;

    $whereParts = [];
    $params     = [];

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

    // ── COUNTRY FILTER ──────────────────────────────────────────────────────
    if ($countryFilter !== '') {
        if ($countryFilter === '__domestic__') {
            $whereParts[] = "(c.country IS NULL OR TRIM(c.country) = '' OR LOWER(TRIM(c.country)) = 'india')";
        } else {
            $whereParts[] = "LOWER(TRIM(c.country)) = ?";
            $params[] = strtolower(trim($countryFilter));
        }
    }
    // ────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // When doing a client search: show ALL records for the matching client(s)
    // across all months/cycles, ordered by id DESC (newest first).
    // When NOT searching: restrict to current month + latest flag only.
    // ─────────────────────────────────────────────────────────────────────────
    $currentMonthYear = date('M Y');

if (!$isClientSearch) {
    array_unshift($whereParts, "c.is_latest = TRUE");

    if ($cycleFilter !== '') {
        // When a specific cycle is selected, show all months for that cycle
        $whereParts[] = "c.review_cycle = ?";
        $params[] = $cycleFilter;
    } else {
        // No cycle filter = show current month only (default view)
        $whereParts[] = "c.month_year = ?";
        $params[] = $currentMonthYear;
    }
} else {
        // Search mode: no month/cycle/is_latest restriction — show full history
        // (cycle filter still applies if explicitly set and useful)
        if ($cycleFilter !== '' && $cycleFilter !== $systemCurrentCycle) {
            // Only apply cycle filter if user explicitly changed it from default
            $whereParts[] = "c.review_cycle = ?";
            $params[] = $cycleFilter;
        }
    }

    $whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    // --- CONTEXTUAL COUNTS FOR DROPDOWNS ---
    $cycleTotals = [];
    $cycleCountStmt = $pdo->prepare("SELECT c.review_cycle, COUNT(*) as total FROM clients c 
    $whereClause GROUP BY c.review_cycle");
    $cycleCountStmt->execute($params);
    foreach ($cycleCountStmt as $row) {
        $cycleTotals[$row['review_cycle']] = (int)$row['total'];
    }
    $allCyclesTotal = array_sum($cycleTotals);

    $ownerWhereParts = [];
    $ownerParams = [];
    if (!$isClientSearch && $cycleFilter !== '') {
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
    if (!$isClientSearch && $cycleFilter !== '') {
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

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM clients c
        {$whereClause}
    ");
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $stmtDistinctNames = $pdo->prepare("SELECT COUNT(DISTINCT c.name) FROM clients c {$whereClause}");
    $stmtDistinctNames->execute($params);
    $totalDistinctNames = (int)$stmtDistinctNames->fetchColumn();

    // Validate sort column to prevent SQL injection
    $allowedSorts = ['id', 'name', 'updated_at', 'priority', 'report_state', 'aum'];
    $sortColumn = in_array($sortBy, $allowedSorts) ? $sortBy : 'updated_at';

    // In search mode always sort by id DESC (latest record first)
    if ($isClientSearch) {
        $orderByClause = "ORDER BY c.id DESC";
    } elseif ($sortColumn === 'priority') {
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

   $stmt = $pdo->prepare("
        SELECT
            c.id, c.name, c.as_on, c.created_at, c.updated_at, c.total_amount, c.profit,
            c.aum,
            c.report_state, c.review_not_ok, c.review_comment, c.created_by, c.assigned_to, c.review_assigned_to,
            c.priority, c.meeting_status, c.meeting_remarks,
            c.review_cycle,
            c.month_year,
            c.is_latest,
            c.review_attempt,
            c.sip_amount_lakhs,
            c.review_sent_date,
            c.meeting_date,
            c.modifications_action,
            c.meeting_comments,
            c.previous_version_id,
            c.country,
            c.is_usa,

            -- Fetch the report_state of the previous version
            (
                SELECT p.report_state
                FROM clients p
                WHERE p.id = c.previous_version_id
                LIMIT 1
            ) AS prev_version_state,

            -- Fallback prev review ID for bulk-allocated rows (previous_version_id is NULL)
            -- Must be strictly older (lower id) than current record
            (
                SELECT p.id
                FROM clients p
                WHERE p.name = c.name
                AND p.id < c.id
                AND p.report_state NOT IN ('pending', '')
                ORDER BY (p.report_state = 'sent') DESC, p.id DESC
                LIMIT 1
            ) AS fallback_prev_id,

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
                ),
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(s.scheme_name, '-', s.action_step)
                        ORDER BY s.id ASC
                        SEPARATOR ' | '
                    )
                    FROM client_schemes s
                    WHERE s.client_id = (
                        SELECT p2.id FROM clients p2
                        WHERE p2.name = c.name
                        AND p2.id != c.id
                        AND p2.report_state != 'pending'
                        AND p2.id < c.id
                        ORDER BY (p2.report_state = 'sent') DESC, p2.id DESC
                        LIMIT 1
                    )
                    AND s.action_step != 'Continue'
                    AND s.action_step != ''
                    AND s.scheme_name != ''
                )
            ) AS prev_modifications_action,

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

            COALESCE(
                (
                    SELECT p.sip_amount_lakhs
                    FROM clients p
                    WHERE p.name = c.name
                    AND p.id != c.id
                    AND p.report_state != 'pending'
                    AND p.id < c.id
                    AND p.sip_amount_lakhs IS NOT NULL
                    AND p.sip_amount_lakhs > 0
                    ORDER BY
                        (p.report_state = 'sent') DESC,
                        p.id DESC
                    LIMIT 1
                ),
                (
                    SELECT ROUND(SUM(g.sip_swp) / 100000, 2)
                    FROM client_goals g
                    INNER JOIN clients p2 ON g.client_id = p2.id
                    WHERE p2.name = c.name
                    AND p2.id != c.id
                    AND p2.report_state != 'pending'
                    AND p2.id < c.id
                    ORDER BY p2.id DESC
                    LIMIT 1
                )
            ) AS prev_sip_amount_lakhs,

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

    // ── DISTINCT COUNTRIES FOR FILTER DROPDOWN ───────────────────────────────
    try {
        $countryListStmt = $pdo->query("
            SELECT DISTINCT TRIM(country) AS country
            FROM clients
            WHERE country IS NOT NULL
              AND TRIM(country) <> ''
              AND LOWER(TRIM(country)) <> 'india'
              AND is_latest = 1
            ORDER BY country
        ");
        $availableCountries = $countryListStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $availableCountries = [];
    }
    // ────────────────────────────────────────────────────────────────────────

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

    // Pass the search mode flag to the HTML template
    $isClientSearchMode = $isClientSearch;

    include __DIR__ . '/public/html/view_saved_reports_html.php';
    ?>
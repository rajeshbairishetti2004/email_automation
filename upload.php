<?php
// upload.php
// - Upload Excel/PDF files
// - Parse and build per-client reports
// - Store everything into DB

require_once 'login.php';
require_once 'db_config.php';
require_once 'parsers.php';
require_once 'renderers.php';
require_once 'env_loader.php';

requireAuth();

/* ---------- CONFIG: DEFAULT TEXTS (fallbacks only) ---------- */
$DEFAULT_GREETING  = 'Dear Mr.';
$DEFAULT_INTRO     = "Introduction";
$DEFAULT_CLOSING   = "Closing remarks";
$DEFAULT_RATIONALE = "Rationale for recommendations";

/* ---------- HANDLE FILE UPLOAD AND PROCESSING ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {

    $greetingBase  = $_POST['greeting']       ?? $DEFAULT_GREETING;
    $introText     = $_POST['intro_text']     ?? $DEFAULT_INTRO;
    $closingText   = $_POST['closing_text']   ?? $DEFAULT_CLOSING;
    $rationaleText = $_POST['rationale_text'] ?? $DEFAULT_RATIONALE;

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $allowedExt = ['xlsx','xls','pdf'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB

    $pvFiles  = [];
    $aaFiles  = [];
    $rstFiles = [];
    $psFiles  = [];
    $pdfFiles = [];
    $fileErrors = [];

    try {
        foreach ($_FILES['client_files']['name'] as $i => $name) {
            if ($_FILES['client_files']['error'][$i] !== UPLOAD_ERR_OK) {
                $fileErrors[] = "Error uploading file: " . htmlspecialchars($name);
                continue;
            }

            $size = $_FILES['client_files']['size'][$i];
            if ($size > $maxFileSize) {
                $fileErrors[] = "File too large (max 10MB): " . htmlspecialchars($name);
                continue;
            }

            $tmp    = $_FILES['client_files']['tmp_name'][$i];
            $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                $fileErrors[] = "Unsupported file type: " . htmlspecialchars($name);
                continue;
            }

            $target = $uploadDir . '/' . uniqid() . '_' . basename($name);
            if (!move_uploaded_file($tmp, $target)) {
                $fileErrors[] = "Failed to move uploaded file: " . htmlspecialchars($name);
                continue;
            }

            if ($ext === 'xlsx' || $ext === 'xls') {
                if (stripos($name, 'PortfolioValuation') !== false) {
                    $pvFiles[] = $target;
                } elseif (stripos($name, 'Allocation Analysis') !== false || stripos($name, 'Allocation_Analysis') !== false) {
                    $aaFiles[] = $target;
                } elseif (stripos($name, 'Running Systematic Transactions') !== false) {
                    $rstFiles[] = $target;
                } elseif (stripos($name, 'Portfolio Summary') !== false) {
                    $psFiles[] = $target;
                }
            } elseif ($ext === 'pdf' && stripos($name, 'GoalStatusReport') !== false) {
                $pdfFiles[] = $target;
            }
        }

        $pvAll = [];
        foreach ($pvFiles as $f) {
            $tmp   = parsePortfolioValuation($f);
            $pvAll = array_replace_recursive($pvAll, $tmp);
        }

        $aaAll = [];
        foreach ($aaFiles as $f) {
            $tmp   = parseAllocationAnalysis($f);
            $aaAll = array_replace_recursive($aaAll, $tmp);
        }

        $rstAll = [];
        foreach ($rstFiles as $f) {
            $tmp = parseRunningSystematicTransactions($f);
            foreach ($tmp as $client => $schemes) {
                if (!isset($rstAll[$client])) $rstAll[$client] = [];
                foreach ($schemes as $scheme => $amt) {
                    if (!isset($rstAll[$client][$scheme])) $rstAll[$client][$scheme] = 0;
                    $rstAll[$client][$scheme] += $amt;
                }
            }
        }

        $psAll = [];
        foreach ($psFiles as $f) {
            $tmp   = parsePortfolioSummary($f);
            $psAll = array_replace_recursive($psAll, $tmp);
        }

        $allClientReports = [];
        $validClientNames = [];

        if ($pdfFiles) {
            foreach ($pdfFiles as $pdfFile) {
                $pdfGoal = parseGoalStatusPdf($pdfFile);
                if (!empty($pdfGoal['client_name'])) {
                    $validClientNames[] = $pdfGoal['client_name'];
                }
                $reports = buildClientReports($pvAll, $aaAll, $rstAll, $psAll, $pdfGoal);
                $allClientReports = array_replace_recursive($allClientReports, $reports);
            }
        } else {
            $reports = buildClientReports($pvAll, $aaAll, $rstAll, $psAll, ['client_name'=>'','as_on'=>'','goals'=>[]]);
            $allClientReports = array_replace_recursive($allClientReports, $reports);
        }

        $validSet = array_flip($validClientNames);

        $pdo = getPdo();

        $stmtClient = $pdo->prepare("
            INSERT INTO clients
                (name, as_on, total_amount, profit, cagr, xirr,
                 total_goal_current, total_goal_target, total_sip,
                 greeting_prefix, intro_text, closing_text, rationale_text)
            VALUES
                (:name, :as_on, :total_amount, :profit, :cagr, :xirr,
                 :total_goal_current, :total_goal_target, :total_sip,
                 :greeting_prefix, :intro_text, :closing_text, :rationale_text)
        ");

        $stmtGoal = $pdo->prepare("
            INSERT INTO client_goals
                (client_id, goal, goal_date, current_amount, sip_swp,
                 target_amount, projected, completion, status)
            VALUES
                (:client_id, :goal, :goal_date, :current_amount, :sip_swp,
                 :target_amount, :projected, :completion, :status)
        ");

        $stmtAlloc = $pdo->prepare("
            INSERT INTO client_allocations
                (client_id, asset, share_pct)
            VALUES
                (:client_id, :asset, :share_pct)
        ");

        $stmtScheme = $pdo->prepare("
            INSERT INTO client_schemes
                (client_id, scheme_name, sip_swp, current_value,
                 action_step, recommended_scheme, recommended_amount)
            VALUES
                (:client_id, :scheme_name, :sip_swp, :current_value,
                 :action_step, :recommended_scheme, :recommended_amount)
        ");

        $stmtAnnex = $pdo->prepare("
            INSERT INTO client_annexures
                (client_id, line_text)
            VALUES
                (:client_id, :line_text)
        ");

        $savedCount = 0;

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Client Goal & Portfolio Summary</title>
            
            <!-- Modern Fonts -->
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
            
            <!-- Modern Styling -->
            <link rel="stylesheet" href="public/css/styles.css">
            
            <style>
                /* Additional specific styles */
                .report-table {
                    width: 70%;
                    margin: 0 auto 20px 0;
                }
                .report-table.small {
                    width: 40%;
                }
                
                .client-report {
                    page-break-after: always;
                }
                
                /* ✅ Ensure textareas are always editable */
                .large-textarea {
                    cursor: text;
                }
                
                .large-textarea[readonly] {
                    background: #f9f9f9;
                    cursor: not-allowed;
                }
            </style>
        </head>
        <body>

        <div class="nav-bar">
            <a href="upload.php" class="nav-button">Upload New Files</a>
            <a href="view_saved_reports.php" class="nav-button">View Saved Reports</a>
            <button onclick="window.print()" class="nav-button" type="button">Print</button>
        </div>

        <?php if ($fileErrors): ?>
            <div class="flash flash-error">
                <?php foreach ($fileErrors as $err) {
                    echo htmlspecialchars($err) . '<br>';
                } ?>
            </div>
        <?php endif; ?>

        <?php
        if (!$allClientReports) {
            echo "<div class='flash flash-error'>No client data could be extracted. Please check that the correct files were uploaded.</div>";
        } else {
            foreach ($allClientReports as $client => $data) {
                if (!empty($validSet) && !isset($validSet[$data['name']])) continue;

                $name       = $data['name'];
                $asOn       = $data['as_on'] ?? '';
                $allocation = $data['allocation'] ?? [];
                $schemes    = $data['schemes'] ?? [];
                $goals      = $data['goals'] ?? [];

                $totals  = $data['current']['totals'] ?? [
                    'purchase'      => 0,
                    'current'       => 0,
                    'profit'        => 0,
                    'cagr_weighted' => 0,
                    'xirr_weighted' => 0
                ];
                $summary = $data['current']['summary'] ?? null;

                $totalAmount = $totals['current'];
                $profit      = $summary['profit'] ?? $totals['profit'];
                $cagr        = $totals['cagr_weighted'];
                $xirr        = $summary['xirr'] ?? $totals['xirr_weighted'];

                $totalSip         = 0;
                $totalGoalCurrent = 0;
                $totalGoalTarget  = 0;
                foreach ($goals as $g) {
                    $totalSip         += $g['running_sip']   ?? 0;
                    $totalGoalCurrent += $g['current_value'] ?? 0;
                    $totalGoalTarget  += $g['target_amount'] ?? 0;
                }

                if ($asOn !== '') {
                    $annexureLinesForClient = [
                        "PDF document showing portfolio performance from inception including redeemed schemes reported on : " . $asOn,
                        "Current portfolio reported on : " . $asOn,
                        "Goal report reported on : " . $asOn,
                    ];
                } else {
                    $annexureLinesForClient = [];
                }

                // --- DUPLICATE CLEANUP: remove existing rows for same name + as_on ---
                if ($asOn !== '') {
                    $delStmt = $pdo->prepare("DELETE FROM clients WHERE name = :name AND as_on = :as_on");
                    $delStmt->execute([':name' => $name, ':as_on' => $asOn]);
                }

                // ----- SAVE MASTER ROW -----
                $stmtClient->execute([
                    ':name'               => $name,
                    ':as_on'              => $asOn,
                    ':total_amount'       => $totalAmount,
                    ':profit'             => $profit,
                    ':cagr'               => $cagr,
                    ':xirr'               => $xirr,
                    ':total_goal_current' => $totalGoalCurrent,
                    ':total_goal_target'  => $totalGoalTarget,
                    ':total_sip'          => $totalSip,
                    ':greeting_prefix'    => $greetingBase,
                    ':intro_text'         => $introText,
                    ':closing_text'       => $closingText,
                    ':rationale_text'     => $rationaleText,
                ]);

                $clientId = (int)$pdo->lastInsertId();
                $savedCount++;

                // ----- SAVE GOALS -----
                foreach ($goals as $g) {
                    $stmtGoal->execute([
                        ':client_id'      => $clientId,
                        ':goal'           => $g['goal']          ?? '',
                        ':goal_date'      => $g['goal_date']     ?? '',
                        ':current_amount' => $g['current_value'] ?? 0,
                        ':sip_swp'        => $g['running_sip']   ?? 0,
                        ':target_amount'  => $g['target_amount'] ?? 0,
                        ':projected'      => $g['projected']     ?? 0,
                        ':completion'     => $g['completion']    ?? 0,
                        ':status'         => $g['status']        ?? '',
                    ]);
                }

                // ----- SAVE ALLOCATION -----
                foreach ($allocation as $asset => $share) {
                    $stmtAlloc->execute([
                        ':client_id' => $clientId,
                        ':asset'     => $asset,
                        ':share_pct' => $share,
                    ]);
                }

                // ----- SAVE SCHEMES -----
                foreach ($schemes as $s) {
                    $stmtScheme->execute([
                        ':client_id'          => $clientId,
                        ':scheme_name'        => $s['scheme']        ?? '',
                        ':sip_swp'            => $s['sip_swp']       ?? 0,
                        ':current_value'      => $s['current_value'] ?? 0,
                        ':action_step'        => 'Continue',
                        ':recommended_scheme' => null,
                        ':recommended_amount' => 0,
                    ]);
                }

                // ----- SAVE ANNEXURES -----
                foreach ($annexureLinesForClient as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $stmtAnnex->execute([
                        ':client_id' => $clientId,
                        ':line_text' => $line,
                    ]);
                }

                echo '<h2>' . htmlspecialchars($name) . '</h2>';

                renderClientReport(
                    $data,
                    $greetingBase,
                    $introText,
                    $closingText,
                    $rationaleText,
                    $annexureLinesForClient,
                    $clientId
                );
            }

            if ($savedCount > 0) {
                echo "<div class='flash flash-success'>Successfully saved reports for {$savedCount} client(s). You can now edit and save each report.</div>";
            }
        }
        ?>
        <div id="toast" class="toast"></div>

        <script>
            function showToast(msg) {
                const toast = document.getElementById('toast');
                toast.textContent = msg;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 2000);
            }

            document.querySelectorAll('.edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function () {
                    const block = btn.closest('.editable-block');
                    const span  = block.querySelector('.editable-text');
                    const clientReport = btn.closest('.client-report');
                    const clientId = clientReport ? clientReport.getAttribute('data-client-id') : null;
                    const field = span ? span.getAttribute('data-field') : null;

                    const editing = span.getAttribute('contenteditable') === 'true';

                    if (!editing) {
                        span.setAttribute('contenteditable', 'true');
                        span.focus();
                        btn.textContent = 'Save';
                    } else {
                        span.setAttribute('contenteditable', 'false');
                        btn.textContent = 'Edit';

                        if (clientId && field) {
                            const value = span.innerText.trim();

                            fetch('upload.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: new URLSearchParams({
                                    ajax: '1',
                                    client_id: clientId,
                                    field: field,
                                    value: value
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (!data.success) {
                                    alert('Save failed: ' . (data.error || 'Unknown error'));
                                } else {
                                    showToast('Saved ' + field + ' for client #' + clientId);
                                }
                            })
                            .catch(err => {
                                alert('Save error: ' + err);
                            });
                        }
                    }
                });
            });
        </script>
        </body>
        </html>
        <?php
        exit;

    } catch (Throwable $e) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Error - Client Reports</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .flash-error { padding: 10px; border-radius: 4px; background: #ffe6e6; border: 1px solid #b30000; }
                .nav-button {
                    display: inline-block;
                    margin-top: 10px;
                    padding: 6px 12px;
                    background-color: #0056b3;
                    color: #fff;
                    border-radius: 4px;
                    text-decoration: none;
                    font-size: 13px;
                }
            </style>
        </head>
        <body>
        <div class="flash-error">
            <strong>Unexpected error:</strong><br>
            <?php echo htmlspecialchars($e->getMessage()); ?>
        </div>
        <a href="upload.php" class="nav-button">Back to Upload</a>
        </body>
        </html>
        <?php
        exit;
    }
}

/* ---------- INITIAL UPLOAD PAGE (GET) ---------- */
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Client Files</title>
    
    <!-- Modern Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Modern Styling -->
    <link rel="stylesheet" href="public/css/styles.css">
    
    <style>
        body { max-width: 800px; margin: 40px auto; }
        
        label {
            display: block;
            margin-top: 20px;
            font-weight: 600;
            color: #0288D1;
            font-size: 14px;
        }
        
        input[type="file"],
        input[type="text"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #E3F2FD;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            margin-top: 8px;
            font-size: 14px;
        }
        
        input[type="file"]:focus,
        input[type="text"]:focus,
        textarea:focus {
            border-color: #4FC3F7;
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 195, 247, 0.1);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        button[type="submit"] {
            margin-top: 25px;
            padding: 12px 30px;
            background: linear-gradient(135deg, #4FC3F7 0%, #29B6F6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(41, 182, 246, 0.3);
        }
        
        button[type="submit"]:hover {
            background: linear-gradient(135deg, #29B6F6 0%, #0288D1 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(41, 182, 246, 0.4);
        }
        
        .form-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="upload.php" class="nav-button">Upload New Files</a>
    <a href="view_saved_reports.php" class="nav-button">View Saved Reports</a>
</div>

<h1>Upload Client Data Files</h1>

<div class="form-section">
    <form method="post" enctype="multipart/form-data">
        <label for="client_files">Select Excel &amp; PDF files (multiple allowed):</label>
        <input type="file" name="client_files[]" id="client_files" multiple required>
        <button type="submit">Create Reports</button>
    </form>
</div>
</body>
</html>
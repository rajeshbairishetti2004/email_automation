<?php
// parsers.php
// - All file parsing functions

require_once __DIR__ . '/vendor/autoload.php';
require_once 'db_config.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/* ---------- PARSERS ---------- */

function parsePortfolioValuation(string $path): array {
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getSheetByName('1. Mutual Fund') ?? $spreadsheet->getSheet(0);
    $rows = $sheet->toArray(null, true, true, true);
    if (!$rows) return [];
    $rows = array_values($rows);

    $headerIndex = findHeaderRowForPV($rows);
    if ($headerIndex === null || !isset($rows[$headerIndex]) || !is_array($rows[$headerIndex])) {
        return [];
    }

    $headerRow = $rows[$headerIndex];
    $dataRows  = array_slice($rows, $headerIndex + 1);

    $colScheme   = findColumnByKeywords($headerRow, ['scheme name', 'scheme']);
    $colPurchase = findColumnByKeywords($headerRow, ['purchase value', 'cost value', 'invested', 'cost']);
    $colCurrent  = findColumnByKeywords($headerRow, ['current value', 'current']);
    $colCagr     = findColumnByKeywords($headerRow, ['cagr']);
    $colXirr     = findColumnByKeywords($headerRow, ['xirr']);
    $colClient   = findColumnByKeywords($headerRow, ['investor name', 'client name', 'holder']);
    $colAbs      = findColumnByKeywords($headerRow, ['absolute return', 'abs return', 'absolute', 'abs %']);

    // Fallback: If "Client Name" column isn't found in the row, assume the file belongs to the main client from filename.
    // This aggregates Family holdings (Ganesh + Sudha) into one report if they are in the same file.
    $fallbackClient = clientNameFromFilename($path) ?? 'Client';
    
    $data = [];
    $grandTotals = [];

    foreach ($dataRows as $row) {
        if (!is_array($row)) continue;

        $scheme = $colScheme !== null ? trim((string)$row[$colScheme]) : '';
        if ($scheme === '') continue;

        $normScheme = strtolower(trim(preg_replace('/\s+/', ' ', $scheme)));
        
        // If colClient is found, use it. Otherwise, group everything under the Fallback Client (Family Head).
        $client = ($colClient !== null && !empty($row[$colClient])) ? trim((string)$row[$colClient]) : $fallbackClient;
        
        // ** AGGREGATION FIX: Force all rows in this file to the single Family Head if requested **
        // If you want separate reports per person, keep the line above. 
        // If you want ONE aggregated report for the whole file, uncomment the line below:
        $client = $fallbackClient; 

        if (preg_match('/\([A-Z0-9]{10}\)/', $scheme)) {
            continue;
        }

        $purchase = $colPurchase !== null ? parseIndianNumber((string)$row[$colPurchase]) : 0.0;
        $current  = $colCurrent  !== null ? parseIndianNumber((string)$row[$colCurrent])  : 0.0;
        $cagr     = $colCagr     !== null ? (float)str_replace(['%', ','], '', (string)$row[$colCagr]) : 0.0;
        $xirr     = $colXirr     !== null ? (float)str_replace(['%', ','], '', (string)$row[$colXirr]) : 0.0;

        // Capture Grand Totals
        if (strpos($normScheme, 'grand total') !== false) {
            $absoluteFromFile = null;
            if ($colAbs !== null && isset($row[$colAbs])) {
                $rawAbs = (string)$row[$colAbs];
                if (trim($rawAbs) !== '') {
                    $absoluteFromFile = parseIndianNumber($rawAbs);
                }
            }

            $grandTotals[$client] = [
                'current'          => $current,
                'cagr'             => $cagr,
                'absolute_return'  => $absoluteFromFile,
            ];
            continue;
        }

        // Skip Category/Total rows
        if (strpos($normScheme, 'total') !== false ||
            $normScheme === 'equity' ||
            $normScheme === 'hybrid' || 
            $normScheme === 'debt') {
            continue;
        }

        if (!isset($data[$client])) {
            $data[$client] = [
                'schemes' => [],
                'totals'  => [
                    'purchase'        => 0,
                    'current'         => 0,
                    'profit'          => 0,
                    'cagr_sum'        => 0,
                    'xirr_sum'        => 0,
                    'absolute_return' => 0,
                ]
            ];
        }

        // --- AGGREGATION LOGIC ---
        // If the scheme already exists for this client, ADD to it.
        if (isset($data[$client]['schemes'][$scheme])) {
            $existing = &$data[$client]['schemes'][$scheme];
            
            $existing['purchase_value'] += $purchase;
            $existing['current_value']  += $current;
            
            // For CAGR/XIRR, usually we take the value from the larger holding as representative
            if ($current > ($existing['current_value'] - $current)) {
                $existing['cagr'] = $cagr;
                $existing['xirr'] = $xirr;
            }
        } else {
            // New Entry
            $data[$client]['schemes'][$scheme] = [
                'scheme'         => $scheme,
                'purchase_value' => $purchase,
                'current_value'  => $current,
                'cagr'           => $cagr,
                'xirr'           => $xirr,
            ];
        }

        // Update Totals
        $data[$client]['totals']['purchase'] += $purchase;
        $data[$client]['totals']['current']  += $current;
        $data[$client]['totals']['profit']   += ($current - $purchase);
        $data[$client]['totals']['cagr_sum'] += $cagr * $current;
        $data[$client]['totals']['xirr_sum'] += $xirr * $current;
    }

    // Finalize weighted averages
    foreach ($data as $client => &$info) {
        $totals = &$info['totals'];
        $curBase = max($totals['current'], 1);
        $totals['cagr_weighted'] = $totals['cagr_sum'] / $curBase;
        $totals['xirr_weighted'] = $totals['xirr_sum'] / $curBase;

        $fileGrandTotalAbs = null;

        if (isset($grandTotals[$client])) {
            $totals['current']       = $grandTotals[$client]['current'];
            $totals['cagr_weighted'] = $grandTotals[$client]['cagr'];
            if (array_key_exists('absolute_return', $grandTotals[$client])) {
                $fileGrandTotalAbs = $grandTotals[$client]['absolute_return'];
            }
        }

        $totalCost    = $totals['purchase'];
        $totalCurrent = $totals['current'];

        // Determine Absolute Return with safety check
        $finalAbsReturn = 0;
        if ($fileGrandTotalAbs !== null) {
            $finalAbsReturn = $fileGrandTotalAbs;
        } elseif ($totalCost > 0) {
            // Formula: ((Current Value - Cost Value) / Cost Value) * 100
            $finalAbsReturn = (($totalCurrent - $totalCost) / $totalCost) * 100;
        }

        // Assign final values to the totals array
        $totals['profit']          = $totalCurrent - $totalCost;
        $totals['absolute_return'] = $finalAbsReturn;
    }

    return $data;
}

function parseAllocationAnalysis(string $path): array {
    $spreadsheet = IOFactory::load($path);
    $client      = clientNameFromFilename($path) ?? 'Client';

    $allocations = []; // Store calculated percentages here

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $rows = $sheet->toArray(null, true, true, true);
        if (!$rows) continue;
        $rows = array_values($rows);

        $headerIndex = findHeaderRowGeneric($rows, [
            ['scheme name'],
            ['equity'],
            ['debt'],
            ['gold'],
            ['global equity'],
            ['total']
        ]);
        if ($headerIndex === null || !isset($rows[$headerIndex]) || !is_array($rows[$headerIndex])) {
            continue;
        }

        $headerRow = $rows[$headerIndex];
        $dataRows  = array_slice($rows, $headerIndex + 1);

        $colScheme = findColumnByKeywords($headerRow, ['scheme name']);
        $colEquity = findColumnByKeywords($headerRow, ['equity']);
        $colDebt   = findColumnByKeywords($headerRow, ['debt']);
        $colGold   = findColumnByKeywords($headerRow, ['gold']);
        $colGlobal = findColumnByKeywords($headerRow, ['global equity']);
        $colTotal  = findColumnByKeywords($headerRow, ['total']);

        $eqAmt = $debtAmt = $goldAmt = $globalAmt = $totalAmt = 0.0;

        foreach ($dataRows as $row) {
            if (!is_array($row)) continue;

            $schemeName = $colScheme !== null ? trim((string)$row[$colScheme]) : '';
            $norm       = strtolower(trim($schemeName));

            if (strpos($norm, 'grand total') !== false) {
                $eqAmt     = $colEquity !== null ? parseIndianNumber((string)$row[$colEquity]) : 0.0;
                $debtAmt   = $colDebt   !== null ? parseIndianNumber((string)$row[$colDebt])   : 0.0;
                $goldAmt   = $colGold   !== null ? parseIndianNumber((string)$row[$colGold])   : 0.0;
                $globalAmt = $colGlobal !== null ? parseIndianNumber((string)$row[$colGlobal]) : 0.0;
                $totalAmt  = $colTotal  !== null ? parseIndianNumber((string)$row[$colTotal])  : 0.0;
                break;
            }
        }

        if ($totalAmt > 0) {
            // 1. Equity = Local Equity + Global Equity (Consolidated)
            $finalEquityAmt = $eqAmt + $globalAmt;

            // 2. Others = Total - (Everything Else)
            $othersAmt = $totalAmt - ($eqAmt + $globalAmt + $debtAmt + $goldAmt);
            if ($othersAmt < 0) $othersAmt = 0; // Fix small floating point errors

            // 3. Calculate Percentages
            if ($finalEquityAmt > 0) $allocations['Equity'] = ($finalEquityAmt / $totalAmt) * 100.0;
            if ($debtAmt > 0)        $allocations['Debt']   = ($debtAmt / $totalAmt) * 100.0;
            if ($goldAmt > 0)        $allocations['Gold']   = ($goldAmt / $totalAmt) * 100.0;
            if ($othersAmt > 0)      $allocations['Others'] = ($othersAmt / $totalAmt) * 100.0;
            
            break; // Found our totals, stop looking
        }
    }

    $data = [];
    $data[$client] = ['asset_allocation' => $allocations];

    return $data;
}

function parseRunningSystematicTransactions(string $path): array {
    $spreadsheet = IOFactory::load($path);
    $sheet       = $spreadsheet->getSheet(0);
    $rows        = $sheet->toArray(null, true, true, true);
    if (!$rows) return [];
    $rows = array_values($rows);

    $headerIndex = findHeaderRowGeneric($rows, [
        ['scheme name', 'scheme'],
        ['installment', 'amount']
    ]);
    if ($headerIndex === null || !isset($rows[$headerIndex]) || !is_array($rows[$headerIndex])) {
        return [];
    }

    $headerRow = $rows[$headerIndex];
    $dataRows  = array_slice($rows, $headerIndex + 1);

    $colClient = findColumnByKeywords($headerRow, ['investor name', 'client name', 'holder']);
    $colScheme = findColumnByKeywords($headerRow, ['scheme name', 'scheme']);
    $colType   = findColumnByKeywords($headerRow, ['txn type', 'transaction type', 'type']);
    $colAmt    = findColumnByKeywords($headerRow, ['installment', 'amount']);

    $fallbackClient = clientNameFromFilename($path) ?? 'Client';
    $data           = [];

    foreach ($dataRows as $row) {
        if (!is_array($row)) continue;

        $scheme = $colScheme !== null ? trim((string)$row[$colScheme]) : '';
        if ($scheme === '') continue;

        $client = $colClient !== null ? trim((string)$row[$colClient]) : $fallbackClient;
        $amount = $colAmt    !== null ? parseIndianNumber((string)$row[$colAmt]) : 0.0;
        if ($amount == 0) continue;

        $type = $colType !== null ? strtoupper(trim((string)$row[$colType])) : 'SIP';

        if (!isset($data[$client])) $data[$client] = [];
        if (!isset($data[$client][$scheme])) $data[$client][$scheme] = 0;
        $data[$client][$scheme] += $amount;
    }

    return $data;
}
function parsePortfolioSummary(string $path): array {
    $spreadsheet = IOFactory::load($path);
    $sheet       = $spreadsheet->getSheet(0);
    $rows        = $sheet->toArray(null, true, true, true);
    if (!$rows) return [];
    $rows = array_values($rows);

    $headerIndex = findHeaderRowGeneric($rows, [
        ['unrealised gain', 'unrealized gain'],
        ['absolute return %', 'abs return %'], // Added keywords
        ['xirr']
    ]);
    if ($headerIndex === null || !isset($rows[$headerIndex])) return [];

    $headerRow = $rows[$headerIndex];
    $dataRows  = array_slice($rows, $headerIndex + 1);

    $colTotal  = findColumnByKeywords($headerRow, ['current value', 'value']);
    $colUnreal = findColumnByKeywords($headerRow, ['unrealised gain', 'unrealized gain']);
    $colReal   = findColumnByKeywords($headerRow, ['realised gain', 'realized gain']);
    $colXirr   = findColumnByKeywords($headerRow, ['xirr']);
    // New: Find the Absolute Return column
    $colAbsPct = findColumnByKeywords($headerRow, ['absolute return %', 'abs return %', 'absolute return']);

    $client = clientNameFromFilename($path) ?? 'Client';
    $best   = null;

    foreach ($dataRows as $row) {
        if (!is_array($row)) continue;

        $total  = $colTotal  !== null ? parseIndianNumber((string)($row[$colTotal]  ?? '')) : 0.0;
        $unreal = $colUnreal !== null ? parseIndianNumber((string)($row[$colUnreal] ?? '')) : 0.0;
        $real   = $colReal   !== null ? parseIndianNumber((string)($row[$colReal]   ?? '')) : 0.0;
        $xirr   = $colXirr   !== null ? (float)str_replace(['%', ','], '', (string)($row[$colXirr] ?? '')) : 0.0;
        
        // Capture absolute return pct
        $absPct = 0.0;
        if ($colAbsPct !== null && isset($row[$colAbsPct])) {
            $absPct = (float)str_replace(['%', ','], '', (string)$row[$colAbsPct]);
        }

        $isGrand = false;
        foreach ($row as $cellVal) {
            $cellStr = strtolower((string)$cellVal);
            if (strpos($cellStr, 'grand total') !== false || $cellStr === 'total') {
                $isGrand = true;
                break;
            }
        }

        $record = [
            'total_amount'    => $total,
            'profit'          => $unreal + $real,
            'cagr'            => 0.0,
            'xirr'            => $xirr,
            'absolute_return' => $absPct, // Added to record
        ];

        if ($isGrand) {
            $best = $record;
            break;
        }
        if ($best === null && $total > 0) $best = $record;
    }

    $data = [];
    if ($best !== null) {
        $data[$client] = $best;
    }
    return $data;
}

function parseGoalStatusPdf(string $path): array
{
    $parser = new PdfParser();
    $pdf    = $parser->parseFile($path);
    $text   = $pdf->getText();

    /* ---------- CLIENT NAME ---------- */
    $clientName = clientNameFromFilename($path) ?? '';

    /* ---------- EMAIL (HEADER PARSING) ---------- */
    $email = '';
    if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $text, $mEmail)) {
        $email = strtolower(trim($mEmail[0]));
    }

    /* ---------- AS ON DATE ---------- */
    $asOn = '';
    if (preg_match('/As on\s+([\d\/-]+)/i', $text, $m)) {
        $asOn = trim($m[1]);
    }

    /* ---------- MAP GOAL DETAIL PAGES (Projected & Shortfall) ---------- */
    // Updated Regex to explicitly capture Goal Title, Projected, and Shortfall from detail sections
    preg_match_all(
        '/\n([A-Za-z0-9\/,&\-\+\s]+)\nGoal Date.*?(Projected Completion\s*:\s*₹?\s*([\d,]+)).*?(Shortfall\s*:\s*₹?\s*(-?[\d,]+))/is',
        $text,
        $detailMatches,
        PREG_SET_ORDER
    );

    $goalDetailProjected = [];
    $goalDetailShortfall = []; // Initialized correctly now
    foreach ($detailMatches as $m) {
        $goalTitle = trim($m[1]);
        // Map Projected Completion (Match index 3 from the inner parenthesis)
        $goalDetailProjected[$goalTitle] = parseIndianNumber($m[3]);
        // Map Shortfall (Match index 5 from the inner parenthesis)
        $goalDetailShortfall[$goalTitle] = parseIndianNumber($m[5]);
    }

    /* ---------- EXTRACT GOAL SUMMARY BLOCK ---------- */
    if (!preg_match('/Goal Summary.*?Options to meet shortfall(.*?)Total\s*:/is', $text, $mSummary)) {
        return [
            'client_name' => $clientName,
            'email'       => $email,
            'as_on'       => $asOn,
            'goals'       => [],
        ];
    }

    $lines = preg_split('/\R/', trim($mSummary[1]));

    /* ---------- IDENTIFY COLUMN INDEXES ---------- */
    $headerLine = '';
    foreach ($lines as $l) {
        if (stripos($l, 'Projected Value') !== false) {
            $headerLine = $l;
            break;
        }
    }

    $headerCols = preg_split('/\s+/', trim($headerLine));
    $idxProjected = null;
    $idxSIP       = null;

    foreach ($headerCols as $i => $h) {
        if (stripos($h, 'Projected') !== false) $idxProjected = $i;
        if (stripos($h, 'SIP') !== false)       $idxSIP       = $i;
    }

    $goals = [];

    /* ---------- PROCESS GOAL ROWS ---------- */
    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line === '' || stripos($line, 'Goal Date') !== false) continue;

        $parts = explode(' ', $line);

        /* --- Find Goal Date --- */
        $dateIndex = null;
        foreach ($parts as $i => $p) {
            if (preg_match('/\d{2}-[A-Za-z]{3}-\d{4}/', $p)) {
                $dateIndex = $i;
                break;
            }
        }
        if ($dateIndex === null) continue;

        $goalName = trim(implode(' ', array_slice($parts, 0, $dateIndex)));
        $goalDate = $parts[$dateIndex];

        $after = array_slice($parts, $dateIndex + 2); // skip date + years left
        if (count($after) < 3) continue;

        $targetAmount = parseIndianNumber($after[0]);
        $completion   = (float)str_replace('%', '', $after[1]);
        $currentValue = parseIndianNumber($after[2]);

        /* ---------- EXTRACT NUMBERS FOR POSITIONAL FALLBACK ---------- */
        $numbers = [];
        foreach ($after as $p) {
            // Updated regex to include negative signs for shortfalls in the numbers array
            if (preg_match('/-?[\d,]+/', $p)) {
                $numbers[] = parseIndianNumber($p);
            }
        }

        /* ---------- SIP ---------- */
        $runningSip = (count($numbers) >= 5) ? $numbers[3] : 0;

        /* ---------- PROJECTED VALUE ---------- */
        $projected = 0;
        foreach ($goalDetailProjected as $title => $val) {
            if (stripos($title, $goalName) !== false) {
                $projected = $val;
                break;
            }
        }
        if ($projected == 0 && $idxProjected !== null) {
            $projPos = $idxProjected - ($dateIndex + 2);
            if (isset($after[$projPos])) {
                $projected = parseIndianNumber($after[$projPos]);
            }
        }

        /* ---------- SHORTFALL ---------- */
        $shortfall = 0;
        // 1. Try extracting from detail page mapping (Highest Accuracy)
        foreach ($goalDetailShortfall as $title => $val) {
            if (stripos($title, $goalName) !== false) {
                $shortfall = $val;
                break;
            }
        }
        // 2. Fallback to summary table position
        if ($shortfall == 0 && count($numbers) > 0) {
            $shortfall = end($numbers); 
        }

        // Status determination logic
        $status = ($shortfall > 0) ? 'Invest More' : 'On Track';

        $goals[] = [
            'goal'          => $goalName,
            'goal_date'     => $goalDate,
            'target_amount' => $targetAmount,
            'current_value' => $currentValue,
            'running_sip'   => $runningSip,
            'projected'     => $projected,
            'shortfall'     => $shortfall,
            'completion'    => $completion,
            'status'        => $status,
        ];
    }

    return [
        'client_name' => $clientName,
        'email'       => $email,
        'as_on'       => $asOn,
        'goals'       => $goals,
    ];
}
function buildClientReports(array $pv, array $aa, array $rst, array $ps, array $pdfGoal): array {
    $clients    = [];
    $targetName = $pdfGoal['client_name'] ?? null;

    if ($targetName) {
        $pv  = filterClientArrayForTarget($pv,  $targetName);
        $aa  = filterClientArrayForTarget($aa,  $targetName);
        $rst = filterClientArrayForTarget($rst, $targetName);
        $ps  = filterClientArrayForTarget($ps,  $targetName);
    }

    foreach ($pv as $client => $info) {
        if (!isset($clients[$client])) {
            $clients[$client] = [
                'name'       => $client,
                'current'    => [],
                'goals'      => [],
                'allocation' => [],
                'schemes'    => [],
                'as_on'      => '',
            ];
        }
        $clients[$client]['schemes']           = $info['schemes'];
        $clients[$client]['current']['totals'] = $info['totals'];
    }

    foreach ($aa as $client => $info) {
        if (!isset($clients[$client])) {
            $clients[$client] = [
                'name'       => $client,
                'current'    => [],
                'goals'      => [],
                'allocation' => [],
                'schemes'    => [],
                'as_on'      => '',
            ];
        }
        $clients[$client]['allocation'] = $info['asset_allocation'];
    }

    foreach ($rst as $client => $schemes) {
        if (!isset($clients[$client])) {
            $clients[$client] = [
                'name'       => $client,
                'current'    => [],
                'goals'      => [],
                'allocation' => [],
                'schemes'    => [],
                'as_on'      => '',
            ];
        }
        foreach ($schemes as $schemeName => $amount) {
            if (!isset($clients[$client]['schemes'][$schemeName])) {
                $clients[$client]['schemes'][$schemeName] = [
                    'scheme'         => $schemeName,
                    'purchase_value' => 0,
                    'current_value'  => 0,
                    'cagr'           => 0,
                    'xirr'           => 0,
                ];
            }
            $clients[$client]['schemes'][$schemeName]['sip_swp'] = $amount;
        }
    }

foreach ($ps as $client => $summary) {
        if (!isset($clients[$client])) {
            $clients[$client] = [ /* ... initialization ... */ ];
        }
        $clients[$client]['current']['summary'] = $summary;

        // CRITICAL FIX: If the Portfolio Summary has an absolute return, 
        // map it into the totals section so view_report.php picks it up.
        if (isset($summary['absolute_return']) && $summary['absolute_return'] != 0) {
            $clients[$client]['current']['totals']['absolute_return'] = $summary['absolute_return'];
        }
    }

    // Inside buildClientReports function
if (!empty($pdfGoal['client_name'])) {
    $cName = $pdfGoal['client_name'];
    if (!isset($clients[$cName])) {
        $clients[$cName] = [
            'name'       => $cName,
            'current'    => [],
            'goals'      => [],
            'allocation' => [],
            'schemes'    => [],
            'as_on'      => '',
            'email'      => '',
        ];
    }
    $clients[$cName]['goals'] = $pdfGoal['goals'];
    $clients[$cName]['as_on'] = $pdfGoal['as_on'];
    $clients[$cName]['email'] = $pdfGoal['email'] ?? '';
    $clients[$cName]['name']  = $cName;
}
    return $clients;
}

function parse_scheme_xlsx($filepath) {
    $names = [];
    $spreadsheet = IOFactory::load($filepath);
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        $cell = $cellIterator->current();
        $val = trim((string)$cell->getValue());
        if ($val !== '' && strtolower($val) !== 'scheme_name') { // skip header if present
            $names[] = $val;
        }
    }
    return $names;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = file_get_contents('php://input');

    $data = json_decode($input, true);

    $action = $data['action'] ?? '';



    if ($action === 'delete_scheme_rows') {

        require_once 'db_config.php';

        $pdo = getPdo();

        $ids = $data['scheme_ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {

            echo json_encode(['success' => false, 'error' => 'No IDs']);

            exit;

        }

        $in = str_repeat('?,', count($ids) - 1) . '?';

        $stmt = $pdo->prepare("DELETE FROM client_schemes WHERE id IN ($in)");

        $ok = $stmt->execute($ids);

        echo json_encode(['success' => $ok]);

        exit;

    }



    if ($action === 'save_scheme_table') {

        require_once 'db_config.php';

        $pdo = getPdo();

        $clientId = (int)($data['client_id'] ?? 0);

        $rows = $data['rows'] ?? [];

        if ($clientId <= 0) {

            echo json_encode(['success' => false, 'error' => 'Invalid client id']);

            exit;

        }

        foreach ($rows as $row) {

            $id = isset($row['id']) ? (int)$row['id'] : 0;

            $fields = [

                'scheme_name' => $row['scheme_name'] ?? '',

                'sip_swp' => $row['sip_swp'] ?? '',

                'current_value' => $row['current_value'] ?? '',

                'action_step' => $row['action_step'] ?? '',

                'recommended_scheme' => $row['recommended_scheme'] ?? '',

                'recommended_amount' => $row['recommended_amount'] ?? '',

            ];

            if ($id > 0) {

                $pdo->prepare("UPDATE client_schemes SET scheme_name=?, sip_swp=?, current_value=?, action_step=?, recommended_scheme=?, recommended_amount=? WHERE id=?")

                    ->execute([

                        $fields['scheme_name'], $fields['sip_swp'], $fields['current_value'],

                        $fields['action_step'], $fields['recommended_scheme'], $fields['recommended_amount'], $id

                    ]);

            } else {

                $pdo->prepare("INSERT INTO client_schemes (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount) VALUES (?, ?, ?, ?, ?, ?, ?)

")

                    ->execute([

                        $clientId,

                        $fields['scheme_name'],

                        $fields['sip_swp'],

                        $fields['current_value'],

                        $fields['action_step'],

                        $fields['recommended_scheme'],

                        $fields['recommended_amount']

                    ]);

            }

        }

        echo json_encode(['success' => true]);

        exit;

    }

}
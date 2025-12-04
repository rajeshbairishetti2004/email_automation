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
            $grandTotals[$client] = [
                'current' => $current,
                'cagr'    => $cagr,
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
                    'purchase' => 0,
                    'current'  => 0,
                    'profit'   => 0,
                    'cagr_sum' => 0,
                    'xirr_sum' => 0,
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
        $cur = max($info['totals']['current'], 1);
        $info['totals']['cagr_weighted'] = $info['totals']['cagr_sum'] / $cur;
        $info['totals']['xirr_weighted'] = $info['totals']['xirr_sum'] / $cur;

        // If Excel had a Grand Total row, prefer that for high-level accuracy
        if (isset($grandTotals[$client])) {
            $info['totals']['current']       = $grandTotals[$client]['current'];
            // Note: We usually keep calculated totals for consistency, but you can uncomment below to overwrite
            // $info['totals']['cagr_weighted'] = $grandTotals[$client]['cagr'];
        }
    }

    return $data;
}

function parseAllocationAnalysis(string $path): array {
    $spreadsheet = IOFactory::load($path);
    $client      = clientNameFromFilename($path) ?? 'Client';

    $equityPct = null;
    $debtPct   = null;

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
            $equityAmt = $eqAmt + $goldAmt + $globalAmt;
            $equityPct = ($equityAmt / $totalAmt) * 100.0;
            $debtPct   = ($debtAmt   / $totalAmt) * 100.0;
            break;
        }
    }

    $data = [];
    $data[$client] = ['asset_allocation' => []];

    if ($equityPct !== null) {
        $data[$client]['asset_allocation']['Equity'] = $equityPct;
    }
    if ($debtPct !== null) {
        $data[$client]['asset_allocation']['Debt']   = $debtPct;
    }

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
        ['realised gain', 'realized gain'],
        ['xirr']
    ]);
    if ($headerIndex === null || !isset($rows[$headerIndex]) || !is_array($rows[$headerIndex])) {
        return [];
    }

    $headerRow = $rows[$headerIndex];
    $dataRows  = array_slice($rows, $headerIndex + 1);

    $colTotal  = findColumnByKeywords($headerRow, ['current value', 'total current value', 'portfolio value', 'market value', 'value']);
    $colUnreal = findColumnByKeywords($headerRow, ['unrealised gain', 'unrealized gain']);
    $colReal   = findColumnByKeywords($headerRow, ['realised gain', 'realized gain']);
    $colXirr   = findColumnByKeywords($headerRow, ['xirr']);

    $client = clientNameFromFilename($path) ?? 'Client';
    $best   = null;

    foreach ($dataRows as $row) {
        if (!is_array($row)) continue;

        $total  = $colTotal  !== null ? parseIndianNumber((string)($row[$colTotal]  ?? '')) : 0.0;
        $unreal = $colUnreal !== null ? parseIndianNumber((string)($row[$colUnreal] ?? '')) : 0.0;
        $real   = $colReal   !== null ? parseIndianNumber((string)($row[$colReal]   ?? '')) : 0.0;
        $xirr   = $colXirr   !== null ? (float)str_replace(['%', ','], '', (string)($row[$colXirr] ?? '')) : 0.0;

        if ($total == 0 && $unreal == 0 && $real == 0 && $xirr == 0) continue;

        $isGrand = false;
        foreach ($row as $cellVal) {
            $cellStr = strtolower((string)$cellVal);
            if (strpos($cellStr, 'grand total') !== false || $cellStr === 'total') {
                $isGrand = true;
                break;
            }
        }

        $record = [
            'total_amount' => $total,
            'profit'       => $unreal + $real,
            'cagr'         => 0.0,
            'xirr'         => $xirr,
        ];

        if ($isGrand) {
            $best = $record;
            break;
        }
        if ($best === null) $best = $record;
    }

    $data = [];
    if ($best !== null) {
        $data[$client] = $best;
    }
    return $data;
}

// parsers.php - Corrected parseGoalStatusPdf function

function parseGoalStatusPdf(string $path): array {
    $parser = new PdfParser();
    $pdf    = $parser->parseFile($path);
    $text   = $pdf->getText();

    $clientName = clientNameFromFilename($path) ?? '';

    $asOn = '';
    if (preg_match('/As on\s+([\d\/-]+)/', $text, $mDate)) {
        $asOn = trim($mDate[1]);
    }

    $goals = [];

    if (preg_match('/Goal Summary.*?Options to meet shortfall(.*?)Total\s*:/s', $text, $mSection)) {
        $goalBlock = trim($mSection[1]);
        $lines     = preg_split('/\r\n|\r|\n/', $goalBlock);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'Goal Date') !== false) continue;

            $lineNorm = preg_replace('/\s+/', ' ', $line);
            $parts    = explode(' ', $lineNorm);

            $goalDatePos = null;
            foreach ($parts as $i => $p) {
                if (preg_match('/\d{2}-[A-Za-z]{3}-\d{4}/', $p)) {
                    $goalDatePos = $i;
                    break;
                }
            }
            if ($goalDatePos === null) continue;

            $goalName = trim(implode(' ', array_slice($parts, 0, $goalDatePos)));
            $goalDate = $parts[$goalDatePos];

            // Start rest after Goal Date (skipping Years Left)
            $rest = array_slice($parts, $goalDatePos + 2);
            // Check if we have enough elements for Target, Completion, Current, SIP, Projected, and Shortfall
            if (count($rest) < 6) continue;

            $targetAmount = parseIndianNumber($rest[0] ?? '0');
            $completion   = (float)str_replace(['%', ','], '', $rest[1] ?? '0');
            $currentValue = parseIndianNumber($rest[2] ?? '0');
            $runningSip   = parseIndianNumber($rest[3] ?? '0');
            $projected    = parseIndianNumber($rest[4] ?? '0');
            // *** CORRECTION: Extract Shortfall ***
            $shortfall    = parseIndianNumber($rest[5] ?? '0'); // Shortfall is the 6th element (index 5)

            // Note: Status logic is correctly handled in view_report.php, but we still pass the
            // original status string if the parser extracted it, though we rely on view_report.php for the rule-based status.
            $status = ($completion >= 70) ? 'On Track' : 'Needs Attention'; 

            $goals[] = [
                'goal'          => $goalName,
                'goal_date'     => $goalDate,
                'target_amount' => $targetAmount,
                'current_value' => $currentValue,
                'running_sip'   => $runningSip,
                'projected'     => $projected,
                'shortfall'     => $shortfall, // *** ADDED ***
                'completion'    => $completion,
                'status'        => $status,
            ];
        }
    }

    return [
        'client_name' => $clientName,
        'as_on'       => $asOn,
        'goals'       => $goals,
    ];
}
/* ---------- MERGING ---------- */

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
            $clients[$client] = [
                'name'       => $client,
                'current'    => [],
                'goals'      => [],
                'allocation' => [],
                'schemes'    => [],
                'as_on'      => '',
            ];
        }
        $clients[$client]['current']['summary'] = $summary;
    }

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
            ];
        }
        $clients[$cName]['goals'] = $pdfGoal['goals'];
        $clients[$cName]['as_on'] = $pdfGoal['as_on'];
        $clients[$cName]['name']  = $cName;
    }

    return $clients;
}
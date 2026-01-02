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

        $finalAbsReturn = 0;
        if ($fileGrandTotalAbs !== null) {
            $finalAbsReturn = $fileGrandTotalAbs;
        } elseif ($totalCost > 0) {
            $finalAbsReturn = (($totalCurrent - $totalCost) / $totalCost) * 100;
        }

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

    // EXTRACT EMAIL: Regex to find email address in the PDF header
    $email = '';
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $mEmail)) {
        $email = trim($mEmail[0]);
    }

    // Extract Date (As on...)
    $asOn = '';
    if (preg_match('/As on\s+([\d\/-]+)/', $text, $mDate)) {
        $asOn = trim($mDate[1]);
    }

    // ...existing goal extraction logic...
    // Assume $goals is built here

    return [
        'client_name' => $clientName,
        'email'       => $email,
        'as_on'       => $asOn,
        'goals'       => $goals ?? [],
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
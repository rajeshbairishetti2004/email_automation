<?php
// report_generator/generate_pdf.php

set_time_limit(300);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

// Get client_id from parameter
$client_id = isset($_GET['client_id']) ? $_GET['client_id'] : '';
if (empty($client_id)) {
    die("Client ID is required");
}

// Get client info
$clientInfo = getClientInfo($client_id);
$client_name = isset($clientInfo['client_name']) ? $clientInfo['client_name'] : 'Client';

// Include TCPDF
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    die("TCPDF not found. Run: composer require tecnickcom/tcpdf");
}
require_once($tcpdf_path);

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Portfolio Report Generator');
$pdf->SetAuthor($client_name);
$pdf->SetTitle('Portfolio Review - ' . $client_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->SetFont('helvetica', '', 10);

// ==================== COVER PAGE ====================
$pdf->AddPage();

$cover_html = '
<style>
    .cover { text-align: center; padding-top: 80px; }
    .title { font-size: 28px; color: #2E75B6; font-weight: bold; margin-bottom: 20px; }
    .client { font-size: 24px; color: #333; font-weight: bold; margin-bottom: 30px; }
    .date { font-size: 16px; color: #666; margin-top: 40px; }
</style>

<div class="cover">
    <div class="title">PORTFOLIO REVIEW REPORT</div>
    <div style="border-top: 2px solid #2E75B6; width: 200px; margin: 20px auto;"></div>
    <div class="client">' . htmlspecialchars($client_name) . '</div>
    <div style="font-size: 18px; color: #555;">Quarterly Portfolio Analysis</div>
    <div class="date">Generated: ' . date('F d, Y') . '</div>
    <div style="font-size: 14px; color: #777; margin-top: 10px;">Client ID: ' . htmlspecialchars($client_id) . '</div>
</div>';

$pdf->writeHTML($cover_html, true, false, true, false, '');

// ==================== TABLE OF CONTENTS ====================
$pdf->AddPage();

$toc_html = '
<style>
    .toc-title { font-size: 20px; color: #2E75B6; font-weight: bold; border-bottom: 2px solid #2E75B6; padding-bottom: 5px; margin-bottom: 20px; }
    .toc-item { margin-bottom: 8px; font-size: 12px; }
    .toc-page { color: #2E75B6; font-weight: bold; }
</style>

<div class="toc-title">TABLE OF CONTENTS</div>
<div class="toc-item"><span class="toc-page">Page 1:</span> Cover Page</div>
<div class="toc-item"><span class="toc-page">Page 2:</span> Table of Contents</div>';

// We'll add TOC items as we process slides
$all_toc_items = '';

// ==================== INCLUDE ALL 24 PAGE FILES ====================
for ($page_num = 1; $page_num <= 24; $page_num++) {
    $pdf->AddPage();
    
    $page_file = "page{$page_num}.php";
    
    if (file_exists($page_file)) {
        // Start output buffering
        ob_start();
        
        // Include the page file
        try {
            // Set variables that might be used in the page files
            $current_page = $page_num;
            include $page_file;
        } catch (Exception $e) {
            ob_end_clean();
            $content = '<p>Error loading page ' . $page_num . ': ' . $e->getMessage() . '</p>';
            ob_start();
            echo $content;
        }
        
        // Get the output
        $page_content = ob_get_clean();
        
        // Clean the content
        $page_content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $page_content); // Remove scripts
        $page_content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $page_content); // Remove style tags
        
        // Extract title from the page
        $title = "Slide {$page_num}";
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $page_content, $matches)) {
            $title = strip_tags($matches[1]);
        } elseif (preg_match('/<h2[^>]*>(.*?)<\/h2>/i', $page_content, $matches)) {
            $title = strip_tags($matches[1]);
        }
        
        // Add to TOC
        $all_toc_items .= '<div class="toc-item"><span class="toc-page">Page ' . ($page_num + 2) . ':</span> ' . htmlspecialchars($title) . '</div>';
        
        // Create slide HTML
        $slide_html = '
        <style>
            .slide-title { font-size: 16px; color: #2E75B6; font-weight: bold; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-bottom: 15px; }
            .slide-content { font-size: 11px; line-height: 1.4; }
            .page-num { position: absolute; bottom: 10px; right: 15px; font-size: 10px; color: #999; }
        </style>
        
        <div class="slide-title">' . htmlspecialchars($title) . '</div>
        <div class="slide-content">' . $page_content . '</div>
        <div class="page-num">Page ' . ($page_num + 2) . ' of 27</div>
        ';
        
        $pdf->writeHTML($slide_html, true, false, true, false, '');
        
    } else {
        // Page file doesn't exist - create placeholder
        $all_toc_items .= '<div class="toc-item"><span class="toc-page">Page ' . ($page_num + 2) . ':</span> Slide ' . $page_num . '</div>';
        
        $placeholder_html = '
        <div style="text-align: center; padding: 100px 20px; color: #999;">
            <h3>Slide ' . $page_num . '</h3>
            <p>Content not available</p>
            <p><small>File: ' . $page_file . ' not found</small></p>
        </div>
        <div style="position: absolute; bottom: 10px; right: 15px; font-size: 10px; color: #999;">
            Page ' . ($page_num + 2) . ' of 27
        </div>
        ';
        
        $pdf->writeHTML($placeholder_html, true, false, true, false, '');
    }
}

// ==================== APPENDIX ====================
$pdf->AddPage();
$appendix_html = '
<div style="border-bottom: 2px solid #2E75B6; padding-bottom: 5px; margin-bottom: 20px;">
    <h3 style="color: #2E75B6; margin: 0;">APPENDIX</h3>
</div>
<p><strong>Report Information:</strong></p>
<ul>
    <li><strong>Client:</strong> ' . htmlspecialchars($client_name) . '</li>
    <li><strong>Client ID:</strong> ' . htmlspecialchars($client_id) . '</li>
    <li><strong>Report Date:</strong> ' . date('F d, Y') . '</li>
    <li><strong>Total Pages:</strong> 27</li>
</ul>

<div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-left: 3px solid #2E75B6; font-size: 10px;">
    <p><strong>Disclaimer:</strong> This report is for informational purposes only. Past performance is not indicative of future results.</p>
</div>
<div style="position: absolute; bottom: 10px; right: 15px; font-size: 10px; color: #999;">
    Page 27 of 27
</div>';

$pdf->writeHTML($appendix_html, true, false, true, false, '');

// ==================== OUTPUT PDF ====================
$filename = 'Portfolio_Review_' . str_replace('CLIENT_', '', $client_id) . '_' . date('Ymd_His') . '.pdf';

// Force download
$pdf->Output($filename, 'D');
exit();
?>
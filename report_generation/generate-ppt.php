<?php
// generate-ppt.php - FULL COLOR VERSION

// Fix vendor path
$vendorPath = 'vendor/autoload.php';
if (!file_exists($vendorPath)) {
    $vendorPath = '../vendor/autoload.php';
}

if (!file_exists($vendorPath)) {
    die('Error: PHPPresentation library not found. Run: composer require phpoffice/phppresentation');
}

require_once $vendorPath;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Slide\Background\Color as BgColor;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Border;

// Get client data
$client_name = $_POST['client_name'] ?? 'Ms. Mukta Dutta Tomar';
$period = $_POST['period'] ?? 'January - March 2026';

// Create new presentation
$presentation = new PhpPresentation();

// Set PPT dimensions (16:9 aspect ratio - Screen)
$presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9, true);

// Set properties
$presentation->getDocumentProperties()
    ->setCreator('Finance Doctor Wealth Management')
    ->setTitle('Quarterly Portfolio Review')
    ->setSubject('Portfolio Analysis')
    ->setDescription('Quarterly review for ' . $client_name)
    ->setCompany('Finance Doctor');

// Function to extract content properly
function extractContentFromPage($pageNumber) {
    $filename = "page{$pageNumber}.php";
    if (!file_exists($filename)) {
        return "Page {$pageNumber}\n\nContent not available.";
    }
    
    ob_start();
    include $filename;
    $content = ob_get_clean();
    
    // Remove scripts and styles
    $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
    $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
    
    // Convert to plain text
    $content = strip_tags($content);
    $content = html_entity_decode($content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = trim($content);
    
    // Limit length for PPT
    if (strlen($content) > 600) {
        $content = substr($content, 0, 600) . '...';
    }
    
    return $content;
}

// ========== TITLE SLIDE ==========
$slide = $presentation->getActiveSlide();
$slide->setName('Title Slide');

// Set vibrant blue background for title slide
$bgColor = new BgColor();
$bgColor->setColor(new Color('2E75B6')); // Vibrant blue
$slide->setBackground($bgColor);

// Main Title (White text for contrast)
$titleShape = $slide->createRichTextShape()
    ->setHeight(120)
    ->setWidth(800)
    ->setOffsetX(80)
    ->setOffsetY(120);
$titleShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$titleRun = $titleShape->createTextRun('QUARTERLY PORTFOLIO REVIEW');
$titleRun->getFont()->setBold(true)->setSize(44)->setColor(new Color('FFFFFF')); // White text

// Period (Light blue text)
$periodShape = $slide->createRichTextShape()
    ->setHeight(60)
    ->setWidth(800)
    ->setOffsetX(80)
    ->setOffsetY(220);
$periodShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$periodRun = $periodShape->createTextRun($period);
$periodRun->getFont()->setBold(true)->setSize(32)->setColor(new Color('E6F2FF')); // Light blue

// Decorative separator line
$separatorShape = $slide->createRichTextShape()
    ->setHeight(10)
    ->setWidth(600)
    ->setOffsetX(180)
    ->setOffsetY(290);
$separatorRun = $separatorShape->createTextRun(str_repeat('─', 80));
$separatorRun->getFont()->setSize(14)->setColor(new Color('FFFFFF'));

// Client Name (White text on yellow background box)
$clientBox = $slide->createRichTextShape()
    ->setHeight(80)
    ->setWidth(600)
    ->setOffsetX(180)
    ->setOffsetY(320);
$clientBox->getFill()->setFillType(Fill::FILL_SOLID)
    ->setStartColor(new Color('FFC000')); // Yellow background

$clientShape = $slide->createRichTextShape()
    ->setHeight(60)
    ->setWidth(600)
    ->setOffsetX(180)
    ->setOffsetY(335);
$clientShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$clientRun = $clientShape->createTextRun('Prepared for: ' . $client_name);
$clientRun->getFont()->setBold(true)->setSize(24)->setColor(new Color('1F4E79')); // Dark blue text

// Footer (White text)
$footerShape = $slide->createRichTextShape()
    ->setHeight(40)
    ->setWidth(960)
    ->setOffsetX(0)
    ->setOffsetY(480);
$footerShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$footerRun = $footerShape->createTextRun('Finance Doctor Wealth Management • ' . date('F d, Y'));
$footerRun->getFont()->setSize(14)->setColor(new Color('FFFFFF')); // White text

// ========== AGENDA SLIDE ==========
$agendaSlide = $presentation->createSlide();
$agendaSlide->setName('Agenda');

// White background for agenda
$agendaBg = new BgColor();
$agendaBg->setColor(new Color('FFFFFF'));
$agendaSlide->setBackground($agendaBg);

// Colored header bar
$headerBar = $agendaSlide->createRichTextShape()
    ->setHeight(60)
    ->setWidth(960)
    ->setOffsetX(0)
    ->setOffsetY(0);
$headerBar->getFill()->setFillType(Fill::FILL_SOLID)
    ->setStartColor(new Color('2E75B6'));

// Agenda title (White on blue)
$agendaTitle = $agendaSlide->createRichTextShape()
    ->setHeight(60)
    ->setWidth(800)
    ->setOffsetX(80)
    ->setOffsetY(15);
$agendaTitle->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$agendaTitle->createTextRun('PRESENTATION AGENDA');
$agendaTitle->getActiveParagraph()->getFont()
    ->setBold(true)
    ->setSize(36)
    ->setColor(new Color('FFFFFF'));

// Agenda items
$agendaItems = [
    'Executive Summary',
    'Portfolio Performance Overview',
    'Asset Allocation Analysis',
    'Equity Portfolio Review',
    'Debt Portfolio Analysis',
    'Mutual Fund Performance',
    'Risk Assessment',
    'Market Outlook',
    'Investment Recommendations',
    'Action Plan & Next Steps'
];

$agendaShape = $agendaSlide->createRichTextShape()
    ->setHeight(350)
    ->setWidth(700)
    ->setOffsetX(130)
    ->setOffsetY(100);

foreach ($agendaItems as $index => $item) {
    $number = $index + 1;
    
    // Create colored bullet
    $bulletShape = $agendaSlide->createRichTextShape()
        ->setHeight(30)
        ->setWidth(30)
        ->setOffsetX(130)
        ->setOffsetY(120 + ($index * 35));
    $bulletShape->getFill()->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('FFC000')); // Yellow bullet
    $bulletShape->createTextRun($number)
        ->getFont()->setBold(true)->setSize(14)->setColor(new Color('1F4E79'));
    $bulletShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Agenda item text
    $itemText = $agendaSlide->createRichTextShape()
        ->setHeight(30)
        ->setWidth(600)
        ->setOffsetX(170)
        ->setOffsetY(120 + ($index * 35));
    $itemText->createTextRun($item)
        ->getFont()->setSize(18)->setColor(new Color('333333'));
}

// Footer (Blue text)
$agendaFooter = $agendaSlide->createRichTextShape()
    ->setHeight(30)
    ->setWidth(960)
    ->setOffsetX(0)
    ->setOffsetY(500);
$agendaFooter->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$agendaFooter->createTextRun('Finance Doctor • Professional Portfolio Review')
    ->getFont()->setSize(12)->setColor(new Color('2E75B6'));

// ========== CONTENT SLIDES ==========
for ($i = 1; $i <= 23; $i++) {
    $slide = $presentation->createSlide();
    $slide->setName("Page {$i}");
    
    // White background
    $bgColor = new BgColor();
    $bgColor->setColor(new Color('FFFFFF'));
    $slide->setBackground($bgColor);
    
    // Colored header
    $headerBg = $slide->createRichTextShape()
        ->setHeight(60)
        ->setWidth(960)
        ->setOffsetX(0)
        ->setOffsetY(0);
    $headerBg->getFill()->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('2E75B6'));
    
    // Page title (White on blue)
    $pageTitle = $slide->createRichTextShape()
        ->setHeight(40)
        ->setWidth(600)
        ->setOffsetX(50)
        ->setOffsetY(15);
    $pageTitle->createTextRun("Portfolio Review - Page {$i}");
    $pageTitle->getActiveParagraph()->getFont()
        ->setBold(true)
        ->setSize(24)
        ->setColor(new Color('FFFFFF'));
    
    // Slide number (White on blue)
    $slideNum = $slide->createRichTextShape()
        ->setHeight(40)
        ->setWidth(100)
        ->setOffsetX(850)
        ->setOffsetY(15);
    $slideNum->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $slideNum->createTextRun("Slide " . ($i + 2)); // +2 for title and agenda slides
    $slideNum->getActiveParagraph()->getFont()
        ->setSize(14)
        ->setColor(new Color('FFFFFF'));
    
    // Content
    $content = extractContentFromPage($i);
    
    $contentShape = $slide->createRichTextShape()
        ->setHeight(350)
        ->setWidth(880)
        ->setOffsetX(40)
        ->setOffsetY(80);
    
    // Add styled content
    $lines = explode('. ', $content);
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $textRun = $contentShape->createTextRun(trim($line) . '. ');
            
            // Style based on content
            if (stripos($line, 'important') !== false || 
                stripos($line, 'key') !== false ||
                stripos($line, 'note') !== false) {
                $textRun->getFont()->setBold(true)->setColor(new Color('C43E1C')); // Red for important
            } elseif (stripos($line, 'profit') !== false || 
                     stripos($line, 'gain') !== false ||
                     stripos($line, 'growth') !== false) {
                $textRun->getFont()->setColor(new Color('00B050')); // Green for positive
            } elseif (stripos($line, 'loss') !== false || 
                     stripos($line, 'risk') !== false ||
                     stripos($line, 'warning') !== false) {
                $textRun->getFont()->setColor(new Color('FF0000')); // Red for negative
            } else {
                $textRun->getFont()->setColor(new Color('333333')); // Dark gray for normal
            }
            
            $textRun->getFont()->setSize(14);
            $contentShape->createBreak();
        }
    }
    
    // Footer (Blue text)
    $footer = $slide->createRichTextShape()
        ->setHeight(30)
        ->setWidth(960)
        ->setOffsetX(0)
        ->setOffsetY(500);
    $footer->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $footer->createTextRun("Finance Doctor Wealth Management • Page {$i} of 23 • " . date('M d, Y'));
    $footer->getActiveParagraph()->getFont()->setSize(10)->setColor(new Color('2E75B6'));
}

// ========== THANK YOU SLIDE ==========
$finalSlide = $presentation->createSlide();
$finalSlide->setName('Thank You Slide');

// Blue gradient background
$finalBg = new BgColor();
$finalBg->setColor(new Color('1F4E79')); // Dark blue
$finalSlide->setBackground($finalBg);

// Thank you text
$thankYouTitle = $finalSlide->createRichTextShape()
    ->setHeight(100)
    ->setWidth(800)
    ->setOffsetX(80)
    ->setOffsetY(100);
$thankYouTitle->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$thankYouRun = $thankYouTitle->createTextRun('Thank You');
$thankYouRun->getFont()->setBold(true)->setSize(48)->setColor(new Color('FFFFFF'));

// Subtitle
$subtitleShape = $finalSlide->createRichTextShape()
    ->setHeight(50)
    ->setWidth(800)
    ->setOffsetX(80)
    ->setOffsetY(180);
$subtitleShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$subtitleShape->createTextRun('For Your Trust and Partnership')
    ->getFont()->setSize(24)->setColor(new Color('CCE0FF'));

// Contact info box
$contactBox = $finalSlide->createRichTextShape()
    ->setHeight(200)
    ->setWidth(600)
    ->setOffsetX(180)
    ->setOffsetY(250);
$contactBox->getFill()->setFillType(Fill::FILL_SOLID)
    ->setStartColor(new Color('FFFFFF')); // White box
$contactBox->getBorder()->setLineStyle(Border::LINE_SINGLE)
    ->setColor(new Color('2E75B6'))
    ->setDashStyle(Border::DASH_SOLID)
    ->setLineWidth(2);

$contactShape = $finalSlide->createRichTextShape()
    ->setHeight(180)
    ->setWidth(580)
    ->setOffsetX(190)
    ->setOffsetY(260);
$contactShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$contactShape->createTextRun("Finance Doctor Wealth Management\n\n")
    ->getFont()->setSize(20)->setBold(true)->setColor(new Color('2E75B6'));

$contactShape->createTextRun("Relationship Manager\n")
    ->getFont()->setSize(16)->setColor(new Color('666666'));
$contactShape->createTextRun("Sailesh Kumar Mulleti\n\n")
    ->getFont()->setSize(18)->setBold(true)->setColor(new Color('1F4E79'));

$contactShape->createTextRun("✉ sailesh.mulleti@financedoctor.in\n")
    ->getFont()->setSize(14)->setColor(new Color('2E75B6'));
$contactShape->createTextRun("📞 9949700435\n\n")
    ->getFont()->setSize(14)->setColor(new Color('2E75B6'));

$contactShape->createTextRun("Presentation Generated: " . date('F d, Y H:i'))
    ->getFont()->setSize(12)->setColor(new Color('666666'));

// Final footer
$finalFooter = $finalSlide->createRichTextShape()
    ->setHeight(30)
    ->setWidth(960)
    ->setOffsetX(0)
    ->setOffsetY(500);
$finalFooter->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$finalFooter->createTextRun('© ' . date('Y') . ' Finance Doctor Wealth Management. All Rights Reserved.')
    ->getFont()->setSize(10)->setColor(new Color('CCE0FF'));

// ========== SAVE PRESENTATION ==========
$filename = 'Portfolio_Review_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $client_name) . '_' . date('Y-m-d_H-i') . '.pptx';

// Set headers
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');
header('Content-Transfer-Encoding: binary');

// Save presentation
try {
    $writer = IOFactory::createWriter($presentation, 'PowerPoint2007');
    $writer->save('php://output');
} catch (Exception $e) {
    // Handle errors gracefully
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>PPT Generation Error</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; }
            .error-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 800px; margin: 50px auto; }
            h1 { color: #dc3545; }
            .btn { display: inline-block; padding: 10px 20px; background: #2E75B6; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>⚠️ PowerPoint Generation Error</h1>
            <p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            <p>Please ensure:</p>
            <ol>
                <li>All page files exist (page1.php to page23.php)</li>
                <li>PHPPresentation library is installed: <code>composer require phpoffice/phppresentation</code></li>
                <li>PHP has sufficient memory (128MB or more)</li>
                <li>Try the <a href="generate-ppt-simple.php">simple version</a> if this continues</li>
            </ol>
            <p><a href="index.php" class="btn">← Back to Report Generator</a></p>
        </div>
    </body>
    </html>';
}

exit;
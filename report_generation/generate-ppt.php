<?php
// generate-ppt.php - WORKING VERSION

// Fix vendor path - adjust based on your structure
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

// Get client data
$client_name = $_POST['client_name'] ?? 'Ms. Mukta Dutta Tomar';
$period = $_POST['period'] ?? 'January - March 2026';

// Create new presentation
$presentation = new PhpPresentation();

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
    
    // Limit length
    if (strlen($content) > 1500) {
        $content = substr($content, 0, 1500) . '...';
    }
    
    return "Page {$pageNumber}\n\n" . $content;
}

// ========== TITLE SLIDE ==========
$slide = $presentation->getActiveSlide();
$slide->setName('Title Slide');

// Background
$bgColor = new BgColor();
$bgColor->setColor(new Color('FFFFFF'));
$slide->setBackground($bgColor);

// Main Title
$titleShape = $slide->createRichTextShape()
    ->setHeight(100)
    ->setWidth(600)
    ->setOffsetX(100)
    ->setOffsetY(150);
$titleShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$titleRun = $titleShape->createTextRun('Quarterly Portfolio Review');
$titleRun->getFont()->setBold(true)->setSize(36)->setColor(new Color('2E75B6'));

// Period
$periodShape = $slide->createRichTextShape()
    ->setHeight(50)
    ->setWidth(600)
    ->setOffsetX(100)
    ->setOffsetY(250);
$periodShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$periodRun = $periodShape->createTextRun($period);
$periodRun->getFont()->setSize(24)->setColor(new Color('1F4E79'));

// Client Name
$clientShape = $slide->createRichTextShape()
    ->setHeight(50)
    ->setWidth(600)
    ->setOffsetX(100)
    ->setOffsetY(300);
$clientShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$clientRun = $clientShape->createTextRun('Prepared for: ' . $client_name);
$clientRun->getFont()->setSize(22)->setColor(new Color('000000'));

// Footer
$footerShape = $slide->createRichTextShape()
    ->setHeight(30)
    ->setWidth(600)
    ->setOffsetX(100)
    ->setOffsetY(450);
$footerShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$footerRun = $footerShape->createTextRun('Finance Doctor Wealth Management • ' . date('F d, Y'));
$footerRun->getFont()->setSize(12)->setColor(new Color('666666'));

// ========== CONTENT SLIDES ==========
for ($i = 1; $i <= 23; $i++) {
    $slide = $presentation->createSlide();
    $slide->setName("Page {$i}");
    
    // Background
    $bgColor = new BgColor();
    $bgColor->setColor(new Color('FFFFFF'));
    $slide->setBackground($bgColor);
    
    // Slide header
    $headerShape = $slide->createRichTextShape()
        ->setHeight(40)
        ->setWidth(650)
        ->setOffsetX(50)
        ->setOffsetY(50);
    $headerRun = $headerShape->createTextRun("Page {$i}");
    $headerRun->getFont()->setBold(true)->setSize(28)->setColor(new Color('2E75B6'));
    
    // Content
    $content = extractContentFromPage($i);
    
    $contentShape = $slide->createRichTextShape()
        ->setHeight(400)
        ->setWidth(650)
        ->setOffsetX(50)
        ->setOffsetY(100);
    
    $textRun = $contentShape->createTextRun($content);
    $textRun->getFont()->setSize(14);
    
    // Footer
    $footerShape = $slide->createRichTextShape()
        ->setHeight(20)
        ->setWidth(650)
        ->setOffsetX(50)
        ->setOffsetY(500);
    $footerShape->createTextRun("Finance Doctor • Page {$i} of 23 • " . date('F d, Y'));
    $footerShape->getActiveParagraph()->getFont()->setSize(10)->setColor(new Color('999999'));
}

// ========== THANK YOU SLIDE ==========
$finalSlide = $presentation->createSlide();
$finalSlide->setName('Thank You Slide');

// Background
$bgColor = new BgColor();
$bgColor->setColor(new Color('F0F8FF'));
$finalSlide->setBackground($bgColor);

// Thank you text
$thankYouTitle = $finalSlide->createRichTextShape()
    ->setHeight(100)
    ->setWidth(600)
    ->setOffsetX(100)
    ->setOffsetY(150);
$thankYouTitle->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$thankYouRun = $thankYouTitle->createTextRun('Thank You');
$thankYouRun->getFont()->setBold(true)->setSize(42)->setColor(new Color('2E75B6'));

// Contact info
$contactShape = $finalSlide->createRichTextShape()
    ->setHeight(150)
    ->setWidth(500)
    ->setOffsetX(150)
    ->setOffsetY(250);
$contactShape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$contactShape->createTextRun("Finance Doctor Wealth Management\n\n")
    ->getFont()->setSize(16)->setBold(true);
$contactShape->createTextRun("Relationship Manager: Sailesh Kumar Mulleti\n")
    ->getFont()->setSize(14);
$contactShape->createTextRun("Email: sailesh.mulleti@financedoctor.in\n")
    ->getFont()->setSize(14);
$contactShape->createTextRun("Phone: 9949700435\n\n")
    ->getFont()->setSize(14);
$contactShape->createTextRun("Generated: " . date('F d, Y H:i'))
    ->getFont()->setSize(12)->setColor(new Color('666666'));

// ========== SAVE ==========
$filename = 'Portfolio_Review_' . date('Y-m-d') . '.pptx';

// Set headers
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

// Save
$writer = IOFactory::createWriter($presentation, 'PowerPoint2007');
$writer->save('php://output');
exit;
?>
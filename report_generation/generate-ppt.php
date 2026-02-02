<?php
// generate_pdf.php - Creates PDF exactly matching slide designs
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

// Check for TCPDF
$tcpdf_path = __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    $tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
}

if (!file_exists($tcpdf_path)) {
    die('TCPDF not found. Please run: composer require tecnickcom/tcpdf');
}

require_once $tcpdf_path;

// Get client data
$client_id = $_GET['client_id'] ?? $_POST['client_id'] ?? 'CLIENT_1';
$clientInfo = getClientInfo($client_id);

// Extract client name
$client_name = 'Client';
$actual_client_id = 0;

if (strpos($client_id, 'CLIENT_') === 0) {
    $client_number = str_replace('CLIENT_', '', $client_id);
    if (is_numeric($client_number)) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT name, id FROM clients WHERE id = ?");
        $stmt->execute([$client_number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $client_name = $row['name'];
            $actual_client_id = (int)$row['id'];
        }
    }
}

// Quarter logic
$month = date('n');
$year = date('Y');
$quarterMap = [
    1 => 'Jan - Mar',
    2 => 'Apr - Jun', 
    3 => 'Jul - Sep',
    4 => 'Oct - Dec'
];
$quarter = $quarterMap[ceil($month / 3)] . ' ' . $year;

// Create custom PDF class for exact slide rendering
class SlidePDF extends TCPDF {
    
    // Colors from your slides
    private $blue = array(79, 125, 243);    // #4F7DF3
    private $teal = array(77, 182, 172);    // #4DB6AC
    private $gold = array(184, 164, 106);   // #B8A46A
    private $dark_blue = array(10, 61, 186); // #0A3DBA
    
    // Slide dimensions (16:9 aspect ratio like PowerPoint)
    private $slide_width = 297;  // A4 width in mm (landscape)
    private $slide_height = 167; // 16:9 ratio height
    
    public function __construct() {
        // Create custom page size for 16:9 slides
        parent::__construct('L', 'mm', array($this->slide_width, $this->slide_height), true, 'UTF-8', false);
        
        // Set document properties
        $this->SetCreator('Finance Doctor');
        $this->SetAuthor('Finance Doctor Wealth Management');
        
        // Disable auto page break
        $this->SetAutoPageBreak(false);
        
        // Set margins to zero for full-page slides
        $this->SetMargins(0, 0, 0);
        $this->SetHeaderMargin(0);
        $this->SetFooterMargin(0);
        
        // Remove header and footer
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
    }
    
    // Add a new slide (page)
    public function addSlide() {
        $this->AddPage();
        // Set white background for the entire slide
        $this->SetFillColor(255, 255, 255);
        $this->Rect(0, 0, $this->slide_width, $this->slide_height, 'F');
    }
    
    // Render Slide 1 exactly as in page1.php
    public function renderSlide1($client_name, $quarter) {
        $this->addSlide();
        
        // Top teal lines
        $this->SetLineWidth(1.2); // 6px equivalent
        $this->SetDrawColorArray($this->teal);
        $this->Line(25, 30, $this->slide_width - 25, 30);
        
        $this->SetLineWidth(0.2); // 1px equivalent
        $this->Line(25, 35, $this->slide_width - 25, 35);
        
        // Client Name
        $this->SetFont('helvetica', 'B', 36);
        $this->SetTextColorArray($this->blue);
        $this->SetXY(0, 70);
        $this->Cell(0, 0, $client_name, 0, 1, 'C');
        
        // Subtitle with gold lines
        $this->SetFont('helvetica', 'B', 24);
        $this->SetXY(0, 110);
        $this->Cell(0, 0, 'Quarterly Portfolio Review', 0, 1, 'C');
        
        // Gold lines
        $this->SetLineWidth(0.8); // 4px equivalent
        $this->SetDrawColorArray($this->gold);
        
        // Calculate positions for gold lines
        $center_x = $this->slide_width / 2;
        $line_length = 30;
        $text_width = $this->GetStringWidth('Quarterly Portfolio Review');
        
        // Left gold line
        $left_line_x = $center_x - ($text_width/2) - $line_length - 10;
        $this->Line($left_line_x, 120, $left_line_x + $line_length, 120);
        
        // Right gold line
        $right_line_x = $center_x + ($text_width/2) + 10;
        $this->Line($right_line_x, 120, $right_line_x + $line_length, 120);
        
        // Quarter
        $this->SetFont('helvetica', '', 16);
        $this->SetTextColorArray($this->blue);
        $this->SetXY(0, 130);
        $this->Cell(0, 0, $quarter, 0, 1, 'C');
        
        // Bottom teal lines
        $this->SetLineWidth(0.2); // 1px equivalent
        $this->SetDrawColorArray($this->teal);
        $this->Line(25, 145, $this->slide_width - 25, 145);
        
        $this->SetLineWidth(1.2); // 6px equivalent
        $this->Line(25, 148, $this->slide_width - 25, 148);
        
        // Logo
        $this->addLogo();
    }
    
    // Render generic slide with title and content
    public function renderGenericSlide($slideNumber, $title, $content) {
        $this->addSlide();
        
        // Add slide border for design
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(230, 230, 230);
        $this->Rect(15, 15, $this->slide_width - 30, $this->slide_height - 30);
        
        // Slide title
        $this->SetFont('helvetica', 'B', 28);
        $this->SetTextColorArray($this->blue);
        $this->SetXY(40, 40);
        $this->Cell(0, 0, $title, 0, 1, 'L');
        
        // Title underline
        $this->SetLineWidth(2);
        $this->SetDrawColorArray($this->gold);
        $this->Line(40, 55, min(40 + $this->GetStringWidth($title), $this->slide_width - 40), 55);
        
        // Content area with subtle background
        $this->SetFillColor(250, 250, 255);
        $this->Rect(40, 70, $this->slide_width - 80, $this->slide_height - 110, 'F');
        
        // Render content based on slide number
        $this->renderSlideContent($slideNumber, $content);
        
        // Add logo
        $this->addLogo();
        
        // Add slide number in bottom right
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(150, 150, 150);
        $this->SetXY($this->slide_width - 60, $this->slide_height - 25);
        $this->Cell(0, 0, 'Slide ' . $slideNumber, 0, 1, 'R');
    }
    
    // Render specific slide content based on slide number
    private function renderSlideContent($slideNumber, $content) {
        // Clean HTML content
        $content = $this->cleanHTML($content);
        
        // Set font for content
        $this->SetFont('helvetica', '', 12);
        $this->SetTextColorArray($this->dark_blue);
        
        // Different rendering based on slide number
        switch($slideNumber) {
            case 2: // Our Recommendations
            case 3: // Impact
            case 4: // Rationale
            case 5: // Portfolio at a Glance
                $this->SetXY(50, 75);
                $this->MultiCell($this->slide_width - 100, 8, $content, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T');
                break;
                
            case 7: // Asset Allocation - could add charts here
                $this->renderAssetAllocation($content);
                break;
                
            case 11: // Fund Performance
            case 14: // Tax-Smart Rebalancing
                $this->SetFont('helvetica', '', 11);
                $this->SetXY(50, 75);
                $this->writeHTMLCell($this->slide_width - 100, 0, 50, 75, $content, 0, 1, 0, true, 'L', true);
                break;
                
            case 16: // Support Team
                $this->renderSupportTeam($content);
                break;
                
            case 21: // Recommendations this quarter
            case 22: // Rationale
            case 23: // Strategic & Tax-Smart Rebalancing
                $this->SetFont('helvetica', '', 11);
                $this->SetXY(50, 75);
                // Preserve some HTML formatting
                $content = strip_tags($content, '<b><strong><i><em><u><br><p>');
                $this->writeHTMLCell($this->slide_width - 100, 0, 50, 75, $content, 0, 1, 0, true, 'L', true);
                break;
                
            default:
                // Default rendering for other slides
                $this->SetXY(50, 75);
                $this->MultiCell($this->slide_width - 100, 8, $content, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T');
        }
    }
    
    // Special rendering for Asset Allocation (Slide 7)
    private function renderAssetAllocation($content) {
        // Parse content to extract allocation data
        $lines = explode("\n", $content);
        
        $current_y = 75;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                // Check if line contains percentage
                if (preg_match('/(\d+)%/', $line, $matches)) {
                    $percentage = $matches[1];
                    
                    // Create progress bar
                    $bar_width = 200;
                    $filled_width = ($percentage / 100) * $bar_width;
                    
                    // Draw progress bar background
                    $this->SetFillColor(230, 230, 230);
                    $this->Rect(50, $current_y, $bar_width, 8, 'F');
                    
                    // Draw filled portion
                    $this->SetFillColorArray($this->teal);
                    $this->Rect(50, $current_y, $filled_width, 8, 'F');
                    
                    // Add percentage text
                    $this->SetFont('helvetica', 'B', 10);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetXY(50, $current_y - 2);
                    $this->Cell($bar_width, 8, $line, 0, 1, 'C');
                    
                    $current_y += 15;
                } else {
                    // Regular text
                    $this->SetFont('helvetica', '', 11);
                    $this->SetTextColorArray($this->dark_blue);
                    $this->SetXY(50, $current_y);
                    $this->Cell(0, 0, $line, 0, 1, 'L');
                    $current_y += 8;
                }
            }
        }
    }
    
    // Special rendering for Support Team (Slide 16)
    private function renderSupportTeam($content) {
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColorArray($this->blue);
        $this->SetXY(0, 75);
        $this->Cell(0, 0, 'Your Support Team', 0, 1, 'C');
        
        $this->SetLineWidth(1);
        $this->SetDrawColorArray($this->gold);
        $this->Line($this->slide_width/2 - 50, 85, $this->slide_width/2 + 50, 85);
        
        // Team members
        $team = [
            ['Sailesh Kumar Mulleti', 'Relationship Manager', 'sailesh.mulleti@financedoctor.in', '9949700435'],
            ['Ajit P Nair', 'Portfolio Manager', 'ajit@financedoctor.in', '---'],
            ['Support Team', 'Customer Service', 'support@financedoctor.in', '---']
        ];
        
        $start_y = 100;
        foreach ($team as $member) {
            $this->SetFont('helvetica', 'B', 14);
            $this->SetTextColorArray($this->blue);
            $this->SetXY(80, $start_y);
            $this->Cell(0, 0, $member[0], 0, 1, 'L');
            
            $this->SetFont('helvetica', '', 12);
            $this->SetTextColor(100, 100, 100);
            $this->SetXY(80, $start_y + 8);
            $this->Cell(0, 0, $member[1], 0, 1, 'L');
            
            $this->SetFont('helvetica', '', 11);
            $this->SetTextColorArray($this->teal);
            $this->SetXY(80, $start_y + 18);
            $this->Cell(0, 0, '✉ ' . $member[2], 0, 1, 'L');
            
            $this->SetXY(80, $start_y + 26);
            $this->Cell(0, 0, '📞 ' . $member[3], 0, 1, 'L');
            
            $start_y += 50;
        }
    }
    
    // Add Finance Doctor logo to slide
    private function addLogo() {
        $logo_path = __DIR__ . '/../image.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, $this->slide_width - 60, $this->slide_height - 35, 40, 0, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
        } else {
            // Fallback text logo
            $this->SetFont('helvetica', 'B', 12);
            $this->SetTextColorArray($this->blue);
            $this->SetXY($this->slide_width - 80, $this->slide_height - 30);
            $this->Cell(0, 0, 'Finance Doctor', 0, 1, 'R');
        }
    }
    
    // Clean HTML content
    private function cleanHTML($html) {
        // Remove scripts
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        // Remove styles
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        // Remove iframes
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
        // Remove JavaScript attributes
        $html = preg_replace('/\s(onclick|onload|onerror|onmouse|onkey)="[^"]*"/i', '', $html);
        // Convert HTML entities
        $html = html_entity_decode($html);
        // Remove multiple spaces
        $html = preg_replace('/\s+/', ' ', $html);
        // Strip tags but keep line breaks
        $html = strip_tags($html, '<br><p><b><strong><i><em><u>');
        // Convert <br> and <p> to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<p>/i', '', $html);
        
        return trim($html);
    }
    
    // Helper method to set color from array
    private function SetDrawColorArray($color) {
        $this->SetDrawColor($color[0], $color[1], $color[2]);
    }
    
    private function SetTextColorArray($color) {
        $this->SetTextColor($color[0], $color[1], $color[2]);
    }
    
    private function SetFillColorArray($color) {
        $this->SetFillColor($color[0], $color[1], $color[2]);
    }
}

// Create PDF document
$pdf = new SlidePDF();
$pdf->SetTitle('Portfolio Review - ' . $client_name);

// Slide titles from your registry
$slideTitles = [
    1 => 'Portfolio Review',
    2 => 'Our Recommendations',
    3 => 'Impact of our recommendations',
    4 => 'Rationale',
    5 => 'Portfolio at a Glance',
    6 => 'Slide 6',
    7 => 'Asset Allocation',
    8 => 'Slide 8',
    9 => 'Slide 9',
    10 => 'Slide 10',
    11 => 'Fund Performance & Risk Metrics',
    12 => 'Slide 12',
    13 => 'Slide 13',
    14 => 'Tax-Smart Rebalancing',
    15 => 'Slide 15',
    16 => 'Your Support Team',
    17 => 'Slide 17',
    18 => 'Slide 18',
    19 => 'Slide 19',
    20 => 'Slide 20',
    21 => 'Our Recommendations This Quarter',
    22 => 'Rationale',
    23 => 'Strategic & Tax-Smart Rebalancing'
];

// Function to get slide content
function getSlideContent($slideNumber, $client_id) {
    // First try to get from database
    $pages = getClientPages($client_id);
    
    if (isset($pages[$slideNumber]) && !empty($pages[$slideNumber]['content'])) {
        return $pages[$slideNumber]['content'];
    }
    
    // If not in database, try to load from template file
    $template_file = __DIR__ . '/slides/page' . $slideNumber . '.php';
    if (file_exists($template_file)) {
        ob_start();
        // Set client_id for the template
        $_GET['client_id'] = $client_id;
        include $template_file;
        $content = ob_get_clean();
        return $content;
    }
    
    // Default content for missing slides
    return '<div style="padding: 40px; text-align: center;">
                <h3>Slide ' . $slideNumber . '</h3>
                <p>Content will be added in the final report.</p>
            </div>';
}

// Generate all 23 slides
for ($i = 1; $i <= 23; $i++) {
    $title = isset($slideTitles[$i]) ? $slideTitles[$i] : 'Slide ' . $i;
    $content = getSlideContent($i, $client_id);
    
    if ($i == 1) {
        // Special rendering for Slide 1
        $pdf->renderSlide1($client_name, $quarter);
    } else {
        // Generic rendering for other slides
        $pdf->renderGenericSlide($i, $title, $content);
    }
}

// Output PDF filename
$filename = 'Portfolio_Review_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $client_name) . '_' . date('Y-m-d') . '.pdf';

// For direct download
$pdf->Output($filename, 'D');

// Alternative: For browser display
// header('Content-Type: application/pdf');
// header('Content-Disposition: inline; filename="' . $filename . '"');
// $pdf->Output($filename, 'I');

exit;
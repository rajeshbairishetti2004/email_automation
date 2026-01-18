<?php
// create_page.php
if (isset($_GET['page'])) {
    $page = intval($_GET['page']);
    if ($page >= 1 && $page <= 23) {
        $filename = "page{$page}.php";
        
        if (!file_exists($filename)) {
            $template = <<<EOT
<?php
// Page $page - Portfolio Review
?>
<div class="content-box">
    <h2>Page $page: Portfolio Review Content</h2>
    <p><strong>Client:</strong> Ms. Mukta Dutta Tomar</p>
    <p><strong>Period:</strong> January - March 2026</p>
    
    <div class="highlight">
        <h3>Key Content for Page $page</h3>
        <p>This is a template for page $page. Replace with actual content.</p>
        <ul>
            <li>Financial analysis data</li>
            <li>Investment performance metrics</li>
            <li>Recommendations and insights</li>
            <li>Charts and graphs</li>
        </ul>
    </div>
    
    <div style="margin-top: 20px; padding: 15px; background: #e9f7fe; border-radius: 5px;">
        <h4>Instructions:</h4>
        <p>Edit this file (<strong>page{$page}.php</strong>) to add actual content for page $page.</p>
        <p>Include tables, charts, analysis, and recommendations specific to this page.</p>
    </div>
</div>

<table style="width: 100%; margin-top: 20px;">
    <tr>
        <th>Metric</th>
        <th>Current</th>
        <th>Target</th>
        <th>Variance</th>
    </tr>
    <tr>
        <td>Portfolio Value</td>
        <td>---</td>
        <td>---</td>
        <td>---</td>
    </tr>
    <tr>
        <td>Returns</td>
        <td>---</td>
        <td>---</td>
        <td>---</td>
    </tr>
</table>
EOT;
            
            file_put_contents($filename, $template);
            echo "Created template for page $page";
        } else {
            echo "Page $page already exists";
        }
    } else {
        echo "Invalid page number";
    }
} else {
    echo "No page specified";
}
?>
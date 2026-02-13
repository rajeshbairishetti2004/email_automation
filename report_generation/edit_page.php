<?php
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageFile = "page{$page}.php";
$content = '';
$image = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = $_POST['content'] ?? '';
    // Handle image upload
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imgName = "page{$page}_" . time() . "." . $ext;
        $imgPath = "uploads/$imgName";
        move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imgName);
        $image = $imgPath;
    } else {
        $image = $_POST['existing_image'] ?? '';
    }
    // Save to page file
    file_put_contents($pageFile, "<div class=\"content-box\">\n" .
        ($image ? "<img src=\"$image\" style=\"max-width:300px;max-height:200px;float:right;margin:10px;\" />\n" : "") .
        "<div class=\"slide-content\">" . htmlspecialchars($content) . "</div>\n</div>");
    header("Location: index.php?page=$page");
    exit;
} else if (file_exists($pageFile)) {
    $pageHtml = file_get_contents($pageFile);
    if (preg_match('/<img src="([^"]+)"/', $pageHtml, $m)) {
        $image = $m[1];
    }
    if (preg_match('/<div class="slide-content">(.*?)<\/div>/s', $pageHtml, $m)) {
        $content = html_entity_decode($m[1]);
    } else {
        $content = strip_tags($pageHtml);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Slide <?php echo $page; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .editor-form { max-width: 700px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 12px #0001; }
        textarea { width: 100%; min-height: 180px; font-size: 16px; margin-bottom: 15px; }
        .img-preview { max-width: 300px; max-height: 200px; display: block; margin-bottom: 10px; }
        .form-actions { display: flex; gap: 10px; }
    </style>
</head>
<body>
    <div class="editor-form">
        <h2>Edit Slide <?php echo $page; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <label>Slide Content (HTML allowed):</label>
            <textarea name="content"><?php echo htmlspecialchars($content); ?></textarea>
            <label>Image (optional):</label>
            <?php if ($image): ?>
                <img src="<?php echo htmlspecialchars($image); ?>" class="img-preview" />
            <?php endif; ?>
            <input type="file" name="image" accept="image/*" />
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($image); ?>" />
            <div class="form-actions">
                <button type="submit" class="btn btn-ppt">Save</button>
                <a href="index.php?page=<?php echo $page; ?>" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>

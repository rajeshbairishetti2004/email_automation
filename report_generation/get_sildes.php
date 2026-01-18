<?php
require_once 'database.php';
header('Content-Type: application/json');

$sql = "SELECT * FROM pages ORDER BY page_number";
$result = $conn->query($sql);
$slides = [];

while ($row = $result->fetch_assoc()) {
    $slides[] = $row;
}

echo json_encode($slides);
$conn->close();
?>
<?php
require_once 'db_config.php';
require_once 'parsers.php'; // or your_pdf_parser.php

$pdo = getPdo();

$stmt = $pdo->query("SELECT id, name FROM clients");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($clients as $client) {
    $clientId = $client['id'];
    $attDir = __DIR__ . "/uploads/attachments/client_$clientId";
    if (!is_dir($attDir)) continue;
    $files = scandir($attDir);
    foreach ($files as $file) {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
            $pdfPath = $attDir . '/' . $file;
            // Parse PDF
            $pdfData = parseGoalStatusPdf($pdfPath);
            if (!empty($pdfData['goals'])) {
                if (!function_exists('syncPdfGoalsToDb')) {
                    function syncPdfGoalsToDb(PDO $pdo, int $clientId, array $pdfGoals): void
                    {
                        if (empty($pdfGoals)) return;
                        $pdo->prepare("DELETE FROM client_goals WHERE client_id = ?")->execute([$clientId]);
                        $stmt = $pdo->prepare("
                            INSERT INTO client_goals
                            (client_id, goal, goal_date, current_amount, sip_swp, target_amount, projected, shortfall, completion, status)
                            VALUES
                            (:client_id, :goal, :goal_date, :current_amount, :sip_swp, :target_amount, :projected, :shortfall, :completion, :status)
                        ");
                        foreach ($pdfGoals as $g) {
                            $stmt->execute([
                                ':client_id'      => $clientId,
                                ':goal'           => $g['goal'],
                                ':goal_date'      => !empty($g['goal_date']) ? date('Y-m-d', strtotime($g['goal_date'])) : null,
                                ':current_amount' => $g['current_amount'] ?? 0,
                                ':sip_swp'        => $g['sip_swp'] ?? 0,
                                ':target_amount'  => $g['target_amount'] ?? 0,
                                ':projected'      => $g['projected'] ?? 0,
                                ':shortfall'      => $g['shortfall'] ?? 0,
                                ':completion'     => $g['completion'] ?? 0,
                                ':status'         => $g['status'] ?? 'On Track',
                            ]);
                        }
                    }
                }
                syncPdfGoalsToDb($pdo, $clientId, $pdfData['goals']);
                echo "Processed client {$client['name']} (ID: $clientId): " . count($pdfData['goals']) . " goals<br>";
                break;
            }
        }
    }
}
echo "<br>✅ All clients processed!";

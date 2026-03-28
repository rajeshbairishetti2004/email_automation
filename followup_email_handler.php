<?php

function sendFollowupEmail($client, $toEmail, $ccList, $fromEmail, $fromName, $pdo, $message)
{
    $brevoApiKey = $_ENV['BREVO_API_KEY'] ?? '';

    $subject = $client['name'] . " - Quarterly Review - scheduling a zoom meeting";

    $body = nl2br(htmlspecialchars($message));

    // Build recipients
    $toRecipients = [['email' => $toEmail, 'name' => $client['name']]];
    $ccRecipients = array_map(fn($e) => ['email' => $e], $ccList);

    // Build payload
    $payload = [
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => $toRecipients,
        'subject'     => $subject,
        'htmlContent' => $body,
    ];

    if (!empty($ccRecipients)) {
        $payload['cc'] = $ccRecipients;
    }

    // Send via Brevo API
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-key: ' . $brevoApiKey
    ]);

    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        $errorDetail = json_decode($response, true)['message'] ?? 'Unknown error';
        error_log("Brevo followup email error for client ID {$client['id']}: HTTP $httpCode - $errorDetail");
        return false;
    }

    // LOG FOLLOW-UP
    $stmt = $pdo->prepare("
        INSERT INTO email_logs 
        (client_id, from_email, from_name, sent_to_email, sent_to_name, cc_emails, email_body, email_type, followup_sent)
        VALUES 
        (:client_id, :from_email, :from_name, :to_email, :to_name, :cc_emails, :email_body, 'followup', 1)
    ");

    $stmt->execute([
        ':client_id'  => $client['id'],
        ':from_email' => $fromEmail,
        ':from_name'  => $fromName,
        ':to_email'   => $toEmail,
        ':to_name'    => $client['name'],
        ':cc_emails'  => implode(', ', $ccList),
        ':email_body' => $body
    ]);

    return true;
}
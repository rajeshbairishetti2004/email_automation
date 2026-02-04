<?php
use PHPMailer\PHPMailer\PHPMailer;

function sendFollowupEmail($client, $toEmail, $ccList, $fromEmail, $fromName, $pdo, $message)
{
    $mail = new PHPMailer(true);

    $subject = $client['name'] . " - Follow-up on Quarterly Review";

    // ✅ USE TEXTAREA CONTENT
    $body = nl2br(htmlspecialchars($message));

    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USERNAME'];
    $mail->Password = $_ENV['SMTP_PASSWORD'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $_ENV['SMTP_PORT'];

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail);

    foreach ($ccList as $cc) {
        $mail->addCC($cc);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $body;

    $mail->send();

    // LOG FOLLOW-UP
    $stmt = $pdo->prepare("
        INSERT INTO email_logs 
        (client_id, from_email, from_name, sent_to_email, sent_to_name, cc_emails, email_body, email_type, followup_sent)
        VALUES 
        (:client_id, :from_email, :from_name, :to_email, :to_name, :cc_emails, :email_body, 'followup', 1)
    ");

    $stmt->execute([
        ':client_id' => $client['id'],
        ':from_email' => $fromEmail,
        ':from_name' => $fromName,
        ':to_email' => $toEmail,
        ':to_name' => $client['name'],
        ':cc_emails' => implode(', ', $ccList),
        ':email_body' => $body
    ]);
}

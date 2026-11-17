<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendSystemEmail($recipientEmail, $subject, $message)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        // Gmail account ng system
        $mail->Username = 'markparedes54321@gmail.com';

        // Gmail App Password
        $mail->Password = 'jrbd wkeo obhq jqgh';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            'markparedes54321@gmail.com',
            'NFC Security System'
        );

        $mail->addAddress($recipientEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log('EMAIL ERROR: ' . $mail->ErrorInfo);

        return false;
    }
}
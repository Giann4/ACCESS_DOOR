
<?php
require_once __DIR__ . '/config/mail.php';

$recipient = 'markparedes54321@gmail.com';

$subject = 'NFC Security System - Email Test';

$message = '
<h2>NFC Security System</h2>
<p>Congratulations!</p>
<p>Your email notification system is working successfully.</p>
<p>This is a test email from the NFC Security System.</p>
';

if (sendSystemEmail($recipient, $subject, $message)) {
    echo "SUCCESS! Email sent successfully.";
} else {
    echo "FAILED! Email could not be sent. Check your mail configuration.";
}
?>

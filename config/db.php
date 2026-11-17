
<?php

date_default_timezone_set('Asia/Manila');

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'nfc_security_db';

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    exit('Unable to connect to the database. Please check the configuration.');
}

$conn->set_charset('utf8mb4');

?>

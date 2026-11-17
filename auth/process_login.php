
<?php
session_start();

require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    exit('Please enter your email and password.');
}

$stmt = $conn->prepare(
    "SELECT id, full_name, email, password, role, status
     FROM users
     WHERE email = ?
     LIMIT 1"
);

$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (
    !$user ||
    !password_verify($password, $user['password']) ||
    $user['status'] !== 'active'
) {
    exit('Invalid email or password, or your account is inactive.');
}

session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];

if ($user['role'] === 'admin') {
    header('Location: ../admin/dashboard.php');
    exit;
}

if ($user['role'] === 'teacher') {
    header('Location: ../teacher/dashboard.php');
    exit;
}

session_unset();
session_destroy();

header('Location: ../index.php');
exit;

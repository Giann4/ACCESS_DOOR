
<?php
require_once 'config/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($full_name === '' || $email === '' || $password === '') {
        $message = 'Please complete all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = 'An account with this email already exists.';
        } else {
            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );
            $role = 'admin';

            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password, role)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssss",
                $full_name,
                $email,
                $hashed_password,
                $role
            );

            if ($stmt->execute()) {
                $message = 'Admin account created successfully!';
            } else {
                $message = 'Unable to create the account.';
            }

            $stmt->close();
        }

        $check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup | NFC Security System</title>
</head>
<body>
    <h2>Create Initial Admin Account</h2>

    <?php if ($message !== ''): ?>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>Full Name</label><br>
        <input type="text" name="full_name" required><br><br>

        <label>Email Address</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password (at least 8 characters)</label><br>
        <input type="password" name="password" minlength="8" required><br><br>

        <button type="submit">Create Admin Account</button>
    </form>
</body>
</html>

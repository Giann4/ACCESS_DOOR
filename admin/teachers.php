<?php
session_start();
require_once __DIR__ . '/../config/db.php';
// Admin-only access
if (!isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$message = '';
$messageType = 'success';
$editTeacher = null;
// Flash message
if (isset($_SESSION['teacher_flash'])) {
    $message = $_SESSION['teacher_flash']['message'];
    $messageType = $_SESSION['teacher_flash']['type'];
    unset($_SESSION['teacher_flash']);
}
function teacherRedirect($message, $type = 'success') {
    $_SESSION['teacher_flash'] = [
        'message' => $message,
        'type' => $type
    ];
    header('Location: teachers.php');
    exit;
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        teacherRedirect('Invalid request token. Please try again.', 'error');
    }
    $action = $_POST['action'] ?? '';
    // Add teacher
    if ($action === 'add') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($fullName === '' || $email === '' || $password === '') {
            teacherRedirect('Please complete all required fields.', 'error');
        }
        if (strlen($fullName) > 100) {
            teacherRedirect('Full name must not exceed 100 characters.', 'error');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            teacherRedirect('Please enter a valid email address.', 'error');
        }
        if (strlen($password) < 8) {
            teacherRedirect('Password must contain at least 8 characters.', 'error');
        }
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );
        $check->bind_param('s', $email);
        $check->execute();
        $existing = $check->get_result()->num_rows > 0;
        $check->close();
        if ($existing) {
            teacherRedirect('This email address is already registered.', 'error');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'teacher';
        $status = 'active';
        $stmt = $conn->prepare(
            "INSERT INTO users (full_name, email, password, role, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssss',
            $fullName,
            $email,
            $passwordHash,
            $role,
            $status
        );
        if ($stmt->execute()) {
            $stmt->close();
            teacherRedirect('Teacher account created successfully.');
        }
        $stmt->close();
        if ($conn->errno === 1062) {
            teacherRedirect('This email address is already registered.', 'error');
        }
        teacherRedirect('Unable to create teacher account. Please check the database.', 'error');
    }
    // Edit teacher
    if ($action === 'edit') {
        $id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $newPassword = $_POST['new_password'] ?? '';
        if (!$id || $fullName === '' || $email === '') {
            teacherRedirect('Please complete the required fields.', 'error');
        }
        if (strlen($fullName) > 100 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 100) {
            teacherRedirect('Please check the name and email format.', 'error');
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            teacherRedirect('New password must contain at least 8 characters.', 'error');
        }
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1"
        );
        $check->bind_param('si', $email, $id);
        $check->execute();
        $duplicate = $check->get_result()->num_rows > 0;
        $check->close();
        if ($duplicate) {
            teacherRedirect('Another account already uses this email.', 'error');
        }
        // Confirm the selected account is a teacher
        $check = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        $check->bind_param('i', $id);
        $check->execute();
        $isTeacher = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$isTeacher) {
            teacherRedirect('Teacher account not found.', 'error');
        }
        if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE users
                 SET full_name = ?, email = ?, password = ?
                 WHERE id = ? AND role = 'teacher'"
            );
            $stmt->bind_param('sssi', $fullName, $email, $passwordHash, $id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET full_name = ?, email = ?
                 WHERE id = ? AND role = 'teacher'"
            );
            $stmt->bind_param('ssi', $fullName, $email, $id);
        }
        if ($stmt->execute()) {
            $stmt->close();
            teacherRedirect('Teacher information updated successfully.');
        }
        $stmt->close();
        teacherRedirect('Unable to update teacher information.', 'error');
    }
    // Activate or deactivate teacher
    if ($action === 'status') {
        $id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        $newStatus = $_POST['status'] ?? '';
        if (!$id || !in_array($newStatus, ['active', 'inactive'], true)) {
            teacherRedirect('Invalid teacher status request.', 'error');
        }
        $stmt = $conn->prepare(
            "UPDATE users SET status = ? WHERE id = ? AND role = 'teacher'"
        );
        $stmt->bind_param('si', $newStatus, $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        // A zero affected-row count can mean the status was already the same.
        $check = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        $check->bind_param('i', $id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if ($exists) {
            teacherRedirect(
                $newStatus === 'active'
                    ? 'Teacher account is now active.'
                    : 'Teacher account is now inactive.'
            );
        }
        teacherRedirect('Teacher account not found.', 'error');
    }
    // Permanently delete a teacher and all records owned by that teacher.
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        if (!$id) {
            teacherRedirect('Invalid teacher account selected.', 'error');
        }

        $check = $conn->prepare(
            "SELECT id, full_name FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        $check->bind_param('i', $id);
        $check->execute();
        $teacherToDelete = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$teacherToDelete) {
            teacherRedirect('Teacher account not found.', 'error');
        }

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "DELETE en
                 FROM email_notifications en
                 INNER JOIN attendance a ON a.id = en.attendance_id
                 INNER JOIN class_schedules cs ON cs.id = a.schedule_id
                 INNER JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE sub.teacher_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE a
                 FROM attendance a
                 INNER JOIN class_schedules cs ON cs.id = a.schedule_id
                 INNER JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE sub.teacher_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE ss
                 FROM schedule_students ss
                 INNER JOIN class_schedules cs ON cs.id = ss.schedule_id
                 INNER JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE sub.teacher_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE cs
                 FROM class_schedules cs
                 INNER JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE sub.teacher_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE FROM subjects WHERE teacher_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE FROM users WHERE id = ? AND role = 'teacher'"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            if ($deleted !== 1) {
                throw new RuntimeException('Teacher account could not be deleted.');
            }

            $conn->commit();
            teacherRedirect(
                'Teacher "' . $teacherToDelete['full_name'] .
                '" and all connected subjects, schedules, enrollments, attendance, and email notification records were permanently deleted.'
            );
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Teacher deletion failed: ' . $e->getMessage());
            teacherRedirect(
                'Unable to delete teacher. No database changes were saved. Check the server error log.',
                'error'
            );
        }
    }
    teacherRedirect('Unknown action.', 'error');
}
// Load teacher for editing
if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, status
             FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editTeacher = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$editTeacher) {
            $message = 'Teacher account not found.';
            $messageType = 'error';
        }
    }
}
// Search teachers
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, status, created_at
         FROM users
         WHERE role = 'teacher'
           AND (full_name LIKE ? OR email LIKE ?)
         ORDER BY id DESC"
    );
    $stmt->bind_param('ss', $like, $like);
} else {
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, status, created_at
         FROM users
         WHERE role = 'teacher'
         ORDER BY id DESC"
    );
}
$stmt->execute();
$teachers = $stmt->get_result();
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'active'), 0) AS active_count,
        COALESCE(SUM(status = 'inactive'), 0) AS inactive_count
     FROM users
     WHERE role = 'teacher'"
);
$counts = $countResult->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Management | NFC Security System</title>
    <style>
        :root {
            --green: #28d889;
            --green-dark: #087443;
            --green-deep: #064e32;
            --background: #f4f7f5;
            --white: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --red: #b91c1c;
            --shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background);
            color: var(--text);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        .main-content{margin-left:250px;min-height:100vh;padding:30px 35px;transition:margin-left .25s ease}
        .page-container {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
        }
        .page-banner{height:168px;display:flex;align-items:center;gap:18px;margin-bottom:20px;padding:30px;border-radius:14px;background:#111827;box-shadow:0 8px 20px rgba(15,23,42,.10)}
        .page-banner h2{margin:0 0 9px;color:#fff;font-size:23px;font-weight:750;letter-spacing:-.4px}
        .page-banner h2 span {
            color: var(--green);
        }
        .page-banner p{margin:0;color:#cbd5e1;font-size:13px;line-height:1.6}
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 39px;
            padding: 10px 15px;
            border: 1px solid transparent;
            border-radius: 9px;
            background: var(--green-dark);
            color: #fff;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .button:hover {
            background: var(--green-deep);
        }
        .button-secondary {
            border-color: #d4e9dc;
            background: #f0f8f3;
            color: var(--green-deep);
        }
        .button-secondary:hover {
            background: #e2f2e8;
        }
        .button-danger {
            border-color: #fecdd3;
            background: #fff1f2;
            color: var(--red);
        }
        .button-danger:hover {
            background: #ffe4e6;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .summary-card {
            position: relative;
            overflow: hidden;
            padding: 22px 23px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .summary-card::before {
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--green-dark);
            content: "";
        }
        .summary-card span {
            display: block;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }
        .summary-card strong {
            color: #172b22;
            font-size: 30px;
            line-height: 1;
            font-weight: 750;
        }
        .panel {
            margin-bottom: 24px;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .panel-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 18px;
        }
        .panel-heading h3 {
            margin: 0 0 6px;
            color: #172b22;
            font-size: 17px;
            font-weight: 750;
        }
        .panel-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .table-toolbar {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .search-form {
            display: flex;
            width: 100%;
            gap: 8px;
            flex-wrap: wrap;
        }
        .search-form input {
            flex: 1 1 280px;
            min-width: 190px;
            min-height: 40px;
            padding: 10px 12px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
        }
        .search-form input:focus,
        .field input:focus {
            border-color: var(--green-dark);
            box-shadow: 0 0 0 3px rgba(40, 216, 137, 0.13);
        }
        .alert {
            margin-bottom: 20px;
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
        }
        .alert-success {
            border: 1px solid #bbebcc;
            background: #eaf7ef;
            color: var(--green-deep);
        }
        .alert-error {
            border: 1px solid #fecdd3;
            background: #fff1f2;
            color: var(--red);
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 11px;
        }
        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 13px;
            border-bottom: 1px solid #edf1ee;
            text-align: left;
            vertical-align: middle;
            font-size: 12px;
        }
        th {
            background: #f5faf6;
            color: var(--green-deep);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.35px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        td {
            color: #334155;
            line-height: 1.5;
        }
        tbody tr:last-child td {
            border-bottom: 0;
        }
        tbody tr:hover {
            background: #fbfefc;
        }
        .muted {
            color: var(--muted);
            font-size: 12px;
        }
        .status {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }
        .status-active {
            background: #e4f8ec;
            color: #087443;
        }
        .status-inactive {
            background: #f1f5f9;
            color: #475569;
        }
        .actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }
        .actions .button {
            min-height: 31px;
            padding: 7px 10px;
            font-size: 11px;
        }
        .status-form {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .status-form select {
            min-height: 31px;
            padding: 6px 8px;
            border: 1px solid #d5dfd9;
            border-radius: 7px;
            background: #fff;
            font-family: inherit;
            font-size: 11px;
        }
        .empty {
            padding: 38px 15px;
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
        }
        .table-footer {
            margin-top: 13px;
            color: var(--muted);
            font-size: 11px;
        }
        .field {
            margin-bottom: 16px;
        }
        .field label {
            display: block;
            margin-bottom: 8px;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }
        .field input {
            width: 100%;
            min-height: 43px;
            padding: 11px 13px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
        }
        .form-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .hint {
            margin-top: 16px;
            padding: 12px 14px;
            border-left: 3px solid var(--green-dark);
            border-radius: 7px;
            background: #f0fdf4;
            color: var(--green-deep);
            font-size: 12px;
            line-height: 1.6;
        }
        @media (max-width: 1000px) {
            .main-content {
                padding: 25px 20px;
            }
        }
        @media (max-width: 700px) {
            .main-content {
                margin-left: 68px;
                padding: 22px 15px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .panel {
                padding: 18px;
            }
            .table-toolbar {
                align-items: stretch;
            }
            .search-form {
                flex-direction: column;
            }
            .search-form input,
            .search-form .button {
                width: 100%;
            }
            .status-form {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <div class="page-container">
        <section class="page-banner">
            <div>
                <h2>Teacher <span>Management</span></h2>
                <p>Register teacher accounts and manage their access to the NFC Security System.</p>
            </div>
        </section>
        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
        <div class="table-toolbar">
            <form class="search-form" method="GET" action="teachers.php">
                <input
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search teacher name or email..."
                    aria-label="Search teachers"
                >
                <button class="button" type="submit">Search</button>
                <a class="button button-secondary" href="teachers.php">Reset</a>
                <a class="button" href="#teacher-form">+ Add Teacher</a>
            </form>
        </div>
        <section class="summary-grid">
            <article class="summary-card">
                <span>Total Teachers</span>
                <strong><?= (int)$counts['total'] ?></strong>
            </article>
            <article class="summary-card">
                <span>Active Teachers</span>
                <strong><?= (int)$counts['active_count'] ?></strong>
            </article>
            <article class="summary-card">
                <span>Inactive Teachers</span>
                <strong><?= (int)$counts['inactive_count'] ?></strong>
            </article>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h3>Registered Teachers</h3>
                    <p>Teacher accounts and their current access status.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Teacher Name</th>
                            <th>Email Address</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($teachers->num_rows > 0): ?>
                        <?php $rowNumber = 1; ?>
                        <?php while ($teacher = $teachers->fetch_assoc()): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <strong><?= e($teacher['full_name']) ?></strong>
                                </td>
                                <td><?= e($teacher['email']) ?></td>
                                <td>
                                    <span class="status status-<?= e($teacher['status']) ?>">
                                        <?= e($teacher['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($teacher['created_at'])
                                    )) ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a
                                            class="button button-secondary"
                                            href="teachers.php?edit=<?= (int)$teacher['id'] ?>#teacher-form"
                                        >
                                            Edit
                                        </a>
                                        <form class="status-form" method="POST" action="teachers.php">
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >
                                            <input type="hidden" name="action" value="status">
                                            <input
                                                type="hidden"
                                                name="teacher_id"
                                                value="<?= (int)$teacher['id'] ?>"
                                            >
                                            <select name="status" aria-label="Teacher status">
                                                <option value="active" <?= $teacher['status'] === 'active' ? 'selected' : '' ?>>
                                                    Active
                                                </option>
                                                <option value="inactive" <?= $teacher['status'] === 'inactive' ? 'selected' : '' ?>>
                                                    Inactive
                                                </option>
                                            </select>
                                            <button class="button" type="submit">Save</button>
                                        </form>
                                        <form method="POST" action="teachers.php"
                                              onsubmit="return confirm('PERMANENT DELETE\\n\\nDelete this teacher and ALL connected subjects, class schedules, student enrollments, attendance records, and email notifications? This cannot be undone. Student NFC access logs will be retained.');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="teacher_id"
                                                   value="<?= (int)$teacher['id'] ?>">
                                            <button class="button button-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty">
                                No teachers found. Use the Add Teacher button to create an account.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                Showing <?= $teachers->num_rows ?> teacher record(s).
            </div>
        </section>
        <section class="panel" id="teacher-form">
            <div class="panel-heading">
                <div>
                    <h3><?= $editTeacher ? 'Edit Teacher Information' : 'Add New Teacher' ?></h3>
                    <p>
                        <?= $editTeacher
                            ? 'Update the selected teacher account details.'
                            : 'Create a teacher account for the system.' ?>
                    </p>
                </div>
            </div>
            <?php if ($editTeacher): ?>
                <form method="POST" action="teachers.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >
                    <input type="hidden" name="action" value="edit">
                    <input
                        type="hidden"
                        name="teacher_id"
                        value="<?= (int)$editTeacher['id'] ?>"
                    >
                    <div class="field">
                        <label for="full_name">Full Name *</label>
                        <input
                            id="full_name"
                            name="full_name"
                            maxlength="100"
                            required
                            value="<?= e($editTeacher['full_name']) ?>"
                        >
                    </div>
                    <div class="field">
                        <label for="email">Email Address *</label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            maxlength="100"
                            required
                            value="<?= e($editTeacher['email']) ?>"
                        >
                    </div>
                    <div class="field">
                        <label for="new_password">New Password (optional)</label>
                        <input
                            id="new_password"
                            type="password"
                            name="new_password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Leave blank to keep current password"
                        >
                    </div>
                    <div class="form-actions">
                        <button class="button" type="submit">Save Changes</button>
                        <a class="button button-secondary" href="teachers.php">Cancel Edit</a>
                    </div>
                </form>
            <?php else: ?>
                <form method="POST" action="teachers.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >
                    <input type="hidden" name="action" value="add">
                    <div class="field">
                        <label for="full_name">Full Name *</label>
                        <input
                            id="full_name"
                            name="full_name"
                            maxlength="100"
                            required
                            placeholder="Enter teacher's full name"
                        >
                    </div>
                    <div class="field">
                        <label for="email">Email Address *</label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            maxlength="100"
                            required
                            placeholder="teacher@example.com"
                        >
                    </div>
                    <div class="field">
                        <label for="password">Temporary Password *</label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            minlength="8"
                            required
                            autocomplete="new-password"
                            placeholder="At least 8 characters"
                        >
                    </div>
                    <div class="form-actions">
                        <button class="button" type="submit">
                            Create Teacher Account
                        </button>
                    </div>
                </form>
                <div class="hint">
                    Teacher accounts are created with the teacher role and active status.
                    Passwords are stored as secure hashes, not plain text.
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>

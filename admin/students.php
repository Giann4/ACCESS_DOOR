<?php
session_start();
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}
require_once '../config/db.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function redirectWithMessage(string $message, string $type = 'success'): void
{
    header(
        'Location: students.php?message=' . urlencode($message) .
        '&type=' . urlencode($type)
    );
    exit;
}
// ADD, EDIT, OR CHANGE STUDENT STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        redirectWithMessage('Invalid request. Please try again.', 'error');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'save_student') {
        $studentId = filter_var(
            $_POST['student_id'] ?? '',
            FILTER_VALIDATE_INT
        );
        $studentNumber = trim($_POST['student_number'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $gradeLevel = trim($_POST['grade_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $parentName = trim($_POST['parent_name'] ?? '');
        $parentEmail = trim($_POST['parent_email'] ?? '');
        if (
            $studentNumber === '' ||
            $firstName === '' ||
            $lastName === '' ||
            $gradeLevel === '' ||
            $section === ''
        ) {
            redirectWithMessage(
                'Please complete all required student fields.',
                'error'
            );
        }
        if (
            mb_strlen($studentNumber) > 50 ||
            mb_strlen($firstName) > 100 ||
            mb_strlen($middleName) > 100 ||
            mb_strlen($lastName) > 100 ||
            mb_strlen($gradeLevel) > 20 ||
            mb_strlen($section) > 50 ||
            mb_strlen($parentName) > 150 ||
            mb_strlen($parentEmail) > 150
        ) {
            redirectWithMessage(
                'One or more fields exceed the allowed length.',
                'error'
            );
        }
        if ($parentEmail !== '' &&
            !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage(
                'Please enter a valid parent or guardian email.',
                'error'
            );
        }
        if ($studentId) {
            // UPDATE EXISTING STUDENT
            $stmt = $conn->prepare(
                "UPDATE students
                 SET student_number = ?, first_name = ?, middle_name = ?,
                     last_name = ?, grade_level = ?, section = ?,
                     parent_name = ?, parent_email = ?
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'ssssssssi',
                $studentNumber,
                $firstName,
                $middleName,
                $lastName,
                $gradeLevel,
                $section,
                $parentName,
                $parentEmail,
                $studentId
            );
            if ($stmt->execute()) {
                $stmt->close();
                redirectWithMessage('Student record updated successfully.');
            }
            $errorCode = $stmt->errno;
            $stmt->close();
            redirectWithMessage(
                $errorCode === 1062
                    ? 'That student number is already registered.'
                    : 'Unable to update student. Please check the information.',
                'error'
            );
        } else {
            // ADD NEW STUDENT
            $stmt = $conn->prepare(
                "INSERT INTO students
                    (student_number, first_name, middle_name, last_name,
                     grade_level, section, parent_name, parent_email)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssssssss',
                $studentNumber,
                $firstName,
                $middleName,
                $lastName,
                $gradeLevel,
                $section,
                $parentName,
                $parentEmail
            );
            if ($stmt->execute()) {
                $stmt->close();
                redirectWithMessage('Student registered successfully.');
            }
            $errorCode = $stmt->errno;
            $stmt->close();
            redirectWithMessage(
                $errorCode === 1062
                    ? 'That student number is already registered.'
                    : 'Unable to register student. Please check the information.',
                'error'
            );
        }
    }
    if ($action === 'delete_student') {
        $studentId = filter_var(
            $_POST['student_id'] ?? '',
            FILTER_VALIDATE_INT
        );
        if (!$studentId || $studentId <= 0) {
            redirectWithMessage('Invalid student record.', 'error');
        }
        try {
            $conn->begin_transaction();
            // Confirm the student exists before deleting related records.
            $checkStudent = $conn->prepare(
                "SELECT id FROM students WHERE id = ? FOR UPDATE"
            );
            $checkStudent->bind_param('i', $studentId);
            $checkStudent->execute();
            $studentExists = $checkStudent->get_result()->num_rows === 1;
            $checkStudent->close();
            if (!$studentExists) {
                throw new RuntimeException('Student record was not found.');
            }
            // Preserve access history. The access_logs foreign key sets
            // student_id to NULL automatically when the student is deleted.
            // Remove dependent notification and attendance data first.
            $deleteNotifications = $conn->prepare(
                "DELETE en FROM email_notifications en
                 INNER JOIN attendance a ON a.id = en.attendance_id
                 WHERE a.student_id = ?"
            );
            $deleteNotifications->bind_param('i', $studentId);
            $deleteNotifications->execute();
            $deleteNotifications->close();
            $deleteAttendance = $conn->prepare(
                "DELETE FROM attendance WHERE student_id = ?"
            );
            $deleteAttendance->bind_param('i', $studentId);
            $deleteAttendance->execute();
            $deleteAttendance->close();
            $deleteAssignments = $conn->prepare(
                "DELETE FROM schedule_students WHERE student_id = ?"
            );
            $deleteAssignments->bind_param('i', $studentId);
            $deleteAssignments->execute();
            $deleteAssignments->close();
            $deleteCards = $conn->prepare(
                "DELETE FROM nfc_cards WHERE student_id = ?"
            );
            $deleteCards->bind_param('i', $studentId);
            $deleteCards->execute();
            $deleteCards->close();
            $deleteStudent = $conn->prepare(
                "DELETE FROM students WHERE id = ?"
            );
            $deleteStudent->bind_param('i', $studentId);
            $deleteStudent->execute();
            $deleted = $deleteStudent->affected_rows;
            $deleteStudent->close();
            if ($deleted !== 1) {
                throw new RuntimeException('Unable to delete the student record.');
            }
            $conn->commit();
            redirectWithMessage(
                'Student and connected records deleted successfully. Historical NFC access logs were retained.'
            );
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Student deletion failed: ' . $exception->getMessage());
            redirectWithMessage(
                'Unable to delete student. No changes were saved. Check related database records.',
                'error'
            );
        }
    }
    if ($action === 'toggle_status') {
        $studentId = filter_var(
            $_POST['student_id'] ?? '',
            FILTER_VALIDATE_INT
        );
        if (!$studentId) {
            redirectWithMessage('Invalid student record.', 'error');
        }
        $stmt = $conn->prepare(
            "UPDATE students
             SET status = CASE
                 WHEN status = 'active' THEN 'inactive'
                 ELSE 'active'
             END
             WHERE id = ?"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        redirectWithMessage(
            $changed >= 0
                ? 'Student status updated.'
                : 'Unable to update student status.',
            $changed >= 0 ? 'success' : 'error'
        );
    }
    redirectWithMessage('Unknown action.', 'error');
}
// EDIT MODE
$editStudent = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($editId) {
    $stmt = $conn->prepare(
        "SELECT id, student_number, first_name, middle_name, last_name,
                grade_level, section, parent_name, parent_email
         FROM students
         WHERE id = ?"
    );
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editStudent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
// SEARCH AND FILTER
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT id, student_number, first_name, middle_name, last_name,
               grade_level, section, parent_name, parent_email,
               status, created_at
        FROM students
        WHERE 1 = 1";
$params = [];
$types = '';
if ($search !== '') {
    $sql .= " AND (
        student_number LIKE ? OR first_name LIKE ? OR
        middle_name LIKE ? OR last_name LIKE ? OR
        section LIKE ? OR grade_level LIKE ?
    )";
    $term = '%' . $search . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $term;
        $types .= 's';
    }
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
$sql .= " ORDER BY created_at DESC, id DESC";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result();
$totalStudents = (int) $conn
    ->query("SELECT COUNT(*) AS total FROM students")
    ->fetch_assoc()['total'];
$activeStudents = (int) $conn
    ->query("SELECT COUNT(*) AS total FROM students WHERE status = 'active'")
    ->fetch_assoc()['total'];
$inactiveStudents = $totalStudents - $activeStudents;
$message = $_GET['message'] ?? '';
$messageType = ($_GET['type'] ?? '') === 'error' ? 'error' : 'success';
$formStudent = $editStudent ?? [
    'id' => '',
    'student_number' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'grade_level' => '',
    'section' => '',
    'parent_name' => '',
    'parent_email' => ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management | NFC Security System</title>
    <style>
        :root {
            --green: #28d889;
            --green-dark: #087443;
            --green-deep: #064e32;
            --green-soft: #eaf7ef;
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
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            background: var(--background);
            color: var(--text);
            min-height: 100vh;
        }
        .main-content{margin-left:250px;min-height:100vh;padding:30px 35px;transition:margin-left .25s ease}
        .page-container {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
        }
        .page-banner{height:168px;display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:20px;padding:30px;border-radius:14px;background:#111827;box-shadow:0 8px 20px rgba(15,23,42,.10)}
        .page-banner h2{color:#fff;font-size:23px;font-weight:750;margin:0 0 9px;letter-spacing:-.4px}
        .page-banner h2 span {
            color: var(--green);
        }
        .page-banner p{color:#cbd5e1;line-height:1.6;margin:0;font-size:13px}
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .summary-card {
            position: relative;
            overflow: hidden;
            background: var(--white);
            padding: 22px 23px;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
        }
        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--green-dark);
        }
        .summary-card .summary-label {
            display: block;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .summary-card h3 {
            color: #172b22;
            font-size: 30px;
            line-height: 1;
            margin: 0;
            font-weight: 750;
        }
        .panel {
            background: var(--white);
            padding: 21px;
            border: 1px solid var(--border);
            border-radius: 15px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .panel-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 22px;
        }
        .panel-heading h3 {
            color: #172b22;
            font-size: 17px;
            font-weight: 750;
            margin: 0 0 6px;
        }
        .panel-heading p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }
        .input-group label {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }
        .input-group input,
        .input-group select {
            width: 100%;
            min-height: 43px;
            padding: 11px 13px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .input-group input:focus,
        .input-group select:focus,
        .search-form input:focus,
        .search-form select:focus {
            border-color: var(--green-dark);
            box-shadow: 0 0 0 3px rgba(40, 216, 137, 0.13);
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
            flex-wrap: wrap;
        }
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
            color: var(--white);
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s, border-color 0.15s;
        }
        .button:hover {
            background: var(--green-deep);
        }
        .button-secondary {
            background: #f0f8f3;
            color: var(--green-deep);
            border-color: #d4e9dc;
        }
        .button-secondary:hover {
            background: #e2f2e8;
        }
        .button-danger {
            background: #fff1f2;
            color: var(--red);
            border-color: #fecdd3;
        }
        .button-danger:hover {
            background: #ffe4e6;
        }
        .message {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
        }
        .message-success {
            background: var(--green-soft);
            color: var(--green-deep);
            border: 1px solid #bbebcc;
        }
        .message-error {
            background: #fff1f2;
            color: var(--red);
            border: 1px solid #fecdd3;
        }
        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 18px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
            flex-wrap: wrap;
        }
        .search-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            width: 100%;
        }
        .search-form input,
        .search-form select {
            min-height: 40px;
            padding: 10px 12px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            background: var(--white);
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            outline: none;
        }
        .search-form input {
            flex: 1 1 280px;
            min-width: 190px;
        }
        .search-form select {
            min-width: 145px;
        }
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        th, td {
            text-align: left;
            padding: 14px 13px;
            border-bottom: 1px solid #edf1ee;
            font-size: 12px;
            vertical-align: middle;
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
        .action-buttons {
            display: flex;
            gap: 7px;
            align-items: center;
        }
        .small-button {
            min-height: 31px;
            padding: 7px 10px;
            font-size: 11px;
        }
        .inline-form {
            display: inline;
            margin: 0;
        }
        .empty-state {
            text-align: center;
            padding: 38px 15px;
            color: var(--muted);
            line-height: 1.7;
        }
        .table-footer {
            margin-top: 13px;
            color: var(--muted);
            font-size: 11px;
        }
        @media (max-width: 1000px) {
            .main-content {
                padding:30px 35px;
            }
            .form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 700px) {
            .main-content {
                margin-left: 68px;
                padding:22px 15px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .panel {
                padding: 18px;
            }
            .panel-heading {
                flex-direction: column;
            }
            .table-toolbar {
                align-items: stretch;
                flex-direction: column;
            }
            .search-form {
                flex-direction: column;
            }
            .search-form input,
            .search-form select,
            .search-form .button {
                width: 100%;
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
                <h2>Student <span>Management</span></h2>
                <p>Manage Senior High School student records and parent or guardian information.</p>
            </div>
        </section>
        <?php if ($message !== ''): ?>
            <div class="message <?= $messageType === 'error'
                ? 'message-error'
                : 'message-success' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
        <div class="table-toolbar">
            <form method="GET" action="students.php" class="search-form">
                <input
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search name, student no., section..."
                    aria-label="Search students"
                >
                <select name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                </select>
                <button type="submit" class="button">Search</button>
                <a href="students.php" class="button button-secondary">
                    Reset
                </a>
                <a href="#student-form" class="button">
                    + Add Student
                </a>
            </form>
        </div>
        <section class="summary-grid">
            <article class="summary-card">
                <span class="summary-label">Total Registered Students</span>
                <h3><?= $totalStudents ?></h3>
            </article>
            <article class="summary-card">
                <span class="summary-label">Active Students</span>
                <h3><?= $activeStudents ?></h3>
            </article>
            <article class="summary-card">
                <span class="summary-label">Inactive Students</span>
                <h3><?= $inactiveStudents ?></h3>
            </article>
        </section>
        <!-- REGISTERED STUDENTS TABLE -->
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h3>Registered Students</h3>
                    <p>Search student records and manage their active status.</p>
                </div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Student Number</th>
                            <th>Student Name</th>
                            <th>Grade &amp; Section</th>
                            <th>Parent / Guardian</th>
                            <th>Parent Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($students->num_rows > 0): ?>
                        <?php $rowNumber = 1; ?>
                        <?php while ($student = $students->fetch_assoc()): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td><?= e($student['student_number']) ?></td>
                                <td>
                                    <?= e($student['last_name']) ?>,
                                    <?= e($student['first_name']) ?>
                                    <?= e($student['middle_name']) ?>
                                </td>
                                <td>
                                    <?= e($student['grade_level']) ?><br>
                                    <span><?= e($student['section']) ?></span>
                                </td>
                                <td><?= e($student['parent_name'] ?: '—') ?></td>
                                <td><?= e($student['parent_email'] ?: '—') ?></td>
                                <td>
                                    <span class="status <?= $student['status'] === 'active'
                                        ? 'status-active'
                                        : 'status-inactive' ?>">
                                        <?= e($student['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a
                                            class="button small-button button-secondary"
                                            href="students.php?edit=<?= (int) $student['id'] ?>#student-form"
                                        >
                                            Edit
                                        </a>
                                        <form
                                            method="POST"
                                            action="students.php"
                                            class="inline-form"
                                            onsubmit="return confirm('Change this student status?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_status"
                                            >
                                            <input
                                                type="hidden"
                                                name="student_id"
                                                value="<?= (int) $student['id'] ?>"
                                            >
                                            <button
                                                type="submit"
                                                class="button small-button <?= $student['status'] === 'active'
                                                    ? 'button-danger'
                                                    : '' ?>"
                                            >
                                                <?= $student['status'] === 'active'
                                                    ? 'Deactivate'
                                                    : 'Activate' ?>
                                            </button>
                                        </form>
                                        <form
                                            method="POST"
                                            action="students.php"
                                            class="inline-form"
                                            onsubmit="return confirm('PERMANENTLY DELETE this student and connected records? NFC access logs will be kept without the student name. This action cannot be undone.');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_student">
                                            <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                            <button type="submit" class="button small-button button-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                No student records found.<br>
                                Add a student using the form below, or change your search.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                Showing <?= $students->num_rows ?> matching record(s).
            </div>
        </section>
        <!-- ADD OR EDIT STUDENT FORM -->
        <section class="panel" id="student-form">
            <div class="panel-heading">
                <div>
                    <h3><?= $editStudent ? 'Edit Student Information' : 'Add New Student' ?></h3>
                    <p>
                        Fields marked with * are required. Parent or guardian
                        information can be completed when available.
                    </p>
                </div>
            </div>
            <form method="POST" action="students.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_student">
                <input type="hidden" name="student_id" value="<?= e($formStudent['id']) ?>">
                <div class="form-grid">
                    <div class="input-group">
                        <label for="student_number">Student Number *</label>
                        <input
                            type="text"
                            id="student_number"
                            name="student_number"
                            maxlength="50"
                            value="<?= e($formStudent['student_number']) ?>"
                            placeholder="Enter student number"
                            required
                        >
                    </div>
                    <div class="input-group">
                        <label for="first_name">First Name *</label>
                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            maxlength="100"
                            value="<?= e($formStudent['first_name']) ?>"
                            placeholder="Enter first name"
                            required
                        >
                    </div>
                    <div class="input-group">
                        <label for="middle_name">Middle Name</label>
                        <input
                            type="text"
                            id="middle_name"
                            name="middle_name"
                            maxlength="100"
                            value="<?= e($formStudent['middle_name']) ?>"
                            placeholder="Enter middle name (optional)"
                        >
                    </div>
                    <div class="input-group">
                        <label for="last_name">Last Name *</label>
                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            maxlength="100"
                            value="<?= e($formStudent['last_name']) ?>"
                            placeholder="Enter last name"
                            required
                        >
                    </div>
                    <div class="input-group">
                        <label for="grade_level">Grade Level *</label>
                        <select id="grade_level" name="grade_level" required>
                            <option value="">Select grade level</option>
                            <option value="Grade 11" <?= $formStudent['grade_level'] === 'Grade 11' ? 'selected' : '' ?>>
                                Grade 11
                            </option>
                            <option value="Grade 12" <?= $formStudent['grade_level'] === 'Grade 12' ? 'selected' : '' ?>>
                                Grade 12
                            </option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="section">Section *</label>
                        <input
                            type="text"
                            id="section"
                            name="section"
                            maxlength="50"
                            value="<?= e($formStudent['section']) ?>"
                            placeholder="e.g. STEM 11-A"
                            required
                        >
                    </div>
                    <div class="input-group">
                        <label for="parent_name">Parent / Guardian Name</label>
                        <input
                            type="text"
                            id="parent_name"
                            name="parent_name"
                            maxlength="150"
                            value="<?= e($formStudent['parent_name']) ?>"
                            placeholder="Enter parent or guardian name"
                        >
                    </div>
                    <div class="input-group">
                        <label for="parent_email">Parent / Guardian Email</label>
                        <input
                            type="email"
                            id="parent_email"
                            name="parent_email"
                            maxlength="150"
                            value="<?= e($formStudent['parent_email']) ?>"
                            placeholder="parent@example.com"
                        >
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">
                        <?= $editStudent ? 'Save Changes' : 'Register Student' ?>
                    </button>
                    <?php if ($editStudent): ?>
                        <a href="students.php" class="button button-secondary">
                            Cancel Edit
                        </a>
                    <?php else: ?>
                        <button type="reset" class="button button-secondary">
                            Clear Form
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>
</main>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>

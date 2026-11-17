
<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function displayTime($time): string
{
    if (!$time) {
        return '—';
    }
    $timestamp = strtotime((string)$time);
    return $timestamp === false ? e($time) : date('h:i A', $timestamp);
}

$message = '';
$error = '';
$editSchedule = null;
$days = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday',
    'Friday', 'Saturday', 'Sunday'
];
$validStatuses = ['active', 'inactive'];

/* HANDLE FORM SUBMISSIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /* ADD OR EDIT SCHEDULE */
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $day = trim((string)($_POST['schedule_day'] ?? ''));
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $late = trim((string)($_POST['late_after'] ?? ''));
            $absence = trim((string)($_POST['absence_check_time'] ?? ''));
            $room = trim((string)($_POST['room_name'] ?? ''));
            $status = (string)($_POST['status'] ?? 'active');

            $validTime = static function ($value): bool {
                return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
            };

            if (
                $subjectId < 1 ||
                !in_array($day, $days, true) ||
                !$validTime($start) ||
                !$validTime($end) ||
                !$validTime($late) ||
                !$validTime($absence) ||
                $room === '' ||
                strlen($room) > 100 ||
                !in_array($status, $validStatuses, true)
            ) {
                $error = 'Please complete all fields with valid values.';
            } elseif ($start >= $end) {
                $error = 'The class end time must be later than the start time.';
            } elseif (
                $late < $start || $late > $end ||
                $absence < $start || $absence > $end
            ) {
                $error = 'Late cutoff and absence check time must be within the class schedule.';
            } else {
                /* Verify that the selected subject exists. */
                $check = $conn->prepare(
                    "SELECT id FROM subjects WHERE id = ? LIMIT 1"
                );

                if (!$check) {
                    $error = 'Unable to check the selected subject: ' . $conn->error;
                } else {
                    $check->bind_param('i', $subjectId);
                    $check->execute();
                    $subjectResult = $check->get_result();

                    if (!$subjectResult || $subjectResult->num_rows === 0) {
                        $error = 'Please select an existing subject.';
                    } else {
                        if ($id > 0) {
                            $stmt = $conn->prepare(
                                "UPDATE class_schedules
                                 SET subject_id = ?,
                                     schedule_day = ?,
                                     start_time = ?,
                                     end_time = ?,
                                     late_after = ?,
                                     absence_check_time = ?,
                                     room_name = ?,
                                     status = ?
                                 WHERE id = ?"
                            );

                            if ($stmt) {
                                $stmt->bind_param(
                                    'isssssssi',
                                    $subjectId,
                                    $day,
                                    $start,
                                    $end,
                                    $late,
                                    $absence,
                                    $room,
                                    $status,
                                    $id
                                );
                            }
                        } else {
                            $stmt = $conn->prepare(
                                "INSERT INTO class_schedules
                                    (subject_id, schedule_day, start_time,
                                     end_time, late_after, absence_check_time,
                                     room_name, status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                            );

                            if ($stmt) {
                                $stmt->bind_param(
                                    'isssssss',
                                    $subjectId,
                                    $day,
                                    $start,
                                    $end,
                                    $late,
                                    $absence,
                                    $room,
                                    $status
                                );
                            }
                        }

                        if (!isset($stmt) || !$stmt) {
                            $error = 'Unable to prepare the schedule save operation: '
                                . $conn->error;
                        } elseif ($stmt->execute()) {
                            header(
                                'Location: schedules.php?success=' .
                                ($id > 0 ? 'updated' : 'created')
                            );
                            exit;
                        } else {
                            $error = 'Unable to save the schedule: ' . $stmt->error;
                        }
                    }
                    $check->close();
                }
            }
        }

        /* UPDATE SCHEDULE STATUS */
        elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');

            if ($id > 0 && in_array($status, $validStatuses, true)) {
                $stmt = $conn->prepare(
                    "UPDATE class_schedules SET status = ? WHERE id = ?"
                );

                if (!$stmt) {
                    $error = 'Unable to prepare schedule status update: ' . $conn->error;
                } else {
                    $stmt->bind_param('si', $status, $id);

                    if ($stmt->execute()) {
                        header('Location: schedules.php?success=status');
                        exit;
                    }

                    $error = 'Unable to update schedule status: ' . $stmt->error;
                }
            } else {
                $error = 'Invalid schedule status.';
            }
        }
    }
}

/* SUCCESS MESSAGES */
if (isset($_GET['success'])) {
    $successMessages = [
        'created' => 'Schedule added successfully.',
        'updated' => 'Schedule updated successfully.',
        'status'  => 'Schedule status updated successfully.'
    ];
    $message = $successMessages[(string)$_GET['success']] ?? '';
}

/* LOAD SCHEDULE FOR EDITING */
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare(
        "SELECT * FROM class_schedules WHERE id = ?"
    );

    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editSchedule = $stmt->get_result()->fetch_assoc();

        if (!$editSchedule) {
            $error = 'Schedule not found.';
        }
    } else {
        $error = 'Unable to load the schedule: ' . $conn->error;
    }
}

/* LOAD SUBJECTS FOR THE DROPDOWN */
$subjects = $conn->query(
    "SELECT
        s.id,
        s.subject_code,
        s.subject_name,
        s.grade_level,
        s.section,
        s.status,
        u.full_name AS teacher_name
     FROM subjects s
     LEFT JOIN users u ON s.teacher_id = u.id
     ORDER BY
        CASE
            WHEN LOWER(COALESCE(s.status, '')) = 'active' THEN 0
            ELSE 1
        END,
        s.subject_name"
);

if (!$subjects && $error === '') {
    $error = 'Unable to load subjects: ' . $conn->error;
}

/* SEARCH AND LOAD SCHEDULES */
$search = trim((string)($_GET['search'] ?? ''));
$searchLike = '%' . $search . '%';

$stmt = $conn->prepare(
    "SELECT
        cs.*,
        s.subject_code,
        s.subject_name,
        s.grade_level,
        s.section,
        u.full_name AS teacher_name
     FROM class_schedules cs
     INNER JOIN subjects s ON cs.subject_id = s.id
     LEFT JOIN users u ON s.teacher_id = u.id
     WHERE s.subject_name LIKE ?
        OR s.subject_code LIKE ?
        OR s.section LIKE ?
        OR cs.room_name LIKE ?
        OR cs.schedule_day LIKE ?
     ORDER BY
        FIELD(
            cs.schedule_day,
            'Monday', 'Tuesday', 'Wednesday', 'Thursday',
            'Friday', 'Saturday', 'Sunday'
        ),
        cs.start_time"
);

$schedules = false;

if ($stmt) {
    $stmt->bind_param(
        'sssss',
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
    $stmt->execute();
    $schedules = $stmt->get_result();
} elseif ($error === '') {
    $error = 'Unable to load schedules: ' . $conn->error;
}

/* SCHEDULE COUNTS */
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'active'), 0) AS active_count,
        COALESCE(SUM(status = 'inactive'), 0) AS inactive_count
     FROM class_schedules"
);

$counts = $countResult
    ? $countResult->fetch_assoc()
    : ['total' => 0, 'active_count' => 0, 'inactive_count' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Schedules | NFC Security System</title>
    <style>
        :root {
            --green: #28d889;
            --green-dark: #087443;
            --green-deep: #064e32;
            --bg: #f1f3f6;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 30px 35px;
        }
        .page-container {
            width: 100%;
            max-width: none;
            margin: 0 auto;
        }
        .page-banner {
            height: 168px;
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 30px;
            border-radius: 14px;
            background: #111827;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .1);
        }
        .page-banner h1 {
            margin: 0 0 9px;
            color: #fff;
            font-size: 23px;
            font-weight: 750;
            letter-spacing: -.4px;
        }
        .page-banner h1 span {
            color: var(--green);
        }
        .page-banner p {
            margin: 0;
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.6;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        .table-toolbar {
            margin-bottom: 20px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
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
            font-size: 12px;
        }
        .search-form input:focus,
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--green-dark);
            box-shadow: 0 0 0 3px rgba(40, 216, 137, .13);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .summary-card {
            position: relative;
            padding: 22px 23px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
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
        .message {
            margin-bottom: 20px;
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
        }
        .message.success {
            border: 1px solid #bbebcc;
            background: #eaf7ef;
            color: var(--green-deep);
        }
        .message.error {
            border: 1px solid #fecdd3;
            background: #fff1f2;
            color: #b91c1c;
        }
        .panel {
            min-width: 0;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: #fff;
            box-shadow: var(--shadow);
        }
        .panel-heading {
            margin-bottom: 18px;
        }
        .panel-heading h2 {
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
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 17px;
        }
        .form-group label {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }
        .form-group input,
        .form-group select {
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
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 11px;
        }
        table {
            width: 100%;
            min-width: 1000px;
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
            letter-spacing: .35px;
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
        .subject-name {
            color: #1e293b;
            font-weight: 700;
        }
        .subtext {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
        }
        .pill {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }
        .pill.active {
            background: #e4f8ec;
            color: #087443;
        }
        .pill.inactive {
            background: #f1f5f9;
            color: #475569;
        }
        .status-form {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .status-form select {
            min-height: 32px;
            padding: 6px 8px;
            border: 1px solid #d5dfd9;
            border-radius: 7px;
            background: #fff;
            font-family: inherit;
            font-size: 11px;
        }
        .status-form .button {
            min-height: 32px;
            padding: 7px 10px;
            font-size: 11px;
        }
        .note {
            margin-top: 16px;
            padding: 12px 14px;
            border-left: 3px solid var(--green-dark);
            border-radius: 7px;
            background: #f0fdf4;
            color: var(--green-deep);
            font-size: 12px;
            line-height: 1.6;
        }
        .empty-state {
            padding: 38px 15px;
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
        }
        .form-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }
        @media (max-width: 1100px) {
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
            .search-form {
                flex-direction: column;
            }
            .search-form input,
            .search-form .button {
                width: 100%;
            }
            .form-grid {
                grid-template-columns: 1fr;
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
                <h1>Class <span>Schedules</span></h1>
                <p>Create and manage subject schedules, rooms, and attendance cutoffs.</p>
            </div>
        </section>

        <?php if ($message !== ''): ?>
            <div class="message success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="table-toolbar">
            <form class="search-form" method="GET" action="schedules.php">
                <input
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search subject, code, section, day, or room..."
                    aria-label="Search schedules"
                >
                <button class="button" type="submit">Search</button>
                <a class="button button-secondary" href="schedules.php">Reset</a>
                <a class="button" href="#schedule-form">+ Add Schedule</a>
            </form>
        </div>

        <section class="summary-grid">
            <article class="summary-card">
                <span>Total Schedules</span>
                <strong><?= (int)($counts['total'] ?? 0) ?></strong>
            </article>
            <article class="summary-card">
                <span>Active Schedules</span>
                <strong><?= (int)($counts['active_count'] ?? 0) ?></strong>
            </article>
            <article class="summary-card">
                <span>Inactive Schedules</span>
                <strong><?= (int)($counts['inactive_count'] ?? 0) ?></strong>
            </article>
        </section>

        <section class="panel" style="margin-bottom:24px">
            <div class="panel-heading">
                <h2>Registered Schedules</h2>
                <p>Review class schedules, assigned subjects and teachers, rooms, and attendance cutoffs.</p>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Subject / Section</th>
                            <th>Day &amp; Time</th>
                            <th>Room</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($schedules && $schedules->num_rows > 0): ?>
                        <?php $rowNumber = 1; ?>
                        <?php while ($row = $schedules->fetch_assoc()): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <div class="subject-name"><?= e($row['subject_name'] ?? '') ?></div>
                                    <span class="subtext">
                                        <?= e($row['subject_code'] ?? '') ?> ·
                                        <?= e($row['grade_level'] ?? '') ?> / <?= e($row['section'] ?? '') ?>
                                    </span>
                                    <span class="subtext">
                                        Teacher: <?= e(($row['teacher_name'] ?? '') ?: 'Not assigned') ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= e($row['schedule_day'] ?? '') ?></strong>
                                    <span class="subtext">
                                        <?= displayTime($row['start_time'] ?? '') ?> –
                                        <?= displayTime($row['end_time'] ?? '') ?>
                                    </span>
                                    <span class="subtext">
                                        Late cutoff: <?= displayTime($row['late_after'] ?? '') ?>
                                    </span>
                                    <span class="subtext">
                                        Absence check: <?= displayTime($row['absence_check_time'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= e($row['room_name'] ?? '') ?></td>
                                <td>
                                    <span class="pill <?= e($row['status'] ?? '') ?>">
                                        <?= e(strtoupper((string)($row['status'] ?? ''))) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="button button-secondary" href="schedules.php?edit=<?= (int)$row['id'] ?>">
                                        Edit
                                    </a>
                                    <form class="status-form" method="POST" action="schedules.php" style="margin-top:7px">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <select name="status" aria-label="Schedule status">
                                            <option value="active" <?= ($row['status'] ?? '') === 'active' ? 'selected' : '' ?>>
                                                Active
                                            </option>
                                            <option value="inactive" <?= ($row['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                                Inactive
                                            </option>
                                        </select>
                                        <button class="button" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                No schedules found.
                                <?php if ($search !== ''): ?>
                                    Try another search term.
                                <?php else: ?>
                                    Add a schedule using the form below.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="schedule-form">
            <div class="panel-heading">
                <h2><?= $editSchedule ? 'Edit Schedule' : 'Add Schedule' ?></h2>
                <p>Choose a subject and enter the class schedule details.</p>
            </div>
            <form method="POST" action="schedules.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editSchedule['id'] ?? 0) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="subject_id">Subject *</label>
                        <select id="subject_id" name="subject_id" required>
                            <option value="">-- Choose a subject --</option>
                            <?php if ($subjects): ?>
                                <?php while ($s = $subjects->fetch_assoc()): ?>
                                    <option
                                        value="<?= (int)$s['id'] ?>"
                                        <?= (int)($editSchedule['subject_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e(trim(($s['subject_code'] ?? '') . ' — ' . ($s['subject_name'] ?? 'Unnamed subject') . ' (' . ($s['grade_level'] ?? '') . ' / ' . ($s['section'] ?? '') . ')')) ?>
                                        <?= strtolower((string)($s['status'] ?? '')) !== 'active' ? ' [Inactive]' : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="schedule_day">Class Day *</label>
                        <select id="schedule_day" name="schedule_day" required>
                            <?php foreach ($days as $day): ?>
                                <option value="<?= e($day) ?>" <?= ($editSchedule['schedule_day'] ?? 'Monday') === $day ? 'selected' : '' ?>>
                                    <?= e($day) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Start Time *</label>
                        <input
                            id="start_time"
                            type="time"
                            name="start_time"
                            required
                            value="<?= e($editSchedule['start_time'] ?? '') ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time *</label>
                        <input
                            id="end_time"
                            type="time"
                            name="end_time"
                            required
                            value="<?= e($editSchedule['end_time'] ?? '') ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="late_after">Late Cutoff *</label>
                        <input
                            id="late_after"
                            type="time"
                            name="late_after"
                            required
                            value="<?= e($editSchedule['late_after'] ?? '') ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="absence_check_time">Absence Check Time *</label>
                        <input
                            id="absence_check_time"
                            type="time"
                            name="absence_check_time"
                            required
                            value="<?= e($editSchedule['absence_check_time'] ?? '') ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="room_name">Room Name *</label>
                        <input
                            id="room_name"
                            name="room_name"
                            maxlength="100"
                            required
                            placeholder="e.g. Room 201"
                            value="<?= e($editSchedule['room_name'] ?? '') ?>"
                        >
                    </div>
                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="active" <?= ($editSchedule['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="inactive" <?= ($editSchedule['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button" type="submit">
                        <?= $editSchedule ? 'Save Changes' : 'Add Schedule' ?>
                    </button>
                    <button class="button button-secondary" type="reset">Clear Form</button>
                    <?php if ($editSchedule): ?>
                        <a class="button button-secondary" href="schedules.php">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
            <div class="note">
                <strong>Reminder:</strong> Late Cutoff is the time from which a student is considered late.
                Absence Check is when the system should check whether the student has arrived.
                Attendance automation will be connected in a later step.
            </div>
        </section>
    </div>
</main>
</body>
</html>

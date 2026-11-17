
<?php
session_start();

date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once '../config/mail.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (
    !isset($_SESSION['role']) ||
    !in_array(strtolower($_SESSION['role']), ['admin', 'teacher'])
) {
    http_response_code(403);
    exit("Access denied.");
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getCurrentUrl()
{
    return 'test_tap.php';
}

$message = '';
$messageType = '';

/*
|--------------------------------------------------------------------------
| PROCESS SIMULATED NFC TAP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $roomName = trim($_POST['room_name'] ?? '');

    if (!$cardId || $roomName === '') {
        $message = "Please select a student and enter the room name.";
        $messageType = "error";
    } else {

        /*
         * Find active NFC card and active student.
         * Grade level and section are included so the system can
         * match the student to the appropriate subject schedule.
         */
        $stmt = $conn->prepare("
            SELECT
                c.id AS card_id,
                c.card_uid,
                c.status AS card_status,
                s.id AS student_id,
                s.student_number,
                s.first_name,
                s.middle_name,
                s.last_name,
                s.grade_level,
                s.section,
                s.parent_email
            FROM nfc_cards c
            INNER JOIN students s ON s.id = c.student_id
            WHERE c.id = ?
              AND c.status = 'active'
              AND s.status = 'active'
            LIMIT 1
        ");

        if (!$stmt) {
            die("Database error: " . e($conn->error));
        }

        $stmt->bind_param("i", $cardId);
        $stmt->execute();

        $result = $stmt->get_result();
        $student = $result->fetch_assoc();

        $stmt->close();

        if (!$student) {

            $message = "The selected card or student is not active.";
            $messageType = "error";

        } else {

            $studentId = (int)$student['student_id'];
            $cardUid = $student['card_uid'];

            $studentName = trim(
                $student['first_name'] . ' ' .
                ($student['middle_name']
                    ? $student['middle_name'] . ' '
                    : '') .
                $student['last_name']
            );

            $gradeLevel = trim($student['grade_level'] ?? '');
            $section = trim($student['section'] ?? '');

            $now = new DateTime(
                'now',
                new DateTimeZone('Asia/Manila')
            );

            $tapDate = $now->format('Y-m-d');
            $tapTime = $now->format('Y-m-d H:i:s');
            $dayName = $now->format('l');
            $timeNow = $now->format('H:i:s');

            /*
             * STEP 1:
             * Save the simulated tap to access_logs.
             */
            $accessResult = 'granted';
            $accessRemarks = 'SIMULATED TAP - software testing only';

            $stmt = $conn->prepare("
                INSERT INTO access_logs
                    (
                        student_id,
                        card_uid,
                        room_name,
                        tap_time,
                        access_result,
                        remarks
                    )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                die("Database error: " . e($conn->error));
            }

            $stmt->bind_param(
                "isssss",
                $studentId,
                $cardUid,
                $roomName,
                $tapTime,
                $accessResult,
                $accessRemarks
            );

            if (!$stmt->execute()) {
                die("Unable to save access log: " . e($stmt->error));
            }

            $stmt->close();

            /*
             * STEP 2:
             * Find today's active schedule using the student's
             * grade level and section.
             *
             * IMPORTANT:
             * This query does NOT require schedule_students.
             * The student is matched through subjects.grade_level
             * and subjects.section instead.
             */
            $stmt = $conn->prepare("
                SELECT
                    cs.id AS schedule_id,
                    cs.start_time,
                    cs.end_time,
                    cs.late_after,
                    cs.room_name,
                    sub.subject_name,
                    sub.subject_code
                FROM class_schedules cs
                INNER JOIN subjects sub
                    ON sub.id = cs.subject_id
                WHERE LOWER(TRIM(sub.grade_level))
                        = LOWER(TRIM(?))
                  AND LOWER(TRIM(sub.section))
                        = LOWER(TRIM(?))
                  AND cs.schedule_day = ?
                  AND cs.status = 'active'
                  AND LOWER(TRIM(cs.room_name))
                        = LOWER(TRIM(?))
                  AND sub.status = 'active'
                ORDER BY cs.start_time ASC
                LIMIT 1
            ");

            if (!$stmt) {
                die("Database error: " . e($conn->error));
            }

            $stmt->bind_param(
                "ssss",
                $gradeLevel,
                $section,
                $dayName,
                $roomName
            );

            $stmt->execute();

            $scheduleResult = $stmt->get_result();
            $schedule = $scheduleResult->fetch_assoc();

            $stmt->close();

            /*
             * STEP 3:
             * Create or update attendance if a matching schedule
             * was found.
             */
            if ($schedule) {

                $scheduleId = (int)$schedule['schedule_id'];
                $lateAfter = $schedule['late_after'];

                /*
                 * Determine whether the student is present or late.
                 */
                $attendanceStatus = (
                    $timeNow > $lateAfter
                ) ? 'late' : 'present';

                /*
                 * Check if attendance already exists for this
                 * student, schedule, and class date.
                 */
                $stmt = $conn->prepare("
                    SELECT id, first_tap_time
                    FROM attendance
                    WHERE student_id = ?
                      AND schedule_id = ?
                      AND class_date = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    die("Database error: " . e($conn->error));
                }

                $stmt->bind_param(
                    "iis",
                    $studentId,
                    $scheduleId,
                    $tapDate
                );

                $stmt->execute();

                $existingResult = $stmt->get_result();
                $existingAttendance = $existingResult->fetch_assoc();

                $stmt->close();

                if ($existingAttendance) {

                    /*
                     * Attendance already exists.
                     * Preserve the original first tap time.
                     */
                    $attendanceId = (int)$existingAttendance['id'];

                    $stmt = $conn->prepare("
                        UPDATE attendance
                        SET updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");

                    if (!$stmt) {
                        die("Database error: " . e($conn->error));
                    }

                    $stmt->bind_param("i", $attendanceId);

                    if (!$stmt->execute()) {
                        die(
                            "Unable to update attendance: " .
                            e($stmt->error)
                        );
                    }

                    $stmt->close();

                    $savedStatus = 'already recorded';

                } else {

                    /*
                     * No attendance exists yet.
                     * Insert a new attendance record.
                     */
                    $attendanceRemarks =
                        'Recorded through simulated NFC tap';

                    $stmt = $conn->prepare("
                        INSERT INTO attendance
                            (
                                student_id,
                                schedule_id,
                                class_date,
                                status,
                                first_tap_time,
                                remarks
                            )
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    if (!$stmt) {
                        die("Database error: " . e($conn->error));
                    }

                    $stmt->bind_param(
                        "iissss",
                        $studentId,
                        $scheduleId,
                        $tapDate,
                        $attendanceStatus,
                        $tapTime,
                        $attendanceRemarks
                    );

                    if (!$stmt->execute()) {
                        die(
                            "Unable to save attendance: " .
                            e($stmt->error)
                        );
                    }

                    $stmt->close();

                    $savedStatus = ucfirst($attendanceStatus);
                }

                $subjectName = $schedule['subject_name'] ?? 'Class';

                /*
                 * STEP 4: Send parent email once per attendance record.
                 * Add the parent_email_sent column using the SQL instructions.
                 */
                $emailStatusMessage = '';
                $checkEmailStmt = $conn->prepare("
                    SELECT parent_email_sent
                    FROM attendance
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$checkEmailStmt) {
                    error_log('EMAIL CHECK ERROR: ' . $conn->error);
                    $emailStatusMessage = ' Parent email status could not be checked.';
                } else {
                    $checkEmailStmt->bind_param("i", $attendanceId);
                    $checkEmailStmt->execute();
                    $emailCheckResult = $checkEmailStmt->get_result();
                    $emailRecord = $emailCheckResult->fetch_assoc();
                    $checkEmailStmt->close();

                    $parentEmail = trim($student['parent_email'] ?? '');

                    if (
                        $emailRecord &&
                        (int)$emailRecord['parent_email_sent'] === 0 &&
                        filter_var($parentEmail, FILTER_VALIDATE_EMAIL)
                    ) {
                        $emailSubject = 'Student Attendance Notification';
                        $emailBody = '
                            <div style="font-family:Arial,sans-serif;line-height:1.6;color:#24352b;">
                                <h2 style="color:#166534;">NFC Student Attendance Notification</h2>
                                <p>Dear Parent/Guardian,</p>
                                <p>This is to inform you that your child has tapped their student ID at the school.</p>
                                <table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:560px;">
                                    <tr><td><strong>Student</strong></td><td>' . e($studentName) . '</td></tr>
                                    <tr><td><strong>Student Number</strong></td><td>' . e($student['student_number']) . '</td></tr>
                                    <tr><td><strong>Grade &amp; Section</strong></td><td>' . e($gradeLevel . ' - ' . $section) . '</td></tr>
                                    <tr><td><strong>Subject</strong></td><td>' . e($subjectName) . '</td></tr>
                                    <tr><td><strong>Room</strong></td><td>' . e($roomName) . '</td></tr>
                                    <tr><td><strong>Attendance</strong></td><td>' . e(ucfirst($attendanceStatus)) . '</td></tr>
                                    <tr><td><strong>Date</strong></td><td>' . e($now->format('F d, Y')) . '</td></tr>
                                    <tr><td><strong>Time</strong></td><td>' . e($now->format('h:i:s A')) . '</td></tr>
                                </table>
                                <p>This is an automated message from the NFC Security System.</p>
                            </div>
                        ';

                        if (sendSystemEmail($parentEmail, $emailSubject, $emailBody)) {
                            $markEmailStmt = $conn->prepare("
                                UPDATE attendance
                                SET parent_email_sent = 1
                                WHERE id = ?
                            ");
                            if ($markEmailStmt) {
                                $markEmailStmt->bind_param("i", $attendanceId);
                                if ($markEmailStmt->execute()) {
                                    $emailStatusMessage = ' Parent email sent successfully.';
                                } else {
                                    $emailStatusMessage = ' Email was sent, but its status could not be saved.';
                                    error_log('EMAIL STATUS UPDATE ERROR: ' . $markEmailStmt->error);
                                }
                                $markEmailStmt->close();
                            } else {
                                $emailStatusMessage = ' Email was sent, but its status could not be saved.';
                                error_log('EMAIL STATUS PREPARE ERROR: ' . $conn->error);
                            }
                        } else {
                            $emailStatusMessage = ' Parent email failed to send. Check config/mail.php and the PHP error log.';
                        }
                    } elseif ($parentEmail === '') {
                        $emailStatusMessage = ' No parent email is registered for this student.';
                    } elseif (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                        $emailStatusMessage = ' The registered parent email address is invalid.';
                    } elseif ($emailRecord && (int)$emailRecord['parent_email_sent'] === 1) {
                        $emailStatusMessage = ' Parent email was already sent for this attendance record.';
                    }
                }

                $message =
                    "Test tap recorded for " .
                    $studentName .
                    " (" . $student['student_number'] . "). " .
                    "Subject: " . $subjectName . ". " .
                    "Room: " . $roomName . ". " .
                    "Attendance: " . $savedStatus . "." .
                    $emailStatusMessage;

            } else {

                /*
                 * The access log is still saved, but attendance
                 * cannot be created without a matching active
                 * schedule for this grade, section, day, and room.
                 */
                $message =
                    "The tap log was saved for " .
                    $studentName .
                    " (" . $student['student_number'] . "), " .
                    "but attendance was not created. No active " .
                    "schedule matched the student's grade level (" .
                    $gradeLevel . "), section (" . $section .
                    "), today's day (" . $dayName .
                    "), and room (" . $roomName . "). " .
                    "Please check the student and subject schedule settings.";
            }

            $messageType = "success";
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ACTIVE STUDENTS WITH ACTIVE REGISTERED NFC CARDS
|--------------------------------------------------------------------------
*/
$students = $conn->query("
    SELECT
        c.id AS card_id,
        c.card_uid,
        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.grade_level,
        s.section
    FROM nfc_cards c
    INNER JOIN students s ON s.id = c.student_id
    WHERE c.status = 'active'
      AND s.status = 'active'
    ORDER BY s.last_name, s.first_name
");

if (!$students) {
    die("Unable to load registered cards: " . e($conn->error));
}

/*
|--------------------------------------------------------------------------
| LOAD RECENT ACCESS LOGS
|--------------------------------------------------------------------------
*/
$logs = $conn->query("
    SELECT
        al.id,
        al.card_uid,
        al.room_name,
        al.tap_time,
        al.access_result,
        al.remarks,
        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name
    FROM access_logs al
    LEFT JOIN students s ON s.id = al.student_id
    ORDER BY al.tap_time DESC, al.id DESC
    LIMIT 20
");

if (!$logs) {
    die("Unable to load access logs: " . e($conn->error));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Simulated NFC Tap | NFC Security System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f1f7f3;
            color: #173d2b;
        }

        .header {
            background: #14532d;
            color: white;
            padding: 22px 5%;
        }

        .header h2 {
            margin: 0 0 6px;
            font-size: 23px;
        }

        .header p {
            margin: 0;
            font-size: 14px;
        }

        .container {
            max-width: 1100px;
            width: 92%;
            margin: 28px auto;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: 27px;
        }

        .page-heading p {
            margin: 0;
            color: #61776a;
            font-size: 14px;
        }

        .card {
            background: white;
            border: 1px solid #dce9df;
            border-radius: 13px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 5px 16px rgba(20, 83, 45, 0.04);
        }

        .card h2 {
            margin: 0 0 12px;
            font-size: 21px;
        }

        .description {
            color: #61776a;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin: 15px 0 7px;
            font-size: 14px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbded0;
            border-radius: 7px;
            background: white;
            font-size: 14px;
            color: #173d2b;
        }

        input:focus,
        select:focus {
            outline: 2px solid #a7d9b6;
            border-color: #16803d;
        }

        button,
        .back-button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            background: #16803d;
            color: white;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }

        button {
            width: 100%;
            margin-top: 18px;
        }

        button:hover,
        .back-button:hover {
            background: #126b33;
        }

        .back-button {
            background: white;
            color: #14532d;
            border: 1px solid #cbded0;
        }

        .notice {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .notice.success {
            background: #dcfce7;
            color: #166534;
        }

        .notice.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .notice.warning {
            background: #fff6df;
            color: #854d0e;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            padding: 13px 11px;
            border-bottom: 1px solid #e3ece5;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #eff6f0;
            color: #14532d;
        }

        .badge {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: bold;
        }

        .badge.granted {
            background: #dcfce7;
            color: #166534;
        }

        .badge.denied {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            text-align: center;
            color: #718579;
            padding: 25px;
        }

        @media (max-width: 650px) {
            .container {
                width: 94%;
                margin: 18px auto;
            }

            .card {
                padding: 17px;
            }

            .page-heading h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h2>NFC Security System</h2>
    <p>Southern Philippines Institute of Science and Technology (CARBAG)</p>
</header>

<main class="container">

    <div class="page-heading">
        <div>
            <h1>Simulated NFC Tap</h1>
            <p>Test the software before connecting an actual NFC reader.</p>
        </div>

        <a class="back-button" href="dashboard.php">
            Back to Dashboard
        </a>
    </div>

    <div class="notice warning">
        <strong>Testing mode only:</strong>
        This page simulates a card tap. It does not read a physical NFC card
        or unlock a door.
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2>Simulate a Card Tap</h2>

        <p class="description">
            Select a student with an active registered NFC card and enter
            the room name. The system will try to match the student's
            grade level and section with today's active subject schedule.
        </p>

        <form method="POST" action="<?= e(getCurrentUrl()) ?>">

            <label for="card_id">Student / Registered Card *</label>

            <select name="card_id" id="card_id" required>
                <option value="">-- Select a student --</option>

                <?php while ($student = $students->fetch_assoc()): ?>
                    <?php
                    $fullName = trim(
                        $student['last_name'] . ', ' .
                        $student['first_name'] . ' ' .
                        ($student['middle_name'] ?? '')
                    );
                    ?>

                    <option value="<?= (int)$student['card_id'] ?>">
                        <?= e($fullName) ?>
                        (<?= e($student['student_number']) ?>)
                        — <?= e($student['grade_level']) ?>
                        / <?= e($student['section']) ?>
                        — UID: <?= e($student['card_uid']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label for="room_name">Room Name *</label>

            <input
                type="text"
                name="room_name"
                id="room_name"
                placeholder="e.g. 143"
                required
                maxlength="100"
            >

            <button type="submit">Simulate NFC Tap</button>
        </form>
    </section>

    <section class="card">
        <h2>Recent Access Logs</h2>

        <p class="description">
            The latest 20 tap events recorded in the access_logs table.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Student Name</th>
                        <th>Card UID</th>
                        <th>Room</th>
                        <th>Tap Date &amp; Time</th>
                        <th>Result</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($logs->num_rows > 0): ?>

                        <?php while ($log = $logs->fetch_assoc()): ?>
                            <?php
                            $logName = 'Unknown student';

                            if ($log['student_number'] !== null) {
                                $logName = trim(
                                    $log['first_name'] . ' ' .
                                    ($log['middle_name']
                                        ? $log['middle_name'] . ' '
                                        : '') .
                                    $log['last_name']
                                );
                            }

                            $resultClass = (
                                strtolower($log['access_result']) === 'granted'
                            ) ? 'granted' : 'denied';
                            ?>

                            <tr>
                                <td>
                                    <?= e($log['student_number'] ?? '—') ?>
                                </td>

                                <td><?= e($logName) ?></td>
                                <td><?= e($log['card_uid']) ?></td>
                                <td><?= e($log['room_name']) ?></td>

                                <td>
                                    <?= e(date(
                                        'M d, Y h:i:s A',
                                        strtotime($log['tap_time'])
                                    )) ?>
                                </td>

                                <td>
                                    <span class="badge <?= e($resultClass) ?>">
                                        <?= e(ucfirst($log['access_result'])) ?>
                                    </span>
                                </td>

                                <td><?= e($log['remarks'] ?? '') ?></td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="empty">
                                No access logs found yet.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>

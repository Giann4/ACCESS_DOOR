<?php

date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once '../config/mail.php';

/*
|--------------------------------------------------------------------------
| CURRENT DATE AND TIME
|--------------------------------------------------------------------------
*/

$now = new DateTime('now', new DateTimeZone('Asia/Manila'));

$today = $now->format('Y-m-d');
$dayName = $now->format('l');
$currentTime = $now->format('H:i:s');

$processed = 0;
$emailsSent = 0;
$errors = [];

/*
|--------------------------------------------------------------------------
| STEP 1: FIND SCHEDULES READY FOR ABSENCE CHECK
|--------------------------------------------------------------------------
*/

$scheduleStmt = $conn->prepare("
    SELECT
        cs.id AS schedule_id,
        cs.subject_id,
        cs.schedule_day,
        cs.start_time,
        cs.end_time,
        cs.absence_check_time,
        cs.room_name,
        sub.subject_name,
        sub.subject_code
    FROM class_schedules cs
    INNER JOIN subjects sub
        ON sub.id = cs.subject_id
    WHERE cs.schedule_day = ?
      AND cs.absence_check_time <= ?
      AND cs.status = 'active'
      AND sub.status = 'active'
    ORDER BY cs.absence_check_time ASC
");

if (!$scheduleStmt) {
    die("Schedule query error: " . $conn->error);
}

$scheduleStmt->bind_param(
    "ss",
    $dayName,
    $currentTime
);

$scheduleStmt->execute();

$schedules = $scheduleStmt->get_result();

/*
|--------------------------------------------------------------------------
| STEP 2: PROCESS EACH SCHEDULE
|--------------------------------------------------------------------------
*/

while ($schedule = $schedules->fetch_assoc()) {

    $scheduleId = (int)$schedule['schedule_id'];
    $subjectName = $schedule['subject_name'];
    $roomName = $schedule['room_name'];

    /*
    |--------------------------------------------------------------------------
    | STEP 3: GET ENROLLED STUDENTS
    |--------------------------------------------------------------------------
    */

    $studentStmt = $conn->prepare("
        SELECT
            s.id AS student_id,
            s.student_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.grade_level,
            s.section,
            s.parent_email
        FROM schedule_students ss
        INNER JOIN students s
            ON s.id = ss.student_id
        WHERE ss.schedule_id = ?
          AND s.status = 'active'
    ");

    if (!$studentStmt) {
        $errors[] = "Student query error: " . $conn->error;
        continue;
    }

    $studentStmt->bind_param("i", $scheduleId);
    $studentStmt->execute();

    $students = $studentStmt->get_result();

    /*
    |--------------------------------------------------------------------------
    | STEP 4: CHECK EACH ENROLLED STUDENT
    |--------------------------------------------------------------------------
    */

    while ($student = $students->fetch_assoc()) {

        $studentId = (int)$student['student_id'];

        $studentName = trim(
            $student['first_name'] . ' ' .
            ($student['middle_name']
                ? $student['middle_name'] . ' '
                : '') .
            $student['last_name']
        );

        /*
         * Check if attendance already exists.
         */

        $attendanceStmt = $conn->prepare("
            SELECT id, status
            FROM attendance
            WHERE student_id = ?
              AND schedule_id = ?
              AND class_date = ?
            LIMIT 1
        ");

        if (!$attendanceStmt) {
            $errors[] = "Attendance query error: " . $conn->error;
            continue;
        }

        $attendanceStmt->bind_param(
            "iis",
            $studentId,
            $scheduleId,
            $today
        );

        $attendanceStmt->execute();

        $attendanceResult = $attendanceStmt->get_result();
        $attendance = $attendanceResult->fetch_assoc();

        $attendanceStmt->close();

        /*
         * If there is no attendance record, create ABSENT.
         */

        if (!$attendance) {

            $remarks = 'Automatically marked absent after absence check time.';

            $insertStmt = $conn->prepare("
                INSERT INTO attendance
                    (
                        student_id,
                        schedule_id,
                        class_date,
                        status,
                        first_tap_time,
                        remarks
                    )
                VALUES (?, ?, ?, 'absent', NULL, ?)
            ");

            if (!$insertStmt) {
                $errors[] = "Attendance insert error: " . $conn->error;
                continue;
            }

            $insertStmt->bind_param(
                "iiss",
                $studentId,
                $scheduleId,
                $today,
                $remarks
            );

            if (!$insertStmt->execute()) {

                $errors[] =
                    "Unable to mark {$studentName} absent: " .
                    $insertStmt->error;

                $insertStmt->close();
                continue;
            }

            $attendanceId = (int)$conn->insert_id;

            $insertStmt->close();

            $processed++;

        } else {

            /*
             * Do not mark students with existing attendance
             * as absent.
             */

            if (
                !in_array(
                    strtolower($attendance['status']),
                    ['absent']
                )
            ) {
                continue;
            }

            $attendanceId = (int)$attendance['id'];
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 5: CHECK PARENT EMAIL
        |--------------------------------------------------------------------------
        */

        $parentEmail = trim($student['parent_email'] ?? '');

        if (
            empty($parentEmail) ||
            !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] =
                "No valid parent email for {$studentName}.";

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 6: PREPARE ABSENCE EMAIL
        |--------------------------------------------------------------------------
        */

        $emailSubject = 'Student Absence Notification';

        $emailBody = '
            <div style="
                font-family:Arial,sans-serif;
                line-height:1.7;
                color:#24352b;
            ">

                <h2 style="color:#b91c1c;">
                    Student Absence Notification
                </h2>

                <p>Dear Parent/Guardian,</p>

                <p>
                    This is to inform you that your child has not
                    recorded an NFC student ID tap for the scheduled
                    class as of the designated absence-check time.
                </p>

                <table
                    cellpadding="8"
                    cellspacing="0"
                    style="
                        border-collapse:collapse;
                        width:100%;
                        max-width:560px;
                    "
                >

                    <tr>
                        <td><strong>Student</strong></td>
                        <td>' . htmlspecialchars($studentName) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Student Number</strong></td>
                        <td>' . htmlspecialchars($student['student_number']) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Grade &amp; Section</strong></td>
                        <td>' . htmlspecialchars(
                            $student['grade_level'] . ' - ' .
                            $student['section']
                        ) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Subject</strong></td>
                        <td>' . htmlspecialchars($subjectName) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Room</strong></td>
                        <td>' . htmlspecialchars($roomName) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Date</strong></td>
                        <td>' . $now->format('F d, Y') . '</td>
                    </tr>

                    <tr>
                        <td><strong>Absence Check Time</strong></td>
                        <td>' . date(
                            'h:i A',
                            strtotime($schedule['absence_check_time'])
                        ) . '</td>
                    </tr>

                    <tr>
                        <td><strong>Attendance Status</strong></td>
                        <td style="color:#b91c1c;">
                            ABSENT
                        </td>
                    </tr>

                </table>

                <p>
                    If you believe this notification is incorrect,
                    please contact the school.
                </p>

                <p>
                    This is an automated message from the
                    NFC Security System.
                </p>

            </div>
        ';

        /*
        |--------------------------------------------------------------------------
        | STEP 7: CREATE OR CHECK EMAIL NOTIFICATION RECORD
        |--------------------------------------------------------------------------
        */

        $notificationType = 'absence';

        $notificationStmt = $conn->prepare("
            INSERT IGNORE INTO email_notifications
                (
                    attendance_id,
                    recipient_email,
                    notification_type,
                    subject_line,
                    message_body,
                    status
                )
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");

        if (!$notificationStmt) {
            $errors[] = "Notification insert error: " . $conn->error;
            continue;
        }

        $notificationStmt->bind_param(
            "issss",
            $attendanceId,
            $parentEmail,
            $notificationType,
            $emailSubject,
            $emailBody
        );

        $notificationStmt->execute();
        $notificationStmt->close();

        /*
         * Check existing notification.
         * Do not resend if already sent.
         */

        $checkNotification = $conn->prepare("
            SELECT id, status
            FROM email_notifications
            WHERE attendance_id = ?
              AND notification_type = 'absence'
            LIMIT 1
        ");

        if (!$checkNotification) {
            $errors[] = "Notification check error: " . $conn->error;
            continue;
        }

        $checkNotification->bind_param("i", $attendanceId);
        $checkNotification->execute();

        $notificationResult = $checkNotification->get_result();
        $notification = $notificationResult->fetch_assoc();

        $checkNotification->close();

        if (!$notification) {
            continue;
        }

        if ($notification['status'] === 'sent') {
            continue;
        }

        $notificationId = (int)$notification['id'];

        /*
        |--------------------------------------------------------------------------
        | STEP 8: SEND EMAIL
        |--------------------------------------------------------------------------
        */

        $updatePending = $conn->prepare("
            UPDATE email_notifications
            SET
                recipient_email = ?,
                subject_line = ?,
                message_body = ?,
                status = 'pending',
                error_message = NULL
            WHERE id = ?
        ");

        if ($updatePending) {

            $updatePending->bind_param(
                "sssi",
                $parentEmail,
                $emailSubject,
                $emailBody,
                $notificationId
            );

            $updatePending->execute();
            $updatePending->close();
        }

        if (
            sendSystemEmail(
                $parentEmail,
                $emailSubject,
                $emailBody
            )
        ) {

            $updateSent = $conn->prepare("
                UPDATE email_notifications
                SET
                    status = 'sent',
                    sent_at = NOW(),
                    error_message = NULL
                WHERE id = ?
            ");

            if ($updateSent) {

                $updateSent->bind_param("i", $notificationId);
                $updateSent->execute();
                $updateSent->close();
            }

            $emailsSent++;

        } else {

            $errorMessage = 'PHPMailer failed to send the absence notification.';

            $updateFailed = $conn->prepare("
                UPDATE email_notifications
                SET
                    status = 'failed',
                    error_message = ?
                WHERE id = ?
            ");

            if ($updateFailed) {

                $updateFailed->bind_param(
                    "si",
                    $errorMessage,
                    $notificationId
                );

                $updateFailed->execute();
                $updateFailed->close();
            }

            $errors[] = "Email failed for {$studentName}.";
        }
    }

    $studentStmt->close();
}

$scheduleStmt->close();

/*
|--------------------------------------------------------------------------
| STEP 9: DISPLAY PROCESSING RESULT
|--------------------------------------------------------------------------
*/

echo "<h2>Automatic Absence Checker</h2>";

echo "<p>Date: " . htmlspecialchars($today) . "</p>";
echo "<p>Day: " . htmlspecialchars($dayName) . "</p>";
echo "<p>Current Time: " . htmlspecialchars($now->format('h:i:s A')) . "</p>";

echo "<p>New Absent Records: " . $processed . "</p>";
echo "<p>Absence Emails Sent: " . $emailsSent . "</p>";

if (!empty($errors)) {

    echo "<h3>Errors / Warnings</h3>";

    foreach ($errors as $error) {
        echo "<p>" . htmlspecialchars($error) . "</p>";
    }

} else {

    echo "<p>Process completed successfully.</p>";
}

?>
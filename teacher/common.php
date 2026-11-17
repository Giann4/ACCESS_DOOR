<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once '../config/mail.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$teacherId = (int) $_SESSION['user_id'];
$teacherName = $_SESSION['full_name'] ?? 'Teacher';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function showTime($time): string {
    return $time ? date('h:i A', strtotime($time)) : '--';
}
function showStatus($status): string {
    $status = strtolower(trim((string)$status));
    if ($status === 'present') return 'Present';
    if ($status === 'late') return 'Late';
    if ($status === 'absent') return 'Absent';
    return 'No Tap Yet';
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}
$today = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT cs.id, cs.subject_id, cs.schedule_day, cs.start_time, cs.end_time,
           cs.late_after, cs.absence_check_time, cs.room_name,
           s.subject_code, s.subject_name, s.grade_level, s.section,
           (SELECT COUNT(*) FROM schedule_students ss WHERE ss.schedule_id=cs.id) AS enrolled_students
    FROM class_schedules cs
    INNER JOIN subjects s ON s.id=cs.subject_id
    WHERE s.teacher_id=? AND s.status='active' AND cs.status='active'
    ORDER BY FIELD(cs.schedule_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             cs.start_time
");
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$requestedScheduleId = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT);
$selectedSchedule = null;
if ($requestedScheduleId) {
    foreach ($schedules as $schedule) {
        if ((int)$schedule['id'] === (int)$requestedScheduleId) {
            $selectedSchedule = $schedule;
            break;
        }
    }
}
if (!$selectedSchedule && $schedules) $selectedSchedule = $schedules[0];
$selectedScheduleId = $selectedSchedule ? (int)$selectedSchedule['id'] : 0;

/*
 * Keep the existing automatic absence and parent-email workflow.
 * It runs when a teacher page is loaded, for the selected class/date.
 */
$isCorrectScheduleDay = false;
$absenceCheckPassed = false;

if ($selectedSchedule && $selectedDate <= $today) {
    $selectedDayName = date('l', strtotime($selectedDate));
    $absenceCheckTime = $selectedSchedule['absence_check_time'] ?? null;
    $isCorrectScheduleDay = strcasecmp($selectedDayName, (string)$selectedSchedule['schedule_day']) === 0;

    if ($absenceCheckTime) {
        $absenceCheckPassed = ($selectedDate < $today || date('H:i:s') >= $absenceCheckTime);
    }

    if ($isCorrectScheduleDay && $absenceCheckPassed) {
        $remarks = 'Automatically marked absent after absence-check time';
        $stmt = $conn->prepare("
            INSERT INTO attendance (student_id,schedule_id,class_date,status,first_tap_time,remarks)
            SELECT ss.student_id,ss.schedule_id,?,'absent',NULL,?
            FROM schedule_students ss
            INNER JOIN students st ON st.id=ss.student_id
            LEFT JOIN attendance a ON a.student_id=ss.student_id
                AND a.schedule_id=ss.schedule_id AND a.class_date=?
            WHERE ss.schedule_id=? AND st.status='active' AND a.id IS NULL
        ");
        if ($stmt) {
            $stmt->bind_param('sssi', $selectedDate, $remarks, $selectedDate, $selectedScheduleId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($isCorrectScheduleDay && $absenceCheckPassed) {
        $stmt = $conn->prepare("
            SELECT a.id AS attendance_id, st.student_number, st.first_name, st.middle_name,
                   st.last_name, st.parent_name, st.parent_email, s.subject_name,
                   s.subject_code, cs.room_name
            FROM attendance a
            INNER JOIN students st ON st.id=a.student_id
            INNER JOIN class_schedules cs ON cs.id=a.schedule_id
            INNER JOIN subjects s ON s.id=cs.subject_id
            WHERE a.schedule_id=? AND a.class_date=? AND a.status='absent'
              AND a.absence_email_sent=0 AND st.status='active' AND s.teacher_id=?
        ");
        if ($stmt) {
            $stmt->bind_param('isi', $selectedScheduleId, $selectedDate, $teacherId);
            $stmt->execute();
            $absentStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($absentStudents as $student) {
                $parentEmail = trim($student['parent_email'] ?? '');
                if (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) continue;

                $studentName = trim($student['first_name'].' '.($student['middle_name'] ?? '').' '.$student['last_name']);
                $parentName = trim($student['parent_name'] ?? '') ?: 'Parent/Guardian';
                $subject = 'Student Absence Notification';
                $body = '
                    <div style="font-family:Arial,sans-serif;line-height:1.7;color:#24352b;max-width:600px;margin:auto">
                    <h2 style="color:#dc2626">Student Absence Notification</h2>
                    <p>Dear '.e($parentName).',</p>
                    <p>Your child has been marked <strong>ABSENT</strong> because no attendance tap was recorded before the absence-check time.</p>
                    <table cellpadding="9" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #ddd">
                    <tr><td><strong>Student Name</strong></td><td>'.e($studentName).'</td></tr>
                    <tr><td><strong>Student Number</strong></td><td>'.e($student['student_number']).'</td></tr>
                    <tr><td><strong>Subject</strong></td><td>'.e($student['subject_name']).'</td></tr>
                    <tr><td><strong>Subject Code</strong></td><td>'.e($student['subject_code']).'</td></tr>
                    <tr><td><strong>Room</strong></td><td>'.e($student['room_name']).'</td></tr>
                    <tr><td><strong>Class Date</strong></td><td>'.e(date('F d, Y', strtotime($selectedDate))).'</td></tr>
                    <tr><td><strong>Status</strong></td><td style="color:#dc2626"><strong>ABSENT</strong></td></tr>
                    </table>
                    <p>If you believe this record is incorrect, please contact the school.</p>
                    <p>Automated notification from the NFC-Enabled Student ID-Based Room Access and Security System.</p>
                    </div>';

                if (sendSystemEmail($parentEmail, $subject, $body)) {
                    $update = $conn->prepare("UPDATE attendance SET absence_email_sent=1 WHERE id=? AND absence_email_sent=0");
                    if ($update) {
                        $attendanceId = (int)$student['attendance_id'];
                        $update->bind_param('i', $attendanceId);
                        $update->execute();
                        $update->close();
                    }
                }
            }
        }
    }
}

function pageUrl($page): string {
    global $selectedDate, $selectedScheduleId;
    return $page . '?schedule_id=' . (int)$selectedScheduleId . '&date=' . urlencode($selectedDate);
}
?>

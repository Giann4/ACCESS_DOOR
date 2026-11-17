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
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function displayTime($time): string
{
    if (!$time) {
        return '—';
    }
    $timestamp = strtotime((string)$time);
    return $timestamp === false ? '—' : date('h:i A', $timestamp);
}
$message = '';
$error = '';
$selectedScheduleId = (int)(
    $_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0
);

/* HANDLE FORM SUBMISSIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /* ENROLL STUDENT */
        if ($action === 'enroll') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $studentId = (int)($_POST['student_id'] ?? 0);
            $selectedScheduleId = $scheduleId;
            if ($scheduleId < 1 || $studentId < 1) {
                $error = 'Please select a schedule and a student.';
            } else {
                $checkSchedule = $conn->prepare(
                    "SELECT id FROM class_schedules WHERE id = ? LIMIT 1"
                );
                $checkStudent = $conn->prepare(
                    "SELECT id FROM students WHERE id = ? LIMIT 1"
                );
                if (!$checkSchedule || !$checkStudent) {
                    $error = 'Unable to validate the selected records: ' . $conn->error;
                } else {
                    $checkSchedule->bind_param('i', $scheduleId);
                    $checkSchedule->execute();
                    $scheduleExists = $checkSchedule->get_result()->num_rows > 0;
                    $checkStudent->bind_param('i', $studentId);
                    $checkStudent->execute();
                    $studentExists = $checkStudent->get_result()->num_rows > 0;
                    if (!$scheduleExists) {
                        $error = 'The selected schedule does not exist.';
                    } elseif (!$studentExists) {
                        $error = 'The selected student does not exist.';
                    } else {
                        $checkEnrollment = $conn->prepare(
                            "SELECT id
                             FROM schedule_students
                             WHERE schedule_id = ? AND student_id = ?
                             LIMIT 1"
                        );
                        if (!$checkEnrollment) {
                            $error = 'Unable to check existing enrollment: ' . $conn->error;
                        } else {
                            $checkEnrollment->bind_param('ii', $scheduleId, $studentId);
                            $checkEnrollment->execute();
                            if ($checkEnrollment->get_result()->num_rows > 0) {
                                $error = 'This student is already assigned to this schedule.';
                            } else {
                                $insert = $conn->prepare(
                                    "INSERT INTO schedule_students
                                        (schedule_id, student_id)
                                     VALUES (?, ?)"
                                );
                                if (!$insert) {
                                    $error = 'Unable to prepare enrollment: ' . $conn->error;
                                } else {
                                    $insert->bind_param('ii', $scheduleId, $studentId);
                                    if ($insert->execute()) {
                                        header(
                                            'Location: schedule_students.php?schedule_id=' .
                                            $scheduleId . '&success=enrolled'
                                        );
                                        exit;
                                    }
                                    $error = 'Unable to enroll student: ' . $insert->error;
                                }
                            }
                        }
                    }
                }
            }
        }

        /* REMOVE ENROLLMENT */
        elseif ($action === 'remove') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $selectedScheduleId = (int)($_POST['schedule_id'] ?? 0);
            if ($enrollmentId < 1 || $selectedScheduleId < 1) {
                $error = 'Invalid enrollment selected.';
            } else {
                $delete = $conn->prepare(
                    "DELETE FROM schedule_students
                     WHERE id = ? AND schedule_id = ?"
                );
                if (!$delete) {
                    $error = 'Unable to prepare removal: ' . $conn->error;
                } else {
                    $delete->bind_param('ii', $enrollmentId, $selectedScheduleId);
                    if ($delete->execute() && $delete->affected_rows > 0) {
                        header(
                            'Location: schedule_students.php?schedule_id=' .
                            $selectedScheduleId . '&success=removed'
                        );
                        exit;
                    }
                    $error = 'Unable to remove enrollment or enrollment was not found.';
                }
            }
        }
    }
}

/* SUCCESS MESSAGES */
if (isset($_GET['success'])) {
    $successMessages = [
        'enrolled' => 'Student assigned to schedule successfully.',
        'removed'  => 'Student removed from schedule successfully.'
    ];
    $message = $successMessages[(string)$_GET['success']] ?? '';
}

/* LOAD SCHEDULES */
$schedules = $conn->query(
    "SELECT
        cs.id,
        cs.schedule_day,
        cs.start_time,
        cs.end_time,
        cs.late_after,
        cs.absence_check_time,
        cs.room_name,
        cs.status,
        s.subject_name,
        s.subject_code,
        s.grade_level,
        s.section
     FROM class_schedules cs
     INNER JOIN subjects s ON cs.subject_id = s.id
     ORDER BY
        FIELD(
            cs.schedule_day,
            'Monday', 'Tuesday', 'Wednesday', 'Thursday',
            'Friday', 'Saturday', 'Sunday'
        ),
        cs.start_time"
);
if (!$schedules && !$error) {
    $error = 'Unable to load schedules: ' . $conn->error;
}

/* LOAD SELECTED SCHEDULE */
$selectedSchedule = null;
if ($selectedScheduleId > 0) {
    $scheduleStmt = $conn->prepare(
        "SELECT
            cs.*,
            s.subject_name,
            s.subject_code,
            s.grade_level,
            s.section
         FROM class_schedules cs
         INNER JOIN subjects s ON cs.subject_id = s.id
         WHERE cs.id = ?
         LIMIT 1"
    );
    if ($scheduleStmt) {
        $scheduleStmt->bind_param('i', $selectedScheduleId);
        $scheduleStmt->execute();
        $selectedSchedule = $scheduleStmt->get_result()->fetch_assoc();
    }
    if (!$selectedSchedule) {
        $error = $error ?: 'Selected schedule was not found.';
        $selectedScheduleId = 0;
    }
}

/* LOAD STUDENTS NOT YET ENROLLED */
$availableStudents = false;
if ($selectedScheduleId > 0) {
    $availableStmt = $conn->prepare(
        "SELECT
            st.id,
            st.student_number,
            st.first_name,
            st.last_name,
            st.middle_name,
            st.grade_level,
            st.section
         FROM students st
         WHERE NOT EXISTS (
             SELECT 1
             FROM schedule_students ss
             WHERE ss.student_id = st.id
               AND ss.schedule_id = ?
         )
         ORDER BY st.last_name, st.first_name"
    );
    if ($availableStmt) {
        $availableStmt->bind_param('i', $selectedScheduleId);
        $availableStmt->execute();
        $availableStudents = $availableStmt->get_result();
    } else {
        $error = $error ?: 'Unable to load available students: ' . $conn->error;
    }
}

/* LOAD ENROLLED STUDENTS */
$enrolledStudents = false;
if ($selectedScheduleId > 0) {
    $enrolledStmt = $conn->prepare(
        "SELECT
            ss.id AS enrollment_id,
            ss.enrolled_at,
            st.id AS student_id,
            st.student_number,
            st.first_name,
            st.last_name,
            st.middle_name,
            st.grade_level,
            st.section
         FROM schedule_students ss
         INNER JOIN students st ON ss.student_id = st.id
         WHERE ss.schedule_id = ?
         ORDER BY st.last_name, st.first_name"
    );
    if ($enrolledStmt) {
        $enrolledStmt->bind_param('i', $selectedScheduleId);
        $enrolledStmt->execute();
        $enrolledStudents = $enrolledStmt->get_result();
    } else {
        $error = $error ?: 'Unable to load enrolled students: ' . $conn->error;
    }
}
$totalSchedules = $schedules ? $schedules->num_rows : 0;
$totalEnrollments = $enrolledStudents ? $enrolledStudents->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Schedule Assignment | NFC Security System</title>
<style>
:root{--green:#20cf83;--green-dark:#087a4b;--navy:#111827;--muted:#8190a5;--line:#e5eaf0;--page:#f1f3f5;--text:#111827}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--text);font-family:Arial,sans-serif}
.main-content{margin-left:250px;padding:30px 35px;min-height:100vh}
.page-banner{height:168px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px;padding:30px;border-radius:14px;background:#111827;color:#fff;box-shadow:0 5px 15px rgba(15,23,42,.08)}
.banner-copy,.banner-action{position:relative;z-index:1}
.banner-kicker{display:none}
.page-banner h1{margin:0 0 9px;font-size:23px;font-weight:700;letter-spacing:-.3px}
.page-banner h1 .accent{color:#20cf83}
.page-banner p{margin:0;color:#e0e7ef;font-size:13px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:8px;padding:10px 15px;font-weight:700;font-size:12px;text-decoration:none;cursor:pointer;transition:background .15s}
.btn-green{background:#20cf83;color:#062b1c}
.btn-green:hover{background:#16b875}
.btn-outline{background:#fff;color:#087a4b;border:1px solid #dce8e1}
.btn-outline:hover{background:#effaf4}
.btn-danger{background:#fff0f0;border:1px solid #ffd5d5;color:#b42318}
.btn-danger:hover{background:#ffe3e3}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:18px}
.stat{display:flex;align-items:center;gap:13px;min-height:91px;padding:17px 19px;background:#fff;border:1px solid #e8edf1;border-radius:12px;box-shadow:0 4px 13px rgba(15,23,42,.035)}
.stat-icon{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;border-radius:10px;background:#e9fbf2;color:#0b8d58;font-size:17px;font-weight:800}
.stat span{display:block;color:#8090a5;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.25px}
.stat strong{display:block;margin-top:7px;color:#111827;font-size:23px;line-height:1.1}
.stat:last-child{background:#20cf83;border-color:#20cf83}
.stat:last-child .stat-icon{background:rgba(255,255,255,.25);color:#063d27}
.stat:last-child span,.stat:last-child strong{color:#062b1c}
.panel{background:#fff;border:1px solid #e7ebef;border-radius:12px;box-shadow:0 4px 14px rgba(15,23,42,.035);padding:19px;margin-bottom:17px;min-width:0}
.panel-heading{display:flex;align-items:flex-start;gap:11px;margin-bottom:15px}
.step-number{width:29px;height:29px;flex:0 0 29px;display:grid;place-items:center;border-radius:8px;background:#e9fbf2;color:#087a4b;font-size:11px;font-weight:800}
.panel h2{margin:1px 0 5px;font-size:14px;color:#111827}
.panel p{color:#8190a5;font-size:11px;line-height:1.5;margin:0}
label{display:block;margin:0 0 7px;font-size:11px;font-weight:700;color:#68788d}
input,select{width:100%;padding:11px 12px;border:1px solid #e0e7ee;border-radius:8px;background:#fff;font:inherit;font-size:12px;color:#27364a}
input:focus,select:focus{outline:0;border-color:#20cf83;box-shadow:0 0 0 3px rgba(32,207,131,.12)}
.form-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}
.notice{padding:12px 14px;border-radius:8px;margin-bottom:15px;font-size:12px;overflow-wrap:anywhere}
.success{background:#e9fbf2;color:#087a4b;border:1px solid #bcefd5}
.error{background:#fff1f1;color:#991b1b;border:1px solid #fecaca}
.schedule-info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:15px;padding:14px;background:#f7f9fb;border:1px solid #edf0f3;border-radius:9px}
.info-item{min-width:0}
.info-item span{display:block;margin-bottom:5px;color:#8190a5;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase}
.info-item strong{display:block;color:#27364a;font-size:11px;line-height:1.5;overflow-wrap:anywhere}
.pill{display:inline-flex;align-items:center;border-radius:20px;padding:5px 9px;font-size:9px;font-weight:800}
.pill.active{color:#087a4b;background:#e1f8ec}
.pill.inactive{color:#92400e;background:#fef3c7}
.assign-form{padding:14px;background:#f8fafb;border:1px solid #edf0f3;border-radius:9px}
.table-topline{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:13px}
.table-count{padding:6px 10px;border-radius:20px;background:#e9fbf2;color:#087a4b;font-size:10px;font-weight:800;white-space:nowrap}
.table-wrap{width:100%;overflow-x:auto;border:1px solid #e8edf1;border-radius:9px}
table{width:100%;border-collapse:collapse;font-size:11px;min-width:650px}
th{background:#f5f8f7;color:#315642;text-align:left;font-size:9px;letter-spacing:.5px;font-weight:800}
th,td{padding:12px 11px;border-bottom:1px solid #edf0f2;vertical-align:middle}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fbfdfc}
td small{display:block;color:#8190a5;margin-top:4px;font-size:10px}
.empty{text-align:center;padding:30px 18px;color:#8190a5}
.empty-icon{display:block;margin-bottom:7px;font-size:23px;color:#aab5c2}
.inline-form{display:inline}
.helper-note{margin-top:9px;color:#8190a5;font-size:10px;line-height:1.5}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.schedule-info{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){.main-content{margin-left:0;padding:22px 18px}}
@media(max-width:650px){.main-content{padding:15px 12px}.page-banner{height:auto;min-height:135px;padding:22px;align-items:flex-start;flex-direction:column}.panel{padding:15px}.stats,.form-row,.schedule-info{grid-template-columns:1fr}.stat{min-height:78px}.table-topline{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main-content">
    <header class="page-banner">
        <div class="banner-copy">
            <h1>Student <span class="accent">Schedule Assignment</span></h1>
            <p>Organize student enrollment by subject, class section, and schedule.</p>
        </div>
    </header>
    <?php if ($message): ?>
        <div class="notice success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice error"><?= e($error) ?></div>
    <?php endif; ?>
    <section class="stats">
        <div class="stat"><div class="stat-icon">▦</div><div><span>Available Schedules</span><strong><?= (int)$totalSchedules ?></strong></div></div>
        <div class="stat"><div class="stat-icon">♙</div><div><span>Assigned Students</span><strong><?= (int)$totalEnrollments ?></strong></div></div>
        <div class="stat"><div class="stat-icon">✓</div><div><span>Current Selection</span><strong style="font-size:16px;margin-top:9px"><?= $selectedSchedule ? 'Schedule Selected' : 'Not Selected' ?></strong></div></div>
    </section>
    <section class="panel">
        <div class="panel-heading"><div class="step-number">01</div><div><h2>Choose a Class Schedule</h2><p>Select the subject and class schedule you want to manage.</p></div></div>
        <form method="GET" action="schedule_students.php">
            <div class="form-row">
                <div>
                    <label for="schedule_id">CLASS SCHEDULE *</label>
                    <select id="schedule_id" name="schedule_id" required>
                        <option value="">-- Choose a schedule --</option>
                        <?php if ($schedules): ?>
                            <?php while ($schedule = $schedules->fetch_assoc()): ?>
                                <option
                                    value="<?= (int)$schedule['id'] ?>"
                                    <?= $selectedScheduleId === (int)$schedule['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($schedule['subject_name']) ?>
                                    — <?= e($schedule['section']) ?>
                                    — <?= e($schedule['schedule_day']) ?>
                                    (<?= e($schedule['room_name']) ?>)
                                    <?= ($schedule['status'] ?? '') !== 'active' ? '[Inactive]' : '' ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <button class="btn btn-green" type="submit">View Schedule</button>
            </div>
        </form>
        <?php if ($selectedSchedule): ?>
            <div class="schedule-info">
                <div class="info-item"><span>Subject</span><strong><?= e($selectedSchedule['subject_name']) ?> (<?= e($selectedSchedule['subject_code'] ?? '') ?>)</strong></div>
                <div class="info-item"><span>Grade / Section</span><strong><?= e($selectedSchedule['grade_level'] ?? '') ?> / <?= e($selectedSchedule['section'] ?? '') ?></strong></div>
                <div class="info-item"><span>Day</span><strong><?= e($selectedSchedule['schedule_day']) ?></strong></div>
                <div class="info-item"><span>Time</span><strong><?= e(displayTime($selectedSchedule['start_time'])) ?> – <?= e(displayTime($selectedSchedule['end_time'])) ?></strong></div>
                <div class="info-item"><span>Room</span><strong><?= e($selectedSchedule['room_name']) ?></strong></div>
                <div class="info-item"><span>Status</span><strong><span class="pill <?= e($selectedSchedule['status']) ?>"><?= e(strtoupper($selectedSchedule['status'])) ?></span></strong></div>
            </div>
        <?php endif; ?>
    </section>
    <?php if ($selectedSchedule): ?>
        <section class="panel">
            <div class="panel-heading"><div class="step-number">02</div><div><h2>Assign a Student</h2><p>Choose a student who is not yet enrolled in this schedule.</p></div></div>
            <?php if ($availableStudents && $availableStudents->num_rows > 0): ?>
                <form class="assign-form" method="POST" action="schedule_students.php">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="enroll">
                    <input type="hidden" name="schedule_id" value="<?= (int)$selectedScheduleId ?>">
                    <label for="student_id">SELECT STUDENT *</label>
                    <select id="student_id" name="student_id" required>
                        <option value="">-- Choose a student --</option>
                        <?php while ($student = $availableStudents->fetch_assoc()): ?>
                            <option value="<?= (int)$student['id'] ?>">
                                <?= e($student['student_number']) ?>
                                — <?= e($student['last_name'] . ', ' . $student['first_name']) ?>
                                <?= !empty($student['middle_name']) ? ' ' . e($student['middle_name']) : '' ?>
                                (<?= e($student['grade_level']) ?> / <?= e($student['section']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <button class="btn btn-green" style="margin-top:16px" type="submit">＋ Assign Student</button>
                    <div class="helper-note">Only students who are not yet assigned to this schedule are shown.</div>
                </form>
            <?php else: ?>
                <div class="notice success">
                    All existing students are already assigned to this schedule,
                    or there are no students available.
                </div>
            <?php endif; ?>
        </section>
        <section class="panel">
            <div class="table-topline">
                <div class="panel-heading" style="margin:0"><div class="step-number">03</div><div><h2>Enrolled Students</h2><p>Students currently assigned to <?= e($selectedSchedule['subject_name']) ?>.</p></div></div>
                <span class="table-count"><?= (int)$totalEnrollments ?> STUDENT<?= $totalEnrollments === 1 ? '' : 'S' ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>STUDENT NUMBER</th>
                            <th>STUDENT NAME</th>
                            <th>GRADE / SECTION</th>
                            <th>ENROLLED AT</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($enrolledStudents && $enrolledStudents->num_rows > 0): ?>
                        <?php while ($student = $enrolledStudents->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($student['student_number']) ?></td>
                                <td>
                                    <strong>
                                        <?= e($student['last_name'] . ', ' . $student['first_name']) ?>
                                        <?= !empty($student['middle_name']) ? ' ' . e($student['middle_name']) : '' ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= e($student['grade_level']) ?>
                                    / <?= e($student['section']) ?>
                                </td>
                                <td>
                                    <?= e(date('M d, Y h:i A', strtotime($student['enrolled_at']))) ?>
                                </td>
                                <td>
                                    <form
                                        class="inline-form"
                                        method="POST"
                                        action="schedule_students.php"
                                        onsubmit="return confirm('Remove this student from the selected schedule?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="schedule_id" value="<?= (int)$selectedScheduleId ?>">
                                        <input type="hidden" name="enrollment_id" value="<?= (int)$student['enrollment_id'] ?>">
                                        <button class="btn btn-danger" type="submit">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty">
                                <span class="empty-icon">♙</span>No students are assigned to this schedule yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-heading"><div class="step-number">＋</div><div><h2>Student Assignments</h2><p>Choose a schedule above to view enrolled students and assign new students. Your enrollment records will appear here once a schedule is selected.</p></div></div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>

<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}
$adminName = $_SESSION['full_name'] ?? 'Admin';
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function displayTime($time): string {
    return $time ? date('h:i A', strtotime($time)) : '--';
}

function displayDateTime($datetime): string {
    return $datetime ? date('M d, Y h:i A', strtotime($datetime)) : '--';
}

function validDate($date): bool {
    if (!is_string($date)) return false;
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

/* =========================================
   FILTERS
========================================= */

$today = date('Y-m-d');
$startDate = $_GET['start_date'] ?? $today;
$endDate = $_GET['end_date'] ?? $today;
if (!validDate($startDate)) $startDate = $today;
if (!validDate($endDate)) $endDate = $today;
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
$subjectId = isset($_GET['subject_id'])
    ? filter_var($_GET['subject_id'], FILTER_VALIDATE_INT)
    : null;
if (!$subjectId || $subjectId < 1) {
    $subjectId = null;
}
$attendanceStatus = strtolower(trim($_GET['status'] ?? ''));
$allowedStatuses = ['', 'present', 'late', 'absent'];
if (!in_array($attendanceStatus, $allowedStatuses, true)) {
    $attendanceStatus = '';
}

/* =========================================
   LOAD SUBJECTS
========================================= */

$subjects = [];
$result = $conn->query("
    SELECT id, subject_code, subject_name, grade_level, section
    FROM subjects
    ORDER BY subject_name
");
if ($result) {
    $subjects = $result->fetch_all(MYSQLI_ASSOC);
}

/* =========================================
   ATTENDANCE SUMMARY
========================================= */

$summary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0
];
$sqlSummary = "
    SELECT LOWER(a.status) AS attendance_status,
           COUNT(*) AS total
    FROM attendance a
    WHERE a.class_date BETWEEN ? AND ?
";
$summaryTypes = 'ss';
$summaryParams = [$startDate, $endDate];
if ($subjectId) {
    $sqlSummary .= "
        AND a.schedule_id IN (
            SELECT cs.id
            FROM class_schedules cs
            WHERE cs.subject_id = ?
        )
    ";
    $summaryTypes .= 'i';
    $summaryParams[] = $subjectId;
}
$sqlSummary .= " GROUP BY LOWER(a.status)";
$stmt = $conn->prepare($sqlSummary);
if ($stmt) {
    $stmt->bind_param($summaryTypes, ...$summaryParams);
    $stmt->execute();
    $summaryResult = $stmt->get_result();
    while ($row = $summaryResult->fetch_assoc()) {
        $status = strtolower($row['attendance_status']);
        if (isset($summary[$status])) {
            $summary[$status] = (int)$row['total'];
        }
    }
    $stmt->close();
}

/* =========================================
   ATTENDANCE RECORDS
========================================= */

$sqlAttendance = "
    SELECT
        a.id AS attendance_id,
        a.class_date,
        a.first_tap_time,
        a.status AS attendance_status,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name,
        cs.schedule_day,
        cs.start_time,
        cs.end_time,
        cs.room_name,
        s.subject_code,
        s.subject_name,
        s.grade_level,
        s.section
    FROM attendance a
    INNER JOIN students st ON st.id = a.student_id
    INNER JOIN class_schedules cs ON cs.id = a.schedule_id
    INNER JOIN subjects s ON s.id = cs.subject_id
    WHERE a.class_date BETWEEN ? AND ?
";
$attendanceTypes = 'ss';
$attendanceParams = [$startDate, $endDate];
if ($subjectId) {
    $sqlAttendance .= " AND cs.subject_id = ?";
    $attendanceTypes .= 'i';
    $attendanceParams[] = $subjectId;
}
if ($attendanceStatus !== '') {
    $sqlAttendance .= " AND LOWER(a.status) = ?";
    $attendanceTypes .= 's';
    $attendanceParams[] = $attendanceStatus;
}
$sqlAttendance .= "
    ORDER BY a.class_date DESC,
             s.subject_name,
             st.last_name,
             st.first_name
";
$attendanceRows = [];
$stmt = $conn->prepare($sqlAttendance);
if ($stmt) {
    $stmt->bind_param($attendanceTypes, ...$attendanceParams);
    $stmt->execute();
    $attendanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$totalRecords = count($attendanceRows);

/* =========================================
   RECENT NFC ACCESS LOGS
========================================= */

$accessLogs = [];
$accessLogError = false;
$accessQuery = $conn->query("
    SELECT
        al.id,
        al.student_id,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name,
        al.card_uid,
        al.room_name,
        al.tap_time,
        al.access_result,
        al.remarks
    FROM access_logs al
    LEFT JOIN students st ON st.id = al.student_id
    ORDER BY al.tap_time DESC, al.id DESC
    LIMIT 200
");
if ($accessQuery) {
    $accessLogs = $accessQuery->fetch_all(MYSQLI_ASSOC);
} else {
    $accessLogError = true;
}

/* =========================================
   CSV EXPORT
========================================= */

if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    if ($exportType === 'attendance') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=attendance_report_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Date',
            'Student Number',
            'Student Name',
            'Subject',
            'Subject Code',
            'Grade Level',
            'Section',
            'Schedule',
            'Room',
            'First Tap',
            'Status'
        ]);
        foreach ($attendanceRows as $row) {
            $studentName = trim(
                $row['first_name'] . ' ' .
                ($row['middle_name'] ?? '') . ' ' .
                $row['last_name']
            );
            fputcsv($output, [
                $row['class_date'],
                $row['student_number'],
                $studentName,
                $row['subject_name'],
                $row['subject_code'],
                $row['grade_level'],
                $row['section'],
                $row['schedule_day'] . ' ' .
                    displayTime($row['start_time']) . ' - ' .
                    displayTime($row['end_time']),
                $row['room_name'],
                $row['first_tap_time'],
                $row['attendance_status']
            ]);
        }
        fclose($output);
        exit;
    }
    if ($exportType === 'logs') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=nfc_access_logs_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Student Number',
            'Student Name',
            'Card UID',
            'Room',
            'Tap Date and Time',
            'Access Result',
            'Remarks'
        ]);
        foreach ($accessLogs as $log) {
            $studentName = trim(
                ($log['first_name'] ?? '') . ' ' .
                ($log['middle_name'] ?? '') . ' ' .
                ($log['last_name'] ?? '')
            );
            fputcsv($output, [
                $log['student_number'] ?? '--',
                $studentName ?: 'Unregistered',
                $log['card_uid'],
                $log['room_name'],
                $log['tap_time'],
                $log['access_result'],
                $log['remarks']
            ]);
        }
        fclose($output);
        exit;
    }
}

/* =========================================
   SUMMARY TOTALS
========================================= */

$totalAttendance = array_sum($summary);
$presentPercent = $totalAttendance
    ? round(($summary['present'] / $totalAttendance) * 100)
    : 0;
$latePercent = $totalAttendance
    ? round(($summary['late'] / $totalAttendance) * 100)
    : 0;
$absentPercent = $totalAttendance
    ? round(($summary['absent'] / $totalAttendance) * 100)
    : 0;
$activePage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | NFC Security System</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    min-height: 100vh;
    background: #f0f2f5;
    color: #142033;
    font-family: Inter, "Segoe UI", Arial, sans-serif;
}
/* MAIN CONTENT */
.main-content {
    margin-left: 250px;
    padding: 30px 35px;
    min-height: 100vh;
}
/* WELCOME HEADER */
.welcome-card {
    background: #111827;
    color: #fff;
    height: 168px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 30px;
    border-radius: 14px;
    margin-bottom: 20px;
}
.welcome-card h1 {
    font-size: 23px;
    font-weight: 900;
    margin-bottom: 7px;
}
.welcome-card h1 span {
    color: #20d38a;
}
.welcome-card p {
    font-size: 12px;
    color: #d5deea;
}
/* PANELS */
.panel {
    background: #fff;
    border-radius: 13px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 5px 18px rgba(20, 32, 51, 0.045);
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}
.panel-heading h3 {
    font-size: 16px;
    color: #101c30;
    font-weight: 800;
    margin-bottom: 6px;
}
.panel-heading p {
    color: #8796aa;
    font-size: 10px;
    line-height: 1.5;
}
/* FILTERS */
.filters {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 13px;
}
.filters label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #73839a;
    margin-bottom: 8px;
}
.filters input,
.filters select {
    width: 100%;
    height: 43px;
    border: 1px solid #dfe5ed;
    border-radius: 8px;
    padding: 0 12px;
    outline: none;
    background: #fff;
    color: #26354a;
    font-size: 12px;
}
.filters input:focus,
.filters select:focus {
    border-color: #20d38a;
    box-shadow: 0 0 0 3px rgba(32, 211, 138, .10);
}
.filter-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
}
/* BUTTONS */
.button {
    min-height: 39px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    border: 1px solid #20d38a;
    border-radius: 8px;
    background: #20d38a;
    color: #092d20;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}
.button:hover {
    background: #13bd78;
}
.button.secondary {
    background: #fff;
    color: #27354a;
    border-color: #dfe5ed;
}
.button.light {
    background: #e9fbf2;
    color: #078c53;
    border-color: #c8f2dc;
}
.button.dark {
    background: #111827;
    border-color: #111827;
    color: #fff;
}
/* STAT CARDS */
.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    min-height: 90px;
    box-shadow: 0 5px 18px rgba(20, 32, 51, .045);
}
.stat-card span {
    display: block;
    color: #8291a7;
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 800;
    margin-bottom: 10px;
}
.stat-card strong {
    font-size: 25px;
    color: #111827;
}
.stat-card.present {
    background: #e4f8ef;
}
.stat-card.present strong {
    color: #079b60;
}
.stat-card.late {
    background: #fff5df;
}
.stat-card.late strong {
    color: #d98a0b;
}
.stat-card.absent {
    background: #ffeded;
}
.stat-card.absent strong {
    color: #ef5757;
}
/* SUMMARY PROGRESS */
.summary-list {
    display: flex;
    flex-direction: column;
    gap: 17px;
}
.summary-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: #73839a;
    margin-bottom: 8px;
}
.summary-top strong {
    color: #142033;
    font-size: 11px;
}
.progress {
    height: 7px;
    background: #edf0f3;
    border-radius: 20px;
    overflow: hidden;
}
.progress-bar {
    height: 100%;
    border-radius: 20px;
}
.progress-bar.green {
    background: #20d38a;
}
.progress-bar.orange {
    background: #f5ae3d;
}
.progress-bar.red {
    background: #f35c62;
}
/* REPORT NOTE */
.report-note {
    padding: 13px 15px;
    border-left: 4px solid #20d38a;
    border-radius: 6px;
    background: #effaf4;
    color: #39745a;
    font-size: 11px;
    line-height: 1.6;
    margin-bottom: 16px;
}
/* TABLE */
.table-wrap {
    width: 100%;
    overflow-x: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}
thead {
    background: #f2f5f8;
}
th {
    padding: 13px 11px;
    color: #63758e;
    text-align: left;
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
    white-space: nowrap;
}
td {
    padding: 15px 11px;
    border-bottom: 1px solid #e8edf2;
    font-size: 11px;
    color: #26354a;
    vertical-align: middle;
}
tbody tr:hover {
    background: #f5fbf8;
}
td strong {
    color: #101c30;
}
td small {
    display: block;
    color: #77879c;
    font-size: 9px;
    margin-top: 4px;
    line-height: 1.5;
}
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: 9px;
    font-weight: 900;
    white-space: nowrap;
}
.badge.present,
.badge.granted {
    color: #078c53;
    background: #e2f8ed;
}
.badge.late {
    color: #bd7900;
    background: #fff3d9;
}
.badge.absent,
.badge.denied {
    color: #df4545;
    background: #ffebeb;
}
.badge.unregistered {
    color: #64748b;
    background: #edf0f4;
}
.empty {
    text-align: center;
    padding: 30px 15px;
    color: #8996a8;
    font-size: 11px;
}
/* FOOTER */
.footer {
    padding: 15px 0 5px;
    text-align: center;
    color: #8a98aa;
    font-size: 10px;
    line-height: 1.8;
}
/* RESPONSIVE */
@media (max-width: 1100px) {
    .filters {
        grid-template-columns: repeat(2, 1fr);
    }
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 700px) {
    
    
    
    
    
    
    .main-content {
        margin-left: 68px;
        padding: 16px 12px;
    }
    .welcome-card {
        padding: 22px;
    }
    .welcome-card h1 {
        font-size: 19px;
    }
    .filters {
        grid-template-columns: 1fr;
    }
    .stats {
        grid-template-columns: 1fr 1fr;
        gap: 9px;
    }
    .stat-card {
        padding: 15px;
    }
    .panel {
        padding: 14px;
    }
    .panel-heading {
        align-items: flex-start;
        flex-direction: column;
    }
}
/* PRINT */
@media print {
    body {
        background: #fff;
    }
    .sidebar,
    .no-print,
    .filter-actions,
    .button {
        display: none !important;
    }
    .main-content {
        margin: 0;
        padding: 0;
    }
    .panel,
    .stat-card {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }
    .welcome-card {
        background: #111827 !important;
        color: white !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    table {
        min-width: 0;
    }
    th, td {
        font-size: 9px;
        padding: 8px 5px;
    }
}

@media (max-width: 520px) {
    .main-content { margin-left: 68px !important; padding: 16px 12px; }
}

</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<!-- MAIN CONTENT -->
<main class="main-content">
    <!-- HEADER -->
    <section class="welcome-card">
        <h1>System <span>Reports</span></h1>
        <p>Monitor student attendance records and NFC room access transactions.</p>
    </section>
    <!-- FILTERS -->
    <section class="panel no-print">
        <div class="panel-heading">
            <div>
                <h3>Filter Reports</h3>
                <p>Choose a date range, subject, and attendance status.</p>
            </div>
        </div>
        <form method="GET" action="reports.php">
            <div class="filters">
                <div>
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?= e($startDate) ?>" required>
                </div>
                <div>
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?= e($endDate) ?>" required>
                </div>
                <div>
                    <label for="subject_id">Subject</label>
                    <select id="subject_id" name="subject_id">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= (int)$subject['id'] ?>"
                                <?= ($subjectId !== null && (int)$subjectId === (int)$subject['id']) ? 'selected' : '' ?>>
                                <?= e($subject['subject_name']) ?> (<?= e($subject['subject_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status">Attendance Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="present" <?= $attendanceStatus === 'present' ? 'selected' : '' ?>>Present</option>
                        <option value="late" <?= $attendanceStatus === 'late' ? 'selected' : '' ?>>Late</option>
                        <option value="absent" <?= $attendanceStatus === 'absent' ? 'selected' : '' ?>>Absent</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a href="reports.php" class="button secondary">Reset</a>
            </div>
        </form>
    </section>
    <!-- SUMMARY CARDS -->
    <section class="stats">
        <div class="stat-card">
            <span>Total Records</span>
            <strong><?= number_format($totalRecords) ?></strong>
        </div>
        <div class="stat-card present">
            <span>Present</span>
            <strong><?= number_format($summary['present']) ?></strong>
        </div>
        <div class="stat-card late">
            <span>Late</span>
            <strong><?= number_format($summary['late']) ?></strong>
        </div>
        <div class="stat-card absent">
            <span>Absent</span>
            <strong><?= number_format($summary['absent']) ?></strong>
        </div>
    </section>
    <!-- ATTENDANCE SUMMARY -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h3>Attendance Summary</h3>
                <p>Attendance distribution based on the selected filters.</p>
            </div>
        </div>
        <div class="summary-list">
            <div class="summary-item">
                <div class="summary-top">
                    <span>Present</span>
                    <strong><?= number_format($summary['present']) ?> (<?= $presentPercent ?>%)</strong>
                </div>
                <div class="progress">
                    <div class="progress-bar green" style="width:<?= $presentPercent ?>%"></div>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-top">
                    <span>Late</span>
                    <strong><?= number_format($summary['late']) ?> (<?= $latePercent ?>%)</strong>
                </div>
                <div class="progress">
                    <div class="progress-bar orange" style="width:<?= $latePercent ?>%"></div>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-top">
                    <span>Absent</span>
                    <strong><?= number_format($summary['absent']) ?> (<?= $absentPercent ?>%)</strong>
                </div>
                <div class="progress">
                    <div class="progress-bar red" style="width:<?= $absentPercent ?>%"></div>
                </div>
            </div>
        </div>
    </section>
    <!-- STUDENT ATTENDANCE RECORDS -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h3>Student Attendance Records</h3>
                <p>Attendance history from all matching class records.</p>
                <p style="margin-top:8px;">Records found: <?= number_format($totalRecords) ?></p>
            </div>
            <div class="filter-actions no-print">
                <a class="button light"
                   href="reports.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'attendance']))) ?>">
                    ↓ Export CSV
                </a>
                <button type="button" class="button dark" onclick="window.print()">
                    Print
                </button>
            </div>
        </div>
        <div class="report-note">
            This report displays saved attendance records matching your selected filters.
            Students without attendance records are not included in this table.
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Student Number</th>
                        <th>Student Name</th>
                        <th>Subject / Section</th>
                        <th>Class Date</th>
                        <th>Schedule</th>
                        <th>Room</th>
                        <th>First Tap Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($attendanceRows): ?>
                    <?php foreach ($attendanceRows as $index => $row): ?>
                        <?php
                        $studentName = trim(
                            $row['first_name'] . ' ' .
                            ($row['middle_name'] ?? '') . ' ' .
                            $row['last_name']
                        );
                        $status = strtolower(trim($row['attendance_status']));
                        $badgeClass = in_array($status, ['present', 'late', 'absent'], true)
                            ? $status : '';
                        ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong><?= e($row['student_number']) ?></strong></td>
                            <td><?= e($studentName) ?></td>
                            <td>
                                <strong><?= e($row['subject_name']) ?></strong>
                                <small>
                                    <?= e($row['subject_code']) ?>
                                    · Grade <?= e($row['grade_level']) ?>
                                    / <?= e($row['section']) ?>
                                </small>
                            </td>
                            <td><?= e(date('M d, Y', strtotime($row['class_date']))) ?></td>
                            <td>
                                <?= e($row['schedule_day']) ?>
                                <small>
                                    <?= e(displayTime($row['start_time'])) ?>
                                    - <?= e(displayTime($row['end_time'])) ?>
                                </small>
                            </td>
                            <td><?= e($row['room_name']) ?></td>
                            <td><?= e(displayTime($row['first_tap_time'])) ?></td>
                            <td>
                                <span class="badge <?= e($badgeClass) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty">
                            No attendance records were found for the selected filters.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <!-- RECENT NFC TAP ACTIVITY -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h3>Recent NFC Tap Activity</h3>
                <p>Latest recorded NFC access transactions from the access_logs table.</p>
                <p style="margin-top:8px;">
                    Tap logs found: <?= number_format(count($accessLogs)) ?>
                    <?php if (!$accessLogError): ?>
                        <small>(Latest 200 records)</small>
                    <?php endif; ?>
                </p>
            </div>
            <div class="filter-actions no-print">
                <a class="button light" href="reports.php?export=logs">
                    ↓ Export CSV
                </a>
                <button type="button" class="button dark" onclick="window.print()">
                    Print
                </button>
            </div>
        </div>
        <?php if ($accessLogError): ?>
            <div class="report-note">
                Unable to load NFC access logs. Please check your access_logs table.
            </div>
        <?php elseif ($accessLogs): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Student Name</th>
                            <th>Card UID</th>
                            <th>Room</th>
                            <th>Tap Date &amp; Time</th>
                            <th>Access Result</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accessLogs as $log): ?>
                        <?php
                        $studentName = trim(
                            ($log['first_name'] ?? '') . ' ' .
                            ($log['middle_name'] ?? '') . ' ' .
                            ($log['last_name'] ?? '')
                        );
                        $accessResult = strtolower(trim($log['access_result']));
                        if ($accessResult === 'granted') {
                            $accessClass = 'granted';
                        } elseif ($accessResult === 'denied') {
                            $accessClass = 'denied';
                        } else {
                            $accessClass = 'unregistered';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($log['student_number'] ?? '--') ?></strong>
                            </td>
                            <td><?= e($studentName ?: 'Unregistered') ?></td>
                            <td><?= e($log['card_uid']) ?></td>
                            <td><?= e($log['room_name']) ?></td>
                            <td><?= e(displayDateTime($log['tap_time'])) ?></td>
                            <td>
                                <span class="badge <?= e($accessClass) ?>">
                                    <?= e(ucfirst($accessResult)) ?>
                                </span>
                            </td>
                            <td><?= e($log['remarks'] ?? '--') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">
                No NFC access log records are available yet.
            </div>
        <?php endif; ?>
    </section>
    <!-- FOOTER -->
    <footer class="footer">
        NFC-Enabled Student ID-Based Room Access and Security System<br>
        Southern Philippines Institute of Science and Technology (CARBAG)
    </footer>
</main>
</body>
</html>


<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';

/* AUTHENTICATION */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$adminName = $_SESSION['full_name'] ?? 'Admin';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function showDate($date): string {
    return $date ? date('M d, Y', strtotime($date)) : '--';
}

function showTime($time): string {
    return $time ? date('h:i:s A', strtotime($time)) : '--';
}

/* FILTERS */
$search = trim($_GET['search'] ?? '');
$date = trim($_GET['date'] ?? '');
$status = strtolower(trim($_GET['status'] ?? ''));

$allowedStatuses = ['present', 'late', 'absent'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
if ($date !== '' && (!$dateObject || $dateObject->format('Y-m-d') !== $date)) {
    $date = '';
}

/* ATTENDANCE QUERY */
$records = [];
$attendanceError = '';

$sql = "
    SELECT
        a.id,
        st.student_number,
        CONCAT(
            st.first_name, ' ',
            COALESCE(CONCAT(st.middle_name, ' '), ''),
            st.last_name
        ) AS student_name,
        sub.subject_code,
        sub.subject_name,
        sub.section,
        a.class_date,
        a.first_tap_time,
        a.status
    FROM attendance a
    INNER JOIN students st ON st.id = a.student_id
    INNER JOIN class_schedules cs ON cs.id = a.schedule_id
    INNER JOIN subjects sub ON sub.id = cs.subject_id
    WHERE 1=1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            st.student_number LIKE ?
            OR st.first_name LIKE ?
            OR st.middle_name LIKE ?
            OR st.last_name LIKE ?
            OR sub.subject_code LIKE ?
            OR sub.subject_name LIKE ?
            OR sub.section LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $params[] = $term;
        $types .= 's';
    }
}

if ($date !== '') {
    $sql .= " AND a.class_date = ? ";
    $params[] = $date;
    $types .= 's';
}

if ($status !== '') {
    $sql .= " AND a.status = ? ";
    $params[] = $status;
    $types .= 's';
}

$sql .= " ORDER BY a.class_date DESC, a.first_tap_time DESC, a.id DESC";

try {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    $stmt->close();

} catch (Throwable $ex) {
    error_log('Admin attendance error: ' . $ex->getMessage());
    $attendanceError = 'Unable to load attendance records. Please check the database.';
}

/* ATTENDANCE COUNTS */
$presentCount = 0;
$lateCount = 0;
$absentCount = 0;

foreach ($records as $row) {
    $recordStatus = strtolower($row['status'] ?? '');

    if ($recordStatus === 'present') {
        $presentCount++;
    } elseif ($recordStatus === 'late') {
        $lateCount++;
    } elseif ($recordStatus === 'absent') {
        $absentCount++;
    }
}

$totalRecords = count($records);

/* NFC TAP LOGS */
$tapLogs = [];
$tapLogError = '';

$logSql = "
    SELECT
        al.id,
        al.student_id,
        st.student_number,
        CONCAT(
            st.first_name, ' ',
            COALESCE(CONCAT(st.middle_name, ' '), ''),
            st.last_name
        ) AS student_name,
        al.card_uid,
        al.room_name,
        al.tap_time,
        al.access_result,
        al.remarks
    FROM access_logs al
    LEFT JOIN students st ON st.id = al.student_id
    WHERE 1=1
";

$logParams = [];
$logTypes = '';

if ($search !== '') {
    $logSql .= "
        AND (
            st.student_number LIKE ?
            OR st.first_name LIKE ?
            OR st.last_name LIKE ?
            OR al.card_uid LIKE ?
            OR al.room_name LIKE ?
            OR al.access_result LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $logParams[] = $term;
        $logTypes .= 's';
    }
}

if ($date !== '') {
    $logSql .= " AND DATE(al.tap_time) = ? ";
    $logParams[] = $date;
    $logTypes .= 's';
}

$logSql .= " ORDER BY al.tap_time DESC, al.id DESC LIMIT 200";

try {
    $logStmt = $conn->prepare($logSql);

    if (!$logStmt) {
        throw new Exception($conn->error);
    }

    if ($logParams) {
        $logStmt->bind_param($logTypes, ...$logParams);
    }

    $logStmt->execute();
    $logResult = $logStmt->get_result();

    while ($row = $logResult->fetch_assoc()) {
        $tapLogs[] = $row;
    }

    $logStmt->close();

} catch (Throwable $ex) {
    error_log('Admin tap logs error: ' . $ex->getMessage());
    $tapLogError = 'Unable to load NFC tap logs. Please check the access_logs table.';
}

/* CSV EXPORT */
$export = $_GET['export'] ?? '';

if ($export === 'attendance') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_records_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Student Number',
        'Student Name',
        'Subject Code',
        'Subject Name',
        'Section',
        'Class Date',
        'First Tap Time',
        'Status'
    ]);

    foreach ($records as $row) {
        fputcsv($output, [
            $row['student_number'],
            $row['student_name'],
            $row['subject_code'],
            $row['subject_name'],
            $row['section'],
            $row['class_date'],
            $row['first_tap_time'],
            $row['status']
        ]);
    }

    fclose($output);
    exit;
}

if ($export === 'logs') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=nfc_tap_logs_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Student Number',
        'Student Name',
        'Card UID',
        'Room',
        'Tap Time',
        'Access Result',
        'Remarks'
    ]);

    foreach ($tapLogs as $log) {
        fputcsv($output, [
            $log['student_number'] ?? 'N/A',
            $log['student_name'] ?? 'Unknown student',
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

$queryString = http_build_query([
    'search' => $search,
    'date' => $date,
    'status' => $status
]);

$adminInitial = strtoupper(substr($adminName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Attendance & Tap Logs | NFC Security</title>

<style>

:root {
    --navy: #111827;
    --navy-light: #1c2739;
    --green: #28d889;
    --green-dark: #16a968;
    --page: #f1f3f6;
    --white: #ffffff;
    --text: #202b3b;
    --muted: #8b98aa;
    --border: #e7eaf0;
    --red: #ef6262;
    --yellow: #f3b94f;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Inter, "Segoe UI", Arial, sans-serif;
    background: var(--page);
    color: var(--text);
}

/* MAIN CONTENT */

.page-content {
    margin-left: 250px;
    padding: 30px 35px;
    min-height: 100vh;
}

/* PAGE HEADER */

.page-header {
    height: 168px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: flex-start;
    background: var(--navy);
    color: white;
    border-radius: 14px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 5px 18px rgba(17,24,39,.08);
}

.page-header h1 {
    margin: 0 0 8px;
    font-size: 23px;
}

.page-header h1 span {
    color: var(--green);
}

.page-header p {
    margin: 0;
    color: #c0c8d5;
    font-size: 13px;
}

/* FILTERS */

.filter-panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 19px;
    margin-bottom: 20px;
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 13px;
    box-shadow: 0 4px 15px rgba(17,24,39,.04);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.filter-group label {
    font-size: 11px;
    color: var(--muted);
    font-weight: 700;
}

.filter-group input,
.filter-group select {
    min-width: 180px;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline: none;
    font-family: inherit;
    font-size: 13px;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: var(--green);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: white;
    color: var(--text);
    text-decoration: none;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
}

.btn-primary {
    background: var(--green);
    border-color: var(--green);
    color: var(--navy);
}

.btn-primary:hover {
    background: var(--green-dark);
    color: white;
}

.btn-dark {
    background: var(--navy);
    border-color: var(--navy);
    color: white;
}

.btn-dark:hover {
    background: var(--navy-light);
}

.btn-export {
    background: #e7f9ef;
    color: #16834f;
    border-color: #c8f0d9;
}

/* STATISTICS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(17,24,39,.04);
}

.stat-card small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 10px;
}

.stat-card strong {
    font-size: 27px;
    color: var(--navy);
}

.stat-card.present strong {
    color: var(--green-dark);
}

.stat-card.late strong {
    color: #d18a17;
}

.stat-card.absent strong {
    color: var(--red);
}

/* PANELS */

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 23px;
    margin-bottom: 22px;
    box-shadow: 0 5px 18px rgba(17,24,39,.04);
}

.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.panel-heading h2 {
    margin: 0;
    font-size: 17px;
    color: var(--navy);
}

.panel-heading p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 11px;
}

.panel-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.record-count {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 13px;
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

thead th {
    background: #f3f6f9;
    color: #66758a;
    padding: 14px 12px;
    text-align: left;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    color: var(--text);
}

tbody tr:hover {
    background: #f5fcf8;
}

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    text-transform: capitalize;
}

.present, .granted {
    background: #e5f9ef;
    color: #16834f;
}

.late {
    background: #fff5df;
    color: #b7791f;
}

.absent, .denied {
    background: #ffeded;
    color: #dc4545;
}

.unregistered {
    background: #edf0f4;
    color: #778397;
}

.empty {
    text-align: center;
    padding: 35px 15px;
    color: var(--muted);
    font-size: 13px;
}

.notice-error {
    padding: 13px 16px;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 12px;
}

.footer {
    text-align: center;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.7;
    padding: 18px;
}

/* RESPONSIVE */

@media(max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 768px) {
    .page-content {
        margin-left: 210px;
        padding: 20px 16px;
    }

    .filter-panel {
        align-items: stretch;
    }

    .filter-group,
    .filter-group input,
    .filter-group select {
        width: 100%;
    }
}

@media(max-width: 520px) {
    .brand-name,
    .menu-label,
    .sidebar > a span:not(:first-child),
    .profile-info,
    .logout span:not(:first-child) {
        display: none;
    }

    .sidebar > a {
        justify-content: center;
        padding: 14px 5px;
    }

    .profile {
        justify-content: center;
    }

    .page-content {
        margin-left: 68px;
        padding: 16px 12px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        padding: 20px;
    }

    .page-header h1 {
        font-size: 20px;
    }
}

/* PRINT */

@media print {
    .sidebar,
    .filter-panel,
    .panel-actions,
    .no-print {
        display: none !important;
    }

    body {
        background: white;
    }

    .page-content {
        margin: 0;
        padding: 10px;
    }

    .page-header {
        background: white;
        color: black;
        box-shadow: none;
        border: 1px solid #ddd;
    }

    .page-header h1 span,
    .page-header p {
        color: black;
    }

    .panel {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }

    table {
        min-width: 100%;
    }

    thead th {
        background: #eee !important;
        color: black;
    }

    tbody td {
        color: black;
    }
}

</style>
</head>

<body>

<!-- SIDEBAR -->
<?php require_once __DIR__ . '/sidebar.php'; ?>

<!-- MAIN CONTENT -->
<main class="page-content">

    <section class="page-header">
        <h1>Attendance <span>& Tap Logs</span></h1>
        <p>Monitor school-wide student attendance and NFC card activity.</p>
    </section>

    <!-- FILTERS -->
    <form method="GET" action="attendance.php" class="filter-panel">

        <div class="filter-group">
            <label>Search Records</label>
            <input
                type="search"
                name="search"
                placeholder="Student, subject, section, or card UID"
                value="<?= e($search) ?>"
            >
        </div>

        <div class="filter-group">
            <label>Select Date</label>
            <input
                type="date"
                name="date"
                value="<?= e($date) ?>"
            >
        </div>

        <div class="filter-group">
            <label>Attendance Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="present" <?= $status === 'present' ? 'selected' : '' ?>>Present</option>
                <option value="late" <?= $status === 'late' ? 'selected' : '' ?>>Late</option>
                <option value="absent" <?= $status === 'absent' ? 'selected' : '' ?>>Absent</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Apply Filter</button>

        <a href="attendance.php" class="btn">Reset</a>

    </form>

    <!-- STATISTICS -->
    <section class="stats-grid">

        <div class="stat-card">
            <small>TOTAL RECORDS</small>
            <strong><?= $totalRecords ?></strong>
        </div>

        <div class="stat-card present">
            <small>PRESENT</small>
            <strong><?= $presentCount ?></strong>
        </div>

        <div class="stat-card late">
            <small>LATE</small>
            <strong><?= $lateCount ?></strong>
        </div>

        <div class="stat-card absent">
            <small>ABSENT</small>
            <strong><?= $absentCount ?></strong>
        </div>

    </section>

    <!-- ATTENDANCE RECORDS -->
    <section class="panel">

        <div class="panel-heading">
            <div>
                <h2>Student Attendance Records</h2>
                <p>Attendance history from all active and inactive class records.</p>
            </div>

            <div class="panel-actions">
                <a
                    href="attendance.php?<?= e($queryString) ?>&export=attendance"
                    class="btn btn-export"
                >↓ Export CSV</a>

                <button type="button" onclick="printSection('attendancePanel')" class="btn btn-dark">
                    Print
                </button>
            </div>
        </div>

        <?php if ($attendanceError !== ''): ?>
            <div class="notice-error"><?= e($attendanceError) ?></div>
        <?php endif; ?>

        <div class="record-count">
            Records found: <?= $totalRecords ?>
        </div>

        <div class="table-wrap" id="attendancePanel">

            <table>
                <thead>
                    <tr>
                        <th>NO.</th>
                        <th>STUDENT NUMBER</th>
                        <th>STUDENT NAME</th>
                        <th>SUBJECT / SECTION</th>
                        <th>CLASS DATE</th>
                        <th>FIRST TAP TIME</th>
                        <th>STATUS</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($attendanceError !== ''): ?>

                        <tr>
                            <td colspan="7" class="empty">Unable to load attendance records.</td>
                        </tr>

                    <?php elseif (empty($records)): ?>

                        <tr>
                            <td colspan="7" class="empty">No attendance records found.</td>
                        </tr>

                    <?php else: ?>

                        <?php $number = 1; ?>

                        <?php foreach ($records as $row): ?>

                            <?php
                                $recordStatus = strtolower($row['status'] ?? '');
                                $badgeClass = in_array(
                                    $recordStatus,
                                    ['present', 'late', 'absent'],
                                    true
                                ) ? $recordStatus : '';
                            ?>

                            <tr>
                                <td><?= $number++ ?></td>

                                <td>
                                    <strong><?= e($row['student_number']) ?></strong>
                                </td>

                                <td><?= e($row['student_name']) ?></td>

                                <td>
                                    <strong><?= e($row['subject_code']) ?></strong>
                                    <br>
                                    <?= e($row['subject_name']) ?>
                                    <br>
                                    <small><?= e($row['section']) ?></small>
                                </td>

                                <td><?= e(showDate($row['class_date'])) ?></td>

                                <td><?= e(showTime($row['first_tap_time'])) ?></td>

                                <td>
                                    <span class="status <?= e($badgeClass) ?>">
                                        <?= e(ucfirst($recordStatus)) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>
            </table>

        </div>
    </section>

    <!-- NFC TAP LOGS -->
    <section class="panel">

        <div class="panel-heading">

            <div>
                <h2>Recent NFC Tap Activity</h2>
                <p>Latest 200 NFC access transactions from the access_logs table.</p>
            </div>

            <div class="panel-actions">
                <a
                    href="attendance.php?<?= e($queryString) ?>&export=logs"
                    class="btn btn-export"
                >↓ Export CSV</a>

                <button type="button" onclick="printSection('tapLogsPanel')" class="btn btn-dark">
                    Print
                </button>
            </div>

        </div>

        <?php if ($tapLogError !== ''): ?>

            <div class="notice-error"><?= e($tapLogError) ?></div>

        <?php else: ?>

            <div class="record-count">
                Tap logs found: <?= count($tapLogs) ?>
            </div>

            <div class="table-wrap" id="tapLogsPanel">

                <table>
                    <thead>
                        <tr>
                            <th>STUDENT NUMBER</th>
                            <th>STUDENT NAME</th>
                            <th>CARD UID</th>
                            <th>ROOM</th>
                            <th>TAP DATE & TIME</th>
                            <th>ACCESS RESULT</th>
                            <th>REMARKS</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($tapLogs)): ?>

                            <tr>
                                <td colspan="7" class="empty">
                                    No NFC tap logs found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($tapLogs as $log): ?>

                                <?php
                                    $resultClass = strtolower(
                                        trim($log['access_result'] ?? '')
                                    );

                                    if (!in_array(
                                        $resultClass,
                                        ['granted', 'denied', 'unregistered'],
                                        true
                                    )) {
                                        $resultClass = '';
                                    }
                                ?>

                                <tr>
                                    <td>
                                        <strong><?= e($log['student_number'] ?? 'N/A') ?></strong>
                                    </td>

                                    <td><?= e($log['student_name'] ?? 'Unknown student') ?></td>

                                    <td><?= e($log['card_uid']) ?></td>

                                    <td><?= e($log['room_name']) ?></td>

                                    <td><?= e(showDate($log['tap_time'])) ?> <?= e(showTime($log['tap_time'])) ?></td>

                                    <td>
                                        <span class="status <?= e($resultClass) ?>">
                                            <?= e(ucfirst($log['access_result'] ?? 'Unknown')) ?>
                                        </span>
                                    </td>

                                    <td><?= e($log['remarks'] ?? '--') ?></td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>
                </table>

            </div>

        <?php endif; ?>

    </section>

    <footer class="footer">
        NFC-Enabled Student ID-Based Room Access and Security System<br>
        Southern Philippines Institute of Science and Technology (CARBAG)
    </footer>

</main>

<script>
function printSection(sectionId) {
    const section = document.getElementById(sectionId);

    if (!section) {
        window.print();
        return;
    }

    const printWindow = window.open('', '_blank', 'width=1100,height=750');

    if (!printWindow) {
        alert('Please allow pop-ups to print this report.');
        return;
    }

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Attendance Report</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    padding: 25px;
                    color: #222;
                }
                h2 {
                    margin-bottom: 5px;
                }
                p {
                    color: #666;
                    font-size: 12px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                th, td {
                    border: 1px solid #ddd;
                    padding: 9px;
                    text-align: left;
                    font-size: 11px;
                }
                th {
                    background: #e9f3ec;
                }
                @media print {
                    body {
                        padding: 0;
                    }
                }
            </style>
        </head>
        <body>
            <h2>NFC Security System</h2>
            <p>Southern Philippines Institute of Science and Technology (CARBAG)</p>
            ${section.innerHTML}
        </body>
        </html>
    `);

    printWindow.document.close();
    printWindow.focus();

    printWindow.onload = function() {
        printWindow.print();
    };
}
</script>

</body>
</html>

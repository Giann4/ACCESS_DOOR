<?php
session_start();
date_default_timezone_set('Asia/Manila');
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}
require_once '../config/db.php';
/* =========================================
   HELPER FUNCTIONS
========================================= */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function getCount(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if (!$result) {
        error_log('Dashboard query error: ' . $conn->error);
        return 0;
    }
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}
/* =========================================
   ADMIN INFORMATION
========================================= */
$adminName = $_SESSION['full_name'] ?? 'Admin';
$adminInitial = strtoupper(substr($adminName, 0, 1));
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}
$today = date('Y-m-d');
$currentTime = date('H:i:s');
/* =========================================
   OVERALL STATISTICS
========================================= */
$studentCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM students"
);
$teacherCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'teacher'"
);
$cardCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM nfc_cards WHERE status = 'active'"
);
$tapCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM access_logs"
);
$subjectCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM subjects WHERE status = 'active'"
);
$scheduleCount = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM class_schedules WHERE status = 'active'"
);
/* =========================================
   ATTENDANCE SUMMARY
========================================= */
$attendanceCounts = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'no_tap' => 0
];
/* GET EXISTING ATTENDANCE RECORDS */
$stmt = $conn->prepare("
    SELECT
        LOWER(a.status) AS attendance_status,
        COUNT(*) AS total
    FROM attendance a
    INNER JOIN class_schedules cs
        ON cs.id = a.schedule_id
    INNER JOIN subjects s
        ON s.id = cs.subject_id
    WHERE a.class_date = ?
      AND cs.status = 'active'
      AND s.status = 'active'
    GROUP BY LOWER(a.status)
");
if ($stmt) {
    $stmt->bind_param('s', $selectedDate);
    $stmt->execute();
    $attendanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($attendanceRows as $row) {
        $status = strtolower(trim($row['attendance_status']));
        $total = (int)$row['total'];
        if (isset($attendanceCounts[$status]) && $status !== 'no_tap') {
            $attendanceCounts[$status] += $total;
        }
    }
}
/* =========================================
   COMPUTE NO TAP YET AND PENDING ABSENCES
========================================= */
$selectedDay = date('l', strtotime($selectedDate));
$stmt = $conn->prepare("
    SELECT
        cs.id AS schedule_id,
        cs.absence_check_time,
        COUNT(DISTINCT ss.id) AS missing_students
    FROM class_schedules cs
    INNER JOIN subjects s
        ON s.id = cs.subject_id
    INNER JOIN schedule_students ss
        ON ss.schedule_id = cs.id
    INNER JOIN students st
        ON st.id = ss.student_id
       AND st.status = 'active'
    LEFT JOIN attendance a
        ON a.student_id = ss.student_id
       AND a.schedule_id = cs.id
       AND a.class_date = ?
    WHERE cs.schedule_day = ?
      AND cs.status = 'active'
      AND s.status = 'active'
      AND a.id IS NULL
    GROUP BY cs.id, cs.absence_check_time
");
if ($stmt) {
    $stmt->bind_param('ss', $selectedDate, $selectedDay);
    $stmt->execute();
    $missingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($missingRows as $row) {
        $missingCount = (int)$row['missing_students'];
        $absenceTime = $row['absence_check_time'];
        if ($missingCount <= 0) {
            continue;
        }
        $absencePassed = false;
        if ($selectedDate < $today) {
            $absencePassed = true;
        } elseif ($selectedDate === $today) {
            $absencePassed = (
                !empty($absenceTime) &&
                $currentTime >= $absenceTime
            );
        }
        if ($absencePassed) {
            $attendanceCounts['absent'] += $missingCount;
        } else {
            $attendanceCounts['no_tap'] += $missingCount;
        }
    }
}
/* =========================================
   ATTENDANCE CHART DATA
========================================= */
$attendanceLabels = [
    'Present',
    'Late',
    'Absent',
    'No Tap'
];
$attendanceValues = [
    $attendanceCounts['present'],
    $attendanceCounts['late'],
    $attendanceCounts['absent'],
    $attendanceCounts['no_tap']
];
$attendanceSummary = [
    ['Present', $attendanceCounts['present'], '#28d889'],
    ['Late', $attendanceCounts['late'], '#f3b94f'],
    ['Absent', $attendanceCounts['absent'], '#ef6262'],
    ['No Tap', $attendanceCounts['no_tap'], '#a7b0bd']
];
$dailyAttendanceTotal = array_sum($attendanceValues);
$summaryTotal = max(1, $dailyAttendanceTotal);
/* =========================================
   STUDENTS BY CLASS
========================================= */
$classLabels = [];
$classValues = [];
$stmt = $conn->prepare("
    SELECT
        CONCAT(s.subject_code, ' - ', s.section) AS class_name,
        COUNT(DISTINCT ss.student_id) AS total_students
    FROM subjects s
    INNER JOIN class_schedules cs
        ON cs.subject_id = s.id
    LEFT JOIN schedule_students ss
        ON ss.schedule_id = cs.id
    WHERE s.status = 'active'
      AND cs.status = 'active'
    GROUP BY cs.id, s.subject_code, s.section
    ORDER BY s.subject_code, s.section
    LIMIT 10
");
if ($stmt) {
    $stmt->execute();
    $classRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($classRows as $row) {
        $classLabels[] = $row['class_name'];
        $classValues[] = (int)$row['total_students'];
    }
}
/* =========================================
   RECENT NFC TAP ACTIVITY
========================================= */
$recentTaps = [];
$stmt = $conn->prepare("
    SELECT
        al.id,
        al.student_id,
        al.card_uid,
        al.room_name,
        al.tap_time,
        al.access_result,
        al.remarks,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name
    FROM access_logs al
    LEFT JOIN students st
        ON st.id = al.student_id
    ORDER BY al.tap_time DESC, al.id DESC
    LIMIT 8
");
if ($stmt) {
    $stmt->execute();
    $recentTaps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Overview | NFC Security System</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    --shadow: 0 5px 18px rgba(17,24,39,.05);
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
.main-content {
    margin-left: 250px;
    padding: 30px 35px;
    min-height: 100vh;
}
/* WELCOME HEADER */
.welcome-header{height:168px;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;width:100%;background:var(--navy);color:#fff;padding:30px;border-radius:14px;margin-bottom:20px;box-shadow:var(--shadow)}
.welcome-header h1{margin:0 0 9px;font-size:23px;font-weight:750;letter-spacing:-.4px}
.welcome-header h1 span{color:var(--green)}
.welcome-header p{margin:0;color:#cbd5e1;font-size:13px;line-height:1.6}
/* DATE FILTER */
.filter-panel {
    display: flex;
    align-items: end;
    gap: 15px;
    flex-wrap: wrap;
    padding: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    margin-bottom: 22px;
    box-shadow: var(--shadow);
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.filter-group label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
}
.filter-group input {
    min-width: 190px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: white;
    color: var(--text);
    font-family: inherit;
}
.btn {
    padding: 12px 17px;
    background: var(--green);
    color: var(--navy);
    border: none;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
}
.btn:hover {
    background: var(--green-dark);
    color: white;
}
/* STATISTICS */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--shadow);
}
.stat-card small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 10px;
    text-transform: uppercase;
}
.stat-card strong {
    font-size: 27px;
    color: var(--navy);
}
.stat-card.highlight {
    background: var(--green);
}
.stat-card.highlight small,
.stat-card.highlight strong {
    color: var(--navy);
}
/* PANELS AND GRIDS */
.content-grid {
    display: grid;
    grid-template-columns: 1.65fr 1fr;
    gap: 15px;
    margin-bottom: 17px;
}
.bottom-grid {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
    box-shadow: var(--shadow);
    min-width: 0;
}
.panel-heading {
    margin-bottom: 20px;
}
.panel-heading h3 {
    margin: 0 0 7px;
    font-size: 15px;
    color: var(--navy);
}
.panel-subtitle {
    color: var(--muted);
    font-size: 11px;
}
/* CHARTS */
.chart-wrap {
    height: 260px;
    position: relative;
}
.small-chart {
    height: 240px;
}
/* ATTENDANCE SUMMARY */
.summary-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    margin-bottom: 9px;
}
.summary-label {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #718096;
}
.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
}
.progress-track {
    width: 100%;
    height: 7px;
    background: #edf0f3;
    border-radius: 20px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    border-radius: 20px;
}
/* SYSTEM INFORMATION */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 13px;
}
.info-item {
    background: #f7f9fb;
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 15px;
}
.info-item small {
    display: block;
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
    margin-bottom: 8px;
}
.info-item strong {
    font-size: 21px;
    color: var(--navy);
}
/* RECENT TAP TABLE */
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
    background: #f5f7fa;
    color: #68768a;
    padding: 13px 12px;
    text-align: left;
    font-size: 10px;
    text-transform: uppercase;
}
tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
    color: #718096;
}
tbody td strong {
    color: var(--navy);
}
.status-pill {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 750;
    white-space: nowrap;
}
.status-granted {
    background: #e5f9ef;
    color: #16834f;
}
.status-denied {
    background: #feecec;
    color: #dc2626;
}
.status-unregistered {
    background: #fff4df;
    color: #b7791f;
}
.status-unknown {
    background: #edf0f3;
    color: #64748b;
}
.empty {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}
/* FOOTER */
.footer {
    text-align: center;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.7;
    padding: 25px 10px 5px;
}
/* RESPONSIVE */
@media(max-width: 1100px) {
    .content-grid,
    .bottom-grid {
        grid-template-columns: 1fr;
    }
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media(max-width: 768px) {
    .main-content {
        margin-left: 210px;
        padding: 20px 16px;
    }
}
@media(max-width: 520px) {
    .sidebar-brand {
        justify-content: center;
        padding: 0;
    }
    .brand-name,
    .menu-label,
    .sidebar-nav a span:not(.nav-icon),
    .profile-details,
    .logout-link {
        display: none;
    }
    .sidebar-nav a {
        justify-content: center;
        padding: 14px 5px;
    }
    .admin-profile {
        justify-content: center;
    }
    .main-content {
        margin-left: 68px;
        padding: 16px 12px;
    }
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .filter-panel {
        align-items: stretch;
        flex-direction: column;
    }
    .filter-group input {
        width: 100%;
    }
}
</style>
</head>
<body>
<!-- ADMIN SIDEBAR -->
<?php require_once __DIR__ . '/sidebar.php'; ?>
<!-- MAIN CONTENT -->
<main class="main-content">
    <!-- WELCOME HEADER -->
    <section class="welcome-header">
        <h1>
            Hello <span><?= e($adminName) ?></span>! 👋
        </h1>
        <p>
            Welcome back! Here's your overall system overview.
        </p>
    </section>
    <!-- DATE FILTER -->
    <form method="GET" action="dashboard.php" class="filter-panel">
        <div class="filter-group">
            <label>Select Date</label>
            <input
                type="date"
                name="date"
                value="<?= e($selectedDate) ?>"
            >
        </div>
        <button type="submit" class="btn">
            Apply Filter
        </button>
    </form>
    <!-- STATISTICS -->
    <section class="stats-grid">
        <div class="stat-card">
            <small>Total Students</small>
            <strong><?= $studentCount ?></strong>
        </div>
        <div class="stat-card highlight">
            <small>Total Teachers</small>
            <strong><?= $teacherCount ?></strong>
        </div>
        <div class="stat-card">
            <small>Active NFC Cards</small>
            <strong><?= $cardCount ?></strong>
        </div>
        <div class="stat-card">
            <small>Total Tap Logs</small>
            <strong><?= $tapCount ?></strong>
        </div>
    </section>
    <!-- ATTENDANCE OVERVIEW AND SUMMARY -->
    <section class="content-grid">
        <div class="panel">
            <div class="panel-heading">
                <h3>Attendance Overview</h3>
                <div class="panel-subtitle">
                    Overall attendance distribution for
                    <?= e(date('M d, Y', strtotime($selectedDate))) ?>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
        <div class="panel">
            <div class="panel-heading">
                <h3>Attendance Summary</h3>
                <div class="panel-subtitle">
                    Overall school attendance records
                </div>
            </div>
            <div class="summary-list">
                <?php foreach ($attendanceSummary as $item): ?>
                    <?php
                    $percentage = round(($item[1] / $summaryTotal) * 100);
                    ?>
                    <div>
                        <div class="summary-row">
                            <div class="summary-label">
                                <span
                                    class="dot"
                                    style="background:<?= e($item[2]) ?>"
                                ></span>
                                <?= e($item[0]) ?>
                            </div>
                            <strong>
                                <?= (int)$item[1] ?>
                                <small style="color:#98a2b1;">
                                    (<?= $percentage ?>%)
                                </small>
                            </strong>
                        </div>
                        <div class="progress-track">
                            <div
                                class="progress-fill"
                                style="width:<?= $percentage ?>%;background:<?= e($item[2]) ?>"
                            ></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <!-- STUDENTS BY CLASS AND SYSTEM INFORMATION -->
    <section class="bottom-grid">
        <div class="panel">
            <div class="panel-heading">
                <h3>Students by Class</h3>
                <div class="panel-subtitle">
                    Student enrollment across active class schedules
                </div>
            </div>
            <div class="chart-wrap small-chart">
                <canvas id="classChart"></canvas>
            </div>
        </div>
        <div class="panel">
            <div class="panel-heading">
                <h3>System Information</h3>
                <div class="panel-subtitle">
                    Current system records
                </div>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <small>ACTIVE SUBJECTS</small>
                    <strong><?= $subjectCount ?></strong>
                </div>
                <div class="info-item">
                    <small>ACTIVE SCHEDULES</small>
                    <strong><?= $scheduleCount ?></strong>
                </div>
                <div class="info-item">
                    <small>REGISTERED STUDENTS</small>
                    <strong><?= $studentCount ?></strong>
                </div>
                <div class="info-item">
                    <small>ACTIVE NFC CARDS</small>
                    <strong><?= $cardCount ?></strong>
                </div>
            </div>
        </div>
    </section>
    <!-- RECENT NFC TAP ACTIVITY -->
    <section class="panel">
        <div class="panel-heading">
            <h3>Recent NFC Tap Activity</h3>
            <div class="panel-subtitle">
                Latest recorded NFC access transactions
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Student Name</th>
                        <th>Room</th>
                        <th>Card UID</th>
                        <th>Tap Time</th>
                        <th>Access Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentTaps) > 0): ?>
                        <?php foreach ($recentTaps as $tap): ?>
                            <?php
                            $fullName = trim(
                                ($tap['first_name'] ?? '') . ' ' .
                                ($tap['middle_name'] ?? '') . ' ' .
                                ($tap['last_name'] ?? '')
                            );
                            $accessResult = strtolower(
                                trim((string)($tap['access_result'] ?? ''))
                            );
                            $statusClass = 'status-unknown';
                            if ($accessResult === 'granted') {
                                $statusClass = 'status-granted';
                            } elseif ($accessResult === 'denied') {
                                $statusClass = 'status-denied';
                            } elseif ($accessResult === 'unregistered') {
                                $statusClass = 'status-unregistered';
                            }
                            $formattedTapTime = !empty($tap['tap_time'])
                                ? date('M d, Y h:i:s A', strtotime($tap['tap_time']))
                                : '--';
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= e($tap['student_number'] ?? 'N/A') ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= e($fullName ?: 'Unknown Student') ?>
                                </td>
                                <td>
                                    <?= e($tap['room_name'] ?? '--') ?>
                                </td>
                                <td>
                                    <?= e($tap['card_uid'] ?? '--') ?>
                                </td>
                                <td>
                                    <?= e($formattedTapTime) ?>
                                </td>
                                <td>
                                    <span class="status-pill <?= e($statusClass) ?>">
                                        <?= e(ucfirst($accessResult ?: 'Unknown')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty">
                                No NFC tap activity recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <!-- FOOTER -->
    <footer class="footer">
        NFC-Enabled Student ID-Based Room Access and Security System<br>
        Southern Philippines Institute of Science and Technology (CARBAG)
    </footer>
</main>
<!-- CHARTS -->
<script>
/* ATTENDANCE OVERVIEW */
const attendanceCanvas = document.getElementById('attendanceChart');
if (attendanceCanvas && typeof Chart !== 'undefined') {
    new Chart(attendanceCanvas, {
        type: 'line',
        data: {
            labels: <?= json_encode($attendanceLabels) ?>,
            datasets: [{
                label: 'Students',
                data: <?= json_encode($attendanceValues) ?>,
                borderColor: '#28d889',
                backgroundColor: 'rgba(40,216,137,.10)',
                borderWidth: 3,
                pointRadius: 5,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#28d889',
                pointBorderWidth: 2,
                tension: 0,
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#edf0f3'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
/* STUDENTS BY CLASS */
const classCanvas = document.getElementById('classChart');
if (classCanvas && typeof Chart !== 'undefined') {
    new Chart(classCanvas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($classLabels) ?>,
            datasets: [{
                label: 'Enrolled Students',
                data: <?= json_encode($classValues) ?>,
                backgroundColor: '#28d889',
                borderRadius: 7,
                maxBarThickness: 45
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#edf0f3'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>
</body>
</html>

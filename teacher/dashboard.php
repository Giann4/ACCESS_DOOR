
<?php
require_once 'common.php';

/* =========================================
   GET SELECTED CLASS ATTENDANCE
========================================= */

$attendanceRows = [];

$attendanceCounts = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'no_tap' => 0
];

if ($selectedScheduleId > 0) {

    $stmt = $conn->prepare("
        SELECT
            st.id AS student_id,
            st.student_number,
            st.first_name,
            st.middle_name,
            st.last_name,
            a.first_tap_time,
            a.status AS attendance_status
        FROM schedule_students ss
        INNER JOIN students st
            ON st.id = ss.student_id
        LEFT JOIN attendance a
            ON a.student_id = st.id
            AND a.schedule_id = ss.schedule_id
            AND a.class_date = ?
        WHERE ss.schedule_id = ?
          AND st.status = 'active'
        ORDER BY st.last_name, st.first_name
    ");

    $stmt->bind_param('si', $selectedDate, $selectedScheduleId);
    $stmt->execute();

    $attendanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($attendanceRows as $row) {

        $status = strtolower(trim($row['attendance_status'] ?? 'no_tap'));

        if (isset($attendanceCounts[$status])) {
            $attendanceCounts[$status]++;
        } else {
            $attendanceCounts['no_tap']++;
        }
    }
}

/* =========================================
   TOTAL STUDENTS
========================================= */

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT ss.student_id) AS total
    FROM schedule_students ss
    INNER JOIN class_schedules cs
        ON cs.id = ss.schedule_id
    INNER JOIN subjects s
        ON s.id = cs.subject_id
    WHERE s.teacher_id = ?
      AND s.status = 'active'
      AND cs.status = 'active'
");

$stmt->bind_param('i', $teacherId);
$stmt->execute();

$studentCount = (int)(
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();

/* =========================================
   OVERVIEW DATA
========================================= */

$totalSelectedStudents = count($attendanceRows);
$summaryTotal = max(1, $totalSelectedStudents);

$summaryItems = [
    ['Present', $attendanceCounts['present'], '#28d889'],
    ['Late', $attendanceCounts['late'], '#f3b94f'],
    ['Absent', $attendanceCounts['absent'], '#ef6262'],
    ['No Tap Yet', $attendanceCounts['no_tap'], '#a7b0bd']
];

$chartLabels = ['Present', 'Late', 'Absent', 'No Tap'];

$chartValues = [
    $attendanceCounts['present'],
    $attendanceCounts['late'],
    $attendanceCounts['absent'],
    $attendanceCounts['no_tap']
];

$classLabels = [];
$classValues = [];

foreach ($schedules as $schedule) {
    $classLabels[] = $schedule['subject_code'] . ' - ' . $schedule['section'];
    $classValues[] = (int)$schedule['enrolled_students'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Overview | Teacher Dashboard</title>

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

/* =========================================
   MAIN CONTENT
========================================= */

.page-content {
    margin-left: 250px;
    padding: 30px 35px;
    min-height: 100vh;
}

/* =========================================
   WELCOME HEADER - NAVY CARD
========================================= */

.welcome-header {
    background: var(--navy);
    color: white;
    padding: 30px;
    border-radius: 14px;
    margin-bottom: 20px;

    display: flex;
    flex-direction: column;
    align-items: stretch;

    box-shadow: 0 5px 18px rgba(17,24,39,.10);
}

.welcome-info h2 {
    margin: 0 0 8px;
    font-size: 22px;
    font-weight: 750;
}

.welcome-info p {
    color: #c0c8d5;
    margin: 0;
    font-size: 13px;
}

/* =========================================
   CLASS AND DATE FILTER - WHITE CARD
========================================= */

.hero-filters {
    display: flex;
    gap: 15px;
    align-items: end;
    flex-wrap: wrap;
    width: 100%;

    padding: 20px;
    margin-bottom: 22px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;

    box-shadow: 0 4px 15px rgba(17,24,39,.04);
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

.filter-group select,
.filter-group input {
    min-width: 180px;
    padding: 11px 12px;

    border: 1px solid var(--border);
    border-radius: 8px;

    background: white;
    color: var(--text);

    font-family: inherit;
    font-size: 12px;
    outline: none;
}

.filter-group select:focus,
.filter-group input:focus {
    border-color: var(--green);
}

/* =========================================
   BUTTON
========================================= */

.btn {
    padding: 12px 17px;
    background: var(--green);
    color: var(--navy);
    border: none;
    border-radius: 8px;

    font-size: 12px;
    font-weight: 750;

    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn:hover {
    background: var(--green-dark);
    color: white;
}

/* =========================================
   STATISTICS
========================================= */

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

    box-shadow: 0 4px 15px rgba(17,24,39,.04);
}

.stat-card small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 9px;
    text-transform: uppercase;
}

.stat-card strong {
    font-size: 27px;
    color: var(--navy);
}

.stat-card.present {
    background: var(--green);
}

.stat-card.present small,
.stat-card.present strong {
    color: var(--navy);
}

.stat-card.absent strong {
    color: var(--red);
}

.stat-card.late strong {
    color: #d18a17;
}

/* =========================================
   CONTENT GRID
========================================= */

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

/* =========================================
   PANELS
========================================= */

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;

    box-shadow: 0 5px 18px rgba(17,24,39,.05);
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

/* =========================================
   CHARTS
========================================= */

.chart-wrap {
    height: 260px;
    position: relative;
}

.small-chart {
    height: 240px;
}

/* =========================================
   ATTENDANCE SUMMARY
========================================= */

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

.summary-row strong {
    color: var(--navy);
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

/* =========================================
   CLASS INFORMATION
========================================= */

.class-info {
    font-size: 12px;
    line-height: 2;
    color: #8290a4;
}

.class-info strong {
    color: var(--text);
}

.class-info .subject-name {
    display: block;
    color: var(--navy);
    font-size: 17px;
    font-weight: 750;
    margin-bottom: 8px;
}

/* =========================================
   CLASS SCHEDULE TABLE
========================================= */

.schedule-panel {
    margin-bottom: 20px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

thead th {
    background: #f5f7fa;
    color: #68768a;
    padding: 14px 12px;
    text-align: left;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

tbody td {
    padding: 15px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    color: #718096;
}

tbody tr:hover {
    background: #f2fbf6;
}

tbody tr.selected-row {
    background: #effbf5;
}

tbody td strong {
    color: var(--navy);
}

tbody td small {
    display: block;
    color: var(--muted);
    margin-top: 5px;
    font-size: 10px;
}

.view-button {
    display: inline-block;
    background: var(--green);
    color: var(--navy);
    text-decoration: none;
    padding: 9px 13px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 750;
}

.view-button:hover {
    background: var(--green-dark);
    color: white;
}

.empty {
    text-align: center;
    padding: 30px 12px;
    color: var(--muted);
    font-size: 12px;
}

/* =========================================
   FOOTER
========================================= */

.footer {
    text-align: center;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.7;
    padding: 20px 10px;
}

/* =========================================
   RESPONSIVE
========================================= */

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

    .page-content {
        margin-left: 210px;
        padding: 18px;
    }

    .welcome-header {
        padding: 22px;
    }

    .welcome-info h2 {
        font-size: 19px;
    }

}

@media(max-width: 480px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .hero-filters {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group,
    .filter-group select,
    .filter-group input {
        width: 100%;
    }

}

</style>

</head>

<body>

<?php include 'sidebar.php'; ?>

<main class="page-content">

    <!-- WELCOME HEADER -->

    <section class="welcome-header">

        <div class="welcome-info">

            <h2>Hello <?= e($teacherName) ?>! 👋</h2>

            <p>
                Welcome back! Here's your class attendance overview.
            </p>

        </div>

    </section>

    <!-- CLASS AND DATE FILTERS - SEPARATE WHITE CARD -->

    <form method="GET" action="dashboard.php" class="hero-filters">

        <div class="filter-group">

            <label>Select Class</label>

            <select name="schedule_id">

                <?php if ($schedules): ?>

                    <?php foreach ($schedules as $schedule): ?>

                        <option
                            value="<?= (int)$schedule['id'] ?>"
                            <?= $selectedScheduleId === (int)$schedule['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($schedule['subject_code']) ?>
                            - <?= e($schedule['section']) ?>
                        </option>

                    <?php endforeach; ?>

                <?php else: ?>

                    <option value="">No assigned classes</option>

                <?php endif; ?>

            </select>

        </div>

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

        <div class="stat-card present">
            <small>Present Today</small>
            <strong><?= $attendanceCounts['present'] ?></strong>
        </div>

        <div class="stat-card absent">
            <small>Absent Today</small>
            <strong><?= $attendanceCounts['absent'] ?></strong>
        </div>

        <div class="stat-card late">
            <small>Late Students</small>
            <strong><?= $attendanceCounts['late'] ?></strong>
        </div>

    </section>

    <!-- ATTENDANCE OVERVIEW -->

    <section class="content-grid">

        <div class="panel">

            <div class="panel-heading">

                <h3>Attendance Overview</h3>

                <div class="panel-subtitle">
                    Attendance distribution for
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
                    Selected class attendance
                </div>

            </div>

            <div class="summary-list">

                <?php foreach ($summaryItems as $item): ?>

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

                                <small style="display:inline;color:#98a2b1;">
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

    <!-- STUDENTS BY CLASS AND CLASS INFORMATION -->

    <section class="bottom-grid">

        <div class="panel">

            <div class="panel-heading">

                <h3>Students by Class</h3>

                <div class="panel-subtitle">
                    Number of students enrolled in your assigned schedules
                </div>

            </div>

            <div class="chart-wrap small-chart">
                <canvas id="classChart"></canvas>
            </div>

        </div>

        <div class="panel">

            <div class="panel-heading">

                <h3>Class Information</h3>

                <div class="panel-subtitle">
                    Currently selected schedule
                </div>

            </div>

            <?php if ($selectedSchedule): ?>

                <div class="class-info">

                    <strong class="subject-name">
                        <?= e($selectedSchedule['subject_name']) ?>
                    </strong>

                    Subject Code:
                    <strong><?= e($selectedSchedule['subject_code']) ?></strong>

                    <br>

                    Grade Level:
                    <strong><?= e($selectedSchedule['grade_level']) ?></strong>

                    <br>

                    Section:
                    <strong><?= e($selectedSchedule['section']) ?></strong>

                    <br>

                    Schedule:
                    <strong><?= e($selectedSchedule['schedule_day']) ?></strong>

                    <br>

                    Time:
                    <strong>
                        <?= e(showTime($selectedSchedule['start_time'])) ?>
                        – <?= e(showTime($selectedSchedule['end_time'])) ?>
                    </strong>

                    <br>

                    Room:
                    <strong><?= e($selectedSchedule['room_name']) ?></strong>

                    <br>

                    Late Cutoff:
                    <strong><?= e(showTime($selectedSchedule['late_after'])) ?></strong>

                    <br>

                    Absence Check:
                    <strong><?= e(showTime($selectedSchedule['absence_check_time'])) ?></strong>

                    <br><br>

                    Enrolled Students:
                    <strong><?= (int)$selectedSchedule['enrolled_students'] ?></strong>

                </div>

            <?php else: ?>

                <div class="empty">
                    No class schedule assigned to your account.
                </div>

            <?php endif; ?>

        </div>

    </section>

    <!-- CLASS SCHEDULES -->

    <section class="panel schedule-panel">

        <div class="panel-heading">

            <h3>My Class Schedules</h3>

            <div class="panel-subtitle">
                View your assigned subjects, rooms, and class schedules.
            </div>

        </div>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Subject / Section</th>
                        <th>Day</th>
                        <th>Class Time</th>
                        <th>Room</th>
                        <th>Students</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (count($schedules) > 0): ?>

                        <?php foreach ($schedules as $row): ?>

                            <tr class="<?= $selectedScheduleId === (int)$row['id'] ? 'selected-row' : '' ?>">

                                <td>

                                    <strong><?= e($row['subject_name']) ?></strong>

                                    <small>
                                        <?= e($row['subject_code']) ?>
                                        · Grade <?= e($row['grade_level']) ?>
                                        / <?= e($row['section']) ?>
                                    </small>

                                </td>

                                <td><?= e($row['schedule_day']) ?></td>

                                <td>

                                    <?= e(showTime($row['start_time'])) ?>
                                    – <?= e(showTime($row['end_time'])) ?>

                                    <small>
                                        Late after:
                                        <?= e(showTime($row['late_after'])) ?>
                                    </small>

                                </td>

                                <td><?= e($row['room_name']) ?></td>

                                <td><?= (int)$row['enrolled_students'] ?></td>

                                <td>

                                    <a
                                        class="view-button"
                                        href="attendance.php?schedule_id=<?= (int)$row['id'] ?>&date=<?= e($selectedDate) ?>"
                                    >
                                        View Class
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="empty">
                                No active class schedules are assigned to your account.
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

<script>

/* =========================================
   ATTENDANCE OVERVIEW CHART
========================================= */

const attendanceCanvas = document.getElementById('attendanceChart');

if (attendanceCanvas && typeof Chart !== 'undefined') {

    new Chart(attendanceCanvas, {

        type: 'line',

        data: {

            labels: <?= json_encode($chartLabels) ?>,

            datasets: [{

                label: 'Students',

                data: <?= json_encode($chartValues) ?>,

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

/* =========================================
   STUDENTS BY CLASS CHART
========================================= */

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

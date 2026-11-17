
<?php
require_once 'common.php';

/* =========================================
   DATE RANGE
========================================= */

$startDate = date(
    'Y-m-d',
    strtotime($selectedDate . ' -6 days')
);

$topStartDate = date(
    'Y-m-d',
    strtotime($selectedDate . ' -29 days')
);

/* =========================================
   ATTENDANCE OVERVIEW
   ALL ACTIVE CLASSES OF TEACHER
========================================= */

$attendanceSummary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0
];

$stmt = $conn->prepare("
    SELECT
        a.status,
        COUNT(*) AS total
    FROM attendance a
    INNER JOIN class_schedules cs
        ON cs.id = a.schedule_id
    INNER JOIN subjects s
        ON s.id = cs.subject_id
    WHERE s.teacher_id = ?
      AND s.status = 'active'
      AND cs.status = 'active'
      AND a.class_date = ?
    GROUP BY a.status
");

$stmt->bind_param('is', $teacherId, $selectedDate);
$stmt->execute();

$summaryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

foreach ($summaryRows as $row) {

    $status = strtolower($row['status']);

    if (isset($attendanceSummary[$status])) {
        $attendanceSummary[$status] = (int)$row['total'];
    }
}

/* =========================================
   TOTAL ENROLLED STUDENTS
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

$totalStudents = (int)(
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();

/* =========================================
   DAILY ATTENDANCE TREND - LAST 7 DAYS
========================================= */

$stmt = $conn->prepare("
    SELECT
        a.class_date,
        SUM(a.status = 'present') AS present_count,
        SUM(a.status = 'late') AS late_count,
        SUM(a.status = 'absent') AS absent_count
    FROM attendance a
    INNER JOIN class_schedules cs
        ON cs.id = a.schedule_id
    INNER JOIN subjects s
        ON s.id = cs.subject_id
    WHERE s.teacher_id = ?
      AND s.status = 'active'
      AND cs.status = 'active'
      AND a.class_date BETWEEN ? AND ?
    GROUP BY a.class_date
    ORDER BY a.class_date
");

$stmt->bind_param(
    'iss',
    $teacherId,
    $startDate,
    $selectedDate
);

$stmt->execute();

$trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$trendMap = [];

foreach ($trendRows as $row) {
    $trendMap[$row['class_date']] = $row;
}

$trendLabels = [];
$trendPresent = [];
$trendLate = [];
$trendAbsent = [];

$weeklyAbsentLabels = [];
$weeklyAbsentValues = [];

for ($i = 0; $i < 7; $i++) {

    $dayDate = date(
        'Y-m-d',
        strtotime($startDate . " +{$i} days")
    );

    $trendLabels[] = date('M d', strtotime($dayDate));

    $weeklyAbsentLabels[] = date(
        'D',
        strtotime($dayDate)
    );

    $trendPresent[] = (int)(
        $trendMap[$dayDate]['present_count'] ?? 0
    );

    $trendLate[] = (int)(
        $trendMap[$dayDate]['late_count'] ?? 0
    );

    $trendAbsent[] = (int)(
        $trendMap[$dayDate]['absent_count'] ?? 0
    );

    $weeklyAbsentValues[] = (int)(
        $trendMap[$dayDate]['absent_count'] ?? 0
    );
}

/* =========================================
   TOP 6 ATTENDEES - LAST 30 DAYS
========================================= */

$stmt = $conn->prepare("
    SELECT
        st.id AS student_id,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name,

        COUNT(a.id) AS recorded_sessions,

        SUM(
            a.status IN ('present', 'late')
        ) AS attended_sessions,

        ROUND(
            SUM(a.status IN ('present', 'late'))
            / NULLIF(COUNT(a.id), 0) * 100,
            1
        ) AS attendance_rate

    FROM attendance a

    INNER JOIN students st
        ON st.id = a.student_id

    INNER JOIN class_schedules cs
        ON cs.id = a.schedule_id

    INNER JOIN subjects s
        ON s.id = cs.subject_id

    WHERE s.teacher_id = ?
      AND s.status = 'active'
      AND cs.status = 'active'
      AND a.class_date BETWEEN ? AND ?

    GROUP BY
        st.id,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name

    HAVING COUNT(a.id) > 0

    ORDER BY
        attendance_rate DESC,
        attended_sessions DESC,
        st.last_name ASC

    LIMIT 6
");

$stmt->bind_param(
    'iss',
    $teacherId,
    $topStartDate,
    $selectedDate
);

$stmt->execute();

$topAttendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports | Teacher Dashboard</title>

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
   PAGE HEADER
========================================= */

.page-header {
    background: var(--navy);
    color: white;
    padding: 28px 30px;
    border-radius: 14px;
    margin-bottom: 25px;
    box-shadow: 0 5px 18px rgba(17,24,39,.10);
}

.page-header h1 {
    color: var(--green);
    margin: 0 0 8px;
    font-size: 25px;
}

.page-header p {
    color: #c0c8d5;
    font-size: 13px;
    margin: 0;
}

/* =========================================
   FILTER
========================================= */

.filter-panel {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 15px;
    padding: 20px;
    margin-bottom: 22px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(17,24,39,.04);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
}

.filter-group input {
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
}

.btn {
    padding: 12px 19px;
    background: var(--green);
    color: var(--navy);
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 750;
    cursor: pointer;
}

.btn:hover {
    background: var(--green-dark);
    color: white;
}

/* =========================================
   SUMMARY CARDS
========================================= */

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
    font-size: 12px;
    font-weight: 650;
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

/* =========================================
   REPORT PANELS
========================================= */

.analytics-grid {
    display: grid;
    grid-template-columns: 1.35fr .9fr;
    gap: 18px;
    margin-bottom: 18px;
}

.analytics-panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 23px;
    box-shadow: 0 5px 18px rgba(17,24,39,.05);
}

.analytics-panel h3 {
    margin: 0 0 8px;
    color: var(--navy);
    font-size: 16px;
}

.analytics-subtitle {
    color: var(--muted);
    font-size: 11px;
    margin-bottom: 20px;
}

.analytics-chart {
    position: relative;
    height: 300px;
    width: 100%;
}

/* =========================================
   TOP ATTENDEES
========================================= */

.top-attendee {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}

.top-attendee:last-child {
    border-bottom: none;
}

.attendee-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.attendee-avatar {
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #e5f9ef;
    color: var(--green-dark);
    font-weight: 800;
    font-size: 12px;
}

.attendee-name {
    font-size: 12px;
    font-weight: 750;
    color: var(--navy);
}

.attendee-number {
    font-size: 10px;
    color: var(--muted);
    margin-top: 4px;
}

.attendee-rate {
    background: #e5f9ef;
    color: #16834f;
    border-radius: 20px;
    padding: 7px 10px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

/* =========================================
   WEEKLY REPORT
========================================= */

.weekly-panel {
    margin-bottom: 20px;
}

.empty {
    text-align: center;
    padding: 25px 10px;
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
    padding: 25px 10px;
}

/* =========================================
   RESPONSIVE
========================================= */

@media(max-width: 1100px) {

    .analytics-grid {
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

    .page-header {
        padding: 22px;
    }

    .analytics-chart {
        height: 250px;
    }

}

@media(max-width: 480px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<?php include 'sidebar.php'; ?>

<main class="page-content">

    <!-- PAGE HEADER -->

    <section class="page-header">

        <h1>Reports & Analytics</h1>

        <p>
            Monitor attendance performance and student attendance trends.
        </p>

    </section>

    <!-- DATE FILTER -->

    <form method="GET" action="reports.php" class="filter-panel">

        <div class="filter-group">

            <label>Select Report Date</label>

            <input
                type="date"
                name="date"
                value="<?= e($selectedDate) ?>"
            >

        </div>

        <button type="submit" class="btn">
            Generate Report
        </button>

    </form>

    <!-- ATTENDANCE SUMMARY -->

    <section class="stats-grid">

        <div class="stat-card">
            <small>Total Enrolled Students</small>
            <strong><?= $totalStudents ?></strong>
        </div>

        <div class="stat-card present">
            <small>Present Today</small>
            <strong><?= $attendanceSummary['present'] ?></strong>
        </div>

        <div class="stat-card late">
            <small>Late Today</small>
            <strong><?= $attendanceSummary['late'] ?></strong>
        </div>

        <div class="stat-card absent">
            <small>Absent Today</small>
            <strong><?= $attendanceSummary['absent'] ?></strong>
        </div>

    </section>

    <!-- DAILY ATTENDANCE TREND AND TOP ATTENDEES -->

    <section class="analytics-grid">

        <div class="analytics-panel">

            <h3>Daily Attendance Trend</h3>

            <div class="analytics-subtitle">
                Present, late, and absent records across your classes
                · 7 days ending
                <?= e(date('M d, Y', strtotime($selectedDate))) ?>
            </div>

            <div class="analytics-chart">
                <canvas id="dailyTrendChart"></canvas>
            </div>

        </div>

        <div class="analytics-panel">

            <h3>Top 6 Attendees</h3>

            <div class="analytics-subtitle">
                Highest attendance rate in the last 30 days
            </div>

            <?php if (count($topAttendees) > 0): ?>

                <?php foreach ($topAttendees as $index => $student): ?>

                    <?php
                    $studentName = trim(
                        $student['first_name'] . ' ' .
                        ($student['middle_name'] ?? '') . ' ' .
                        $student['last_name']
                    );
                    ?>

                    <div class="top-attendee">

                        <div class="attendee-info">

                            <div class="attendee-avatar">
                                <?= (int)$index + 1 ?>
                            </div>

                            <div>

                                <div class="attendee-name">
                                    <?= e($studentName) ?>
                                </div>

                                <div class="attendee-number">
                                    <?= e($student['student_number']) ?>
                                </div>

                            </div>

                        </div>

                        <div class="attendee-rate">
                            <?= number_format(
                                (float)$student['attendance_rate'],
                                1
                            ) ?>%
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty">
                    No attendance history available for the selected period.
                </div>

            <?php endif; ?>

        </div>

    </section>

    <!-- WEEKLY ABSENT REPORT -->

    <section class="analytics-panel weekly-panel">

        <h3>Weekly Absent Report</h3>

        <div class="analytics-subtitle">
            Total absent attendance records per day across your assigned classes
        </div>

        <div class="analytics-chart">
            <canvas id="weeklyAbsentChart"></canvas>
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
   DAILY ATTENDANCE TREND
========================================= */

const dailyTrendCanvas = document.getElementById('dailyTrendChart');

if (dailyTrendCanvas && typeof Chart !== 'undefined') {

    new Chart(dailyTrendCanvas, {

        type: 'line',

        data: {

            labels: <?= json_encode($trendLabels) ?>,

            datasets: [

                {
                    label: 'Present',
                    data: <?= json_encode($trendPresent) ?>,
                    borderColor: '#28d889',
                    backgroundColor: 'rgba(40,216,137,.10)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3
                },

                {
                    label: 'Late',
                    data: <?= json_encode($trendLate) ?>,
                    borderColor: '#f3b94f',
                    tension: 0.35,
                    borderWidth: 2
                },

                {
                    label: 'Absent',
                    data: <?= json_encode($trendAbsent) ?>,
                    borderColor: '#ef6262',
                    tension: 0.35,
                    borderWidth: 2
                }

            ]

        },

        options: {

            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: 'bottom'
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
   WEEKLY ABSENT REPORT
========================================= */

const weeklyAbsentCanvas = document.getElementById('weeklyAbsentChart');

if (weeklyAbsentCanvas && typeof Chart !== 'undefined') {

    new Chart(weeklyAbsentCanvas, {

        type: 'bar',

        data: {

            labels: <?= json_encode($weeklyAbsentLabels) ?>,

            datasets: [{

                label: 'Absent Students',

                data: <?= json_encode($weeklyAbsentValues) ?>,

                backgroundColor: '#ef6262',

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

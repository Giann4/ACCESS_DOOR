
<?php
require_once 'common.php';

/* =========================================
   GET ATTENDANCE RECORDS
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
        INNER JOIN class_schedules cs
            ON cs.id = ss.schedule_id
        INNER JOIN subjects s
            ON s.id = cs.subject_id
        LEFT JOIN attendance a
            ON a.student_id = st.id
            AND a.schedule_id = cs.id
            AND a.class_date = ?
        WHERE ss.schedule_id = ?
            AND s.teacher_id = ?
            AND s.status = 'active'
            AND cs.status = 'active'
            AND st.status = 'active'
        ORDER BY st.last_name, st.first_name
    ");

    $stmt->bind_param(
        'sii',
        $selectedDate,
        $selectedScheduleId,
        $teacherId
    );

    $stmt->execute();

    $attendanceRows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($attendanceRows as $row) {

        $status = strtolower(
            trim($row['attendance_status'] ?? 'no_tap')
        );

        if (isset($attendanceCounts[$status])) {
            $attendanceCounts[$status]++;
        } else {
            $attendanceCounts['no_tap']++;
        }
    }
}

$totalStudents = count($attendanceRows);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Attendance | Teacher Dashboard</title>

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
    color: var(--white);
    padding: 28px 30px;
    border-radius: 14px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    box-shadow: 0 5px 18px rgba(17,24,39,.10);
}

.page-header h1 {
    margin: 0 0 8px;
    font-size: 25px;
    font-weight: 750;
    color: var(--green);
}

.page-header p {
    margin: 0;
    color: #c0c8d5;
    font-size: 13px;
}

/* =========================================
   FILTERS
========================================= */

.filter-panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 15px;
    box-shadow: 0 4px 15px rgba(17,24,39,.04);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
}

.filter-group select,
.filter-group input {
    min-width: 180px;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

.filter-group select:focus,
.filter-group input:focus,
.search-input:focus {
    border-color: var(--green);
}

/* =========================================
   BUTTONS
========================================= */

.btn {
    padding: 12px 19px;
    background: var(--green);
    color: var(--navy);
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 750;
    cursor: pointer;
    transition: .2s;
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
    margin-bottom: 22px;
}

.stat-card {
    background: var(--white);
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
   ATTENDANCE TABLE PANEL
========================================= */

.attendance-panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 25px;
    box-shadow: 0 5px 18px rgba(17,24,39,.05);
}

.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.panel-heading h2 {
    margin: 0;
    font-size: 18px;
    color: var(--navy);
}

.panel-heading p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
}

.search-input {
    padding: 11px 14px;
    width: 240px;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline: none;
    font-size: 13px;
}

/* =========================================
   TABLE
========================================= */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

thead th {
    background: var(--navy);
    color: var(--white);
    padding: 15px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .4px;
    white-space: nowrap;
}

thead th:first-child {
    border-radius: 8px 0 0 0;
}

thead th:last-child {
    border-radius: 0 8px 0 0;
}

tbody td {
    padding: 15px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    color: var(--text);
}

tbody tr:hover {
    background: #f2fbf6;
}

/* =========================================
   STATUS
========================================= */

.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 750;
}

.status-present {
    background: #e5f9ef;
    color: #16834f;
}

.status-late {
    background: #fff5df;
    color: #b7791f;
}

.status-absent {
    background: #ffeded;
    color: #dc4545;
}

.status-no-tap {
    background: #edf0f4;
    color: #778397;
}

.empty-state {
    text-align: center;
    padding: 35px 15px;
    color: var(--muted);
    font-size: 13px;
}

/* =========================================
   ATTENDANCE INFORMATION
========================================= */

.info-box {
    background: #e9fbf2;
    border-left: 4px solid var(--green);
    padding: 15px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #236b48;
    font-size: 12px;
    line-height: 1.7;
}

/* =========================================
   RESPONSIVE
========================================= */

@media(max-width: 1100px) {

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

    .page-header h1 {
        font-size: 21px;
    }

    .filter-panel {
        align-items: stretch;
    }

    .filter-group,
    .filter-group select,
    .filter-group input {
        width: 100%;
    }

    .search-input {
        width: 100%;
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

        <div>
            <h1>Manage Attendance</h1>

            <p>
                View and monitor student attendance records
                for your assigned classes.
            </p>
        </div>

    </section>

    <!-- FILTERS -->

    <form method="GET" action="attendance.php" class="filter-panel">

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
            <strong><?= $totalStudents ?></strong>
        </div>

        <div class="stat-card present">
            <small>Present</small>
            <strong><?= $attendanceCounts['present'] ?></strong>
        </div>

        <div class="stat-card late">
            <small>Late</small>
            <strong><?= $attendanceCounts['late'] ?></strong>
        </div>

        <div class="stat-card absent">
            <small>Absent</small>
            <strong><?= $attendanceCounts['absent'] ?></strong>
        </div>

    </section>

    <!-- ATTENDANCE INFORMATION -->

    <div class="info-box">

        <strong>Attendance Information:</strong>

        Students with no attendance record remain
        "No Tap Yet" until the schedule's absence-check
        time has passed on the correct class day.

        After that time, enrolled active students without
        a tap record are automatically marked Absent.

    </div>

    <!-- ATTENDANCE TABLE -->

    <section class="attendance-panel">

        <div class="panel-heading">

            <div>
                <h2>Student Attendance Records</h2>

                <p>
                    <?= $selectedSchedule
                        ? e($selectedSchedule['subject_code'] . ' - ' .
                            $selectedSchedule['section'])
                        : 'No selected class' ?>
                    |
                    <?= e(date('F d, Y', strtotime($selectedDate))) ?>
                </p>
            </div>

            <input
                type="text"
                id="studentSearch"
                class="search-input"
                placeholder="Search student..."
            >

        </div>

        <div class="table-wrap">

            <table id="attendanceTable">

                <thead>
                    <tr>
                        <th>NO.</th>
                        <th>STUDENT NUMBER</th>
                        <th>STUDENT NAME</th>
                        <th>FIRST TAP TIME</th>
                        <th>ATTENDANCE STATUS</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($attendanceRows): ?>

                        <?php $number = 1; ?>

                        <?php foreach ($attendanceRows as $row): ?>

                            <?php
                                $status = strtolower(
                                    trim($row['attendance_status'] ?? 'no_tap')
                                );

                                $badgeClass = 'status-no-tap';

                                if ($status === 'present') {
                                    $badgeClass = 'status-present';
                                } elseif ($status === 'late') {
                                    $badgeClass = 'status-late';
                                } elseif ($status === 'absent') {
                                    $badgeClass = 'status-absent';
                                }

                                $studentName = trim(
                                    $row['first_name'] . ' ' .
                                    ($row['middle_name'] ?? '') . ' ' .
                                    $row['last_name']
                                );
                            ?>

                            <tr>

                                <td><?= $number++ ?></td>

                                <td>
                                    <?= e($row['student_number']) ?>
                                </td>

                                <td>
                                    <strong><?= e($studentName) ?></strong>
                                </td>

                                <td>
                                    <?= e(showTime($row['first_tap_time'])) ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= $badgeClass ?>">
                                        <?= e(showStatus($status)) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="empty-state">
                                No students are enrolled in this schedule yet.
                                Ask the administrator to assign students to this class.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- FOOTER -->

    <footer style="
        text-align:center;
        color:#8b98aa;
        font-size:11px;
        padding:25px 10px;
        line-height:1.7;
    ">

        NFC-Enabled Student ID-Based Room Access and Security System<br>
        Southern Philippines Institute of Science and Technology (CARBAG)

    </footer>

</main>

<script>

/* =========================================
   SEARCH STUDENT
========================================= */

const studentSearch = document.getElementById('studentSearch');

studentSearch.addEventListener('input', function() {

    const searchValue = this.value.toLowerCase();

    const rows = document.querySelectorAll(
        '#attendanceTable tbody tr'
    );

    rows.forEach(row => {

        const rowText = row.innerText.toLowerCase();

        row.style.display = rowText.includes(searchValue)
            ? ''
            : 'none';

    });

});

</script>

</body>
</html>

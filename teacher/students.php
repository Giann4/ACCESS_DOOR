
<?php
require_once 'common.php';

/* =========================================
   GET ALL STUDENTS ASSIGNED TO TEACHER
========================================= */

$stmt = $conn->prepare("
    SELECT
        st.id,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name,
        st.status,

        GROUP_CONCAT(
            DISTINCT CONCAT(s.subject_code, ' - ', s.section)
            ORDER BY s.subject_code
            SEPARATOR ', '
        ) AS enrolled_classes

    FROM schedule_students ss

    INNER JOIN students st
        ON st.id = ss.student_id

    INNER JOIN class_schedules cs
        ON cs.id = ss.schedule_id

    INNER JOIN subjects s
        ON s.id = cs.subject_id

    WHERE s.teacher_id = ?
      AND s.status = 'active'
      AND cs.status = 'active'
      AND st.status = 'active'

    GROUP BY
        st.id,
        st.student_number,
        st.first_name,
        st.middle_name,
        st.last_name,
        st.status

    ORDER BY
        st.last_name ASC,
        st.first_name ASC
");

$stmt->bind_param('i', $teacherId);
$stmt->execute();

$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$totalStudents = count($students);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Student List | Teacher Dashboard</title>

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
    font-weight: 750;
}

.page-header p {
    color: #c0c8d5;
    font-size: 13px;
    margin: 0;
}

/* =========================================
   STUDENT SUMMARY
========================================= */

.summary-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 22px 25px;
    margin-bottom: 22px;
    box-shadow: 0 4px 15px rgba(17,24,39,.04);
    display: flex;
    align-items: center;
    gap: 18px;
}

.summary-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: #e8f9f0;
    color: var(--green-dark);
    display: grid;
    place-items: center;
    font-size: 24px;
}

.summary-info small {
    color: var(--muted);
    display: block;
    font-size: 12px;
    font-weight: 650;
    margin-bottom: 6px;
}

.summary-info strong {
    color: var(--navy);
    font-size: 27px;
}

/* =========================================
   STUDENT TABLE PANEL
========================================= */

.student-panel {
    background: white;
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

/* =========================================
   SEARCH
========================================= */

.search-input {
    width: 260px;
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline: none;
    font-size: 13px;
}

.search-input:focus {
    border-color: var(--green);
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
    min-width: 750px;
}

thead th {
    background: var(--navy);
    color: white;
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

.status-active {
    display: inline-block;
    padding: 6px 12px;
    background: #e5f9ef;
    color: #16834f;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 750;
}

/* =========================================
   EMPTY STATE
========================================= */

.empty-state {
    text-align: center;
    padding: 40px 15px;
    color: var(--muted);
    font-size: 13px;
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

@media(max-width: 768px) {

    .page-content {
        margin-left: 210px;
        padding: 18px;
    }

    .page-header {
        padding: 22px;
    }

    .search-input {
        width: 100%;
    }

}

</style>

</head>

<body>

<?php include 'sidebar.php'; ?>

<main class="page-content">

    <!-- PAGE HEADER -->

    <section class="page-header">

        <h1>Student List</h1>

        <p>
            View all active students enrolled in your assigned classes.
        </p>

    </section>

    <!-- STUDENT SUMMARY -->

    <section class="summary-card">

        <div class="summary-icon">
            ♙
        </div>

        <div class="summary-info">

            <small>Total Enrolled Students</small>

            <strong><?= $totalStudents ?></strong>

        </div>

    </section>

    <!-- STUDENT LIST -->

    <section class="student-panel">

        <div class="panel-heading">

            <div>

                <h2>My Students</h2>

                <p>
                    Students from all your assigned active classes.
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

            <table id="studentsTable">

                <thead>

                    <tr>
                        <th>NO.</th>
                        <th>STUDENT NUMBER</th>
                        <th>STUDENT NAME</th>
                        <th>ENROLLED CLASSES</th>
                        <th>STATUS</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($students): ?>

                        <?php $number = 1; ?>

                        <?php foreach ($students as $student): ?>

                            <?php
                                $studentName = trim(
                                    $student['first_name'] . ' ' .
                                    ($student['middle_name'] ?? '') . ' ' .
                                    $student['last_name']
                                );
                            ?>

                            <tr>

                                <td><?= $number++ ?></td>

                                <td>
                                    <?= e($student['student_number']) ?>
                                </td>

                                <td>
                                    <strong><?= e($studentName) ?></strong>
                                </td>

                                <td>
                                    <?= e($student['enrolled_classes'] ?? '--') ?>
                                </td>

                                <td>
                                    <span class="status-active">
                                        Active
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="5" class="empty-state">
                                No active students are enrolled
                                in your assigned classes.
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
   SEARCH STUDENTS
========================================= */

const studentSearch = document.getElementById('studentSearch');

studentSearch.addEventListener('input', function() {

    const searchValue = this.value.toLowerCase();

    const rows = document.querySelectorAll(
        '#studentsTable tbody tr'
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

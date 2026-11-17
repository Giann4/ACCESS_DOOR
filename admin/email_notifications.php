<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header("Location: ../index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| FILTERS AND PAGINATION
|--------------------------------------------------------------------------
*/
$type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$allowedTypes = ['all', 'arrival', 'absence'];
$allowedStatuses = ['all', 'pending', 'sent', 'failed'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
$paramTypes = '';
if ($type !== 'all') {
    $where[] = "en.notification_type = ?";
    $params[] = $type;
    $paramTypes .= 's';
}
if ($status !== 'all') {
    $where[] = "en.status = ?";
    $params[] = $status;
    $paramTypes .= 's';
}
$whereSQL = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/
$stats = [
    'total' => 0,
    'arrival' => 0,
    'absence' => 0,
    'sent' => 0,
    'failed' => 0,
    'pending' => 0
];
$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(notification_type = 'arrival') AS arrival,
        SUM(notification_type = 'absence') AS absence,
        SUM(status = 'sent') AS sent,
        SUM(status = 'failed') AS failed,
        SUM(status = 'pending') AS pending
    FROM email_notifications
");
if ($statsResult) {
    $stats = array_merge(
        $stats,
        $statsResult->fetch_assoc()
    );
}

/*
|--------------------------------------------------------------------------
| COUNT FILTERED RECORDS
|--------------------------------------------------------------------------
*/
$countSQL = "
    SELECT COUNT(*) AS total
    FROM email_notifications en
    $whereSQL
";
$countStmt = $conn->prepare($countSQL);
if (!$countStmt) {
    die("Database error: " . e($conn->error));
}
if (!empty($params)) {
    $countStmt->bind_param($paramTypes, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRecords = (int)$countResult->fetch_assoc()['total'];
$countStmt->close();
$totalPages = max(1, (int)ceil($totalRecords / $limit));

/*
|--------------------------------------------------------------------------
| LOAD EMAIL NOTIFICATION HISTORY
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        en.id,
        en.attendance_id,
        en.recipient_email,
        en.notification_type,
        en.subject_line,
        en.message_body,
        en.status,
        en.sent_at,
        en.error_message,
        en.created_at,
        a.class_date,
        a.status AS attendance_status,
        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        sub.subject_name
    FROM email_notifications en
    LEFT JOIN attendance a
        ON a.id = en.attendance_id
    LEFT JOIN students s
        ON s.id = a.student_id
    LEFT JOIN class_schedules cs
        ON cs.id = a.schedule_id
    LEFT JOIN subjects sub
        ON sub.id = cs.subject_id
    $whereSQL
    ORDER BY en.created_at DESC, en.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Database error: " . e($conn->error));
}
$queryParams = $params;
$queryTypes = $paramTypes . 'ii';
$queryParams[] = $limit;
$queryParams[] = $offset;
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$notifications = $stmt->get_result();
$adminName = $_SESSION['full_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Email Notification History | NFC Security System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            color: #1e293b;
            min-height: 100vh;
        }
        .main-content {
            margin-left: 250px;
            padding: 30px 35px;
            min-height: 100vh;
        }
        .welcome-card {
            height: 168px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            background: #111827;
            color: #fff;
            padding: 30px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .welcome-card h2 {
            font-size: 23px;
            color: #fff;
            font-weight: 900;
            margin-bottom: 7px;
        }
        .welcome-card h2 span { color: #20d38a; }
        .welcome-card p {
            color: #d5deea;
            font-size: 12px;
        }
        .back-button {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #087443;
            padding: 11px 17px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-top: 4px solid #087443;
            border-radius: 11px;
            padding: 20px;
        }
        .stat-card h3 {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .stat-card p {
            font-size: 28px;
            font-weight: bold;
            color: #064e32;
        }
        .content-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 23px;
            margin-bottom: 20px;
        }
        .content-card h3 {
            color: #064e32;
            font-size: 19px;
            margin-bottom: 8px;
        }
        .description {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .filter-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .filter-form select {
            padding: 11px 13px;
            border: 1px solid #cbded0;
            border-radius: 7px;
            min-width: 180px;
            background: white;
            color: #173d2b;
        }
        .filter-button {
            background: #087443;
            color: white;
            border: none;
            border-radius: 7px;
            padding: 11px 20px;
            font-weight: bold;
            cursor: pointer;
        }
        .reset-button {
            background: #eaf7ef;
            color: #064e32;
            padding: 11px 18px;
            border-radius: 7px;
            text-decoration: none;
            font-weight: bold;
            font-size: 13px;
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th,
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #e5ece7;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #eff6f0;
            color: #14532d;
            white-space: nowrap;
        }
        td {
            color: #334155;
        }
        .type-badge,
        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }
        .arrival {
            background: #dcfce7;
            color: #166534;
        }
        .absence {
            background: #fee2e2;
            color: #991b1b;
        }
        .sent {
            background: #dcfce7;
            color: #166534;
        }
        .failed {
            background: #fee2e2;
            color: #991b1b;
        }
        .pending {
            background: #fef3c7;
            color: #92400e;
        }
        .error-text {
            color: #b91c1c;
            font-size: 12px;
            max-width: 220px;
            white-space: normal;
        }
        .empty {
            text-align: center;
            padding: 35px;
            color: #64748b;
        }
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .pagination-links {
            display: flex;
            gap: 7px;
        }
        .pagination-links a {
            padding: 9px 13px;
            border: 1px solid #cbded0;
            border-radius: 6px;
            text-decoration: none;
            color: #14532d;
            font-size: 13px;
        }
        .pagination-links a.active {
            background: #087443;
            color: white;
            border-color: #087443;
        }
        @media (max-width: 1000px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 650px) {
            .welcome-card { height: auto; min-height: 145px; align-items: flex-start; flex-direction: column; }
            .main-content { margin-left: 68px; padding: 16px 12px; }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .page-heading h2 {
                font-size: 23px;
            }
            .filter-form select,
            .filter-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <section class="welcome-card page-heading">
        <div>
            <h2>Email Notification <span>History</span></h2>
            <p>
                Monitor parent arrival and absence email notifications.
            </p>
        </div>
    </section>
    <!-- STATISTICS -->
    <section class="stats-grid">
        <div class="stat-card">
            <h3>Total Notifications</h3>
            <p><?= (int)$stats['total'] ?></p>
        </div>
        <div class="stat-card">
            <h3>Arrival Emails</h3>
            <p><?= (int)$stats['arrival'] ?></p>
        </div>
        <div class="stat-card">
            <h3>Absence Emails</h3>
            <p><?= (int)$stats['absence'] ?></p>
        </div>
        <div class="stat-card">
            <h3>Successfully Sent</h3>
            <p><?= (int)$stats['sent'] ?></p>
        </div>
        <div class="stat-card">
            <h3>Failed Emails</h3>
            <p><?= (int)$stats['failed'] ?></p>
        </div>
    </section>
    <section class="content-card">
        <h3>Notification Records</h3>
        <p class="description">
            View the history and delivery status of parent email notifications.
        </p>
        <!-- FILTERS -->
        <form method="GET" class="filter-form">
            <select name="type">
                <option value="all"
                    <?= $type === 'all' ? 'selected' : '' ?>>
                    All Notification Types
                </option>
                <option value="arrival"
                    <?= $type === 'arrival' ? 'selected' : '' ?>>
                    Arrival
                </option>
                <option value="absence"
                    <?= $type === 'absence' ? 'selected' : '' ?>>
                    Absence
                </option>
            </select>
            <select name="status">
                <option value="all"
                    <?= $status === 'all' ? 'selected' : '' ?>>
                    All Statuses
                </option>
                <option value="sent"
                    <?= $status === 'sent' ? 'selected' : '' ?>>
                    Sent
                </option>
                <option value="pending"
                    <?= $status === 'pending' ? 'selected' : '' ?>>
                    Pending
                </option>
                <option value="failed"
                    <?= $status === 'failed' ? 'selected' : '' ?>>
                    Failed
                </option>
            </select>
            <button type="submit" class="filter-button">
                Apply Filters
            </button>
            <a href="email_notifications.php" class="reset-button">
                Reset
            </a>
        </form>
        <!-- NOTIFICATION TABLE -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Student Number</th>
                        <th>Student Name</th>
                        <th>Subject</th>
                        <th>Parent Email</th>
                        <th>Type</th>
                        <th>Date Sent</th>
                        <th>Status</th>
                        <th>Error Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($notifications->num_rows > 0): ?>
                        <?php $number = $offset + 1; ?>
                        <?php while ($row = $notifications->fetch_assoc()): ?>
                            <?php
                            $studentName = 'Unknown Student';
                            if ($row['student_number'] !== null) {
                                $studentName = trim(
                                    $row['first_name'] . ' ' .
                                    ($row['middle_name']
                                        ? $row['middle_name'] . ' '
                                        : '') .
                                    $row['last_name']
                                );
                            }
                            ?>
                            <tr>
                                <td><?= $number++ ?></td>
                                <td>
                                    <?= e($row['student_number'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= e($studentName) ?>
                                </td>
                                <td>
                                    <?= e($row['subject_name'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= e($row['recipient_email']) ?>
                                </td>
                                <td>
                                    <span class="type-badge <?= e($row['notification_type']) ?>">
                                        <?= e(ucfirst($row['notification_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['sent_at']): ?>
                                        <?= e(date(
                                            'M d, Y h:i A',
                                            strtotime($row['sent_at'])
                                        )) ?>
                                    <?php else: ?>
                                        <span>Not sent</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= e($row['status']) ?>">
                                        <?= e(ucfirst($row['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['error_message']): ?>
                                        <div class="error-text">
                                            <?= e($row['error_message']) ?>
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty">
                                No email notification records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>
                to <?= min($offset + $limit, $totalRecords) ?>
                of <?= $totalRecords ?> records
            </span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a href="?type=<?= e($type) ?>&status=<?= e($status) ?>&page=<?= $page - 1 ?>">
                        Previous
                    </a>
                <?php endif; ?>
                <a class="active">
                    <?= $page ?>
                </a>
                <?php if ($page < $totalPages): ?>
                    <a href="?type=<?= e($type) ?>&status=<?= e($status) ?>&page=<?= $page + 1 ?>">
                        Next
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
</body>
</html>
<?php
$stmt->close();
?>


<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once '../config/db.php';

$message = '';
$messageType = 'success';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Register a card or update an existing card status */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'register') {
            $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $cardUid = strtoupper(trim($_POST['card_uid'] ?? ''));

            if (!$studentId || $cardUid === '') {
                $message = 'Please select a student and enter the NFC card UID.';
                $messageType = 'error';
            } elseif (strlen($cardUid) > 100) {
                $message = 'The card UID must not exceed 100 characters.';
                $messageType = 'error';
            } else {
                $studentStmt = $conn->prepare(
                    "SELECT id FROM students WHERE id = ? AND status = 'active'"
                );
                $studentStmt->bind_param('i', $studentId);
                $studentStmt->execute();
                $studentResult = $studentStmt->get_result();

                if ($studentResult->num_rows === 0) {
                    $message = 'Student not found or inactive.';
                    $messageType = 'error';
                } else {
                    $checkStmt = $conn->prepare(
                        "SELECT id FROM nfc_cards WHERE card_uid = ? LIMIT 1"
                    );
                    $checkStmt->bind_param('s', $cardUid);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();

                    if ($checkResult->num_rows > 0) {
                        $message = 'This NFC card UID is already registered.';
                        $messageType = 'error';
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO nfc_cards (student_id, card_uid, status)
                             VALUES (?, ?, 'active')"
                        );
                        $insertStmt->bind_param('is', $studentId, $cardUid);

                        if ($insertStmt->execute()) {
                            $message = 'NFC card registered successfully.';
                        } else {
                            $message = 'Unable to register the card. Please check the database.';
                            $messageType = 'error';
                        }

                        $insertStmt->close();
                    }

                    $checkStmt->close();
                }

                $studentStmt->close();
            }
        } elseif ($action === 'change_status') {
            $cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
            $newStatus = $_POST['status'] ?? '';

            if (!$cardId || !in_array($newStatus, ['active', 'inactive', 'lost'], true)) {
                $message = 'Invalid card or status.';
                $messageType = 'error';
            } else {
                $updateStmt = $conn->prepare(
                    "UPDATE nfc_cards SET status = ? WHERE id = ?"
                );
                $updateStmt->bind_param('si', $newStatus, $cardId);

                if ($updateStmt->execute() && $updateStmt->affected_rows >= 0) {
                    $message = 'Card status updated.';
                } else {
                    $message = 'Unable to update card status.';
                    $messageType = 'error';
                }

                $updateStmt->close();
            }
        }
    }
}

/* Get active students for the registration form */
$students = [];

$studentQuery = $conn->query(
    "SELECT id, student_number, first_name, middle_name, last_name
     FROM students
     WHERE status = 'active'
     ORDER BY last_name, first_name"
);

if ($studentQuery) {
    while ($student = $studentQuery->fetch_assoc()) {
        $students[] = $student;
    }
}

/* Search cards */
$search = trim($_GET['search'] ?? '');
$cards = [];

$sql = "SELECT nc.id, nc.student_id, nc.card_uid, nc.status, nc.registered_at,
               s.student_number, s.first_name, s.middle_name, s.last_name
        FROM nfc_cards nc
        INNER JOIN students s ON s.id = nc.student_id";

if ($search !== '') {
    $sql .= " WHERE nc.card_uid LIKE ?
              OR s.student_number LIKE ?
              OR s.first_name LIKE ?
              OR s.last_name LIKE ?";
}

$sql .= " ORDER BY nc.registered_at DESC";

if ($search !== '') {
    $stmt = $conn->prepare($sql);
    $term = '%' . $search . '%';
    $stmt->bind_param('ssss', $term, $term, $term, $term);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

if ($result) {
    while ($card = $result->fetch_assoc()) {
        $cards[] = $card;
    }
}

if (isset($stmt) && $search !== '') {
    $stmt->close();
}

/* Summary counts */
$summaryQuery = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'active'), 0) AS active,
        COALESCE(SUM(status = 'inactive'), 0) AS inactive,
        COALESCE(SUM(status = 'lost'), 0) AS lost
     FROM nfc_cards"
);

$summary = $summaryQuery ? $summaryQuery->fetch_assoc() : [];

$totalCards = (int)($summary['total'] ?? 0);
$activeCount = (int)($summary['active'] ?? 0);
$inactiveCount = (int)($summary['inactive'] ?? 0);
$lostCount = (int)($summary['lost'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFC Card Management | NFC Security System</title>
    <style>
        :root {
            --green: #28d889;
            --green-dark: #087443;
            --green-deep: #064e32;
            --background: #f4f7f5;
            --white: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --red: #b91c1c;
            --shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background);
            color: var(--text);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 30px 35px;
            transition: margin-left 0.25s ease;
        }
        .page-container {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
        }
        .page-banner {
            height: 168px;
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 30px;
            border-radius: 14px;
            background: #111827;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.10);
        }
        .page-banner h2 {
            margin: 0 0 9px;
            color: #fff;
            font-size: 23px;
            font-weight: 750;
            letter-spacing: -0.4px;
        }
        .page-banner h2 span {
            color: var(--green);
        }
        .page-banner p {
            margin: 0;
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.6;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 39px;
            padding: 10px 15px;
            border: 1px solid transparent;
            border-radius: 9px;
            background: var(--green-dark);
            color: #fff;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .button:hover {
            background: var(--green-deep);
        }
        .button-secondary {
            border-color: #d4e9dc;
            background: #f0f8f3;
            color: var(--green-deep);
        }
        .button-secondary:hover {
            background: #e2f2e8;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .summary-card {
            position: relative;
            overflow: hidden;
            padding: 22px 23px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .summary-card::before {
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--green-dark);
            content: "";
        }
        .summary-card span {
            display: block;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }
        .summary-card strong {
            color: #172b22;
            font-size: 30px;
            line-height: 1;
            font-weight: 750;
        }
        .table-toolbar {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .search-form {
            display: flex;
            width: 100%;
            gap: 8px;
            flex-wrap: wrap;
        }
        .search-form input {
            flex: 1 1 280px;
            min-width: 190px;
            min-height: 40px;
            padding: 10px 12px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
        }
        .search-form input:focus,
        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--green-dark);
            box-shadow: 0 0 0 3px rgba(40, 216, 137, 0.13);
        }
        .message {
            margin-bottom: 20px;
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
        }
        .message.success {
            border: 1px solid #bbebcc;
            background: #eaf7ef;
            color: var(--green-deep);
        }
        .message.error {
            border: 1px solid #fecdd3;
            background: #fff1f2;
            color: var(--red);
        }
        .panel {
            min-width: 0;
            margin-bottom: 24px;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: var(--white);
            box-shadow: var(--shadow);
        }
        .panel-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 18px;
        }
        .panel-heading h3 {
            margin: 0 0 6px;
            color: #172b22;
            font-size: 17px;
            font-weight: 750;
        }
        .panel-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 17px;
        }
        .form-group label {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            min-height: 43px;
            padding: 11px 13px;
            border: 1px solid #d5dfd9;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 11px;
        }
        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 13px;
            border-bottom: 1px solid #edf1ee;
            text-align: left;
            vertical-align: middle;
            font-size: 12px;
        }
        th {
            background: #f5faf6;
            color: var(--green-deep);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.35px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        td {
            color: #334155;
            line-height: 1.5;
        }
        tbody tr:last-child td {
            border-bottom: 0;
        }
        tbody tr:hover {
            background: #fbfefc;
        }
        .student-name {
            color: #1e293b;
            font-weight: 700;
        }
        .subtext {
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
        }
        .status {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }
        .status-active {
            background: #e4f8ec;
            color: #087443;
        }
        .status-inactive {
            background: #f1f5f9;
            color: #475569;
        }
        .status-lost {
            background: #fff1f2;
            color: #b91c1c;
        }
        .status-form {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .status-form select {
            min-height: 32px;
            padding: 6px 8px;
            border: 1px solid #d5dfd9;
            border-radius: 7px;
            background: #fff;
            font-family: inherit;
            font-size: 11px;
        }
        .status-form .button {
            min-height: 32px;
            padding: 7px 10px;
            font-size: 11px;
        }
        .empty-state {
            padding: 38px 15px;
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
        }
        .table-footer {
            margin-top: 13px;
            color: var(--muted);
            font-size: 11px;
        }
        .note {
            margin-top: 16px;
            padding: 12px 14px;
            border-left: 3px solid var(--green-dark);
            border-radius: 7px;
            background: #f0fdf4;
            color: var(--green-deep);
            font-size: 12px;
            line-height: 1.6;
        }
        .form-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }
        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 700px) {
            .main-content {
                margin-left: 68px;
                padding: 22px 15px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .panel {
                padding: 18px;
            }
            .search-form {
                flex-direction: column;
            }
            .search-form input,
            .search-form .button {
                width: 100%;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .status-form {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <div class="page-container">
        <section class="page-banner">
            <div>
                <h2>NFC Card <span>Management</span></h2>
                <p>Register student NFC cards and manage card assignments and status.</p>
            </div>
        </section>
        <?php if ($message !== ''): ?>
            <div class="message <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
        <div class="table-toolbar">
            <form method="GET" action="nfc_cards.php" class="search-form">
                <input
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search student, student number, or card UID..."
                    aria-label="Search NFC cards"
                >
                <button class="button" type="submit">Search</button>
                <a class="button button-secondary" href="nfc_cards.php">Reset</a>
                <a class="button" href="#register-card">+ Register NFC Card</a>
            </form>
        </div>
        <section class="summary-grid">
            <article class="summary-card">
                <span>Total NFC Cards</span>
                <strong><?= $totalCards ?></strong>
            </article>
            <article class="summary-card">
                <span>Active Cards</span>
                <strong><?= $activeCount ?></strong>
            </article>
            <article class="summary-card">
                <span>Inactive Cards</span>
                <strong><?= $inactiveCount ?></strong>
            </article>
            <article class="summary-card">
                <span>Lost Cards</span>
                <strong><?= $lostCount ?></strong>
            </article>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h3>Registered NFC Cards</h3>
                    <p>View assigned cards, search records, and update card status.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Student</th>
                            <th>Student Number</th>
                            <th>Card UID</th>
                            <th>Status</th>
                            <th>Registered Date</th>
                            <th>Update Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($cards) > 0): ?>
                        <?php $rowNumber = 1; ?>
                        <?php foreach ($cards as $card): ?>
                            <?php
                                $fullName = trim(
                                    $card['first_name'] . ' ' .
                                    ($card['middle_name'] ?? '') . ' ' .
                                    $card['last_name']
                                );
                                $statusClass = 'status-' . $card['status'];
                            ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <div class="student-name"><?= e($fullName) ?></div>
                                </td>
                                <td><?= e($card['student_number']) ?></td>
                                <td><strong><?= e($card['card_uid']) ?></strong></td>
                                <td>
                                    <span class="status <?= e($statusClass) ?>">
                                        <?= e($card['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(date(
                                        'M d, Y h:i A',
                                        strtotime($card['registered_at'])
                                    )) ?>
                                </td>
                                <td>
                                    <form method="POST" action="nfc_cards.php" class="status-form">
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >
                                        <input type="hidden" name="action" value="change_status">
                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card['id'] ?>"
                                        >
                                        <select name="status" aria-label="Card status">
                                            <option value="active" <?= $card['status'] === 'active' ? 'selected' : '' ?>>
                                                Active
                                            </option>
                                            <option value="inactive" <?= $card['status'] === 'inactive' ? 'selected' : '' ?>>
                                                Inactive
                                            </option>
                                            <option value="lost" <?= $card['status'] === 'lost' ? 'selected' : '' ?>>
                                                Lost
                                            </option>
                                        </select>
                                        <button type="submit" class="button">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                No NFC cards found.
                                <?php if ($search !== ''): ?>
                                    Try another search term.
                                <?php else: ?>
                                    Register a card using the form below.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                Showing <?= count($cards) ?> matching NFC card record(s).
            </div>
        </section>
        <section class="panel" id="register-card">
            <div class="panel-heading">
                <div>
                    <h3>Register NFC Card</h3>
                    <p>Select an active student and enter the unique UID of their NFC card.</p>
                </div>
            </div>
            <?php if (count($students) > 0): ?>
                <form method="POST" action="nfc_cards.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >
                    <input type="hidden" name="action" value="register">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_id">Select Student *</label>
                            <select id="student_id" name="student_id" required>
                                <option value="">-- Choose a student --</option>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                        $fullName = trim(
                                            $student['first_name'] . ' ' .
                                            ($student['middle_name'] ?? '') . ' ' .
                                            $student['last_name']
                                        );
                                    ?>
                                    <option value="<?= (int)$student['id'] ?>">
                                        <?= e($student['student_number']) ?> — <?= e($fullName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="card_uid">NFC Card UID *</label>
                            <input
                                type="text"
                                id="card_uid"
                                name="card_uid"
                                maxlength="100"
                                placeholder="Enter actual card UID"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button">Register Card</button>
                        <button type="reset" class="button button-secondary">Clear Form</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    No active students found.<br>
                    Register or activate a student first.
                    <br><br>
                    <a class="button" href="students.php">Student Management</a>
                </div>
            <?php endif; ?>
            <div class="note">
                <strong>Reminder:</strong> Enter the actual UID read from your NFC card or reader.
                A manually entered UID does not verify that the physical card exists.
            </div>
        </section>
    </div>
</main>
</body>
</html>

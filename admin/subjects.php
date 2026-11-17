
<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Please refresh the page and try again.');
    }
}

// Delete subject and its schedule-related records
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);

    if (!$subject_id || $subject_id < 1) {
        $error = 'Invalid subject selected.';
    } else {
        try {
            $conn->begin_transaction();

            // Remove notifications first because they reference attendance records.
            $stmt = $conn->prepare(
                "DELETE en
                 FROM email_notifications en
                 INNER JOIN attendance a ON en.attendance_id = a.id
                 INNER JOIN class_schedules cs ON a.schedule_id = cs.id
                 WHERE cs.subject_id = ?"
            );
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $stmt->close();

            // Remove attendance and student assignments belonging to this subject's schedules.
            $stmt = $conn->prepare(
                "DELETE FROM attendance
                 WHERE schedule_id IN (
                     SELECT id FROM class_schedules WHERE subject_id = ?
                 )"
            );
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE FROM schedule_students
                 WHERE schedule_id IN (
                     SELECT id FROM class_schedules WHERE subject_id = ?
                 )"
            );
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $stmt->close();

            // Delete schedules, then the subject itself.
            $stmt = $conn->prepare("DELETE FROM class_schedules WHERE subject_id = ?");
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
            $stmt->bind_param('i', $subject_id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            if ($deleted !== 1) {
                throw new RuntimeException('Subject not found.');
            }

            $conn->commit();
            header('Location: subjects.php?success=deleted');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Unable to delete the subject. No changes were saved.';
        }
    }
}

// Add subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
    $subject_name = trim($_POST['subject_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $teacher_id = !empty($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : null;

    if ($subject_code === '' || $subject_name === '' ||
        $grade_level === '' || $section === '') {
        $error = 'Please complete all required fields.';
    } else {
        // Check for duplicate subject code
        $check = $conn->prepare(
            "SELECT id FROM subjects WHERE subject_code = ? LIMIT 1"
        );
        $check->bind_param('s', $subject_code);
        $check->execute();
        $existing = $check->get_result();

        if ($existing->num_rows > 0) {
            $error = 'This subject code is already registered.';
        } else {
            if ($teacher_id === null) {
                $stmt = $conn->prepare(
                    "INSERT INTO subjects
                    (subject_code, subject_name, grade_level, section, teacher_id, status)
                    VALUES (?, ?, ?, ?, NULL, 'active')"
                );
                $stmt->bind_param(
                    'ssss',
                    $subject_code,
                    $subject_name,
                    $grade_level,
                    $section
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO subjects
                    (subject_code, subject_name, grade_level, section, teacher_id, status)
                    VALUES (?, ?, ?, ?, ?, 'active')"
                );
                $stmt->bind_param(
                    'ssssi',
                    $subject_code,
                    $subject_name,
                    $grade_level,
                    $section,
                    $teacher_id
                );
            }

            if ($stmt->execute()) {
                header('Location: subjects.php?success=added');
                exit;
            } else {
                $error = 'Unable to save the subject. Please check the database.';
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Update subject status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($subject_id > 0 && in_array($status, ['active', 'inactive'], true)) {
        $stmt = $conn->prepare(
            "UPDATE subjects SET status = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $status, $subject_id);

        if ($stmt->execute()) {
            header('Location: subjects.php?success=status');
            exit;
        } else {
            $error = 'Unable to update subject status.';
        }
        $stmt->close();
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $message = 'Subject registered successfully.';
    } elseif ($_GET['success'] === 'status') {
        $message = 'Subject status updated successfully.';
    } elseif ($_GET['success'] === 'deleted') {
        $message = 'Subject and its related class schedule records were deleted successfully.';
    }
}

// Search
$search = trim($_GET['search'] ?? '');
$search_like = '%' . $search . '%';

$stmt = $conn->prepare(
    "SELECT s.id, s.subject_code, s.subject_name, s.grade_level,
            s.section, s.status, s.created_at, s.teacher_id,
            u.full_name AS teacher_name
     FROM subjects s
     LEFT JOIN users u ON s.teacher_id = u.id AND u.role = 'teacher'
     WHERE s.subject_code LIKE ?
        OR s.subject_name LIKE ?
        OR s.grade_level LIKE ?
        OR s.section LIKE ?
        OR COALESCE(u.full_name, '') LIKE ?
     ORDER BY s.created_at DESC, s.id DESC"
);
$stmt->bind_param(
    'sssss',
    $search_like,
    $search_like,
    $search_like,
    $search_like,
    $search_like
);
$stmt->execute();
$subjects = $stmt->get_result();

// Teacher dropdown
$teachers = $conn->query(
    "SELECT id, full_name FROM users
     WHERE role = 'teacher' AND status = 'active'
     ORDER BY full_name ASC"
);

// Counts
$total_result = $conn->query("SELECT COUNT(*) AS total FROM subjects");
$total_subjects = (int) $total_result->fetch_assoc()['total'];

$active_result = $conn->query(
    "SELECT COUNT(*) AS total FROM subjects WHERE status = 'active'"
);
$active_subjects = (int) $active_result->fetch_assoc()['total'];

$inactive_subjects = $total_subjects - $active_subjects;

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subject Management | NFC Security System</title>

<style>
:root{--green:#28d889;--green-dark:#087443;--green-deep:#064e32;--bg:#f4f7f5;--text:#1e293b;--muted:#64748b;--border:#e2e8f0;--shadow:0 8px 24px rgba(15,23,42,.06)}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif}
.main-content{margin-left:250px;min-height:100vh;padding:32px}.page-container{width:100%;max-width:1500px;margin:0 auto}
.page-banner{min-height:104px;display:flex;align-items:center;margin-bottom:20px;padding:24px 26px;border-radius:14px;background:#111827;box-shadow:0 8px 20px rgba(15,23,42,.1)}
.page-banner h1{margin:0 0 7px;color:#fff;font-size:25px;font-weight:750;letter-spacing:-.4px}.page-banner h1 span{color:var(--green)}.page-banner p{margin:0;color:#cbd5e1;font-size:12px;line-height:1.6}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:10px 15px;border:1px solid transparent;border-radius:9px;background:var(--green-dark);color:#fff;font:700 12px inherit;text-decoration:none;white-space:nowrap;cursor:pointer}.button:hover{background:var(--green-deep)}
.button-secondary{border-color:#d4e9dc;background:#f0f8f3;color:var(--green-deep)}.button-secondary:hover{background:#e2f2e8}
.button-danger{border-color:#fecdd3;background:#fff1f2;color:#b91c1c}.button-danger:hover{background:#ffe4e6}
.subject-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.subject-actions form{margin:0}
.table-toolbar{margin-bottom:20px;padding:18px;border:1px solid var(--border);border-radius:13px;background:#fff;box-shadow:var(--shadow)}
.search-form{display:flex;width:100%;gap:8px;flex-wrap:wrap}.search-form input{flex:1 1 280px;min-width:190px;min-height:40px;padding:10px 12px;border:1px solid #d5dfd9;border-radius:9px;font-size:12px}
.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:24px}
.summary-card{position:relative;padding:22px 23px;border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:var(--shadow)}.summary-card:before{position:absolute;inset:0 auto 0 0;width:4px;background:var(--green-dark);content:""}
.summary-card span{display:block;margin-bottom:12px;color:var(--muted);font-size:13px;font-weight:600}.summary-card strong{color:#172b22;font-size:30px;line-height:1;font-weight:750}
.message{margin-bottom:20px;padding:13px 16px;border-radius:10px;font-size:13px;line-height:1.5}.message.success{border:1px solid #bbebcc;background:#eaf7ef;color:var(--green-deep)}.message.error{border:1px solid #fecdd3;background:#fff1f2;color:#b91c1c}
.content-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:24px}.panel{min-width:0;padding:22px;border:1px solid var(--border);border-radius:15px;background:#fff;box-shadow:var(--shadow)}
.panel-heading{margin-bottom:18px}.panel-heading h2{margin:0 0 6px;color:#172b22;font-size:17px;font-weight:750}.panel-heading p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.form-group{display:flex;flex-direction:column;gap:8px;margin-bottom:17px}.form-group label{color:#334155;font-size:12px;font-weight:700}
.form-group input,.form-group select{width:100%;min-height:43px;padding:11px 13px;border:1px solid #d5dfd9;border-radius:9px;outline:none;background:#fff;color:var(--text);font-size:13px}
input:focus,select:focus{border-color:var(--green-dark);box-shadow:0 0 0 3px rgba(40,216,137,.13)}
.table-wrap{width:100%;overflow-x:auto;border:1px solid var(--border);border-radius:11px}table{width:100%;min-width:900px;border-collapse:collapse}
th,td{padding:14px 13px;border-bottom:1px solid #edf1ee;text-align:left;vertical-align:middle;font-size:12px}th{background:#f5faf6;color:var(--green-deep);font-size:11px;font-weight:800;letter-spacing:.35px;text-transform:uppercase;white-space:nowrap}
td{color:#334155;line-height:1.5}tbody tr:last-child td{border-bottom:0}tbody tr:hover{background:#fbfefc}.subject-name{color:#1e293b;font-weight:700}.subtext{margin-top:4px;color:var(--muted);font-size:11px}
.status{display:inline-flex;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:800;text-transform:capitalize}.status-active{background:#e4f8ec;color:#087443}.status-inactive{background:#f1f5f9;color:#475569}
.status-form{display:flex;align-items:center;gap:7px}.status-form select{min-height:32px;padding:6px 8px;border:1px solid #d5dfd9;border-radius:7px;background:#fff;font-size:11px}.status-form .button{min-height:32px;padding:7px 10px;font-size:11px}
.note{margin-top:16px;padding:12px 14px;border-left:3px solid var(--green-dark);border-radius:7px;background:#f0fdf4;color:var(--green-deep);font-size:12px;line-height:1.6}
.empty-state{padding:38px 15px;color:var(--muted);text-align:center;line-height:1.7}.form-actions{display:flex;gap:9px;flex-wrap:wrap}
@media(max-width:1100px){.main-content{padding:25px}}@media(max-width:700px){.main-content{margin-left:68px;padding:22px 15px}.summary-grid{grid-template-columns:1fr;gap:12px}.panel{padding:18px}.search-form{flex-direction:column}.search-form input,.search-form .button{width:100%}.form-grid{grid-template-columns:1fr}.status-form{flex-wrap:wrap}}
</style>

</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main-content"><div class="page-container">
    <section class="page-banner">
        <div><h1>Subject <span>Management</span></h1><p>Register subjects, assign teachers, and organize grade levels and sections.</p></div>
    </section>

    <?php if ($message !== ''): ?><div class="message success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="message error"><?= e($error) ?></div><?php endif; ?>

    <div class="table-toolbar">
        <form class="search-form" method="GET" action="subjects.php">
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search subject, code, grade, section, or teacher..." aria-label="Search subjects">
            <button class="button" type="submit">Search</button>
            <a class="button button-secondary" href="subjects.php">Reset</a>
            <a class="button" href="#add-subject">+ Add Subject</a>
        </form>
    </div>

    <section class="summary-grid">
        <article class="summary-card"><span>Total Subjects</span><strong><?= $total_subjects ?></strong></article>
        <article class="summary-card"><span>Active Subjects</span><strong><?= $active_subjects ?></strong></article>
        <article class="summary-card"><span>Inactive Subjects</span><strong><?= $inactive_subjects ?></strong></article>
    </section>

    <section class="content-grid">
        <div class="panel">
            <div class="panel-heading"><h2>Registered Subjects</h2><p>View subject information, assigned teachers, and update subject status.</p></div>
            <div class="table-wrap"><table>
                <thead><tr><th>No.</th><th>Subject</th><th>Grade / Section</th><th>Teacher</th><th>Status</th><th>Registered</th><th>Update Status</th></tr></thead>
                <tbody>
                <?php if ($subjects->num_rows > 0): ?>
                    <?php $rowNumber = 1; ?>
                    <?php while ($subject = $subjects->fetch_assoc()): ?>
                    <tr>
                        <td><?= $rowNumber++ ?></td>
                        <td><div class="subject-name"><?= e($subject['subject_name']) ?></div><div class="subtext"><?= e($subject['subject_code']) ?></div></td>
                        <td><?= e($subject['grade_level']) ?><div class="subtext"><?= e($subject['section']) ?></div></td>
                        <td><?= e($subject['teacher_name'] ?? 'Not assigned') ?></td>
                        <td><span class="status status-<?= e($subject['status']) ?>"><?= e(strtoupper($subject['status'])) ?></span></td>
                        <td><?= e(date('M d, Y', strtotime($subject['created_at']))) ?></td>
                        <td><div class="subject-actions">
                            <form class="status-form" method="POST" action="subjects.php">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                            <select name="status" aria-label="Subject status">
                                <option value="active" <?= $subject['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $subject['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <button class="button" type="submit" name="update_status">Save</button>
                            </form>
                            <form method="POST" action="subjects.php" onsubmit="return confirm('Are you sure you want to permanently delete this subject? Its class schedules, student assignments, attendance records, and related email notifications will also be deleted. NFC access logs will be kept.');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                                <button class="button button-danger" type="submit" name="delete_subject">Delete</button>
                            </form>
                        </div></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="empty-state">No subjects found. <?php if ($search !== ''): ?>Try another search term.<?php else: ?>Add a subject using the form below.<?php endif; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="panel" id="add-subject">
            <div class="panel-heading"><h2>Add Subject</h2><p>Enter the subject information and optionally assign a teacher.</p></div>
            <form method="POST" action="subjects.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <div class="form-grid">
                    <div class="form-group"><label for="subject_code">Subject Code *</label><input id="subject_code" name="subject_code" required maxlength="30" placeholder="e.g. ICT-101"></div>
                    <div class="form-group"><label for="subject_name">Subject Name *</label><input id="subject_name" name="subject_name" required maxlength="150" placeholder="e.g. Empowerment Technologies"></div>
                    <div class="form-group"><label for="grade_level">Grade Level *</label><select id="grade_level" name="grade_level" required><option value="">-- Choose grade level --</option><option value="Grade 11">Grade 11</option><option value="Grade 12">Grade 12</option></select></div>
                    <div class="form-group"><label for="section">Section *</label><input id="section" name="section" required maxlength="50" placeholder="e.g. STEM 11-A"></div>
                    <div class="form-group"><label for="teacher_id">Assigned Teacher</label><select id="teacher_id" name="teacher_id"><option value="">-- No teacher assigned --</option>
                        <?php if ($teachers): ?><?php while ($teacher = $teachers->fetch_assoc()): ?><option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name']) ?></option><?php endwhile; ?><?php endif; ?>
                    </select></div>
                </div>
                <div class="form-actions"><button class="button" type="submit" name="add_subject">Add Subject</button><button class="button button-secondary" type="reset">Clear Form</button></div>
            </form>
            <div class="note"><strong>Reminder:</strong> Add the subject first. The registered subject should then become available in the class schedule form.</div>
        </div>
    </section>
</div></main>
</body>
</html>

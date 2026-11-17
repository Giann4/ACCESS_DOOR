<?php
$adminSidebarName = $_SESSION['full_name'] ?? 'Admin';
$adminSidebarInitial = strtoupper(substr(trim($adminSidebarName), 0, 1));
$currentAdminPage = basename($_SERVER['PHP_SELF']);
?>

<style>
:root {
    --navy: #111827;
    --green: #28d889;
}

.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--navy);
    color: white;
    display: flex;
    flex-direction: column;
    padding: 25px 16px;
    z-index: 1000;
    overflow-y: auto;
    box-sizing: border-box;
    font-family: Inter, "Segoe UI", Arial, sans-serif;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 10px;
    margin-bottom: 38px;
}

.brand-logo {
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--green);
    color: var(--navy);
    font-size: 20px;
    font-weight: 800;
}

.brand-name {
    font-size: 19px;
    font-weight: 800;
    white-space: nowrap;
}

.brand-name span {
    color: var(--green);
}

.menu-label {
    color: #8290a4;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    padding: 0 12px;
    margin-bottom: 12px;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 43px;
    padding: 13px 14px;
    color: #c0c8d5;
    text-decoration: none;
    font-size: 12px;
    font-weight: 650;
    border-radius: 9px;
    border-left: 4px solid transparent;
    transition: background 0.2s, color 0.2s;
}

.sidebar-nav a:hover {
    background: #202e40;
    color: var(--green);
}

.sidebar-nav a.active {
    background: #26364a;
    color: var(--green);
    border-left-color: var(--green);
}

.nav-icon {
    width: 20px;
    flex: 0 0 20px;
    text-align: center;
    font-size: 16px;
}

.sidebar-bottom {
    margin-top: auto;
    padding: 18px 10px 0;
    border-top: 1px solid #293548;
}

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
    margin-bottom: 18px;
    min-width: 0;
}

.profile-icon {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--green);
    color: var(--navy);
    font-weight: 800;
}

.profile-info small {
    display: block;
    color: #aab5c4;
    font-size: 10px;
    margin-bottom: 4px;
}

.profile-info strong {
    display: block;
    color: #fff;
    font-size: 11px;
    overflow-wrap: anywhere;
}

.logout {
    display: block;
    color: #ff7474;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    padding: 0;
}

@media (max-width: 700px) {
    .sidebar {
        width: 68px;
        padding: 20px 8px;
    }

    .sidebar-brand {
        justify-content: center;
        padding: 0;
    }

    .brand-name,
    .menu-label,
    .nav-text,
    .profile-info,
    .logout-text {
        display: none;
    }

    .sidebar-nav a {
        justify-content: center;
        padding: 13px 5px;
    }

    .sidebar-bottom {
        padding: 15px 0 0;
    }

    .profile {
        justify-content: center;
    }
}
</style>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">A</div>

        <div class="brand-name">
            NFC <span>Security</span>
        </div>
    </div>

    <div class="menu-label">MAIN MENU</div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= $currentAdminPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon">⌂</span>
            <span class="nav-text">Overview</span>
        </a>

        <a href="students.php" class="<?= $currentAdminPage === 'students.php' ? 'active' : '' ?>">
            <span class="nav-icon">♙</span>
            <span class="nav-text">Students</span>
        </a>

        <a href="teachers.php" class="<?= $currentAdminPage === 'teachers.php' ? 'active' : '' ?>">
            <span class="nav-icon">♟</span>
            <span class="nav-text">Teachers</span>
        </a>

        <a href="nfc_cards.php" class="<?= $currentAdminPage === 'nfc_cards.php' ? 'active' : '' ?>">
            <span class="nav-icon">▣</span>
            <span class="nav-text">NFC Cards</span>
        </a>

        <a href="subjects.php" class="<?= $currentAdminPage === 'subjects.php' ? 'active' : '' ?>">
            <span class="nav-icon">▤</span>
            <span class="nav-text">Subjects</span>
        </a>

        <a href="schedules.php" class="<?= $currentAdminPage === 'schedules.php' ? 'active' : '' ?>">
            <span class="nav-icon">▦</span>
            <span class="nav-text">Class Schedules</span>
        </a>

        <a href="schedule_students.php" class="<?= $currentAdminPage === 'schedule_students.php' ? 'active' : '' ?>">
            <span class="nav-icon">♧</span>
            <span class="nav-text">Student Assignments</span>
        </a>

        <a href="attendance.php" class="<?= $currentAdminPage === 'attendance.php' ? 'active' : '' ?>">
            <span class="nav-icon">✓</span>
            <span class="nav-text">Attendance & Tap Logs</span>
        </a>

        <a href="reports.php" class="<?= $currentAdminPage === 'reports.php' ? 'active' : '' ?>">
            <span class="nav-icon">▥</span>
            <span class="nav-text">Reports</span>
        </a>

        <a href="email_notifications.php" class="<?= $currentAdminPage === 'email_notifications.php' ? 'active' : '' ?>">
            <span class="nav-icon">✉</span>
            <span class="nav-text">Email Notifications</span>
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="profile-icon">
                <?= htmlspecialchars($adminSidebarInitial ?: 'A', ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="profile-info">
                <small>Logged in as</small>
                <strong><?= htmlspecialchars($adminSidebarName, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>

        <a href="../auth/logout.php" class="logout">
            ↪ <span class="logout-text">Logout</span>
        </a>
    </div>
</aside>

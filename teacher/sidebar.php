
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

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

.teacher-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: var(--navy);
    color: var(--white);
    padding: 28px 18px;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    border-right: 1px solid #293548;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 8px 35px;
}

.sidebar-logo {
    width: 39px;
    height: 39px;
    border-radius: 50%;
    background: var(--green);
    color: var(--navy);
    display: grid;
    place-items: center;
    font-size: 22px;
    font-weight: 900;
}

.sidebar-brand h2 {
    font-size: 22px;
    margin: 0;
    font-weight: 750;
    letter-spacing: -.5px;
}

.sidebar-brand h2 span {
    color: var(--green);
}

.sidebar-label {
    color: #8b98aa;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 0 12px;
    margin-bottom: 12px;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 47px;
    padding: 0 14px;
    border-radius: 9px;
    color: #c0c8d5;
    text-decoration: none;
    font-size: 13px;
    font-weight: 650;
    transition: .2s;
}

.sidebar-nav a:hover {
    color: var(--green);
    background: var(--navy-light);
}

.sidebar-nav a.active {
    color: var(--green);
    background: #263548;
    position: relative;
}

.sidebar-nav a.active::before {
    content: "";
    position: absolute;
    left: 0;
    top: 9px;
    bottom: 9px;
    width: 4px;
    border-radius: 5px;
    background: var(--green);
}

.sidebar-icon {
    width: 22px;
    text-align: center;
    font-size: 17px;
}

.sidebar-bottom {
    margin-top: auto;
    border-top: 1px solid #293548;
    padding-top: 18px;
}

.sidebar-profile {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px;
    margin-bottom: 8px;
}

.sidebar-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--green);
    color: var(--navy);
    display: grid;
    place-items: center;
    font-weight: 800;
}

.sidebar-profile-info {
    font-size: 12px;
    color: #c0c8d5;
}

.sidebar-profile-info strong {
    display: block;
    color: white;
    font-size: 13px;
    margin-top: 3px;
}

.sidebar-logout {
    display: block;
    padding: 12px;
    color: #ff7777;
    text-decoration: none;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 650;
}

.sidebar-logout:hover {
    background: #293548;
}

@media (max-width: 768px) {
    .teacher-sidebar {
        width: 210px;
        padding: 22px 12px;
    }

    .sidebar-brand h2 {
        font-size: 18px;
    }
}
</style>

<aside class="teacher-sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-logo">A</div>
        <h2>Attend<span>ance</span></h2>
    </div>

    <div class="sidebar-label">
        MAIN MENU
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php"
           class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="sidebar-icon">⌂</span>
            Overview
        </a>

        <a href="attendance.php"
           class="<?= $currentPage === 'attendance.php' ? 'active' : '' ?>">
            <span class="sidebar-icon">▤</span>
            Manage Attendance
        </a>

        <a href="students.php"
           class="<?= $currentPage === 'students.php' ? 'active' : '' ?>">
            <span class="sidebar-icon">♙</span>
            Student List
        </a>

        <a href="reports.php"
           class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>">
            <span class="sidebar-icon">▥</span>
            Reports
        </a>

    </nav>

    <div class="sidebar-bottom">

        <div class="sidebar-profile">

            <div class="sidebar-avatar">
                <?= htmlspecialchars(
                    strtoupper(substr($_SESSION['full_name'] ?? 'T', 0, 1)),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>

            <div class="sidebar-profile-info">
                Logged in as
                <strong>
                    <?= htmlspecialchars(
                        $_SESSION['full_name'] ?? 'Teacher',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>
            </div>

        </div>

        <a href="../auth/logout.php" class="sidebar-logout">
            ↪ Logout
        </a>

    </div>

</aside>

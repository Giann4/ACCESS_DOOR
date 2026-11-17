
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | NFC Security System</title>
    <link rel="stylesheet" href="style.css?v=16">
</head>
<body>

<div class="login-page">
    <div class="login-container">

        <!-- LEFT BRANDING PANEL -->
        <section class="brand-section">

            <div class="brand-top">
                <div class="brand-logo">NFC</div>
                <span class="brand-name">NFC SECURITY SYSTEM</span>
            </div>

            <div class="brand-content">

                <div class="brand-badge">
                    <span class="badge-icon">▣</span>
                    STUDENT ACCESS MANAGEMENT
                </div>

                <h1>
                    SOUTHERN PHILIPPINES
                    INSTITUTE OF SCIENCE AND
                    TECHNOLOGY
                </h1>

                <p class="brand-description">
                    Student Room Access and Security System
                </p>

                <div class="brand-divider"></div>

                <div class="security-info">
                    <span class="status-dot"></span>
                    <span>Secure Room Access Management</span>
                </div>

            </div>

            <!-- OFFICIAL SCHOOL LOGO WATERMARK -->
            <div class="brand-watermark" aria-hidden="true">
                <img src="logo2.png" alt="">
            </div>

            <div class="brand-bottom">
                <span class="campus-name">CARBAG</span>
                <span class="authorized-text">
                    Authorized School Personnel
                </span>
            </div>

        </section>

        <!-- RIGHT LOGIN PANEL -->
        <section class="login-section">

            <div class="login-form-container">

                <div class="login-header">
                    <span class="login-label">ACCOUNT ACCESS</span>
                    <h2>Welcome Back</h2>
                    <p>Sign in using your registered account.</p>
                </div>

                <form action="auth/process_login.php" method="POST">

                    <div class="input-group">
                        <label for="email">Email Address</label>

                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="m3 7 9 6 9-6"></path>
                                </svg>
                            </span>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="Enter your email address"
                                autocomplete="username"
                                required
                            >
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>

                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="5" y="10" width="14" height="11" rx="2"></rect>
                                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                                </svg>
                            </span>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                id="togglePassword"
                                aria-label="Show password"
                            >
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-button">
                        <span>Sign In</span>
                        <span class="button-arrow">→</span>
                    </button>

                </form>

                <div class="login-footer">
                    <div class="footer-line"></div>
                    <p>Authorized Admin and Teacher Access Only</p>
                    <span class="copyright">
                        NFC Security System - CARBAG
                    </span>
                </div>

            </div>

        </section>

    </div>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function () {
        const isHidden = passwordInput.type === 'password';

        passwordInput.type = isHidden ? 'text' : 'password';

        this.setAttribute(
            'aria-label',
            isHidden ? 'Hide password' : 'Show password'
        );
    });
</script>

<script src="script.js"></script>

</body>
</html>

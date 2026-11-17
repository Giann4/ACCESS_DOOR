
/* ========================================
   NFC SECURITY SYSTEM
   LOGIN PAGE JAVASCRIPT
======================================== */

// Prevent submitting the login form with blank fields
document.addEventListener("DOMContentLoaded", function () {

    const loginForm = document.querySelector(".login-section form");
    const emailInput = document.getElementById("email");
    const passwordInput = document.getElementById("password");

    if (!loginForm || !emailInput || !passwordInput) {
        return;
    }

    loginForm.addEventListener("submit", function (event) {

        const email = emailInput.value.trim();
        const password = passwordInput.value;

        if (email === "" || password.trim() === "") {
            event.preventDefault();
            alert("Please enter your email address and password.");
            return;
        }

        const loginButton = loginForm.querySelector(".login-button");

        if (loginButton) {
            loginButton.disabled = true;
            loginButton.textContent = "SIGNING IN...";
        }

    });

});

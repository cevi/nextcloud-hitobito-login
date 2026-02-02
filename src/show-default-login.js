/**
 * Show default login form when clicking the "Mit Benutzername & Passwort anmelden" button
 */
document.addEventListener('DOMContentLoaded', function() {
    // Find the button with href="#body-login"
    const showLoginButton = document.querySelector('a[href="#body-login"]');
    
    if (showLoginButton) {
        showLoginButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get the body-login element
            const bodyLogin = document.getElementById('body-login');
            
            if (bodyLogin) {
                // Add class to show the login form
                bodyLogin.classList.add('show-default-login');
            }
        });
    }
});

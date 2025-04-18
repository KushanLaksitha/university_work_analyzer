document.addEventListener('DOMContentLoaded', function() {
    // Password visibility toggle
    const togglePassword = document.querySelector('.toggle-password');
    const passwordField = document.querySelector('#password');
    
    if (togglePassword && passwordField) {
        togglePassword.addEventListener('click', function() {
            // Toggle the password field type
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            
            // Toggle the eye icon
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
    }
    
    // Form validation
    const loginForm = document.getElementById('loginForm');
    const emailField = document.getElementById('email');
    const loginButton = document.getElementById('loginButton');
    
    if (loginForm) {
        loginForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Basic email validation
            if (emailField && emailField.value) {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailField.value)) {
                    emailField.classList.add('is-invalid');
                    if (!document.getElementById('email-feedback')) {
                        const feedback = document.createElement('div');
                        feedback.id = 'email-feedback';
                        feedback.className = 'invalid-feedback';
                        feedback.innerText = 'Please enter a valid email address.';
                        emailField.parentNode.appendChild(feedback);
                    }
                    isValid = false;
                } else {
                    emailField.classList.remove('is-invalid');
                    const feedback = document.getElementById('email-feedback');
                    if (feedback) feedback.remove();
                }
            }
            
            // If the form is invalid, prevent submission
            if (!isValid) {
                event.preventDefault();
            } else if (loginButton) {
                // Show loading state
                loginButton.disabled = true;
                loginButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Signing in...';
            }
        });
    }
    
    // Reset form validation when typing
    if (emailField) {
        emailField.addEventListener('input', function() {
            this.classList.remove('is-invalid');
            const feedback = document.getElementById('email-feedback');
            if (feedback) feedback.remove();
        });
    }
    
    // Add "Remember Me" functionality
    const rememberMeCheckbox = document.getElementById('rememberMe');
    
    // Check if email is stored in localStorage
    if (rememberMeCheckbox && emailField && localStorage.getItem('rememberedEmail')) {
        emailField.value = localStorage.getItem('rememberedEmail');
        rememberMeCheckbox.checked = true;
    }
    
    // Save email to localStorage if "Remember Me" is checked
    if (loginForm && rememberMeCheckbox) {
        loginForm.addEventListener('submit', function() {
            if (rememberMeCheckbox.checked && emailField.value) {
                localStorage.setItem('rememberedEmail', emailField.value);
            } else {
                localStorage.removeItem('rememberedEmail');
            }
        });
    }
    
    // Add simple animation when focusing on input fields
    const formControls = document.querySelectorAll('.form-control');
    formControls.forEach(function(control) {
        control.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
            this.parentElement.style.transition = 'transform 0.3s ease';
        });
        
        control.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });
});
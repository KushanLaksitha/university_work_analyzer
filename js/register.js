document.addEventListener('DOMContentLoaded', function() {
    // Password visibility toggle
    const togglePasswordButtons = document.querySelectorAll('.toggle-password');
    
    togglePasswordButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const passwordField = this.previousElementSibling;
            // Toggle the password field type
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            
            // Toggle the eye icon
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
    });
    
    // Form validation
    const registerForm = document.getElementById('registerForm');
    const passwordField = document.getElementById('password');
    const confirmPasswordField = document.getElementById('confirm_password');
    const emailField = document.getElementById('email');
    const registerButton = document.getElementById('registerButton');
    const termsCheckbox = document.getElementById('terms');
    
    if (registerForm) {
        registerForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Password validation
            if (passwordField && passwordField.value) {
                if (passwordField.value.length < 8) {
                    passwordField.classList.add('is-invalid');
                    if (!document.getElementById('password-feedback')) {
                        const feedback = document.createElement('div');
                        feedback.id = 'password-feedback';
                        feedback.className = 'invalid-feedback';
                        feedback.innerText = 'Password must be at least 8 characters long.';
                        passwordField.parentNode.appendChild(feedback);
                    }
                    isValid = false;
                } else {
                    passwordField.classList.remove('is-invalid');
                    const feedback = document.getElementById('password-feedback');
                    if (feedback) feedback.remove();
                }
            }
            
            // Password confirmation validation
            if (passwordField && confirmPasswordField && (passwordField.value !== confirmPasswordField.value)) {
                confirmPasswordField.classList.add('is-invalid');
                if (!document.getElementById('confirm-password-feedback')) {
                    const feedback = document.createElement('div');
                    feedback.id = 'confirm-password-feedback';
                    feedback.className = 'invalid-feedback';
                    feedback.innerText = 'Passwords do not match.';
                    confirmPasswordField.parentNode.appendChild(feedback);
                }
                isValid = false;
            } else {
                confirmPasswordField.classList.remove('is-invalid');
                const feedback = document.getElementById('confirm-password-feedback');
                if (feedback) feedback.remove();
            }
            
            // Email validation
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
            
            // Terms agreement validation
            if (termsCheckbox && !termsCheckbox.checked) {
                termsCheckbox.classList.add('is-invalid');
                if (!document.getElementById('terms-feedback')) {
                    const feedback = document.createElement('div');
                    feedback.id = 'terms-feedback';
                    feedback.className = 'invalid-feedback';
                    feedback.innerText = 'You must agree to the Terms and Conditions.';
                    termsCheckbox.parentNode.appendChild(feedback);
                }
                isValid = false;
            } else {
                termsCheckbox.classList.remove('is-invalid');
                const feedback = document.getElementById('terms-feedback');
                if (feedback) feedback.remove();
            }
            
            // If the form is invalid, prevent submission
            if (!isValid) {
                event.preventDefault();
            } else if (registerButton) {
                // Show loading state
                registerButton.disabled = true;
                registerButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Creating account...';
            }
        });
    }
    
    // Real-time password match validation
    if (confirmPasswordField) {
        confirmPasswordField.addEventListener('input', function() {
            if (passwordField.value !== this.value) {
                this.classList.add('is-invalid');
                if (!document.getElementById('confirm-password-feedback')) {
                    const feedback = document.createElement('div');
                    feedback.id = 'confirm-password-feedback';
                    feedback.className = 'invalid-feedback';
                    feedback.innerText = 'Passwords do not match.';
                    this.parentNode.appendChild(feedback);
                }
            } else {
                this.classList.remove('is-invalid');
                const feedback = document.getElementById('confirm-password-feedback');
                if (feedback) feedback.remove();
                
                this.classList.add('is-valid');
            }
        });
    }
    
    // Password strength indicator
    if (passwordField) {
        passwordField.addEventListener('input', function() {
            // Remove existing feedback
            const existingFeedback = document.getElementById('password-strength');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            if (this.value.length > 0) {
                // Create password strength element
                const strengthElement = document.createElement('div');
                strengthElement.id = 'password-strength';
                strengthElement.className = 'mt-2';
                
                // Calculate password strength
                let strength = 0;
                const password = this.value;
                
                // Length check
                if (password.length >= 8) strength += 1;
                if (password.length >= 12) strength += 1;
                
                // Complexity checks
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[a-z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^A-Za-z0-9]/.test(password)) strength += 1;
                
                // Set colors and messages based on strength
                let strengthClass = '';
                let strengthText = '';
                
                switch(true) {
                    case (strength <= 2):
                        strengthClass = 'text-danger';
                        strengthText = 'Weak password';
                        break;
                    case (strength <= 4):
                        strengthClass = 'text-warning';
                        strengthText = 'Moderate password';
                        break;
                    default:
                        strengthClass = 'text-success';
                        strengthText = 'Strong password';
                }
                
                // Create progress bar
                const progressBar = document.createElement('div');
                progressBar.className = 'progress';
                progressBar.style.height = '5px';
                
                const progress = document.createElement('div');
                progress.className = `progress-bar bg-${strengthClass.replace('text-', '')}`;
                progress.style.width = `${(strength / 6) * 100}%`;
                
                progressBar.appendChild(progress);
                
                // Add text
                const strengthText_el = document.createElement('small');
                strengthText_el.className = strengthClass;
                strengthText_el.textContent = strengthText;
                
                // Append elements
                strengthElement.appendChild(progressBar);
                strengthElement.appendChild(document.createElement('br'));
                strengthElement.appendChild(strengthText_el);
                
                // Add to form
                this.parentNode.parentNode.appendChild(strengthElement);
            }
        });
    }
    
    // Terms and conditions modal
    const acceptTermsBtn = document.getElementById('acceptTerms');
    if (acceptTermsBtn && termsCheckbox) {
        acceptTermsBtn.addEventListener('click', function() {
            termsCheckbox.checked = true;
            termsCheckbox.classList.remove('is-invalid');
            const feedback = document.getElementById('terms-feedback');
            if (feedback) feedback.remove();
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
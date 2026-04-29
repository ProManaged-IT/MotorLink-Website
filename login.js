// ============================================================================
// MotorLink Login Functionality
// ============================================================================
// Handles user authentication and login form interactions
// Uses global CONFIG from config.js for API endpoints
// ============================================================================

document.addEventListener('DOMContentLoaded', function() {
    // Redirect already-authenticated users away from the login page
    if (localStorage.getItem('motorlink_authenticated') === 'true') {
        fetch(`${CONFIG.API_URL}?action=check_auth`, { credentials: 'include' })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.authenticated) {
                    const params = new URLSearchParams(window.location.search);
                    const redir = params.get('redirect');
                    window.location.replace(
                        (redir && !/^https?:|^\/\//i.test(redir)) ? redir : 'index.html'
                    );
                }
            })
            .catch(() => {}); // silently fail — user stays on login page
    }

    const loginForm = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');
    const buttonText = document.getElementById('buttonText');
    const loginSpinner = document.getElementById('loginSpinner');
    const passwordToggle = document.getElementById('passwordToggle');

    // Password toggle functionality
    if (passwordToggle) {
        passwordToggle.addEventListener('click', togglePassword);
    }
    
    // Forgot password link navigates to forgot-password.html (handled by href)

    // Form submission
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!validateForm()) {
                return;
            }
            
            await performLogin();
        });
    }
    
    // Real-time validation
    const emailInput = document.getElementById('emailInput');
    const passwordInput = document.getElementById('passwordInput');
    
    if (emailInput) emailInput.addEventListener('input', clearError);
    if (passwordInput) passwordInput.addEventListener('input', clearError);
});

function validateForm() {
    const email = document.getElementById('emailInput').value.trim();
    const password = document.getElementById('passwordInput').value;
    let isValid = true;
    
    // Clear previous errors
    clearErrors();
    
    // Email validation
    if (!email) {
        showError('emailError', 'Email address is required');
        isValid = false;
    } else if (!isValidEmail(email)) {
        showError('emailError', 'Please enter a valid email address');
        isValid = false;
    }
    
    // Password validation
    if (!password) {
        showError('passwordError', 'Password is required');
        isValid = false;
    } else if (password.length < 6) {
        showError('passwordError', 'Password must be at least 6 characters');
        isValid = false;
    }
    
    return isValid;
}

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

function showError(elementId, message) {
    const errorElement = document.getElementById(elementId);
    if (errorElement) {
        errorElement.querySelector('span').textContent = message;
        errorElement.style.display = 'flex';
    }
}

function clearError(e) {
    const errorMap = {
        emailInput: 'emailError',
        passwordInput: 'passwordError'
    };
    const errorElement = document.getElementById(errorMap[e.target.id]);
    if (errorElement) {
        errorElement.style.display = 'none';
    }
}

function clearErrors() {
    document.querySelectorAll('.error-message').forEach(error => {
        error.style.display = 'none';
    });
}

async function performLogin() {
    const email = document.getElementById('emailInput').value.trim();
    const password = document.getElementById('passwordInput').value;
    const rememberMe = document.getElementById('rememberMe').checked;
    const loginButton = document.getElementById('loginButton');
    const buttonText = document.getElementById('buttonText');
    const loginSpinner = document.getElementById('loginSpinner');
    
    if (!loginButton || !buttonText || !loginSpinner) {
        return;
    }
    
    // Show loading state
    loginButton.disabled = true;
    buttonText.textContent = 'Logging in...';
    loginSpinner.style.display = 'inline-block';
    
    try {
        const recaptchaToken = typeof window.getRecaptchaToken === 'function'
            ? await window.getRecaptchaToken('login')
            : '';
        
        const response = await fetch(`${CONFIG.API_URL}?action=login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                email: email,
                password: password,
                remember: rememberMe,
                recaptcha_token: recaptchaToken,
                recaptcha_action: 'login'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Login successful — store user data for session persistence
            if (data.user) {
                localStorage.setItem('motorlink_user', JSON.stringify(data.user));
                localStorage.setItem('motorlink_authenticated', 'true');
            }

            const urlParams = new URLSearchParams(window.location.search);
            const redirect = urlParams.get('redirect');

            // Only allow relative in-site redirects to prevent open-redirect attacks
            if (redirect && !/^https?:|^\/\//i.test(redirect)) {
                window.location.href = redirect;
            } else {
                window.location.href = 'index.html';
            }

        } else {
            // Surface the server's error message directly
            throw new Error(data.message || 'Login failed. Please check your credentials.');
        }
        
    } catch (error) {
        // Use the API's error message; fall back to a network error message
        let userMessage = (error && error.message) ? error.message : 'Login failed. Please try again.';
        if (!navigator.onLine || error instanceof TypeError) {
            userMessage = 'Network error. Please check your internet connection.';
        }

        showToast(userMessage, 'error');

        // Reset button state
        loginButton.disabled = false;
        buttonText.textContent = 'Login to Account';
        loginSpinner.style.display = 'none';
    }
}

function togglePassword() {
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('passwordToggleIcon');
    
    if (!passwordInput || !toggleIcon) return;
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'fas fa-eye';
    }
}

function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };
    
    const colors = {
        success: '#28a745',
        error: '#dc3545',
        warning: '#ffc107',
        info: '#17a2b8'
    };
    
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="${icons[type]}" style="color: ${colors[type]}; font-size: 16px;"></i>
            <span style="flex: 1; line-height: 1.4;">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #999; margin-left: auto;">×</button>
        </div>
    `;
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        padding: 16px 20px;
        border-radius: 12px;
        box-shadow: 0 6px 25px rgba(0,0,0,0.15);
        z-index: 10000;
        border-left: 4px solid ${colors[type]};
        max-width: 350px;
        font-size: 14px;
        animation: slideInRight 0.4s ease;
    `;
    
    // Add animation styles if not already present
    if (!document.querySelector('#toast-styles')) {
        const style = document.createElement('style');
        style.id = 'toast-styles';
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 5000);
}
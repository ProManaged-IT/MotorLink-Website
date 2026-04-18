// MotorLink Business Onboarding

// Derives the admin API URL from the same environment logic used by admin-script.js
const getAdminAPIUrl = () => {
    const hostname = window.location.hostname;
    const protocol = window.location.protocol;
    const checkIsLocal = hostname === 'localhost' ||
                         hostname === '127.0.0.1' ||
                         protocol === 'file:' ||
                         hostname.startsWith('192.168.') ||
                         hostname.startsWith('10.') ||
                         /^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(hostname);
    const checkIsProduction = !checkIsLocal && hostname !== '' &&
                               !hostname.includes('localhost') &&
                               !hostname.includes('127.0.0.1');
    if (checkIsProduction) {
        return '/motorlink/admin/admin-api.php';
    }
    if (typeof CONFIG !== 'undefined' && CONFIG.MODE === 'UAT') {
        if (checkIsLocal) {
            return `${window.location.origin}/proxy.php?endpoint=admin-api`;
        }
        return '/motorlink/admin/admin-api.php';
    }
    return 'admin-api.php';
};

// API URL configuration - same pattern as admin and main website
const getOnboardingAPIUrl = () => {
    // Check if running on localhost
    const hostname = window.location.hostname;
    const protocol = window.location.protocol;
    const port = window.location.port;
    
    // Comprehensive local environment detection (RFC 1918 compliant)
    const checkIsLocal = hostname === 'localhost' || 
                         hostname === '127.0.0.1' || 
                         protocol === 'file:' ||
                         hostname.startsWith('192.168.') ||
                         hostname.startsWith('10.') ||
                         // Check for 172.16.0.0/12 range (172.16.0.0 - 172.31.255.255)
                         /^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(hostname);
    
    // Production: Any non-localhost hostname (flexible for any domain)
    const checkIsProduction = !checkIsLocal && 
                               hostname !== '' && 
                               !hostname.includes('localhost') && 
                               !hostname.includes('127.0.0.1');
    
    if (checkIsProduction) {
        // Production mode: use relative path since we're on the same server
        return '/motorlink/onboarding/api-onboarding.php';
    }
    
    // Check CONFIG if available, otherwise auto-detect
    if (typeof CONFIG !== 'undefined') {
        if (CONFIG.MODE === 'UAT') {
            if (checkIsLocal) {
                // Local development: keep the same host as the page origin to avoid CORS
                // issues when credentials are included (localhost vs 127.0.0.1 mismatch).
                const localHost = hostname;
                const currentPort = port || '8000';
                return `${protocol}//${localHost}:${currentPort}/onboarding/api-onboarding.php`;
            } else {
                // UAT on production server: use local onboarding API
                return '/motorlink/onboarding/api-onboarding.php';
            }
        } else {
            // Production mode: use full path
            return 'https://promanaged-it.com/motorlink/onboarding/api-onboarding.php';
        }
    }
    
    // Fallback: auto-detect based on hostname
    if (checkIsLocal) {
        const localHost = hostname;
        const currentPort = port || '8000';
        return `${protocol}//${localHost}:${currentPort}/onboarding/api-onboarding.php`;
    }
    
    // Default: use relative path
    return '/motorlink/onboarding/api-onboarding.php';
};

class OnboardingForm {
    constructor() {
        this.currentStep = 1;
        this.totalSteps = 5;
        this.businessType = '';
        this.formData = {};
        this.currentAdmin = null;
        this.duplicateChecked = false;
        this.duplicateExists = false;
        this.duplicateApproved = false;
        this.API_BASE_URL = getOnboardingAPIUrl();
        this.STORAGE_KEY = 'motorlink_onboarding_progress';
        this.STORAGE_EXPIRY = 24 * 60 * 60 * 1000; // 24 hours
        this.init();
    }

    getFirstExistingField(idCandidates = [], nameCandidate = '') {
        for (const id of idCandidates) {
            const el = document.getElementById(id);
            if (el) return el;
        }

        if (nameCandidate) {
            return document.querySelector(`[name="${nameCandidate}"]`);
        }

        return null;
    }

    async init() {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Initializing onboarding form...');
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('API URL:', this.API_BASE_URL);
        }

        const hasAccess = await this.verifyPortalAccess();
        if (!hasAccess) {
            return;
        }

        this.setupEventListeners();
        this.setupQuickActions();
        this.loadInitialData();
        this.restoreProgress(); // Try to restore saved progress
        this.showStep(this.currentStep);
    }

    async verifyPortalAccess() {
        const allowedRoles = ['super_admin', 'admin', 'onboarding_manager'];
        const adminApiUrl = getAdminAPIUrl();
        const sep = adminApiUrl.includes('?') ? '&' : '?';

        try {
            const response = await fetch(`${adminApiUrl}${sep}action=check_admin_auth`, {
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`Auth check failed with HTTP ${response.status}`);
            }

            const data = await response.json();
            if (data.success && data.authenticated && allowedRoles.includes(data.admin?.role)) {
                this.currentAdmin = data.admin || null;
                // Show admin badge in header
                const badge = document.getElementById('onboardingAdminBadge');
                const nameEl = document.getElementById('onboardingAdminName');
                const logoutBtn = document.getElementById('onboardingLogoutBtn');
                if (badge && nameEl && data.admin?.name) {
                    nameEl.textContent = data.admin.name;
                    badge.style.display = '';
                }
                if (logoutBtn) {
                    logoutBtn.style.display = 'inline-flex';
                }
                return true;
            }

            this.renderAccessDenied();
            return false;
        } catch (error) {
            this.renderAccessDenied('We could not verify onboarding access right now. Please sign in with an internal onboarding or admin account.');
            return false;
        }
    }

    validatePasswordStrength(password) {
        if (!password || password.length < 8) {
            return 'Password must be at least 8 characters long.';
        }
        if (!/[A-Z]/.test(password)) {
            return 'Password must include at least one uppercase letter.';
        }
        if (!/[0-9]/.test(password)) {
            return 'Password must include at least one number.';
        }
        if (!/[^A-Za-z0-9]/.test(password)) {
            return 'Password must include at least one special character (e.g. !@#$%).';
        }
        return null; // valid
    }

    renderAccessDenied(message = 'This portal is restricted to internal onboarding managers and administrators.') {
        const container = document.querySelector('.form-container');
        if (!container) {
            return;
        }

        container.innerHTML = `
            <div class="form-step active" style="display:block; text-align:center; padding:48px 24px;">
                <div style="width:72px; height:72px; border-radius:50%; background:#ecfdf5; color:#0f6d37; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto; font-size:30px;">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h2 style="margin-bottom:12px;">Restricted Portal</h2>
                <p style="max-width:560px; margin:0 auto 24px auto; color:#5f6b66; line-height:1.6;">${message}</p>
                <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                    <a class="btn btn-primary" href="../admin/admin.html">
                        <i class="fas fa-sign-in-alt"></i> Go to Admin Login
                    </a>
                    <a class="btn btn-secondary" href="../register.html">
                        <i class="fas fa-arrow-left"></i> Back to Register
                    </a>
                </div>
            </div>
        `;
    }

    async logoutAndKillSession() {
        const adminApiUrl = getAdminAPIUrl();
        const sep = adminApiUrl.includes('?') ? '&' : '?';
        const logoutBtn = document.getElementById('onboardingLogoutBtn');
        const originalHtml = logoutBtn ? logoutBtn.innerHTML : '';

        if (logoutBtn) {
            logoutBtn.disabled = true;
            logoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing out...';
        }

        try {
            await fetch(`${adminApiUrl}${sep}action=admin_logout`, {
                method: 'GET',
                credentials: 'include'
            });
        } catch (error) {
            // Even if request fails, continue redirect to avoid a stuck UI.
        }

        this.currentAdmin = null;
        this.clearProgress();
        window.location.href = '../admin/admin.html';

        if (logoutBtn) {
            logoutBtn.disabled = false;
            logoutBtn.innerHTML = originalHtml;
        }
    }
    
    // Save form progress to localStorage
    saveProgress() {
        try {
            const form = document.getElementById('onboardingForm');
            const data = {};

            // Persist each form control explicitly so grouped checkboxes/radios restore correctly.
            const fields = form.querySelectorAll('input, select, textarea');
            fields.forEach(field => {
                if (!field.name) return;

                if (field.type === 'checkbox') {
                    if (!Array.isArray(data[field.name])) {
                        data[field.name] = [];
                    }
                    if (field.checked) {
                        data[field.name].push(field.value);
                    }
                    return;
                }

                if (field.type === 'radio') {
                    if (field.checked) {
                        data[field.name] = field.value;
                    }
                    return;
                }

                data[field.name] = field.value;
            });
            
            const progressData = {
                step: this.currentStep,
                businessType: this.businessType,
                formData: data,
                timestamp: Date.now()
            };
            
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(progressData));
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Progress saved');
        } catch (error) {
            console.error('Error saving progress:', error);
        }
    }
    
    // Restore saved progress
    restoreProgress() {
        try {
            const saved = localStorage.getItem(this.STORAGE_KEY);
            if (!saved) return;
            
            const progressData = JSON.parse(saved);
            const age = Date.now() - progressData.timestamp;
            
            // Check if saved data is still valid (less than 24 hours old)
            if (age > this.STORAGE_EXPIRY) {
                localStorage.removeItem(this.STORAGE_KEY);
                return;
            }
            
            // Ask user if they want to restore
            if (confirm('Continue your previous application?\n\nWe found a saved application from ' + this.formatTimeAgo(age) + '.')) {
                // Restore form data
                const form = document.getElementById('onboardingForm');
                
                Object.keys(progressData.formData).forEach(key => {
                    const elements = form.querySelectorAll(`[name="${key}"]`);
                    if (!elements || elements.length === 0) {
                        return;
                    }

                    const first = elements[0];
                    const savedValue = progressData.formData[key];

                    if (first.type === 'checkbox') {
                        const selectedValues = Array.isArray(savedValue)
                            ? savedValue.map(String)
                            : [];
                        elements.forEach(el => {
                            el.checked = selectedValues.includes(String(el.value));
                        });
                        return;
                    }

                    if (first.type === 'radio') {
                        elements.forEach(el => {
                            el.checked = String(el.value) === String(savedValue);
                        });
                        return;
                    }

                    first.value = savedValue;
                });
                
                // Restore business type
                if (progressData.businessType) {
                    this.businessType = progressData.businessType;
                    document.getElementById('businessType').value = progressData.businessType;
                    
                    // Highlight selected business option
                    const selectedOption = document.querySelector(`.business-option[data-type="${progressData.businessType}"]`);
                    if (selectedOption) {
                        selectedOption.classList.add('selected');
                    }

                    this.handleBusinessTypeChange(progressData.businessType);
                }
                
                // Restore step
                this.currentStep = progressData.step || 1;

                this.updateCarHireEventTypesVisibility();
                
                alert('Your previous application has been restored. You can continue from where you left off.');
            } else {
                // User declined, clear saved data
                localStorage.removeItem(this.STORAGE_KEY);
            }
        } catch (error) {
            console.error('Error restoring progress:', error);
            localStorage.removeItem(this.STORAGE_KEY);
        }
    }
    
    // Format time ago for display
    formatTimeAgo(ms) {
        const minutes = Math.floor(ms / 60000);
        const hours = Math.floor(minutes / 60);
        
        if (hours > 0) {
            return hours === 1 ? '1 hour ago' : `${hours} hours ago`;
        } else if (minutes > 0) {
            return minutes === 1 ? '1 minute ago' : `${minutes} minutes ago`;
        } else {
            return 'just now';
        }
    }
    
    // Clear saved progress (call after successful submission)
    clearProgress() {
        localStorage.removeItem(this.STORAGE_KEY);
    }

    setupEventListeners() {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Setting up event listeners...');
        
        // Business type selection - COMPLETELY FIXED
        document.querySelectorAll('.business-option').forEach(option => {
            option.addEventListener('click', (e) => {
                if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Business option clicked:', option);
                this.selectBusinessType(option);
            });
        });

        // Navigation buttons
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                this.previousStep();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                this.nextStep();
            });
        }

        if (submitBtn) {
            submitBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.submitForm();
            });
        }

        const logoutBtn = document.getElementById('onboardingLogoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async () => {
                const confirmed = confirm('Sign out and end this onboarding session?');
                if (!confirmed) return;
                await this.logoutAndKillSession();
            });
        }

        // Modal buttons
        document.getElementById('newBusinessBtn')?.addEventListener('click', () => {
            // Reload page to start fresh
            window.location.reload();
        });

        document.getElementById('closeBtn')?.addEventListener('click', () => {
            document.getElementById('successModal').style.display = 'none';
            // Reload page to prevent duplicate submissions
            window.location.reload();
        });

        // Handle clicking outside the success modal to close it
        const successModal = document.getElementById('successModal');
        if (successModal) {
            successModal.addEventListener('click', (e) => {
                // If clicking on the modal backdrop (not the content), close and reload
                if (e.target === successModal) {
                    successModal.style.display = 'none';
                    window.location.reload();
                }
            });
        }

        document.getElementById('cancelSubmitBtn')?.addEventListener('click', () => {
            document.getElementById('duplicateModal').style.display = 'none';
            this.duplicateApproved = false;
        });

        document.getElementById('continueSubmitBtn')?.addEventListener('click', () => {
            document.getElementById('duplicateModal').style.display = 'none';
            this.duplicateChecked = true;
            this.duplicateApproved = true;
            this.submitForm();
        });

        // Duplicate check
        document.getElementById('checkDuplicateBtn')?.addEventListener('click', () => {
            this.checkForDuplicates();
        });

        // Real-time validation
        document.addEventListener('input', (e) => {
            if (e.target.matches('input, select, textarea')) {
                this.validateCurrentStep();
                // Real-time validation for specific fields
                this.validateFieldOnInput(e.target);
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.type === 'checkbox' || e.target.type === 'radio' || e.target.tagName === 'SELECT') {
                this.validateCurrentStep();
            }
            // Keep event types visibility and state consistent with hire category.
            if (e.target.id === 'hire_category') {
                this.updateCarHireEventTypesVisibility();
            }
        });

        this.updateCarHireEventTypesVisibility();

        // Add specific event listeners for email and phone (with debouncing)
        const emailField = document.getElementById('email');
        const phoneField = document.getElementById('phone');
        const businessNameField = document.getElementById('businessName');
        
        if (emailField) {
            let emailTimeout;
            emailField.addEventListener('input', (e) => {
                clearTimeout(emailTimeout);
                emailTimeout = setTimeout(() => {
                    this.validateEmailField(e.target);
                    this.checkEmailOrPhoneDuplicate('email', e.target.value);
                }, 500); // Debounce 500ms
            });
            emailField.addEventListener('blur', () => {
                this.validateEmailField(emailField);
                if (emailField.value) {
                    this.checkEmailOrPhoneDuplicate('email', emailField.value);
                }
            });
        }
        
        if (phoneField) {
            let phoneTimeout;
            phoneField.addEventListener('input', (e) => {
                clearTimeout(phoneTimeout);
                phoneTimeout = setTimeout(() => {
                    this.validatePhoneField(e.target);
                    this.checkEmailOrPhoneDuplicate('phone', e.target.value);
                }, 500); // Debounce 500ms
            });
            phoneField.addEventListener('blur', () => {
                this.validatePhoneField(phoneField);
                if (phoneField.value) {
                    this.checkEmailOrPhoneDuplicate('phone', phoneField.value);
                }
            });
        }

        // Add business name duplicate checking
        if (businessNameField) {
            let businessNameTimeout;
            businessNameField.addEventListener('input', (e) => {
                clearTimeout(businessNameTimeout);
                businessNameTimeout = setTimeout(() => {
                    if (e.target.value && this.businessType) {
                        this.checkBusinessNameDuplicate(e.target.value);
                    }
                }, 500); // Debounce 500ms
            });
            businessNameField.addEventListener('blur', () => {
                if (businessNameField.value && this.businessType) {
                    this.checkBusinessNameDuplicate(businessNameField.value);
                }
            });
        }

        // Validate optional fields on blur
        const optionalFields = [
            { ids: ['website'], nameAttr: 'website', type: 'url', name: 'Website' },
            { ids: ['facebook_url', 'facebookUrl'], nameAttr: 'facebook_url', type: 'url', name: 'Facebook URL' },
            { ids: ['instagram_url', 'instagramUrl'], nameAttr: 'instagram_url', type: 'url', name: 'Instagram URL' },
            { ids: ['twitter_url', 'twitterUrl'], nameAttr: 'twitter_url', type: 'url', name: 'Twitter URL' },
            { ids: ['linkedin_url', 'linkedinUrl'], nameAttr: 'linkedin_url', type: 'url', name: 'LinkedIn URL' },
            { ids: ['whatsapp'], nameAttr: 'whatsapp', type: 'phone', name: 'WhatsApp' },
            { ids: ['recovery_number', 'recoveryNumber'], nameAttr: 'recovery_number', type: 'phone', name: 'Recovery Number' }
        ];

        optionalFields.forEach(field => {
            const fieldEl = this.getFirstExistingField(field.ids, field.nameAttr);
            if (fieldEl) {
                fieldEl.addEventListener('blur', () => {
                    if (fieldEl.value) {
                        if (field.type === 'url') {
                            this.validateURLField(fieldEl, field.name);
                        } else if (field.type === 'phone') {
                            this.validatePhoneField(fieldEl);
                        }
                    }
                });
            }
        });
    }

    setupQuickActions() {
        const phoneField = document.getElementById('phone');
        const whatsappField = document.getElementById('whatsapp');
        const recoveryField = document.getElementById('recoveryNumber') || document.querySelector('[name="recovery_number"]');
        const businessNameField = document.getElementById('businessName');
        const ownerNameField = document.getElementById('ownerName');
        const usernameField = document.getElementById('username');
        const passwordField = document.getElementById('password');

        document.getElementById('copyPhoneToWhatsappBtn')?.addEventListener('click', () => {
            if (phoneField && whatsappField) {
                whatsappField.value = phoneField.value.trim();
                whatsappField.dispatchEvent(new Event('input', { bubbles: true }));
                this.showInfoToast('WhatsApp number copied from phone.');
            }
        });

        document.getElementById('copyPhoneToRecoveryBtn')?.addEventListener('click', () => {
            if (phoneField && recoveryField) {
                recoveryField.value = phoneField.value.trim();
                recoveryField.dispatchEvent(new Event('input', { bubbles: true }));
                this.showInfoToast('Recovery number copied from phone.');
            }
        });

        phoneField?.addEventListener('blur', () => {
            const phone = phoneField.value.trim();
            if (!phone) return;

            if (whatsappField && !whatsappField.value.trim()) {
                whatsappField.value = phone;
                whatsappField.dispatchEvent(new Event('input', { bubbles: true }));
            }

            if (this.businessType === 'garage' && recoveryField && !recoveryField.value.trim()) {
                recoveryField.value = phone;
                recoveryField.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        document.getElementById('suggestUsernameBtn')?.addEventListener('click', () => {
            if (!usernameField) return;

            const businessName = businessNameField?.value?.trim() || '';
            const ownerName = ownerNameField?.value?.trim() || '';
            const source = businessName || ownerName || 'motorlink';
            const suggested = this.generateUsernameSuggestion(source);
            usernameField.value = suggested;
            usernameField.dispatchEvent(new Event('input', { bubbles: true }));
            this.showInfoToast(`Username suggested: ${suggested}`);
        });

        document.getElementById('generatePasswordBtn')?.addEventListener('click', () => {
            if (!passwordField) return;

            const generated = this.generateStrongPassword();
            passwordField.value = generated;
            passwordField.dispatchEvent(new Event('input', { bubbles: true }));
            this.showInfoToast('Strong password generated. Use Copy Password to share it securely.');
        });

        document.getElementById('copyPasswordBtn')?.addEventListener('click', async () => {
            if (!passwordField || !passwordField.value.trim()) {
                this.showError('Generate or enter a password before copying.');
                return;
            }

            const copied = await this.copyToClipboard(passwordField.value.trim());
            if (copied) {
                this.showInfoToast('Password copied to clipboard.');
            } else {
                this.showError('Could not copy password automatically. Please copy it manually.');
            }
        });
    }

    sanitizeUsernameBase(value) {
        return (value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_\s-]/g, '')
            .replace(/[\s-]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    generateUsernameSuggestion(source) {
        const cleanBase = this.sanitizeUsernameBase(source) || 'motorlink';
        const timePart = Date.now().toString().slice(-4);
        const maxBaseLength = 20 - timePart.length - 1;
        const truncatedBase = cleanBase.slice(0, Math.max(4, maxBaseLength));
        return `${truncatedBase}_${timePart}`.slice(0, 20);
    }

    generateStrongPassword(length = 12) {
        const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const lower = 'abcdefghijkmnopqrstuvwxyz';
        const digits = '23456789';
        const symbols = '!@#$%*?';
        const all = upper + lower + digits + symbols;

        const pick = (chars) => chars[Math.floor(Math.random() * chars.length)];

        let result = [pick(upper), pick(lower), pick(digits), pick(symbols)];
        for (let i = result.length; i < length; i++) {
            result.push(pick(all));
        }

        // Shuffle
        for (let i = result.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [result[i], result[j]] = [result[j], result[i]];
        }

        return result.join('');
    }

    async copyToClipboard(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (error) {
        }

        try {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-9999px';
            document.body.appendChild(textArea);
            textArea.select();
            const copied = document.execCommand('copy');
            document.body.removeChild(textArea);
            return copied;
        } catch (error) {
            return false;
        }
    }

    showInfoToast(message) {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary-orange);
            color: white;
            padding: 12px 16px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            z-index: 1001;
            max-width: 360px;
            animation: slideInRight 0.3s ease;
        `;
        toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-bolt"></i>
                <div>${message}</div>
            </div>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2500);
    }

    selectBusinessType(option) {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('=== SELECTING BUSINESS TYPE ===');
        
        // Remove selected class from all options
        document.querySelectorAll('.business-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        
        // Add selected class to clicked option
        option.classList.add('selected');
        
        // Get business type from data attribute
        const businessType = option.getAttribute('data-type');
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Selected business type:', businessType);
        
        // Update hidden input
        const businessTypeInput = document.getElementById('businessType');
        if (businessTypeInput) {
            businessTypeInput.value = businessType;
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Hidden input value set to:', businessTypeInput.value);
        }
        
        this.businessType = businessType;
        
        // Handle business type specific changes
        this.handleBusinessTypeChange(businessType);
        
        // Validate step to enable Next button
        this.validateCurrentStep();
        
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('=== BUSINESS TYPE SELECTION COMPLETE ===');
    }

    handleBusinessTypeChange(type) {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Handling business type change to:', type);
        
        // Hide all specific fields
        document.querySelectorAll('.business-specific-fields').forEach(field => {
            field.style.display = 'none';
        });

        // Show recovery number for garages
        const recoveryGroup = document.getElementById('recoveryNumberGroup');
        const recoveryItem = document.getElementById('reviewRecoveryNumberItem');
        if (type === 'garage') {
            if (recoveryGroup) recoveryGroup.style.display = 'block';
            if (recoveryItem) recoveryItem.style.display = 'flex';
        } else {
            if (recoveryGroup) recoveryGroup.style.display = 'none';
            if (recoveryItem) recoveryItem.style.display = 'none';
        }

        // Show certified checkbox for garages
        const certifiedCheckbox = document.getElementById('certifiedCheckbox');
        const certifiedItem = document.getElementById('reviewCertifiedItem');
        if (type === 'garage') {
            if (certifiedCheckbox) certifiedCheckbox.style.display = 'flex';
            if (certifiedItem) certifiedItem.style.display = 'flex';
        } else {
            if (certifiedCheckbox) certifiedCheckbox.style.display = 'none';
            if (certifiedItem) certifiedItem.style.display = 'none';
        }

        // Show relevant fields for selected business type
        if (type) {
            const fieldsElement = document.getElementById(type + 'Fields');
            if (fieldsElement) {
                fieldsElement.style.display = 'block';
                if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Showing fields for:', type);
            }
        }

        if (type === 'car_hire') {
            this.updateCarHireEventTypesVisibility();
        } else {
            const eventTypes = document.querySelectorAll('input[name="event_types"]');
            eventTypes.forEach(cb => {
                cb.checked = false;
            });
            const group = document.getElementById('eventTypesGroup');
            if (group) {
                group.style.display = 'none';
            }
        }

        this.businessType = type;
    }

    updateCarHireEventTypesVisibility() {
        const hireCategoryEl = document.getElementById('hire_category');
        const group = document.getElementById('eventTypesGroup');
        if (!hireCategoryEl || !group) {
            return;
        }

        const shouldShow = hireCategoryEl.value === 'events' || hireCategoryEl.value === 'all';
        group.style.display = shouldShow ? '' : 'none';

        if (!shouldShow) {
            const eventTypes = document.querySelectorAll('input[name="event_types"]');
            eventTypes.forEach(cb => {
                cb.checked = false;
            });
        }
    }

    showStep(step) {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Showing step:', step);
        
        // Hide all steps
        document.querySelectorAll('.form-step').forEach(stepEl => {
            stepEl.classList.remove('active');
        });

        // Show current step
        const currentStepEl = document.querySelector(`.form-step[data-step="${step}"]`);
        if (currentStepEl) {
            currentStepEl.classList.add('active');
        }

        // Update progress bar
        document.querySelectorAll('.progress-step').forEach(progressStep => {
            progressStep.classList.remove('active');
            if (parseInt(progressStep.getAttribute('data-step')) <= step) {
                progressStep.classList.add('active');
            }
        });

        // Update navigation buttons
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        
        if (prevBtn) prevBtn.style.display = step > 1 ? 'flex' : 'none';
        if (nextBtn) {
            nextBtn.style.display = step < this.totalSteps ? 'flex' : 'none';
            // Enable next button to allow free navigation (validation happens on submit)
            if (step === 1) {
                // Step 1 still requires business type selection
                nextBtn.disabled = !this.businessType;
            } else {
                // Other steps allow navigation
                nextBtn.disabled = false;
            }
        }
        if (submitBtn) submitBtn.style.display = step === this.totalSteps ? 'flex' : 'none';

        // Update step indicator
        const stepNumberEl = document.getElementById('currentStepNumber');
        if (stepNumberEl) {
            stepNumberEl.textContent = step;
        }

        // Show helpful tips for first-time users
        if (step === 1) {
            this.showUniqueListingTip();
        }

        // Update step-specific content
        if (step === 5) {
            this.updateReviewSection();
        }

        // Validate current step
        this.validateCurrentStep();
    }

    /**
     * Show helpful tip about unique listings (education-first approach)
     */
    showUniqueListingTip() {
        // Check if tip was already shown in this session
        if (sessionStorage.getItem('uniqueListingTipShown')) {
            return;
        }

        // Show tip only once per session
        sessionStorage.setItem('uniqueListingTipShown', 'true');

        const tipHtml = `
            <div class="onboarding-tip unique-listing-tip">
                <div class="tip-icon">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <div class="tip-content">
                    <h4>💡 Pro Tip: Unique Ads Get More Views!</h4>
                    <p>Keep your listings unique - one active ad per vehicle. This helps buyers find what they need and makes your business look more professional.</p>
                    <ul>
                        <li>✓ Better search rankings</li>
                        <li>✓ More buyer trust</li>
                        <li>✓ Faster sales</li>
                    </ul>
                    <a href="../terms.html" target="_blank">Learn more about our listing policy</a>
                </div>
                <button class="tip-dismiss" onclick="this.parentElement.remove()" aria-label="Got it">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        // Insert tip at the top of the form
        const formStep = document.querySelector('.form-step[data-step="1"]');
        if (formStep) {
            const tip = document.createElement('div');
            tip.innerHTML = tipHtml;
            formStep.insertBefore(tip.firstElementChild, formStep.firstChild);

            // Auto-dismiss after 15 seconds
            setTimeout(() => {
                const tipElement = formStep.querySelector('.unique-listing-tip');
                if (tipElement) {
                    tipElement.style.opacity = '0';
                    setTimeout(() => tipElement.remove(), 300);
                }
            }, 15000);
        }
    }

    nextStep() {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Next button clicked, current step:', this.currentStep);
        
        // Allow navigation but show warning if validation fails (except for step 1 which requires business type)
        if (this.currentStep === 1) {
            // Step 1 requires business type selection
            if (!this.validateCurrentStep()) {
                this.showError('Please select a business type before proceeding');
                return;
            }
        } else {
            // For other steps, validate but allow navigation with warning
            const isValid = this.validateCurrentStep();
            if (!isValid) {
                // Show warning but allow navigation
                const proceed = confirm('Some required fields are incomplete. You can continue and come back to fill them later. Do you want to proceed?');
                if (!proceed) {
                    return;
                }
            }
        }
        
        if (this.currentStep < this.totalSteps) {
            this.currentStep++;
            this.saveProgress(); // Auto-save progress
            this.showStep(this.currentStep);
        }
    }

    previousStep() {
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Previous button clicked, current step:', this.currentStep);
        // Always allow going back - no validation needed
        if (this.currentStep > 1) {
            this.currentStep--;
            this.saveProgress(); // Auto-save progress
            this.showStep(this.currentStep);
        }
    }

    validateCurrentStep() {
        let isValid = true;
        const currentStepEl = document.querySelector(`.form-step[data-step="${this.currentStep}"]`);
        
        if (!currentStepEl) {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Current step element not found');
            return false;
        }
        
        if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Validating step:', this.currentStep);
        
        // Step 1 validation - Business type (FIXED)
        if (this.currentStep === 1) {
            const businessTypeInput = document.getElementById('businessType');
            const businessType = businessTypeInput ? businessTypeInput.value : '';
            isValid = !!businessType;
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Step 1 validation - Business type selected:', isValid, 'Value:', businessType);
            
            // Show/hide message
            const messageEl = document.getElementById('step1Message');
            if (messageEl) {
                messageEl.style.display = isValid ? 'none' : 'block';
            }
            
            // Update next button
            const nextBtn = document.getElementById('nextBtn');
            if (nextBtn) {
                nextBtn.disabled = !isValid;
                if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Next button disabled:', nextBtn.disabled);
            }
            
            return isValid;
        }
        
        // Step 2 validation - Basic info
        if (this.currentStep === 2) {
            const requiredFields = currentStepEl.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    this.highlightFieldError(field, true);
                } else {
                    this.highlightFieldError(field, false);
                }
            });
            
            // Validate business name format
            const businessNameField = document.getElementById('businessName');
            if (businessNameField && businessNameField.value) {
                const businessName = businessNameField.value.trim();
                if (businessName.length < 2) {
                    isValid = false;
                    this.showFieldError(businessNameField, 'Business name must be at least 2 characters long');
                } else if (businessName.length > 255) {
                    isValid = false;
                    this.showFieldError(businessNameField, 'Business name is too long (max 255 characters)');
                } else {
                    this.clearFieldError(businessNameField);
                }
            }
            
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Step 2 validation - Basic info valid:', isValid);
        }
        
        // Step 3 validation - Contact details
        if (this.currentStep === 3) {
            const requiredFields = currentStepEl.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    this.highlightFieldError(field, true);
                } else {
                    this.highlightFieldError(field, false);
                }
            });
            
            // Email validation
            const emailField = document.getElementById('email');
            if (emailField) {
                if (emailField.value && !this.validateEmailField(emailField)) {
                    isValid = false;
                }
            }
            
            // Phone validation
            const phoneField = document.getElementById('phone');
            if (phoneField) {
                if (phoneField.value && !this.validatePhoneField(phoneField)) {
                    isValid = false;
                }
            }
            
            // Validate optional URLs
            const urlFields = [
                { ids: ['website'], nameAttr: 'website', name: 'Website' },
                { ids: ['facebook_url', 'facebookUrl'], nameAttr: 'facebook_url', name: 'Facebook URL' },
                { ids: ['instagram_url', 'instagramUrl'], nameAttr: 'instagram_url', name: 'Instagram URL' },
                { ids: ['twitter_url', 'twitterUrl'], nameAttr: 'twitter_url', name: 'Twitter URL' },
                { ids: ['linkedin_url', 'linkedinUrl'], nameAttr: 'linkedin_url', name: 'LinkedIn URL' }
            ];
            
            urlFields.forEach(field => {
                const fieldEl = this.getFirstExistingField(field.ids, field.nameAttr);
                if (fieldEl && fieldEl.value) {
                    if (!this.validateURLField(fieldEl, field.name)) {
                        isValid = false;
                    }
                }
            });
            
            // Validate optional phone numbers
            const optionalPhoneFields = [
                { ids: ['whatsapp'], nameAttr: 'whatsapp', name: 'WhatsApp' },
                { ids: ['recovery_number', 'recoveryNumber'], nameAttr: 'recovery_number', name: 'Recovery Number' }
            ];
            
            optionalPhoneFields.forEach(field => {
                const fieldEl = this.getFirstExistingField(field.ids, field.nameAttr);
                if (fieldEl && fieldEl.value) {
                    if (!this.validatePhoneField(fieldEl)) {
                        isValid = false;
                    }
                }
            });
            
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Step 3 validation - Contact details valid:', isValid);
        }
        
        // Step 4 validation - Services & Features
        if (this.currentStep === 4) {
            // For car hire: require at least one vehicle type
            if (this.businessType === 'car_hire') {
                const vehicleTypes = document.querySelectorAll('input[name="vehicle_types"]:checked');
                if (vehicleTypes.length === 0) {
                    isValid = false;
                    this.showFieldMessage('Please select at least one vehicle type');
                } else {
                    this.clearFieldMessage();
                }

                const hireCategory = (document.getElementById('hire_category') || {}).value || 'standard';
                if (hireCategory === 'events' || hireCategory === 'all') {
                    const eventTypes = document.querySelectorAll('input[name="event_types"]:checked');
                    if (eventTypes.length === 0) {
                        isValid = false;
                        this.showFieldMessage('Please select at least one event type for Events/All category.');
                    }
                }
            }
            
            // For garage: require at least one service
            if (this.businessType === 'garage') {
                const services = document.querySelectorAll('input[name="services"]:checked');
                if (services.length === 0) {
                    isValid = false;
                    this.showFieldMessage('Please select at least one service');
                } else {
                    this.clearFieldMessage();
                }
            }
            
            // For dealer: require at least one specialization
            if (this.businessType === 'dealer') {
                const specializations = document.querySelectorAll('input[name="specialization"]:checked');
                if (specializations.length === 0) {
                    isValid = false;
                    this.showFieldMessage('Please select at least one car brand specialization');
                } else {
                    this.clearFieldMessage();
                }
            }
            
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Step 4 validation - Services valid:', isValid);
        }
        
        // Step 5 validation - Confirmation
        if (this.currentStep === 5) {
            const confirmCheckbox = document.getElementById('confirmDetails');
            isValid = confirmCheckbox && confirmCheckbox.checked;
            if (confirmCheckbox) {
                if (!isValid) {
                    confirmCheckbox.parentElement.style.color = 'var(--danger-red)';
                } else {
                    confirmCheckbox.parentElement.style.color = '';
                }
            }
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Step 5 validation - Confirmation valid:', isValid);
        }
        
        // Keep navigation flexible after step 1.
        const nextBtn = document.getElementById('nextBtn');
        if (nextBtn) {
            nextBtn.disabled = this.currentStep === 1 ? !isValid : false;
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Next button disabled:', nextBtn.disabled);
        }
        
        return isValid;
    }

    highlightFieldError(field, hasError) {
        if (hasError) {
            field.style.borderColor = 'var(--danger-red)';
            field.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
        } else {
            field.style.borderColor = 'var(--gray-300)';
            field.style.boxShadow = 'none';
        }
    }

    clearFieldMessage() {
        const messageEl = document.getElementById('fieldMessage');
        if (messageEl) {
            messageEl.remove();
        }
    }

    showFieldMessage(message) {
        this.clearFieldMessage();
        
        const messageEl = document.createElement('div');
        messageEl.id = 'fieldMessage';
        messageEl.style.cssText = `
            color: var(--danger-red);
            padding: 10px;
            margin: 10px 0;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: var(--border-radius);
        `;
        const currentStep = document.querySelector('.form-step.active');
        if (currentStep) {
            currentStep.prepend(messageEl);
        }
        messageEl.textContent = message;
    }

    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    isValidPhone(phone) {
        // Basic phone validation - accepts +265 format and local numbers
        const phoneRegex = /^[\+]?[265]?[-\s\.]?\(?[\d]{1,4}\)?[-\s\.]?[\d]{1,4}[-\s\.]?[\d]{1,9}$/;
        return phoneRegex.test(phone.replace(/\s/g, ''));
    }

    isValidURL(url) {
        if (!url) return true; // URLs are optional
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }

    validateFieldOnInput(field) {
        const fieldId = field.id;
        const value = field.value.trim();

        // Clear previous error messages
        this.clearFieldError(field);

        // Validate based on field type
        if (fieldId === 'email' && value) {
            this.validateEmailField(field);
        } else if (fieldId === 'phone' && value) {
            this.validatePhoneField(field);
        } else if (field.type === 'url' && value) {
            const fieldName = field.placeholder || field.name || 'URL';
            this.validateURLField(field, fieldName);
        }
    }

    validateEmailField(field) {
        const email = field.value.trim();
        if (!email) {
            this.clearFieldError(field);
            return true;
        }

        if (!this.isValidEmail(email)) {
            this.showFieldError(field, 'Invalid email format');
            return false;
        }

        if (email.length > 255) {
            this.showFieldError(field, 'Email is too long (max 255 characters)');
            return false;
        }

        this.clearFieldError(field);
        return true;
    }

    validatePhoneField(field) {
        const phone = field.value.trim();
        if (!phone) {
            this.clearFieldError(field);
            return true;
        }

        // Remove formatting characters for validation
        const cleaned = phone.replace(/[\s\-\(\)\+]/g, '');
        
        if (!/^\d{7,15}$/.test(cleaned)) {
            this.showFieldError(field, 'Invalid phone format. Use 7-15 digits');
            return false;
        }

        this.clearFieldError(field);
        return true;
    }

    validateURLField(field, fieldName = 'URL') {
        const url = field.value.trim();
        if (!url) {
            this.clearFieldError(field);
            return true; // URLs are optional
        }

        if (url.length > 500) {
            this.showFieldError(field, `${fieldName} is too long (max 500 characters)`);
            return false;
        }

        if (!this.isValidURL(url)) {
            this.showFieldError(field, `Invalid ${fieldName} format. Please include http:// or https://`);
            return false;
        }

        this.clearFieldError(field);
        return true;
    }

    showFieldError(field, message) {
        this.clearFieldError(field);
        
        field.style.borderColor = 'var(--danger-red)';
        field.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
        
        const errorEl = document.createElement('div');
        errorEl.className = 'field-error-message';
        errorEl.style.cssText = `
            color: var(--danger-red);
            font-size: 0.875rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        `;
        errorEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        
        field.parentElement.appendChild(errorEl);
    }

    clearFieldError(field) {
        field.style.borderColor = '';
        field.style.boxShadow = '';
        
        const errorEl = field.parentElement.querySelector('.field-error-message');
        if (errorEl) {
            errorEl.remove();
        }
        
        const warningEl = field.parentElement.querySelector('.field-warning-message');
        if (warningEl) {
            warningEl.remove();
        }
    }

    async checkEmailOrPhoneDuplicate(type, value) {
        if (!value || !this.businessType) return;

        try {
            const payload = {
                type: this.businessType,
                [type]: value
            };

            const response = await fetch(`${this.API_BASE_URL}?action=check_email_phone`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                return; // Silently fail for real-time checks
            }

            const result = await response.json();

            if (result.success) {
                const field = document.getElementById(type);
                if (!field) return;

                if (type === 'email' && result.email_exists) {
                    if (result.email_belongs_to_user) {
                        // Email belongs to existing user - might be second business
                        this.showFieldWarning(field, 'This email is already registered. You can continue if this is a second business.');
                    } else {
                        this.showFieldError(field, 'This email is already registered. Please use a different email.');
                    }
                } else if (type === 'phone' && result.phone_exists) {
                    if (result.phone_belongs_to_user) {
                        // Phone belongs to existing user - might be second business
                        this.showFieldWarning(field, 'This phone number is already registered. You can continue if this is a second business.');
                    } else {
                        this.showFieldError(field, 'This phone number is already registered. Please use a different phone number.');
                    }
                } else {
                    this.clearFieldError(field);
                }
            }
        } catch (error) {
            // Silently fail for real-time checks
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) {
                console.error('Duplicate check error:', error);
            }
        }
    }

    showFieldWarning(field, message) {
        this.clearFieldError(field);
        
        field.style.borderColor = 'var(--warning-orange)';
        field.style.boxShadow = '0 0 0 3px rgba(255, 193, 7, 0.1)';
        
        const warningEl = document.createElement('div');
        warningEl.className = 'field-warning-message';
        warningEl.style.cssText = `
            color: var(--warning-orange);
            font-size: 0.875rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        `;
        warningEl.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
        
        field.parentElement.appendChild(warningEl);
    }

    async checkBusinessNameDuplicate(businessName) {
        if (!businessName || !this.businessType) return;

        try {
            const response = await fetch(`${this.API_BASE_URL}?action=check_business_name`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.businessType,
                    business_name: businessName
                })
            });

            if (!response.ok) {
                return; // Silently fail for real-time checks
            }

            const result = await response.json();

            if (result.success) {
                const field = document.getElementById('businessName');
                if (!field) return;

                if (result.exists) {
                    if (result.is_second_business) {
                        // Business name exists but belongs to same user - might be second business
                        this.showFieldWarning(field, 'This business name is already registered. You can continue if this is a second business.');
                    } else {
                        this.showFieldError(field, 'This business name is already registered. Please choose a different name.');
                    }
                } else {
                    this.clearFieldError(field);
                }
            }
        } catch (error) {
            // Silently fail for real-time checks
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) {
                console.error('Business name duplicate check error:', error);
            }
        }
    }

    async loadInitialData() {
        try {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Loading initial data from:', this.API_BASE_URL);
            await Promise.all([
                this.loadLocations(),
                this.loadMakes(),
                this.loadServices(),
                this.loadVehicleTypes()
            ]);
        } catch (error) {
            this.showError('Failed to load initial data. Using fallback options.');
        }
    }

    async loadLocations() {
        try {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Loading locations from:', `${this.API_BASE_URL}?action=locations`);
            const response = await fetch(`${this.API_BASE_URL}?action=locations`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.locations) {
                const locationSelect = document.getElementById('location');
                if (locationSelect) {
                    const previousValue = locationSelect.value;
                    locationSelect.innerHTML = '<option value="">Select City/Town</option>';
                    
                    data.locations.forEach(location => {
                        const option = document.createElement('option');
                        option.value = location.id;
                        option.textContent = `${location.name}, ${location.region}`;
                        locationSelect.appendChild(option);
                    });

                    if (previousValue) {
                        locationSelect.value = String(previousValue);
                    }
                }
            } else {
                throw new Error(data.message || 'Failed to load locations');
            }
        } catch (error) {
            this.loadFallbackLocations();
        }
    }

    async loadMakes() {
        try {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Loading makes from:', `${this.API_BASE_URL}?action=get_makes`);
            const response = await fetch(`${this.API_BASE_URL}?action=get_makes`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error('Endpoint not available');
            }
            
            const data = await response.json();
            
            if (data.success && data.makes) {
                const container = document.getElementById('carBrandsContainer');
                const dealerContainer = document.getElementById('dealerSpecializationContainer');
                
                if (container) {
                    data.makes.forEach(make => {
                        const label = document.createElement('label');
                        label.className = 'checkbox-label';
                        label.innerHTML = `
                            <input type="checkbox" name="specializes_in_cars" value="${make.name}">
                            <span class="checkmark"></span>
                            ${make.name}
                        `;
                        container.appendChild(label);
                    });
                }
                
                if (dealerContainer) {
                    data.makes.forEach(make => {
                        const dealerLabel = document.createElement('label');
                        dealerLabel.className = 'checkbox-label';
                        dealerLabel.innerHTML = `
                            <input type="checkbox" name="specialization" value="${make.name}">
                            <span class="checkmark"></span>
                            ${make.name}
                        `;
                        dealerContainer.appendChild(dealerLabel);
                    });
                }
            }
        } catch (error) {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Using fallback makes data');
            this.loadFallbackMakes();
        }
    }

    async loadServices() {
        try {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Loading services from:', `${this.API_BASE_URL}?action=get_services`);
            const response = await fetch(`${this.API_BASE_URL}?action=get_services`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error('Endpoint not available');
            }
            
            const data = await response.json();
            
            if (data.success && data.services) {
                const container = document.getElementById('servicesContainer');
                if (container) {
                    data.services.forEach(service => {
                        const label = document.createElement('label');
                        label.className = 'checkbox-label';
                        label.innerHTML = `
                            <input type="checkbox" name="services" value="${service}">
                            <span class="checkmark"></span>
                            ${service}
                        `;
                        container.appendChild(label);
                    });
                }
            }
        } catch (error) {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Using fallback services data');
            this.loadFallbackServices();
        }
    }

    async loadVehicleTypes() {
        try {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Loading vehicle types from:', `${this.API_BASE_URL}?action=get_vehicle_types`);
            const response = await fetch(`${this.API_BASE_URL}?action=get_vehicle_types`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error('Endpoint not available');
            }
            
            const data = await response.json();
            
            if (data.success && data.vehicle_types) {
                const container = document.getElementById('vehicleTypesContainer');
                if (container) {
                    data.vehicle_types.forEach(type => {
                        const label = document.createElement('label');
                        label.className = 'checkbox-label';
                        label.innerHTML = `
                            <input type="checkbox" name="vehicle_types" value="${type}">
                            <span class="checkmark"></span>
                            ${type}
                        `;
                        container.appendChild(label);
                    });
                }
            }
        } catch (error) {
            if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('Using fallback vehicle types data');
            this.loadFallbackVehicleTypes();
        }
    }

    loadFallbackLocations() {
        const fallbackLocations = [
            { id: 1, name: 'Blantyre', region: 'Southern' },
            { id: 2, name: 'Lilongwe', region: 'Central' },
            { id: 3, name: 'Mzuzu', region: 'Northern' },
            { id: 4, name: 'Zomba', region: 'Southern' },
            { id: 5, name: 'Kasungu', region: 'Central' }
        ];
        
        const locationSelect = document.getElementById('location');
        if (locationSelect) {
            const previousValue = locationSelect.value;
            locationSelect.innerHTML = '<option value="">Select City/Town</option>';
            
            fallbackLocations.forEach(location => {
                const option = document.createElement('option');
                option.value = location.id;
                option.textContent = `${location.name}, ${location.region}`;
                locationSelect.appendChild(option);
            });

            if (previousValue) {
                locationSelect.value = String(previousValue);
            }
        }
    }

    loadFallbackMakes() {
        const fallbackMakes = [
            'Toyota', 'Honda', 'Nissan', 'Mazda', 'Mitsubishi', 
            'Suzuki', 'Mercedes-Benz', 'BMW', 'Ford', 'Hyundai'
        ];
        
        const container = document.getElementById('carBrandsContainer');
        const dealerContainer = document.getElementById('dealerSpecializationContainer');
        
        if (container) {
            fallbackMakes.forEach(make => {
                const label = document.createElement('label');
                label.className = 'checkbox-label';
                label.innerHTML = `
                    <input type="checkbox" name="specializes_in_cars" value="${make}">
                    <span class="checkmark"></span>
                    ${make}
                `;
                container.appendChild(label);
            });
        }
        
        if (dealerContainer) {
            fallbackMakes.forEach(make => {
                const dealerLabel = document.createElement('label');
                dealerLabel.className = 'checkbox-label';
                dealerLabel.innerHTML = `
                    <input type="checkbox" name="specialization" value="${make}">
                    <span class="checkmark"></span>
                    ${make}
                `;
                dealerContainer.appendChild(dealerLabel);
            });
        }
    }

    loadFallbackServices() {
        const fallbackServices = [
            "Engine Repair", "Brake Service", "Oil Change", "AC Repair", "Transmission Service",
            "Electrical Repair", "Body Work", "Painting", "Dent Removal", "Glass Replacement",
            "Tire Service", "Battery Replacement", "Computer Diagnostics", "Hybrid Service", "Performance Tuning"
        ];
        
        const container = document.getElementById('servicesContainer');
        if (container) {
            fallbackServices.forEach(service => {
                const label = document.createElement('label');
                label.className = 'checkbox-label';
                label.innerHTML = `
                    <input type="checkbox" name="services" value="${service}">
                    <span class="checkmark"></span>
                    ${service}
                `;
                container.appendChild(label);
            });
        }
    }

    loadFallbackVehicleTypes() {
        const fallbackVehicleTypes = [
            "Economy", "Compact", "Sedan", "SUV", "Pickup",
            "Luxury", "Sports Car", "Van", "Truck", "Minibus", "4WD", "Executive", "Limousine"
        ];
        
        const container = document.getElementById('vehicleTypesContainer');
        if (container) {
            fallbackVehicleTypes.forEach(type => {
                const label = document.createElement('label');
                label.className = 'checkbox-label';
                label.innerHTML = `
                    <input type="checkbox" name="vehicle_types" value="${type}">
                    <span class="checkmark"></span>
                    ${type}
                `;
                container.appendChild(label);
            });
        }
    }

    updateReviewSection() {
        const formData = new FormData(document.getElementById('onboardingForm'));
        
        // Basic Information
        document.getElementById('reviewBusinessType').textContent = this.formatBusinessType(this.businessType);
        document.getElementById('reviewBusinessName').textContent = formData.get('business_name') || 'Not provided';
        document.getElementById('reviewOwnerName').textContent = formData.get('owner_name') || 'Not provided';
        document.getElementById('reviewYearsEstablished').textContent = formData.get('years_established') ? formData.get('years_established') + ' years' : 'Not specified';
        document.getElementById('reviewDescription').textContent = formData.get('description') || 'No description provided';
        
        // Contact Information
        document.getElementById('reviewEmail').textContent = formData.get('email') || 'Not provided';
        document.getElementById('reviewPhone').textContent = formData.get('phone') || 'Not provided';
        document.getElementById('reviewWhatsapp').textContent = formData.get('whatsapp') || 'Not provided';
        document.getElementById('reviewWhatsappUpdates').textContent = formData.get('whatsapp_updates_opt_in') ? 'Enabled' : 'Disabled';
        document.getElementById('reviewRecoveryNumber').textContent = formData.get('recovery_number') || 'Not provided';
        document.getElementById('reviewAddress').textContent = formData.get('address') || 'Not provided';
        
        // Location
        const locationSelect = document.getElementById('location');
        if (locationSelect) {
            const selectedOption = locationSelect.options[locationSelect.selectedIndex];
            document.getElementById('reviewLocation').textContent = selectedOption?.text || 'Not selected';
        }

        // Social Media Links
        const facebookUrl = formData.get('facebook_url');
        const instagramUrl = formData.get('instagram_url');
        const twitterUrl = formData.get('twitter_url');
        const linkedinUrl = formData.get('linkedin_url');

        let hasSocialMedia = false;

        if (facebookUrl) {
            document.getElementById('reviewFacebook').textContent = facebookUrl;
            document.getElementById('reviewFacebookItem').style.display = 'flex';
            hasSocialMedia = true;
        } else {
            document.getElementById('reviewFacebookItem').style.display = 'none';
        }

        if (instagramUrl) {
            document.getElementById('reviewInstagram').textContent = instagramUrl;
            document.getElementById('reviewInstagramItem').style.display = 'flex';
            hasSocialMedia = true;
        } else {
            document.getElementById('reviewInstagramItem').style.display = 'none';
        }

        if (twitterUrl) {
            document.getElementById('reviewTwitter').textContent = twitterUrl;
            document.getElementById('reviewTwitterItem').style.display = 'flex';
            hasSocialMedia = true;
        } else {
            document.getElementById('reviewTwitterItem').style.display = 'none';
        }

        if (linkedinUrl) {
            document.getElementById('reviewLinkedin').textContent = linkedinUrl;
            document.getElementById('reviewLinkedinItem').style.display = 'flex';
            hasSocialMedia = true;
        } else {
            document.getElementById('reviewLinkedinItem').style.display = 'none';
        }

        // Show/hide "no social media" message
        document.getElementById('reviewNoSocialMedia').style.display = hasSocialMedia ? 'none' : 'block';

        // Services & Features
        const servicesContainer = document.getElementById('reviewServices');
        if (servicesContainer) {
            servicesContainer.innerHTML = this.getSelectedServicesHTML();
        }
        
        document.getElementById('reviewVerified').textContent = formData.get('verified') ? 'Yes' : 'No';
        document.getElementById('reviewFeatured').textContent = formData.get('featured') ? 'Yes' : 'No';
        document.getElementById('reviewCertified').textContent = formData.get('certified') ? 'Yes' : 'No';
        
        // Reset duplicate check
        this.duplicateChecked = false;
        this.duplicateExists = false;
        this.duplicateApproved = false;
        const duplicateResult = document.getElementById('duplicateResult');
        if (duplicateResult) {
            duplicateResult.style.display = 'none';
        }
    }

    getSelectedServicesHTML() {
        let html = '';
        const businessType = this.businessType;
        
        if (businessType === 'car_hire') {
            const vehicleTypes = Array.from(document.querySelectorAll('input[name="vehicle_types"]:checked'))
                .map(cb => cb.value);
            const services = Array.from(document.querySelectorAll('input[name="services"]:checked'))
                .map(cb => cb.value);
            const specialServices = Array.from(document.querySelectorAll('input[name="special_services"]:checked'))
                .map(cb => cb.value);
            const hireCategoryEl = document.getElementById('hire_category');
            const hireCategory = hireCategoryEl ? hireCategoryEl.options[hireCategoryEl.selectedIndex]?.text : null;
            const eventTypes = Array.from(document.querySelectorAll('input[name="event_types"]:checked'))
                .map(cb => cb.value);
            
            if (vehicleTypes.length > 0) {
                html += `<div class="review-item"><strong>Vehicle Types:</strong> <span>${vehicleTypes.join(', ')}</span></div>`;
            }
            if (hireCategory) {
                html += `<div class="review-item"><strong>Hire Category:</strong> <span>${hireCategory}</span></div>`;
            }
            if (eventTypes.length > 0) {
                html += `<div class="review-item"><strong>Event Types:</strong> <span>${eventTypes.join(', ')}</span></div>`;
            }
            if (services.length > 0) {
                html += `<div class="review-item"><strong>Services:</strong> <span>${services.join(', ')}</span></div>`;
            }
            if (specialServices.length > 0) {
                html += `<div class="review-item"><strong>Special Services:</strong> <span>${specialServices.join(', ')}</span></div>`;
            }
        }
        else if (businessType === 'garage') {
            const services = Array.from(document.querySelectorAll('input[name="services"]:checked'))
                .map(cb => cb.value);
            const emergencyServices = Array.from(document.querySelectorAll('input[name="emergency_services"]:checked'))
                .map(cb => cb.value);
            const specializesIn = Array.from(document.querySelectorAll('input[name="specializes_in_cars"]:checked'))
                .map(cb => cb.value);
            
            if (services.length > 0) {
                html += `<div class="review-item"><strong>Services:</strong> <span>${services.join(', ')}</span></div>`;
            }
            if (emergencyServices.length > 0) {
                html += `<div class="review-item"><strong>Emergency Services:</strong> <span>${emergencyServices.join(', ')}</span></div>`;
            }
            if (specializesIn.length > 0) {
                html += `<div class="review-item"><strong>Specializes In:</strong> <span>${specializesIn.join(', ')}</span></div>`;
            }
        }
        else if (businessType === 'dealer') {
            const specialization = Array.from(document.querySelectorAll('input[name="specialization"]:checked'))
                .map(cb => cb.value);
            
            if (specialization.length > 0) {
                html += `<div class="review-item"><strong>Specialization:</strong> <span>${specialization.join(', ')}</span></div>`;
            }
        }
        
        return html || '<div class="review-item"><strong>Services:</strong> <span>No services selected</span></div>';
    }

    formatBusinessType(type) {
        const types = {
            'car_hire': 'Car Hire Company',
            'garage': 'Garage / Workshop',
            'dealer': 'Car Dealer'
        };
        return types[type] || type;
    }

    async checkForDuplicates(fromSubmit = false) {
        const businessName = document.getElementById('businessName');
        const email = document.getElementById('email');
        const phone = document.getElementById('phone');
        
        if (!businessName || !businessName.value || !email || !email.value || !phone || !phone.value) {
            if (fromSubmit) {
                this.showError('Please complete business name, email, and phone before submitting.');
            } else {
                this.showError('Please complete business name, email, and phone before checking for duplicates.');
            }
            return false;
        }
        
        try {
            const checkBtn = document.getElementById('checkDuplicateBtn');
            if (checkBtn) {
                checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
                checkBtn.disabled = true;
            }
            
            const response = await fetch(`${this.API_BASE_URL}?action=check_business`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.businessType,
                    business_name: businessName.value,
                    email: email.value,
                    phone: phone.value
                })
            });
            
            const result = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(result?.message || `HTTP ${response.status}: ${response.statusText}`);
            }
            
            if (result.success) {
                this.duplicateChecked = true;
                
                if (result.exists) {
                    this.duplicateExists = true;
                    this.duplicateApproved = false;
                    // Pass the is_second_business flag to the modal
                    const businessData = {
                        ...result.business,
                        is_second_business: result.is_second_business || false
                    };
                    await this.showDuplicateModal(businessData);
                    return false;
                } else {
                    this.duplicateExists = false;
                    this.duplicateApproved = true;
                    this.showDuplicateResult('No similar businesses found. You can proceed with submission.', 'success');
                    return true;
                }
            } else {
                throw new Error(result.message || 'Duplicate check failed');
            }
            
        } catch (error) {
            this.showError('Failed to check for duplicates: ' + error.message);
            return false;
        } finally {
            const checkBtn = document.getElementById('checkDuplicateBtn');
            if (checkBtn) {
                checkBtn.innerHTML = '<i class="fas fa-search"></i> Check for Duplicates';
                checkBtn.disabled = false;
            }
        }
    }

    async showDuplicateModal(business) {
        document.getElementById('duplicateBusinessName').textContent = business.name || business.business_name;
        document.getElementById('duplicateEmail').textContent = business.email;
        document.getElementById('duplicatePhone').textContent = business.phone;
        
        // Check if this is a second business (email belongs to existing user)
        const emailField = document.getElementById('email');
        const email = emailField ? emailField.value : '';
        let isSecondBusiness = false;
        
        if (email && business.is_second_business !== undefined) {
            isSecondBusiness = business.is_second_business;
        } else if (email) {
            // Fallback: check via API
            try {
                const response = await fetch(`${this.API_BASE_URL}?action=check_email_phone`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        type: this.businessType,
                        email: email
                    })
                });
                
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.email_belongs_to_user) {
                        isSecondBusiness = true;
                    }
                }
            } catch (error) {
                // If check fails, default to not allowing continue
            }
        }
        
        // Update modal message based on whether it's a second business
        const warningText = document.querySelector('#duplicateModal .warning-text');
        if (warningText) {
            if (isSecondBusiness) {
                warningText.textContent = 'This email is already registered to an existing user. You can continue if this is a second business for the same user.';
            } else {
                warningText.textContent = 'Please verify if this is the same business or a different one. You cannot continue unless this is a second business from the same user.';
            }
        }
        
        // Show/hide "Continue Anyway" button based on whether it's a second business
        const continueBtn = document.getElementById('continueSubmitBtn');
        if (continueBtn) {
            if (isSecondBusiness) {
                continueBtn.style.display = 'flex';
                continueBtn.innerHTML = '<i class="fas fa-check"></i> Continue Anyway (Second Business)';
            } else {
                continueBtn.style.display = 'none';
            }
        }

        this.duplicateApproved = isSecondBusiness;
        
        document.getElementById('duplicateModal').style.display = 'flex';
    }

    showDuplicateResult(message, type) {
        const resultEl = document.getElementById('duplicateResult');
        if (resultEl) {
            resultEl.innerHTML = `
                <div class="alert ${type}">
                    <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'}"></i>
                    <div>${message}</div>
                </div>
            `;
            resultEl.style.display = 'block';
        }
    }

    async submitForm() {
        if (!this.validateCurrentStep()) {
            this.showError('Please complete all required fields and confirm the details.');
            return;
        }

        // Run duplicate check automatically before final submit to reduce manual steps.
        if (!this.duplicateChecked) {
            const canProceedAfterCheck = await this.checkForDuplicates(true);
            if (!canProceedAfterCheck) {
                return;
            }
        }

        // If duplicate was found, only allow submission when explicitly approved.
        if (this.duplicateExists && !this.duplicateApproved) {
            this.showError('Duplicate business details detected. Resolve the conflict or use an approved second-business flow before submitting.');
            return;
        }

        try {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                submitBtn.disabled = true;
            }

            const formData = new FormData(document.getElementById('onboardingForm'));
            const data = Object.fromEntries(formData.entries());

            // Debug: Log raw form data
            console.log('=== ONBOARDING DEBUG ===');
            console.log('Raw form data:', data);
            console.log('Username from form:', data.username);
            console.log('Password present:', data.password ? 'Yes (length: ' + data.password.length + ')' : 'No');

            // Validate username and password are present
            if (!data.username || !data.username.trim()) {
                throw new Error('Username is required. Please fill in the login credentials.');
            }
            if (!data.password || !data.password.trim()) {
                throw new Error('Password is required. Please fill in the login credentials.');
            }

            const pwError = this.validatePasswordStrength(data.password.trim());
            if (pwError) {
                throw new Error(pwError);
            }

            // Prepare data based on business type
            const apiData = this.prepareSubmissionData(data);

            // Debug: Log prepared API data
            console.log('Prepared API data:', apiData);
            console.log('API username:', apiData.username);
            console.log('API password present:', apiData.password ? 'Yes' : 'No');

            // Determine endpoint
            const endpoint = this.getEndpointForBusinessType();

            console.log('Submitting to:', `${this.API_BASE_URL}?action=${endpoint}`);
            console.log('=== END DEBUG ===');
            
            const response = await fetch(`${this.API_BASE_URL}?action=${endpoint}`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(apiData)
            });

            const result = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(result?.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            if (result.success) {
                // Clear saved progress after successful submission
                this.clearProgress();
                // Lock the form to prevent duplicate submissions
                this.lockForm();
                this.showSuccessModal(result);
            } else {
                throw new Error(result.message || 'Submission failed');
            }
            
        } catch (error) {
            this.showError('Failed to submit business: ' + error.message);
        } finally {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Submit Business';
                submitBtn.disabled = false;
            }
        }
    }

    prepareSubmissionData(data) {
        const baseData = {
            business_name: data.business_name,
            owner_name: data.owner_name,
            email: data.email,
            phone: data.phone,
            whatsapp: data.whatsapp || null,
            whatsapp_updates_opt_in: data.whatsapp_updates_opt_in ? 1 : 0,
            address: data.address,
            location_id: parseInt(data.location_id),
            years_established: data.years_established ? parseInt(data.years_established) : null,
            description: data.description || null,
            verified: data.verified ? 1 : 0,
            featured: data.featured ? 1 : 0,
            website: data.website || null,

            // Business registration (exists in users table)
            business_registration: data.business_registration || null,

            // Owner information (maps to users table: national_id, date_of_birth)
            owner_id_number: data.owner_id_number || null,
            owner_dob: data.owner_dob || null,

            // Social media links (all exist in business tables)
            facebook_url: data.facebook_url || null,
            instagram_url: data.instagram_url || null,
            twitter_url: data.twitter_url || null,
            linkedin_url: data.linkedin_url || null,

            // Login credentials for the business manager
            username: data.username,
            password: data.password
        };

        // Add business-specific data
        switch (this.businessType) {
            case 'car_hire':
                baseData.vehicle_types = Array.from(document.querySelectorAll('input[name="vehicle_types"]:checked'))
                    .map(cb => cb.value);
                baseData.services = Array.from(document.querySelectorAll('input[name="services"]:checked'))
                    .map(cb => cb.value);
                baseData.special_services = Array.from(document.querySelectorAll('input[name="special_services"]:checked'))
                    .map(cb => cb.value);
                baseData.hire_category = (document.getElementById('hire_category') || {}).value || 'standard';
                baseData.event_types = (baseData.hire_category === 'events' || baseData.hire_category === 'all')
                    ? Array.from(document.querySelectorAll('input[name="event_types"]:checked')).map(cb => cb.value)
                    : [];
                baseData.daily_rate_from = data.daily_rate_from ? parseFloat(data.daily_rate_from) : null;
                baseData.weekly_rate_from = data.weekly_rate_from ? parseFloat(data.weekly_rate_from) : null;
                baseData.monthly_rate_from = data.monthly_rate_from ? parseFloat(data.monthly_rate_from) : null;
                baseData.business_hours = data.business_hours || null;
                baseData.currency = (typeof CONFIG !== 'undefined' && CONFIG.CURRENCY_CODE)
                    ? CONFIG.CURRENCY_CODE
                    : 'LOCAL';
                baseData.operates_24_7 = 0;
                break;

            case 'garage':
                baseData.name = data.business_name;
                baseData.recovery_number = data.recovery_number || null;
                baseData.services = Array.from(document.querySelectorAll('input[name="services"]:checked'))
                    .map(cb => cb.value);
                baseData.emergency_services = Array.from(document.querySelectorAll('input[name="emergency_services"]:checked'))
                    .map(cb => cb.value);
                baseData.specialization = Array.from(document.querySelectorAll('input[name="services"]:checked'))
                    .map(cb => cb.value);
                baseData.specializes_in_cars = Array.from(document.querySelectorAll('input[name="specializes_in_cars"]:checked'))
                    .map(cb => cb.value);
                baseData.years_experience = data.years_established ? parseInt(data.years_established) : null;
                baseData.operating_hours = data.operating_hours || null;
                baseData.certified = data.certified ? 1 : 0;
                break;

            case 'dealer':
                baseData.specialization = Array.from(document.querySelectorAll('input[name="specialization"]:checked'))
                    .map(cb => cb.value);
                baseData.business_hours = data.business_hours || null;
                baseData.total_sales = 0;
                // Note: total_reviews and rating fields don't exist in car_dealers table
                break;
        }

        return baseData;
    }

    getEndpointForBusinessType() {
        const endpoints = {
            'car_hire': 'add_car_hire',
            'garage': 'add_garage',
            'dealer': 'add_dealer'
        };
        return endpoints[this.businessType];
    }

    showSuccessModal(result) {
        document.getElementById('successBusinessName').textContent = result.business_name;
        document.getElementById('successBusinessId').textContent = result.company_id || result.garage_id || result.dealer_id;
        document.getElementById('successReference').textContent = result.reference;

        // Display user account info
        document.getElementById('successUserId').textContent = result.user_id || 'N/A';
        document.getElementById('successUsername').textContent = result.username || 'N/A';

        // Update status badges dynamically
        const businessStatusText = result.business_status === 'pending_approval' ? 'Pending Approval' : result.business_status;
        const userStatusText = result.user_status === 'pending' ? 'Pending Approval' : result.user_status;

        const emailNotice = result?.notifications?.email?.message || 'Credentials email sent.';
        const waNotice = result?.notifications?.whatsapp?.message || 'WhatsApp updates status unavailable.';
        const waStatus = result?.notifications?.whatsapp?.status || 'unknown';
        const waPrefix = waStatus === 'sent' ? 'WhatsApp update sent.' : 'WhatsApp update not sent yet.';
        const notificationNote = `${emailNotice} ${waPrefix} ${waNotice}`;
        const noteEl = document.getElementById('successNotificationNote');
        if (noteEl) {
            noteEl.textContent = notificationNote;
        }

        document.getElementById('successModal').style.display = 'flex';

        // Log success for debugging with complete information
        console.log('Business created successfully:', result);
        console.log('Business Status:', result.business_status);
        console.log('User Status:', result.user_status);
        console.log('Owner:', result.owner_name);
        console.log('Email:', result.email);
        console.log('Phone:', result.phone);
    }

    showError(message) {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--danger-red);
            color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            z-index: 1001;
            max-width: 400px;
            animation: slideInRight 0.3s ease;
        `;
        toast.innerHTML = `
            <div style="display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Error</strong>
                    <div style="margin-top: 5px; font-size: 0.9rem;">${message}</div>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; margin-left: auto;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 5000);
    }

    resetForm() {
        // Re-enable all form inputs (unlock form)
        const form = document.getElementById('onboardingForm');
        if (form) {
            form.reset();
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.disabled = false;
            });
        }

        // Re-enable all buttons
        const allButtons = document.querySelectorAll('.btn, button');
        allButtons.forEach(btn => {
            btn.disabled = false;
        });

        // Reset UI state
        document.querySelectorAll('.business-option').forEach(opt => {
            opt.classList.remove('selected');
            opt.style.pointerEvents = '';
            opt.style.opacity = '';
        });

        // Hide modals
        document.getElementById('successModal').style.display = 'none';
        document.getElementById('duplicateModal').style.display = 'none';

        // Reset state
        this.currentStep = 1;
        this.businessType = '';
        this.duplicateChecked = false;
        this.duplicateExists = false;
        this.duplicateApproved = false;

        // Go back to step 1
        this.showStep(1);

        // Reset specific fields visibility
        this.handleBusinessTypeChange('');
    }

    lockForm() {
        // Disable all form inputs
        const form = document.getElementById('onboardingForm');
        if (form) {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.disabled = true;
            });
        }

        // Disable all buttons except the "New Business" button in success modal
        const allButtons = document.querySelectorAll('.btn, button');
        allButtons.forEach(btn => {
            if (!btn.closest('#successModal')) {
                btn.disabled = true;
            }
        });

        // Disable business type selection
        document.querySelectorAll('.business-option').forEach(opt => {
            opt.style.pointerEvents = 'none';
            opt.style.opacity = '0.6';
        });
    }
}

// Initialize the onboarding form when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) console.log('DOM loaded, initializing onboarding form...');
    new OnboardingForm();
});

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    .field-message {
        padding: 10px;
        margin: 10px 0;
        border-radius: var(--border-radius);
        font-size: 0.9rem;
    }
`;
document.head.appendChild(style);
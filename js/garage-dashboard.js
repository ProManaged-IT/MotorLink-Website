class GarageDashboard {
    constructor() {
        this.garageInfo = null;
        this.garages = [];
        this.selectedGarageId = localStorage.getItem('motorlink_selected_garage_id') || '';
        this.services = [];
        this.currentUser = null;
        this.init();
    }

    async init() {
        await this.checkAuth();
        await this.loadGarageInfo();
        this.setupEventListeners();
        this.setupNavigation();
        this.setupMobileSidebar();
    }

    async checkAuth() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (!data.authenticated) {
                window.location.href = 'login.html';
                return;
            }

            const businessTypes = Array.isArray(data.user.business_types) ? data.user.business_types : [];
            if (data.user.type !== 'garage' && !businessTypes.includes('garage')) {
                alert('Access denied. This area is for garage businesses only.');
                window.location.href = 'index.html';
                return;
            }

            this.currentUser = data.user;
            document.getElementById('garageName').textContent = data.user.name;

            // Update avatar
            if (window.app && window.app.updateUserAvatar) {
                window.app.updateUserAvatar(data.user.name);
            }
        } catch (error) {
            window.location.href = 'login.html';
        }
    }

    async loadGarageInfo() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_garage_info${this.getSelectedGarageQuery()}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.garage) {
                this.syncGarageSelection(data.garages || [], data.selected_garage_id || data.garage.id);
                this.renderGarageSwitcher();
                this.garageInfo = data.garage;
                this.populateGarageForm();
                this.populateHoursForm();
                this.loadServices();
                this.updateStats();
            }
        } catch (error) {
        }
    }

    getSelectedGarageQuery() {
        return this.selectedGarageId ? `&garage_id=${encodeURIComponent(this.selectedGarageId)}` : '';
    }

    appendSelectedGarage(formData) {
        if (this.selectedGarageId) {
            formData.set('garage_id', this.selectedGarageId);
        }
    }

    syncGarageSelection(garages, selectedId) {
        this.garages = Array.isArray(garages) ? garages : [];
        const ids = this.garages.map(garage => String(garage.id));
        const preferredId = selectedId ? String(selectedId) : this.selectedGarageId;
        this.selectedGarageId = ids.includes(preferredId) ? preferredId : (ids[0] || preferredId || '');

        if (this.selectedGarageId) {
            localStorage.setItem('motorlink_selected_garage_id', this.selectedGarageId);
        }
    }

    renderGarageSwitcher() {
        const existing = document.getElementById('garageBusinessSwitcher');
        if (this.garages.length <= 1) {
            if (existing) existing.remove();
            return;
        }

        const header = document.querySelector('.dashboard-header');
        if (!header) return;

        const switcher = existing || document.createElement('div');
        switcher.id = 'garageBusinessSwitcher';
        switcher.className = 'dashboard-business-switcher';
        switcher.innerHTML = `
            <label for="garageBusinessSelect">Managing</label>
            <select id="garageBusinessSelect" aria-label="Select garage business">
                ${this.garages.map(garage => `
                    <option value="${garage.id}" ${String(garage.id) === String(this.selectedGarageId) ? 'selected' : ''}>${garage.name || 'Garage'}</option>
                `).join('')}
            </select>
        `;

        if (!existing) header.appendChild(switcher);

        const select = document.getElementById('garageBusinessSelect');
        if (select) {
            select.onchange = async () => {
                this.selectedGarageId = select.value;
                localStorage.setItem('motorlink_selected_garage_id', this.selectedGarageId);
                await this.loadGarageInfo();
            };
        }
    }

    populateGarageForm() {
        if (!this.garageInfo) return;

        // Garage Name (from 'name' field in API response)
        const garageNameInput = document.getElementById('garageNameInput');
        if (garageNameInput) {
            garageNameInput.value = this.garageInfo.name || this.garageInfo.garage_name || '';
        }

        // Location/District (from 'location_name' field in API response)
        const garageDistrict = document.getElementById('garageDistrict');
        if (garageDistrict && this.garageInfo.location_name) {
            garageDistrict.value = this.garageInfo.location_name;
            // If the location_name doesn't match any option, we can still set it
            // The browser will select the matching option if it exists
        }

        const fields = {
            'garageDescription': 'description',
            'garagePhone': 'phone',
            'garageWhatsApp': 'whatsapp',
            'garageEmail': 'email',
            'garageWebsite': 'website',
            'garageAddress': 'address',
            'garageCity': 'city'
        };

        for (const [fieldId, dataKey] of Object.entries(fields)) {
            const element = document.getElementById(fieldId);
            if (element && this.garageInfo[dataKey]) {
                element.value = this.garageInfo[dataKey];
            }
        }

        // Character counter for description
        const descTextarea = document.getElementById('garageDescription');
        const charCount = document.getElementById('descCharCount');
        if (descTextarea && charCount) {
            charCount.textContent = descTextarea.value.length;
            descTextarea.addEventListener('input', (e) => {
                charCount.textContent = e.target.value.length;
            });
        }

        // Logo preview
        if (this.garageInfo.logo_url) {
            const preview = document.getElementById('logoPreview');
            preview.innerHTML = `<img src="${this.garageInfo.logo_url}" alt="Logo">`;
        }
    }

    populateHoursForm() {
        if (!this.garageInfo || !this.garageInfo.operating_hours) return;

        try {
            const hours = typeof this.garageInfo.operating_hours === 'string'
                ? JSON.parse(this.garageInfo.operating_hours)
                : this.garageInfo.operating_hours;

            const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

            days.forEach(day => {
                const openInput = document.getElementById(`${day}Open`);
                const closeInput = document.getElementById(`${day}Close`);
                
                if (hours[`${day}_open`]) {
                    if (openInput) openInput.value = hours[`${day}_open`];
                }
                if (hours[`${day}_close`]) {
                    if (closeInput) closeInput.value = hours[`${day}_close`];
                }
            });
        } catch (error) {
        }
    }

    loadServices() {
        if (!this.garageInfo || !this.garageInfo.services) return;

        try {
            this.services = typeof this.garageInfo.services === 'string'
                ? JSON.parse(this.garageInfo.services)
                : this.garageInfo.services;

            // Check the appropriate checkboxes
            const checkboxes = document.querySelectorAll('input[name="service"]');
            checkboxes.forEach(checkbox => {
                if (this.services.includes(checkbox.value)) {
                    checkbox.checked = true;
                }
            });

            // Display custom services
            const customServices = this.services.filter(service => {
                const standardServices = Array.from(checkboxes).map(cb => cb.value);
                return !standardServices.includes(service);
            });

            this.displayCustomServices(customServices);
            this.updateStats();
        } catch (error) {
        }
    }

    displayCustomServices(customServices) {
        const container = document.getElementById('customServicesList');
        if (!container) return;

        container.innerHTML = customServices.map(service => `
            <div class="custom-service-item">
                <span>${service}</span>
                <button type="button" class="btn btn-small btn-danger" onclick="garageDashboard.removeCustomService('${service}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `).join('');
    }

    updateStats() {
        document.getElementById('totalServices').textContent = this.services.length;
        document.getElementById('totalViews').textContent = this.garageInfo?.views || 0;
        document.getElementById('totalInquiries').textContent = this.garageInfo?.inquiries_count || 0;
        document.getElementById('activeServices').textContent = this.services.filter(s => s.active).length || this.services.length;
    }

    setupEventListeners() {
        // Garage info form
        const garageForm = document.getElementById('garageInfoForm');
        if (garageForm) {
            garageForm.addEventListener('submit', (e) => this.handleGarageSubmit(e));
        }

        // Hours form
        const hoursForm = document.getElementById('hoursForm');
        if (hoursForm) {
            hoursForm.addEventListener('submit', (e) => this.handleHoursSubmit(e));
        }

        // Services
        const saveServicesBtn = document.getElementById('saveServices');
        if (saveServicesBtn) {
            saveServicesBtn.addEventListener('click', () => this.handleSaveServices());
        }

        const addCustomServiceBtn = document.getElementById('addCustomService');
        if (addCustomServiceBtn) {
            addCustomServiceBtn.addEventListener('click', () => this.handleAddCustomService());
        }

        // Logo upload
        const logoInput = document.getElementById('garageLogo');
        if (logoInput) {
            logoInput.addEventListener('change', (e) => this.handleLogoUpload(e));
        }

        // Notification form
        const notificationForm = document.getElementById('notificationForm');
        if (notificationForm) {
            notificationForm.addEventListener('submit', (e) => this.handleNotificationSubmit(e));
        }
    }

    async handleGarageSubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'update_garage_info');
        this.appendSelectedGarage(formData);

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Garage details updated successfully!', 'success');
                await this.loadGarageInfo();
            } else {
                this.showNotification(data.message || 'Failed to update garage details', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async handleHoursSubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        const hours = {};
        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        days.forEach(day => {
            const openTime = formData.get(`${day}_open`);
            const closeTime = formData.get(`${day}_close`);
            
            if (openTime && closeTime) {
                hours[`${day}_open`] = openTime;
                hours[`${day}_close`] = closeTime;
            } else {
                hours[`${day}_open`] = '';
                hours[`${day}_close`] = '';
            }
        });

        const submitData = new FormData();
        submitData.append('action', 'update_garage_hours');
        this.appendSelectedGarage(submitData);
        submitData.append('operating_hours', JSON.stringify(hours));

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: submitData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Operating hours updated successfully!', 'success');
                await this.loadGarageInfo();
            } else {
                this.showNotification(data.message || 'Failed to update operating hours', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async handleSaveServices() {
        const checkboxes = document.querySelectorAll('input[name="service"]:checked');
        const selectedServices = Array.from(checkboxes).map(cb => cb.value);

        // Add custom services
        const customServices = Array.from(document.querySelectorAll('.custom-service-item span'))
            .map(span => span.textContent);

        const allServices = [...selectedServices, ...customServices];

        const formData = new FormData();
        formData.append('action', 'update_garage_services');
        this.appendSelectedGarage(formData);
        formData.append('services', JSON.stringify(allServices));

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.services = allServices;
                this.showNotification('Services updated successfully!', 'success');
                this.updateStats();
            } else {
                this.showNotification(data.message || 'Failed to update services', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    handleAddCustomService() {
        const input = document.getElementById('customService');
        const serviceName = input.value.trim();

        if (!serviceName) {
            this.showNotification('Please enter a service name', 'error');
            return;
        }

        if (this.services.includes(serviceName)) {
            this.showNotification('This service is already added', 'error');
            return;
        }

        const container = document.getElementById('customServicesList');
        const serviceDiv = document.createElement('div');
        serviceDiv.className = 'custom-service-item';
        serviceDiv.innerHTML = `
            <span>${serviceName}</span>
            <button type="button" class="btn btn-small btn-danger" onclick="garageDashboard.removeCustomService('${serviceName}')">
                <i class="fas fa-times"></i>
            </button>
        `;

        container.appendChild(serviceDiv);
        input.value = '';
        this.showNotification('Service added! Don\'t forget to save.', 'info');
    }

    removeCustomService(serviceName) {
        const container = document.getElementById('customServicesList');
        const items = container.querySelectorAll('.custom-service-item');

        items.forEach(item => {
            if (item.querySelector('span').textContent === serviceName) {
                item.remove();
            }
        });

        this.showNotification('Service removed! Don\'t forget to save.', 'info');
    }

    async handleLogoUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file type
        if (!file.type.startsWith('image/')) {
            this.showNotification('Please select an image file', 'error');
            return;
        }

        // Validate file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            this.showNotification('Image must be less than 2MB', 'error');
            return;
        }

        // Preview
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = document.getElementById('logoPreview');
            preview.innerHTML = `<img src="${event.target.result}" alt="Logo Preview">`;
        };
        reader.readAsDataURL(file);

        // Upload
        const formData = new FormData();
        formData.append('action', 'upload_garage_logo');
        this.appendSelectedGarage(formData);
        formData.append('logo', file);

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Logo uploaded successfully!', 'success');
                this.garageInfo.logo_url = data.logo_url;
            } else {
                this.showNotification(data.message || 'Failed to upload logo', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async handleNotificationSubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'update_notification_preferences');

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Notification preferences updated!', 'success');
            } else {
                this.showNotification(data.message || 'Failed to update preferences', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    setupNavigation() {
        const links = document.querySelectorAll('.sidebar-link');
        links.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const section = link.getAttribute('data-section');
                this.switchSection(section);

                // Update active state
                links.forEach(l => l.classList.remove('active'));
                link.classList.add('active');

                // Close mobile sidebar
                if (window.innerWidth <= 1024) {
                    document.getElementById('dashboardSidebar').classList.remove('active');
                }
            });
        });
    }

    switchSection(sectionId) {
        const sections = document.querySelectorAll('.dashboard-section');
        sections.forEach(section => {
            section.classList.remove('active');
        });

        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.classList.add('active');
        }

        // Update URL hash
        window.location.hash = sectionId;
    }

    setupMobileSidebar() {
        const toggle = document.getElementById('mobileSidebarToggle');
        const sidebar = document.getElementById('dashboardSidebar');

        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    }

    formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} days ago`;
        return date.toLocaleDateString();
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.classList.add('show');
        }, 100);

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
}

// Initialize dashboard when DOM is loaded
let garageDashboard;
document.addEventListener('DOMContentLoaded', () => {
    garageDashboard = new GarageDashboard();
});

// Scroll to top functionality removed - using .back-to-top button from script.js instead

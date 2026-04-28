class CarHireDashboard {
    constructor() {
        this.companyInfo = null;
        this.companies = [];
        this.selectedCompanyId = localStorage.getItem('motorlink_selected_car_hire_company_id') || '';
        this.fleet = [];
        this.rentals = [];
        this.currentUser = null;
        this.init();
    }

    async init() {
        await this.checkAuth();
        await this.loadCompanyInfo();
        await this.loadFleet();
        await this.loadRentals();
        this.setupEventListeners();
        this.setupNavigation();
        this.setupMobileSidebar();
        this.populateYearDropdowns();
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
            if (data.user.type !== 'car_hire' && !businessTypes.includes('car_hire')) {
                alert('Access denied. This area is for car hire companies only.');
                window.location.href = 'index.html';
                return;
            }

            this.currentUser = data.user;
            document.getElementById('companyName').textContent = data.user.name;

            // Update avatar
            if (window.app && window.app.updateUserAvatar) {
                window.app.updateUserAvatar(data.user.name);
            }
        } catch (error) {
            window.location.href = 'login.html';
        }
    }

    async loadCompanyInfo() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_car_hire_company_info${this.getSelectedCompanyQuery()}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.company) {
                this.syncCompanySelection(data.companies || [], data.selected_company_id || data.company.id);
                this.renderCompanySwitcher();
                this.companyInfo = data.company;
                this.populateCompanyForm();
            }
        } catch (error) {
        }
    }

    getSelectedCompanyQuery() {
        return this.selectedCompanyId ? `&company_id=${encodeURIComponent(this.selectedCompanyId)}` : '';
    }

    appendSelectedCompany(formData) {
        if (this.selectedCompanyId) {
            formData.set('company_id', this.selectedCompanyId);
        }
    }

    syncCompanySelection(companies, selectedId) {
        this.companies = Array.isArray(companies) ? companies : [];
        const ids = this.companies.map(company => String(company.id));
        const preferredId = selectedId ? String(selectedId) : this.selectedCompanyId;
        this.selectedCompanyId = ids.includes(preferredId) ? preferredId : (ids[0] || preferredId || '');

        if (this.selectedCompanyId) {
            localStorage.setItem('motorlink_selected_car_hire_company_id', this.selectedCompanyId);
        }
    }

    renderCompanySwitcher() {
        const existing = document.getElementById('carHireBusinessSwitcher');
        if (this.companies.length <= 1) {
            if (existing) existing.remove();
            return;
        }

        const header = document.querySelector('.dashboard-header');
        if (!header) return;

        const switcher = existing || document.createElement('div');
        switcher.id = 'carHireBusinessSwitcher';
        switcher.className = 'dashboard-business-switcher';
        switcher.innerHTML = `
            <label for="carHireBusinessSelect">Managing</label>
            <select id="carHireBusinessSelect" aria-label="Select car hire company">
                ${this.companies.map(company => `
                    <option value="${company.id}" ${String(company.id) === String(this.selectedCompanyId) ? 'selected' : ''}>${company.name || 'Car Hire'}</option>
                `).join('')}
            </select>
        `;

        if (!existing) header.appendChild(switcher);

        const select = document.getElementById('carHireBusinessSelect');
        if (select) {
            select.onchange = async () => {
                this.selectedCompanyId = select.value;
                localStorage.setItem('motorlink_selected_car_hire_company_id', this.selectedCompanyId);
                await this.loadCompanyInfo();
                await this.loadFleet();
                await this.loadRentals();
            };
        }
    }

    populateCompanyForm() {
        if (!this.companyInfo) return;

        const fields = {
            'companyNameInput': 'business_name',
            'companyDescription': 'description',
            'companyPhone': 'phone',
            'companyWhatsApp': 'whatsapp',
            'companyEmail': 'email',
            'companyWebsite': 'website',
            'companyAddress': 'address',
            'companyCity': 'city',
            'companyDistrict': 'district'
        };

        for (const [fieldId, dataKey] of Object.entries(fields)) {
            const element = document.getElementById(fieldId);
            if (element && this.companyInfo[dataKey]) {
                element.value = this.companyInfo[dataKey];
            }
        }

        // Hire category
        const hireCatEl = document.getElementById('companyHireCategory');
        if (hireCatEl && this.companyInfo.hire_category) {
            hireCatEl.value = this.companyInfo.hire_category;
        }

        // Event types checkboxes
        if (this.companyInfo.event_types) {
            try {
                const types = typeof this.companyInfo.event_types === 'string'
                    ? JSON.parse(this.companyInfo.event_types)
                    : this.companyInfo.event_types;
                document.querySelectorAll('input[name="event_types[]"]').forEach(cb => {
                    cb.checked = types.includes(cb.value);
                });
            } catch (e) {}
        }

        // Show/hide event types based on hire_category
        this.toggleEventTypesVisibility();

        // Character counter for description
        const descTextarea = document.getElementById('companyDescription');
        const charCount = document.getElementById('descCharCount');
        if (descTextarea && charCount) {
            charCount.textContent = descTextarea.value.length;
            descTextarea.addEventListener('input', (e) => {
                charCount.textContent = e.target.value.length;
            });
        }

        // Operating hours
        if (this.companyInfo.weekday_open) document.getElementById('weekdayOpen').value = this.companyInfo.weekday_open;
        if (this.companyInfo.weekday_close) document.getElementById('weekdayClose').value = this.companyInfo.weekday_close;
        if (this.companyInfo.saturday_open) document.getElementById('saturdayOpen').value = this.companyInfo.saturday_open;
        if (this.companyInfo.saturday_close) document.getElementById('saturdayClose').value = this.companyInfo.saturday_close;
        if (this.companyInfo.sunday_open) document.getElementById('sundayOpen').value = this.companyInfo.sunday_open;
        if (this.companyInfo.sunday_close) document.getElementById('sundayClose').value = this.companyInfo.sunday_close;

        // Logo preview
        if (this.companyInfo.logo_url) {
            const preview = document.getElementById('logoPreview');
            preview.innerHTML = `<img src="${this.companyInfo.logo_url}" alt="Logo">`;
        }
    }

    toggleEventTypesVisibility() {
        const hireCat = document.getElementById('companyHireCategory');
        const eventGroup = document.getElementById('eventTypesGroup');
        if (hireCat && eventGroup) {
            const val = hireCat.value;
            eventGroup.style.display = (val === 'events' || val === 'all') ? '' : 'none';
        }
    }

    populateYearDropdowns() {
        const currentYear = new Date().getFullYear();
        const startYear = 1990;
        const yearSelect = document.getElementById('vehicleYear');
        
        if (yearSelect) {
            for (let year = currentYear; year >= startYear; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearSelect.appendChild(option);
            }
        }
    }

    async loadFleet() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_car_hire_fleet${this.getSelectedCompanyQuery()}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success) {
                this.fleet = data.fleet || [];
                this.displayFleet(this.fleet);
                this.updateStats();
            }
        } catch (error) {
            document.getElementById('fleetGrid').innerHTML =
                '<p class="error-message">Failed to load fleet</p>';
        }
    }

    displayFleet(vehicles) {
        const grid = document.getElementById('fleetGrid');

        if (!vehicles || vehicles.length === 0) {
            grid.innerHTML = '<p class="text-muted">No vehicles in fleet yet. Add your first vehicle!</p>';
            return;
        }

        grid.innerHTML = vehicles.map(vehicle => {
            // Get proper make and model names
            const make = vehicle.make_name || vehicle.make || 'Vehicle';
            const model = vehicle.model_name || vehicle.model || '';
            const year = vehicle.year || '';

            // Get vehicle details with fallbacks
            const plate = vehicle.license_plate || vehicle.registration_number || 'No Plate';
            const color = vehicle.exterior_color || vehicle.color || '';

            // Format status label for display
            const status = vehicle.status || 'available';
            const statusLabel = this.formatStatusLabel(status);
            const imageUrl = vehicle.image_url || (vehicle.image ? `uploads/fleet/${vehicle.image}` : '');

            // Build info string
            let infoText = `${make} ${model} • ${plate}`;
            if (color) {
                infoText += ` • ${color}`;
            }

            return `
            <div class="car-card">
                <div class="car-image">
                    ${imageUrl ?
                        `<img src="${imageUrl}" alt="${make} ${model}">` :
                        `<div class="car-placeholder no-image-nudge">
                            <i class="fas fa-camera"></i>
                            <p>No photos yet</p>
                            <button class="btn btn-small btn-outline-light nudge-btn" onclick="event.stopPropagation();carHireDashboard.editVehicle(${vehicle.id})">
                                <i class="fas fa-plus"></i> Add Photos
                            </button>
                        </div>`
                    }
                </div>
                <div class="car-details">
                    <h3>${year} ${make} ${model}</h3>
                    <p class="car-year-make">${infoText}</p>
                    <div class="car-price">${CONFIG.CURRENCY_CODE || 'MWK'} ${vehicle.daily_rate ? parseInt(vehicle.daily_rate).toLocaleString() : '0'}/day</div>
                    <div class="car-meta">
                        <span class="status-badge status-${status}">${statusLabel}</span>
                        <span class="car-seats"><i class="fas fa-users"></i> ${vehicle.seats || 5} seats</span>
                        ${vehicle.vehicle_category && vehicle.vehicle_category !== 'car' ? `<span class="category-badge cat-${vehicle.vehicle_category}"><i class="fas fa-${vehicle.vehicle_category === 'van' ? 'van-shuttle' : 'truck'}"></i> ${vehicle.vehicle_category.charAt(0).toUpperCase() + vehicle.vehicle_category.slice(1)}</span>` : ''}
                        ${vehicle.cargo_capacity ? `<span class="cargo-info"><i class="fas fa-box"></i> ${vehicle.cargo_capacity}</span>` : ''}
                        ${vehicle.event_suitable == 1 ? `<span class="event-suitable-badge"><i class="fas fa-calendar-check"></i> Events</span>` : ''}
                    </div>
                    <div class="card-actions">
                        <button class="btn btn-small btn-primary" onclick="carHireDashboard.editVehicle(${vehicle.id})" title="Edit vehicle details">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-small ${status === 'available' ? 'btn-secondary' : 'btn-primary'}"
                                onclick="carHireDashboard.toggleVehicleStatus(${vehicle.id}, '${status}')"
                                title="${status === 'available' ? 'Mark as rented' : 'Mark as available'}">
                            <i class="fas fa-${status === 'available' ? 'pause' : 'check'}"></i>
                            ${status === 'available' ? 'Mark Rented' : 'Mark Available'}
                        </button>
                        <button class="btn btn-small btn-secondary" onclick="carHireDashboard.setMaintenance(${vehicle.id})" title="Set maintenance status">
                            <i class="fas fa-tools"></i> Maintenance
                        </button>
                        <button class="btn btn-small btn-danger" onclick="carHireDashboard.deleteVehicle(${vehicle.id})" title="Delete vehicle">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        `;
        }).join('');
    }
    
    formatStatusLabel(status) {
        const labels = {
            'available': 'Available',
            'rented': 'Rented Out',
            'maintenance': 'Maintenance',
            'not_available': 'Not Available'
        };
        return labels[status] || 'Available';
    }

    updateStats() {
        const total = this.fleet.length;
        const available = this.fleet.filter(v => v.status === 'available').length;
        const rented = this.fleet.filter(v => v.status === 'rented').length;
        const maintenance = this.fleet.filter(v => v.status === 'maintenance').length;
        const vans = this.fleet.filter(v => v.vehicle_category === 'van').length;
        const trucks = this.fleet.filter(v => v.vehicle_category === 'truck').length;

        document.getElementById('totalVehicles').textContent = total;
        document.getElementById('availableVehicles').textContent = available;
        document.getElementById('rentedVehicles').textContent = rented;
        document.getElementById('maintenanceVehicles').textContent = maintenance;
        const vanEl = document.getElementById('vanVehicles');
        if (vanEl) vanEl.textContent = vans;
        const truckEl = document.getElementById('truckVehicles');
        if (truckEl) truckEl.textContent = trucks;
    }

    async loadRentals() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_car_hire_rentals${this.getSelectedCompanyQuery()}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success) {
                this.rentals = data.rentals || [];
                this.displayRentals(this.rentals);
                this.displayRecentActivity();
            }
        } catch (error) {
            document.getElementById('rentalsList').innerHTML =
                '<p class="error-message">Failed to load rentals</p>';
        }
    }

    displayRentals(rentals) {
        const container = document.getElementById('rentalsList');

        if (!rentals || rentals.length === 0) {
            container.innerHTML = '<p class="text-muted">No rental requests yet. New WhatsApp booking requests will appear here.</p>';
            return;
        }

        const counts = rentals.reduce((summary, rental) => {
            const status = rental.status || 'pending';
            summary[status] = (summary[status] || 0) + 1;
            return summary;
        }, {});

        const summaryHtml = `
            <div class="rental-summary-grid">
                <div class="rental-summary-card pending"><strong>${counts.pending || 0}</strong><span>Pending Requests</span></div>
                <div class="rental-summary-card confirmed"><strong>${counts.confirmed || 0}</strong><span>Active Rentals</span></div>
                <div class="rental-summary-card completed"><strong>${counts.completed || 0}</strong><span>Completed</span></div>
                <div class="rental-summary-card closed"><strong>${(counts.declined || 0) + (counts.cancelled || 0)}</strong><span>Closed</span></div>
            </div>
        `;

        const rentalsHtml = rentals.map(rental => {
            const status = rental.status || 'pending';
            const vehicleName = rental.vehicle_name || `${rental.vehicle_make || ''} ${rental.vehicle_model || ''}`.trim() || 'Vehicle';
            const dailyRate = Number(rental.daily_rate || 0);
            const totalEstimate = Number(rental.total_estimate || 0);
            const customerPhone = rental.customer_phone || rental.renter_phone || '';
            const customerWhatsApp = rental.customer_whatsapp || rental.renter_whatsapp || customerPhone;
            const safePhone = customerPhone.replace(/[^0-9+]/g, '');
            const safeWa = customerWhatsApp.replace(/[^0-9]/g, '');
            const waEnabled = typeof window.motorlinkWhatsAppButtonsEnabled === 'function'
                ? window.motorlinkWhatsAppButtonsEnabled()
                : !(window.CONFIG && CONFIG.WHATSAPP_BUTTONS_ENABLED === false);

            const actions = [];
            if (status === 'pending') {
                actions.push(`
                    <button class="btn btn-small btn-success" onclick="carHireDashboard.confirmRental(${rental.id})">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                    <button class="btn btn-small btn-danger" onclick="carHireDashboard.declineRental(${rental.id})">
                        <i class="fas fa-times"></i> Decline
                    </button>
                `);
            }

            if (status === 'confirmed') {
                actions.push(`
                    <button class="btn btn-small btn-primary" onclick="carHireDashboard.completeRental(${rental.id})">
                        <i class="fas fa-flag-checkered"></i> Complete
                    </button>
                    <button class="btn btn-small btn-secondary" onclick="carHireDashboard.cancelRental(${rental.id})">
                        <i class="fas fa-ban"></i> Cancel
                    </button>
                `);
            }

            return `
            <div class="rental-card rental-${status}">
                <div class="rental-vehicle">
                    <h3>${this.escapeHtml(vehicleName)}</h3>
                    <p>${this.escapeHtml(rental.license_plate || 'No plate recorded')}</p>
                </div>
                <div class="rental-details">
                    <div class="rental-info">
                        <strong>Customer:</strong> ${this.escapeHtml(rental.customer_name || 'Customer')}
                    </div>
                    <div class="rental-info">
                        <strong>Period:</strong> ${this.formatDate(rental.start_date)} - ${this.formatDate(rental.end_date)}
                    </div>
                    <div class="rental-info">
                        <strong>Rate:</strong> ${this.formatCurrency(dailyRate)}/day
                    </div>
                    <div class="rental-info">
                        <strong>Total:</strong> ${this.formatCurrency(totalEstimate)} ${rental.duration_days ? `(${rental.duration_days} days)` : ''}
                    </div>
                    <div class="rental-info">
                        <strong>Status:</strong> <span class="status-badge status-${status}">${this.formatRentalStatusLabel(status)}</span>
                    </div>
                    ${rental.special_requests ? `<div class="rental-info rental-note"><strong>Note:</strong> ${this.escapeHtml(rental.special_requests)}</div>` : ''}
                </div>
                <div class="rental-actions">
                    ${safePhone ? `<a class="btn btn-small btn-secondary" href="tel:${safePhone}"><i class="fas fa-phone"></i> Call</a>` : ''}
                    ${waEnabled && safeWa ? `<a class="btn btn-small btn-secondary" href="https://wa.me/${safeWa}" target="_blank" rel="noopener" data-whatsapp-cta><i class="fab fa-whatsapp"></i> WhatsApp</a>` : ''}
                    ${actions.join('')}
                </div>
            </div>
        `;
        }).join('');

        container.innerHTML = summaryHtml + rentalsHtml;
    }

    displayRecentActivity() {
        const container = document.getElementById('recentActivity');
        const recent = this.rentals.slice(0, 5);

        if (recent.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent activity</p>';
            return;
        }

        container.innerHTML = recent.map(rental => `
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="activity-content">
                    <strong>${this.escapeHtml(rental.vehicle_name || `${rental.vehicle_make || ''} ${rental.vehicle_model || ''}`.trim() || 'Vehicle')}</strong>
                    ${this.formatRentalStatusLabel(rental.status || 'pending').toLowerCase()} for ${this.escapeHtml(rental.customer_name || 'Customer')}
                    <div class="activity-time">${this.formatTimeAgo(rental.created_at)}</div>
                </div>
            </div>
        `).join('');
    }

    setupEventListeners() {
        // Company info form
        const companyForm = document.getElementById('companyInfoForm');
        if (companyForm) {
            companyForm.addEventListener('submit', (e) => this.handleCompanySubmit(e));
        }

        // Hire category change — show/hide event types
        const hireCatSelect = document.getElementById('companyHireCategory');
        if (hireCatSelect) {
            hireCatSelect.addEventListener('change', () => this.toggleEventTypesVisibility());
        }

        // Vehicle category change — show/hide cargo capacity
        const vehicleCatSelect = document.getElementById('vehicleCategory');
        if (vehicleCatSelect) {
            vehicleCatSelect.addEventListener('change', () => {
                const cargoGroup = document.getElementById('cargoCapacityGroup');
                if (cargoGroup) {
                    cargoGroup.style.display = (vehicleCatSelect.value === 'van' || vehicleCatSelect.value === 'truck') ? '' : 'none';
                }
            });
            // Initial state
            const cargoGroup = document.getElementById('cargoCapacityGroup');
            if (cargoGroup) cargoGroup.style.display = 'none';
        }

        // Add vehicle form
        const addVehicleForm = document.getElementById('addVehicleForm');
        if (addVehicleForm) {
            addVehicleForm.addEventListener('submit', (e) => this.handleAddVehicle(e));
        }

        // Logo upload
        const logoInput = document.getElementById('companyLogo');
        if (logoInput) {
            logoInput.addEventListener('change', (e) => this.handleLogoUpload(e));
        }

        // Vehicle images preview
        const vehicleImages = document.getElementById('vehicleImages');
        if (vehicleImages) {
            vehicleImages.addEventListener('change', (e) => this.previewVehicleImages(e));
        }

        // Fleet filters
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', () => this.filterFleet());
        }

        const categoryFilter = document.getElementById('categoryFilter');
        if (categoryFilter) {
            categoryFilter.addEventListener('change', () => this.filterFleet());
        }

        const searchInput = document.getElementById('searchFleet');
        if (searchInput) {
            searchInput.addEventListener('input', () => this.filterFleet());
        }

        // Notification form
        const notificationForm = document.getElementById('notificationForm');
        if (notificationForm) {
            notificationForm.addEventListener('submit', (e) => this.handleNotificationSubmit(e));
        }

        // Setup model variations for add vehicle form
        this.setupModelVariations();
    }

    setupModelVariations() {
        // Setup model change listener for vehicle model dropdown
        const vehicleModelSelect = document.getElementById('vehicleModel');
        if (vehicleModelSelect && window.modelVariationsHelper) {
            vehicleModelSelect.addEventListener('change', async (e) => {
                const modelId = e.target.value;
                if (modelId) {
                    await window.modelVariationsHelper.loadModelVariations(modelId, {
                        engineSizeSelect: 'vehicleEngineSize',
                        fuelTankSelect: 'vehicleFuelTankCapacity',
                        drivetrainSelect: 'vehicleDrivetrain',
                        transmissionSelect: 'vehicleTransmission'
                    });
                } else {
                    window.modelVariationsHelper.clearVariationDropdowns({
                        engineSizeSelect: 'vehicleEngineSize',
                        fuelTankSelect: 'vehicleFuelTankCapacity',
                        drivetrainSelect: 'vehicleDrivetrain',
                        transmissionSelect: 'vehicleTransmission'
                    });
                }
            });
        }
    }

    async handleCompanySubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'update_car_hire_company');
        this.appendSelectedCompany(formData);

        // Collect event_types checkboxes into JSON
        const eventCheckboxes = document.querySelectorAll('input[name="event_types[]"]:checked');
        const eventTypes = Array.from(eventCheckboxes).map(cb => cb.value);
        // Remove the individual checkbox entries FormData auto-collected
        formData.delete('event_types[]');
        formData.append('event_types', JSON.stringify(eventTypes));

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Company details updated successfully!', 'success');
                await this.loadCompanyInfo();
            } else {
                this.showNotification(data.message || 'Failed to update company details', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async handleAddVehicle(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'add_car_hire_vehicle');
        this.appendSelectedCompany(formData);

        // Ensure event_suitable is always sent (checkbox sends nothing when unchecked)
        if (!formData.has('event_suitable')) {
            formData.append('event_suitable', '0');
        }

        // Get vehicle images
        const imageFiles = document.getElementById('vehicleImages').files;
        for (let i = 0; i < imageFiles.length; i++) {
            formData.append('images[]', imageFiles[i]);
        }

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Vehicle added successfully!', 'success');
                e.target.reset();
                document.getElementById('imagePreview').innerHTML = '';
                await this.loadFleet();
                this.switchSection('fleet');
            } else {
                this.showNotification(data.message || 'Failed to add vehicle', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
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
        formData.append('action', 'upload_car_hire_logo');
        this.appendSelectedCompany(formData);
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
                this.companyInfo.logo_url = data.logo_url;
            } else {
                this.showNotification(data.message || 'Failed to upload logo', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    previewVehicleImages(e) {
        const files = e.target.files;
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';

        if (files.length > 5) {
            this.showNotification('Maximum 5 images allowed', 'error');
            e.target.value = '';
            return;
        }

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const reader = new FileReader();

            reader.onload = (event) => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `
                    <img src="${event.target.result}" alt="Preview ${i + 1}">
                    <span class="image-number">${i + 1}</span>
                `;
                preview.appendChild(div);
            };

            reader.readAsDataURL(file);
        }
    }

    filterFleet() {
        const statusFilter = document.getElementById('statusFilter').value;
        const categoryFilter = document.getElementById('categoryFilter')?.value || '';
        const searchTerm = document.getElementById('searchFleet').value.toLowerCase().trim();

        let filtered = this.fleet;

        if (statusFilter) {
            filtered = filtered.filter(vehicle => (vehicle.status || 'available') === statusFilter);
        }

        if (categoryFilter) {
            filtered = filtered.filter(vehicle => (vehicle.vehicle_category || 'car') === categoryFilter);
        }

        if (searchTerm) {
            filtered = filtered.filter(vehicle => {
                const make = (vehicle.make_name || vehicle.make || '').toLowerCase();
                const model = (vehicle.model_name || vehicle.model || '').toLowerCase();
                const plate = (vehicle.license_plate || vehicle.registration_number || '').toLowerCase();
                const year = (vehicle.year || '').toString().toLowerCase();
                return make.includes(searchTerm) || model.includes(searchTerm) || plate.includes(searchTerm) || year.includes(searchTerm);
            });
        }

        this.displayFleet(filtered);
    }

    async editVehicle(vehicleId) {
        try {
            // Fetch vehicle details from API
            const response = await fetch(`${CONFIG.API_URL}?action=get_vehicle&id=${vehicleId}${this.getSelectedCompanyQuery()}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (!data.success || !data.vehicle) {
                this.showNotification('Failed to load vehicle details', 'error');
                return;
            }

            const vehicle = data.vehicle;

            // Populate all form fields (including readonly ones for display)
            document.getElementById('editVehicleId').value = vehicle.id;
            document.getElementById('editMake').value = vehicle.make || '';
            document.getElementById('editModel').value = vehicle.model || '';
            document.getElementById('editYear').value = vehicle.year || '';
            document.getElementById('editLicensePlate').value = vehicle.license_plate || '';
            document.getElementById('editDailyRate').value = vehicle.daily_rate || '';
            document.getElementById('editSeats').value = vehicle.seats || '';
            document.getElementById('editStatus').value = vehicle.status || 'available';
            document.getElementById('editColor').value = vehicle.color || '';
            
            // Set vehicle category and cargo
            const editVehicleCat = document.getElementById('editVehicleCategory');
            if (editVehicleCat) {
                editVehicleCat.value = vehicle.vehicle_category || 'car';
            }
            const editCargoCapacity = document.getElementById('editCargoCapacity');
            if (editCargoCapacity) {
                editCargoCapacity.value = vehicle.cargo_capacity || '';
            }
            const editEventSuitable = document.getElementById('editEventSuitable');
            if (editEventSuitable) {
                editEventSuitable.checked = vehicle.event_suitable == 1;
            }
            // Show/hide cargo capacity in edit modal
            const editCargoGroup = document.getElementById('editCargoCapacityGroup');
            if (editCargoGroup) {
                const cat = vehicle.vehicle_category || 'car';
                editCargoGroup.style.display = (cat === 'van' || cat === 'truck') ? '' : 'none';
            }

            // Set fuel type and transmission if available
            if (vehicle.fuel_type) {
                document.getElementById('editFuelType').value = vehicle.fuel_type.toLowerCase();
            }
            if (vehicle.transmission) {
                document.getElementById('editTransmission').value = vehicle.transmission.toLowerCase();
            }

            // Display current images
            const imageContainer = document.getElementById('editCurrentImage');
            if (vehicle.image_url) {
                imageContainer.innerHTML = `
                    <div class="current-image-item">
                        <img src="${vehicle.image_url}" alt="${vehicle.make} ${vehicle.model}" style="max-width: 300px; border-radius: 8px;">
                    </div>
                `;
            } else {
                imageContainer.innerHTML = '<p class="text-muted">No images available</p>';
            }

            // Clear new images preview
            const newImagesPreview = document.getElementById('editNewImagesPreview');
            if (newImagesPreview) {
                newImagesPreview.innerHTML = '';
            }

            // Show modal
            document.getElementById('editVehicleModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Setup form submit handler
            const form = document.getElementById('editVehicleForm');
            form.onsubmit = (e) => this.handleEditVehicleSubmit(e);

            // Setup new images preview
            const newImageInput = document.getElementById('editNewImage');
            if (newImageInput) {
                newImageInput.onchange = (e) => this.previewEditImages(e);
            }

        } catch (error) {
            this.showNotification('Failed to load vehicle details', 'error');
        }
    }

    previewEditImages(e) {
        const files = e.target.files;
        const preview = document.getElementById('editNewImagesPreview');
        if (!preview) return;
        
        preview.innerHTML = '';

        if (files.length > 5) {
            this.showNotification('Maximum 5 images allowed', 'error');
            e.target.value = '';
            return;
        }

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const reader = new FileReader();

            reader.onload = (event) => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `
                    <img src="${event.target.result}" alt="New image ${i + 1}">
                    <span class="image-number">${i + 1}</span>
                `;
                preview.appendChild(div);
            };

            reader.readAsDataURL(file);
        }
    }

    closeEditModal() {
        document.getElementById('editVehicleModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    async handleEditVehicleSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'update_vehicle');
        this.appendSelectedCompany(formData);

        // Ensure event_suitable is always sent
        if (!formData.has('event_suitable')) {
            formData.append('event_suitable', '0');
        }

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Vehicle updated successfully!', 'success');
                this.closeEditModal();
                await this.loadFleet();
            } else {
                this.showNotification(data.message || 'Failed to update vehicle', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async toggleVehicleStatus(vehicleId, currentStatus) {
        const newStatus = currentStatus === 'available' ? 'rented' : 'available';

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=update_vehicle_status&vehicle_id=${vehicleId}&status=${newStatus}${this.getSelectedCompanyQuery()}`, {
                method: 'POST',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification(`Vehicle marked as ${newStatus}`, 'success');
                await this.loadFleet();
            } else {
                this.showNotification(data.message || 'Failed to update status', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async setMaintenance(vehicleId) {
        if (!confirm('Mark this vehicle as under maintenance?')) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=update_vehicle_status&vehicle_id=${vehicleId}&status=maintenance${this.getSelectedCompanyQuery()}`, {
                method: 'POST',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Vehicle marked for maintenance', 'success');
                await this.loadFleet();
            } else {
                this.showNotification(data.message || 'Failed to update status', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async deleteVehicle(vehicleId) {
        if (!confirm('Are you sure you want to delete this vehicle from your fleet?')) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=delete_car_hire_vehicle&vehicle_id=${vehicleId}${this.getSelectedCompanyQuery()}`, {
                method: 'POST',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Vehicle deleted successfully!', 'success');
                await this.loadFleet();
            } else {
                this.showNotification(data.message || 'Failed to delete vehicle', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async updateRentalStatus(rentalId, status, confirmMessage, successMessage) {
        if (confirmMessage && !confirm(confirmMessage)) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=update_car_hire_booking_status${this.getSelectedCompanyQuery()}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ rental_id: rentalId, status })
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification(successMessage || 'Rental updated successfully!', 'success');
                await this.loadRentals();
                await this.loadFleet();
            } else {
                this.showNotification(data.message || 'Failed to update rental', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    confirmRental(rentalId) {
        return this.updateRentalStatus(
            rentalId,
            'confirmed',
            'Confirm this rental request? The vehicle will be marked as rented out.',
            'Rental confirmed. Vehicle marked as rented out.'
        );
    }

    declineRental(rentalId) {
        return this.updateRentalStatus(
            rentalId,
            'declined',
            'Decline this rental request?',
            'Rental request declined.'
        );
    }

    cancelRental(rentalId) {
        return this.updateRentalStatus(
            rentalId,
            'cancelled',
            'Cancel this active rental? The vehicle will be released if no other confirmed rental holds it.',
            'Rental cancelled.'
        );
    }

    completeRental(rentalId) {
        return this.updateRentalStatus(
            rentalId,
            'completed',
            'Mark this rental as completed? The vehicle will become available again.',
            'Rental completed. Vehicle released back to the fleet.'
        );
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

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-GB');
    }

    formatCurrency(value) {
        const amount = Number(value || 0);
        return `${CONFIG.CURRENCY_CODE || 'MWK'} ${amount.toLocaleString()}`;
    }

    formatRentalStatusLabel(status) {
        const labels = {
            pending: 'Pending Request',
            confirmed: 'Active Rental',
            declined: 'Declined',
            cancelled: 'Cancelled',
            completed: 'Completed'
        };
        return labels[status] || status || 'Pending Request';
    }

    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
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
let carHireDashboard;
document.addEventListener('DOMContentLoaded', () => {
    carHireDashboard = new CarHireDashboard();
});

// Scroll to top functionality removed - using .back-to-top button from script.js instead

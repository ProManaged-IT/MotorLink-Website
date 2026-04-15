class DealerDashboard {
    constructor() {
        this.dealerInfo = null;
        this.inventory = [];
        this.currentUser = null;
        this.init();
    }

    async init() {
        await this.checkAuth();
        await this.loadDealerInfo();
        await this.loadInventory();
        await this.loadRecentActivity();
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

            if (data.user.type !== 'dealer') {
                alert('Access denied. This area is for dealers only.');
                window.location.href = 'index.html';
                return;
            }

            this.currentUser = data.user;
            document.getElementById('dealerName').textContent = data.user.name;

            // Update avatar
            if (window.app && window.app.updateUserAvatar) {
                window.app.updateUserAvatar(data.user.name);
            }
        } catch (error) {
            window.location.href = 'login.html';
        }
    }

    async loadDealerInfo() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_dealer_info`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.dealer) {
                this.dealerInfo = data.dealer;
                this.populateShowroomForm();
                this.populateBusinessForm();
            }
        } catch (error) {
        }
    }

    populateShowroomForm() {
        if (!this.dealerInfo) return;

        // Business Name (readonly)
        const businessName = document.getElementById('businessName');
        if (businessName) {
            businessName.value = this.dealerInfo.business_name || '';
        }

        // Basic text fields
        const fields = {
            'showroomDescription': 'description',
            'showroomPhone': 'phone',
            'showroomEmail': 'email',
            'showroomAddress': 'address',
            'showroomCity': 'city',
            'showroomDistrict': 'district'
        };

        for (const [fieldId, dataKey] of Object.entries(fields)) {
            const element = document.getElementById(fieldId);
            if (element && this.dealerInfo[dataKey]) {
                element.value = this.dealerInfo[dataKey];
            }
        }

        // Character counter for description
        const descTextarea = document.getElementById('showroomDescription');
        const charCount = document.getElementById('descCharCount');
        if (descTextarea && charCount) {
            charCount.textContent = descTextarea.value.length;
            descTextarea.addEventListener('input', (e) => {
                charCount.textContent = e.target.value.length;
            });
        }

        // Parse and populate time pickers from hour ranges
        // Format expected: "08:00 - 17:00" or "08:00 AM - 05:00 PM"
        this.populateTimePickers('weekdayOpen', 'weekdayClose', this.dealerInfo.weekday_hours);
        this.populateTimePickers('saturdayOpen', 'saturdayClose', this.dealerInfo.saturday_hours);
        this.populateTimePickers('sundayOpen', 'sundayClose', this.dealerInfo.sunday_hours);
    }

    populateTimePickers(openId, closeId, hourRange) {
        if (!hourRange || hourRange.toLowerCase() === 'closed') return;

        const openEl = document.getElementById(openId);
        const closeEl = document.getElementById(closeId);
        if (!openEl || !closeEl) return;

        // Try to parse time range like "8:00 AM - 5:00 PM" or "08:00 - 17:00"
        const match = hourRange.match(/(\d{1,2}):(\d{2})\s*(AM|PM)?\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)?/i);
        if (match) {
            let openHour = parseInt(match[1]);
            const openMin = match[2];
            const openAMPM = match[3];
            let closeHour = parseInt(match[4]);
            const closeMin = match[5];
            const closeAMPM = match[6];

            // Convert to 24-hour format if AM/PM present
            if (openAMPM) {
                if (openAMPM.toUpperCase() === 'PM' && openHour !== 12) openHour += 12;
                if (openAMPM.toUpperCase() === 'AM' && openHour === 12) openHour = 0;
            }
            if (closeAMPM) {
                if (closeAMPM.toUpperCase() === 'PM' && closeHour !== 12) closeHour += 12;
                if (closeAMPM.toUpperCase() === 'AM' && closeHour === 12) closeHour = 0;
            }

            openEl.value = `${String(openHour).padStart(2, '0')}:${openMin}`;
            closeEl.value = `${String(closeHour).padStart(2, '0')}:${closeMin}`;
        }
    }

    populateBusinessForm() {
        if (!this.dealerInfo) return;

        // Business Name (already populated in showroom form, but populate here too)
        const businessName = document.getElementById('businessName');
        if (businessName) {
            businessName.value = this.dealerInfo.business_name || '';
        }

        // Owner Name - use user's full name (readonly)
        const ownerName = document.getElementById('ownerName');
        if (ownerName) {
            ownerName.value = this.dealerInfo.user_full_name || this.currentUser?.full_name || this.currentUser?.name || this.dealerInfo.owner_name || '';
        }

        // Physical Address
        const businessAddress = document.getElementById('businessAddress');
        if (businessAddress) {
            businessAddress.value = this.dealerInfo.address || '';
        }

        // Location - load locations dropdown
        this.loadBusinessLocations().then(() => {
            const businessLocation = document.getElementById('businessLocation');
            if (businessLocation && this.dealerInfo.location_id) {
                businessLocation.value = this.dealerInfo.location_id;
            }
        });

        // Other business fields
        const fields = {
            'businessRegNumber': 'registration_number',
            'taxId': 'tax_id',
            'businessType': 'business_type',
            'yearsInBusiness': 'years_in_business',
            'yearsEstablished': 'years_established'
        };

        for (const [fieldId, dataKey] of Object.entries(fields)) {
            const element = document.getElementById(fieldId);
            if (element && this.dealerInfo[dataKey]) {
                element.value = this.dealerInfo[dataKey];
            }
        }
    }

    async loadBusinessLocations() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_locations`);
            const data = await response.json();

            const locationSelect = document.getElementById('businessLocation');
            if (locationSelect && data.locations) {
                // Keep the first option (Select Location...)
                const firstOption = locationSelect.options[0];
                locationSelect.innerHTML = '';
                if (firstOption) {
                    locationSelect.appendChild(firstOption);
                } else {
                    locationSelect.innerHTML = '<option value="">Select Location...</option>';
                }

                // Add locations
                data.locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.id;
                    option.textContent = location.name + (location.region ? ` (${location.region})` : '');
                    locationSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading locations:', error);
        }
    }

    async loadInventory() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=dealer_inventory`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success) {
                this.inventory = data.cars || [];
                this.currentFilteredInventory = [...this.inventory];
                this.displayInventory(this.currentFilteredInventory);
                this.updateInventoryStats(this.inventory);
            }
        } catch (error) {
            document.getElementById('inventoryGrid').innerHTML =
                '<p class="error-message">Failed to load inventory</p>';
        }
    }

    displayInventory(cars) {
        const grid = document.getElementById('inventoryGrid');

        if (!cars || cars.length === 0) {
            grid.innerHTML = '<p class="text-muted">No cars match the current filter. Try adjusting your filters!</p>';
            return;
        }

        grid.innerHTML = cars.map(car => {
            // Construct proper web URL for image
            let imageUrl = '';
            if (car.featured_image_id) {
                imageUrl = `${CONFIG.API_URL}?action=image&id=${car.featured_image_id}`;
            } else if (car.featured_image) {
                imageUrl = `uploads/${car.featured_image}`;
            } else if (car.image_url && !car.image_url.startsWith('file://') && !car.image_url.includes(':/')) {
                imageUrl = car.image_url;
            }

            return `
                <div class="car-card" data-status="${car.status}">
                    <div class="car-image">
                        ${imageUrl ?
                            `<img src="${imageUrl}" alt="${car.title}">` :
                            `<div class="car-placeholder no-image-nudge">
                                <i class="fas fa-camera"></i>
                                <p>No photos yet</p>
                                <button class="btn btn-small btn-outline-light nudge-btn" onclick="event.stopPropagation();dealerDashboard.editCar(${car.id})">
                                    <i class="fas fa-plus"></i> Add Photos
                                </button>
                            </div>`
                        }
                        <span class="status-badge status-${car.status}">${this.getStatusLabel(car.status)}</span>
                    </div>
                    <div class="car-details">
                        <h3>${car.title}</h3>
                        <p class="car-year-make">${car.year || 'N/A'} ${car.make || ''} ${car.model || ''}</p>
                        <div class="car-price">MWK ${parseInt(car.price).toLocaleString()}</div>
                        <div class="car-meta">
                            <span class="views"><i class="fas fa-eye"></i> ${car.views || 0} views</span>
                            <span class="date"><i class="fas fa-calendar"></i> ${this.formatDate(car.created_at)}</span>
                        </div>
                        <div class="card-actions">
                            <button class="btn btn-small btn-secondary" onclick="dealerDashboard.viewCar(${car.id})" title="View Listing">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-small btn-primary" onclick="dealerDashboard.editCar(${car.id})" title="Edit Listing">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            ${car.status === 'active' ? `
                                <button class="btn btn-small btn-success" onclick="dealerDashboard.markAsSold(${car.id})" title="Mark as Sold">
                                    <i class="fas fa-handshake"></i> Mark Sold
                                </button>
                            ` : ''}
                            ${car.status === 'sold' ? `
                                <button class="btn btn-small btn-warning" onclick="dealerDashboard.markAsActive(${car.id})" title="Reactivate Listing">
                                    <i class="fas fa-redo"></i> Reactivate
                                </button>
                            ` : ''}
                            <button class="btn btn-small btn-danger" onclick="dealerDashboard.deleteCar(${car.id})" title="Delete Listing">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    getStatusLabel(status) {
        const labels = {
            'active': 'Active',
            'sold': 'Sold',
            'pending': 'Pending',
            'inactive': 'Inactive',
            'approved': 'Active'
        };
        return labels[status] || status;
    }

    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = Math.abs(now - date);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;
        if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
        return date.toLocaleDateString();
    }

    updateInventoryStats(cars) {
        const total = cars.length;
        const active = cars.filter(c => c.status === 'active' || c.status === 'approved').length;
        const sold = cars.filter(c => c.status === 'sold').length;
        const pending = cars.filter(c => c.status === 'pending').length;
        const inactive = cars.filter(c => c.status === 'inactive').length;
        const totalViews = cars.reduce((sum, c) => sum + (parseInt(c.views) || 0), 0);

        // Update main stats
        document.getElementById('totalListings').textContent = total;
        document.getElementById('activeListings').textContent = active;
        document.getElementById('pendingListings').textContent = pending;
        document.getElementById('totalViews').textContent = totalViews;

        // Update inventory count badge
        const inventoryCount = document.getElementById('inventoryCount');
        if (inventoryCount) {
            inventoryCount.textContent = `${total} car${total !== 1 ? 's' : ''}`;
        }

        // Update stat cards
        const activeInventoryCount = document.getElementById('activeInventoryCount');
        const soldInventoryCount = document.getElementById('soldInventoryCount');
        const pendingInventoryCount = document.getElementById('pendingInventoryCount');
        const inactiveInventoryCount = document.getElementById('inactiveInventoryCount');

        if (activeInventoryCount) activeInventoryCount.textContent = active;
        if (soldInventoryCount) soldInventoryCount.textContent = sold;
        if (pendingInventoryCount) pendingInventoryCount.textContent = pending;
        if (inactiveInventoryCount) inactiveInventoryCount.textContent = inactive;

        // Update tab counts
        const tabCountAll = document.getElementById('tabCountAll');
        const tabCountActive = document.getElementById('tabCountActive');
        const tabCountSold = document.getElementById('tabCountSold');
        const tabCountPending = document.getElementById('tabCountPending');
        const tabCountInactive = document.getElementById('tabCountInactive');

        if (tabCountAll) tabCountAll.textContent = total;
        if (tabCountActive) tabCountActive.textContent = active;
        if (tabCountSold) tabCountSold.textContent = sold;
        if (tabCountPending) tabCountPending.textContent = pending;
        if (tabCountInactive) tabCountInactive.textContent = inactive;
    }

    async loadRecentActivity() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=dealer_recent_activity`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.activities) {
                this.displayRecentActivity(data.activities);
            } else {
                // Fallback to generating activity from inventory data
                this.generateActivityFromInventory();
            }
        } catch (error) {
            // Fallback to generating activity from inventory data
            this.generateActivityFromInventory();
        }
    }

    generateActivityFromInventory() {
        if (!this.inventory || this.inventory.length === 0) {
            document.getElementById('recentActivity').innerHTML =
                '<p class="text-muted">No recent activity. Start by adding cars to your inventory!</p>';
            return;
        }

        const activities = [];

        // Sort cars by created_at date (most recent first)
        const sortedByDate = [...this.inventory].sort((a, b) => {
            const dateA = new Date(a.created_at || 0);
            const dateB = new Date(b.created_at || 0);
            return dateB - dateA;
        });

        // Add recent listings (last 3)
        sortedByDate.slice(0, 3).forEach(car => {
            const createdDate = new Date(car.created_at);
            const now = new Date();
            const diffTime = Math.abs(now - createdDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            activities.push({
                type: 'listing_added',
                icon: 'fa-plus-circle',
                color: 'success',
                title: 'New Listing Added',
                description: `${car.year} ${car.make} ${car.model}`,
                time: diffDays === 0 ? 'Today' : diffDays === 1 ? 'Yesterday' : `${diffDays} days ago`,
                timestamp: createdDate
            });
        });

        // Sort cars by views (most viewed)
        const sortedByViews = [...this.inventory].sort((a, b) => {
            return (parseInt(b.views) || 0) - (parseInt(a.views) || 0);
        });

        // Add top viewed cars (max 2)
        sortedByViews.slice(0, 2).forEach(car => {
            if (parseInt(car.views) > 0) {
                activities.push({
                    type: 'views',
                    icon: 'fa-eye',
                    color: 'info',
                    title: 'Popular Listing',
                    description: `${car.year} ${car.make} ${car.model} - ${car.views} views`,
                    time: 'Recent',
                    timestamp: new Date(car.updated_at || car.created_at)
                });
            }
        });

        // Sort activities by timestamp (most recent first)
        activities.sort((a, b) => b.timestamp - a.timestamp);

        this.displayRecentActivity(activities.slice(0, 5));
    }

    displayRecentActivity(activities) {
        const container = document.getElementById('recentActivity');

        if (!activities || activities.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent activity.</p>';
            return;
        }

        container.innerHTML = activities.map(activity => `
            <div class="activity-item">
                <div class="activity-icon ${activity.color}">
                    <i class="fas ${activity.icon}"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">${activity.title}</div>
                    <div class="activity-description">${activity.description}</div>
                    <div class="activity-time">${activity.time}</div>
                </div>
            </div>
        `).join('');
    }

    setupEventListeners() {
        // Showroom form
        const showroomForm = document.getElementById('showroomForm');
        if (showroomForm) {
            showroomForm.addEventListener('submit', (e) => this.handleShowroomSubmit(e));
        }

        // Business info form
        const businessForm = document.getElementById('businessInfoForm');
        if (businessForm) {
            businessForm.addEventListener('submit', (e) => this.handleBusinessSubmit(e));
        }

        // Add car form
        const addCarForm = document.getElementById('addCarForm');
        if (addCarForm) {
            addCarForm.addEventListener('submit', (e) => this.handleAddCar(e));
        }

        // Logo upload
        const logoInput = document.getElementById('showroomLogo');
        if (logoInput) {
            logoInput.addEventListener('change', (e) => this.handleLogoUpload(e));
        }

        // Car images preview
        const carImages = document.getElementById('carImages');
        if (carImages) {
            carImages.addEventListener('change', (e) => this.previewCarImages(e));
        }

        // Inventory tabs
        const inventoryTabs = document.querySelectorAll('.inventory-tab');
        inventoryTabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                // Remove active from all tabs
                inventoryTabs.forEach(t => t.classList.remove('active'));
                // Add active to clicked tab
                tab.classList.add('active');
                // Filter inventory
                const status = tab.getAttribute('data-status');
                this.filterInventory(status);
            });
        });

        // Search inventory
        const searchInput = document.getElementById('searchInventory');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchInventory(e.target.value);
            });
        }

        // Clear filters
        const clearFilters = document.getElementById('clearFilters');
        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                inventoryTabs.forEach(t => t.classList.remove('active'));
                const allTab = document.querySelector('.inventory-tab[data-status="all"]');
                if (allTab) allTab.classList.add('active');
                this.filterInventory('all');
            });
        }
    }

    async handleShowroomSubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'update_dealer_showroom');

        // Format operating hours from time pickers
        const weekdayHours = this.formatOperatingHours('weekdayOpen', 'weekdayClose');
        const saturdayHours = this.formatOperatingHours('saturdayOpen', 'saturdayClose');
        const sundayHours = this.formatOperatingHours('sundayOpen', 'sundayClose');

        if (weekdayHours) formData.set('weekday_hours', weekdayHours);
        if (saturdayHours) formData.set('saturday_hours', saturdayHours);
        if (sundayHours) formData.set('sunday_hours', sundayHours);

        // Remove individual time fields (not needed in backend)
        formData.delete('weekday_open');
        formData.delete('weekday_close');
        formData.delete('saturday_open');
        formData.delete('saturday_close');
        formData.delete('sunday_open');
        formData.delete('sunday_close');

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Business details updated successfully!', 'success');
                await this.loadDealerInfo();
            } else {
                this.showNotification(data.message || 'Failed to update business details', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    formatOperatingHours(openId, closeId) {
        const openEl = document.getElementById(openId);
        const closeEl = document.getElementById(closeId);

        if (!openEl || !closeEl || !openEl.value || !closeEl.value) {
            return 'Closed';
        }

        // Convert 24-hour time to 12-hour with AM/PM
        const formatTime = (time24) => {
            const [hours, minutes] = time24.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
            return `${hour12}:${minutes} ${ampm}`;
        };

        return `${formatTime(openEl.value)} - ${formatTime(closeEl.value)}`;
    }

    async handleBusinessSubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'update_dealer_business');

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Business information updated successfully!', 'success');
                await this.loadDealerInfo();
            } else {
                this.showNotification(data.message || 'Failed to update business information', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async handleAddCar(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'dealer_add_car');

        // Get car images
        const imageFiles = document.getElementById('carImages').files;
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
                this.showNotification('Car added successfully!', 'success');
                e.target.reset();
                document.getElementById('imagePreview').innerHTML = '';
                await this.loadInventory();
                this.switchSection('inventory');
            } else {
                this.showNotification(data.message || 'Failed to add car', 'error');
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
        formData.append('action', 'upload_dealer_logo');
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
                this.dealerInfo.logo_url = data.logo_url;
            } else {
                this.showNotification(data.message || 'Failed to upload logo', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    previewCarImages(e) {
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

    filterInventory() {
        const statusFilter = document.getElementById('statusFilter').value;
        const searchTerm = document.getElementById('searchInventory').value.toLowerCase();

        let filtered = this.inventory;

        if (statusFilter) {
            filtered = filtered.filter(car => car.status === statusFilter);
        }

        if (searchTerm) {
            filtered = filtered.filter(car =>
                car.title.toLowerCase().includes(searchTerm) ||
                car.make.toLowerCase().includes(searchTerm) ||
                car.model.toLowerCase().includes(searchTerm)
            );
        }

        this.displayInventory(filtered);
    }

    async editCar(carId) {
        try {
            // Fetch car details
            const response = await fetch(`${CONFIG.API_URL}?action=get_listing&id=${carId}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (!data.success || !data.listing) {
                this.showNotification('Failed to load car details', 'error');
                return;
            }

            const car = data.listing;

            // Load makes, models, and locations for the form
            await this.loadEditFormData();

            // Populate form fields
            document.getElementById('editCarId').value = car.id;
            document.getElementById('editTitle').value = car.title || '';
            document.getElementById('editPrice').value = car.price || '';
            document.getElementById('editYear').value = car.year || '';
            document.getElementById('editMileage').value = car.mileage || '';
            document.getElementById('editFuelType').value = car.fuel_type || 'petrol';
            document.getElementById('editTransmission').value = car.transmission || 'manual';
            document.getElementById('editCondition').value = car.condition_type || 'good';
            document.getElementById('editColor').value = car.exterior_color || '';
            document.getElementById('editDescription').value = car.description || '';
            document.getElementById('editStatus').value = car.status || 'active';
            
            // Set negotiable checkbox
            const negotiableCheckbox = document.getElementById('editNegotiable');
            if (negotiableCheckbox) {
                negotiableCheckbox.checked = car.negotiable == 1;
            }

            // Set make and model
            if (car.make_id) {
                document.getElementById('editMake').value = car.make_id;
                await this.loadEditModels(car.make_id);
                if (car.model_id) {
                    document.getElementById('editModel').value = car.model_id;
                }
            }

            // Set location
            if (car.location_id) {
                document.getElementById('editLocation').value = car.location_id;
            }

            // Display current images
            const imagesContainer = document.getElementById('editCurrentImages');
            if (car.images && car.images.length > 0) {
                imagesContainer.innerHTML = car.images.map(img => {
                    // Construct proper web URL for image
                    const imageUrl = img.id
                        ? `${CONFIG.API_URL}?action=image&id=${img.id}`
                        : (img.filename ? `uploads/${img.filename}` : img.file_path);

                    return `
                        <div class="current-image-item ${img.is_primary ? 'featured' : ''}" data-image-id="${img.id}">
                            <img src="${imageUrl}" alt="Car image">
                            <button type="button" class="btn-set-featured ${img.is_primary ? 'active' : ''}"
                                    onclick="dealerDashboard.setFeaturedImage(${img.id}, ${car.id})"
                                    title="${img.is_primary ? 'Featured Image' : 'Set as Featured'}">
                                <i class="fas fa-star"></i>
                            </button>
                            <button type="button" class="btn-remove-image" onclick="dealerDashboard.removeImage(${img.id}, ${car.id})" title="Remove image">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                }).join('');
            } else {
                imagesContainer.innerHTML = '<p class="text-muted">No images uploaded</p>';
            }

            // Show modal
            document.getElementById('editCarModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Setup form submit handler
            const form = document.getElementById('editCarForm');
            form.onsubmit = (e) => this.handleEditCarSubmit(e);

            // Setup new images upload (upload immediately when selected)
            const newImageInput = document.getElementById('editNewImages');
            if (newImageInput) {
                newImageInput.onchange = (e) => this.uploadNewImages(e, car.id);
            }

        } catch (error) {
            this.showNotification('Failed to load car details', 'error');
        }
    }

    async loadEditFormData() {
        try {
            // Load makes
            const makesResponse = await fetch(`${CONFIG.API_URL}?action=get_makes`);
            const makesData = await makesResponse.json();

            const makeSelect = document.getElementById('editMake');
            if (makeSelect) {
                makeSelect.innerHTML = '<option value="">Select Make</option>';
                if (makesData.makes) {
                    makesData.makes.forEach(make => {
                        makeSelect.innerHTML += `<option value="${make.id}">${make.name}</option>`;
                    });
                }

                // Bind make change event
                makeSelect.addEventListener('change', (e) => {
                    this.loadEditModels(e.target.value);
                });
            }

            // Load locations
            const locationsResponse = await fetch(`${CONFIG.API_URL}?action=get_locations`);
            const locationsData = await locationsResponse.json();

            const locationSelect = document.getElementById('editLocation');
            if (locationSelect) {
                locationSelect.innerHTML = '<option value="">Select Location</option>';
                if (locationsData.locations) {
                    locationsData.locations.forEach(location => {
                        locationSelect.innerHTML += `<option value="${location.id}">${location.name}</option>`;
                    });
                }
            }
        } catch (error) {
            console.error('Error loading edit form data:', error);
        }
    }

    async loadEditModels(makeId) {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_models&make_id=${makeId}`);
            const data = await response.json();

            const modelSelect = document.getElementById('editModel');
            if (modelSelect) {
                modelSelect.innerHTML = '<option value="">Select Model</option>';
                if (data.models) {
                    // Group models by name to avoid duplicates
                    const uniqueModels = new Map();
                    data.models.forEach(model => {
                        if (!uniqueModels.has(model.name)) {
                            uniqueModels.set(model.name, model);
                        }
                    });
                    
                    Array.from(uniqueModels.values()).forEach(model => {
                        modelSelect.innerHTML += `<option value="${model.id}">${model.name}</option>`;
                    });
                    
                    // Setup model change listener to load variations
                    if (!modelSelect.dataset.variationListenerAdded) {
                        modelSelect.dataset.variationListenerAdded = 'true';
                        modelSelect.addEventListener('change', async (e) => {
                            const modelId = e.target.value;
                            if (modelId && window.modelVariationsHelper) {
                                await window.modelVariationsHelper.loadModelVariations(modelId, {
                                    engineSizeSelect: 'carEngineSize',
                                    fuelTankSelect: 'carFuelTankCapacity',
                                    drivetrainSelect: 'carDrivetrain',
                                    transmissionSelect: 'carTransmission'
                                });
                            }
                        });
                    }
                }
            }
        } catch (error) {
            console.error('Error loading models:', error);
        }
    }

    closeEditModal() {
        document.getElementById('editCarModal').style.display = 'none';
        document.body.style.overflow = '';

        // Clear new images preview and input
        const newImageInput = document.getElementById('editNewImages');
        if (newImageInput) {
            newImageInput.value = '';
        }
        const preview = document.getElementById('editNewImagesPreview');
        if (preview) {
            preview.innerHTML = '';
        }
    }

    async uploadNewImages(e, carId) {
        const files = e.target.files;
        if (!files || files.length === 0) return;

        // Validate file count
        if (files.length > 10) {
            this.showNotification('Maximum 10 images allowed per upload', 'error');
            e.target.value = '';
            return;
        }

        // Validate file types
        for (let i = 0; i < files.length; i++) {
            if (!files[i].type.match('image.*')) {
                this.showNotification('Only image files are allowed', 'error');
                e.target.value = '';
                return;
            }
        }

        // Create FormData and upload
        const formData = new FormData();
        formData.append('action', 'upload_listing_images');
        formData.append('listing_id', carId);

        for (let i = 0; i < files.length; i++) {
            formData.append('images[]', files[i]);
        }

        try {
            this.showNotification('Uploading images...', 'info');

            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            if (!response.ok) {
                // Try to parse error response
                try {
                    const errorData = await response.json();
                    throw new Error(errorData.message || `Server error: ${response.status}`);
                } catch (parseError) {
                    throw new Error(`Server error: ${response.status} ${response.statusText}`);
                }
            }

            const data = await response.json();

            if (data.success) {
                this.showNotification(`${files.length} image(s) uploaded successfully!`, 'success');

                // Clear the input
                e.target.value = '';

                // Reload the images in the edit modal to show newly uploaded images
                // Fetch fresh car data with images
                const carResponse = await fetch(`${CONFIG.API_URL}?action=get_listing&id=${carId}`, {
                    credentials: 'include'
                });
                const carData = await carResponse.json();

                if (carData.success && carData.listing && carData.listing.images) {
                    // Refresh the images display
                    const imagesContainer = document.getElementById('editCurrentImages');
                    if (carData.listing.images.length > 0) {
                        imagesContainer.innerHTML = carData.listing.images.map(img => {
                            // Construct proper web URL for image
                            const imageUrl = img.id
                                ? `${CONFIG.API_URL}?action=image&id=${img.id}`
                                : (img.filename ? `uploads/${img.filename}` : img.file_path);

                            return `
                                <div class="current-image-item ${img.is_primary ? 'featured' : ''}" data-image-id="${img.id}">
                                    <img src="${imageUrl}" alt="Car image">
                                    <button type="button" class="btn-set-featured ${img.is_primary ? 'active' : ''}"
                                            onclick="dealerDashboard.setFeaturedImage(${img.id}, ${carId})"
                                            title="${img.is_primary ? 'Featured Image' : 'Set as Featured'}">
                                        <i class="fas fa-star"></i>
                                    </button>
                                    <button type="button" class="btn-remove-image" onclick="dealerDashboard.removeImage(${img.id}, ${carId})" title="Remove image">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            `;
                        }).join('');
                    }
                }
            } else {
                // Show detailed error message
                const errorMsg = data.message || 'Failed to upload images';
                this.showNotification(errorMsg, 'error');
            }
        } catch (error) {
            console.error('Upload error:', error);
            
            // Provide user-friendly error messages
            let errorMessage = 'Error uploading images. ';
            if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                errorMessage += 'Network error - please check your internet connection and try again.';
            } else if (error.message.includes('400')) {
                errorMessage += 'Invalid file format or size. Please ensure files are images (JPG, PNG, GIF, WEBP) and under 10MB each.';
            } else if (error.message.includes('403')) {
                errorMessage += 'Permission denied - you can only upload images to your own listings.';
            } else if (error.message.includes('404')) {
                errorMessage += 'Listing not found. Please refresh the page and try again.';
            } else {
                errorMessage += error.message || 'Please try again.';
            }
            
            this.showNotification(errorMessage, 'error');
        }
    }

    async handleEditCarSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const carId = document.getElementById('editCarId').value;
        
        // Get the original car details for validation
        const originalCar = this.inventory.find(c => c.id == carId);
        if (!originalCar) {
            this.showNotification('Original car not found', 'error');
            return;
        }

        // Get form values
        const newMakeId = parseInt(document.getElementById('editMake')?.value) || null;
        const newModelId = parseInt(document.getElementById('editModel')?.value) || null;
        const newYear = parseInt(document.getElementById('editYear')?.value) || 0;
        const newPrice = parseFloat(document.getElementById('editPrice')?.value) || 0;

        // VALIDATION: Prevent changing to a completely different car
        // This matches the STRICT backend validation rules
        //
        // What CAN be changed freely (minor details):
        // - title, description, color, transmission, fuel_type, condition_type
        // - engine_size, doors, seats, drivetrain, location_id, negotiable
        //
        // What is RESTRICTED (to prevent fraud):
        // - make_id (cannot change), model_id (must stay within same make)
        // - year (±2 years max), price (±50% max), mileage (±50,000 km max)

        // 1. MAKE CANNOT BE CHANGED - Backend will reject this completely
        if (newMakeId && originalCar.make_id && newMakeId !== parseInt(originalCar.make_id)) {
            this.showNotification(
                'Error: Cannot change car make. You cannot change the brand to a different one. ' +
                'This is to prevent fraud. If you made a mistake, please delete and create a new listing.',
                'error'
            );
            return;
        }

        // 2. MODEL: If changed, must belong to the same make (backend validates this)
        if (newModelId && originalCar.model_id && newModelId !== parseInt(originalCar.model_id)) {
            if (!confirm('⚠️ Warning: You are changing the model. Make sure it belongs to the same make. Continue?')) {
                return;
            }
        }

        // 3. YEAR: Maximum 2 year difference (backend enforces this strictly)
        if (newYear && originalCar.year && Math.abs(newYear - originalCar.year) > 2) {
            this.showNotification(
                `Error: Year can only be changed by up to 2 years (from ${originalCar.year}). ` +
                'Larger changes suggest a completely different vehicle. ' +
                'If this is a different car, please create a new listing.',
                'error'
            );
            return;
        }

        // 4. PRICE: Maximum 50% change (backend enforces this)
        if (newPrice && originalCar.price) {
            const originalPrice = parseFloat(originalCar.price);
            const priceChangePercent = Math.abs((newPrice - originalPrice) / originalPrice * 100);

            if (priceChangePercent > 50) {
                this.showNotification(
                    `Error: Price change too large. You can only change price by up to 50%. ` +
                    `Original: MWK ${originalPrice.toLocaleString()}, New: MWK ${newPrice.toLocaleString()}. ` +
                    'This prevents changing a cheap car to an expensive one.',
                    'error'
                );
                return;
            }
        }

        // 5. MILEAGE: Can only change by max 50,000 km (backend enforces this)
        const newMileage = parseInt(document.getElementById('editMileage')?.value) || 0;
        if (newMileage && originalCar.mileage) {
            const originalMileage = parseInt(originalCar.mileage);

            if (Math.abs(newMileage - originalMileage) > 50000) {
                this.showNotification(
                    `Error: Mileage change too large. Maximum change is ±50,000 km. ` +
                    `Original: ${originalMileage.toLocaleString()} km, New: ${newMileage.toLocaleString()} km. ` +
                    'This prevents changing to a completely different vehicle.',
                    'error'
                );
                return;
            }
        }

        // Convert FormData to JSON for the update_listing endpoint
        const formData = new FormData(form);
        const data = {
            action: 'update_listing',
            listing_id: carId
        };

        // Map form fields to API fields
        for (let [key, value] of formData.entries()) {
            if (key !== 'car_id') {
                data[key] = value;
            }
        }

        // Handle checkboxes
        data.negotiable = document.getElementById('editNegotiable')?.checked ? 1 : 0;

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('Car updated successfully!', 'success');
                this.closeEditModal();
                await this.loadInventory();
            } else {
                this.showNotification(result.message || 'Failed to update car', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async removeImage(imageId, carId) {
        if (!confirm('Are you sure you want to remove this image?')) {
            return;
        }

        try {
            const response = await fetch(CONFIG.API_URL, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_car_image&image_id=${imageId}`
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Image removed successfully!', 'success');
                // Reload the car details in the modal
                await this.editCar(carId);
            } else {
                this.showNotification(data.message || 'Failed to remove image', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async setFeaturedImage(imageId, carId) {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=set_featured_image`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    listing_id: carId,
                    image_id: imageId
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Featured image updated!', 'success');
                // Reload the car details in the modal to refresh featured status
                await this.editCar(carId);
            } else {
                this.showNotification(data.message || 'Failed to set featured image', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    viewCar(carId) {
        window.location.href = `car.html?id=${carId}`;
    }

    async deleteCar(carId) {
        if (!confirm('Are you sure you want to delete this car listing?')) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=dealer_delete_car&car_id=${carId}`, {
                method: 'POST',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Car deleted successfully!', 'success');
                await this.loadInventory();
            } else {
                this.showNotification(data.message || 'Failed to delete car', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async markAsSold(carId) {
        if (!confirm('Mark this car as SOLD? This will hide it from active listings.')) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=update_listing_status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    listing_id: carId,
                    status: 'sold'
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Car marked as sold!', 'success');
                await this.loadInventory();
            } else {
                this.showNotification(data.message || 'Failed to update status', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    async markAsActive(carId) {
        if (!confirm('Reactivate this car listing? It will appear in active listings again.')) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=update_listing_status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    listing_id: carId,
                    status: 'active'
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Car reactivated successfully!', 'success');
                await this.loadInventory();
            } else {
                this.showNotification(data.message || 'Failed to update status', 'error');
            }
        } catch (error) {
            this.showNotification('An error occurred. Please try again.', 'error');
        }
    }

    filterInventory(status) {
        if (!this.inventory) return;

        if (status === 'all' || !status) {
            this.currentFilteredInventory = [...this.inventory];
        } else {
            this.currentFilteredInventory = this.inventory.filter(car => car.status === status);
        }

        this.displayInventory(this.currentFilteredInventory);
    }

    searchInventory(searchTerm) {
        if (!this.inventory) return;

        const term = searchTerm.toLowerCase().trim();
        
        if (!term) {
            this.currentFilteredInventory = [...this.inventory];
        } else {
            this.currentFilteredInventory = this.inventory.filter(car => {
                const title = (car.title || '').toLowerCase();
                const make = (car.make || '').toLowerCase();
                const model = (car.model || '').toLowerCase();
                const year = (car.year || '').toString();
                
                return title.includes(term) || 
                       make.includes(term) || 
                       model.includes(term) || 
                       year.includes(term);
            });
        }

        this.displayInventory(this.currentFilteredInventory);
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

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;

        document.body.appendChild(notification);

        // Show notification
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);

        // Hide and remove notification
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
}

// Initialize dashboard when DOM is loaded
let dealerDashboard;
document.addEventListener('DOMContentLoaded', () => {
    dealerDashboard = new DealerDashboard();
});

// Scroll to top functionality removed - using .back-to-top button from script.js instead

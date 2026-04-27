// Add Modals for Admin Dashboard
// Separated for better organization

// ===== ADD GARAGE MODAL =====
function showAddGarageModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-wrench"></i> Add New Garage</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addGarageForm" class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-garage-name">Garage Name *</label>
                            <input type="text" id="add-garage-name" name="name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-garage-owner">Owner Name *</label>
                            <input type="text" id="add-garage-owner" name="owner_name" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-garage-email">Email *</label>
                            <input type="email" id="add-garage-email" name="email" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-garage-phone">Phone *</label>
                            <input type="tel" id="add-garage-phone" name="phone" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-garage-whatsapp">WhatsApp</label>
                            <input type="tel" id="add-garage-whatsapp" name="whatsapp" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-garage-recovery">Recovery Number</label>
                            <input type="tel" id="add-garage-recovery" name="recovery_number" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-garage-location">Location *</label>
                        <select id="add-garage-location" name="location_id" required class="form-control">
                            <option value="">Select Location</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="add-garage-address">Address</label>
                        <textarea id="add-garage-address" name="address" rows="2" class="form-control"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="add-garage-description">Description</label>
                        <textarea id="add-garage-description" name="description" rows="3" class="form-control"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-garage-hours">Business Hours</label>
                            <input type="text" id="add-garage-hours" name="business_hours" placeholder="Mon-Fri: 8AM-5PM" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-garage-experience">Years of Experience</label>
                            <input type="number" id="add-garage-experience" name="years_experience" min="0" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-garage-website">Website</label>
                        <input type="url" id="add-garage-website" name="website" placeholder="https://" class="form-control">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddGarage()"><i class="fas fa-save"></i> Add Garage</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    loadLocationsForModal('add-garage-location');
}

async function submitAddGarage() {
    const form = document.getElementById('addGarageForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        name: document.getElementById('add-garage-name').value,
        owner_name: document.getElementById('add-garage-owner').value,
        email: document.getElementById('add-garage-email').value,
        phone: document.getElementById('add-garage-phone').value,
        whatsapp: document.getElementById('add-garage-whatsapp').value || null,
        recovery_number: document.getElementById('add-garage-recovery').value || null,
        location_id: document.getElementById('add-garage-location').value,
        address: document.getElementById('add-garage-address').value || null,
        description: document.getElementById('add-garage-description').value || null,
        business_hours: document.getElementById('add-garage-hours').value || null,
        years_experience: document.getElementById('add-garage-experience').value || 0,
        website: document.getElementById('add-garage-website').value || null
    };

    try {
        const response = await admin.apiCall('add_garage', formData, 'POST');
        if (response.success) {
            admin.showAlert('success', 'Garage added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadGarages();
        } else {
            admin.showAlert('error', response.message || 'Failed to add garage');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding garage: ' + error.message);
    }
}

// ===== ADD DEALER MODAL =====
function showAddDealerModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-store"></i> Add New Dealer</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addDealerForm" class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-dealer-business">Business Name *</label>
                            <input type="text" id="add-dealer-business" name="business_name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-dealer-owner">Owner Name *</label>
                            <input type="text" id="add-dealer-owner" name="owner_name" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-dealer-email">Email *</label>
                            <input type="email" id="add-dealer-email" name="email" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-dealer-phone">Phone *</label>
                            <input type="tel" id="add-dealer-phone" name="phone" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-dealer-whatsapp">WhatsApp</label>
                            <input type="tel" id="add-dealer-whatsapp" name="whatsapp" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-dealer-location">Location *</label>
                            <select id="add-dealer-location" name="location_id" required class="form-control">
                                <option value="">Select Location</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-dealer-address">Address</label>
                        <textarea id="add-dealer-address" name="address" rows="2" class="form-control"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="add-dealer-description">Description</label>
                        <textarea id="add-dealer-description" name="description" rows="3" class="form-control"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-dealer-hours">Business Hours</label>
                            <input type="text" id="add-dealer-hours" name="business_hours" placeholder="Mon-Sat: 8AM-6PM" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-dealer-established">Year Established</label>
                            <input type="number" id="add-dealer-established" name="years_established" min="1950" max="2025" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-dealer-website">Website</label>
                        <input type="url" id="add-dealer-website" name="website" placeholder="https://" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="add-dealer-verified" name="verified">
                            Verified Dealer
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddDealer()"><i class="fas fa-save"></i> Add Dealer</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    loadLocationsForModal('add-dealer-location');
}

async function submitAddDealer() {
    const form = document.getElementById('addDealerForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        business_name: document.getElementById('add-dealer-business').value,
        owner_name: document.getElementById('add-dealer-owner').value,
        email: document.getElementById('add-dealer-email').value,
        phone: document.getElementById('add-dealer-phone').value,
        whatsapp: document.getElementById('add-dealer-whatsapp').value || null,
        location_id: document.getElementById('add-dealer-location').value,
        address: document.getElementById('add-dealer-address').value || null,
        description: document.getElementById('add-dealer-description').value || null,
        business_hours: document.getElementById('add-dealer-hours').value || null,
        years_established: document.getElementById('add-dealer-established').value || null,
        website: document.getElementById('add-dealer-website').value || null,
        verified: document.getElementById('add-dealer-verified').checked ? 1 : 0
    };

    try {
        const response = await admin.apiCall('add_dealer', formData, 'POST');
        if (response.success) {
            admin.showAlert('success', 'Dealer added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadDealers();
        } else {
            admin.showAlert('error', response.message || 'Failed to add dealer');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding dealer: ' + error.message);
    }
}

// ===== ADD CAR HIRE MODAL =====
function showAddCarHireModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-car-side"></i> Add New Car Hire Company</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCarHireForm" class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-business">Business Name *</label>
                            <input type="text" id="add-carhire-business" name="business_name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-owner">Owner Name *</label>
                            <input type="text" id="add-carhire-owner" name="owner_name" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-email">Email *</label>
                            <input type="email" id="add-carhire-email" name="email" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-phone">Phone *</label>
                            <input type="tel" id="add-carhire-phone" name="phone" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-whatsapp">WhatsApp</label>
                            <input type="tel" id="add-carhire-whatsapp" name="whatsapp" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-location">Location (District) *</label>
                            <select id="add-carhire-location" name="location_id" required class="form-control">
                                <option value="">Select Location</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-carhire-address">Physical Address</label>
                        <textarea id="add-carhire-address" name="address" rows="2" class="form-control" placeholder="Enter the physical address of the car hire company"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-website">Website</label>
                            <input type="url" id="add-carhire-website" name="website" placeholder="https://example.com" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-established">Year Established</label>
                            <input type="number" id="add-carhire-established" name="years_established" min="1950" max="2025" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-hours">Business Hours</label>
                            <input type="text" id="add-carhire-hours" name="business_hours" placeholder="e.g., 8AM-8PM or 24/7" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-carhire-description">Description</label>
                        <textarea id="add-carhire-description" name="description" rows="3" class="form-control"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-daily">Daily Rate From (Configured Currency)</label>
                            <input type="number" id="add-carhire-daily" name="daily_rate_from" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-daily-to">Daily Rate To (Configured Currency)</label>
                            <input type="number" id="add-carhire-daily-to" name="daily_rate_to" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-weekly">Weekly Rate From (Configured Currency)</label>
                            <input type="number" id="add-carhire-weekly" name="weekly_rate_from" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-carhire-monthly">Monthly Rate From (Configured Currency)</label>
                            <input type="number" id="add-carhire-monthly" name="monthly_rate_from" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="add-carhire-verified" name="verified">
                            Verified Company
                        </label>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-carhire-category">Hire Category</label>
                            <select id="add-carhire-category" name="hire_category" class="form-control">
                                <option value="standard">Standard Car Hire</option>
                                <option value="events">Events & Occasions</option>
                                <option value="vans_trucks">Vans & Trucks</option>
                                <option value="all">All Services</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="add-carhire-events-group">
                        <label>Event Types Offered</label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <label><input type="checkbox" class="add-event-type-cb" value="Wedding"> Wedding</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Corporate Event"> Corporate</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Funeral"> Funeral</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Birthday Party"> Birthday</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Prom Night"> Prom Night</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Airport VIP Transfer"> Airport VIP</label>
                            <label><input type="checkbox" class="add-event-type-cb" value="Graduation"> Graduation</label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddCarHire()"><i class="fas fa-save"></i> Add Car Hire</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    loadLocationsForModal('add-carhire-location');
}

async function submitAddCarHire() {
    const form = document.getElementById('addCarHireForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        business_name: document.getElementById('add-carhire-business').value,
        owner_name: document.getElementById('add-carhire-owner').value,
        email: document.getElementById('add-carhire-email').value,
        phone: document.getElementById('add-carhire-phone').value,
        whatsapp: document.getElementById('add-carhire-whatsapp').value || null,
        location_id: document.getElementById('add-carhire-location').value,
        address: document.getElementById('add-carhire-address').value || null,
        description: document.getElementById('add-carhire-description').value || null,
        website: document.getElementById('add-carhire-website').value || null,
        years_established: document.getElementById('add-carhire-established').value || null,
        business_hours: document.getElementById('add-carhire-hours').value || null,
        daily_rate_from: document.getElementById('add-carhire-daily').value || null,
        daily_rate_to: document.getElementById('add-carhire-daily-to').value || null,
        weekly_rate_from: document.getElementById('add-carhire-weekly').value || null,
        monthly_rate_from: document.getElementById('add-carhire-monthly').value || null,
        verified: document.getElementById('add-carhire-verified').checked ? 1 : 0,
        hire_category: document.getElementById('add-carhire-category').value || 'standard',
        event_types: Array.from(document.querySelectorAll('.add-event-type-cb:checked')).map(cb => cb.value)
    };

    try {
        const response = await admin.apiCall('add_car_hire', formData, 'POST');
        if (response.success) {
            admin.showAlert('success', 'Car hire company added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadCarHire();
        } else {
            admin.showAlert('error', response.message || 'Failed to add car hire');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding car hire: ' + error.message);
    }
}

// ===== ADD USER MODAL =====
function showAddUserModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addUserForm" class="modal-form">
                    <div class="form-group">
                        <label for="add-user-fullname">Full Name *</label>
                        <input type="text" id="add-user-fullname" name="full_name" required class="form-control">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-user-email">Email *</label>
                            <input type="email" id="add-user-email" name="email" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-user-phone">Phone</label>
                            <input type="tel" id="add-user-phone" name="phone" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-user-password">Password *</label>
                            <input type="password" id="add-user-password" name="password" required class="form-control" minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="add-user-whatsapp">WhatsApp</label>
                            <input type="tel" id="add-user-whatsapp" name="whatsapp" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-user-type">User Type *</label>
                            <select id="add-user-type" name="user_type" required class="form-control">
                                <option value="buyer">Buyer</option>
                                <option value="seller">Seller</option>
                                <option value="dealer">Dealer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-user-city">City</label>
                            <input type="text" id="add-user-city" name="city" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-user-address">Address</label>
                        <textarea id="add-user-address" name="address" rows="2" class="form-control"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="add-user-status">Status *</label>
                        <select id="add-user-status" name="status" required class="form-control">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddUser()"><i class="fas fa-save"></i> Add User</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

async function submitAddUser() {
    const form = document.getElementById('addUserForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        full_name: document.getElementById('add-user-fullname').value,
        email: document.getElementById('add-user-email').value,
        phone: document.getElementById('add-user-phone').value || null,
        password: document.getElementById('add-user-password').value,
        whatsapp: document.getElementById('add-user-whatsapp').value || null,
        user_type: document.getElementById('add-user-type').value,
        city: document.getElementById('add-user-city').value || null,
        address: document.getElementById('add-user-address').value || null,
        status: document.getElementById('add-user-status').value
    };

    try {
        const response = await admin.apiCall('add_user', formData, 'POST');
        if (response.success) {
            admin.showAlert('success', 'User added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadUsers();
        } else {
            admin.showAlert('error', response.message || 'Failed to add user');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding user: ' + error.message);
    }
}

// ===== ADD ADMIN MODAL =====
function showAddAdminModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-shield"></i> Add New Admin</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addAdminForm" class="modal-form">
                    <div class="form-group">
                        <label for="add-admin-fullname">Full Name *</label>
                        <input type="text" id="add-admin-fullname" name="full_name" required class="form-control">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-admin-username">Username *</label>
                            <input type="text" id="add-admin-username" name="username" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-admin-email">Email *</label>
                            <input type="email" id="add-admin-email" name="email" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-admin-password">Password *</label>
                            <input type="password" id="add-admin-password" name="password" required class="form-control" minlength="8">
                            <small class="form-text">Minimum 8 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="add-admin-role">Role *</label>
                            <select id="add-admin-role" name="role" required class="form-control">
                                <option value="moderator">Moderator</option>
                                <option value="onboarding_manager">Onboarding Manager</option>
                                <option value="admin">Admin</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-admin-status">Status *</label>
                        <select id="add-admin-status" name="status" required class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Roles:</strong><br>
                        <strong>Moderator:</strong> Can review and approve listings<br>
                        <strong>Onboarding Manager:</strong> Can access the business onboarding portal without full admin rights<br>
                        <strong>Admin:</strong> Full access to manage content<br>
                        <strong>Super Admin:</strong> All permissions including settings
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddAdmin()"><i class="fas fa-save"></i> Add Admin</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

async function submitAddAdmin() {
    const form = document.getElementById('addAdminForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        full_name: document.getElementById('add-admin-fullname').value,
        username: document.getElementById('add-admin-username').value,
        email: document.getElementById('add-admin-email').value,
        password: document.getElementById('add-admin-password').value,
        role: document.getElementById('add-admin-role').value,
        status: document.getElementById('add-admin-status').value
    };

    try {
        const response = await admin.apiCall('add_admin', formData, 'POST');
        if (response.success) {
            admin.showAlert('success', 'Admin added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadAdmins();
        } else {
            admin.showAlert('error', response.message || 'Failed to add admin');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding admin: ' + error.message);
    }
}

// ===== UTILITY FUNCTION =====
async function loadLocationsForModal(selectId) {
    try {
        const response = await admin.apiCall('get_locations');
        if (response.success && response.locations) {
            const select = document.getElementById(selectId);
            response.locations.forEach(location => {
                const option = document.createElement('option');
                option.value = location.id;
                option.textContent = location.name + (location.region ? ` (${location.region})` : '');
                select.appendChild(option);
            });
        }
    } catch (error) {
    }
}

// ===== ADD MAKE MODAL =====
function showAddMakeModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Add New Car Make</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addMakeForm" class="modal-form">
                    <div class="form-group">
                        <label for="add-make-name">Make Name *</label>
                        <input type="text" id="add-make-name" name="name" required class="form-control" placeholder="e.g., Toyota">
                    </div>

                    <div class="form-group">
                        <label for="add-make-country">Country of Origin</label>
                        <input type="text" id="add-make-country" name="country" class="form-control" placeholder="e.g., Japan">
                    </div>

                    <div class="form-group">
                        <label for="add-make-logo">Logo URL (optional)</label>
                        <input type="url" id="add-make-logo" name="logo_url" class="form-control" placeholder="https://example.com/logo.png">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddMake()"><i class="fas fa-save"></i> Add Make</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

async function submitAddMake() {
    const form = document.getElementById('addMakeForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        name: document.getElementById('add-make-name').value.trim(),
        country: document.getElementById('add-make-country').value.trim() || null,
        logo_url: document.getElementById('add-make-logo').value.trim() || null
    };

    try {
        const response = await admin.apiCall('add_make', 'POST', formData);
        if (response.success) {
            admin.showAlert('success', 'Make added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadMakesModels(); // Reload the makes/models table
        } else {
            admin.showAlert('error', response.message || 'Failed to add make');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding make: ' + error.message);
    }
}

// ===== ADD MODEL MODAL =====
function showAddModelModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-car"></i> Add New Car Model</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addModelForm" class="modal-form">
                    <div class="form-group">
                        <label for="add-model-make">Make *</label>
                        <select id="add-model-make" name="make_id" required class="form-control">
                            <option value="">Select Make</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="add-model-name">Model Name *</label>
                        <input type="text" id="add-model-name" name="name" required class="form-control" placeholder="e.g., Camry">
                    </div>

                    <div class="form-group">
                        <label for="add-model-body-type">Body Type</label>
                        <select id="add-model-body-type" name="body_type" class="form-control">
                            <option value="">Select Body Type</option>
                            <option value="sedan">Sedan</option>
                            <option value="suv">SUV</option>
                            <option value="hatchback">Hatchback</option>
                            <option value="coupe">Coupe</option>
                            <option value="convertible">Convertible</option>
                            <option value="wagon">Wagon</option>
                            <option value="minivan">Minivan</option>
                            <option value="crossover">Crossover</option>
                            <option value="pickup">Pickup</option>
                            <option value="commercial">Commercial</option>
                        </select>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-calendar-alt"></i> Year Range</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="add-model-year-start">Year Start</label>
                            <input type="number" id="add-model-year-start" name="year_start" class="form-control" placeholder="e.g., 2015" min="1900" max="2100">
                        </div>
                        <div class="form-group">
                            <label for="add-model-year-end">Year End (leave blank if still in production)</label>
                            <input type="number" id="add-model-year-end" name="year_end" class="form-control" placeholder="e.g., 2020" min="1900" max="2100">
                        </div>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-gas-pump"></i> Fuel & Performance</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="add-model-fuel-type">Fuel Type</label>
                            <select id="add-model-fuel-type" name="fuel_type" class="form-control">
                                <option value="">Select Fuel Type</option>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="electric">Electric</option>
                                <option value="plug-in_hybrid">Plug-in Hybrid</option>
                                <option value="lpg">LPG</option>
                                <option value="cng">CNG</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-model-fuel-tank-capacity">Fuel Tank Capacity (Liters)</label>
                            <input type="number" id="add-model-fuel-tank-capacity" name="fuel_tank_capacity_liters" class="form-control" placeholder="e.g., 50" step="0.1" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-consumption-urban">Fuel Consumption Urban (L/100km)</label>
                            <input type="number" id="add-model-consumption-urban" name="fuel_consumption_urban_l100km" class="form-control" placeholder="e.g., 8.5" step="0.1" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-consumption-highway">Fuel Consumption Highway (L/100km)</label>
                            <input type="number" id="add-model-consumption-highway" name="fuel_consumption_highway_l100km" class="form-control" placeholder="e.g., 6.5" step="0.1" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-consumption-combined">Fuel Consumption Combined (L/100km)</label>
                            <input type="number" id="add-model-consumption-combined" name="fuel_consumption_combined_l100km" class="form-control" placeholder="e.g., 7.5" step="0.1" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-co2-emissions">CO2 Emissions (g/km)</label>
                            <input type="number" id="add-model-co2-emissions" name="co2_emissions_gkm" class="form-control" placeholder="e.g., 150" min="0">
                        </div>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-cog"></i> Engine Specifications</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="add-model-engine-size">Engine Size (Liters)</label>
                            <input type="number" id="add-model-engine-size" name="engine_size_liters" class="form-control" placeholder="e.g., 2.0" step="0.1" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-engine-cylinders">Engine Cylinders</label>
                            <input type="number" id="add-model-engine-cylinders" name="engine_cylinders" class="form-control" placeholder="e.g., 4" min="1" max="16">
                        </div>
                        <div class="form-group">
                            <label for="add-model-horsepower">Horsepower (HP)</label>
                            <input type="number" id="add-model-horsepower" name="horsepower_hp" class="form-control" placeholder="e.g., 150" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-torque">Torque (Nm)</label>
                            <input type="number" id="add-model-torque" name="torque_nm" class="form-control" placeholder="e.g., 200" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-transmission">Transmission Type</label>
                            <select id="add-model-transmission" name="transmission_type" class="form-control">
                                <option value="">Select Transmission</option>
                                <option value="manual">Manual</option>
                                <option value="automatic">Automatic</option>
                                <option value="cvt">CVT</option>
                                <option value="semi-automatic">Semi-Automatic</option>
                                <option value="dct">DCT</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-model-drive-type">Drive Type</label>
                            <select id="add-model-drive-type" name="drive_type" class="form-control">
                                <option value="">Select Drive Type</option>
                                <option value="fwd">FWD (Front Wheel Drive)</option>
                                <option value="rwd">RWD (Rear Wheel Drive)</option>
                                <option value="awd">AWD (All Wheel Drive)</option>
                                <option value="4wd">4WD (Four Wheel Drive)</option>
                            </select>
                        </div>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-ruler-combined"></i> Dimensions & Weight</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="add-model-length">Length (mm)</label>
                            <input type="number" id="add-model-length" name="length_mm" class="form-control" placeholder="e.g., 4500" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-width">Width (mm)</label>
                            <input type="number" id="add-model-width" name="width_mm" class="form-control" placeholder="e.g., 1800" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-height">Height (mm)</label>
                            <input type="number" id="add-model-height" name="height_mm" class="form-control" placeholder="e.g., 1500" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-wheelbase">Wheelbase (mm)</label>
                            <input type="number" id="add-model-wheelbase" name="wheelbase_mm" class="form-control" placeholder="e.g., 2700" min="0">
                        </div>
                        <div class="form-group">
                            <label for="add-model-weight">Weight (kg)</label>
                            <input type="number" id="add-model-weight" name="weight_kg" class="form-control" placeholder="e.g., 1500" min="0">
                        </div>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-users"></i> Capacity</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="add-model-seating">Seating Capacity</label>
                            <input type="number" id="add-model-seating" name="seating_capacity" class="form-control" placeholder="e.g., 5" min="1" max="20">
                        </div>
                        <div class="form-group">
                            <label for="add-model-doors">Number of Doors</label>
                            <input type="number" id="add-model-doors" name="doors" class="form-control" placeholder="e.g., 4" min="2" max="6">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddModel()"><i class="fas fa-save"></i> Add Model</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    loadMakesForAddModel(); // Load makes dropdown
}

async function loadMakesForAddModel() {
    try {
        const response = await admin.apiCall('get_makes');
        if (response.success && response.makes) {
            const select = document.getElementById('add-model-make');
            response.makes.forEach(make => {
                const option = document.createElement('option');
                option.value = make.id;
                option.textContent = make.name + (make.country ? ` (${make.country})` : '');
                select.appendChild(option);
            });
        }
    } catch (error) {
    }
}

async function submitAddModel() {
    const form = document.getElementById('addModelForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const getValue = (id) => {
        const el = document.getElementById(id);
        return el && el.value ? el.value.trim() : null;
    };

    const getNumberValue = (id) => {
        const val = getValue(id);
        return val !== null && val !== '' ? parseFloat(val) : null;
    };

    const getIntValue = (id) => {
        const val = getValue(id);
        return val !== null && val !== '' ? parseInt(val) : null;
    };

    const formData = {
        make_id: parseInt(document.getElementById('add-model-make').value),
        name: document.getElementById('add-model-name').value.trim(),
        body_type: getValue('add-model-body-type'),
        year_start: getIntValue('add-model-year-start'),
        year_end: getIntValue('add-model-year-end'),
        fuel_tank_capacity_liters: getNumberValue('add-model-fuel-tank-capacity'),
        engine_size_liters: getNumberValue('add-model-engine-size'),
        engine_cylinders: getIntValue('add-model-engine-cylinders'),
        fuel_consumption_urban_l100km: getNumberValue('add-model-consumption-urban'),
        fuel_consumption_highway_l100km: getNumberValue('add-model-consumption-highway'),
        fuel_consumption_combined_l100km: getNumberValue('add-model-consumption-combined'),
        fuel_type: getValue('add-model-fuel-type'),
        transmission_type: getValue('add-model-transmission'),
        horsepower_hp: getIntValue('add-model-horsepower'),
        torque_nm: getIntValue('add-model-torque'),
        seating_capacity: getIntValue('add-model-seating'),
        doors: getIntValue('add-model-doors'),
        weight_kg: getIntValue('add-model-weight'),
        drive_type: getValue('add-model-drive-type'),
        co2_emissions_gkm: getIntValue('add-model-co2-emissions'),
        length_mm: getIntValue('add-model-length'),
        width_mm: getIntValue('add-model-width'),
        height_mm: getIntValue('add-model-height'),
        wheelbase_mm: getIntValue('add-model-wheelbase')
    };

    try {
        const response = await admin.apiCall('add_model', 'POST', formData);
        if (response.success) {
            admin.showAlert('success', 'Model added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadMakesModels(); // Reload the makes/models table
        } else {
            admin.showAlert('error', response.message || 'Failed to add model');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding model: ' + error.message);
    }
}

// ===== ADD LOCATION MODAL =====
function showAddLocationModal() {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-marker-alt"></i> Add New Location</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addLocationForm" class="modal-form">
                    <div class="form-group">
                        <label for="add-location-name">Location Name *</label>
                        <input type="text" id="add-location-name" name="name" required class="form-control" placeholder="e.g., Lilongwe">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-location-region">Region</label>
                            <select id="add-location-region" name="region" class="form-control">
                                <option value="">Select Region</option>
                                <option value="Central">Central</option>
                                <option value="Northern">Northern</option>
                                <option value="Southern">Southern</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-location-type">Type</label>
                            <select id="add-location-type" name="type" class="form-control">
                                <option value="city">City</option>
                                <option value="district">District</option>
                                <option value="town">Town</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-location-district">District (optional)</label>
                        <input type="text" id="add-location-district" name="district" class="form-control" placeholder="e.g., Lilongwe District">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddLocation()"><i class="fas fa-save"></i> Add Location</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

async function submitAddLocation() {
    const form = document.getElementById('addLocationForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        name: document.getElementById('add-location-name').value.trim(),
        region: document.getElementById('add-location-region').value || null,
        district: document.getElementById('add-location-district').value.trim() || null,
        type: document.getElementById('add-location-type').value || 'city'
    };

    try {
        const response = await admin.apiCall('add_location', 'POST', formData);
        if (response.success) {
            admin.showAlert('success', 'Location added successfully!');
            document.querySelector('.modal-overlay.active').remove();
            admin.loadLocations();
        } else {
            admin.showAlert('error', response.message || 'Failed to add location');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding location: ' + error.message);
    }
}

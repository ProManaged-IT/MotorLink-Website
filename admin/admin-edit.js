/**
 * MotorLink Admin - Complete Edit Operations Handler
 * Handles editing for all entities: Cars, Garages, Dealers, Car Hire, Makes, Models, Locations
 * @version 2.0.2
 * @updated 2025-12-17 - Removed object literal from console.log to fix syntax error
 */


// Use CONFIG from config.js (loaded globally)
const getAdminEditAPIUrl = () => {
    if (typeof CONFIG !== 'undefined' && CONFIG.MODE === 'UAT') {
        if (isLocal) {
            // Local development: use proxy to connect to production API (avoids CORS)
            // Use current origin (localhost or 127.0.0.1) to avoid CORS issues
            const currentOrigin = window.location.origin;
            return `${currentOrigin}/proxy.php?endpoint=admin-edit`;
        } else {
            // UAT on production server: use local admin-edit.php
            return 'admin-edit.php';
        }
    }
    // Production mode: use relative path (same server)
    return 'admin-edit.php';
};

const ADMIN_EDIT_API = getAdminEditAPIUrl();


class EditOperations {
    constructor() {
        this.currentEditingId = null;
        this.currentEntityType = null;
        this.init();
    }

    init() {
        this.bindEvents();
    }

    bindEvents() {
        // Modal close events
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-close') || 
                e.target.classList.contains('modal-overlay')) {
                this.closeModal();
            }
        });

        // Escape key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    }

    // ============================================================================
    // GARAGE OPERATIONS
    // ============================================================================

    async viewGarageDetails(garageId) {
        try {
            this.showLoading('Loading garage details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_garage&garage_id=${garageId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.showGarageDetailsModal(data.garage);
            } else {
                this.showError('Failed to load garage details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading garage: ' + error.message);
        }
    }

    showGarageDetailsModal(garage) {
        const modalHtml = `
            <div class="modal-overlay active" id="garageDetailsModal">
                <div class="modal-content" style="max-width: 800px;">
                    <div class="modal-header">
                        <h3>Garage Details - ${this.escapeHtml(garage.name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="car-details-grid">
                            <div class="detail-section">
                                <h4>Basic Information</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Garage Name:</span>
                                    <span class="detail-value">${this.escapeHtml(garage.name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Owner:</span>
                                    <span class="detail-value">${this.escapeHtml(garage.owner_name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${this.escapeHtml(garage.email)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value">${garage.phone}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4>Location & Services</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value">${this.escapeHtml(garage.location)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Specialization:</span>
                                    <span class="detail-value">${garage.specialization || 'N/A'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Operating Hours:</span>
                                    <span class="detail-value">${garage.operating_hours || 'N/A'}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section full-width">
                                <h4>Description</h4>
                                <div class="detail-value">${garage.description || 'No description provided'}</div>
                            </div>
                            
                            <div class="detail-section full-width">
                                <h4>Services Offered</h4>
                                <div class="detail-value">${garage.services || 'No services listed'}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Close</button>
                        <button type="button" class="btn btn-primary" onclick="editOps.editGarage(${garage.id})">
                            <i class="fas fa-edit"></i> Edit Garage
                        </button>
                        ${garage.status === 'active' ? `
                            <button type="button" class="btn btn-warning" onclick="editOps.suspendGarage(${garage.id})">
                                <i class="fas fa-ban"></i> Suspend
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    async editGarage(garageId) {
        try {
            this.showLoading('Loading garage details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_garage&garage_id=${garageId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = garageId;
                this.currentEntityType = 'garage';
                this.showEditGarageModal(data.garage);
            } else {
                this.showError('Failed to load garage details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading garage: ' + error.message);
        }
    }

    showEditGarageModal(garage) {
        const modalHtml = `
            <div class="modal-overlay active" id="editGarageModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Garage - ${this.escapeHtml(garage.name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editGarageForm" onsubmit="editOps.updateGarage(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="garageName">Garage Name *</label>
                                    <input type="text" id="garageName" name="name" value="${this.escapeHtml(garage.name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="garageOwner">Owner Name</label>
                                    <input type="text" id="garageOwner" name="owner_name" value="${this.escapeHtml(garage.owner_name)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="garageEmail">Email</label>
                                    <input type="email" id="garageEmail" name="email" value="${this.escapeHtml(garage.email)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="garagePhone">Phone *</label>
                                    <input type="tel" id="garagePhone" name="phone" value="${garage.phone}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="garageLocation">Location *</label>
                                    <select id="garageLocation" name="location_id" required class="form-control">
                                        <option value="">Select Location</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="garageSpecialization">Specialization</label>
                                    <input type="text" id="garageSpecialization" name="specialization" value="${garage.specialization || ''}" class="form-control" placeholder="e.g., Engine Repair, Brakes, Electrical">
                                </div>
                                <div class="form-group full-width">
                                    <label for="garageDescription">Description</label>
                                    <textarea id="garageDescription" name="description" rows="4" class="form-control">${garage.description || ''}</textarea>
                                </div>
                                <div class="form-group full-width">
                                    <label for="garageServices">Services Offered</label>
                                    <textarea id="garageServices" name="services" rows="3" class="form-control" placeholder="List services separated by commas">${garage.services || ''}</textarea>
                                </div>
                                <div class="form-group">
                                    <label for="garageHours">Operating Hours</label>
                                    <input type="text" id="garageHours" name="operating_hours" value="${garage.operating_hours || ''}" class="form-control" placeholder="e.g., Mon-Fri 8AM-6PM">
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Garage Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${garage.status === 'active' ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${garage.status === 'pending_approval' ? 'active' : ''}" data-status="pending_approval">Pending</button>
                                    <button type="button" class="status-btn ${garage.status === 'suspended' ? 'active' : ''}" data-status="suspended">Suspended</button>
                                    <input type="hidden" id="garageStatus" name="status" value="${garage.status}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Garage
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindStatusButtons();
        // Populate location dropdown
        this.populateLocationDropdown('garageLocation', garage.location_id);
    }

    async updateGarage(event) {
        event.preventDefault();
        
        try {
            this.showLoading('Updating garage...');
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_garage');
            formData.append('garage_id', this.currentEditingId);

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Garage updated successfully!');
                this.closeModal();
                // Refresh the garages table
                if (typeof admin !== 'undefined' && admin.loadGarages) {
                    admin.loadGarages();
                }
            } else {
                this.showError('Failed to update garage: ' + data.message);
            }
        } catch (error) {
            this.showError('Error updating garage: ' + error.message);
        }
    }

    async suspendGarage(garageId) {
        if (!confirm('Are you sure you want to suspend this garage? This will also suspend their user account and hide them from public view.')) {
            return;
        }

        try {
            this.showLoading('Suspending garage...');

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=suspend_garage&garage_id=${garageId}&suspend_user=true`
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess('Garage and associated user account suspended successfully!');
                this.closeModal();
                // Refresh the garages table
                if (typeof admin !== 'undefined' && admin.loadGarages) {
                    admin.loadGarages();
                }
            } else {
                this.showError('Failed to suspend garage: ' + data.message);
            }
        } catch (error) {
            this.showError('Error suspending garage: ' + error.message);
        }
    }

    // ============================================================================
    // DEALER OPERATIONS
    // ============================================================================

    async viewDealerDetails(dealerId) {
        try {
            this.showLoading('Loading dealer details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_dealer&dealer_id=${dealerId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.showDealerDetailsModal(data.dealer);
            } else {
                this.showError('Failed to load dealer details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading dealer: ' + error.message);
        }
    }

    showDealerDetailsModal(dealer) {
        const modalHtml = `
            <div class="modal-overlay active" id="dealerDetailsModal">
                <div class="modal-content" style="max-width: 800px;">
                    <div class="modal-header">
                        <h3>Dealer Details - ${this.escapeHtml(dealer.business_name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="car-details-grid">
                            <div class="detail-section">
                                <h4>Business Information</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Business Name:</span>
                                    <span class="detail-value">${this.escapeHtml(dealer.business_name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Owner:</span>
                                    <span class="detail-value">${this.escapeHtml(dealer.owner_name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${this.escapeHtml(dealer.email)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value">${dealer.phone}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4>Location & Details</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value">${this.escapeHtml(dealer.location)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Years Established:</span>
                                    <span class="detail-value">${dealer.years_established || 'N/A'}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section full-width">
                                <h4>Business Description</h4>
                                <div class="detail-value">${dealer.description || 'No description provided'}</div>
                            </div>
                            
                            <div class="detail-section full-width">
                                <h4>Specializations</h4>
                                <div class="detail-value">${dealer.specializations || 'No specializations listed'}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Close</button>
                        <button type="button" class="btn btn-primary" onclick="editOps.editDealer(${dealer.id})">
                            <i class="fas fa-edit"></i> Edit Dealer
                        </button>
                        ${dealer.status === 'active' ? `
                            <button type="button" class="btn btn-warning" onclick="editOps.suspendDealer(${dealer.id})">
                                <i class="fas fa-ban"></i> Suspend
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    async editDealer(dealerId) {
        try {
            this.showLoading('Loading dealer details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_dealer&dealer_id=${dealerId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = dealerId;
                this.currentEntityType = 'dealer';
                this.showEditDealerModal(data.dealer);
            } else {
                this.showError('Failed to load dealer details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading dealer: ' + error.message);
        }
    }

    showEditDealerModal(dealer) {
        const modalHtml = `
            <div class="modal-overlay active" id="editDealerModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Dealer - ${this.escapeHtml(dealer.business_name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editDealerForm" onsubmit="editOps.updateDealer(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="dealerBusinessName">Business Name *</label>
                                    <input type="text" id="dealerBusinessName" name="business_name" value="${this.escapeHtml(dealer.business_name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="dealerOwner">Owner Name</label>
                                    <input type="text" id="dealerOwner" name="owner_name" value="${this.escapeHtml(dealer.owner_name)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="dealerEmail">Email</label>
                                    <input type="email" id="dealerEmail" name="email" value="${this.escapeHtml(dealer.email)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="dealerPhone">Phone *</label>
                                    <input type="tel" id="dealerPhone" name="phone" value="${dealer.phone}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="dealerLocation">Location *</label>
                                    <select id="dealerLocation" name="location_id" required class="form-control">
                                        <option value="">Select Location</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="dealerYearsEstablished">Years Established</label>
                                    <input type="number" id="dealerYearsEstablished" name="years_established" value="${dealer.years_established || ''}" min="1950" max="2025" class="form-control">
                                </div>
                                <div class="form-group full-width">
                                    <label for="dealerDescription">Description</label>
                                    <textarea id="dealerDescription" name="description" rows="4" class="form-control">${dealer.description || ''}</textarea>
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Dealer Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${dealer.status === 'active' ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${dealer.status === 'pending_approval' ? 'active' : ''}" data-status="pending_approval">Pending</button>
                                    <button type="button" class="status-btn ${dealer.status === 'suspended' ? 'active' : ''}" data-status="suspended">Suspended</button>
                                    <input type="hidden" id="dealerStatus" name="status" value="${dealer.status}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Dealer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindStatusButtons();
        // Populate location dropdown
        this.populateLocationDropdown('dealerLocation', dealer.location_id);
    }

    async updateDealer(event) {
        event.preventDefault();
        
        try {
            this.showLoading('Updating dealer...');
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_dealer');
            formData.append('dealer_id', this.currentEditingId);

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Dealer updated successfully!');
                this.closeModal();
                // Refresh the dealers table
                if (typeof admin !== 'undefined' && admin.loadDealers) {
                    admin.loadDealers();
                }
            } else {
                this.showError('Failed to update dealer: ' + data.message);
            }
        } catch (error) {
            this.showError('Error updating dealer: ' + error.message);
        }
    }

    async suspendDealer(dealerId) {
        if (!confirm('Are you sure you want to suspend this dealer? This will also suspend their user account and hide them from public view.')) {
            return;
        }

        try {
            this.showLoading('Suspending dealer...');

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=suspend_dealer&dealer_id=${dealerId}&suspend_user=true`
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess('Dealer and associated user account suspended successfully!');
                this.closeModal();
                // Refresh the dealers table
                if (typeof admin !== 'undefined' && admin.loadDealers) {
                    admin.loadDealers();
                }
            } else {
                this.showError('Failed to suspend dealer: ' + data.message);
            }
        } catch (error) {
            this.showError('Error suspending dealer: ' + error.message);
        }
    }

    // ============================================================================
    // CAR HIRE OPERATIONS
    // ============================================================================

    async viewCarHireDetails(carHireId) {
        try {
            this.showLoading('Loading car hire details...');
            
            const [companyRes, fleetRes] = await Promise.all([
                fetch(ADMIN_EDIT_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_car_hire&car_hire_id=${carHireId}`
                }).then(r => r.json()),
                fetch(ADMIN_EDIT_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_car_hire_fleet_admin&car_hire_id=${carHireId}`
                }).then(r => r.json())
            ]);
            
            if (companyRes.success) {
                this.showCarHireDetailsModal(companyRes.car_hire, fleetRes.success ? fleetRes : null);
            } else {
                this.showError('Failed to load car hire details: ' + companyRes.message);
            }
        } catch (error) {
            this.showError('Error loading car hire: ' + error.message);
        }
    }

    showCarHireDetailsModal(carHire, fleetData) {
        const categoryLabels = {
            standard: { label: 'Standard', icon: '🚗' },
            events: { label: 'Events / Weddings', icon: '🎉' },
            vans_trucks: { label: 'Vans &amp; Trucks', icon: '🚚' },
            all: { label: 'All Services', icon: '🔄' }
        };
        const cat = categoryLabels[carHire.hire_category] || { label: carHire.hire_category || 'Standard', icon: '🚗' };

        let eventTypesHtml = '';
        if (carHire.event_types) {
            try {
                const evts = typeof carHire.event_types === 'string' ? JSON.parse(carHire.event_types) : carHire.event_types;
                if (Array.isArray(evts) && evts.length) {
                    eventTypesHtml = evts.map(e => `<span class="tag">${this.escapeHtml(e)}</span>`).join(' ');
                }
            } catch(e) { /* ignore */ }
        }

        // Fleet section
        let fleetSectionHtml = '';
        if (fleetData && fleetData.fleet) {
            const c = fleetData.counts || {};
            const statusColors = { available: '#16a34a', rented: '#2563eb', maintenance: '#d97706', not_available: '#6b7280' };
            const catIcons = { car: 'fa-car', van: 'fa-van-shuttle', truck: 'fa-truck' };
            const fleetRows = fleetData.fleet.map(v => {
                const catIcon = catIcons[v.vehicle_category] || 'fa-car';
                const statusColor = statusColors[v.status] || '#6b7280';
                const isTransporter = v.vehicle_category === 'van' || v.vehicle_category === 'truck';
                return `
                    <tr>
                        <td>${this.escapeHtml(v.vehicle_name || 'N/A')}</td>
                        <td><i class="fas ${catIcon}"></i> ${this.escapeHtml((v.vehicle_category || 'car').charAt(0).toUpperCase() + (v.vehicle_category || 'car').slice(1))}</td>
                        <td><span style="color:${statusColor};font-weight:600;">${(v.status || 'N/A').replace('_', ' ')}</span></td>
                        <td>${isTransporter && v.cargo_capacity ? `<i class="fas fa-box"></i> ${this.escapeHtml(v.cargo_capacity)}` : (v.seats ? `${v.seats} seats` : '—')}</td>
                        <td>${getConfiguredCurrencyLabel()} ${this.formatNumber(v.daily_rate || 0)}/day</td>
                    </tr>`;
            }).join('');

            fleetSectionHtml = `
                <div class="detail-section full-width" style="margin-top:16px;">
                    <h4><i class="fas fa-truck-ramp-box"></i> Fleet Overview</h4>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
                        <span class="tag" style="background:#e0f2fe;color:#0369a1;"><i class="fas fa-car"></i> ${c.car || 0} Cars</span>
                        <span class="tag" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-van-shuttle"></i> ${c.van || 0} Vans</span>
                        <span class="tag" style="background:#fff7ed;color:#c2410c;"><i class="fas fa-truck"></i> ${c.truck || 0} Trucks</span>
                        <span class="tag" style="background:#f3f4f6;color:#374151;"><i class="fas fa-layer-group"></i> ${fleetData.fleet.length} Total</span>
                    </div>
                    ${fleetData.fleet.length > 0 ? `
                    <div style="overflow-x:auto;">
                        <table class="data-table" style="font-size:0.85rem;">
                            <thead><tr><th>Vehicle</th><th>Category</th><th>Status</th><th>Capacity</th><th>Rate</th></tr></thead>
                            <tbody>${fleetRows}</tbody>
                        </table>
                    </div>` : '<p class="text-muted">No fleet vehicles registered yet.</p>'}
                </div>`;
        }

        const modalHtml = `
            <div class="modal-overlay active" id="carHireDetailsModal">
                <div class="modal-content" style="max-width: 860px;">
                    <div class="modal-header">
                        <h3>Car Hire Details — ${this.escapeHtml(carHire.business_name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="car-details-grid">
                            <div class="detail-section">
                                <h4>Business Information</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Business Name:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.business_name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Owner:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.owner_name || '—')}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.email || '—')}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value">${carHire.phone || '—'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Category:</span>
                                    <span class="detail-value">${cat.icon} ${cat.label}</span>
                                </div>
                                ${eventTypesHtml ? `<div class="detail-row"><span class="detail-label">Event Types:</span><span class="detail-value">${eventTypesHtml}</span></div>` : ''}
                            </div>
                            
                            <div class="detail-section">
                                <h4>Pricing &amp; Location</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.location_name || carHire.location || '—')}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Address:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.address || '—')}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Daily Rate From:</span>
                                    <span class="detail-value">${formatConfiguredCurrencyAmount(carHire.daily_rate_from || 0)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Weekly Rate From:</span>
                                    <span class="detail-value">${formatConfiguredCurrencyAmount(carHire.weekly_rate_from || 0)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Monthly Rate From:</span>
                                    <span class="detail-value">${formatConfiguredCurrencyAmount(carHire.monthly_rate_from || 0)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Business Hours:</span>
                                    <span class="detail-value">${this.escapeHtml(carHire.business_hours || '—')}</span>
                                </div>
                            </div>

                            <div class="detail-section full-width">
                                <h4>Business Description</h4>
                                <div class="detail-value">${this.escapeHtml(carHire.description || 'No description provided.')}</div>
                            </div>

                            ${fleetSectionHtml}
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Close</button>
                        <button type="button" class="btn btn-primary" onclick="editOps.editCarHire(${carHire.id})">
                            <i class="fas fa-edit"></i> Edit Car Hire
                        </button>
                        ${carHire.status === 'active' ? `
                            <button type="button" class="btn btn-warning" onclick="editOps.suspendCarHire(${carHire.id})">
                                <i class="fas fa-ban"></i> Suspend
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    async editCarHire(carHireId) {
        try {
            this.showLoading('Loading car hire details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_car_hire&car_hire_id=${carHireId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = carHireId;
                this.currentEntityType = 'car_hire';
                this.showEditCarHireModal(data.car_hire);
            } else {
                this.showError('Failed to load car hire details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading car hire: ' + error.message);
        }
    }

    showEditCarHireModal(carHire) {
        const modalHtml = `
            <div class="modal-overlay active" id="editCarHireModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Car Hire - ${this.escapeHtml(carHire.business_name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editCarHireForm" onsubmit="editOps.updateCarHire(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="carHireBusinessName">Business Name *</label>
                                    <input type="text" id="carHireBusinessName" name="business_name" value="${this.escapeHtml(carHire.business_name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireOwner">Owner Name</label>
                                    <input type="text" id="carHireOwner" name="owner_name" value="${this.escapeHtml(carHire.owner_name)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireEmail">Email</label>
                                    <input type="email" id="carHireEmail" name="email" value="${this.escapeHtml(carHire.email)}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHirePhone">Phone *</label>
                                    <input type="tel" id="carHirePhone" name="phone" value="${carHire.phone}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireWhatsapp">WhatsApp</label>
                                    <input type="tel" id="carHireWhatsapp" name="whatsapp" value="${carHire.whatsapp || ''}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireLocation">Location (District) *</label>
                                    <select id="carHireLocation" name="location_id" required class="form-control">
                                        <option value="">Select Location</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label for="carHireAddress">Physical Address</label>
                                    <textarea id="carHireAddress" name="address" rows="2" class="form-control" placeholder="Enter the physical address of the car hire company">${carHire.address || ''}</textarea>
                                </div>
                                <div class="form-group">
                                    <label for="carHireWebsite">Website</label>
                                    <input type="url" id="carHireWebsite" name="website" value="${carHire.website || ''}" class="form-control" placeholder="https://example.com">
                                </div>
                                <div class="form-group">
                                    <label for="carHireYearsEstablished">Year Established</label>
                                    <input type="number" id="carHireYearsEstablished" name="years_established" value="${carHire.years_established || ''}" min="1950" max="2025" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireBusinessHours">Business Hours</label>
                                    <input type="text" id="carHireBusinessHours" name="business_hours" value="${carHire.business_hours || ''}" class="form-control" placeholder="e.g., 8AM-8PM or 24/7">
                                </div>
                                <div class="form-group">
                                    <label for="carHireDailyRateFrom">Daily Rate From (${getConfiguredCurrencyLabel()})</label>
                                    <input type="number" id="carHireDailyRateFrom" name="daily_rate_from" value="${carHire.daily_rate_from || ''}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireWeeklyRateFrom">Weekly Rate From (${getConfiguredCurrencyLabel()})</label>
                                    <input type="number" id="carHireWeeklyRateFrom" name="weekly_rate_from" value="${carHire.weekly_rate_from || ''}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="carHireMonthlyRateFrom">Monthly Rate From (${getConfiguredCurrencyLabel()})</label>
                                    <input type="number" id="carHireMonthlyRateFrom" name="monthly_rate_from" value="${carHire.monthly_rate_from || ''}" class="form-control">
                                </div>
                                <div class="form-group full-width">
                                    <label for="carHireDescription">Description</label>
                                    <textarea id="carHireDescription" name="description" rows="4" class="form-control">${carHire.description || ''}</textarea>
                                </div>
                                <div class="form-group">
                                    <label for="carHireCategory">Hire Category</label>
                                    <select id="carHireCategory" name="hire_category" class="form-control">
                                        <option value="standard" ${carHire.hire_category === 'standard' ? 'selected' : ''}>Standard</option>
                                        <option value="events" ${carHire.hire_category === 'events' ? 'selected' : ''}>Events</option>
                                        <option value="vans_trucks" ${carHire.hire_category === 'vans_trucks' ? 'selected' : ''}>Vans & Trucks</option>
                                        <option value="all" ${carHire.hire_category === 'all' ? 'selected' : ''}>All Services</option>
                                    </select>
                                </div>
                                <div class="form-group full-width" id="editCarHireEventsGroup">
                                    <label>Event Types</label>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                        ${['Wedding','Corporate Event','Funeral','Birthday Party','Prom Night','Airport VIP Transfer','Graduation'].map(et => {
                                            let checked = '';
                                            try {
                                                const types = typeof carHire.event_types === 'string' ? JSON.parse(carHire.event_types) : (carHire.event_types || []);
                                                checked = types.includes(et) ? 'checked' : '';
                                            } catch(e) {}
                                            return '<label><input type="checkbox" class="edit-event-type-cb" value="' + et + '" ' + checked + '> ' + et + '</label>';
                                        }).join('')}
                                    </div>
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Car Hire Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${carHire.status === 'active' ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${carHire.status === 'pending_approval' ? 'active' : ''}" data-status="pending_approval">Pending</button>
                                    <button type="button" class="status-btn ${carHire.status === 'suspended' ? 'active' : ''}" data-status="suspended">Suspended</button>
                                    <input type="hidden" id="carHireStatus" name="status" value="${carHire.status}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Car Hire
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindStatusButtons();
        // Populate location dropdown
        this.populateLocationDropdown('carHireLocation', carHire.location_id);
    }

    async updateCarHire(event) {
        event.preventDefault();
        
        try {
            this.showLoading('Updating car hire...');
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_car_hire');
            formData.append('car_hire_id', this.currentEditingId);

            // Collect event type checkboxes
            const eventTypes = Array.from(document.querySelectorAll('.edit-event-type-cb:checked')).map(cb => cb.value);
            formData.append('event_types', JSON.stringify(eventTypes));

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Car hire updated successfully!');
                this.closeModal();
                // Refresh the car hire table
                if (typeof admin !== 'undefined' && admin.loadCarHire) {
                    admin.loadCarHire();
                }
            } else {
                this.showError('Failed to update car hire: ' + data.message);
            }
        } catch (error) {
            this.showError('Error updating car hire: ' + error.message);
        }
    }

    async suspendCarHire(carHireId) {
        if (!confirm('Are you sure you want to suspend this car hire company? This will also suspend their user account and hide them from public view.')) {
            return;
        }

        try {
            this.showLoading('Suspending car hire...');

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=suspend_car_hire&car_hire_id=${carHireId}&suspend_user=true`
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess('Car hire and associated user account suspended successfully!');
                this.closeModal();
                // Refresh the car hire table
                if (typeof admin !== 'undefined' && admin.loadCarHire) {
                    admin.loadCarHire();
                }
            } else {
                this.showError('Failed to suspend car hire: ' + data.message);
            }
        } catch (error) {
            this.showError('Error suspending car hire: ' + error.message);
        }
    }

    // ============================================================================
    // MAKE & MODEL OPERATIONS
    // ============================================================================

    async editMake(makeId) {
        try {
            this.showLoading('Loading make details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_make&make_id=${makeId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = makeId;
                this.currentEntityType = 'make';
                this.showEditMakeModal(data.make);
            } else {
                this.showError('Failed to load make details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading make: ' + error.message);
        }
    }

    showEditMakeModal(make) {
        const modalHtml = `
            <div class="modal-overlay active" id="editMakeModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Car Make - ${this.escapeHtml(make.name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editMakeForm" onsubmit="editOps.updateMake(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="makeName">Make Name *</label>
                                    <input type="text" id="makeName" name="name" value="${this.escapeHtml(make.name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="makeCountry">Country of Origin</label>
                                    <input type="text" id="makeCountry" name="country" value="${make.country || ''}" class="form-control" placeholder="e.g., Japan, Germany, USA">
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Make Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${make.is_active ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${!make.is_active ? 'active' : ''}" data-status="inactive">Inactive</button>
                                    <input type="hidden" id="makeStatus" name="is_active" value="${make.is_active ? '1' : '0'}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Make
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindStatusButtons();
    }

    async updateMake(event) {
        event.preventDefault();

        try {
            this.showLoading('Updating make...');

            const formData = new FormData(event.target);
            formData.append('action', 'update_make');
            formData.append('make_id', this.currentEditingId);

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });


            const responseText = await response.text();

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                this.showError('Invalid response from server: ' + responseText.substring(0, 100));
                return;
            }


            if (data.success) {
                this.showSuccess('Make updated successfully!');
                this.closeModal();
                // Refresh the makes table
                if (typeof admin !== 'undefined' && admin.loadMakesModels) {
                    admin.loadMakesModels();
                }
            } else {
                this.showError('Failed to update make: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            this.showError('Error updating make: ' + error.message);
        }
    }

    async deleteMake(makeId) {
        if (!confirm('Are you sure you want to delete this car make? This action cannot be undone and will affect all associated models.')) {
            return;
        }

        try {
            this.showLoading('Deleting make...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_make&make_id=${makeId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Make deleted successfully!');
                // Refresh the makes table
                if (typeof admin !== 'undefined' && admin.loadMakesModels) {
                    admin.loadMakesModels();
                }
            } else {
                this.showError('Failed to delete make: ' + data.message);
            }
        } catch (error) {
            this.showError('Error deleting make: ' + error.message);
        }
    }

    async editModel(modelId) {
        try {
            this.showLoading('Loading model details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_model&model_id=${modelId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = modelId;
                this.currentEntityType = 'model';
                this.showEditModelModal(data.model);
            } else {
                this.showError('Failed to load model details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading model: ' + error.message);
        }
    }

    showEditModelModal(model) {
        const bodyTypes = ['sedan', 'suv', 'hatchback', 'coupe', 'convertible', 'wagon', 'minivan', 'crossover', 'pickup', 'commercial'];
        
        const modalHtml = `
            <div class="modal-overlay active" id="editModelModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Car Model - ${this.escapeHtml(model.name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editModelForm" onsubmit="editOps.updateModel(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelName">Model Name *</label>
                                    <input type="text" id="modelName" name="name" value="${this.escapeHtml(model.name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="modelMake">Make *</label>
                                    <select id="modelMake" name="make_id" required class="form-control">
                                        <option value="">Select Make</option>
                                        <!-- Makes will be populated by JavaScript -->
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="modelBodyType">Body Type</label>
                                    <select id="modelBodyType" name="body_type" class="form-control">
                                        <option value="">Select Body Type</option>
                                        ${bodyTypes.map(type => `
                                            <option value="${type}" ${model.body_type === type ? 'selected' : ''}>
                                                ${type.charAt(0).toUpperCase() + type.slice(1)}
                                            </option>
                                        `).join('')}
                                    </select>
                                </div>
                            </div>

                            <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-calendar-alt"></i> Year Range</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelYearStart">Year Start</label>
                                    <input type="number" id="modelYearStart" name="year_start" value="${model.year_start || ''}" class="form-control" placeholder="e.g., 2015" min="1900" max="2100">
                                </div>
                                <div class="form-group">
                                    <label for="modelYearEnd">Year End (leave blank if still in production)</label>
                                    <input type="number" id="modelYearEnd" name="year_end" value="${model.year_end || ''}" class="form-control" placeholder="e.g., 2020" min="1900" max="2100">
                                </div>
                            </div>

                            <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-gas-pump"></i> Fuel & Performance</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelFuelType">Fuel Type</label>
                                    <select id="modelFuelType" name="fuel_type" class="form-control">
                                        <option value="">Select Fuel Type</option>
                                        <option value="petrol" ${model.fuel_type === 'petrol' ? 'selected' : ''}>Petrol</option>
                                        <option value="diesel" ${model.fuel_type === 'diesel' ? 'selected' : ''}>Diesel</option>
                                        <option value="hybrid" ${model.fuel_type === 'hybrid' ? 'selected' : ''}>Hybrid</option>
                                        <option value="electric" ${model.fuel_type === 'electric' ? 'selected' : ''}>Electric</option>
                                        <option value="plug-in_hybrid" ${model.fuel_type === 'plug-in_hybrid' ? 'selected' : ''}>Plug-in Hybrid</option>
                                        <option value="lpg" ${model.fuel_type === 'lpg' ? 'selected' : ''}>LPG</option>
                                        <option value="cng" ${model.fuel_type === 'cng' ? 'selected' : ''}>CNG</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="modelFuelTankCapacity">Fuel Tank Capacity (Liters)</label>
                                    <input type="number" id="modelFuelTankCapacity" name="fuel_tank_capacity_liters" value="${model.fuel_tank_capacity_liters || ''}" class="form-control" placeholder="e.g., 50" step="0.1" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelConsumptionUrban">Fuel Consumption Urban (L/100km)</label>
                                    <input type="number" id="modelConsumptionUrban" name="fuel_consumption_urban_l100km" value="${model.fuel_consumption_urban_l100km || ''}" class="form-control" placeholder="e.g., 8.5" step="0.1" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelConsumptionHighway">Fuel Consumption Highway (L/100km)</label>
                                    <input type="number" id="modelConsumptionHighway" name="fuel_consumption_highway_l100km" value="${model.fuel_consumption_highway_l100km || ''}" class="form-control" placeholder="e.g., 6.5" step="0.1" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelConsumptionCombined">Fuel Consumption Combined (L/100km)</label>
                                    <input type="number" id="modelConsumptionCombined" name="fuel_consumption_combined_l100km" value="${model.fuel_consumption_combined_l100km || ''}" class="form-control" placeholder="e.g., 7.5" step="0.1" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelCO2Emissions">CO2 Emissions (g/km)</label>
                                    <input type="number" id="modelCO2Emissions" name="co2_emissions_gkm" value="${model.co2_emissions_gkm || ''}" class="form-control" placeholder="e.g., 150" min="0">
                                </div>
                            </div>

                            <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-cog"></i> Engine Specifications</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelEngineSize">Engine Size (Liters)</label>
                                    <input type="number" id="modelEngineSize" name="engine_size_liters" value="${model.engine_size_liters || ''}" class="form-control" placeholder="e.g., 2.0" step="0.1" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelEngineCylinders">Engine Cylinders</label>
                                    <input type="number" id="modelEngineCylinders" name="engine_cylinders" value="${model.engine_cylinders || ''}" class="form-control" placeholder="e.g., 4" min="1" max="16">
                                </div>
                                <div class="form-group">
                                    <label for="modelHorsepower">Horsepower (HP)</label>
                                    <input type="number" id="modelHorsepower" name="horsepower_hp" value="${model.horsepower_hp || ''}" class="form-control" placeholder="e.g., 150" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelTorque">Torque (Nm)</label>
                                    <input type="number" id="modelTorque" name="torque_nm" value="${model.torque_nm || ''}" class="form-control" placeholder="e.g., 200" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelTransmission">Transmission Type</label>
                                    <select id="modelTransmission" name="transmission_type" class="form-control">
                                        <option value="">Select Transmission</option>
                                        <option value="manual" ${model.transmission_type === 'manual' ? 'selected' : ''}>Manual</option>
                                        <option value="automatic" ${model.transmission_type === 'automatic' ? 'selected' : ''}>Automatic</option>
                                        <option value="cvt" ${model.transmission_type === 'cvt' ? 'selected' : ''}>CVT</option>
                                        <option value="semi-automatic" ${model.transmission_type === 'semi-automatic' ? 'selected' : ''}>Semi-Automatic</option>
                                        <option value="dct" ${model.transmission_type === 'dct' ? 'selected' : ''}>DCT</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="modelDriveType">Drive Type</label>
                                    <select id="modelDriveType" name="drive_type" class="form-control">
                                        <option value="">Select Drive Type</option>
                                        <option value="fwd" ${model.drive_type === 'fwd' ? 'selected' : ''}>FWD (Front Wheel Drive)</option>
                                        <option value="rwd" ${model.drive_type === 'rwd' ? 'selected' : ''}>RWD (Rear Wheel Drive)</option>
                                        <option value="awd" ${model.drive_type === 'awd' ? 'selected' : ''}>AWD (All Wheel Drive)</option>
                                        <option value="4wd" ${model.drive_type === '4wd' ? 'selected' : ''}>4WD (Four Wheel Drive)</option>
                                    </select>
                                </div>
                            </div>

                            <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-ruler-combined"></i> Dimensions & Weight</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelLength">Length (mm)</label>
                                    <input type="number" id="modelLength" name="length_mm" value="${model.length_mm || ''}" class="form-control" placeholder="e.g., 4500" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelWidth">Width (mm)</label>
                                    <input type="number" id="modelWidth" name="width_mm" value="${model.width_mm || ''}" class="form-control" placeholder="e.g., 1800" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelHeight">Height (mm)</label>
                                    <input type="number" id="modelHeight" name="height_mm" value="${model.height_mm || ''}" class="form-control" placeholder="e.g., 1500" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelWheelbase">Wheelbase (mm)</label>
                                    <input type="number" id="modelWheelbase" name="wheelbase_mm" value="${model.wheelbase_mm || ''}" class="form-control" placeholder="e.g., 2700" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="modelWeight">Weight (kg)</label>
                                    <input type="number" id="modelWeight" name="weight_kg" value="${model.weight_kg || ''}" class="form-control" placeholder="e.g., 1500" min="0">
                                </div>
                            </div>

                            <hr style="margin: 20px 0; border: none; border-top: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 15px 0; color: #333;"><i class="fas fa-users"></i> Capacity</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="modelSeating">Seating Capacity</label>
                                    <input type="number" id="modelSeating" name="seating_capacity" value="${model.seating_capacity || ''}" class="form-control" placeholder="e.g., 5" min="1" max="20">
                                </div>
                                <div class="form-group">
                                    <label for="modelDoors">Number of Doors</label>
                                    <input type="number" id="modelDoors" name="doors" value="${model.doors || ''}" class="form-control" placeholder="e.g., 4" min="2" max="6">
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Model Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${model.is_active ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${!model.is_active ? 'active' : ''}" data-status="inactive">Inactive</button>
                                    <input type="hidden" id="modelStatus" name="is_active" value="${model.is_active ? '1' : '0'}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Model
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.populateMakesForModel(model.make_id);
        this.bindStatusButtons();
    }

    async populateMakesForModel(selectedMakeId) {
        try {
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_makes'
            });

            const data = await response.json();
            
            if (data.success) {
                const makeSelect = document.getElementById('modelMake');
                makeSelect.innerHTML = '<option value="">Select Make</option>';
                
                data.makes.forEach(make => {
                    const option = document.createElement('option');
                    option.value = make.id;
                    option.textContent = make.name;
                    option.selected = make.id == selectedMakeId;
                    makeSelect.appendChild(option);
                });
            }
        } catch (error) {
        }
    }

    async updateModel(event) {
        event.preventDefault();

        try {
            this.showLoading('Updating model...');

            const formData = new FormData(event.target);
            formData.append('action', 'update_model');
            formData.append('model_id', this.currentEditingId);

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });


            const responseText = await response.text();

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                this.showError('Invalid response from server: ' + responseText.substring(0, 100));
                return;
            }


            if (data.success) {
                this.showSuccess('Model updated successfully!');
                this.closeModal();
                // Refresh the models table
                if (typeof admin !== 'undefined' && admin.loadMakesModels) {
                    admin.loadMakesModels();
                }
            } else {
                this.showError('Failed to update model: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            this.showError('Error updating model: ' + error.message);
        }
    }

    async deleteModel(modelId) {
        if (!confirm('Are you sure you want to delete this car model? This action cannot be undone.')) {
            return;
        }

        try {
            this.showLoading('Deleting model...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_model&model_id=${modelId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Model deleted successfully!');
                // Refresh the models table
                if (typeof admin !== 'undefined' && admin.loadMakesModels) {
                    admin.loadMakesModels();
                }
            } else {
                this.showError('Failed to delete model: ' + data.message);
            }
        } catch (error) {
            this.showError('Error deleting model: ' + error.message);
        }
    }

    // ============================================================================
    // LOCATION OPERATIONS
    // ============================================================================

    async editLocation(locationId) {
        try {
            this.showLoading('Loading location details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_location&location_id=${locationId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = locationId;
                this.currentEntityType = 'location';
                this.showEditLocationModal(data.location);
            } else {
                this.showError('Failed to load location details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading location: ' + error.message);
        }
    }

    showEditLocationModal(location) {
        const modalHtml = `
            <div class="modal-overlay active" id="editLocationModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Location - ${this.escapeHtml(location.name)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form class="modal-form" id="editLocationForm" onsubmit="editOps.updateLocation(event)">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="locationName">Location Name *</label>
                                    <input type="text" id="locationName" name="name" value="${this.escapeHtml(location.name)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="locationRegion">Region *</label>
                                    <input type="text" id="locationRegion" name="region" value="${this.escapeHtml(location.region)}" required class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="locationDistrict">District</label>
                                    <input type="text" id="locationDistrict" name="district" value="${location.district || ''}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="locationCountry">Country</label>
                                    <input type="text" id="locationCountry" name="country" value="${this.escapeHtml(location.country || ((window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : ''))}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="locationLatitude">Latitude</label>
                                    <input type="text" id="locationLatitude" name="latitude" value="${location.latitude || ''}" class="form-control" placeholder="e.g., -13.9626">
                                </div>
                                <div class="form-group">
                                    <label for="locationLongitude">Longitude</label>
                                    <input type="text" id="locationLongitude" name="longitude" value="${location.longitude || ''}" class="form-control" placeholder="e.g., 33.7741">
                                </div>
                            </div>

                            <!-- Status Controls -->
                            <div class="form-group full-width">
                                <label>Location Status</label>
                                <div class="status-controls">
                                    <button type="button" class="status-btn ${location.is_active ? 'active' : ''}" data-status="active">Active</button>
                                    <button type="button" class="status-btn ${!location.is_active ? 'active' : ''}" data-status="inactive">Inactive</button>
                                    <input type="hidden" id="locationStatus" name="is_active" value="${location.is_active ? '1' : '0'}">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="btn btn-outline" onclick="editOps.closeModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Location
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindStatusButtons();
    }

    async updateLocation(event) {
        event.preventDefault();
        
        try {
            this.showLoading('Updating location...');
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_location');
            formData.append('location_id', this.currentEditingId);

            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Location updated successfully!');
                this.closeModal();
                // Refresh the locations table
                if (typeof admin !== 'undefined' && admin.loadLocations) {
                    admin.loadLocations();
                }
            } else {
                this.showError('Failed to update location: ' + data.message);
            }
        } catch (error) {
            this.showError('Error updating location: ' + error.message);
        }
    }

    async deleteLocation(locationId) {
        if (!confirm('Are you sure you want to delete this location? This action cannot be undone.')) {
            return;
        }

        try {
            this.showLoading('Deleting location...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_location&location_id=${locationId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Location deleted successfully!');
                // Refresh the locations table
                if (typeof admin !== 'undefined' && admin.loadLocations) {
                    admin.loadLocations();
                }
            } else {
                this.showError('Failed to delete location: ' + data.message);
            }
        } catch (error) {
            this.showError('Error deleting location: ' + error.message);
        }
    }

    // ============================================================================
    // CAR OPERATIONS (Keep your existing car functions)
    // ============================================================================

    async editCar(carId) {
        try {
            this.showLoading('Loading car details...');
            
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_car&car_id=${carId}`
            });

            const data = await response.json();
            
            if (data.success) {
                this.currentEditingId = carId;
                this.currentEntityType = 'car';
                this.showEditCarModal(data.car);
            } else {
                this.showError('Failed to load car details: ' + data.message);
            }
        } catch (error) {
            this.showError('Error loading car: ' + error.message);
        }
    }

    // ... Keep all your existing car functions (showEditCarModal, updateCar, etc.)

    // ============================================================================
    // UTILITY FUNCTIONS
    // ============================================================================

    bindStatusButtons() {
        const statusButtons = document.querySelectorAll('.status-btn');
        
        statusButtons.forEach(button => {
            button.addEventListener('click', () => {
                const parent = button.closest('.form-group');
                const statusInput = parent.querySelector('input[type="hidden"]');
                
                // Remove active class from all buttons in this group
                parent.querySelectorAll('.status-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                // Add active class to clicked button
                button.classList.add('active');
                
                // Update the hidden input value
                if (statusInput.id.includes('Status')) {
                    if (statusInput.id.includes('makeStatus') || statusInput.id.includes('modelStatus') || statusInput.id.includes('locationStatus')) {
                        statusInput.value = button.dataset.status === 'active' ? '1' : '0';
                    } else {
                        statusInput.value = button.dataset.status;
                    }
                }
            });
        });
    }

    closeModal() {
        const modals = document.querySelectorAll('.modal-overlay');
        modals.forEach(modal => modal.remove());
        
        this.currentEditingId = null;
        this.currentEntityType = null;
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatNumber(number) {
        if (!number) return '0';
        return new Intl.NumberFormat().format(number);
    }

    showLoading(message = 'Loading...') {
        // You can implement a loading spinner here
    }

    showSuccess(message) {
        // You can implement a toast notification here
        if (typeof admin !== 'undefined' && admin.showAlert) {
            admin.showAlert('success', message);
        } else {
            alert('Success: ' + message);
        }
    }

    showError(message) {
        // You can implement a toast notification here
        if (typeof admin !== 'undefined' && admin.showAlert) {
            admin.showAlert('error', message);
        } else {
            alert('Error: ' + message);
        }
    }

    async populateLocationDropdown(selectId, selectedLocationId = null) {
        try {
            const response = await fetch(ADMIN_EDIT_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_locations'
            });
            
            const data = await response.json();
            
            if (data.success && data.locations) {
                const select = document.getElementById(selectId);
                if (!select) return;
                
                // Clear existing options except the first one
                select.innerHTML = '<option value="">Select Location</option>';
                
                // Add locations
                data.locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.id;
                    // Display location name with district and region information
                    let displayText = location.name;
                    if (location.district) {
                        displayText += ` (${location.district}`;
                        if (location.region) {
                            displayText += `, ${location.region}`;
                        }
                        displayText += ')';
                    } else if (location.region) {
                        displayText += ` (${location.region})`;
                    }
                    option.textContent = displayText;

                    // Select the current location if it matches
                    if (selectedLocationId && location.id == selectedLocationId) {
                        option.selected = true;
                    }

                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading locations:', error);
        }
    }
}

// Initialize the edit operations
const editOps = new EditOperations();

// Make it globally available
window.editOps = editOps;

// Global functions for HTML onclick attributes
function editCar(carId) {
    editOps.editCar(carId);
}

function editGarage(garageId) {
    editOps.editGarage(garageId);
}

function editDealer(dealerId) {
    editOps.editDealer(dealerId);
}

function editCarHire(carHireId) {
    editOps.editCarHire(carHireId);
}

function editMake(makeId) {
    editOps.editMake(makeId);
}

function editModel(modelId) {
    editOps.editModel(modelId);
}

function editLocation(locationId) {
    editOps.editLocation(locationId);
}

function viewGarageDetails(garageId) {
    editOps.viewGarageDetails(garageId);
}

function viewDealerDetails(dealerId) {
    editOps.viewDealerDetails(dealerId);
}

function viewCarHireDetails(carHireId) {
    editOps.viewCarHireDetails(carHireId);
}

function suspendGarage(garageId) {
    editOps.suspendGarage(garageId);
}

function suspendDealer(dealerId) {
    editOps.suspendDealer(dealerId);
}

function suspendCarHire(carHireId) {
    editOps.suspendCarHire(carHireId);
}

function deleteMake(makeId) {
    editOps.deleteMake(makeId);
}

function deleteModel(modelId) {
    editOps.deleteModel(modelId);
}

function deleteLocation(locationId) {
    editOps.deleteLocation(locationId);
}

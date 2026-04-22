// ============================================================================
// Car Hire Company Detail Page
// ============================================================================
// Displays detailed information about a specific car hire company
// Includes fleet listings, location map, pricing, and contact information
// Uses global CONFIG from config.js for API endpoints
// ============================================================================

let companyData = null;
let fleet = [];
let mapInitialized = false;
let userLocation = null;
let companyDistanceKm = null; // distance from user to this company

async function fetchJsonWithRetry(url, options = {}, attempts = 2, timeoutMs = 10000) {
    let lastError = null;

    for (let attempt = 1; attempt <= attempts; attempt++) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            return await response.json();
        } catch (error) {
            clearTimeout(timeoutId);
            lastError = error;

            if (attempt < attempts) {
                await new Promise(resolve => setTimeout(resolve, 250 * attempt));
            }
        }
    }

    throw lastError || new Error('Request failed');
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const companyId = urlParams.get('id');

    if (!companyId) {
        window.location.href = 'car-hire.html';
        return;
    }

    loadCompanyData(companyId);
    loadFleet(companyId);
    getUserLocation(companyId);
});

// Haversine distance in km
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Get user location and update distance displays
function getUserLocation(companyId) {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
        function(position) {
            userLocation = { lat: position.coords.latitude, lng: position.coords.longitude };
            // If company data already loaded, update distance in header
            if (companyData && companyData.latitude && companyData.longitude) {
                companyDistanceKm = calculateDistance(
                    userLocation.lat, userLocation.lng,
                    parseFloat(companyData.latitude), parseFloat(companyData.longitude)
                );
                updateDistanceDisplay();
            }
            // Update any already-rendered fleet cards
            updateFleetDistanceBadges();
        },
        function() { /* no-op if denied */ },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
    );
}

// Inject/update the distance pill in the company header meta row
function updateDistanceDisplay() {
    if (!companyDistanceKm) return;
    const existing = document.getElementById('companyDistancePill');
    const distText = companyDistanceKm < 1
        ? `${Math.round(companyDistanceKm * 1000)} m from you`
        : `${companyDistanceKm.toFixed(1)} km from you`;
    if (existing) {
        existing.innerHTML = `<i class="fas fa-location-arrow"></i> ${distText}`;
    } else {
        const metaEl = document.querySelector('.company-meta');
        if (metaEl) {
            const span = document.createElement('span');
            span.id = 'companyDistancePill';
            span.className = 'meta-item company-distance-pill';
            span.innerHTML = `<i class="fas fa-location-arrow"></i> ${distText}`;
            metaEl.prepend(span);
        }
    }
}

// Add distance badges to van/truck fleet cards that are already rendered
function updateFleetDistanceBadges() {
    if (!companyDistanceKm) return;
    const distText = companyDistanceKm < 1
        ? `${Math.round(companyDistanceKm * 1000)} m away`
        : `${companyDistanceKm.toFixed(1)} km away`;
    document.querySelectorAll('.fleet-card .fleet-distance-badge').forEach(el => {
        el.innerHTML = `<i class="fas fa-location-arrow"></i> ${distText}`;
    });
}

function showCompanyLoadError(message) {
    const container = document.getElementById('companyHeader');
    const safeMessage = escapeHtml(message || 'Failed to load company information.');
    if (!container) return;

    container.innerHTML = `
        <div class="container">
            <div class="loading" style="padding: 30px 0; text-align: center;">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${safeMessage}</p>
                <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:12px;">
                    <button onclick="retryCompanyLoad()" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                    <a href="car-hire.html" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left"></i> Back to Listings
                    </a>
                </div>
            </div>
        </div>
    `;
}

function retryCompanyLoad() {
    const urlParams = new URLSearchParams(window.location.search);
    const companyId = urlParams.get('id');
    if (!companyId) {
        window.location.href = 'car-hire.html';
        return;
    }

    loadCompanyData(companyId);
    loadFleet(companyId);
}

async function loadCompanyData(id) {
    try {
        const apiUrl = `${CONFIG.API_URL}?action=car_hire_company&id=${id}`;
        const data = await fetchJsonWithRetry(apiUrl);

        if (data.success) {
            companyData = data.company;
            renderCompanyHeader(companyData);
            renderContactCard(companyData);
            const siteName = (window.CONFIG && CONFIG.SITE_NAME) ? CONFIG.SITE_NAME : 'MotorLink';
            document.title = `${companyData.business_name} - Car Hire - ${siteName}`;
            // Re-trigger mobile description move after async render completes
            if (typeof moveDescriptionsOnMobile === 'function') {
                setTimeout(moveDescriptionsOnMobile, 50);
            }
            // Compute distance if user location already available
            if (userLocation && companyData.latitude && companyData.longitude) {
                companyDistanceKm = calculateDistance(
                    userLocation.lat, userLocation.lng,
                    parseFloat(companyData.latitude), parseFloat(companyData.longitude)
                );
                updateDistanceDisplay();
            }
            // Load reviews section
            const rvContainer = document.getElementById('rv-section-car_hire-container');
            const rvInner = document.getElementById('rv-section-car_hire-inner');
            if (rvContainer && rvInner && typeof rvRenderSection === 'function') {
                rvContainer.style.display = '';
                rvInner.id = `rv-section-car_hire-${companyData.id}`;
                rvRenderSection(rvInner, 'car_hire', companyData.id, companyData.business_name);
            }
        } else {
            showCompanyLoadError(data.message || 'Company not found.');
        }
    } catch (error) {
        showCompanyLoadError(error.message || 'Error loading company information.');
    }
}

async function loadFleet(companyId) {
    try {
        const apiUrl = `${CONFIG.API_URL}?action=car_hire_fleet&company_id=${companyId}`;
        const data = await fetchJsonWithRetry(apiUrl);
        
        if (data.success) {
            fleet = data.fleet;
            renderFleet(fleet);
        } else {
            document.getElementById('fleetGrid').innerHTML = `
                <div class="loading">
                    <p>No vehicles available</p>
                </div>
            `;
        }
    } catch (error) {
        const grid = document.getElementById('fleetGrid');
        if (grid) {
            grid.innerHTML = `
                <div class="loading" style="text-align:center;">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Failed to load fleet. Please try again.</p>
                    <button onclick="retryCompanyLoad()" class="btn btn-primary" style="margin-top:12px;">
                        <i class="fas fa-redo"></i> Retry Fleet
                    </button>
                </div>
            `;
        }
    }
}

function renderCompanyHeader(company) {
    const header = document.getElementById('companyHeader');

    // Calculate stats
    const totalVehicles = company.total_vehicles || 0;
    const availableVehicles = company.available_vehicles || 0;
    const rentedVehicles = company.rented_vehicles || 0;
    const maintenanceVehicles = company.maintenance_vehicles || 0;
    const yearsEstablished = company.years_established || null;
    const operates247 = company.operates_24_7 == 1;
    const isVerified = company.verified == 1;
    const isFeatured = company.featured == 1;
    const isCertified = company.certified == 1;
    const hireCategory = company.hire_category || 'standard';

    // Parse event types
    let eventTypes = [];
    if (company.event_types) {
        try {
            eventTypes = typeof company.event_types === 'string'
                ? JSON.parse(company.event_types)
                : company.event_types;
        } catch (e) {}
    }

    header.innerHTML = `
        <div class="company-header-content container">
            <div class="header-top">
                <div class="company-header-left">
                    <h1>${escapeHtml(company.business_name)}</h1>
                    <div class="company-badge-row">
                        ${isVerified ? '<span class="company-status-tag verified-tag"><i class="fas fa-check-circle"></i> Verified</span>' : ''}
                        ${isFeatured ? '<span class="company-status-tag featured-tag"><i class="fas fa-star"></i> Featured</span>' : ''}
                        ${isCertified ? '<span class="company-status-tag certified-tag"><i class="fas fa-certificate"></i> Certified</span>' : ''}
                        ${(hireCategory === 'events' || hireCategory === 'all') ? '<span class="company-status-tag events-tag"><i class="fas fa-calendar-check"></i> Events</span>' : ''}
                        ${(hireCategory === 'vans_trucks' || hireCategory === 'all') ? '<span class="company-status-tag vantruck-tag"><i class="fas fa-truck"></i> Vans & Trucks</span>' : ''}
                    </div>
                    <div class="company-meta">
                        ${company.address ? `
                            <span class="meta-item address-meta" title="${escapeHtml(company.address)}">
                                <i class="fas fa-map-marker-alt"></i> ${escapeHtml(company.address)}
                            </span>
                        ` : `
                            <span class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                ${company.location_name}
                                ${company.district ? ` (${company.district})` : ''}
                            </span>
                        `}
                        ${company.phone ? `
                            <span class="meta-item">
                                <i class="fas fa-phone"></i> ${company.phone}
                            </span>
                        ` : ''}
                        ${company.email ? `
                            <span class="meta-item">
                                <i class="fas fa-envelope"></i> ${company.email}
                            </span>
                        ` : ''}
                        ${totalVehicles > 0 ? `
                            <span class="meta-item highlight">
                                <i class="fas fa-car"></i> ${totalVehicles} Vehicles
                            </span>
                        ` : ''}
                        ${yearsEstablished ? `
                            <span class="meta-item">
                                <i class="fas fa-calendar-check"></i> Since ${yearsEstablished}
                            </span>
                        ` : ''}
                    </div>
                </div>

                ${company.phone || company.whatsapp || company.address ? `
                    <div class="quick-contact-box">
                        <h4><i class="fas fa-phone-alt"></i> Quick Contact</h4>
                        <div class="quick-contact-buttons">
                            ${company.whatsapp ? `
                                <a href="https://wa.me/${company.whatsapp.replace(/[^0-9]/g, '')}" 
                                   class="quick-contact-btn whatsapp" target="_blank">
                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                </a>
                            ` : ''}
                            ${company.phone ? `
                                <a href="tel:${company.phone}" class="quick-contact-btn phone">
                                    <i class="fas fa-phone"></i> Call Now
                                </a>
                            ` : ''}
                            ${company.address ? `
                                <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(company.address)}" 
                                   class="quick-contact-btn directions" target="_blank">
                                    <i class="fas fa-directions"></i> Get Directions
                                </a>
                            ` : ''}
                        </div>
                    </div>
                ` : ''}
            </div>

            ${company.description ? `
                <div class="company-description">
                    <p>${escapeHtml(company.description)}</p>
                </div>
            ` : ''}

            ${eventTypes.length > 0 ? `
                <div class="company-event-types-detail">
                    <h4><i class="fas fa-calendar-check"></i> Events We Cover</h4>
                    <div class="event-types-list">
                        ${eventTypes.map(et => `<span class="event-type-tag">${escapeHtml(et)}</span>`).join('')}
                    </div>
                </div>
            ` : ''}

            <div class="car-hire-stats">
                ${totalVehicles > 0 ? `
                    <div class="feature-box">
                        <i class="fas fa-car"></i>
                        <div>
                            <strong>${totalVehicles}</strong>
                            <small>Total Fleet</small>
                        </div>
                    </div>
                ` : ''}
                <div class="feature-box stat-available">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>${availableVehicles}</strong>
                        <small>Available Now</small>
                    </div>
                </div>
                ${rentedVehicles > 0 ? `
                    <div class="feature-box stat-rented">
                        <i class="fas fa-key"></i>
                        <div>
                            <strong>${rentedVehicles}</strong>
                            <small>Rented Out</small>
                        </div>
                    </div>
                ` : ''}
                ${maintenanceVehicles > 0 ? `
                    <div class="feature-box stat-maintenance">
                        <i class="fas fa-wrench"></i>
                        <div>
                            <strong>${maintenanceVehicles}</strong>
                            <small>In Maintenance</small>
                        </div>
                    </div>
                ` : ''}
                ${operates247 ? `
                    <div class="feature-box">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>24/7</strong>
                            <small>Service</small>
                        </div>
                    </div>
                ` : ''}
                ${isVerified ? `
                    <div class="feature-box">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>Verified</strong>
                            <small>Business</small>
                        </div>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
}

function renderFleet(data) {
    const grid = document.getElementById('fleetGrid');
    const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22260%22 viewBox=%220 0 400 260%22%3E%3Crect width=%22400%22 height=%22260%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22130%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EVehicle image unavailable%3C/text%3E%3C/svg%3E';
    
    if (!data || data.length === 0) {
        grid.innerHTML = '<div class="no-vehicles"><i class="fas fa-car"></i><p>No vehicles available at the moment</p></div>';
        return;
    }
    
    grid.innerHTML = data.map(vehicle => {
        const features = vehicle.features ? JSON.parse(vehicle.features) : [];
        const imageSrc = vehicle.image ? `uploads/${vehicle.image}` : inlinePlaceholder;
        
        // Check status - either from status field or is_available field
        const status = vehicle.status || (vehicle.is_available == 1 ? 'available' : 'rented');
        const isAvailable = status === 'available';
        
        // Determine badge based on status
        let statusBadge = '';
        if (status === 'rented') {
            statusBadge = '<div class="unavailable-badge"><i class="fas fa-ban"></i> Rented Out</div>';
        } else if (status === 'maintenance') {
            statusBadge = '<div class="maintenance-badge"><i class="fas fa-wrench"></i> Maintenance</div>';
        } else if (status === 'not_available') {
            statusBadge = '<div class="not-available-badge"><i class="fas fa-times-circle"></i> Not Available</div>';
        } else if (status === 'available') {
            statusBadge = '<div class="available-badge"><i class="fas fa-check-circle"></i> Available</div>';
        }
        
        const isVanOrTruck = vehicle.vehicle_category === 'van' || vehicle.vehicle_category === 'truck';
        const distText = companyDistanceKm != null
            ? (companyDistanceKm < 1
                ? `${Math.round(companyDistanceKm * 1000)} m away`
                : `${companyDistanceKm.toFixed(1)} km away`)
            : null;

        return `
            <div class="fleet-card ${!isAvailable ? 'unavailable' : ''}">
                <div class="fleet-image">
                    <img src="${imageSrc}" alt="${escapeHtml(vehicle.vehicle_name)}" loading="lazy" onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                    ${statusBadge}
                    ${vehicle.registration_number ? `<div class="reg-badge">${escapeHtml(vehicle.registration_number)}</div>` : ''}
                    ${vehicle.vehicle_category && vehicle.vehicle_category !== 'car' ? `<div class="category-badge cat-${vehicle.vehicle_category}"><i class="fas fa-${vehicle.vehicle_category === 'van' ? 'van-shuttle' : 'truck'}"></i> ${capitalize(vehicle.vehicle_category)}</div>` : ''}
                    ${vehicle.event_suitable == 1 ? `<div class="event-suitable-flag"><i class="fas fa-calendar-check"></i> Event Ready</div>` : ''}
                </div>
                <div class="fleet-info">
                    <h3 class="fleet-title">${escapeHtml(vehicle.vehicle_name)}</h3>
                    ${isVanOrTruck && distText ? `<div class="fleet-distance-badge"><i class="fas fa-location-arrow"></i> ${distText}</div>` : (isVanOrTruck ? `<div class="fleet-distance-badge" style="display:none;"></div>` : '')}
                    
                    <div class="fleet-specs">
                        <div class="spec-item">
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <small>Year</small>
                                <strong>${vehicle.year}</strong>
                            </div>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-cogs"></i>
                            <div>
                                <small>Transmission</small>
                                <strong>${capitalize(vehicle.transmission)}</strong>
                            </div>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-gas-pump"></i>
                            <div>
                                <small>Fuel</small>
                                <strong>${capitalize(vehicle.fuel_type)}</strong>
                            </div>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-users"></i>
                            <div>
                                <small>Seats</small>
                                <strong>${vehicle.seats}</strong>
                            </div>
                        </div>
                        ${vehicle.exterior_color ? `
                        <div class="spec-item">
                            <i class="fas fa-palette"></i>
                            <div>
                                <small>Color</small>
                                <strong>${capitalize(vehicle.exterior_color)}</strong>
                            </div>
                        </div>
                        ` : `
                        <div class="spec-item placeholder" aria-hidden="true">
                            <i class="fas fa-palette"></i>
                            <div>
                                <small>Color</small>
                                <strong>&nbsp;</strong>
                            </div>
                        </div>
                        `}
                        ${vehicle.cargo_capacity ? `
                        <div class="spec-item">
                            <i class="fas fa-box"></i>
                            <div>
                                <small>Capacity</small>
                                <strong>${escapeHtml(vehicle.cargo_capacity)}</strong>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${features.length > 0 ? `
                        <div class="fleet-features">
                            <h4><i class="fas fa-check-circle"></i> Features</h4>
                            <div class="features-list">
                                ${features.slice(0, 6).map(f => `<span class="feature-badge"><i class="fas fa-check"></i> ${f}</span>`).join('')}
                            </div>
                        </div>
                    ` : `
                        <div class="fleet-features fleet-features-placeholder" aria-hidden="true">
                            <h4>Features</h4>
                            <div class="features-list"></div>
                        </div>
                    `}
                    
                    <div class="fleet-pricing">
                        <div class="pricing-row main-price">
                            <span class="price-label">Daily Rate</span>
                            <span class="price-value">
                                <strong>${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(vehicle.daily_rate)}</strong>
                                <small>/day</small>
                            </span>
                        </div>
                        ${vehicle.weekly_rate ? `
                            <div class="pricing-row">
                                <span class="price-label">Weekly Rate</span>
                                <span class="price-value">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(vehicle.weekly_rate)}</span>
                            </div>
                        ` : `
                            <div class="pricing-row placeholder" aria-hidden="true">
                                <span class="price-label">Weekly Rate</span>
                                <span class="price-value">&nbsp;</span>
                            </div>
                        `}
                        ${vehicle.monthly_rate ? `
                            <div class="pricing-row">
                                <span class="price-label">Monthly Rate</span>
                                <span class="price-value">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(vehicle.monthly_rate)}</span>
                            </div>
                        ` : `
                            <div class="pricing-row placeholder" aria-hidden="true">
                                <span class="price-label">Monthly Rate</span>
                                <span class="price-value">&nbsp;</span>
                            </div>
                        `}
                    </div>

                    ${isAvailable && companyData?.whatsapp ? `
                        <button class="fleet-wa-book-btn"
                            onclick="carHireBooking.open(${vehicle.id}, ${vehicle.company_id}, '${escapeHtml(vehicle.vehicle_name).replace(/'/g, "\\'")}', ${vehicle.daily_rate})">
                            <i class="fab fa-whatsapp"></i> Book via WhatsApp
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function renderContactCard(company) {
    const card = document.getElementById('contactCard');

    // Check if company has social media links
    const hasSocialMedia = company.facebook_url || company.instagram_url || company.twitter_url || company.linkedin_url;

    // Render services
    const servicesCard = document.getElementById('servicesContent');
    const services = company.services ? (typeof company.services === 'string' ? JSON.parse(company.services) : company.services) : [];
    const specialServices = company.special_services ? (typeof company.special_services === 'string' ? JSON.parse(company.special_services) : company.special_services) : [];
    
    if (services.length > 0 || specialServices.length > 0) {
        let servicesHTML = '<div class="services-grid">';
        
        // Regular Services
        if (services.length > 0) {
            servicesHTML += services.map(service => `
                <div class="service-item">
                    <i class="fas fa-check"></i>
                    <span class="service-name">${escapeHtml(service)}</span>
                </div>
            `).join('');
        }
        
        // Special Services
        if (specialServices.length > 0) {
            servicesHTML += specialServices.map(service => `
                <div class="service-item special">
                    <i class="fas fa-star"></i>
                    <span class="service-name">${escapeHtml(service)}</span>
                </div>
            `).join('');
        }
        
        servicesHTML += '</div>';
        servicesCard.innerHTML = servicesHTML;
    } else {
        servicesCard.innerHTML = `
            <div class="services-empty">
                <div class="empty-icon">
                    <i class="fas fa-concierge-bell"></i>
                </div>
                <p>Services information not available</p>
            </div>
        `;
    }

    card.innerHTML = `
        <h3><i class="fas fa-phone-alt"></i> Contact Information</h3>

        <!-- Contact Details (matches showroom.html structure) -->
        <div class="contact-info-list">
            ${company.phone ? `
                <div class="contact-info-item">
                    <i class="fas fa-phone-alt"></i>
                    <div>
                        <strong>Phone</strong>
                        <p><a href="tel:${escapeHtml(company.phone)}">${escapeHtml(company.phone)}</a></p>
                    </div>
                </div>
            ` : ''}
            ${company.email ? `
                <div class="contact-info-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <strong>Email</strong>
                        <p><a href="mailto:${escapeHtml(company.email)}">${escapeHtml(company.email)}</a></p>
                    </div>
                </div>
            ` : ''}
            ${company.whatsapp ? `
                <div class="contact-info-item">
                    <i class="fab fa-whatsapp" style="color:#25d366;"></i>
                    <div>
                        <strong>WhatsApp</strong>
                        <p><a href="https://wa.me/${company.whatsapp.replace(/[^0-9]/g, '')}" target="_blank">${escapeHtml(company.whatsapp)}</a></p>
                    </div>
                </div>
            ` : ''}
            ${company.website ? `
                <div class="contact-info-item">
                    <i class="fas fa-globe"></i>
                    <div>
                        <strong>Website</strong>
                        <p><a href="${escapeHtml(company.website)}" target="_blank" rel="noopener">Visit Website</a></p>
                    </div>
                </div>
            ` : ''}
        </div>

        <!-- Quick Action Buttons (matches showroom dealer-quick-actions) -->
        <div class="dealer-quick-actions contact-actions">
            ${company.phone ? `
                <a href="tel:${escapeHtml(company.phone)}" class="quick-action-btn">
                    <i class="fas fa-phone"></i> Call
                </a>
            ` : ''}
            ${company.whatsapp ? `
                <a href="https://wa.me/${company.whatsapp.replace(/[^0-9]/g, '')}" class="quick-action-btn whatsapp" target="_blank">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
            ` : ''}
            ${company.email ? `
                <a href="mailto:${escapeHtml(company.email)}" class="quick-action-btn">
                    <i class="fas fa-envelope"></i> Email
                </a>
            ` : ''}
            ${company.website ? `
                <a href="${escapeHtml(company.website)}" class="quick-action-btn website" target="_blank" rel="noopener">
                    <i class="fas fa-globe"></i> Website
                </a>
            ` : ''}
        </div>

        ${hasSocialMedia ? `
            <div class="dealer-social-section">
                <h4><i class="fas fa-share-alt"></i> Connect With Us</h4>
                <div class="social-icons-clean">
                    ${company.facebook_url ? `
                        <a href="${escapeHtml(company.facebook_url)}" target="_blank" rel="noopener noreferrer"
                           class="facebook" title="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                    ` : ''}
                    ${company.instagram_url ? `
                        <a href="${escapeHtml(company.instagram_url)}" target="_blank" rel="noopener noreferrer"
                           class="instagram" title="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                    ` : ''}
                    ${company.twitter_url ? `
                        <a href="${escapeHtml(company.twitter_url)}" target="_blank" rel="noopener noreferrer"
                           class="twitter" title="Twitter/X">
                            <i class="fab fa-twitter"></i>
                        </a>
                    ` : ''}
                    ${company.linkedin_url ? `
                        <a href="${escapeHtml(company.linkedin_url)}" target="_blank" rel="noopener noreferrer"
                           class="linkedin" title="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    ` : ''}
                </div>
            </div>
        ` : ''}
    `;

    // Update opening hours
    const hoursCardContent = document.getElementById('hoursContent');

    if (company.opening_hours) {
        try {
            const hours = typeof company.opening_hours === 'string'
                ? JSON.parse(company.opening_hours)
                : company.opening_hours;

            let hoursHTML = '<div class="hours-grid">';

            const days = [
                { key: 'monday', label: 'Monday' },
                { key: 'tuesday', label: 'Tuesday' },
                { key: 'wednesday', label: 'Wednesday' },
                { key: 'thursday', label: 'Thursday' },
                { key: 'friday', label: 'Friday' },
                { key: 'saturday', label: 'Saturday' },
                { key: 'sunday', label: 'Sunday' }
            ];

            days.forEach(day => {
                const dayData = hours[day.key];
                if (dayData && !dayData.closed && dayData.open && dayData.close) {
                    hoursHTML += `
                        <div class="hour-item">
                            <span class="day">${day.label}</span>
                            <span class="time">${dayData.open} - ${dayData.close}</span>
                        </div>
                    `;
                } else {
                    hoursHTML += `
                        <div class="hour-item">
                            <span class="day">${day.label}</span>
                            <span class="time closed">Closed</span>
                        </div>
                    `;
                }
            });

            hoursHTML += '</div>';

            // Update only hours card
            if (hoursCardContent) {
                hoursCardContent.innerHTML = hoursHTML;
            }
        } catch (error) {
            if (hoursCardContent) {
                hoursCardContent.innerHTML = '<p>Opening hours not available</p>';
            }
        }
    } else {
        if (hoursCardContent) {
            hoursCardContent.innerHTML = '<p>Opening hours not available</p>';
        }
    }
    
    // Initialize map with company address
    if (company.address && !mapInitialized) {
        initMap(company.address);
    }
}

// Function to copy address to clipboard
function copyAddress() {
    const addressElement = document.querySelector('#addressCard .address-text');
    if (addressElement) {
        const address = addressElement.textContent.replace('Address:', '').trim();
        navigator.clipboard.writeText(address).then(() => {
            alert('Address copied to clipboard!');
        }).catch(err => {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = address;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            alert('Address copied to clipboard!');
        });
    }
}

// Function to initialize Google Map with proper async loading
function initMap(address) {
    if (mapInitialized) return;
    
    
    if (typeof google === 'undefined' || !google.maps) {
        
        // Create a promise-based Google Maps loader
        loadGoogleMaps().then(() => {
            initMapWithAddress(address);
        }).catch((error) => {
            showMapError(address);
        });
    } else {
        initMapWithAddress(address);
    }
}

// Promise-based Google Maps loader
function loadGoogleMaps() {
    return new Promise(async (resolve, reject) => {
        if (typeof google !== 'undefined' && google.maps) {
            resolve();
            return;
        }

        // Check if we're already loading Google Maps
        if (window.googleMapsLoading) {
            // Wait for existing load to complete
            const checkInterval = setInterval(() => {
                if (typeof google !== 'undefined' && google.maps) {
                    clearInterval(checkInterval);
                    resolve();
                }
            }, 100);
            return;
        }

        window.googleMapsLoading = true;
        
        // Load Google Maps API with marker library for AdvancedMarkerElement
        const mapConfig = await window.getGoogleMapsConfig();
        if (!mapConfig || !mapConfig.apiKey) {
            window.googleMapsLoading = false;
            reject(new Error('Google Maps API key is not configured'));
            return;
        }

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${mapConfig.apiKey}&loading=async&libraries=places,marker`;
        script.async = true;
        
        script.onload = () => {
            // Google Maps loads asynchronously, so we need to wait for it to be ready
            const checkReady = setInterval(() => {
                if (typeof google !== 'undefined' && google.maps && google.maps.Map) {
                    clearInterval(checkReady);
                    window.googleMapsLoading = false;
                    resolve();
                }
            }, 50);

            // Timeout after 10 seconds
            setTimeout(() => {
                clearInterval(checkReady);
                if (typeof google === 'undefined' || !google.maps) {
                    window.googleMapsLoading = false;
                    reject(new Error('Google Maps failed to initialize within timeout period'));
                }
            }, 10000);
        };
        
        script.onerror = (error) => {
            window.googleMapsLoading = false;
            reject(new Error('Failed to load Google Maps API'));
        };
        
        document.head.appendChild(script);
    });
}

// Separate function to handle the actual map initialization
function initMapWithAddress(address) {
    if (mapInitialized) {
        return;
    }
    
    const mapElement = document.getElementById('companyMap');
    if (!mapElement) {
        return;
    }
    
    
    try {
        // Check if geocoder is available
        if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
            throw new Error('Google Maps not properly loaded');
        }
        
        const geocoder = new google.maps.Geocoder();
        
        
        geocoder.geocode({ address: address }, (results, status) => {
            
            if (status === 'OK' && results[0]) {
                try {
                    const mapOptions = {
                        zoom: 15,
                        center: results[0].geometry.location,
                        mapTypeControl: true,
                        streetViewControl: true,
                        fullscreenControl: true
                    };

                    if (CONFIG.GOOGLE_MAPS_MAP_ID) {
                        mapOptions.mapId = CONFIG.GOOGLE_MAPS_MAP_ID;
                    }

                    const map = new google.maps.Map(mapElement, mapOptions);

                    // Create custom marker icon element
                    const markerIcon = document.createElement('div');
                    markerIcon.innerHTML = '<i class="fas fa-map-marker-alt" style="font-size: 32px; color: #ff6f00;"></i>';

                    // Use AdvancedMarkerElement (replaces deprecated google.maps.Marker)
                    const marker = new google.maps.marker.AdvancedMarkerElement({
                        position: results[0].geometry.location,
                        map: map,
                        title: companyData?.business_name || 'Car Hire Company',
                        content: markerIcon
                    });


                    // Add info window
                    const infoWindow = new google.maps.InfoWindow({
                        content: `
                            <div style="padding: 10px; max-width: 250px;">
                                <h3 style="margin: 0 0 8px 0; color: #333; font-size: 16px;">${escapeHtml(companyData?.business_name || 'Car Hire Company')}</h3>
                                <p style="margin: 0; color: #666; font-size: 14px;">${escapeHtml(address)}</p>
                                <div style="margin-top: 8px;">
                                    <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(address)}" 
                                       target="_blank" 
                                       style="color: #ff6f00; text-decoration: none; font-size: 12px;">
                                        <i class="fas fa-directions"></i> Get Directions
                                    </a>
                                </div>
                            </div>
                        `
                    });

                    // Add click listener to marker (AdvancedMarkerElement uses addEventListener)
                    marker.addEventListener('click', () => {
                        infoWindow.open({ anchor: marker, map });
                    });

                    // Also add click listener to map for closing info window
                    map.addListener('click', () => {
                        infoWindow.close();
                    });

                    // Auto-open info window after a short delay
                    setTimeout(() => {
                        infoWindow.open({ anchor: marker, map });
                    }, 1000);

                    mapInitialized = true;

                    // Update the loading state in the UI
                    mapElement.style.opacity = '1';

                } catch (error) {
                    showMapError(address);
                }
            } else {
                showMapError(address);
            }
        });
    } catch (error) {
        showMapError(address);
    }
}

// Function to show map error state
function showMapError(address) {
    const mapElement = document.getElementById('companyMap');
    if (mapElement) {
        mapElement.innerHTML = `
            <div style="height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; color: #6c757d; padding: 20px; text-align: center;">
                <i class="fas fa-map-marked-alt" style="font-size: 48px; margin-bottom: 16px; color: #dc3545;"></i>
                <h4 style="margin: 0 0 8px 0;">Map Unavailable</h4>
                <p style="margin: 0 0 16px 0; font-size: 14px;">We couldn't load the map for this location.</p>
                <p style="margin: 0 0 16px 0; font-size: 12px; color: #999;">Address: ${escapeHtml(address)}</p>
                <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}" 
                   target="_blank" 
                   class="btn-directions" 
                   style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #ff6f00; color: white; text-decoration: none; border-radius: 6px; font-size: 14px;">
                    <i class="fas fa-external-link-alt"></i> Open in Google Maps
                </a>
            </div>
        `;
    }
}

function inquireVehicle(vehicleName) {
    if (companyData) {
        const message = `Hello, I'm interested in renting the ${vehicleName} from ${companyData.business_name}.`;
        const phone = companyData.whatsapp || companyData.phone;
        const cleanPhone = phone.replace(/[^0-9]/g, '');
        
        if (cleanPhone) {
            window.open(`https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`, '_blank');
        } else {
            alert('Contact number not available for this company.');
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(number) {
    return new Intl.NumberFormat().format(number);
}

function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}
// ============================================================================
// Car Hire Directory - Browse and Filter Car Hire Companies
// ============================================================================
// Displays available car rental companies with filtering and statistics
// Uses global CONFIG from config.js for API endpoints
// ============================================================================

let companies = [];
let stats = {};
let userLocation = null;
let geocodedCompanies = new Map(); // Cache geocoded addresses

// Pagination state
let carHirePerPage = 25;
let carHireCurrentPage = 1;
let carHireFilteredAll = []; // Full filtered set for pagination

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

            const data = await response.json();
            return data;
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
    loadStats();
    loadLocations();
    loadCompanies();
    getUserLocation();

    // When Google Maps API finishes loading (async), retry geocoding if we already
    // have user location (handles the race condition where geolocation resolved first)
    document.addEventListener('googlemapsloaded', function() {
        if (userLocation && companies.length > 0) {
            geocodeAndRenderCompanies();
        }
    });

    // Desktop search — live filter on input
    const carHireSearch = document.getElementById('carHireSearch');
    if (carHireSearch) {
        carHireSearch.addEventListener('input', () => applyFilters());
    }

    // Show/hide event type filter based on category selection
    const categoryFilter = document.getElementById('categoryFilter');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function() {
            const eventGroup = document.getElementById('eventTypeFilterGroup');
            if (eventGroup) {
                eventGroup.style.display = this.value === 'events' ? '' : 'none';
            }
            // Reset event type when switching away from events
            if (this.value !== 'events') {
                const etf = document.getElementById('eventTypeFilter');
                if (etf) etf.value = '';
            }
            applyFilters();
        });
    }

    // Event type auto-filter
    const eventTypeFilter = document.getElementById('eventTypeFilter');
    if (eventTypeFilter) {
        eventTypeFilter.addEventListener('change', () => applyFilters());
    }

    // Auto-apply on dropdown change for all remaining filter selects
    ['locationFilter', 'vehicleTypeFilter', 'transmissionFilter',
     'seatsFilter', 'fuelTypeFilter', 'sortFilter', 'distanceFilter'
    ].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => applyFilters());
    });

    // Mobile hero search — syncs with #carHireSearch and triggers applyFilters
    const mobileCarHireSearch = document.getElementById('mobileCarHireSearch');
    const mobileCarHireSearchBtn = document.getElementById('mobileCarHireSearchBtn');
    if (mobileCarHireSearch && mobileCarHireSearchBtn) {
        const doSearch = () => {
            const si = document.getElementById('carHireSearch');
            if (si) si.value = mobileCarHireSearch.value.trim();
            applyFilters();
        };
        mobileCarHireSearchBtn.addEventListener('click', doSearch);
        mobileCarHireSearch.addEventListener('input', doSearch);
        mobileCarHireSearch.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });
    }
});

// Load overall car hire statistics
async function loadStats() {
    try {
        const data = await fetchJsonWithRetry(`${CONFIG.API_URL}?action=car_hire_stats`);

        if (data.success) {
            stats = data.stats;
            updateStatsDisplay();
        }
    } catch (error) {
        // Set default values if API fails
        ['totalCompanies','mobileAvailableVehicles','mobileTotalCompanies'].forEach(id => {
            const el = document.getElementById(id); if (el) el.textContent = '0';
        });
        ['availableVehicles','totalCities','featuredCompanies',
         'mobileTotalCities','mobileFeaturedCompanies'].forEach(id => {
            const el = document.getElementById(id); if (el) el.textContent = '0';
        });
    }
}

function updateStatsDisplay() {
    const available    = stats.available_vehicles || 0;
    const companies    = stats.total_companies    || 0;
    const cities       = stats.total_cities       || 0;
    const featured     = stats.featured_companies || 0;

    // Hero stats (desktop)
    const companiesElement = document.getElementById('totalCompanies');
    const vehiclesElement  = document.getElementById('availableVehicles');
    const citiesElement    = document.getElementById('totalCities');
    const featuredElement  = document.getElementById('featuredCompanies');
    if (companiesElement) companiesElement.textContent = companies + '+';
    if (vehiclesElement)  vehiclesElement.textContent  = available + '+';
    if (citiesElement)    citiesElement.textContent    = cities;
    if (featuredElement)  featuredElement.textContent  = featured;

    // Mobile stats strip
    const mAvailable  = document.getElementById('mobileAvailableVehicles');
    const mCompanies  = document.getElementById('mobileTotalCompanies');
    const mCities     = document.getElementById('mobileTotalCities');
    const mFeatured   = document.getElementById('mobileFeaturedCompanies');
    if (mAvailable) mAvailable.textContent = available + '+';
    if (mCompanies) mCompanies.textContent = companies + '+';
    if (mCities)    mCities.textContent    = cities;
    if (mFeatured)  mFeatured.textContent  = featured;

    // Show/hide mobile strip based on data
    const strip = document.getElementById('chMobileStats');
    if (strip) strip.classList.toggle('ch-ms-loaded', companies > 0);
}

// Load locations that actually have car hire companies
async function loadLocations() {
    try {
        const data = await fetchJsonWithRetry(`${CONFIG.API_URL}?action=car_hire_locations`);

        if (data.success) {
            const select = document.getElementById('locationFilter');
            // Clear existing options except the first one
            select.innerHTML = '<option value="">All Locations</option>';

            data.locations.forEach(loc => {
                const option = document.createElement('option');
                option.value = loc.id;
                // Display location with district information
                let displayText = loc.name;
                if (loc.district) {
                    displayText += ` (${loc.district}`;
                    if (loc.region) {
                        displayText += `, ${loc.region}`;
                    }
                    displayText += ')';
                } else if (loc.region) {
                    displayText += ` (${loc.region})`;
                }
                option.textContent = displayText;
                select.appendChild(option);
            });
        }
    } catch (error) {
    }
}

// Load companies with additional filtering options
async function loadCompanies() {
    try {
        const data = await fetchJsonWithRetry(`${CONFIG.API_URL}?action=car_hire_companies_with_fleet`);
        
        if (data.success) {
            companies = data.companies;
            renderCompanies(companies);
            populateAdditionalFilters(companies);
            updateResultsCount(companies.length);
            
            // Geocode addresses if user location is available
            if (userLocation) {
                geocodeAndRenderCompanies();
            }
        } else {
            showNoCompaniesMessage();
        }
    } catch (error) {
        showErrorMessage();
    }
}

// Populate additional filter options based on available company data
function populateAdditionalFilters(companiesData) {
    // Populate vehicle type filter from company data
    const vehicleTypes = [...new Set(companiesData.flatMap(company => {
        if (company.vehicle_types && typeof company.vehicle_types === 'string') {
            try {
                return JSON.parse(company.vehicle_types);
            } catch (e) {
                return [];
            }
        }
        return company.vehicle_types || [];
    }))];

    const vehicleTypeFilter = document.getElementById('vehicleTypeFilter');
    if (vehicleTypeFilter && vehicleTypes.length > 0) {
        vehicleTypes.forEach(type => {
            if (type) {
                const option = document.createElement('option');
                option.value = type.toLowerCase().replace(/\s+/g, '_');
                option.textContent = type;
                vehicleTypeFilter.appendChild(option);
            }
        });
    }
}

function renderCompanies(data) {
    const grid = document.getElementById('companiesGrid');
    
    if (!data || data.length === 0) {
        showNoCompaniesMessage();
        return;
    }

    // Store full filtered set for pagination
    carHireFilteredAll = data;
    const total = data.length;
    const perPage = carHirePerPage;
    const page = carHireCurrentPage;
    const paged = perPage === 0 ? data : data.slice((page - 1) * perPage, page * perPage);
    
    grid.innerHTML = paged.map(company => {
        // Parse vehicle types safely
        let vehicleTypes = [];
        if (company.vehicle_types) {
            try {
                vehicleTypes = typeof company.vehicle_types === 'string' 
                    ? JSON.parse(company.vehicle_types) 
                    : company.vehicle_types;
            } catch (e) {
                vehicleTypes = [];
            }
        }
        
        // Parse services safely
        let services = [];
        if (company.services) {
            try {
                services = typeof company.services === 'string' 
                    ? JSON.parse(company.services) 
                    : company.services;
            } catch (e) {
                services = [];
            }
        }
        
        // Build badges array
        const badges = [];
        const isFeatured = company.featured == 1;
        const isCertified = company.certified == 1;
        const isVerified = company.verified == 1;

        if (isFeatured) badges.push('<span class="company-status-tag featured-tag"><i class="fas fa-star"></i> Featured</span>');
        if (isCertified) badges.push('<span class="company-status-tag certified-tag"><i class="fas fa-certificate"></i> Certified</span>');
        if (isVerified) badges.push('<span class="company-status-tag verified-tag"><i class="fas fa-check-circle"></i> Verified</span>');

        // Category badges
        const hireCategory = company.hire_category || 'standard';
        if (hireCategory === 'events' || hireCategory === 'all') {
            badges.push('<span class="company-status-tag events-tag"><i class="fas fa-calendar-check"></i> Events</span>');
        }
        if (hireCategory === 'vans_trucks' || hireCategory === 'all') {
            badges.push('<span class="company-status-tag vantruck-tag"><i class="fas fa-truck"></i> Vans & Trucks</span>');
        }

        // Parse event types
        let eventTypes = [];
        if (company.event_types) {
            try {
                eventTypes = typeof company.event_types === 'string'
                    ? JSON.parse(company.event_types)
                    : company.event_types;
            } catch (e) {
                eventTypes = [];
            }
        }

        // Calculate distance from user if location available — render as clickable directions link
        let distanceInfo = '';
        if (userLocation && company.latitude && company.longitude) {
            const distance = calculateDistance(
                userLocation.lat,
                userLocation.lng,
                parseFloat(company.latitude),
                parseFloat(company.longitude)
            );
            const mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${userLocation.lat},${userLocation.lng}&destination=${company.latitude},${company.longitude}&travelmode=driving`;
            distanceInfo = `<button type="button" class="loc-chip distance-info clickable-chip" onclick="event.stopPropagation(); window.open('${mapsUrl}', '_blank', 'noopener,noreferrer');" title="Get directions to ${escapeHtml(company.business_name || '')}"><i class="fas fa-location-arrow"></i> ${distance.toFixed(1)} km away</button>`;
        }

        return `
        <a href="car-hire-company.html?id=${company.id}" class="company-card-link">
            <div class="company-card ${isFeatured ? 'featured-company' : ''}">
                <div class="company-card-header">
                    <div class="company-header-left" style="display:flex;align-items:center;gap:10px;">
                        <div class="card-logo-dp"${company.logo_url ? ` style="background-image:url('${company.logo_url}')"` : ''}>
                            ${!company.logo_url ? '<i class="fas fa-key"></i>' : ''}
                        </div>
                        <h3 class="company-business-name">${escapeHtml(company.business_name)}</h3>
                    </div>
                    ${badges.length > 0 ? `
                        <div class="company-header-right">
                            ${badges.join('')}
                        </div>
                    ` : ''}
                </div>

                <div class="loc-panel">
                    <div class="loc-chips">
                        <span class="loc-chip"><i class="fas fa-map-marker-alt"></i> ${company.location_name}</span>
                        ${distanceInfo}
                    </div>
                    <div class="loc-stats">
                        <span class="loc-chip"><i class="fas fa-history"></i> ${company.years_established ? 'Est. ' + company.years_established : 'New'}</span>
                        <span class="loc-chip"><i class="fas fa-car"></i> ${company.total_vehicles || 0} vehicles</span>
                    </div>
                </div>
                ${company.address ? `
                    <div class="company-address">
                        <i class="fas fa-building"></i>
                        <span>${escapeHtml(company.address)}</span>
                    </div>
                ` : ''}
                ${company.phone ? `
                    <div class="company-address">
                        <i class="fas fa-phone"></i>
                        <span>${escapeHtml(company.phone)}</span>
                    </div>
                ` : ''}

                <div class="company-card-body">
                    ${(company.total_vehicles > 0 || company.daily_rate_from || company.weekly_rate_from) ? `
                    <div class="company-stats-display">
                        ${company.total_vehicles > 0 ? `<div class="stat-item-inline"><i class="fas fa-car"></i><span>${company.total_vehicles} vehicles</span></div>` : ''}
                        ${company.daily_rate_from && company.daily_rate_to ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-day"></i>
                                <span class="company-rate-text">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(company.daily_rate_from)} - ${formatNumber(company.daily_rate_to)}/day</span>
                            </div>
                        ` : company.daily_rate_from ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-day"></i>
                                <span class="company-rate-text">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(company.daily_rate_from)}/day</span>
                            </div>
                        ` : ''}
                        ${company.weekly_rate_from && company.weekly_rate_to ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-week"></i>
                                <span class="company-rate-text">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(company.weekly_rate_from)} - ${formatNumber(company.weekly_rate_to)}/week</span>
                            </div>
                        ` : company.weekly_rate_from ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-week"></i>
                                <span class="company-rate-text">${CONFIG.CURRENCY_CODE || 'MWK'} ${formatNumber(company.weekly_rate_from)}/week</span>
                            </div>
                        ` : ''}
                    </div>
                    ` : ''}

                    ${vehicleTypes.length > 0 ? `
                        <div class="company-vehicle-types">
                            <i class="fas fa-car-side"></i>
                            <div class="vehicle-types-list">
                                ${vehicleTypes.slice(0, 6).map(type =>
                                    `<span class="vehicle-type-tag">${escapeHtml(type)}</span>`
                                ).join('')}
                                ${vehicleTypes.length > 6 ? `<span class="vehicle-type-tag more-types">+${vehicleTypes.length - 6}</span>` : ''}
                            </div>
                        </div>
                    ` : ''}

                    ${services.length > 0 ? `
                        <div class="company-services">
                            <i class="fas fa-concierge-bell"></i>
                            <div class="services-list">
                                ${services.slice(0, 4).map(service =>
                                    `<span class="service-tag">${escapeHtml(service)}</span>`
                                ).join('')}
                                ${services.length > 4 ? `<span class="service-tag more-services">+${services.length - 4}</span>` : ''}
                            </div>
                        </div>
                    ` : ''}

                    ${eventTypes.length > 0 ? `
                        <div class="company-event-types">
                            <i class="fas fa-calendar-check"></i>
                            <div class="event-types-list">
                                ${eventTypes.slice(0, 5).map(et =>
                                    `<span class="event-type-tag">${escapeHtml(et)}</span>`
                                ).join('')}
                                ${eventTypes.length > 5 ? `<span class="event-type-tag more-events">+${eventTypes.length - 5}</span>` : ''}
                            </div>
                        </div>
                    ` : ''}

                    ${(company.van_count > 0 || company.truck_count > 0) ? `
                        <div class="company-cargo-info">
                            ${company.van_count > 0 ? `<span class="cargo-badge van-badge"><i class="fas fa-van-shuttle"></i> ${company.van_count} Van${company.van_count > 1 ? 's' : ''}</span>` : ''}
                            ${company.truck_count > 0 ? `<span class="cargo-badge truck-badge"><i class="fas fa-truck"></i> ${company.truck_count} Truck${company.truck_count > 1 ? 's' : ''}</span>` : ''}
                        </div>
                    ` : ''}
                </div>

                <div class="company-card-actions">
                    <span class="company-action-btn primary">
                        <i class="fas fa-car"></i>
                        <span>View Fleet</span>
                    </span>
                </div>
            </div>
        </a>
        `;
    }).join('');

    // Append pagination controls after the grid
    const pagContainer = document.getElementById('companiesGrid')?.parentElement;
    if (pagContainer) {
        let existingPag = pagContainer.querySelector('.ml-pagination');
        if (existingPag) existingPag.remove();
        const pagDiv = document.createElement('div');
        pagDiv.innerHTML = buildCarHirePaginationHTML(total, page, perPage);
        if (pagDiv.firstElementChild) pagContainer.appendChild(pagDiv.firstElementChild);
    }
}

function applyFilters() {
    carHireCurrentPage = 1; // Reset page on new filter
    const searchTerm = (document.getElementById('carHireSearch')?.value || '').toLowerCase().trim();
    const location = document.getElementById('locationFilter').value;
    const sort = document.getElementById('sortFilter').value;
    const vehicleType = document.getElementById('vehicleTypeFilter')?.value;
    const transmission = document.getElementById('transmissionFilter')?.value;
    const seats = document.getElementById('seatsFilter')?.value;
    const fuelType = document.getElementById('fuelTypeFilter')?.value;
    const category = document.getElementById('categoryFilter')?.value;
    const eventType = document.getElementById('eventTypeFilter')?.value;

    let filtered = [...companies];

    // Category filter (standard / events / vans_trucks)
    if (category) {
        filtered = filtered.filter(c => {
            const hc = c.hire_category || 'standard';
            if (category === 'events') return hc === 'events' || hc === 'all';
            if (category === 'vans_trucks') {
                // Match companies with hire_category vans_trucks/all, OR companies with van/truck fleet
                if (hc === 'vans_trucks' || hc === 'all') return true;
                // Also include companies that happen to have van/truck fleet vehicles
                if (c.fleet && Array.isArray(c.fleet)) {
                    return c.fleet.some(v => v.vehicle_category === 'van' || v.vehicle_category === 'truck');
                }
                return false;
            }
            if (category === 'standard') return hc === 'standard' || hc === 'all';
            return true;
        });
    }

    // Event type filter (only visible when category=events)
    if (eventType) {
        filtered = filtered.filter(c => {
            if (!c.event_types) return false;
            try {
                const types = typeof c.event_types === 'string'
                    ? JSON.parse(c.event_types)
                    : c.event_types;
                return types.some(t => t.toLowerCase() === eventType.toLowerCase());
            } catch (e) {
                return false;
            }
        });
    }

    // Text search (name, description, location, address, phone)
    if (searchTerm) {
        filtered = filtered.filter(c => {
            const name  = (c.business_name || c.company_name || '').toLowerCase();
            const desc  = (c.description   || '').toLowerCase();
            const loc   = (c.location_name || c.location || '').toLowerCase();
            const addr  = (c.address       || '').toLowerCase();
            const phone = (c.phone         || c.contact_phone || '').toLowerCase();
            return name.includes(searchTerm) || desc.includes(searchTerm) ||
                   loc.includes(searchTerm)  || addr.includes(searchTerm) ||
                   phone.includes(searchTerm);
        });
    }

    // Location filter
    if (location) {
        filtered = filtered.filter(c => c.location_id == location);
    }

    // Vehicle type filter
    if (vehicleType) {
        filtered = filtered.filter(c => {
            if (!c.vehicle_types) return false;
            try {
                const types = typeof c.vehicle_types === 'string'
                    ? JSON.parse(c.vehicle_types)
                    : c.vehicle_types;
                return types.some(type =>
                    type.toLowerCase().replace(/\s+/g, '_').includes(vehicleType)
                );
            } catch (e) {
                return false;
            }
        });
    }

    // Transmission filter
    if (transmission) {
        filtered = filtered.filter(c => {
            if (!c.fleet || !Array.isArray(c.fleet)) return false;
            return c.fleet.some(vehicle => {
                const vehicleTransmission = (vehicle.transmission || '').toLowerCase();
                return vehicleTransmission.includes(transmission.toLowerCase());
            });
        });
    }

    // Seats filter
    if (seats) {
        filtered = filtered.filter(c => {
            if (!c.fleet || !Array.isArray(c.fleet)) return false;
            return c.fleet.some(vehicle => {
                const vehicleSeats = parseInt(vehicle.seats) || 0;
                if (seats === '2-4') {
                    return vehicleSeats >= 2 && vehicleSeats <= 4;
                } else if (seats === '5-6') {
                    return vehicleSeats >= 5 && vehicleSeats <= 6;
                } else if (seats === '7+') {
                    return vehicleSeats >= 7;
                }
                return false;
            });
        });
    }

    // Fuel type filter
    if (fuelType) {
        filtered = filtered.filter(c => {
            if (!c.fleet || !Array.isArray(c.fleet)) return false;
            return c.fleet.some(vehicle => {
                const vehicleFuelType = (vehicle.fuel_type || '').toLowerCase();
                return vehicleFuelType.includes(fuelType.toLowerCase());
            });
        });
    }

    // Distance radius filter (only active when user location is available)
    const distanceFilterEl = document.getElementById('distanceFilter');
    const distanceRadius = distanceFilterEl && !distanceFilterEl.disabled ? parseFloat(distanceFilterEl.value) : NaN;
    if (!isNaN(distanceRadius) && userLocation) {
        filtered = filtered.filter(c => {
            if (!c.latitude || !c.longitude) return true; // keep ungeocoded companies
            const dist = calculateDistance(userLocation.lat, userLocation.lng, parseFloat(c.latitude), parseFloat(c.longitude));
            return dist <= distanceRadius;
        });
    }

    // Sort results
    if (sort === 'nearest') {
        if (!userLocation) {
            // Auto-request location; results render unsorted for now, will re-sort once obtained
            getUserLocation();
        } else {
            filtered.sort((a, b) => {
                const distA = (a.latitude && a.longitude)
                    ? calculateDistance(userLocation.lat, userLocation.lng, parseFloat(a.latitude), parseFloat(a.longitude))
                    : 999999;
                const distB = (b.latitude && b.longitude)
                    ? calculateDistance(userLocation.lat, userLocation.lng, parseFloat(b.latitude), parseFloat(b.longitude))
                    : 999999;
                return distA - distB;
            });
        }
    } else if (sort === 'vehicles') {
        filtered.sort((a, b) => (b.total_vehicles || 0) - (a.total_vehicles || 0));
    } else if (sort === 'price') {
        filtered.sort((a, b) => (a.daily_rate_from || 999999) - (b.daily_rate_from || 999999));
    } else if (sort === 'featured') {
        filtered.sort((a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0));
    } else if (sort === 'name') {
        filtered.sort((a, b) => (a.business_name || '').localeCompare(b.business_name || ''));
    }

    renderCompanies(filtered);
    updateResultsCount(filtered.length);
}

function buildCarHirePaginationHTML(total, page, perPage) {
    if (total === 0) return '';
    const effPer = perPage === 0 ? total : perPage;
    const totalPages = Math.ceil(total / effPer);
    const start = (page - 1) * effPer + 1;
    const end   = Math.min(page * effPer, total);
    return `<div class="ml-pagination">
        <span class="ml-pag-info">Showing ${start}\u2013${end} of ${total}</span>
        <div class="ml-pag-controls">
            <button class="ml-pag-btn" onclick="window.setCarHirePage(${page - 1},${perPage})" ${page <= 1 ? 'disabled' : ''}>&#8249; Prev</button>
            <span class="ml-pag-pages">Page ${page} of ${totalPages}</span>
            <button class="ml-pag-btn" onclick="window.setCarHirePage(${page + 1},${perPage})" ${page >= totalPages ? 'disabled' : ''}>Next &#8250;</button>
        </div>
        <label class="ml-pag-perpage">Show:&nbsp;<select class="ml-pag-select" onchange="window.setCarHirePage(1,parseInt(this.value))">
            <option value="25" ${perPage===25?'selected':''}>25</option>
            <option value="50" ${perPage===50?'selected':''}>50</option>
            <option value="100" ${perPage===100?'selected':''}>100</option>
            <option value="200" ${perPage===200?'selected':''}>200</option>
            <option value="250" ${perPage===250?'selected':''}>250</option>
            <option value="0" ${perPage===0?'selected':''}>All</option>
        </select></label>
    </div>`;
}

window.setCarHirePage = function(page, perPage) {
    const total = carHireFilteredAll.length;
    const effPer = perPage === 0 ? total : perPage;
    const totalPages = Math.ceil(total / effPer) || 1;
    carHireCurrentPage = Math.max(1, Math.min(page, totalPages));
    carHirePerPage = perPage;
    renderCompanies(carHireFilteredAll);
    const grid = document.getElementById('companiesGrid');
    if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

function buildCarHirePaginationHTML(total, page, perPage) {
    if (total === 0) return '';
    const effPer = perPage === 0 ? total : perPage;
    const totalPages = Math.ceil(total / effPer);
    const start = (page - 1) * effPer + 1;
    const end   = Math.min(page * effPer, total);
    return `<div class="ml-pagination">
        <span class="ml-pag-info">Showing ${start}\u2013${end} of ${total}</span>
        <div class="ml-pag-controls">
            <button class="ml-pag-btn" onclick="window.setCarHirePage(${page - 1},${perPage})" ${page <= 1 ? 'disabled' : ''}>&#8249; Prev</button>
            <span class="ml-pag-pages">Page ${page} of ${totalPages}</span>
            <button class="ml-pag-btn" onclick="window.setCarHirePage(${page + 1},${perPage})" ${page >= totalPages ? 'disabled' : ''}>Next &#8250;</button>
        </div>
        <label class="ml-pag-perpage">Show:&nbsp;<select class="ml-pag-select" onchange="window.setCarHirePage(1,parseInt(this.value))">
            <option value="25" ${perPage===25?'selected':''}>25</option>
            <option value="50" ${perPage===50?'selected':''}>50</option>
            <option value="100" ${perPage===100?'selected':''}>100</option>
            <option value="200" ${perPage===200?'selected':''}>200</option>
            <option value="250" ${perPage===250?'selected':''}>250</option>
            <option value="0" ${perPage===0?'selected':''}>All</option>
        </select></label>
    </div>`;
}

window.setCarHirePage = function(page, perPage) {
    const total = carHireFilteredAll.length;
    const effPer = perPage === 0 ? total : perPage;
    const totalPages = Math.ceil(total / effPer) || 1;
    carHireCurrentPage = Math.max(1, Math.min(page, totalPages));
    carHirePerPage = perPage;
    renderCompanies(carHireFilteredAll);
    const grid = document.getElementById('companiesGrid');
    if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

function updateResultsCount(count) {
    const resultsElement = document.getElementById('resultsCount');
    if (resultsElement) {
        resultsElement.textContent = `Showing ${count} of ${companies.length} companies`;
    }
}

function showNoCompaniesMessage() {
    const grid = document.getElementById('companiesGrid');
    grid.innerHTML = `
        <div class="no-results">
            <i class="fas fa-car" style="font-size: 3rem; color: #ddd; margin-bottom: 16px;"></i>
            <h3>No Car Hire Companies Found</h3>
            <p>There are currently no car hire companies available in your selected criteria.</p>
            <button onclick="clearFilters()" class="btn btn-primary" style="margin-top: 16px;">
                <i class="fas fa-refresh"></i> Clear Filters
            </button>
        </div>
    `;
}

function showErrorMessage() {
    const grid = document.getElementById('companiesGrid');
    grid.innerHTML = `
        <div class="loading">
            <i class="fas fa-exclamation-circle"></i>
            <p>Failed to load car hire companies. Please try again later.</p>
            <button onclick="retryCarHireLoad()" class="btn btn-primary" style="margin-top: 14px;">
                <i class="fas fa-redo"></i> Try Again
            </button>
        </div>
    `;
}

function retryCarHireLoad() {
    const grid = document.getElementById('companiesGrid');
    if (grid) {
        grid.innerHTML = `
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Retrying...</p>
            </div>
        `;
    }

    loadStats();
    loadLocations();
    loadCompanies();
}

function clearFilters() {
    const ids = ['locationFilter', 'vehicleTypeFilter', 'transmissionFilter',
                 'seatsFilter', 'fuelTypeFilter', 'distanceFilter'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    const sortFilter = document.getElementById('sortFilter');
    if (sortFilter) sortFilter.value = 'featured';

    const carHireSearch = document.getElementById('carHireSearch');
    if (carHireSearch) carHireSearch.value = '';

    const mobileCarHireSearch = document.getElementById('mobileCarHireSearch');
    if (mobileCarHireSearch) mobileCarHireSearch.value = '';

    const categoryFilter = document.getElementById('categoryFilter');
    if (categoryFilter) categoryFilter.value = '';

    const eventTypeFilter = document.getElementById('eventTypeFilter');
    if (eventTypeFilter) eventTypeFilter.value = '';

    const eventGroup = document.getElementById('eventTypeFilterGroup');
    if (eventGroup) eventGroup.style.display = 'none';

    renderCompanies(companies);
    updateResultsCount(companies.length);
}

function generateStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

    let starsHTML = '';

    // Full stars
    for (let i = 0; i < fullStars; i++) {
        starsHTML += '<i class="fas fa-star star"></i>';
    }

    // Half star
    if (hasHalfStar) {
        starsHTML += '<i class="fas fa-star-half-alt star"></i>';
    }

    // Empty stars
    for (let i = 0; i < emptyStars; i++) {
        starsHTML += '<i class="far fa-star star"></i>';
    }

    return starsHTML;
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

// Calculate distance between two coordinates using Haversine formula
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Radius of the Earth in km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c; // Distance in km
}

// Get user's current location
function getUserLocation() {
    if (!navigator.geolocation) {
        showCarHireStatusHint('Location services are not supported by your browser.', true);
        return;
    }

    showCarHireStatusHint('<i class="fas fa-spinner fa-spin"></i> Getting your location…');

    navigator.geolocation.getCurrentPosition(
        function(position) {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };

            showCarHireStatusHint('');

            // Enable distance filter now that we have a location
            const distFilter = document.getElementById('distanceFilter');
            if (distFilter) {
                distFilter.disabled = false;
                distFilter.title = 'Filter by distance from your location';
                distFilter.addEventListener('change', () => applyFilters());
            }

            // Geocode then re-apply filters (preserves current sort/filter state)
            if (companies.length > 0) {
                geocodeAndRenderCompanies();
            }
        },
        function(error) {
            const msg = error.code === error.PERMISSION_DENIED
                ? 'Location access denied. Enable it in browser settings to sort by distance.'
                : 'Could not get your location. Please try again.';
            showCarHireStatusHint(msg, true);
            // Reset sort dropdown back to featured so UI matches behaviour
            const sortEl = document.getElementById('sortFilter');
            if (sortEl && sortEl.value === 'nearest') {
                sortEl.value = 'featured';
            }
        },
        {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 300000 // Cache for 5 minutes
        }
    );
}

// Show/clear a small status hint below the sort filter
function showCarHireStatusHint(html, isError) {
    let hint = document.getElementById('carHireSortHint');
    if (!hint) {
        hint = document.createElement('p');
        hint.id = 'carHireSortHint';
        hint.style.cssText = 'font-size:12px;margin:4px 0 0;padding:0;';
        const sortEl = document.getElementById('sortFilter');
        if (sortEl && sortEl.parentNode) sortEl.parentNode.appendChild(hint);
    }
    hint.style.color = isError ? '#d32f2f' : '#555';
    hint.innerHTML = html;
}

// Geocode a company address to get coordinates
async function geocodeAddress(address, locationName) {
    // Validate parameters
    if (!address || !address.trim()) return null;
    
    // Create a full address string
    const countryName = (window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : '';
    const fullAddress = [address, locationName, countryName].map((part) => String(part || '').trim()).filter(Boolean).join(', ');
    
    // Check cache first
    if (geocodedCompanies.has(fullAddress)) {
        return geocodedCompanies.get(fullAddress);
    }
    
    // Check if Google Maps is available
    if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
        return null; // Maps not loaded yet; geocodeAndRenderCompanies will retry via googlemapsloaded event
    }
    
    try {
        const geocoder = new google.maps.Geocoder();
        
        return new Promise((resolve) => {
            geocoder.geocode({ address: fullAddress }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    const location = {
                        lat: results[0].geometry.location.lat(),
                        lng: results[0].geometry.location.lng()
                    };
                    geocodedCompanies.set(fullAddress, location);
                    resolve(location);
                } else {
                    console.log('Geocoding failed for:', fullAddress, status);
                    resolve(null);
                }
            });
        });
    } catch (error) {
        console.error('Error geocoding address:', error);
        return null;
    }
}

// Geocode all companies and re-render with distance information
async function geocodeAndRenderCompanies() {
    if (!userLocation) {
        return;
    }

    // Bail out if Maps API not ready yet; the 'googlemapsloaded' event listener will re-call us
    if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
        return;
    }
    
    // Geocode addresses for companies that have addresses
    const geocodePromises = companies
        .filter(company => company.address)
        .map(async (company) => {
            const coords = await geocodeAddress(company.address, company.location_name);
            if (coords) {
                company.latitude = coords.lat;
                company.longitude = coords.lng;
            }
        });
    
    await Promise.all(geocodePromises);
    
    // Re-apply filters/sort so any active "Nearest First" sort takes effect
    applyFilters();
}
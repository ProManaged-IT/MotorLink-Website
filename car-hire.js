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

document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadLocations();
    loadCompanies();
    getUserLocation();
});

// Load overall car hire statistics
async function loadStats() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=car_hire_stats`);
        const data = await response.json();

        if (data.success) {
            stats = data.stats;
            updateStatsDisplay();
        }
    } catch (error) {
        // Set default values if API fails
        document.getElementById('totalCompanies').textContent = '0';
        document.getElementById('totalVehicles').textContent = '0';
        document.getElementById('totalCities').textContent = '0';
        document.getElementById('featuredCompanies').textContent = '0';
    }
}

function updateStatsDisplay() {
    const companiesElement = document.getElementById('totalCompanies');
    const vehiclesElement = document.getElementById('totalVehicles');
    const citiesElement = document.getElementById('totalCities');
    const featuredElement = document.getElementById('featuredCompanies');

    if (companiesElement) companiesElement.textContent = (stats.total_companies || 0) + '+';
    if (vehiclesElement) vehiclesElement.textContent = (stats.total_vehicles || 0) + '+';
    if (citiesElement) citiesElement.textContent = stats.total_cities || 0;
    if (featuredElement) featuredElement.textContent = stats.featured_companies || 0;
}

// Load locations that actually have car hire companies
async function loadLocations() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=car_hire_locations`);
        const data = await response.json();

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
        const response = await fetch(`${CONFIG.API_URL}?action=car_hire_companies_with_fleet`);
        const data = await response.json();
        
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
    
    grid.innerHTML = data.map(company => {
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

        // Calculate distance from user if location available
        let distanceInfo = '';
        if (userLocation && company.latitude && company.longitude) {
            const distance = calculateDistance(
                userLocation.lat,
                userLocation.lng,
                parseFloat(company.latitude),
                parseFloat(company.longitude)
            );
            distanceInfo = `
                <span class="distance-info">
                    <i class="fas fa-location-arrow"></i>
                    ${distance.toFixed(1)} km away
                </span>
            `;
        }

        return `
        <a href="car-hire-company.html?id=${company.id}" class="company-card-link">
            <div class="company-card ${isFeatured ? 'featured-company' : ''}">
                <div class="company-card-header">
                    <div class="company-header-left">
                        <h3 class="company-business-name">${escapeHtml(company.business_name)}</h3>
                    </div>
                    ${badges.length > 0 ? `
                        <div class="company-header-right">
                            ${badges.join('')}
                        </div>
                    ` : ''}
                </div>

                <div class="company-location-meta">
                    <span><i class="fas fa-map-marker-alt"></i> ${company.location_name}</span>
                    ${distanceInfo}
                    <span>${company.years_established ? 'Est. ' + company.years_established : 'Newly Established'}</span>
                    <span>${company.total_vehicles || 0} vehicles</span>
                </div>
                ${company.address ? `
                    <div class="company-address">
                        <i class="fas fa-building"></i>
                        <span>${escapeHtml(company.address)}</span>
                    </div>
                ` : ''}

                <div class="company-card-body">
                    <div class="company-stats-display">
                        <div class="stat-item-inline">
                            <i class="fas fa-car"></i>
                            <span>${company.total_vehicles || 0} vehicles</span>
                        </div>
                        ${company.daily_rate_from && company.daily_rate_to ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-day"></i>
                                <span class="company-rate-text">MWK ${formatNumber(company.daily_rate_from)} - ${formatNumber(company.daily_rate_to)}/day</span>
                            </div>
                        ` : company.daily_rate_from ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-day"></i>
                                <span class="company-rate-text">MWK ${formatNumber(company.daily_rate_from)}/day</span>
                            </div>
                        ` : ''}
                        ${company.weekly_rate_from && company.weekly_rate_to ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-week"></i>
                                <span class="company-rate-text">MWK ${formatNumber(company.weekly_rate_from)} - ${formatNumber(company.weekly_rate_to)}/week</span>
                            </div>
                        ` : company.weekly_rate_from ? `
                            <div class="stat-item-inline price-item">
                                <i class="fas fa-calendar-week"></i>
                                <span class="company-rate-text">MWK ${formatNumber(company.weekly_rate_from)}/week</span>
                            </div>
                        ` : ''}
                    </div>

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
}

function applyFilters() {
    const location = document.getElementById('locationFilter').value;
    const sort = document.getElementById('sortFilter').value;
    const vehicleType = document.getElementById('vehicleTypeFilter')?.value;
    const transmission = document.getElementById('transmissionFilter')?.value;
    const seats = document.getElementById('seatsFilter')?.value;
    const fuelType = document.getElementById('fuelTypeFilter')?.value;

    let filtered = [...companies];

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

    // Sort results
    if (sort === 'vehicles') {
        filtered.sort((a, b) => (b.total_vehicles || 0) - (a.total_vehicles || 0));
    } else if (sort === 'price') {
        filtered.sort((a, b) => (a.daily_rate_from || 999999) - (b.daily_rate_from || 999999));
    } else if (sort === 'featured') {
        filtered.sort((a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0));
    }

    renderCompanies(filtered);
    updateResultsCount(filtered.length);
}

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
        </div>
    `;
}

function clearFilters() {
    document.getElementById('locationFilter').value = '';
    document.getElementById('sortFilter').value = 'featured';
    const vehicleTypeFilter = document.getElementById('vehicleTypeFilter');
    if (vehicleTypeFilter) vehicleTypeFilter.value = '';
    const transmissionFilter = document.getElementById('transmissionFilter');
    if (transmissionFilter) transmissionFilter.value = '';
    const seatsFilter = document.getElementById('seatsFilter');
    if (seatsFilter) seatsFilter.value = '';
    const fuelTypeFilter = document.getElementById('fuelTypeFilter');
    if (fuelTypeFilter) fuelTypeFilter.value = '';

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
        console.log('Geolocation is not supported by your browser');
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            console.log('User location obtained:', userLocation);
            
            // Re-render companies with distance information if already loaded
            if (companies.length > 0) {
                geocodeAndRenderCompanies();
            }
        },
        function(error) {
            console.log('Error getting user location:', error.message);
        },
        {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 300000 // Cache for 5 minutes
        }
    );
}

// Geocode a company address to get coordinates
async function geocodeAddress(address, locationName) {
    // Validate parameters
    if (!address || !address.trim()) return null;
    
    // Create a full address string
    const fullAddress = `${address}, ${locationName || 'Malawi'}, Malawi`;
    
    // Check cache first
    if (geocodedCompanies.has(fullAddress)) {
        return geocodedCompanies.get(fullAddress);
    }
    
    // Check if Google Maps is available
    if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
        console.log('Google Maps Geocoder not available');
        return null;
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
    
    // Re-render companies with distance information
    renderCompanies(companies);
}
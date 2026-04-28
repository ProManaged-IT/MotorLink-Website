//const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.protocol === 'file:';

// Update GARAGES_CONFIG to use the global CONFIG object from config.js
const GARAGES_CONFIG = {
    // Fallback strings provided just in case config.js fails to load
    API_URL: (typeof CONFIG !== 'undefined' && CONFIG.API_URL) ? CONFIG.API_URL : '/api.php',
    BASE_URL: (typeof CONFIG !== 'undefined' && CONFIG.BASE_URL) ? CONFIG.BASE_URL : ''
};

// Constants
const MAX_DISTANCE_FOR_SORTING = 999999; // Used when garage has no distance info
const GARAGE_NEARBY_RADIUS_KM = 10;

// Global variables
let userLocation = null;
let googleMapsAvailable = false;
let currentGarages = [];
let googleGarages = [];
let geocodedGarages = new Map(); // Cache for geocoded garage addresses
let shouldGeocodeOnMapsReady = false; // Flag to geocode when Maps API loads
let nearbyGarageSearchActive = false;

// Pagination state
let garagesPerPage = 25;
let garagesCurrentPage = 1;

// Suppress Google Maps analytics errors (gen_204) that are blocked by ad blockers
// This doesn't affect Maps functionality, just analytics tracking
const originalError = window.onerror;
window.onerror = function(msg, url, line, col, error) {
    // Suppress gen_204 errors (Google Maps analytics blocked by ad blockers)
    if (msg && (msg.includes('gen_204') || msg.includes('ERR_BLOCKED_BY_CLIENT'))) {
        return true; // Suppress the error
    }
    // Call original error handler if it exists
    if (originalError) {
        return originalError.apply(this, arguments);
    }
    return false;
};

// Also catch fetch/network errors
window.addEventListener('unhandledrejection', function(event) {
    if (event.reason && event.reason.message && event.reason.message.includes('gen_204')) {
        event.preventDefault();
    }
});

// Define initMap globally BEFORE loading the API
window.initMap = function() {
    window.googleMapsReady = true;
    googleMapsAvailable = true;
    
    // If main script is already loaded, trigger the callback
    if (window.mainScriptLoaded && typeof window.onGoogleMapsReady === 'function') {
        window.onGoogleMapsReady();
    }
};

// Load Google Maps API dynamically with proper async loading
async function loadGoogleMapsAPI() {
    // Check if already loaded
    if (window.google) {
        return;
    }
    
    // Check if already loading
    if (window.googleMapsLoading) {
        return;
    }
    
    window.googleMapsLoading = true;
    
    let mapConfig;
    try {
        mapConfig = await window.getGoogleMapsConfig();
    } catch (error) {
        showSearchStatus('Failed to load map configuration.', 'error');
        window.googleMapsLoading = false;
        return;
    }

    if (!mapConfig || !mapConfig.apiKey) {
        showSearchStatus('Google Maps API key is not configured.', 'error');
        window.googleMapsLoading = false;
        return;
    }

    // Create the script element with proper async loading
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${mapConfig.apiKey}&libraries=places&callback=initMap&loading=async`;
    script.async = true;
    script.defer = true;
    
    script.onload = function() {
        window.googleMapsLoading = false;
    };
    
    script.onerror = function() {
        showSearchStatus('Failed to load Google Maps. Please check your connection.', 'error');
        window.googleMapsLoading = false;
    };
    
    document.head.appendChild(script);
}

// Google Maps callback
window.onGoogleMapsReady = function() {
    googleMapsAvailable = true;
    
    // If we have user location and need to geocode, do it now
    if (userLocation && shouldGeocodeOnMapsReady && currentGarages.length > 0) {
        geocodeAndUpdateGarageDistances().then(() => {
            // Re-display garages with updated distances
            displayGarages(currentGarages);
            updateResultsCount(currentGarages);
        }).catch((error) => {
            // Silently handle geocoding errors - garages still display without distances
            console.error('Geocoding failed:', error);
        });
        shouldGeocodeOnMapsReady = false;
    }
    
    // If we need to search nearby (from "Nearby" button), trigger it
    // This is different from the automatic geocoding above
    if (userLocation && window.shouldSearchNearby) {
        findNearbyGarages();
        window.shouldSearchNearby = false;
    }
};

document.addEventListener('DOMContentLoaded', function() {
    // Load Google Maps API first with proper loading pattern
    loadGoogleMapsAPI();
    
    // Initialize all components
    // Mobile menu handled by global js/mobile-menu.js
    loadDistricts();
    loadDynamicFilterOptions(); // Load dynamic filters first
    loadGarages();
    setupFilters();
    setupQuickDistrictLinks();
    setupEmergencyFeatures();
    setupNearbyButton();
    setupResultsBreakdownLinks();

    // Mobile hero search bar — syncs with desktop #garageSearchInput
    const mobileSearchInput = document.getElementById('mobileGarageSearch');
    const mobileSearchBtn   = document.getElementById('mobileSearchBtn');
    if (mobileSearchInput && mobileSearchBtn) {
        const doMobileSearch = () => {
            const mainInput = document.getElementById('garageSearchInput');
            if (mainInput) mainInput.value = mobileSearchInput.value.trim();
            loadGarages();
        };
        mobileSearchBtn.addEventListener('click', doMobileSearch);
        mobileSearchInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); doMobileSearch(); }
        });
        // Keep cleared when desktop search is cleared
        const mainInput = document.getElementById('garageSearchInput');
        if (mainInput) {
            mainInput.addEventListener('input', () => {
                if (!mainInput.value) mobileSearchInput.value = '';
            });
        }
    }
    
    // Automatically request user location on page load for distance calculation
    requestUserLocationForDistances();
    
    // Mark main script as loaded
    window.mainScriptLoaded = true;
});

// ===== DYNAMIC FILTER FUNCTIONS =====

/**
 * Load available car brands from database garages
 */
async function loadCarBrandsFromDatabase() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=garages`);
        const data = await response.json();
        
        if (data.success && data.garages && data.garages.length > 0) {
            const allBrands = new Set();
            
            // Extract car brands from specializes_in_cars field
            data.garages.forEach(garage => {
                if (garage.specializes_in_cars) {
                    try {
                        const brands = typeof garage.specializes_in_cars === 'string' 
                            ? JSON.parse(garage.specializes_in_cars) 
                            : garage.specializes_in_cars;
                        
                        if (Array.isArray(brands)) {
                            brands.forEach(brand => {
                                if (brand && brand.trim()) {
                                    allBrands.add(brand.trim());
                                }
                            });
                        }
                    } catch (e) {
                    }
                }
            });
            
            // Update car brand filter
            const carBrandFilter = document.getElementById('carBrandFilter');
            if (carBrandFilter) {
                // Clear existing options except the first one
                while (carBrandFilter.options.length > 1) {
                    carBrandFilter.remove(1);
                }
                
                // Add brands alphabetically
                const sortedBrands = Array.from(allBrands).sort();
                sortedBrands.forEach(brand => {
                    const option = document.createElement('option');
                    option.value = brand;
                    option.textContent = brand;
                    carBrandFilter.appendChild(option);
                });
                
            }
        } else {
        }
    } catch (error) {
    }
}

/**
 * Load available services from database garages
 */
async function loadServicesFromDatabase() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=garages`);
        const data = await response.json();
        
        if (data.success && data.garages && data.garages.length > 0) {
            const allServices = new Set();
            const allEmergencyServices = new Set();
            
            // Extract services from services and emergency_services fields
            data.garages.forEach(garage => {
                // Regular services
                if (garage.services) {
                    try {
                        const services = typeof garage.services === 'string' 
                            ? JSON.parse(garage.services) 
                            : garage.services;
                        
                        if (Array.isArray(services)) {
                            services.forEach(service => {
                                if (service && service.trim()) {
                                    allServices.add(service.trim());
                                }
                            });
                        }
                    } catch (e) {
                    }
                }
                
                // Emergency services
                if (garage.emergency_services) {
                    try {
                        const emergencyServices = typeof garage.emergency_services === 'string' 
                            ? JSON.parse(garage.emergency_services) 
                            : garage.emergency_services;
                        
                        if (Array.isArray(emergencyServices)) {
                            emergencyServices.forEach(service => {
                                if (service && service.trim()) {
                                    allEmergencyServices.add(service.trim());
                                }
                            });
                        }
                    } catch (e) {
                    }
                }
            });
            
            // Update service filter
            const serviceFilter = document.getElementById('serviceFilter');
            if (serviceFilter) {
                // Clear existing options except the first one
                while (serviceFilter.options.length > 1) {
                    serviceFilter.remove(1);
                }
                
                // Add services alphabetically
                const sortedServices = Array.from(allServices).sort();
                sortedServices.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service;
                    option.textContent = service;
                    serviceFilter.appendChild(option);
                });
                
            }
            
            // Update emergency service filter
            const emergencyFilter = document.getElementById('emergencyFilter');
            if (emergencyFilter) {
                // Clear existing options except the first one
                while (emergencyFilter.options.length > 1) {
                    emergencyFilter.remove(1);
                }
                
                // Add emergency services alphabetically
                const sortedEmergencyServices = Array.from(allEmergencyServices).sort();
                sortedEmergencyServices.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service;
                    option.textContent = service;
                    emergencyFilter.appendChild(option);
                });
                
            }
        } else {
        }
    } catch (error) {
    }
}

/**
 * Load dynamic filter options from database
 */
async function loadDynamicFilterOptions() {
    try {
        await Promise.all([
            loadCarBrandsFromDatabase(),
            loadServicesFromDatabase()
        ]);
    } catch (error) {
    }
}

// Mobile menu now handled by global js/mobile-menu.js

function setupNearbyButton() {
    const findNearbyBtn = document.getElementById('findNearbyBtn');
    if (findNearbyBtn) {
        findNearbyBtn.addEventListener('click', findNearbyGarages);
    }

    const nearbyGaragesBtn = document.getElementById('nearbyGaragesBtn');
    if (nearbyGaragesBtn) {
        nearbyGaragesBtn.addEventListener('click', findNearbyGarages);
    }
    
    // Setup mobile nearby FAB
    const mobileNearbyFab = document.getElementById('mobileNearbyFab');
    if (mobileNearbyFab) {
        mobileNearbyFab.addEventListener('click', findNearbyGarages);
    }
}

function getNearbyGarageButtons() {
    return [
        document.getElementById('findNearbyBtn'),
        document.getElementById('nearbyGaragesBtn')
    ].filter(Boolean);
}

function setNearbyGarageButtonsLoading(isLoading) {
    getNearbyGarageButtons().forEach((button) => {
        button.disabled = isLoading;
        button.innerHTML = isLoading
            ? '<i class="fas fa-spinner fa-spin"></i> Locating...'
            : '<i class="fas fa-location-arrow"></i> Nearby (10km)';
    });
}

function setupFilters() {
    const applyBtn = document.getElementById('applyFiltersBtn');
    const clearBtn = document.getElementById('clearFiltersBtn');

    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            loadGarages();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            clearFilters();
        });
    }

    // Auto-apply filters when dropdowns change (only on desktop, not mobile)
    // On mobile, filters should only apply when "Apply" button is clicked
    const isMobile = window.innerWidth <= 768;
    
    if (!isMobile) {
        const districtFilter = document.getElementById('districtFilter');
        const carBrandFilter = document.getElementById('carBrandFilter');
        const serviceFilter = document.getElementById('serviceFilter');
        const emergencyFilter = document.getElementById('emergencyFilter');
        const sortFilter = document.getElementById('sortFilter');
        
        if (districtFilter) {
            districtFilter.addEventListener('change', function() {
                // Don't auto-apply if syncing from mobile tray
                if (!window.syncingFromMobileTray) {
                    loadGarages();
                }
            });
        }
        if (carBrandFilter) {
            carBrandFilter.addEventListener('change', function() {
                if (!window.syncingFromMobileTray) {
                    loadGarages();
                }
            });
        }
        if (serviceFilter) {
            serviceFilter.addEventListener('change', function() {
                if (!window.syncingFromMobileTray) {
                    loadGarages();
                }
            });
        }
        if (emergencyFilter) {
            emergencyFilter.addEventListener('change', function() {
                if (!window.syncingFromMobileTray) {
                    loadGarages();
                }
            });
        }
        if (sortFilter) {
            sortFilter.addEventListener('change', function() {
                if (!window.syncingFromMobileTray) {
                    displayGarages(currentGarages);
                }
            });
        }

        // Live search — debounced so it doesn't fire on every keystroke
        const searchInput = document.getElementById('garageSearchInput');
        if (searchInput) {
            let searchDebounce;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(function() {
                    if (!window.syncingFromMobileTray) loadGarages();
                }, 350);
            });
        }
    }
}

function setupQuickDistrictLinks() {
    document.querySelectorAll('.district-tag').forEach(tag => {
        tag.addEventListener('click', function() {
            const district = this.getAttribute('data-district');
            const districtFilter = document.getElementById('districtFilter');
            if (districtFilter) {
                districtFilter.value = district;
                userLocation = null; // Clear location when selecting district
                hideLocationStatus();
                loadGarages();
            }
        });
    });
}

function setupResultsBreakdownLinks() {
    // Add click handlers to database and google count badges
    const databaseCount = document.getElementById('databaseCount');
    const googleCount = document.getElementById('googleCount');
    
    if (databaseCount) {
        databaseCount.addEventListener('click', function() {
            const motorlinkSection = document.getElementById('motorlink-garages-section');
            if (motorlinkSection) {
                motorlinkSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
    
    if (googleCount) {
        googleCount.addEventListener('click', function() {
            const googleSection = document.getElementById('google-garages-section');
            if (googleSection) {
                googleSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
}

function setupEmergencyFeatures() {
    const emergencyBtn = document.getElementById('emergencyBtn');
    const modal = document.getElementById('emergencyModal');
    const closeBtn = document.querySelector('.modal-close');
    const modalFindNearbyBtn = document.getElementById('modalFindNearbyBtn');
    
    if (emergencyBtn && modal) {
        emergencyBtn.addEventListener('click', function() {
            modal.style.display = 'block';
        });
        
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }
        
        if (modalFindNearbyBtn) {
            modalFindNearbyBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                findNearbyGarages();
            });
        }
        
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    }
}

/**
 * Automatically request user location for distance calculation
 * This runs on page load without showing any UI changes
 */
function requestUserLocationForDistances() {
    if (!navigator.geolocation) {
        return; // Silently fail if geolocation not supported
    }
    
    // Request location silently in the background
    navigator.geolocation.getCurrentPosition(
        async function(position) {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            
            // Wait for Google Maps to be ready
            if (!googleMapsAvailable) {
                // Set a flag to geocode when maps is ready
                shouldGeocodeOnMapsReady = true;
                return;
            }
            
            // Geocode addresses and update distances for current garages
            if (currentGarages.length > 0) {
                try {
                    await geocodeAndUpdateGarageDistances();
                    // Re-display garages with updated distances
                    displayGarages(currentGarages);
                    updateResultsCount(currentGarages);
                } catch (error) {
                    // Silently handle geocoding errors - garages still display without distances
                    console.error('Geocoding failed:', error);
                }
            }
        },
        function(error) {
            // Silently fail - user denied location or it's unavailable
            // This is fine, we just won't show distances
        },
        {
            enableHighAccuracy: false, // Use lower accuracy for background request
            timeout: 10000,
            maximumAge: 300000 // Cache for 5 minutes
        }
    );
}

function findNearbyGarages() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser');
        return;
    }
    
    nearbyGarageSearchActive = true;
    const mobileNearbyFab = document.getElementById('mobileNearbyFab');
    setNearbyGarageButtonsLoading(true);
    
    if (mobileNearbyFab) {
        mobileNearbyFab.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        mobileNearbyFab.disabled = true;
    }
    
    showSearchStatus('Getting your location...', 'info');
    
    navigator.geolocation.getCurrentPosition(
        async function(position) {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            
            showLocationStatus();
            setNearbyGarageButtonsLoading(false);

            const sortFilter = document.getElementById('sortFilter');
            if (sortFilter) sortFilter.value = 'distance';
            
            if (mobileNearbyFab) {
                mobileNearbyFab.innerHTML = '<i class="fas fa-location-arrow"></i><span class="fab-text">Nearby</span>';
                mobileNearbyFab.disabled = false;
            }
            
            showSearchStatus('Searching for nearby garages...', 'info');
            
            if (!googleMapsAvailable) {
                showSearchStatus('Google Maps is still loading. Please wait...', 'warning');
                window.shouldSearchNearby = true;
                return;
            }
            
            // Load both database garages and Google Maps garages
            try {
                await Promise.all([
                    loadDatabaseGarages(),
                    loadGoogleMapsGarages()
                ]);
                
                combineAndDisplayGarages();
                window.shouldSearchNearby = false;
                
            } catch (error) {
                showSearchStatus('Error searching for garages. Please try again.', 'error');
            }
            
        },
        function(error) {
            let errorMessage = 'Unable to get your location. ';
            
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMessage += 'Please enable location permissions in your browser settings.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMessage += 'Location information is unavailable.';
                    break;
                case error.TIMEOUT:
                    errorMessage += 'Location request timed out. Please try again.';
                    break;
                default:
                    errorMessage += 'Please ensure location services are enabled.';
            }
            
            showSearchStatus(errorMessage, 'error');
            if (findNearbyBtn) {
                findNearbyBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Find Garages Within 10km';
                findNearbyBtn.disabled = false;
            }
            if (mobileNearbyFab) {
                mobileNearbyFab.innerHTML = '<i class="fas fa-location-arrow"></i><span class="fab-text">Nearby</span>';
                mobileNearbyFab.disabled = false;
            }
            window.shouldSearchNearby = false;
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 60000
        }
    );
}

async function loadDatabaseGarages() {
    try {
        let url = `${CONFIG.API_URL}?action=garages`;
        const params = [];
        
        // Add current filters
        const search = document.getElementById('garageSearchInput')?.value?.trim() || '';
        const district = document.getElementById('districtFilter')?.value || '';
        const carBrand = document.getElementById('carBrandFilter')?.value || '';
        const service = document.getElementById('serviceFilter')?.value || '';
        const emergency = document.getElementById('emergencyFilter')?.value || '';
        
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        if (district) params.push(`district=${encodeURIComponent(district)}`);
        if (carBrand) params.push(`car_brand=${encodeURIComponent(carBrand)}`);
        if (service) params.push(`service=${encodeURIComponent(service)}`);
        if (emergency) params.push(`emergency=${encodeURIComponent(emergency)}`);
        
        if (params.length > 0) {
            url += '&' + params.join('&');
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.garages) {
            currentGarages = data.garages.map(garage => ({
                ...garage,
                source: 'database',
                // Parse JSON fields if they are strings
                services: typeof garage.services === 'string' ? JSON.parse(garage.services) : garage.services,
                emergency_services: typeof garage.emergency_services === 'string' ? JSON.parse(garage.emergency_services) : garage.emergency_services,
                specialization: typeof garage.specialization === 'string' ? JSON.parse(garage.specialization) : garage.specialization,
                specializes_in_cars: typeof garage.specializes_in_cars === 'string' ? JSON.parse(garage.specializes_in_cars) : garage.specializes_in_cars,
                distance: undefined // Will be calculated via geocoding if user location available and address exists
            }));
            
            // Geocode addresses and calculate distances if user location is available
            if (userLocation && googleMapsAvailable) {
                await geocodeAndUpdateGarageDistances();
            }
        } else {
            currentGarages = [];
        }
    } catch (error) {
        currentGarages = [];
    }
}

async function loadGoogleMapsGarages() {
    if (!userLocation || !googleMapsAvailable || !window.google) {
        googleGarages = [];
        return;
    }
    
    try {
        showSearchStatus('Searching Google Maps for nearby garages...', 'info');
        
        // Use the new Place API with minimal, supported parameters
        const { Place } = await google.maps.importLibrary("places");
        
        // Simple text query that should work with the new API
        // Enhanced to fetch contact information
        const request = {
            textQuery: `car repair garage near ${userLocation.lat},${userLocation.lng}`,
            maxResultCount: 20,
            fields: ['displayName', 'formattedAddress', 'rating', 'userRatingCount', 'location', 'internationalPhoneNumber', 'websiteURI', 'googleMapsURI']
        };
        
        
        // Use the new Place.searchByText method
        const { places } = await Place.searchByText(request);
        
        if (places && places.length > 0) {
            
            googleGarages = await Promise.all(
                places.map(async (place) => {
                    try {
                        // Fetch additional details for each place including contact info
                        await place.fetchFields({
                            fields: ['displayName', 'formattedAddress', 'rating', 'userRatingCount', 'location', 'internationalPhoneNumber', 'websiteURI', 'googleMapsURI']
                        });
                        
                        let placeLat = userLocation.lat;
                        let placeLng = userLocation.lng;
                        
                        // Safely get location coordinates
                        if (place.location) {
                            try {
                                placeLat = place.location.lat();
                                placeLng = place.location.lng();
                            } catch (locError) {
                            }
                        }
                        
                        const distance = calculateDistance(
                            userLocation.lat,
                            userLocation.lng,
                            placeLat,
                            placeLng
                        );
                        
                        // Filter out places outside the explicit nearby radius
                        if (distance > GARAGE_NEARBY_RADIUS_KM) {
                            return null;
                        }
                        
                        return {
                            id: 'google_' + place.id,
                            name: place.displayName || 'Unknown Garage',
                            address: place.formattedAddress || '',
                            phone: place.internationalPhoneNumber || null,
                            website: place.websiteURI || null,
                            google_maps_url: place.googleMapsURI || null,
                            rating: place.rating || 0,
                            total_reviews: place.userRatingCount || 0,
                            source: 'google',
                            distance: distance,
                            place_id: place.id,
                            location: {
                                lat: placeLat,
                                lng: placeLng
                            }
                        };
                    } catch (placeError) {
                        return null;
                    }
                })
            );
            
            // Filter out any null results from failed place processing or distance filtering
            googleGarages = googleGarages.filter(garage => garage !== null);
            
            
        } else {
            googleGarages = [];
        }
        
    } catch (error) {
        // Try the legacy API as fallback
        await loadGoogleMapsGaragesLegacy();
    }
}

// Legacy API fallback using nearbySearch
async function loadGoogleMapsGaragesLegacy() {
    try {
        
        const service = new google.maps.places.PlacesService(document.createElement('div'));
        const request = {
            location: new google.maps.LatLng(userLocation.lat, userLocation.lng),
            radius: GARAGE_NEARBY_RADIUS_KM * 1000,
            type: 'car_repair',
            keyword: 'auto repair garage mechanic'
        };
        
        return new Promise((resolve) => {
            service.nearbySearch(request, (results, status) => {
                if (status === google.maps.places.PlacesServiceStatus.OK && results) {
                    googleGarages = results.map(place => ({
                        id: 'google_' + place.place_id,
                        name: place.name,
                        address: place.vicinity || '',
                        rating: place.rating || 0,
                        total_reviews: place.user_ratings_total || 0,
                        source: 'google',
                        distance: calculateDistance(
                            userLocation.lat,
                            userLocation.lng,
                            place.geometry.location.lat(),
                            place.geometry.location.lng()
                        ),
                        place_id: place.place_id,
                        location: {
                            lat: place.geometry.location.lat(),
                            lng: place.geometry.location.lng()
                        }
                    }));
                } else {
                    googleGarages = [];
                }
                resolve();
            });
        });
        
    } catch (error) {
        googleGarages = [];
    }
}

function combineAndDisplayGarages() {
    // Remove Google garages that are already in our database (by name similarity)
    const databaseGarageNames = currentGarages.map(g => g.name.toLowerCase());
    const uniqueGoogleGarages = googleGarages.filter(googleGarage => {
        const googleName = googleGarage.name.toLowerCase();
        return !databaseGarageNames.some(dbName => 
            dbName.includes(googleName) || googleName.includes(dbName)
        );
    });
    
    // Combine garages, prioritizing database garages
    let allGarages = [...currentGarages, ...uniqueGoogleGarages];

    if (nearbyGarageSearchActive) {
        allGarages = allGarages
            .filter(garage => garage.distance != null && garage.distance <= GARAGE_NEARBY_RADIUS_KM)
            .sort((a, b) => (a.distance ?? MAX_DISTANCE_FOR_SORTING) - (b.distance ?? MAX_DISTANCE_FOR_SORTING));
    }
    
    displayGarages(allGarages);
    updateActiveFilters();
    updateResultsCount(allGarages);
    
    // Check if sorting by distance
    const sortFilter = document.getElementById('sortFilter');
    const isSortedByDistance = sortFilter && sortFilter.value === 'distance';
    const statusMessage = isSortedByDistance 
        ? `Found ${allGarages.length} garages within ${GARAGE_NEARBY_RADIUS_KM}km (${currentGarages.length} MotorLink checked, ${uniqueGoogleGarages.length} nearby map results) - Sorted by distance`
        : `Found ${allGarages.length} garages (${currentGarages.length} from MotorLink, ${uniqueGoogleGarages.length} from nearby)`;
    
    showSearchStatus(statusMessage, 'success');
}

function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Earth's radius in kilometers
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = 
        Math.sin(dLat/2) * Math.sin(dLat/2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
        Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    const distance = R * c;
    return Math.round(distance * 10) / 10; // Round to 1 decimal place
}

// Geocode a garage address to get coordinates
async function geocodeGarageAddress(address, locationName) {
    // Validate parameters
    if (!address || !address.trim()) return null;
    
    // Create a full address string
    const countryName = (window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : '';
    const fullAddress = [address, locationName, countryName].map((part) => String(part || '').trim()).filter(Boolean).join(', ');
    
    // Check cache first
    if (geocodedGarages.has(fullAddress)) {
        return geocodedGarages.get(fullAddress);
    }
    
    // Check if Google Maps is available
    if (!googleMapsAvailable || typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
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
                    geocodedGarages.set(fullAddress, location);
                    resolve(location);
                } else {
                    resolve(null);
                }
            });
        });
    } catch (error) {
        console.error('Error geocoding garage address:', error);
        return null;
    }
}

// Geocode all database garages and update distances
async function geocodeAndUpdateGarageDistances() {
    if (!userLocation || currentGarages.length === 0) {
        return;
    }
    
    // Geocode addresses for garages that have addresses
    const geocodePromises = currentGarages
        .filter(garage => garage.source === 'database' && garage.address)
        .map(async (garage) => {
            const coords = await geocodeGarageAddress(garage.address, garage.location?.name || garage.district || '');
            if (coords) {
                garage.latitude = coords.lat;
                garage.longitude = coords.lng;
                garage.distance = calculateDistance(
                    userLocation.lat,
                    userLocation.lng,
                    coords.lat,
                    coords.lng
                );
            }
        });
    
    await Promise.all(geocodePromises);
}

function showLocationStatus() {
    const locationStatus = document.getElementById('locationStatus');
    const locationStatusText = document.getElementById('locationStatusText');
    
    if (locationStatus && locationStatusText) {
        locationStatusText.textContent = 'Searching within 10km of your location';
        locationStatus.style.display = 'block';
    }
}

function hideLocationStatus() {
    const locationStatus = document.getElementById('locationStatus');
    if (locationStatus) {
        locationStatus.style.display = 'none';
    }
}

function showSearchStatus(message, type = 'info') {
    const searchStatus = document.getElementById('searchStatus');
    const searchStatusText = document.getElementById('searchStatusText');

    if (searchStatus && searchStatusText) {
        // Add spinner for loading/info messages
        const spinner = (type === 'info') ? '<i class="fas fa-spinner fa-pulse" style="margin-right: 8px;"></i>' : '<i class="fas fa-info-circle" style="margin-right: 8px;"></i>';

        searchStatusText.innerHTML = spinner + message;
        searchStatus.className = `search-status search-status-${type}`;
        searchStatus.style.display = 'block';

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(() => {
                searchStatus.style.display = 'none';
            }, 5000);
        }
    }
}

function hideSearchStatus() {
    const searchStatus = document.getElementById('searchStatus');
    if (searchStatus) {
        searchStatus.style.display = 'none';
    }
}

function clearFilters() {
    const districtFilter = document.getElementById('districtFilter');
    const carBrandFilter = document.getElementById('carBrandFilter');
    const serviceFilter = document.getElementById('serviceFilter');
    const emergencyFilter = document.getElementById('emergencyFilter');
    const sortFilter = document.getElementById('sortFilter');
    
    if (districtFilter) districtFilter.value = '';
    if (carBrandFilter) carBrandFilter.value = '';
    if (serviceFilter) serviceFilter.value = '';
    if (emergencyFilter) emergencyFilter.value = '';
    if (sortFilter) sortFilter.value = 'featured';
    
    userLocation = null;
    nearbyGarageSearchActive = false;
    hideLocationStatus();
    hideSearchStatus();
    loadGarages();
}

async function loadDistricts() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=locations`);
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('districtFilter');
            if (select) {
                // Clear existing options except the first one
                while (select.options.length > 1) {
                    select.remove(1);
                }
                
                // Create a set to store unique districts
                const uniqueDistricts = new Set();
                
                data.locations.forEach(location => {
                    // Only add if district exists and is not already added
                    if (location.district && !uniqueDistricts.has(location.district)) {
                        uniqueDistricts.add(location.district);
                        const option = document.createElement('option');
                        option.value = location.district;
                        option.textContent = location.district;
                        select.appendChild(option);
                    }
                });
            }
        }
    } catch (error) {
    }
}

// Make loadGarages globally accessible for mobile filter tray
window.loadGarages = async function loadGarages() {
    const grid = document.getElementById('garagesGrid');
    if (grid) {
        grid.innerHTML = '<div class="garage-loading"><i class="fas fa-spinner fa-spin"></i><div>Loading garages...</div></div>';
    }

    hideSearchStatus();
    garagesCurrentPage = 1; // Reset to first page on new load/filter

    try {
        await loadDatabaseGarages();

        currentGarages = currentGarages.map(garage => ({
            ...garage,
            source: 'database'
        }));

        displayGarages(currentGarages);
        updateActiveFilters();
        updateResultsCount(currentGarages);

    } catch (error) {
        showError('Failed to load garages. Please check your connection.');
    }
}

function updateHeroStats(garages) {
    // Update garages page stats
    const totalGaragesEl = document.getElementById('totalGarages');
    const certifiedEl    = document.getElementById('certifiedGarages');
    const emergencyEl    = document.getElementById('emergencyGarages');
    const districtsEl    = document.getElementById('districtsCount');

    if (totalGaragesEl) {
        totalGaragesEl.textContent = garages.length + '+';
    }

    let certifiedCount = 0;
    if (certifiedEl) {
        // Garages with a logo, certified, or verified flag
        certifiedCount = garages.filter(g => g.logo_url || g.certified || g.verified).length;
        certifiedEl.textContent = certifiedCount + '+';
    }

    let emergencyCount = 0;
    if (emergencyEl) {
        const emergency = garages.filter(g => {
            // emergency_services comes as a pre-parsed array from the API
            const hasEmergency = Array.isArray(g.emergency_services) && g.emergency_services.length > 0;
            return hasEmergency || !!g.recovery_number;
        }).length;
        // Fall back to phone-holding garages so the stat is never stuck at 0
        const withPhone = garages.filter(g => g.phone || g.recovery_number).length;
        emergencyCount = emergency > 0 ? emergency : withPhone;
        emergencyEl.textContent = emergencyCount + '+';
    }

    const districts = new Set();
    garages.forEach(g => {
        if (g.location && g.location.district) districts.add(g.location.district);
    });
    if (districtsEl) districtsEl.textContent = districts.size;

    // Mobile stats strip (garages.html — visible on mobile/tablet only)
    const gmsStrip     = document.getElementById('garagesMobileStats');
    const gmsTotal     = document.getElementById('gmsTotal');
    const gmsCertified = document.getElementById('gmsCertified');
    const gmsEmergency = document.getElementById('gmsEmergency');
    const gmsDistricts = document.getElementById('gmsDistricts');
    if (gmsTotal)     gmsTotal.textContent     = garages.length + '+';
    if (gmsCertified) gmsCertified.textContent = certifiedCount + '+';
    if (gmsEmergency) gmsEmergency.textContent = emergencyCount + '+';
    if (gmsDistricts) gmsDistricts.textContent = districts.size;
    if (gmsStrip)     gmsStrip.classList.toggle('ch-ms-loaded', garages.length > 0);
}

function displayGarages(garages) {
    const grid = document.getElementById('garagesGrid');
    if (!grid) return;
    
    // Update hero stats
    updateHeroStats(garages);
    
    if (!garages || garages.length === 0) {
        grid.innerHTML = `
            <div class="garage-no-results">
                <i class="fas fa-tools"></i>
                <h3>No garages found</h3>
                <p>Try adjusting your search criteria or use the "Find Garages Within 10km" button</p>
                <button class="btn btn-primary" onclick="findNearbyGarages()">
                    <i class="fas fa-map-marker-alt"></i> Find Nearby Garages
                </button>
            </div>
        `;
        return;
    }
    
    // Update sort filter UI if needed (before sorting)
    updateSortFilterUI(garages);
    
    // Separate database and Google garages
    const databaseGarages = garages.filter(garage => garage.source === 'database');
    const googleGarages = garages.filter(garage => garage.source === 'google');
    
    const sortedDatabaseGarages = sortGarages(databaseGarages);
    const sortedGoogleGarages = sortGarages(googleGarages);
    
    let html = '';
    
    // Paginate MotorLink garages
    const totalDb = sortedDatabaseGarages.length;
    const pagedDb = garagesPerPage === 0
        ? sortedDatabaseGarages
        : sortedDatabaseGarages.slice((garagesCurrentPage - 1) * garagesPerPage, garagesCurrentPage * garagesPerPage);

    // Display MotorLink garages first with a section header
    if (sortedDatabaseGarages.length > 0) {
        html += `
            <div class="garage-section" id="motorlink-garages-section">
                <div class="garage-section-header">
                    <h2 class="garage-section-title">
                        <i class="fas fa-database"></i> 
                        MotorLink Garages
                        <span class="garage-section-count">${sortedDatabaseGarages.length} garages</span>
                    </h2>
                    <p class="garage-section-description">Trusted garages from our verified database with detailed service information</p>
                </div>
                <div class="garage-section-grid">
                    ${pagedDb.map(garage => createGarageCard(garage)).join('')}
                </div>
                ${buildGaragesPaginationHTML(totalDb, garagesCurrentPage, garagesPerPage)}
            </div>
        `;
    }
    
    // Display Google garages with a separate section
    if (sortedGoogleGarages.length > 0) {
        html += `
            <div class="garage-section google-section" id="google-garages-section">
                <div class="garage-section-header">
                    <h2 class="garage-section-title">
                        <i class="fab fa-google"></i> 
                        Nearby Garages from Google
                        <span class="garage-section-count">${sortedGoogleGarages.length} nearby</span>
                    </h2>
                    <p class="garage-section-description">Additional garages found near your location. Contact information may be limited.</p>
                </div>
                <div class="garage-section-grid">
                    ${sortedGoogleGarages.map(garage => createGarageCard(garage)).join('')}
                </div>
            </div>
        `;
    }
    
    grid.innerHTML = html;
    
    // Add event listeners for clickable address rows
    document.querySelectorAll('.garage-address-link').forEach(addressRow => {
        addressRow.addEventListener('click', function(e) {
            e.preventDefault();
            const address = this.getAttribute('data-address');
            const lat = this.getAttribute('data-lat');
            const lng = this.getAttribute('data-lng');
            openGoogleMaps(address, lat, lng);
        });
    });
    
    // Add event listeners for emergency calls (if any still exist in the info rows)
    document.querySelectorAll('.emergency-call-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const number = this.getAttribute('data-number');
            if (number && confirm('Call emergency recovery number: ' + number + '?')) {
                window.location.href = 'tel:' + number;
            }
        });
    });
}

/**
 * Build pagination HTML for the MotorLink garages section
 */
function buildGaragesPaginationHTML(total, page, perPage) {
    if (total === 0) return '';
    const effPer = perPage === 0 ? total : perPage;
    const totalPages = Math.ceil(total / effPer);
    const start = (page - 1) * effPer + 1;
    const end   = Math.min(page * effPer, total);
    return `<div class="ml-pagination">
        <span class="ml-pag-info">Showing ${start}–${end} of ${total}</span>
        <div class="ml-pag-controls">
            <button class="ml-pag-btn" onclick="window.setGaragesPage(${page - 1},${perPage})" ${page <= 1 ? 'disabled' : ''}>&#8249; Prev</button>
            <span class="ml-pag-pages">Page ${page} of ${totalPages}</span>
            <button class="ml-pag-btn" onclick="window.setGaragesPage(${page + 1},${perPage})" ${page >= totalPages ? 'disabled' : ''}>Next &#8250;</button>
        </div>
        <label class="ml-pag-perpage">Show:&nbsp;<select class="ml-pag-select" onchange="window.setGaragesPage(1,parseInt(this.value))">
            <option value="25" ${perPage===25?'selected':''}>25</option>
            <option value="50" ${perPage===50?'selected':''}>50</option>
            <option value="100" ${perPage===100?'selected':''}>100</option>
            <option value="200" ${perPage===200?'selected':''}>200</option>
            <option value="250" ${perPage===250?'selected':''}>250</option>
            <option value="0" ${perPage===0?'selected':''}>All</option>
        </select></label>
    </div>`;
}

window.setGaragesPage = function(page, perPage) {
    const totalDb = currentGarages.filter(g => g.source === 'database').length;
    const effPer = perPage === 0 ? totalDb : perPage;
    const totalPages = Math.ceil(totalDb / effPer) || 1;
    garagesCurrentPage = Math.max(1, Math.min(page, totalPages));
    garagesPerPage = perPage;
    displayGarages(currentGarages);
    const section = document.getElementById('motorlink-garages-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

/**
 * Update the sort filter dropdown UI based on available data
 * Automatically switches to distance sorting when distances are available
 */
function updateSortFilterUI(garages) {
    const sortFilter = document.getElementById('sortFilter');
    if (!sortFilter) return;

    if (sortFilter.value === 'distance' && !userLocation) {
        sortFilter.value = 'featured';
    }
}

function sortGarages(garages) {
    const sortFilter = document.getElementById('sortFilter');
    const sortValue = sortFilter ? sortFilter.value : 'featured';
    
    switch(sortValue) {
        case 'featured':
            return garages.sort((a, b) => {
                if (a.featured && !b.featured) return -1;
                if (!a.featured && b.featured) return 1;
                return (a.name || '').localeCompare(b.name || '');
            });
        case 'distance':
            return garages.sort((a, b) => {
                // Sort by distance, but keep garages without distance at the end
                const distA = a.distance ?? MAX_DISTANCE_FOR_SORTING;
                const distB = b.distance ?? MAX_DISTANCE_FOR_SORTING;
                return distA - distB;
            });
        case 'verified':
            return garages.sort((a, b) => {
                if (a.verified && !b.verified) return -1;
                if (!a.verified && b.verified) return 1;
                return (a.name || '').localeCompare(b.name || '');
            });
        case 'experience':
            return garages.sort((a, b) => (b.years_experience || 0) - (a.years_experience || 0));
        case 'name':
            return garages.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        default:
            return garages.sort((a, b) => {
                if (a.featured && !b.featured) return -1;
                if (!a.featured && b.featured) return 1;
                return (a.name || '').localeCompare(b.name || '');
            });
    }
}

function createGarageCard(garage) {
    const isFromGoogle = garage.source === 'google';
    const locationData = garage.location || garage;

    // Parse JSON fields only if they exist
    const services = isFromGoogle ? [] : (garage.services ? parseJsonArray(garage.services) : []);
    const emergencyServices = isFromGoogle ? [] : (garage.emergency_services ? parseJsonArray(garage.emergency_services) : []);
    const carBrands = isFromGoogle ? [] : (garage.specializes_in_cars ? parseJsonArray(garage.specializes_in_cars) : []);
    const specializations = isFromGoogle ? [] : (garage.specialization ? parseJsonArray(garage.specialization) : []);

    // Parse operating hours
    const operatingHours = !isFromGoogle && garage.operating_hours ? garage.operating_hours : null;
    const { isOpen, todayHours } = parseOperatingHours(operatingHours);

    // Check what data exists in database
    const hasYearsExperience = garage.years_experience && garage.years_experience > 0;
    const hasDistance = garage.distance != null;
    const hasRecoveryNumber = garage.recovery_number && garage.recovery_number.trim() !== '';
    const hasWhatsApp = garage.whatsapp && garage.whatsapp.trim() !== '';
    const hasPhone = garage.phone && garage.phone.trim() !== '';
    const hasEmail = garage.email && garage.email.trim() !== '';
    const hasWebsite = garage.website && garage.website.trim() !== '';
    const hasAddress = garage.address && garage.address.trim() !== '';
    const hasDescription = garage.description && garage.description.trim() !== '';
    const hasOperatingHours = operatingHours != null;
    
    // Check badges
    const isVerified = garage.verified == 1;
    const isCertified = garage.certified == 1;
    const isFeatured = garage.featured == 1;
    
    // Social media
    const hasFacebook = garage.facebook_url && garage.facebook_url.trim() !== '';
    const hasInstagram = garage.instagram_url && garage.instagram_url.trim() !== '';
    const hasTwitter = garage.twitter_url && garage.twitter_url.trim() !== '';
    const hasLinkedIn = garage.linkedin_url && garage.linkedin_url.trim() !== '';
    const hasSocialMedia = hasFacebook || hasInstagram || hasTwitter || hasLinkedIn;
    
    // Location data
    const displayDistrict = locationData.district || garage.district || null;
    const fullAddress = garage.address || (isFromGoogle ? garage.vicinity : null);

    // Calculate distance info (same logic as car-hire.js) — clickable directions link
    let distanceInfo = '';
    if (hasDistance) {
        let mapsUrl;
        if (userLocation && garage.latitude && garage.longitude) {
            mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${userLocation.lat},${userLocation.lng}&destination=${garage.latitude},${garage.longitude}&travelmode=driving`;
        } else if (userLocation) {
            const q = encodeURIComponent(`${garage.name || ''} ${garage.address || garage.vicinity || ''}`.trim());
            mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${userLocation.lat},${userLocation.lng}&destination=${q}&travelmode=driving`;
        } else {
            const q = encodeURIComponent(`${garage.name || ''} ${garage.address || garage.vicinity || ''}`.trim());
            mapsUrl = `https://www.google.com/maps/search/?api=1&query=${q}`;
        }
        distanceInfo = `<a href="${mapsUrl}" target="_blank" rel="noopener noreferrer" class="loc-chip distance-info clickable-chip" onclick="event.stopPropagation();" title="Get directions to ${escapeHtml(garage.name || '')}"><i class="fas fa-location-arrow"></i> ${garage.distance.toFixed(1)} km away</a>`;
    }

    // Start building card HTML
    let html = `<div class="garage-service-card ${isFromGoogle ? 'google-garage' : ''} ${isFeatured ? 'featured' : ''}" data-garage-id="${garage.id}">`;
    
    // === HEADER SECTION ===
    html += `<div class="garage-card-header">`;
    
    // Left side: Logo (if available) + Name and meta info
    html += `<div class="garage-header-left">`;
    html += `<div class="garage-logo-name-row">`;
    html += `<div class="garage-card-logo card-logo-dp"${garage.logo_url ? ` style="background-image:url('${garage.logo_url}')"` : ''}>`;
    if (!garage.logo_url) {
        html += `<i class="fas fa-wrench"></i>`;
    }
    html += `</div>`;
    html += `<h3 class="garage-service-name">${escapeHtml(garage.name)}</h3>`;
    // Avg rating badge (populated from DB)
    if (!isFromGoogle && garage.reviews_enabled !== false && garage.avg_rating && parseFloat(garage.avg_rating) > 0) {
        const avg = parseFloat(garage.avg_rating).toFixed(1);
        const cnt = parseInt(garage.review_count || 0);
        html += `<span class="rv-badge"><i class="fas fa-star"></i> ${avg}${cnt > 0 ? `<span class="rv-badge-count">(${cnt})</span>` : ''}</span>`;
    }
    html += `</div>`;

    // Location meta — only if there's data to show
    if (displayDistrict || hasDistance) {
        html += `<div class="loc-panel"><div class="loc-chips">`;
        if (displayDistrict) {
            html += `<span class="loc-chip"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(displayDistrict)}</span>`;
        }
        if (hasDistance) {
            html += distanceInfo;
        }
        html += `</div></div>`;
    }
    html += `</div>`; // Close left side
    
    // Right side: Status badges (absolutely positioned)
    const badges = [];
    if (isFeatured) {
        badges.push(`<span class="garage-badge featured"><i class="fas fa-star"></i>Featured</span>`);
    }
    if (isCertified) {
        badges.push(`<span class="garage-badge certified"><i class="fas fa-certificate"></i>Certified</span>`);
    }
    if (isVerified) {
        badges.push(`<span class="garage-badge verified"><i class="fas fa-check-circle"></i>Verified</span>`);
    }

    if (badges.length > 0) {
        html += `<div class="garage-status-badges">${badges.join('')}</div>`;
    }
    
    html += `</div>`; // Close header
    
    // === BODY SECTION ===
    html += `<div class="garage-card-body">`;
    
    // Operating status — only shown when known
    if (isOpen !== null) {
        html += `<div class="garage-status-indicator ${isOpen ? 'open' : 'closed'}">
            <i class="fas fa-circle"></i>
            ${isOpen ? 'Open Now' : 'Closed Now'}
        </div>`;
    }

    const buildGarageInfoRow = (options) => {
        const {
            hasData,
            icon,
            label,
            value,
            rowClass = '',
            clickable = false,
            dataAddress = '',
            extraAttrs = '',
            title = ''
        } = options;

        if (!hasData) return '';

        const classes = ['garage-info-row'];
        if (rowClass) classes.push(rowClass);
        if (clickable) classes.push('clickable');

        const safeDataAddress = dataAddress ? `data-address="${escapeHtml(dataAddress)}"` : '';
        const safeTitle = title ? `title="${escapeHtml(title)}"` : '';

        return `<div class="${classes.join(' ')}" ${safeDataAddress} ${extraAttrs} ${safeTitle}>
            <div class="icon"><i class="${icon}"></i></div>
            <div class="content">
                <span class="label">${label}</span>
                <span class="value">${value}</span>
            </div>
        </div>`;
    };
    
    // Description
    if (hasDescription) {
        html += `<div class="garage-description">${escapeHtml(garage.description)}</div>`;
    }
    
    // Fixed info-row slots keep rows perfectly aligned across cards.
    html += buildGarageInfoRow({
        hasData: !!todayHours,
        icon: 'fas fa-clock',
        label: 'Hours Today',
        value: escapeHtml(todayHours || '')
    });

    html += buildGarageInfoRow({
        hasData: hasPhone,
        icon: 'fas fa-phone',
        label: 'Phone',
        value: hasPhone ? `<a href="tel:${garage.phone}">${garage.phone}</a>` : ''
    });

    html += buildGarageInfoRow({
        hasData: hasEmail && !isFromGoogle,
        icon: 'fas fa-envelope',
        label: 'Email',
        value: hasEmail && !isFromGoogle ? `<a href="mailto:${garage.email}">${garage.email}</a>` : ''
    });

    html += buildGarageInfoRow({
        hasData: hasRecoveryNumber,
        icon: 'fas fa-ambulance',
        label: 'Emergency Hotline',
        value: hasRecoveryNumber ? `<a href="tel:${garage.recovery_number}">${garage.recovery_number}</a>` : '',
        rowClass: 'emergency'
    });

    if (hasWebsite) {
        const displayUrl = garage.website.replace(/^https?:\/\/(www\.)?/, '');
        html += buildGarageInfoRow({
            hasData: true,
            icon: 'fas fa-globe',
            label: 'Website',
            value: `<a href="${garage.website}" target="_blank" rel="noopener">${displayUrl}</a>`
        });
    }

    const addressClickHandler = isFromGoogle && garage.location
        ? `data-lat="${garage.location.lat}" data-lng="${garage.location.lng}"`
        : '';
    html += buildGarageInfoRow({
        hasData: hasAddress,
        icon: 'fas fa-map-marker-alt',
        label: 'Address',
        value: hasAddress ? `<span class="address-text">${escapeHtml(garage.address)}</span><i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-left: 4px; opacity: 0.7; flex-shrink: 0;"></i>` : '',
        rowClass: hasAddress ? 'garage-address-link' : '',
        clickable: hasAddress,
        dataAddress: fullAddress || '',
        extraAttrs: addressClickHandler,
        title: hasAddress ? 'Click to open in Google Maps' : ''
    });

    html += buildGarageInfoRow({
        hasData: hasYearsExperience,
        icon: 'fas fa-award',
        label: 'Experience',
        value: hasYearsExperience ? `${garage.years_experience} yr${garage.years_experience > 1 ? 's' : ''}` : ''
    });
    
    // Car brands specialization
    if (carBrands.length > 0) {
        html += `<div class="garage-specializations">
            <div class="garage-specializations-title"><i class="fas fa-car"></i> Specializes In</div>
            <div class="garage-specializations-list">`;
        carBrands.forEach(brand => {
            html += `<span class="garage-spec-tag">${escapeHtml(brand)}</span>`;
        });
        html += `</div></div>`;
    }
    
    // Specializations
    if (specializations.length > 0) {
        html += `<div class="garage-specializations">
            <div class="garage-specializations-title"><i class="fas fa-wrench"></i> Specializations</div>
            <div class="garage-specializations-list">`;
        specializations.forEach(spec => {
            html += `<span class="garage-spec-tag">${escapeHtml(spec)}</span>`;
        });
        html += `</div></div>`;
    }
    
    // Services
    if (services.length > 0) {
        html += `<div class="garage-services-section">
            <div class="garage-services-title"><i class="fas fa-tools"></i> Services Offered</div>
            <div class="garage-services-grid">`;
        services.forEach(service => {
            html += `<div class="garage-service-item"><i class="fas fa-check"></i>${escapeHtml(service)}</div>`;
        });
        html += `</div></div>`;
    }
    
    // Social media
    if (hasSocialMedia) {
        html += `<div class="garage-social-section">
            <div class="garage-social-title">Follow Us</div>
            <div class="garage-social-links">`;
        if (hasFacebook) {
            html += `<a href="${garage.facebook_url}" target="_blank" rel="noopener" class="garage-social-link facebook"><i class="fab fa-facebook-f"></i></a>`;
        }
        if (hasInstagram) {
            html += `<a href="${garage.instagram_url}" target="_blank" rel="noopener" class="garage-social-link instagram"><i class="fab fa-instagram"></i></a>`;
        }
        if (hasTwitter) {
            html += `<a href="${garage.twitter_url}" target="_blank" rel="noopener" class="garage-social-link twitter"><i class="fab fa-twitter"></i></a>`;
        }
        if (hasLinkedIn) {
            html += `<a href="${garage.linkedin_url}" target="_blank" rel="noopener" class="garage-social-link linkedin"><i class="fab fa-linkedin-in"></i></a>`;
        }
        html += `</div></div>`;
    }
    
    html += `</div>`; // Close body
    
    // Reviews action (DB-only garages)
    if (!isFromGoogle && garage.reviews_enabled !== false) {
        html += `<div class="garage-card-reviews-row">
            <button class="rv-badge-btn" onclick="event.stopPropagation(); openGarageReviews(${garage.id}, '${escapeHtml(garage.name).replace(/'/g, "\\'")}')">
                <i class="fas fa-star"></i> ${garage.avg_rating && parseFloat(garage.avg_rating) > 0 ? 'Reviews & Ratings' : 'Write a Review'}
            </button>
        </div>`;
    }

    html += `</div>`; // Close card
    return html;
}

// Helper function to parse operating hours from database text format
function parseOperatingHours(operatingHours) {
    if (!operatingHours) return { isOpen: null, todayHours: null };

    try {
        const now = new Date();
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const today = days[now.getDay()];

        // Parse simple text format like "Mon-Fri: 7:30 AM - 6:00 PM\nSat: 8:00 AM - 4:00 PM\nSun: Closed"
        const lines = operatingHours.split('\n');
        let todayHours = null;
        let isOpen = null;

        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;

            // Check if line contains today
            const dayPattern = new RegExp(`(${today.substring(0, 3)}|${today})`, 'i');

            if (dayPattern.test(trimmed)) {
                // Extract hours part
                const parts = trimmed.split(':');
                if (parts.length > 1) {
                    todayHours = parts.slice(1).join(':').trim();

                    // Check if closed
                    if (/closed/i.test(todayHours)) {
                        isOpen = false;
                        todayHours = 'Closed today';
                    } else if (/24\/7|always open|emergency only/i.test(todayHours)) {
                        isOpen = true;
                        // Keep todayHours as is (e.g., "24/7" or "Emergency Only")
                    } else {
                        isOpen = true; // Assume open if hours are shown
                    }
                }
                break;
            }
        }

        // Check Mon-Fri pattern
        if (!todayHours && /Mon-Fri|Monday-Friday/i.test(operatingHours)) {
            const dayNum = now.getDay();
            if (dayNum >= 1 && dayNum <= 5) {
                const match = operatingHours.match(/(Mon-Fri|Monday-Friday)[:\s]+([^\n]+)/i);
                if (match) {
                    todayHours = match[2].trim();
                    isOpen = !/closed/i.test(todayHours);
                }
            }
        }

        return { isOpen, todayHours };
    } catch (e) {
        return { isOpen: null, todayHours: null };
    }
}

function openGoogleMaps(address, lat, lng) {
    let mapsUrl;
    
    if (lat && lng) {
        // Use coordinates for more accurate directions
        mapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
    } else if (address) {
        // Use address as fallback
        const encodedAddress = encodeURIComponent(address);
        mapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`;
    } else {
        return;
    }
    
    window.open(mapsUrl, '_blank');
}

function openGoogleMapsPlace(placeId) {
    if (!placeId) {
        return;
    }
    
    const mapsUrl = `https://www.google.com/maps/place/?q=place_id:${placeId}`;
    window.open(mapsUrl, '_blank');
}

function updateActiveFilters() {
    const district = document.getElementById('districtFilter')?.value || '';
    const carBrand = document.getElementById('carBrandFilter')?.value || '';
    const service = document.getElementById('serviceFilter')?.value || '';
    const emergency = document.getElementById('emergencyFilter')?.value || '';
    
    const activeFiltersContainer = document.getElementById('activeFilters');
    const activeFiltersList = document.getElementById('activeFiltersList');
    
    if (!activeFiltersContainer || !activeFiltersList) return;
    
    activeFiltersList.innerHTML = '';
    
    const activeFilters = [];
    
    if (district) {
        activeFilters.push({ type: 'District', value: district });
    }
    
    if (carBrand) {
        activeFilters.push({ type: 'Car Brand', value: carBrand });
    }
    
    if (service) {
        activeFilters.push({ type: 'Service', value: service });
    }
    
    if (emergency) {
        activeFilters.push({ type: 'Emergency', value: emergency });
    }
    
    if (userLocation) {
        activeFilters.push({ type: 'Location', value: 'Within 10km' });
    }
    
    if (activeFilters.length > 0) {
        activeFiltersContainer.style.display = 'block';
        
        activeFilters.forEach(filter => {
            const filterElement = document.createElement('div');
            filterElement.className = 'active-filter-tag';
            filterElement.innerHTML = `
                <span class="filter-type">${filter.type}:</span>
                <span class="filter-value">${filter.value}</span>
                <button class="remove-filter" data-type="${filter.type.toLowerCase().replace(' ', '_')}" title="Remove filter">
                    <i class="fas fa-times"></i>
                </button>
            `;
            activeFiltersList.appendChild(filterElement);
        });
        
        // Add event listeners to remove buttons
        document.querySelectorAll('.remove-filter').forEach(button => {
            button.addEventListener('click', function() {
                const type = this.getAttribute('data-type');
                if (type === 'district') {
                    const districtFilter = document.getElementById('districtFilter');
                    if (districtFilter) districtFilter.value = '';
                } else if (type === 'car_brand') {
                    const carBrandFilter = document.getElementById('carBrandFilter');
                    if (carBrandFilter) carBrandFilter.value = '';
                } else if (type === 'service') {
                    const serviceFilter = document.getElementById('serviceFilter');
                    if (serviceFilter) serviceFilter.value = '';
                } else if (type === 'emergency') {
                    const emergencyFilter = document.getElementById('emergencyFilter');
                    if (emergencyFilter) emergencyFilter.value = '';
                } else if (type === 'location') {
                    userLocation = null;
                    hideLocationStatus();
                }
                loadGarages();
            });
        });
    } else {
        activeFiltersContainer.style.display = 'none';
    }
}

function updateResultsCount(garages) {
    const resultsCount = document.getElementById('resultsCount');
    const garageCount = document.getElementById('garageCount');
    const databaseCount = document.getElementById('databaseCount');
    const googleCount = document.getElementById('googleCount');
    
    if (!resultsCount || !garageCount || !databaseCount || !googleCount) return;
    
    if (garages.length > 0) {
        const databaseGarages = garages.filter(g => g.source === 'database').length;
        const googleGarages = garages.filter(g => g.source === 'google').length;
        
        resultsCount.style.display = 'block';
        garageCount.textContent = garages.length;
        databaseCount.textContent = `${databaseGarages} from MotorLink`;
        googleCount.textContent = `${googleGarages} from nearby`;
    } else {
        resultsCount.style.display = 'none';
    }
}

// Helper functions
function parseJsonArray(jsonString) {
    if (!jsonString) return [];
    try {
        const parsed = JSON.parse(jsonString);
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        return [];
    }
}

function createStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
    
    let stars = '';
    for (let i = 0; i < fullStars; i++) stars += '<i class="fas fa-star"></i>';
    if (hasHalfStar) stars += '<i class="fas fa-star-half-alt"></i>';
    for (let i = 0; i < emptyStars; i++) stars += '<i class="far fa-star"></i>';
    
    return stars;
}

function showError(message) {
    const grid = document.getElementById('garagesGrid');
    if (!grid) return;
    
    grid.innerHTML = `
        <div class="garage-error-state">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Error Loading Garages</h3>
            <p>${message}</p>
            <button class="btn btn-primary" onclick="loadGarages()">
                <i class="fas fa-refresh"></i> Try Again
            </button>
        </div>
    `;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Scroll to top functionality removed - using .back-to-top button from script.js instead

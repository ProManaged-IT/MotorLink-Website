// ============================================================================
// Journey Planner JavaScript
// ============================================================================
// Handles journey planning with Google Maps integration and fuel calculations
// ============================================================================

let journeyMap = null;
let directionsService = null;
let journeyRoutePolyline = null;
let journeyRouteMarkers = [];
let currentFuelPrices = {};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function cleanJourneyVehicleLabel(label) {
    return String(label || '').replace(/\s*★$/, '').trim();
}

function updateJourneyFuelEstimateStatus(message, state = 'idle') {
    const statusElement = document.getElementById('journeyFuelEstimateStatus');
    if (!statusElement) {
        return;
    }

    statusElement.textContent = message;
    statusElement.dataset.state = state;
}

function setJourneyFuelConsumptionValue(value, meta = {}) {
    const fuelInput = document.getElementById('journeyFuelConsumption');
    if (!fuelInput) {
        return;
    }

    fuelInput.value = value === '' || value === null || typeof value === 'undefined'
        ? ''
        : Number(value).toFixed(2);
    fuelInput.dataset.sourceType = meta.type || '';
    fuelInput.dataset.sourceLabel = meta.label || '';
    fuelInput.dataset.sourceDetail = meta.detail || '';
}

function getSelectedJourneyVehicleContext() {
    const vehicleSelect = document.getElementById('journeyVehicle');
    if (!vehicleSelect || vehicleSelect.selectedIndex < 0) {
        return null;
    }

    const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        return null;
    }

    return {
        id: selectedOption.value,
        label: cleanJourneyVehicleLabel(selectedOption.textContent),
        make: selectedOption.dataset.make || '',
        model: selectedOption.dataset.model || '',
        year: parseInt(selectedOption.dataset.year || '', 10) || 0,
        fuelType: selectedOption.dataset.fuelType || 'petrol',
        fuelConsumption: selectedOption.dataset.fuelConsumption || '',
        engineSizeLiters: selectedOption.dataset.engineSize ? parseFloat(selectedOption.dataset.engineSize) : null,
        transmission: selectedOption.dataset.transmission || ''
    };
}

function resolveJourneyFuelSourceMeta(selectedVehicle, manualInputValue, vehicleFuelConsumption, fuelType) {
    const fuelInput = document.getElementById('journeyFuelConsumption');
    const sourceType = fuelInput?.dataset.sourceType || '';
    const sourceLabel = fuelInput?.dataset.sourceLabel || '';
    const sourceDetail = fuelInput?.dataset.sourceDetail || '';

    if (manualInputValue) {
        return {
            type: sourceType || 'manual',
            label: sourceLabel || 'Manual fuel consumption',
            detail: sourceDetail || 'Custom value entered in the journey planner'
        };
    }

    if (selectedVehicle && vehicleFuelConsumption) {
        return {
            type: 'saved-vehicle',
            label: 'Saved vehicle profile',
            detail: selectedVehicle.label
        };
    }

    return {
        type: 'default',
        label: 'Default journey estimate',
        detail: `Using the Malawi ${fuelType === 'diesel' ? 'diesel' : 'petrol'} fallback average because no vehicle-specific fuel consumption was available`
    };
}

function toggleJourneyLookupButton(button, isLoading) {
    if (!button) {
        return;
    }

    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
    }

    button.disabled = isLoading;
    button.innerHTML = isLoading
        ? '<i class="fas fa-spinner fa-spin"></i> Looking up...'
        : button.dataset.originalHtml;
}

document.addEventListener('DOMContentLoaded', function() {
    loadFuelPrices();
    loadGoogleMapsAPI();
});

async function loadGoogleMapsAPI() {
    // Check if already loaded
    if (typeof google !== 'undefined' && google.maps) {
        initializeJourneyPlanner();
        return;
    }
    
    // Check if already loading
    if (window.googleMapsLoading) {
        const checkInterval = setInterval(() => {
            if (typeof google !== 'undefined' && google.maps) {
                clearInterval(checkInterval);
                initializeJourneyPlanner();
            }
        }, 100);
        return;
    }
    
    window.googleMapsLoading = true;
    
    let mapConfig;
    try {
        mapConfig = await window.getGoogleMapsConfig();
    } catch (error) {
        console.error('Failed to load Google Maps runtime config', error);
        window.googleMapsLoading = false;
        return;
    }

    if (!mapConfig || !mapConfig.apiKey) {
        console.error('Google Maps API key is not configured');
        window.googleMapsLoading = false;
        return;
    }

    // Load Google Maps API with places + geometry (geometry is used to decode encoded polylines)
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${mapConfig.apiKey}&libraries=places,geometry&loading=async&callback=initJourneyPlanner`;
    script.async = true;
    script.defer = true;
    
    script.onerror = function() {
        console.error('Failed to load Google Maps API');
        window.googleMapsLoading = false;
    };
    
    document.head.appendChild(script);
}

// Global callback for Google Maps
window.initJourneyPlanner = function() {
    window.googleMapsLoading = false;
    initializeJourneyPlanner();
};

function initializeJourneyPlanner() {
    // Wait a bit to ensure all libraries are loaded
    setTimeout(() => {
        // Initialize Google Maps
        if (typeof google !== 'undefined' && google.maps) {
            initMap();
            initAutocomplete();
        } else {
            console.error('Google Maps API not loaded');
        }
    }, 100);
    
    // Setup event listeners (these can be set up immediately)
    const calculateBtn = document.getElementById('calculateJourneyBtn');
    if (calculateBtn && !calculateBtn.dataset.listenerBound) {
        calculateBtn.addEventListener('click', calculateJourney);
        calculateBtn.dataset.listenerBound = 'true';
    }
    
    const vehicleSelect = document.getElementById('journeyVehicle');
    if (vehicleSelect && !vehicleSelect.dataset.listenerBound) {
        vehicleSelect.addEventListener('change', function() {
            const selectedVehicle = getSelectedJourneyVehicleContext();
            const fuelInput = document.getElementById('journeyFuelConsumption');

            if (selectedVehicle && selectedVehicle.fuelConsumption) {
                setJourneyFuelConsumptionValue(selectedVehicle.fuelConsumption, {
                    type: 'saved-vehicle',
                    label: 'Saved vehicle profile',
                    detail: selectedVehicle.label
                });
                updateJourneyFuelEstimateStatus(`Auto-filled from ${selectedVehicle.label}. You can override it or fetch an official online estimate.`, 'saved');
                return;
            }

            if (fuelInput?.dataset.sourceType === 'saved-vehicle') {
                setJourneyFuelConsumptionValue('', {
                    type: '',
                    label: '',
                    detail: ''
                });
            }

            if (fuelInput?.value.trim()) {
                updateJourneyFuelEstimateStatus('Using the current custom fuel consumption value.', 'manual');
            } else {
                updateJourneyFuelEstimateStatus('Select a saved vehicle or enter your own fuel consumption.', 'idle');
            }
        });
        vehicleSelect.dataset.listenerBound = 'true';
    }

    const fuelInput = document.getElementById('journeyFuelConsumption');
    if (fuelInput && !fuelInput.dataset.listenerBound) {
        fuelInput.addEventListener('input', function() {
            if (this.value.trim()) {
                this.dataset.sourceType = 'manual';
                this.dataset.sourceLabel = 'Manual fuel consumption';
                this.dataset.sourceDetail = 'Custom value entered in the journey planner';
                updateJourneyFuelEstimateStatus('Using a custom fuel consumption value. Select a saved vehicle to fetch an official online estimate.', 'manual');
            } else {
                this.dataset.sourceType = '';
                this.dataset.sourceLabel = '';
                this.dataset.sourceDetail = '';
                updateJourneyFuelEstimateStatus('Select a saved vehicle or enter your own fuel consumption.', 'idle');
            }
        });
        fuelInput.dataset.listenerBound = 'true';
    }

    const onlineEstimateButton = document.getElementById('journeyOnlineFuelEstimateBtn');
    if (onlineEstimateButton && !onlineEstimateButton.dataset.listenerBound) {
        onlineEstimateButton.addEventListener('click', handleJourneyOnlineFuelEstimate);
        onlineEstimateButton.dataset.listenerBound = 'true';
    }
    
}

function initMap() {
    // Initialize map centered on Malawi
    journeyMap = new google.maps.Map(document.getElementById('journeyMap'), {
        center: { lat: -13.9626, lng: 33.7741 }, // Lilongwe, Malawi
        zoom: 7,
        mapTypeControl: true,
        streetViewControl: false
    });
    
    // Prefer Routes API route computation and render route manually.
}

function initAutocomplete() {
    if (!google || !google.maps || !google.maps.places) {
        console.error('Google Maps Places API not loaded');
        return;
    }
    
    const originInput = document.getElementById('journeyOrigin');
    const destinationInput = document.getElementById('journeyDestination');
    
    if (originInput) {
        try {
            // Use new PlaceAutocompleteElement API
            const originAutocomplete = new google.maps.places.PlaceAutocompleteElement({
                componentRestrictions: { country: 'mw' }, // Restrict to Malawi
            });
            
            originAutocomplete.addEventListener('gmp-placeselect', async ({ place }) => {
                await place.fetchFields({
                    fields: ['displayName', 'formattedAddress', 'location']
                });
                
                if (place.location) {
                    journeyMap.setCenter(place.location);
                }
            });
            
            // Replace the input with the autocomplete element
            originInput.parentNode.replaceChild(originAutocomplete, originInput);
            originAutocomplete.id = 'journeyOrigin';
            originAutocomplete.className = 'journey-place-autocomplete';
            originAutocomplete.placeholder = 'Enter origin location';
        } catch (error) {
            console.warn('PlaceAutocompleteElement initialization error (user can still type addresses):', error);
            // Fallback: user can still type addresses manually
        }
    }
    
    if (destinationInput) {
        try {
            // Use new PlaceAutocompleteElement API
            const destinationAutocomplete = new google.maps.places.PlaceAutocompleteElement({
                componentRestrictions: { country: 'mw' }, // Restrict to Malawi
            });
            
            destinationAutocomplete.addEventListener('gmp-placeselect', async ({ place }) => {
                await place.fetchFields({
                    fields: ['displayName', 'formattedAddress', 'location']
                });
                
                if (place.location) {
                    journeyMap.setCenter(place.location);
                }
            });
            
            // Replace the input with the autocomplete element
            destinationInput.parentNode.replaceChild(destinationAutocomplete, destinationInput);
            destinationAutocomplete.id = 'journeyDestination';
            destinationAutocomplete.className = 'journey-place-autocomplete';
            destinationAutocomplete.placeholder = 'Enter destination location';
        } catch (error) {
            console.warn('PlaceAutocompleteElement initialization error (user can still type addresses):', error);
            // Fallback: user can still type addresses manually
        }
    }
}

async function loadFuelPrices() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_fuel_prices`);
        const data = await response.json();
        
        if (data.success && data.prices) {
            data.prices.forEach(price => {
                currentFuelPrices[price.fuel_type] = price.price_per_liter_mwk;
            });
            
            // Display fuel prices in the UI
            displayFuelPrices(data.prices);
        }
    } catch (error) {
        console.error('Error loading fuel prices:', error);
    }
}

function displayFuelPrices(prices) {
    const displayContainer = document.getElementById('fuelPricesDisplay');
    const updateTimeContainer = document.getElementById('fuelPricesUpdateTime');
    
    if (!displayContainer) return;
    
    if (prices.length === 0) {
        displayContainer.innerHTML = '<div style="color: #666;">No fuel prices available</div>';
        if (updateTimeContainer) updateTimeContainer.textContent = 'Not available';
        return;
    }
    
    // Find the most recent update time
    let mostRecentUpdate = null;
    prices.forEach(price => {
        if (price.last_updated) {
            const updateTime = new Date(price.last_updated);
            if (!mostRecentUpdate || updateTime > mostRecentUpdate) {
                mostRecentUpdate = updateTime;
            }
        }
    });
    
    // Display prices
    let html = '';
    prices.forEach(price => {
        const fuelTypeName = price.fuel_type.charAt(0).toUpperCase() + price.fuel_type.slice(1);
        html += `
            <div style="padding: 10px 15px; background: white; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${fuelTypeName}</div>
                <div style="font-size: 1.2rem; color: #28a745; font-weight: bold;">MWK ${parseFloat(price.price_per_liter_mwk).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/L</div>
            </div>
        `;
    });
    displayContainer.innerHTML = html;
    
    // Display last updated time
    if (updateTimeContainer && mostRecentUpdate) {
        const now = new Date();
        const diffMs = now - mostRecentUpdate;
        const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
        const diffMinutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
        
        let timeText = '';
        if (diffHours > 0) {
            timeText = `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
        } else if (diffMinutes > 0) {
            timeText = `${diffMinutes} minute${diffMinutes > 1 ? 's' : ''} ago`;
        } else {
            timeText = 'Just now';
        }
        
        updateTimeContainer.textContent = timeText + ' (' + mostRecentUpdate.toLocaleString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit' 
        }) + ')';
    } else if (updateTimeContainer) {
        updateTimeContainer.textContent = 'Not available';
    }
}

async function calculateJourney() {
    if (typeof window.ensureVehicleFeatureAccess === 'function') {
        const hasAccess = await window.ensureVehicleFeatureAccess('journey-planner', { forceRefresh: true });
        if (!hasAccess) {
            alert('Please log in to use the journey planner.');
            return;
        }
    }

    const origin = document.getElementById('journeyOrigin').value.trim();
    const destination = document.getElementById('journeyDestination').value.trim();
    const vehicleId = document.getElementById('journeyVehicle').value;
    const fuelConsumption = document.getElementById('journeyFuelConsumption').value;
    const selectedVehicle = getSelectedJourneyVehicleContext();
    
    if (!origin || !destination) {
        alert('Please enter both origin and destination');
        return;
    }
    
    const calculateBtn = document.getElementById('calculateJourneyBtn');
    const originalText = calculateBtn.innerHTML;
    calculateBtn.disabled = true;
    calculateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculating...';
    
    try {
        // Get route from Google Maps
        const route = await getRoute(origin, destination);
        
        if (!route) {
            throw new Error('Could not calculate route');
        }
        
        const distanceKm = route.distance.value / 1000; // Convert to km
        const durationMinutes = Math.round(route.duration.value / 60);
        
        // Get vehicle details if selected
        let vehicleFuelType = 'petrol';
        let vehicleFuelConsumption = null;
        
        if (vehicleId && selectedVehicle) {
            vehicleFuelType = selectedVehicle.fuelType || 'petrol';
            vehicleFuelConsumption = selectedVehicle.fuelConsumption || null;
        }
        
        // Use provided fuel consumption or vehicle's default
        const finalFuelConsumption = fuelConsumption || vehicleFuelConsumption || (vehicleFuelType === 'diesel' ? 8.5 : 9.5);
        const fuelSource = resolveJourneyFuelSourceMeta(selectedVehicle, fuelConsumption, vehicleFuelConsumption, vehicleFuelType);
        
        // Calculate fuel cost
        const fuelPrice = currentFuelPrices[vehicleFuelType] || (vehicleFuelType === 'diesel' ? 1950.00 : 1850.00);
        const fuelNeeded = (distanceKm / 100) * parseFloat(finalFuelConsumption);
        const fuelCost = fuelNeeded * fuelPrice;
        
        // Display results
        displayJourneyResults({
            origin: origin,
            destination: destination,
            distanceKm: distanceKm,
            durationMinutes: durationMinutes,
            fuelType: vehicleFuelType,
            fuelConsumption: finalFuelConsumption,
            fuelNeeded: fuelNeeded,
            fuelPrice: fuelPrice,
            fuelCost: fuelCost,
            fuelSource: fuelSource,
            originLat: route.start_location.lat(),
            originLng: route.start_location.lng(),
            destinationLat: route.end_location.lat(),
            destinationLng: route.end_location.lng()
        });
        
        // Save to history
        try {
            await saveJourneyToHistory({
                vehicle_id: vehicleId || null,
                origin: origin,
                destination: destination,
                distance_km: distanceKm,
                duration_minutes: durationMinutes,
                fuel_type: vehicleFuelType,
                fuel_consumption: finalFuelConsumption,
                origin_lat: route.start_location.lat(),
                origin_lng: route.start_location.lng(),
                destination_lat: route.end_location.lat(),
                destination_lng: route.end_location.lng(),
                save_to_history: true
            });
        } catch (error) {
            console.error('Error saving journey to history:', error);
        }
        
    } catch (error) {
        console.error('Error calculating journey:', error);
        alert('Error calculating journey: ' + error.message);
    } finally {
        calculateBtn.disabled = false;
        calculateBtn.innerHTML = originalText;
    }
}

async function handleJourneyOnlineFuelEstimate() {
    const button = document.getElementById('journeyOnlineFuelEstimateBtn');
    const selectedVehicle = getSelectedJourneyVehicleContext();

    if (!selectedVehicle) {
        alert('Select a saved vehicle first to fetch an official online estimate.');
        return;
    }

    if (!selectedVehicle.year) {
        alert('The selected saved vehicle needs a model year before an online estimate can be fetched. Update the vehicle details and try again.');
        return;
    }

    toggleJourneyLookupButton(button, true);
    updateJourneyFuelEstimateStatus('Looking up the official combined fuel economy estimate online...', 'loading');

    try {
        const result = await window.lookupOnlineFuelConsumptionEstimate({
            year: selectedVehicle.year,
            make: selectedVehicle.make,
            model: selectedVehicle.model,
            fuel_type: selectedVehicle.fuelType,
            transmission: selectedVehicle.transmission,
            engine_size_liters: selectedVehicle.engineSizeLiters
        }, 'journey-planner');

        const estimate = result.estimate;
        setJourneyFuelConsumptionValue(estimate.fuel_consumption_l100km, {
            type: 'online',
            label: `${estimate.source} official estimate`,
            detail: estimate.matched_option
        });
        updateJourneyFuelEstimateStatus(
            `Official estimate applied from ${estimate.source}: ${estimate.matched_option} (${estimate.combined_mpg} MPG combined).`,
            'online'
        );
    } catch (error) {
        console.error('Error fetching journey online estimate:', error);
        updateJourneyFuelEstimateStatus(error.message || 'Failed to fetch the online estimate.', 'error');
        alert(error.message || 'Failed to fetch the online estimate.');
    } finally {
        toggleJourneyLookupButton(button, false);
    }
}

async function getRoute(origin, destination) {
    try {
        if (!google || !google.maps) {
            throw new Error('Google Maps is not available. Please refresh the page.');
        }

        let RouteApi = google.maps?.routes?.Route;
        if (!RouteApi && typeof google.maps.importLibrary === 'function') {
            const routesLib = await google.maps.importLibrary('routes');
            RouteApi = routesLib?.Route;
        }

        if (RouteApi && typeof RouteApi.computeRoutes === 'function') {
            const routesResult = await RouteApi.computeRoutes({
                origin: { address: origin },
                destination: { address: destination },
                travelMode: 'DRIVE',
                regionCode: 'MW'
            });

            const primaryRoute = routesResult?.routes?.[0];
            const primaryLeg = primaryRoute?.legs?.[0];
            if (!primaryRoute || !primaryLeg) {
                throw new Error('No route found');
            }

            const startLoc = normalizeRouteLocation(primaryLeg.startLocation);
            const endLoc = normalizeRouteLocation(primaryLeg.endLocation);
            renderComputedRoute(primaryRoute, startLoc, endLoc);

            return {
                distance: { value: primaryRoute.distanceMeters ?? primaryLeg.distanceMeters ?? 0 },
                duration: { value: parseRouteDurationSeconds(primaryRoute.duration ?? primaryLeg.duration) },
                start_location: {
                    lat: () => startLoc.lat,
                    lng: () => startLoc.lng
                },
                end_location: {
                    lat: () => endLoc.lat,
                    lng: () => endLoc.lng
                }
            };
        }
    } catch (routesError) {
        console.warn('Routes API computeRoutes failed, attempting legacy fallback:', routesError);
    }

    // Fallback path for older Maps runtimes where Routes library isn't available yet.
    return new Promise((resolve, reject) => {
        if (!directionsService) {
            if (typeof google !== 'undefined' && google.maps && google.maps.DirectionsService) {
                directionsService = new google.maps.DirectionsService();
            } else {
                reject(new Error('Route service not available. Please refresh the page.'));
                return;
            }
        }

        directionsService.route({
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING,
            unitSystem: google.maps.UnitSystem.METRIC
        }, (result, status) => {
            if (status === 'OK' && result.routes && result.routes.length > 0) {
                const route = result.routes[0].legs[0];
                const path = result.routes[0].overview_path || [];
                renderPathOnMap(path, route.start_location, route.end_location);
                resolve(route);
            } else {
                reject(new Error('Route calculation failed: ' + status));
            }
        });
    });
}

function parseRouteDurationSeconds(durationValue) {
    if (typeof durationValue === 'number') {
        return durationValue;
    }
    if (typeof durationValue === 'string') {
        const cleaned = durationValue.trim();
        if (cleaned.endsWith('s')) {
            return parseInt(cleaned.slice(0, -1), 10) || 0;
        }
        return parseInt(cleaned, 10) || 0;
    }
    return 0;
}

function normalizeRouteLocation(locationObj) {
    if (!locationObj) {
        return { lat: -13.9626, lng: 33.7741 };
    }

    if (typeof locationObj.lat === 'function' && typeof locationObj.lng === 'function') {
        return { lat: locationObj.lat(), lng: locationObj.lng() };
    }

    if (typeof locationObj.lat === 'number' && typeof locationObj.lng === 'number') {
        return { lat: locationObj.lat, lng: locationObj.lng };
    }

    const latLng = locationObj.latLng || locationObj.location || null;
    if (latLng) {
        if (typeof latLng.lat === 'function' && typeof latLng.lng === 'function') {
            return { lat: latLng.lat(), lng: latLng.lng() };
        }
        if (typeof latLng.lat === 'number' && typeof latLng.lng === 'number') {
            return { lat: latLng.lat, lng: latLng.lng };
        }
        if (typeof latLng.latitude === 'number' && typeof latLng.longitude === 'number') {
            return { lat: latLng.latitude, lng: latLng.longitude };
        }
    }

    return { lat: -13.9626, lng: 33.7741 };
}

function renderComputedRoute(routeObj, startLoc, endLoc) {
    if (!journeyMap) return;

    let decodedPath = [];
    const encodedPolyline = routeObj?.polyline?.encodedPolyline;
    if (encodedPolyline && google.maps.geometry && google.maps.geometry.encoding) {
        decodedPath = google.maps.geometry.encoding.decodePath(encodedPolyline);
    }

    if (decodedPath.length === 0) {
        decodedPath = [startLoc, endLoc];
    }

    renderPathOnMap(decodedPath, startLoc, endLoc);
}

function renderPathOnMap(path, startLoc, endLoc) {
    if (!journeyMap) return;

    if (journeyRoutePolyline) {
        journeyRoutePolyline.setMap(null);
    }

    journeyRouteMarkers.forEach(marker => marker.setMap(null));
    journeyRouteMarkers = [];

    journeyRoutePolyline = new google.maps.Polyline({
        path: path,
        geodesic: true,
        strokeColor: '#1a73e8',
        strokeOpacity: 0.9,
        strokeWeight: 5,
        map: journeyMap
    });

    const startMarker = new google.maps.Marker({
        position: startLoc,
        map: journeyMap,
        label: 'A'
    });

    const endMarker = new google.maps.Marker({
        position: endLoc,
        map: journeyMap,
        label: 'B'
    });

    journeyRouteMarkers.push(startMarker, endMarker);

    const bounds = new google.maps.LatLngBounds();
    const points = Array.isArray(path) && path.length ? path : [startLoc, endLoc];
    points.forEach(point => bounds.extend(point));
    journeyMap.fitBounds(bounds, 60);
}

function formatCurrencyMWK(value) {
    return `MWK ${Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })}`;
}

function displayJourneyResults(results) {
    const container = document.getElementById('journeyResults');
    if (!container) return;

    const fuelSource = results.fuelSource || {
        label: 'Manual fuel consumption',
        detail: 'Custom value entered in the journey planner'
    };
    const updatedAt = new Date().toLocaleString();
    const fuelTypeLabel = results.fuelType.charAt(0).toUpperCase() + results.fuelType.slice(1);
    
    container.style.display = 'block';
    container.innerHTML = `
        <div class="journey-result-shell">
            <div class="journey-result-header">
                <div>
                    <div class="journey-result-kicker">Trip estimate ready</div>
                    <h3><i class="fas fa-route"></i> Journey Cost Breakdown</h3>
                </div>
                <div class="journey-result-updated">${escapeHtml(updatedAt)}</div>
            </div>

            <div class="journey-result-route">
                <div class="journey-route-stop">
                    <span class="journey-route-dot journey-route-dot-origin"></span>
                    <div>
                        <span class="journey-route-label">From</span>
                        <strong>${escapeHtml(results.origin)}</strong>
                    </div>
                </div>
                <div class="journey-route-line"></div>
                <div class="journey-route-stop">
                    <span class="journey-route-dot journey-route-dot-destination"></span>
                    <div>
                        <span class="journey-route-label">To</span>
                        <strong>${escapeHtml(results.destination)}</strong>
                    </div>
                </div>
            </div>

            <div class="journey-result-grid">
                <article class="journey-result-card journey-result-card-highlight">
                    <span class="journey-result-label">Estimated fuel cost</span>
                    <strong class="journey-result-value journey-result-value-cost">${escapeHtml(formatCurrencyMWK(results.fuelCost))}</strong>
                    <span class="journey-result-subtext">Based on ${results.fuelNeeded.toFixed(2)} L at ${escapeHtml(formatCurrencyMWK(results.fuelPrice))}/L</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Distance</span>
                    <strong class="journey-result-value">${results.distanceKm.toFixed(1)} km</strong>
                    <span class="journey-result-subtext">Road distance from the calculated route</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Duration</span>
                    <strong class="journey-result-value">${formatDuration(results.durationMinutes)}</strong>
                    <span class="journey-result-subtext">Estimated drive time in normal conditions</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Fuel needed</span>
                    <strong class="journey-result-value">${results.fuelNeeded.toFixed(2)} L</strong>
                    <span class="journey-result-subtext">Fuel type: ${escapeHtml(fuelTypeLabel)}</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Consumption used</span>
                    <strong class="journey-result-value">${Number(results.fuelConsumption).toFixed(2)} L/100km</strong>
                    <span class="journey-result-subtext">${escapeHtml(fuelSource.label || 'Manual fuel consumption')}</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Fuel price</span>
                    <strong class="journey-result-value">${escapeHtml(formatCurrencyMWK(results.fuelPrice))}/L</strong>
                    <span class="journey-result-subtext">Latest available price loaded for this session</span>
                </article>
            </div>

            <div class="journey-result-footer">
                <div class="journey-result-source">
                    <span class="journey-result-label">Fuel data source</span>
                    <strong>${escapeHtml(fuelSource.label || 'Manual fuel consumption')}</strong>
                    <span class="journey-result-subtext">${escapeHtml(fuelSource.detail || 'Custom value entered in the journey planner')}</span>
                </div>
                <div class="journey-result-meta">
                    <span class="journey-result-label">Last updated</span>
                    <strong>${escapeHtml(updatedAt)}</strong>
                </div>
            </div>
        </div>
    `;
    
    // Scroll to results
    container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function formatDuration(minutes) {
    if (minutes < 60) {
        return `${minutes} min`;
    }
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return `${hours}h ${mins}min`;
}

async function saveJourneyToHistory(journeyData) {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=calculate_journey`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(journeyData)
        });

        if (response.status === 401) {
            if (typeof window.handleVehicleFeatureUnauthorized === 'function') {
                await window.handleVehicleFeatureUnauthorized('journey-planner');
            }
            throw new Error('Please log in to save journey history.');
        }
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Failed to save journey history.');
        }

        return data;
    } catch (error) {
        console.error('Error saving journey:', error);
        throw error;
    }
}

// Initialize when Google Maps API loads
if (typeof google !== 'undefined' && google.maps) {
    document.addEventListener('DOMContentLoaded', initializeJourneyPlanner);
}


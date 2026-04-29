// ============================================================================
// Journey Planner JavaScript
// ============================================================================
// Handles journey planning with Google Maps routing/location services and fuel calculations
// ============================================================================

let journeyRoutePoints = [];
let currentFuelPrices = {};
let currentFuelPriceMeta = {};
const journeyGeocodeCache = new Map();
let journeyMap = null;
let journeyRoutePolyline = null;
let journeyAutocompleteBound = false;
const journeyPlacesTimers = {};

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
        detail: `Using the ${fuelType === 'diesel' ? 'diesel' : 'petrol'} fallback average because no vehicle-specific fuel consumption was available`
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
    initializeJourneyPlanner();
});

function initializeJourneyPlanner() {
    // Bind origin/destination autocomplete immediately — it uses the server-side
    // Places API only and must NOT wait for the Google Maps JS API to load.
    bindJourneyPlacesAutocomplete();

    initMap();

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
    initializeJourneyGoogleMap().catch(error => {
        console.warn('Google journey map initialization failed:', error);
        const countryName = (window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : 'Malawi';
        renderJourneyMapEmbed({ query: countryName, zoom: 7, title: 'Journey map' });
    });
}

function getJourneyCountryCode() {
    return (window.CONFIG && CONFIG.COUNTRY_CODE) ? String(CONFIG.COUNTRY_CODE).toLowerCase() : 'mw';
}

function getJourneyDefaultCenter() {
    const geoPosition = (window.CONFIG && CONFIG.GEO_POSITION) ? String(CONFIG.GEO_POSITION) : '';
    const match = geoPosition.match(/^\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*$/);
    if (match) {
        return { lat: parseFloat(match[1]), lng: parseFloat(match[2]) };
    }

    return { lat: -13.2543, lng: 34.3015 };
}

async function initializeJourneyGoogleMap() {
    const mapElement = document.getElementById('journeyMap');
    if (!mapElement) return null;

    await window.ensureGoogleMapsApi({ libraries: [] });

    if (!journeyMap) {
        mapElement.innerHTML = '';
        const mapsConfig = await window.getGoogleMapsConfig();
        const mapOptions = {
            center: getJourneyDefaultCenter(),
            zoom: 7,
            gestureHandling: 'greedy',
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true
        };

        if (mapsConfig.mapId) {
            mapOptions.mapId = mapsConfig.mapId;
        }

        journeyMap = new google.maps.Map(mapElement, mapOptions);
    }

    bindJourneyPlacesAutocomplete();
    return journeyMap;
}

function bindJourneyPlacesAutocomplete() {
    if (journeyAutocompleteBound) {
        return;
    }

    ['journeyOrigin', 'journeyDestination'].forEach(id => {
        const input = document.getElementById(id);
        if (!input) return;

        const suggestions = document.createElement('div');
        suggestions.className = 'journey-place-suggestions';
        suggestions.style.cssText = 'display:none;position:absolute;z-index:30;left:0;right:0;top:100%;background:#fff;border:1px solid #d8e2dc;border-radius:6px;box-shadow:0 10px 24px rgba(0,0,0,.12);overflow:hidden;';

        const parent = input.parentElement;
        if (parent) {
            parent.style.position = parent.style.position || 'relative';
            parent.appendChild(suggestions);
        }

        input.addEventListener('input', () => {
            input.dataset.placeId = '';
            input.dataset.placeLat = '';
            input.dataset.placeLng = '';
            queueJourneyPlaceSuggestions(input, suggestions, id);
        });
        input.addEventListener('blur', () => {
            setTimeout(() => { suggestions.style.display = 'none'; }, 160);
        });
    });

    journeyAutocompleteBound = true;
}

function getJourneyPlacesSessionToken(input) {
    if (!input.dataset.placesSessionToken) {
        input.dataset.placesSessionToken = (window.crypto?.randomUUID?.() || `${Date.now()}_${Math.random().toString(36).slice(2)}`);
    }
    return input.dataset.placesSessionToken;
}

function queueJourneyPlaceSuggestions(input, container, inputId) {
    clearTimeout(journeyPlacesTimers[inputId]);
    const value = input.value.trim();
    if (value.length < 3) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    journeyPlacesTimers[inputId] = setTimeout(async () => {
        try {
            const params = new URLSearchParams({
                action: 'google_places_autocomplete_new',
                input: value,
                country_code: getJourneyCountryCode(),
                session_token: getJourneyPlacesSessionToken(input)
            });
            const response = await fetch(`${CONFIG.API_URL}?${params.toString()}`);
            const data = await response.json();
            const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
            renderJourneyPlaceSuggestions(input, container, suggestions);
        } catch (error) {
            console.warn('Places autocomplete lookup failed:', error);
            container.style.display = 'none';
        }
    }, 280);
}

function renderJourneyPlaceSuggestions(input, container, suggestions) {
    if (!suggestions.length) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    container.innerHTML = suggestions.slice(0, 5).map((suggestion, index) => `
        <button type="button" data-index="${index}" style="width:100%;text-align:left;border:0;background:#fff;padding:10px 12px;cursor:pointer;display:block;">
            <strong style="display:block;color:#1f2933;font-size:14px;">${escapeHtml(suggestion.main_text || suggestion.text)}</strong>
            ${suggestion.secondary_text ? `<span style="display:block;color:#667085;font-size:12px;margin-top:2px;">${escapeHtml(suggestion.secondary_text)}</span>` : ''}
        </button>
    `).join('');

    container.querySelectorAll('button[data-index]').forEach(button => {
        button.addEventListener('mousedown', event => {
            event.preventDefault();
            const suggestion = suggestions[parseInt(button.dataset.index, 10)];
            if (!suggestion) return;
            input.value = suggestion.text || suggestion.main_text || input.value;
            input.dataset.placeId = suggestion.place_id || '';
            input.dataset.placeLat = '';
            input.dataset.placeLng = '';
            input.dataset.placesSessionToken = '';
            container.style.display = 'none';
        });
    });

    container.style.display = 'block';
}

function getJourneyRoutePayload(inputId, fallbackText) {
    const input = document.getElementById(inputId);
    const payload = {};
    if (input?.dataset.placeId) {
        payload.place_id = input.dataset.placeId;
    }

    const lat = input?.dataset.placeLat ? parseFloat(input.dataset.placeLat) : null;
    const lng = input?.dataset.placeLng ? parseFloat(input.dataset.placeLng) : null;
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
        payload.lat = lat;
        payload.lng = lng;
    }

    payload.text = String(input?.value || fallbackText || '').trim();
    return payload;
}

function renderJourneyMapEmbed({ query = '', origin = '', destination = '', zoom = 7, title = 'Journey map' } = {}) {
    const mapElement = document.getElementById('journeyMap');
    if (!mapElement) return;

    const src = origin && destination
        ? 'https://maps.google.com/maps?saddr=' + encodeURIComponent(origin) + '&daddr=' + encodeURIComponent(destination) + '&output=embed'
        : 'https://maps.google.com/maps?q=' + encodeURIComponent(query) + '&output=embed&z=' + encodeURIComponent(String(zoom));

    mapElement.innerHTML =
        '<iframe src="' + src + '" width="100%" height="100%"' +
        ' style="border:0;display:block;min-height:320px;"' +
        ' allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"' +
        ' title="' + escapeHtml(title) + '"></iframe>';
    journeyMap = null;
    journeyRoutePolyline = null;
}

function decodeGooglePolyline(encoded) {
    const path = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < encoded.length) {
        let result = 0;
        let shift = 0;
        let byte = null;
        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index < encoded.length);
        lat += (result & 1) ? ~(result >> 1) : (result >> 1);

        result = 0;
        shift = 0;
        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index < encoded.length);
        lng += (result & 1) ? ~(result >> 1) : (result >> 1);

        path.push({ lat: lat / 1e5, lng: lng / 1e5 });
    }

    return path;
}

function renderJourneyRoutePath(points) {
    if (!journeyMap || !Array.isArray(points) || points.length < 2) {
        return;
    }

    if (journeyRoutePolyline) {
        journeyRoutePolyline.setMap(null);
    }

    journeyRoutePolyline = new google.maps.Polyline({
        path: points,
        geodesic: false,
        strokeColor: '#ff6f00',
        strokeOpacity: 0.95,
        strokeWeight: 5,
        map: journeyMap
    });

    const bounds = new google.maps.LatLngBounds();
    points.forEach(point => bounds.extend(point));
    journeyMap.fitBounds(bounds, 48);
}

async function loadFuelPrices() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_fuel_prices`);
        const data = await response.json();
        
        if (data.success && data.prices) {
            currentFuelPrices = {};
            currentFuelPriceMeta = data.meta || {};

            data.prices.forEach(price => {
                currentFuelPrices[price.fuel_type] = price;
            });
            
            // Display fuel prices in the UI
            displayFuelPrices(data.prices, currentFuelPriceMeta);
        }
    } catch (error) {
        console.error('Error loading fuel prices:', error);
    }
}
function displayFuelPrices(prices, meta = {}) {
    const displayContainer = document.getElementById('fuelPricesDisplay');
    const updateTimeContainer = document.getElementById('fuelPricesUpdateTime');
    const sourceNoteContainer = document.getElementById('fuelPricesSourceNote');
    
    if (!displayContainer) return;
    
    if (prices.length === 0) {
        displayContainer.innerHTML = '<div style="color: #666;">No fuel prices available</div>';
        if (updateTimeContainer) updateTimeContainer.textContent = 'Not available';
        if (sourceNoteContainer) sourceNoteContainer.textContent = 'No live or saved fuel prices are available right now.';
        return;
    }
    
    const mostRecentUpdate = meta.last_updated ? new Date(meta.last_updated) : null;
    
    // Display prices
    let html = '';
    prices.forEach(price => {
        const fuelTypeName = price.fuel_type.charAt(0).toUpperCase() + price.fuel_type.slice(1);
        const displayCode = price.display_currency_code || meta.display_currency_code || CONFIG.CURRENCY_CODE || 'MWK';
        const displaySymbol = price.display_currency_symbol || meta.display_currency_symbol || displayCode;
        const displayDecimals = displayCode === 'USD' ? 4 : 2;
        const displayPrice = Number(price.display_price_per_liter ?? price.price_per_liter_mwk ?? 0);
        let secondaryText = '';

        if ((price.display_currency_source || '') === 'usd' && price.price_per_liter_mwk !== null && price.price_per_liter_mwk !== undefined) {
            secondaryText = `${price.currency || CONFIG.CURRENCY_CODE || 'MWK'} ${Number(price.price_per_liter_mwk).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}/L local`;
        } else if (price.price_per_liter_usd !== null && price.price_per_liter_usd !== undefined && (price.display_currency_source || '') !== 'usd') {
            secondaryText = `USD $${Number(price.price_per_liter_usd).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}/L`;
        }

        html += `
            <div style="padding: 10px 15px; background: white; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${fuelTypeName}</div>
                <div style="font-size: 1.2rem; color: #28a745; font-weight: bold;">${displaySymbol} ${displayPrice.toLocaleString('en-US', { minimumFractionDigits: displayDecimals, maximumFractionDigits: displayDecimals })}/L</div>
                ${secondaryText ? `<div style="font-size: 0.85rem; color: #666; margin-top: 4px;">${secondaryText}</div>` : ''}
            </div>
        `;
    });
    displayContainer.innerHTML = html;

    if (sourceNoteContainer) {
        sourceNoteContainer.textContent = meta.public_notice || 'Showing the latest available fuel prices.';
    }
    
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
        // Get route from free location/routing services
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
        const fuelConsumptionSource = resolveJourneyFuelSourceMeta(selectedVehicle, fuelConsumption, vehicleFuelConsumption, vehicleFuelType);

        const calculation = await saveJourneyToHistory({
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

        displayJourneyResults({
            origin: origin,
            destination: destination,
            distanceKm: Number(calculation.distance_km ?? distanceKm),
            durationMinutes: durationMinutes,
            fuelType: calculation.fuel_type || vehicleFuelType,
            fuelConsumption: Number(calculation.fuel_consumption_liters_per_100km ?? finalFuelConsumption),
            fuelNeeded: Number(calculation.fuel_needed_liters ?? ((distanceKm / 100) * parseFloat(finalFuelConsumption))),
            fuelPrice: Number(calculation.fuel_price_per_liter_display ?? calculation.fuel_price_per_liter_mwk ?? 0),
            fuelPricePrimary: Number(calculation.fuel_price_per_liter_mwk ?? 0),
            fuelCost: Number(calculation.fuel_cost_display ?? calculation.fuel_cost_mwk ?? 0),
            fuelCostPrimary: Number(calculation.fuel_cost_mwk ?? 0),
            displayCurrencyCode: calculation.display_currency_code || currentFuelPriceMeta.display_currency_code || CONFIG.CURRENCY_CODE || 'MWK',
            displayCurrencySymbol: calculation.display_currency_symbol || currentFuelPriceMeta.display_currency_symbol || CONFIG.CURRENCY_CODE || 'MWK',
            fuelConsumptionSource: fuelConsumptionSource,
            fuelPriceMeta: calculation.fuel_price_meta || currentFuelPriceMeta,
            originLat: route.start_location.lat(),
            originLng: route.start_location.lng(),
            destinationLat: route.end_location.lat(),
            destinationLng: route.end_location.lng()
        });
        
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
        const originPayload = getJourneyRoutePayload('journeyOrigin', origin);
        const destinationPayload = getJourneyRoutePayload('journeyDestination', destination);
        const response = await fetch(`${CONFIG.API_URL}?action=google_routes_compute`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: CONFIG.USE_CREDENTIALS ? 'include' : 'same-origin',
            body: JSON.stringify({
                origin_text: originPayload.text || origin,
                origin_place_id: originPayload.place_id || '',
                origin_lat: originPayload.lat,
                origin_lng: originPayload.lng,
                destination_text: destinationPayload.text || destination,
                destination_place_id: destinationPayload.place_id || '',
                destination_lat: destinationPayload.lat,
                destination_lng: destinationPayload.lng,
                country_code: getJourneyCountryCode().toUpperCase()
            })
        });

        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.success || !data.route?.distance_meters) {
            throw new Error(data?.message || `Google Routes failed (HTTP ${response.status})`);
        }

        const decodedPoints = data.route.encoded_polyline
            ? decodeGooglePolyline(data.route.encoded_polyline)
            : [];
        journeyRoutePoints = decodedPoints;
        if (decodedPoints.length >= 2) {
            try {
                await initializeJourneyGoogleMap();
                renderJourneyRoutePath(decodedPoints);
            } catch (mapError) {
                console.warn('Journey route calculated, but map rendering failed:', mapError);
                renderJourneyMapEmbed({ origin, destination, title: `Route from ${origin} to ${destination}` });
            }
        }

        const originCoords = data.route.origin || decodedPoints[0] || await geocodeJourneyLocation(origin);
        const destinationCoords = data.route.destination || decodedPoints[decodedPoints.length - 1] || await geocodeJourneyLocation(destination);

        return buildJourneyRouteResult(
            Number(data.route.distance_meters),
            Number(data.route.duration_seconds || 0),
            originCoords,
            destinationCoords
        );
    } catch (error) {
        console.warn('Google Routes lookup failed, using Google geocode fallback:', error);
        const originCoords = await geocodeJourneyLocation(origin);
        const destinationCoords = await geocodeJourneyLocation(destination);
        const distanceKm = calculateGreatCircleDistanceKm(originCoords, destinationCoords) * 1.35;
        const durationSeconds = (distanceKm / 65) * 3600;
        journeyRoutePoints = [originCoords, destinationCoords];
        renderJourneyMapEmbed({ origin, destination, title: `Route from ${origin} to ${destination}` });
        return buildJourneyRouteResult(distanceKm * 1000, durationSeconds, originCoords, destinationCoords);
    }
}

async function geocodeJourneyLocation(locationName) {
    const countryName = (window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : 'Malawi';
    const countryCode = (window.CONFIG && CONFIG.COUNTRY_CODE) ? String(CONFIG.COUNTRY_CODE).toLowerCase() : 'mw';
    const query = [locationName, countryName].map(part => String(part || '').trim()).filter(Boolean).join(', ');
    const cacheKey = `${countryCode}:${query.toLowerCase()}`;

    if (journeyGeocodeCache.has(cacheKey)) {
        return journeyGeocodeCache.get(cacheKey);
    }

    await initializeJourneyGoogleMap();
    const geocoder = new google.maps.Geocoder();
    const data = await geocoder.geocode({
        address: query,
        componentRestrictions: countryCode ? { country: countryCode } : undefined
    });
    const location = data.results?.[0]?.geometry?.location;
    if (!location) {
        throw new Error(`Could not find ${locationName}. Try adding the city or district.`);
    }

    const coords = { lat: location.lat(), lng: location.lng() };
    journeyGeocodeCache.set(cacheKey, coords);
    return coords;
}

function buildJourneyRouteResult(distanceMeters, durationSeconds, originCoords, destinationCoords) {
    return {
        distance: { value: distanceMeters },
        duration: { value: durationSeconds },
        start_location: {
            lat: () => originCoords.lat,
            lng: () => originCoords.lng
        },
        end_location: {
            lat: () => destinationCoords.lat,
            lng: () => destinationCoords.lng
        }
    };
}

function calculateGreatCircleDistanceKm(originCoords, destinationCoords) {
    const toRadians = value => value * Math.PI / 180;
    const earthRadiusKm = 6371;
    const dLat = toRadians(destinationCoords.lat - originCoords.lat);
    const dLng = toRadians(destinationCoords.lng - originCoords.lng);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
        + Math.cos(toRadians(originCoords.lat)) * Math.cos(toRadians(destinationCoords.lat))
        * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return earthRadiusKm * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function formatJourneyCurrency(value, currencyCode, currencySymbol, decimals = 2) {
    const numericValue = Number(value || 0);
    const safeValue = Number.isFinite(numericValue) ? numericValue : 0;
    const safeCode = currencyCode || CONFIG.CURRENCY_CODE || 'MWK';
    const safeSymbol = currencySymbol || safeCode;

    return `${safeSymbol} ${safeValue.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    })}`;
}

function displayJourneyResults(results) {
    const container = document.getElementById('journeyResults');
    if (!container) return;

    const fuelConsumptionSource = results.fuelConsumptionSource || {
        label: 'Manual fuel consumption',
        detail: 'Custom value entered in the journey planner'
    };
    const fuelPriceMeta = results.fuelPriceMeta || {};
    const renderedAt = new Date().toLocaleString();
    const fuelTypeLabel = results.fuelType.charAt(0).toUpperCase() + results.fuelType.slice(1);
    const displayCurrencyCode = results.displayCurrencyCode || fuelPriceMeta.display_currency_code || CONFIG.CURRENCY_CODE || 'MWK';
    const displayCurrencySymbol = results.displayCurrencySymbol || fuelPriceMeta.display_currency_symbol || displayCurrencyCode;
    const displayPriceDecimals = displayCurrencyCode === 'USD' ? 4 : 2;
    const displayCostText = formatJourneyCurrency(results.fuelCost, displayCurrencyCode, displayCurrencySymbol, 2);
    const displayPriceText = formatJourneyCurrency(results.fuelPrice, displayCurrencyCode, displayCurrencySymbol, displayPriceDecimals);
    const primaryCostText = Number(results.fuelCostPrimary || 0) > 0
        ? formatJourneyCurrency(results.fuelCostPrimary, CONFIG.CURRENCY_CODE || 'MWK', CONFIG.CURRENCY_SYMBOL || CONFIG.CURRENCY_CODE || 'MWK', 2)
        : '';
    const primaryPriceText = Number(results.fuelPricePrimary || 0) > 0
        ? formatJourneyCurrency(results.fuelPricePrimary, CONFIG.CURRENCY_CODE || 'MWK', CONFIG.CURRENCY_SYMBOL || CONFIG.CURRENCY_CODE || 'MWK', 2)
        : '';
    const priceSourceLabel = fuelPriceMeta.source_label || 'Current fuel price feed';
    const pricePublishedDate = fuelPriceMeta.published_date || 'Not available';
    const priceSyncedAt = fuelPriceMeta.last_updated ? new Date(fuelPriceMeta.last_updated).toLocaleString() : 'Not available';
    const priceNotice = fuelPriceMeta.public_notice || 'Showing the latest resolved fuel price feed.';
    const showPrimaryComparison = displayCurrencyCode === 'USD' && primaryCostText !== '';
    const costSubtext = showPrimaryComparison && primaryCostText !== ''
        ? `Based on ${results.fuelNeeded.toFixed(2)} L at ${displayPriceText}/L. Local equivalent: ${primaryCostText}`
        : `Based on ${results.fuelNeeded.toFixed(2)} L at ${displayPriceText}/L`;
    const priceSubtext = showPrimaryComparison && primaryPriceText !== ''
        ? `${priceSourceLabel}. Local price: ${primaryPriceText}/L`
        : `${priceSourceLabel}. Published ${pricePublishedDate}`;
    
    container.style.display = 'block';
    container.innerHTML = `
        <div class="journey-result-shell">
            <div class="journey-result-header">
                <div>
                    <div class="journey-result-kicker">Trip estimate ready</div>
                    <h3><i class="fas fa-route"></i> Journey Cost Breakdown</h3>
                </div>
                <div class="journey-result-updated">${escapeHtml(renderedAt)}</div>
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
                    <strong class="journey-result-value journey-result-value-cost">${escapeHtml(displayCostText)}</strong>
                    <span class="journey-result-subtext">${escapeHtml(costSubtext)}</span>
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
                    <span class="journey-result-subtext">${escapeHtml(fuelConsumptionSource.label || 'Manual fuel consumption')}</span>
                </article>
                <article class="journey-result-card">
                    <span class="journey-result-label">Fuel price</span>
                    <strong class="journey-result-value">${escapeHtml(displayPriceText)}/L</strong>
                    <span class="journey-result-subtext">${escapeHtml(priceSubtext)}</span>
                </article>
            </div>

            <div class="journey-result-footer">
                <div class="journey-result-source">
                    <span class="journey-result-label">Fuel consumption source</span>
                    <strong>${escapeHtml(fuelConsumptionSource.label || 'Manual fuel consumption')}</strong>
                    <span class="journey-result-subtext">${escapeHtml(fuelConsumptionSource.detail || 'Custom value entered in the journey planner')}</span>
                </div>
                <div class="journey-result-meta">
                    <span class="journey-result-label">Fuel price data</span>
                    <strong>${escapeHtml(pricePublishedDate)}</strong>
                    <span class="journey-result-subtext">${escapeHtml(priceNotice)} Synced: ${escapeHtml(priceSyncedAt)}</span>
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

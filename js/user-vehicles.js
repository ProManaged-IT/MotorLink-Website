// ============================================================================
// User Vehicle Management JavaScript
// ============================================================================
// Handles adding, updating, and managing user vehicles for journey planning
// ============================================================================

let userVehicles = [];
let makes = [];
let models = [];
let vehicleFeatureAuthState = {
    checked: false,
    authenticated: false
};

const VEHICLE_FEATURE_TABS = new Set(['journey-planner', 'my-vehicles']);

document.addEventListener('DOMContentLoaded', async function() {
    // Initialize for both journey planner and my vehicles tabs
    const journeyPlannerTab = document.getElementById('journey-planner-tab');
    const myVehiclesTab = document.getElementById('my-vehicles-tab');

    if (!(journeyPlannerTab || myVehiclesTab)) {
        return;
    }

    updateVehicleFeatureLoginLinks();
    setupVehicleFormListeners();
    setupTabSwitching();

    const requestedTab = getRequestedVehicleFeatureTab();
    if (requestedTab) {
        await activateTab(requestedTab, { syncUrl: false, refreshAccess: false });
    }

    loadMakes();

    const isAuthenticated = await fetchVehicleFeatureAuth(true);
    if (isAuthenticated) {
        await loadUserVehicles();
    } else {
        clearUserVehicleState();
    }
});

function getCarDatabasePagePath() {
    const pageName = window.location.pathname.split('/').pop();
    return pageName || 'car-database.html';
}

function getRequestedVehicleFeatureTab() {
    const requestedTab = new URLSearchParams(window.location.search).get('tab') || '';
    return VEHICLE_FEATURE_TABS.has(requestedTab) ? requestedTab : '';
}

function getLoginUrlForVehicleFeature(tabName) {
    const redirectTarget = VEHICLE_FEATURE_TABS.has(tabName)
        ? `${getCarDatabasePagePath()}?tab=${encodeURIComponent(tabName)}`
        : getCarDatabasePagePath();

    return `login.html?redirect=${encodeURIComponent(redirectTarget)}`;
}

function updateVehicleFeatureLoginLinks() {
    const journeyLoginLink = document.getElementById('journeyLoginLink');
    if (journeyLoginLink) {
        journeyLoginLink.href = getLoginUrlForVehicleFeature('journey-planner');
    }

    const myVehiclesLoginLink = document.getElementById('myVehiclesLoginLink');
    if (myVehiclesLoginLink) {
        myVehiclesLoginLink.href = getLoginUrlForVehicleFeature('my-vehicles');
    }
}

function updateVehicleFeatureUrl(tabName) {
    if (!window.history || typeof window.history.replaceState !== 'function') {
        return;
    }

    const url = new URL(window.location.href);
    if (VEHICLE_FEATURE_TABS.has(tabName)) {
        url.searchParams.set('tab', tabName);
    } else {
        url.searchParams.delete('tab');
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

function clearUserVehicleState() {
    userVehicles = [];
    updateJourneyVehicleSelect();

    const vehiclesContainer = document.getElementById('userVehiclesList');
    if (vehiclesContainer) {
        vehiclesContainer.innerHTML = '';
    }
}

function applyJourneyPlannerAccess(isAuthenticated) {
    const loginPrompt = document.getElementById('journeyLoginPrompt');
    const plannerContent = document.getElementById('journeyPlannerContent');
    const journeyResults = document.getElementById('journeyResults');

    if (loginPrompt) {
        loginPrompt.style.display = isAuthenticated ? 'none' : 'block';
    }

    if (plannerContent) {
        plannerContent.style.display = isAuthenticated ? 'block' : 'none';
    }

    if (!isAuthenticated && journeyResults) {
        journeyResults.style.display = 'none';
    }
}

function applyMyVehiclesAccess(isAuthenticated) {
    const loginPrompt = document.getElementById('myVehiclesLoginPrompt');
    const content = document.getElementById('myVehiclesContent');
    const addVehicleButton = document.getElementById('myVehiclesAddVehicleBtn');

    if (loginPrompt) {
        loginPrompt.style.display = isAuthenticated ? 'none' : 'block';
    }

    if (content) {
        content.style.display = isAuthenticated ? 'block' : 'none';
    }

    if (addVehicleButton) {
        addVehicleButton.style.display = isAuthenticated ? 'inline-flex' : 'none';
    }
}

function applyVehicleFeatureAccess(isAuthenticated) {
    updateVehicleFeatureLoginLinks();
    applyJourneyPlannerAccess(isAuthenticated);
    applyMyVehiclesAccess(isAuthenticated);

    if (!isAuthenticated) {
        clearUserVehicleState();
    }
}

async function fetchVehicleFeatureAuth(forceRefresh = false) {
    if (vehicleFeatureAuthState.checked && !forceRefresh) {
        return vehicleFeatureAuthState.authenticated;
    }

    try {
        const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
            headers: { 'X-Skip-Global-Loader': '1' },
            ...(CONFIG.USE_CREDENTIALS && { credentials: 'include' })
        });
        const data = await response.json();

        vehicleFeatureAuthState = {
            checked: true,
            authenticated: !!(data.success && data.authenticated)
        };
    } catch (error) {
        vehicleFeatureAuthState = {
            checked: true,
            authenticated: false
        };
    }

    applyVehicleFeatureAccess(vehicleFeatureAuthState.authenticated);
    return vehicleFeatureAuthState.authenticated;
}

async function handleVehicleFeatureUnauthorized(feature = 'my-vehicles', redirectOnFail = false) {
    vehicleFeatureAuthState = {
        checked: true,
        authenticated: false
    };

    applyVehicleFeatureAccess(false);

    if (VEHICLE_FEATURE_TABS.has(feature)) {
        await activateTab(feature, { syncUrl: true, refreshAccess: false });
    }

    if (redirectOnFail) {
        window.location.href = getLoginUrlForVehicleFeature(feature);
    }
}

async function ensureVehicleFeatureAccess(feature = 'my-vehicles', options = {}) {
    const {
        forceRefresh = false,
        redirectOnFail = false
    } = options;

    const isAuthenticated = await fetchVehicleFeatureAuth(forceRefresh);
    if (isAuthenticated) {
        return true;
    }

    if (VEHICLE_FEATURE_TABS.has(feature)) {
        await activateTab(feature, { syncUrl: true, refreshAccess: false });
    }

    if (redirectOnFail) {
        window.location.href = getLoginUrlForVehicleFeature(feature);
    }

    return false;
}

window.ensureVehicleFeatureAccess = ensureVehicleFeatureAccess;
window.handleVehicleFeatureUnauthorized = handleVehicleFeatureUnauthorized;

function setFuelEstimateStatus(elementId, message, state = 'idle') {
    const statusElement = document.getElementById(elementId);
    if (!statusElement) {
        return;
    }

    statusElement.textContent = message;
    statusElement.dataset.state = state;
}

function toggleFuelEstimateButton(button, isLoading, loadingHtml) {
    if (!button) {
        return;
    }

    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
    }

    button.disabled = isLoading;
    button.innerHTML = isLoading ? loadingHtml : button.dataset.originalHtml;
}

function getSelectedOptionText(selectElement) {
    if (!selectElement || selectElement.selectedIndex < 0) {
        return '';
    }

    return (selectElement.options[selectElement.selectedIndex]?.textContent || '').trim();
}

function normalizeTransmissionSelection(transmissionValue) {
    const normalized = (transmissionValue || '').toString().trim().toLowerCase();

    if (!normalized) {
        return '';
    }

    if (normalized.includes('cvt') || normalized.includes('variable gear')) {
        return 'cvt';
    }

    if (normalized.includes('dct') || normalized.includes('dual clutch')) {
        return 'dct';
    }

    if (normalized.includes('semi') || normalized.includes('automated manual')) {
        return 'semi-automatic';
    }

    if (normalized.includes('manual') || normalized.startsWith('man ')) {
        return 'manual';
    }

    if (normalized.includes('auto')) {
        return 'automatic';
    }

    return normalized;
}

async function lookupOnlineFuelConsumptionEstimate(payload, feature = 'journey-planner') {
    const response = await fetch(`${CONFIG.API_URL}?action=lookup_online_fuel_consumption`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Skip-Global-Loader': '1'
        },
        credentials: 'include',
        body: JSON.stringify(payload)
    });

    if (response.status === 401) {
        await handleVehicleFeatureUnauthorized(feature);
        throw new Error('Please log in to fetch online fuel consumption estimates.');
    }

    const data = await response.json();
    if (!data.success || !data.estimate) {
        throw new Error(data.message || 'Unable to fetch an online fuel consumption estimate right now.');
    }

    return data;
}

window.lookupOnlineFuelConsumptionEstimate = lookupOnlineFuelConsumptionEstimate;

function getActiveVehicleFeatureTab() {
    return document.querySelector('.tab-btn.active[data-tab]')?.dataset.tab || 'vin-decoder';
}

async function activateTab(tabName, options = {}) {
    const {
        syncUrl = true,
        refreshAccess = true
    } = options;

    const tabButtons = document.querySelectorAll('.tab-btn[data-tab]');

    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });

    tabButtons.forEach(btn => btn.classList.remove('active'));

    const selectedTab = document.getElementById(`${tabName}-tab`);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }

    const selectedButton = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
    if (selectedButton) {
        selectedButton.classList.add('active');
    }

    if (syncUrl) {
        updateVehicleFeatureUrl(tabName);
    }

    if (refreshAccess && VEHICLE_FEATURE_TABS.has(tabName)) {
        const isAuthenticated = await fetchVehicleFeatureAuth(true);
        if (isAuthenticated && tabName === 'my-vehicles') {
            await loadUserVehiclesForDisplay();
        }
    }
}

function setupTabSwitching() {
    const tabButtons = document.querySelectorAll('.tab-btn[data-tab]');
    tabButtons.forEach(button => {
        button.addEventListener('click', async function() {
            await activateTab(this.dataset.tab);
        });
    });
}

async function checkAuthForMyVehicles(forceRefresh = false) {
    return fetchVehicleFeatureAuth(forceRefresh);
}

async function fetchUserVehiclesFromApi() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_user_vehicles`, {
            credentials: 'include',
            headers: { 'X-Skip-Global-Loader': '1' }
        });

        if (response.status === 401) {
            await handleVehicleFeatureUnauthorized('my-vehicles');
            return null;
        }

        const data = await response.json();

        if (data.success && Array.isArray(data.vehicles)) {
            return data.vehicles;
        }

        return [];
    } catch (error) {
        console.error('Error loading user vehicles:', error);
        return null;
    }
}

async function loadUserVehiclesForDisplay() {
    const isAuthenticated = await ensureVehicleFeatureAccess('my-vehicles');
    if (!isAuthenticated) {
        return false;
    }

    const vehicles = await fetchUserVehiclesFromApi();
    if (!vehicles) {
        return false;
    }

    userVehicles = vehicles;
    displayUserVehicles();
    return true;
}

function displayUserVehicles() {
    const container = document.getElementById('userVehiclesList');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (userVehicles.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px;">
                <i class="fas fa-car" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                <h3 style="color: #666; margin-bottom: 10px;">No Vehicles Added Yet</h3>
                <p style="color: #999; margin-bottom: 20px;">Add your first vehicle to start tracking journeys and fuel costs!</p>
                <button class="btn btn-primary" onclick="showAddVehicleModal()">
                    <i class="fas fa-plus"></i> Add Your First Vehicle
                </button>
            </div>
        `;
        return;
    }
    
    userVehicles.forEach(vehicle => {
        const vehicleCard = document.createElement('div');
        vehicleCard.className = 'vehicle-card';
        vehicleCard.style.cssText = 'background: white; border-radius: 8px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
        
        if (vehicle.is_primary) {
            vehicleCard.style.borderLeft = '4px solid #28a745';
        }
        
        const fuelTypeName = vehicle.fuel_type ? vehicle.fuel_type.charAt(0).toUpperCase() + vehicle.fuel_type.slice(1) : 'N/A';
        const engineSize = vehicle.engine_size_liters ? `${vehicle.engine_size_liters}L` : 'N/A';
        const transmission = vehicle.transmission ? vehicle.transmission.charAt(0).toUpperCase() + vehicle.transmission.slice(1) : 'N/A';
        const consumption = vehicle.fuel_consumption_liters_per_100km ? `${vehicle.fuel_consumption_liters_per_100km} L/100km` : 'N/A';
        const tankCapacity = vehicle.fuel_tank_capacity_liters ? `${vehicle.fuel_tank_capacity_liters}L` : 'N/A';
        
        vehicleCard.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                <div>
                    <h3 style="margin: 0 0 5px 0; color: #2c3e50;">
                        ${vehicle.make} ${vehicle.model}${vehicle.year ? ' (' + vehicle.year + ')' : ''}
                        ${vehicle.is_primary ? '<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; margin-left: 10px;">Primary</span>' : ''}
                    </h3>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn btn-sm btn-danger" onclick="deleteUserVehicle(${vehicle.id})" style="padding: 5px 10px;">
                        <i class="fas fa-trash"></i>
                    </button>
                    ${!vehicle.is_primary ? `<button class="btn btn-sm btn-secondary" onclick="setPrimaryVehicle(${vehicle.id})" style="padding: 5px 10px;"><i class="fas fa-star"></i></button>` : ''}
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-gas-pump"></i> Fuel Type</div>
                    <div style="font-weight: bold; color: #333;">${fuelTypeName}</div>
                </div>
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-cog"></i> Engine Size</div>
                    <div style="font-weight: bold; color: #333;">${engineSize}</div>
                </div>
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-cogs"></i> Transmission</div>
                    <div style="font-weight: bold; color: #333;">${transmission}</div>
                </div>
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-tachometer-alt"></i> Consumption</div>
                    <div style="font-weight: bold; color: #333;">${consumption}</div>
                </div>
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-tint"></i> Tank Capacity</div>
                    <div style="font-weight: bold; color: #333;">${tankCapacity}</div>
                </div>
                ${vehicle.vin ? `
                <div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-barcode"></i> VIN</div>
                    <div style="font-weight: bold; color: #333; font-family: monospace;">${vehicle.vin}</div>
                </div>
                ` : ''}
            </div>
        `;
        
        container.appendChild(vehicleCard);
    });
}

function setupVehicleFormListeners() {
    const makeSelect = document.getElementById('vehicleMake');
    const modelSelect = document.getElementById('vehicleModel');
    const onlineEstimateButton = document.getElementById('vehicleOnlineFuelEstimateBtn');
    const fuelConsumptionInput = document.getElementById('vehicleFuelConsumption');
    
    if (makeSelect) {
        makeSelect.addEventListener('change', function() {
            loadModels(this.value);
            const currentYearInput = document.getElementById('vehicleYear');
            if (currentYearInput) {
                currentYearInput.value = '';
                currentYearInput.disabled = true;
            }

             setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Select make, model, and year to fetch an official online estimate.', 'idle');
        });
    }
    
    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            loadYearsForModel(this.value);
            loadVehicleDetailsForModel(this.value);
        });
    }

    if (onlineEstimateButton) {
        onlineEstimateButton.addEventListener('click', handleVehicleOnlineFuelEstimate);
    }

    if (fuelConsumptionInput) {
        fuelConsumptionInput.addEventListener('input', function() {
            if (this.value.trim()) {
                setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Using a custom fuel consumption value. You can still fetch an official online estimate.', 'manual');
            } else {
                setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Auto-fill from the database or fetch an official online estimate.', 'idle');
            }
        });
    }
}

async function loadVehicleDetailsForModel(modelId) {
    if (!modelId) return;
    
    const modelSelect = document.getElementById('vehicleModel');
    if (!modelSelect) return;
    
    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    if (!selectedOption) return;
    
    // Get the selected model from the models array
    const selectedModel = models.find(model => String(model.id) === modelId);
    
    // Check for engine variations
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_model_engine_variations&model_id=${modelId}`, {
            headers: { 'X-Skip-Global-Loader': '1' }
        });
        const data = await response.json();
        
        const engineCapacityContainer = document.getElementById('vehicleEngineCapacity').parentElement;
        const engineCapacityInput = document.getElementById('vehicleEngineCapacity');
        
        if (data.success && data.variations && data.has_multiple && data.variations.length > 1) {
            // Multiple engine sizes - show dropdown
            if (engineCapacityInput && engineCapacityInput.tagName === 'INPUT') {
                const select = document.createElement('select');
                select.id = 'vehicleEngineCapacity';
                select.className = engineCapacityInput.className;
                select.name = engineCapacityInput.name;
                select.required = engineCapacityInput.required;
                
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '-- Select Engine Size --';
                select.appendChild(placeholder);
                
                data.variations.forEach(variation => {
                    const option = document.createElement('option');
                    option.value = variation.engine_size_liters;
                    option.textContent = `${variation.engine_size_liters}L`;
                    option.dataset.fuelConsumption = variation.fuel_consumption_combined_l100km || '';
                    option.dataset.fuelTankCapacity = variation.fuel_tank_capacity_liters || '';
                    option.dataset.transmission = variation.transmission_type || '';
                    select.appendChild(option);
                });
                
                engineCapacityInput.parentNode.replaceChild(select, engineCapacityInput);
                
                // Add change listener to update other fields when engine size changes
                select.addEventListener('change', function() {
                    const selectedVariation = data.variations.find(v => String(v.engine_size_liters) === this.value);
                    if (selectedVariation) {
                        updateFieldsFromVariation(selectedVariation);
                    }
                });
            } else if (engineCapacityInput && engineCapacityInput.tagName === 'SELECT') {
                // Already a select, just update options
                engineCapacityInput.innerHTML = '<option value="">-- Select Engine Size --</option>';
                data.variations.forEach(variation => {
                    const option = document.createElement('option');
                    option.value = variation.engine_size_liters;
                    option.textContent = `${variation.engine_size_liters}L`;
                    option.dataset.fuelConsumption = variation.fuel_consumption_combined_l100km || '';
                    option.dataset.fuelTankCapacity = variation.fuel_tank_capacity_liters || '';
                    option.dataset.transmission = variation.transmission_type || '';
                    engineCapacityInput.appendChild(option);
                });
            }
        } else {
            // Single or no engine size - show input field
            if (engineCapacityInput && engineCapacityInput.tagName === 'SELECT') {
                const input = document.createElement('input');
                input.type = 'number';
                input.id = 'vehicleEngineCapacity';
                input.className = engineCapacityInput.className;
                input.name = engineCapacityInput.name;
                input.required = engineCapacityInput.required;
                input.placeholder = 'e.g., 2.0';
                input.step = '0.1';
                input.min = '0';
                engineCapacityInput.parentNode.replaceChild(input, engineCapacityInput);
            }
            
            // Auto-fill Engine Capacity
            const engineCapacityField = document.getElementById('vehicleEngineCapacity');
            if (engineCapacityField && engineCapacityField.tagName === 'INPUT') {
                const engineSize = selectedModel?.engine_size_liters || selectedOption.dataset.engineSize;
                if (engineSize) {
                    engineCapacityField.value = parseFloat(engineSize).toFixed(2);
                } else {
                    engineCapacityField.value = '';
                }
            }
        }
    } catch (error) {
        console.error('Error loading engine variations:', error);
        // Fallback to single value
        const engineCapacityInput = document.getElementById('vehicleEngineCapacity');
        if (engineCapacityInput && engineCapacityInput.tagName === 'INPUT') {
            const engineSize = selectedModel?.engine_size_liters || selectedOption.dataset.engineSize;
            if (engineSize) {
                engineCapacityInput.value = parseFloat(engineSize).toFixed(2);
            } else {
                engineCapacityInput.value = '';
            }
        }
    }
    
    // Auto-fill other fields (will be updated if engine variation is selected)
    updateFieldsFromModel(selectedModel, selectedOption);
}

function updateFieldsFromModel(model, option) {
    // Auto-fill Fuel Consumption
    const fuelConsumptionInput = document.getElementById('vehicleFuelConsumption');
    if (fuelConsumptionInput) {
        const fuelConsumption = model?.fuel_consumption_combined_l100km || option?.dataset.fuelConsumption;
        if (fuelConsumption) {
            fuelConsumptionInput.value = parseFloat(fuelConsumption).toFixed(2);
            setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Auto-filled from the MotorLink vehicle database. You can override it or fetch an official online estimate.', 'database');
        } else {
            fuelConsumptionInput.value = '';
            setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Auto-fill unavailable for this model. Select year, then fetch an official online estimate.', 'idle');
        }
    }
    
    // Auto-fill Fuel Tank Capacity
    const fuelTankInput = document.getElementById('vehicleFuelTankCapacity');
    if (fuelTankInput) {
        const fuelTankCapacity = model?.fuel_tank_capacity_liters || option?.dataset.fuelTankCapacity;
        if (fuelTankCapacity) {
            fuelTankInput.value = parseFloat(fuelTankCapacity).toFixed(1);
        } else {
            fuelTankInput.value = '';
        }
    }
    
    // Auto-fill Transmission
    const transmissionSelect = document.getElementById('vehicleTransmission');
    if (transmissionSelect) {
        const transmission = model?.transmission_type || option?.dataset.transmission;
        if (transmission) {
            transmissionSelect.value = transmission.toLowerCase();
        } else {
            transmissionSelect.value = '';
        }
    }
}

function updateFieldsFromVariation(variation) {
    // Update fuel consumption
    const fuelConsumptionInput = document.getElementById('vehicleFuelConsumption');
    if (fuelConsumptionInput && variation.fuel_consumption_combined_l100km) {
        fuelConsumptionInput.value = parseFloat(variation.fuel_consumption_combined_l100km).toFixed(2);
        setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Updated from the selected engine variation in the MotorLink database.', 'database');
    }
    
    // Update fuel tank capacity
    const fuelTankInput = document.getElementById('vehicleFuelTankCapacity');
    if (fuelTankInput && variation.fuel_tank_capacity_liters) {
        fuelTankInput.value = parseFloat(variation.fuel_tank_capacity_liters).toFixed(1);
    }
    
    // Update transmission
    const transmissionSelect = document.getElementById('vehicleTransmission');
    if (transmissionSelect && variation.transmission_type) {
        transmissionSelect.value = variation.transmission_type.toLowerCase();
    }
}

function loadFuelTankCapacityForModel(modelId) {
    // This function is kept for backwards compatibility but the main logic is in loadVehicleDetailsForModel
    const fuelTankInput = document.getElementById('vehicleFuelTankCapacity');
    if (!fuelTankInput || !modelId) return;
    
    // Get the selected model from the models array
    const selectedModel = models.find(model => String(model.id) === modelId);
    if (selectedModel && selectedModel.fuel_tank_capacity_liters) {
        fuelTankInput.value = parseFloat(selectedModel.fuel_tank_capacity_liters).toFixed(1);
    } else {
        // Try to get from option dataset
        const modelSelect = document.getElementById('vehicleModel');
        if (modelSelect) {
            const selectedOption = modelSelect.options[modelSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.fuelTankCapacity) {
                fuelTankInput.value = parseFloat(selectedOption.dataset.fuelTankCapacity).toFixed(1);
            } else {
                // Clear if no data available
                fuelTankInput.value = '';
            }
        }
    }
}

function loadYearsForModel(modelId) {
    const yearInput = document.getElementById('vehicleYear');
    if (!yearInput || !modelId) {
        if (yearInput) yearInput.disabled = true;
        return;
    }
    
    const modelSelect = document.getElementById('vehicleModel');
    if (!modelSelect) return;
    
    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    const yearStart = selectedOption ? selectedOption.dataset.yearStart : null;
    const yearEnd = selectedOption ? selectedOption.dataset.yearEnd : null;
    
    if (yearStart) {
        const startYear = parseInt(yearStart);
        const endYear = yearEnd ? parseInt(yearEnd) : new Date().getFullYear();
        const currentYear = new Date().getFullYear();
        
        // Convert year input to select if it's not already
        if (yearInput.tagName === 'INPUT') {
            const select = document.createElement('select');
            select.id = 'vehicleYear';
            select.className = yearInput.className;
            select.name = yearInput.name;
            
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select Year (Optional)';
            select.appendChild(placeholder);
            
            for (let year = Math.min(endYear, currentYear); year >= startYear; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                select.appendChild(option);
            }
            
            yearInput.parentNode.replaceChild(select, yearInput);
        } else {
            yearInput.innerHTML = '<option value="">Select Year (Optional)</option>';
            for (let year = Math.min(endYear, currentYear); year >= startYear; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearInput.appendChild(option);
            }
        }
        
        yearInput.disabled = false;
    } else {
        // If no year info, keep as input
        if (yearInput.tagName === 'SELECT') {
            const input = document.createElement('input');
            input.type = 'number';
            input.id = 'vehicleYear';
            input.className = yearInput.className;
            input.name = yearInput.name;
            input.placeholder = 'Enter Year (Optional)';
            input.min = '1900';
            input.max = new Date().getFullYear().toString();
            yearInput.parentNode.replaceChild(input, yearInput);
        }
        yearInput.disabled = false;
    }
}

async function loadMakes() {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_makes`, {
            headers: { 'X-Skip-Global-Loader': '1' }
        });
        const data = await response.json();
        
        if (data.success && data.makes) {
            makes = data.makes;
            const makeSelect = document.getElementById('vehicleMake');
            if (makeSelect) {
                makeSelect.innerHTML = '<option value="">-- Select Make --</option>';
                makes.forEach(make => {
                    const option = document.createElement('option');
                    option.value = make.id;
                    option.textContent = make.name;
                    makeSelect.appendChild(option);
                });
            }
        }
    } catch (error) {
        console.error('Error loading makes:', error);
    }
}

async function loadModels(makeId) {
    if (!makeId) {
        const modelSelect = document.getElementById('vehicleModel');
        if (modelSelect) {
            modelSelect.innerHTML = '<option value="">-- Select Make First --</option>';
            modelSelect.disabled = true;
        }
        const yearInput = document.getElementById('vehicleYear');
        if (yearInput) {
            yearInput.value = '';
            yearInput.disabled = true;
        }
        setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Select make, model, and year to fetch an official online estimate.', 'idle');
        return;
    }
    
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=get_models&make_id=${makeId}`, {
            headers: { 'X-Skip-Global-Loader': '1' }
        });
        const data = await response.json();
        
        if (data.success && data.models) {
            models = data.models;
            const modelSelect = document.getElementById('vehicleModel');
            if (modelSelect) {
                modelSelect.innerHTML = '<option value="">-- Select Model --</option>';
                models.forEach(model => {
                    const option = document.createElement('option');
                    option.value = model.id;
                    option.textContent = model.name;
                    // Store model data for auto-filling fields
                    option.dataset.yearStart = model.year_start || '';
                    option.dataset.yearEnd = model.year_end || '';
                    option.dataset.fuelTankCapacity = model.fuel_tank_capacity_liters || '';
                    option.dataset.engineSize = model.engine_size_liters || '';
                    option.dataset.fuelConsumption = model.fuel_consumption_combined_l100km || '';
                    option.dataset.transmission = model.transmission_type || '';
                    modelSelect.appendChild(option);
                });
                modelSelect.disabled = false;
            }
            
            // Reset year field
            const yearInput = document.getElementById('vehicleYear');
            if (yearInput) {
                yearInput.value = '';
                yearInput.disabled = true;
            }
            
            // Reset fuel tank capacity field
            const fuelTankInput = document.getElementById('vehicleFuelTankCapacity');
            if (fuelTankInput) {
                fuelTankInput.value = '';
            }

            const fuelConsumptionInput = document.getElementById('vehicleFuelConsumption');
            if (fuelConsumptionInput) {
                fuelConsumptionInput.value = '';
            }

            setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Choose a model to auto-fill from the database or fetch an official online estimate.', 'idle');
        }
    } catch (error) {
        console.error('Error loading models:', error);
    }
}

async function loadUserVehicles() {
    const isAuthenticated = await ensureVehicleFeatureAccess(getActiveVehicleFeatureTab() === 'journey-planner' ? 'journey-planner' : 'my-vehicles');
    if (!isAuthenticated) {
        return false;
    }

    const vehicles = await fetchUserVehiclesFromApi();
    if (!vehicles) {
        return false;
    }

    userVehicles = vehicles;
    updateJourneyVehicleSelect();

    const myVehiclesTab = document.getElementById('my-vehicles-tab');
    if (myVehiclesTab && myVehiclesTab.classList.contains('active')) {
        displayUserVehicles();
    }

    return true;
}

function updateJourneyVehicleSelect() {
    const vehicleSelect = document.getElementById('journeyVehicle');
    if (!vehicleSelect) return;
    
    // Clear existing options except the first one
    vehicleSelect.innerHTML = '<option value="">-- Select Vehicle or Enter Details --</option>';
    
    userVehicles.forEach(vehicle => {
        const option = document.createElement('option');
        option.value = vehicle.id;
        option.textContent = `${vehicle.make} ${vehicle.model}${vehicle.year ? ' (' + vehicle.year + ')' : ''}`;
        option.dataset.fuelType = vehicle.fuel_type || 'petrol';
        option.dataset.fuelConsumption = vehicle.fuel_consumption_liters_per_100km || '';
        option.dataset.make = vehicle.make || '';
        option.dataset.model = vehicle.model || '';
        option.dataset.year = vehicle.year || '';
        option.dataset.engineSize = vehicle.engine_size_liters || '';
        option.dataset.transmission = vehicle.transmission || '';
        
        // Mark primary vehicle
        if (vehicle.is_primary) {
            option.textContent += ' ★';
            option.selected = true;
        }
        
        vehicleSelect.appendChild(option);
    });
    
    // Trigger change event to update fuel consumption field
    if (vehicleSelect.selectedIndex > 0) {
        const event = new Event('change');
        vehicleSelect.dispatchEvent(event);
    }
}

async function handleVehicleOnlineFuelEstimate() {
    const button = document.getElementById('vehicleOnlineFuelEstimateBtn');
    const makeSelect = document.getElementById('vehicleMake');
    const modelSelect = document.getElementById('vehicleModel');
    const yearInput = document.getElementById('vehicleYear');
    const fuelConsumptionInput = document.getElementById('vehicleFuelConsumption');
    const transmissionSelect = document.getElementById('vehicleTransmission');
    const engineCapacityInput = document.getElementById('vehicleEngineCapacity');

    const make = getSelectedOptionText(makeSelect);
    const model = getSelectedOptionText(modelSelect);
    const year = parseInt(yearInput?.value || '', 10);

    if (!makeSelect?.value || !modelSelect?.value) {
        alert('Select the vehicle make and model first.');
        return;
    }

    if (!year) {
        alert('Select or enter the vehicle year first. The online estimate needs a model year.');
        return;
    }

    toggleFuelEstimateButton(button, true, '<i class="fas fa-spinner fa-spin"></i> Looking up...');
    setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Looking up the official combined fuel economy estimate online...', 'loading');

    try {
        const result = await lookupOnlineFuelConsumptionEstimate({
            year,
            make,
            model,
            transmission: transmissionSelect?.value || '',
            engine_size_liters: engineCapacityInput?.value ? parseFloat(engineCapacityInput.value) : null
        }, 'my-vehicles');

        const estimate = result.estimate;
        if (fuelConsumptionInput) {
            fuelConsumptionInput.value = Number(estimate.fuel_consumption_l100km || 0).toFixed(2);
        }

        if (transmissionSelect && !transmissionSelect.value && estimate.transmission) {
            transmissionSelect.value = normalizeTransmissionSelection(estimate.transmission);
        }

        setFuelEstimateStatus(
            'vehicleFuelEstimateStatus',
            `Official estimate applied from ${estimate.source}: ${estimate.matched_option} (${estimate.combined_mpg} MPG combined).`,
            'online'
        );
    } catch (error) {
        console.error('Error fetching vehicle online fuel estimate:', error);
        setFuelEstimateStatus('vehicleFuelEstimateStatus', error.message || 'Failed to fetch the online estimate.', 'error');
        alert(error.message || 'Failed to fetch the online estimate.');
    } finally {
        toggleFuelEstimateButton(button, false, '');
    }
}

window.showAddVehicleModal = async function() {
    const activeTab = getActiveVehicleFeatureTab();
    const protectedFeature = activeTab === 'journey-planner' ? 'journey-planner' : 'my-vehicles';
    const isAuthenticated = await ensureVehicleFeatureAccess(protectedFeature, {
        forceRefresh: true,
        redirectOnFail: true
    });

    if (!isAuthenticated) {
        return;
    }

    const modalElement = document.getElementById('addVehicleModal');
    if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    modal.show();
};

window.addUserVehicle = async function(event) {
    event.preventDefault();
    
    const makeId = document.getElementById('vehicleMake').value;
    const modelId = document.getElementById('vehicleModel').value;
    const yearElement = document.getElementById('vehicleYear');
    const year = yearElement ? yearElement.value : '';
    const vin = document.getElementById('vehicleVin').value;
    const transmissionSelect = document.getElementById('vehicleTransmission');
    const transmission = transmissionSelect ? transmissionSelect.value : '';
    const engineCapacityEl = document.getElementById('vehicleEngineCapacity');
    const engineCapacity = engineCapacityEl ? engineCapacityEl.value : '';
    const fuelConsumptionEl = document.getElementById('vehicleFuelConsumption');
    const fuelConsumption = fuelConsumptionEl ? fuelConsumptionEl.value : '';
    const fuelTankCapacityEl = document.getElementById('vehicleFuelTankCapacity');
    const fuelTankCapacity = fuelTankCapacityEl ? fuelTankCapacityEl.value : '';
    const isPrimary = document.getElementById('vehicleIsPrimary').checked;
    
    if (!makeId || !modelId) {
        alert('Please select both Make and Model');
        return;
    }
    
    if (!engineCapacity || parseFloat(engineCapacity) <= 0) {
        alert('Please enter a valid engine capacity');
        return;
    }
    
    if (!fuelConsumption || parseFloat(fuelConsumption) <= 0) {
        alert('Please enter a valid fuel consumption');
        return;
    }
    
    if (!fuelTankCapacity || parseFloat(fuelTankCapacity) <= 0) {
        alert('Please enter a valid fuel tank capacity');
        return;
    }
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=add_user_vehicle`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                make_id: makeId,
                model_id: modelId,
                year: year || null,
                vin: vin || null,
                transmission: transmission || null,
                engine_size_liters: engineCapacity ? parseFloat(engineCapacity) : null,
                fuel_consumption_liters_per_100km: fuelConsumption ? parseFloat(fuelConsumption) : null,
                fuel_tank_capacity_liters: fuelTankCapacity ? parseFloat(fuelTankCapacity) : null,
                is_primary: isPrimary
            })
        });

        if (response.status === 401) {
            await handleVehicleFeatureUnauthorized('my-vehicles', true);
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Reset form
            event.target.reset();
            document.getElementById('vehicleModel').disabled = true;
            document.getElementById('vehicleModel').innerHTML = '<option value="">-- Select Make First --</option>';
            const yearInput = document.getElementById('vehicleYear');
            if (yearInput) {
                yearInput.disabled = true;
                if (yearInput.tagName === 'SELECT') {
                    yearInput.innerHTML = '<option value="">Select Year (Optional)</option>';
                }
            }
            setFuelEstimateStatus('vehicleFuelEstimateStatus', 'Auto-fill from the database or fetch an official online estimate.', 'idle');
            
            // Reload vehicles
            await loadUserVehicles();
            
            // Close modal if exists
            const modal = document.getElementById('addVehicleModal');
            if (modal) {
                const bootstrapModal = bootstrap.Modal.getInstance(modal);
                if (bootstrapModal) {
                    bootstrapModal.hide();
                }
            }
            
            alert('Vehicle added successfully!');
        } else {
            alert(data.message || 'Failed to add vehicle');
        }
    } catch (error) {
        console.error('Error adding vehicle:', error);
        alert('Failed to add vehicle. Please try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

async function deleteUserVehicle(vehicleId) {
    if (!confirm('Are you sure you want to delete this vehicle?')) {
        return;
    }
    
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=delete_user_vehicle&vehicle_id=${vehicleId}`, {
            credentials: 'include'
        });

        if (response.status === 401) {
            await handleVehicleFeatureUnauthorized('my-vehicles', true);
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            await loadUserVehicles();
            alert('Vehicle deleted successfully!');
        } else {
            alert(data.message || 'Failed to delete vehicle');
        }
    } catch (error) {
        console.error('Error deleting vehicle:', error);
        alert('Failed to delete vehicle. Please try again.');
    }
}

async function setPrimaryVehicle(vehicleId) {
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=set_primary_vehicle&vehicle_id=${vehicleId}`, {
            credentials: 'include'
        });

        if (response.status === 401) {
            await handleVehicleFeatureUnauthorized('my-vehicles', true);
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            await loadUserVehicles();
        } else {
            alert(data.message || 'Failed to set primary vehicle');
        }
    } catch (error) {
        console.error('Error setting primary vehicle:', error);
        alert('Failed to set primary vehicle. Please try again.');
    }
}


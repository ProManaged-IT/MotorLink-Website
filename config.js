// MotorLink Malawi - Configuration
// ============================================================================
// ENVIRONMENT CONFIGURATION
// ============================================================================
//
// AUTOMATIC ENVIRONMENT DETECTION:
// The system automatically detects the environment based on the hostname.
// - Production: promanaged-it.com domain
// - Local Development: localhost/127.0.0.1
// - UAT/Staging: All other environments (Codespaces, staging servers, etc.)
//
// You can still manually override by setting MODE to 'PRODUCTION' or 'UAT'
// ============================================================================

// ========== ENVIRONMENT DETECTION ==========
const DEBUG = false;  // Set to true to enable console logs for debugging

// MANUAL OVERRIDE: Set to 'UAT' or 'PRODUCTION' to force a specific mode
// Leave as null for automatic detection
const MANUAL_MODE_OVERRIDE = null;  // Auto-detect based on hostname

// Detect environment automatically
const hostname = window.location.hostname;
const protocol = window.location.protocol;
const port = window.location.port;
const fullHost = window.location.host; // includes port

// Helper: Check if IP is a local/private network address
const isLocalNetworkIP = (host) => {
    // Remove port if present
    const ip = host.split(':')[0];
    
    // Check for localhost variants
    if (ip === 'localhost' || ip === '127.0.0.1' || ip === '::1') {
        return true;
    }
    
    // Check for private IP ranges (IPv4)
    const privateIPv4Patterns = [
        /^10\./,                    // 10.0.0.0 - 10.255.255.255
        /^172\.(1[6-9]|2[0-9]|3[0-1])\./, // 172.16.0.0 - 172.31.255.255
        /^192\.168\./,              // 192.168.0.0 - 192.168.255.255
        /^169\.254\./               // 169.254.0.0 - 169.254.255.255 (link-local)
    ];
    
    return privateIPv4Patterns.some(pattern => pattern.test(ip));
};

// Check for different environment types
const isLocalNetwork = isLocalNetworkIP(hostname);
const isLocal = hostname === 'localhost' ||
                hostname === '127.0.0.1' ||
                isLocalNetwork ||
                protocol === 'file:';
const isCodespaces = hostname.includes('github.dev') ||
                     hostname.includes('codespaces') ||
                     hostname.includes('preview.app');
const isCloudIDE = isCodespaces ||
                   hostname.includes('gitpod') ||
                   hostname.includes('replit') ||
                   hostname.includes('stackblitz');
// Production: Any non-localhost hostname (flexible for any domain)
const isProduction = !isLocal && !isCloudIDE && hostname !== '' && 
                     !hostname.includes('localhost') && 
                     !hostname.includes('127.0.0.1') &&
                     !isLocalNetworkIP(hostname);

// Auto-detect MODE based on hostname (or use manual override)
const MODE = MANUAL_MODE_OVERRIDE || (isProduction ? 'PRODUCTION' : 'UAT');

const isDevelopment = isLocal || isCloudIDE;

// Production API endpoint (used for remote API calls)
const PRODUCTION_API = 'https://promanaged-it.com/motorlink/api.php';
const PRODUCTION_BASE = 'https://promanaged-it.com/motorlink/';

// Determine the API URL based on environment
const getAPIUrl = () => {
    // Production environment: use local api.php (same server)
    if (isProduction) {
        return '/motorlink/api.php';
    }

    // Local development on static ports (e.g. Live Server 5500):
    // force API calls to the local PHP server so localhost testing works.
    if (isLocal && protocol !== 'file:' && port && port !== '80' && port !== '443' && port !== '8000') {
        return `${window.location.protocol}//${window.location.hostname}:8000/api.php`;
    }

    if (protocol === 'file:') {
        return 'http://127.0.0.1:8000/api.php';
    }

    // UAT/Local development: ALWAYS use local api.php
    // Never reference promanaged.com in development
    return 'api.php';
};

// Dedicated recommendation endpoint URL
const getRecommendationApiUrl = () => {
    // Production environment: same server path
    if (isProduction) {
        return '/motorlink/recommendation_engine.php';
    }

    // Local development with static servers should target local PHP server
    if (isLocal && protocol !== 'file:' && port && port !== '80' && port !== '443' && port !== '8000') {
        return `${window.location.protocol}//${window.location.hostname}:8000/recommendation_engine.php`;
    }

    if (protocol === 'file:') {
        return 'http://127.0.0.1:8000/recommendation_engine.php';
    }

    // UAT/Local development: relative path
    return 'recommendation_engine.php';
};

// Determine the base URL
const getBaseURL = () => {
    // Production: use relative paths (same server)
    if (isProduction) {
        return '/motorlink/';
    }

    // Cloud IDEs: use production base URL for assets
    if (isCloudIDE) {
        return PRODUCTION_BASE;
    }

    // Local and other UAT: use relative paths
    return '';
};

// Export configuration
const CONFIG = {
    MODE: MODE,
    DEBUG: DEBUG,
    BASE_URL: getBaseURL(),
    API_URL: getAPIUrl(),
    RECOMMENDATION_API_URL: getRecommendationApiUrl(),
    SITE_NAME: 'MotorLink Malawi',
    VERSION: '4.1.0',
    // Always use credentials for session management
    USE_CREDENTIALS: true,
    // Loaded at runtime from secure backend settings endpoint.
    GOOGLE_MAPS_API_KEY: null,
    GOOGLE_MAPS_MAP_ID: null
};

let __runtimeMapConfigPromise = null;

async function getGoogleMapsConfig() {
    if (CONFIG.GOOGLE_MAPS_API_KEY) {
        return {
            apiKey: CONFIG.GOOGLE_MAPS_API_KEY,
            mapId: CONFIG.GOOGLE_MAPS_MAP_ID
        };
    }

    if (!__runtimeMapConfigPromise) {
        __runtimeMapConfigPromise = (async () => {
            try {
                const response = await fetch(`${CONFIG.API_URL}?action=get_public_client_config`, {
                    method: 'GET',
                    credentials: CONFIG.USE_CREDENTIALS ? 'include' : 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`Failed to load runtime config (HTTP ${response.status})`);
                }

                const data = await response.json();
                if (!data || !data.success || !data.config || !data.config.google_maps_api_key) {
                    throw new Error('Google Maps runtime config is missing');
                }

                CONFIG.GOOGLE_MAPS_API_KEY = data.config.google_maps_api_key;
                CONFIG.GOOGLE_MAPS_MAP_ID = data.config.google_maps_map_id || null;

                return {
                    apiKey: CONFIG.GOOGLE_MAPS_API_KEY,
                    mapId: CONFIG.GOOGLE_MAPS_MAP_ID
                };
            } catch (error) {
                __runtimeMapConfigPromise = null;
                throw error;
            }
        })();
    }

    return __runtimeMapConfigPromise;
}

window.getGoogleMapsConfig = getGoogleMapsConfig;

// Debug: Show which configuration is being used (only if DEBUG is enabled)
if (DEBUG || MANUAL_MODE_OVERRIDE) {
    console.log('=== MotorLink Configuration ===');
    console.log('MODE:', CONFIG.MODE);
    if (MANUAL_MODE_OVERRIDE) {
        console.warn('⚠️ MANUAL MODE OVERRIDE ACTIVE:', MANUAL_MODE_OVERRIDE);
    }
    console.log('Hostname:', hostname);
    console.log('Protocol:', protocol);
    console.log('Environment Detection:');
    console.log('  - isProduction:', isProduction);
    console.log('  - isLocal:', isLocal);
    console.log('  - isCloudIDE:', isCloudIDE);
    console.log('  - isCodespaces:', isCodespaces);
    console.log('  - isDevelopment:', isDevelopment);
    console.log('BASE_URL:', CONFIG.BASE_URL);
    console.log('API_URL:', CONFIG.API_URL);
    console.log('USE_CREDENTIALS:', CONFIG.USE_CREDENTIALS);
    console.log('================================');
}

// Note: Do not freeze CONFIG because runtime secure settings are injected after page load.

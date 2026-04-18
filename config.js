// MotorLink Runtime Configuration
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

const DEFAULT_PUBLIC_SITE_CONFIG = {
    site_name: 'MotorLink',
    site_short_name: 'MotorLink',
    site_tagline: 'Your trusted vehicle marketplace',
    site_description: 'MotorLink helps people buy, sell, hire, and manage vehicles in one place.',
    site_url: '',
    country_name: '',
    country_code: '',
    country_demonym: '',
    locale: 'en',
    currency_code: 'LOCAL',
    currency_symbol: 'LOCAL',
    market_scope_label: 'nationwide',
    contact_support_email: 'support@example.com',
    fuel_price_country_slug: '',
    geo_region: '',
    geo_placename: '',
    geo_position: '',
    icbm: ''
};

function getDefaultPublicSiteUrl() {
    if (isProduction) {
        return `${window.location.origin}/motorlink`;
    }

    if (isLocal && protocol !== 'file:' && port && port !== '80' && port !== '443' && port !== '8000') {
        return `${window.location.protocol}//${window.location.hostname}:8000`;
    }

    if (protocol === 'file:') {
        return 'http://127.0.0.1:8000';
    }

    return window.location.origin || '';
}

function normalizePublicSiteUrl(url) {
    const raw = String(url || '').trim();
    const fallback = getDefaultPublicSiteUrl();

    if (!raw) {
        return fallback;
    }

    return raw.replace(/\/+$/, '') || fallback;
}

function getRuntimeSiteHost() {
    const runtimeUrl = normalizePublicSiteUrl(CONFIG.SITE_URL || getDefaultPublicSiteUrl());

    try {
        return new URL(`${runtimeUrl}/`).host || 'example.com';
    } catch (error) {
        return 'example.com';
    }
}

function getCountryPossessiveLabel() {
    const countryName = String(CONFIG.COUNTRY_NAME || '').trim();
    if (!countryName) {
        return 'the market\'s';
    }

    return /s$/i.test(countryName) ? `${countryName}'` : `${countryName}'s`;
}

function getPluralCountryDemonym() {
    const demonym = String(CONFIG.COUNTRY_DEMONYM || '').trim();
    if (!demonym) {
        return 'local drivers';
    }

    return /s$/i.test(demonym) ? demonym : `${demonym}s`;
}

function replaceBrandingTokens(value) {
    let text = String(value || '');
    if (!text) {
        return text;
    }

    text = text.replace(/MotorLink Malawi/gi, CONFIG.SITE_NAME || DEFAULT_PUBLIC_SITE_CONFIG.site_name);
    text = text.replace(/\bMotorLink\b/gi, CONFIG.SITE_SHORT_NAME || CONFIG.SITE_NAME || DEFAULT_PUBLIC_SITE_CONFIG.site_short_name);
    text = text.replace(/\bMalawian\b/gi, CONFIG.COUNTRY_DEMONYM || DEFAULT_PUBLIC_SITE_CONFIG.country_demonym);
    text = text.replace(/\bMalawi\b/gi, CONFIG.COUNTRY_NAME || DEFAULT_PUBLIC_SITE_CONFIG.country_name);
    text = text.replace(/\bMWK\b/g, CONFIG.CURRENCY_CODE || DEFAULT_PUBLIC_SITE_CONFIG.currency_code);

    return text;
}

function replaceLegacySiteUrls(value) {
    let text = String(value || '');
    if (!text) {
        return text;
    }

    const runtimeSiteUrl = normalizePublicSiteUrl(CONFIG.SITE_URL || getDefaultPublicSiteUrl());
    const legacyUrls = [
        'https://promanaged-it.com/motorlink',
        'http://promanaged-it.com/motorlink'
    ];

    legacyUrls.forEach((legacyUrl) => {
        text = text.split(legacyUrl).join(runtimeSiteUrl);
    });

    return text;
}

function replaceRuntimeText(value) {
    let text = replaceLegacySiteUrls(value);
    text = replaceBrandingTokens(text);

    text = text.replace(/\bMalawi's\b/gi, getCountryPossessiveLabel());
    text = text.replace(/\bMalawians\b/gi, getPluralCountryDemonym());
    text = text.replace(/\ball 28 districts of Malawi\b/gi, String(CONFIG.MARKET_SCOPE_LABEL || '').trim() || 'the selected market');
    text = text.replace(/\ball 28 districts\b/gi, String(CONFIG.MARKET_SCOPE_LABEL || '').trim() || 'the selected market');
    text = text.replace(/\bmotorlink\.mw\b/gi, getRuntimeSiteHost());

    if (CONFIG.LOCALE) {
        text = text.replace(/\ben_MW\b/g, CONFIG.LOCALE.replace(/-/g, '_'));
        text = text.replace(/\ben-MW\b/g, CONFIG.LOCALE);
    }

    return text;
}

function shouldSkipRuntimeTextNode(node) {
    const parent = node && node.parentElement;
    if (!parent) {
        return true;
    }

    if (parent.closest('[data-runtime-branding-skip]')) {
        return true;
    }

    const blockedTags = ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION'];
    return blockedTags.includes(parent.tagName);
}

function applyRuntimeTextToBody(root = document.body) {
    if (!root) {
        return;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    const textNodes = [];
    let currentNode = walker.nextNode();

    while (currentNode) {
        if (!shouldSkipRuntimeTextNode(currentNode) && currentNode.nodeValue && currentNode.nodeValue.trim()) {
            textNodes.push(currentNode);
        }
        currentNode = walker.nextNode();
    }

    textNodes.forEach((node) => {
        const updated = replaceRuntimeText(node.nodeValue);
        if (updated !== node.nodeValue) {
            node.nodeValue = updated;
        }
    });

    root.querySelectorAll('[title], [aria-label], [placeholder], [alt]').forEach((element) => {
        ['title', 'aria-label', 'placeholder', 'alt'].forEach((attribute) => {
            const value = element.getAttribute(attribute);
            if (!value) {
                return;
            }

            const updated = replaceRuntimeText(value);
            if (updated !== value) {
                element.setAttribute(attribute, updated);
            }
        });
    });

    root.querySelectorAll('input[type="button"], input[type="submit"], input[type="reset"], button[value]').forEach((element) => {
        const value = element.getAttribute('value');
        if (!value) {
            return;
        }

        const updated = replaceRuntimeText(value);
        if (updated !== value) {
            element.setAttribute('value', updated);
        }
    });
}

function transformStructuredDataNode(node) {
    if (Array.isArray(node)) {
        return node.map(transformStructuredDataNode);
    }

    if (node && typeof node === 'object') {
        const transformed = {};
        Object.entries(node).forEach(([key, value]) => {
            transformed[key] = transformStructuredDataNode(value);
        });
        return transformed;
    }

    if (typeof node === 'string') {
        return replaceRuntimeText(node);
    }

    return node;
}

function applyRuntimeSiteConfig(runtimeConfig = {}) {
    const merged = {
        ...DEFAULT_PUBLIC_SITE_CONFIG,
        ...(runtimeConfig || {})
    };

    CONFIG.SITE_NAME = merged.site_name || DEFAULT_PUBLIC_SITE_CONFIG.site_name;
    CONFIG.SITE_SHORT_NAME = merged.site_short_name || CONFIG.SITE_NAME;
    CONFIG.SITE_TAGLINE = merged.site_tagline || DEFAULT_PUBLIC_SITE_CONFIG.site_tagline;
    CONFIG.SITE_DESCRIPTION = merged.site_description || DEFAULT_PUBLIC_SITE_CONFIG.site_description;
    CONFIG.SITE_URL = normalizePublicSiteUrl(merged.site_url);
    CONFIG.COUNTRY_NAME = merged.country_name || DEFAULT_PUBLIC_SITE_CONFIG.country_name;
    CONFIG.COUNTRY_CODE = merged.country_code || DEFAULT_PUBLIC_SITE_CONFIG.country_code;
    CONFIG.COUNTRY_DEMONYM = merged.country_demonym || DEFAULT_PUBLIC_SITE_CONFIG.country_demonym;
    CONFIG.LOCALE = merged.locale || DEFAULT_PUBLIC_SITE_CONFIG.locale;
    CONFIG.CURRENCY_CODE = merged.currency_code || DEFAULT_PUBLIC_SITE_CONFIG.currency_code;
    CONFIG.CURRENCY_SYMBOL = merged.currency_symbol || merged.currency_code || DEFAULT_PUBLIC_SITE_CONFIG.currency_symbol;
    CONFIG.MARKET_SCOPE_LABEL = merged.market_scope_label || DEFAULT_PUBLIC_SITE_CONFIG.market_scope_label;
    CONFIG.SUPPORT_EMAIL = merged.contact_support_email || DEFAULT_PUBLIC_SITE_CONFIG.contact_support_email;
    CONFIG.FUEL_PRICE_COUNTRY_SLUG = merged.fuel_price_country_slug || '';
    CONFIG.GEO_REGION = merged.geo_region || '';
    CONFIG.GEO_PLACENAME = merged.geo_placename || '';
    CONFIG.GEO_POSITION = merged.geo_position || '';
    CONFIG.ICBM = merged.icbm || '';

    if (merged.google_maps_api_key) {
        CONFIG.GOOGLE_MAPS_API_KEY = merged.google_maps_api_key;
    }
    if (Object.prototype.hasOwnProperty.call(merged, 'google_maps_map_id')) {
        CONFIG.GOOGLE_MAPS_MAP_ID = merged.google_maps_map_id || null;
    }

    applyBrandingToDocument();

    window.dispatchEvent(new CustomEvent('motorlink:site-config-ready', {
        detail: getPublicSiteConfigSnapshot()
    }));
}

function getPublicSiteConfigSnapshot() {
    return {
        site_name: CONFIG.SITE_NAME,
        site_short_name: CONFIG.SITE_SHORT_NAME,
        site_tagline: CONFIG.SITE_TAGLINE,
        site_description: CONFIG.SITE_DESCRIPTION,
        site_url: CONFIG.SITE_URL,
        country_name: CONFIG.COUNTRY_NAME,
        country_code: CONFIG.COUNTRY_CODE,
        country_demonym: CONFIG.COUNTRY_DEMONYM,
        locale: CONFIG.LOCALE,
        currency_code: CONFIG.CURRENCY_CODE,
        currency_symbol: CONFIG.CURRENCY_SYMBOL,
        market_scope_label: CONFIG.MARKET_SCOPE_LABEL,
        contact_support_email: CONFIG.SUPPORT_EMAIL,
        fuel_price_country_slug: CONFIG.FUEL_PRICE_COUNTRY_SLUG,
        geo_region: CONFIG.GEO_REGION,
        geo_placename: CONFIG.GEO_PLACENAME,
        geo_position: CONFIG.GEO_POSITION,
        icbm: CONFIG.ICBM,
        google_maps_api_key: CONFIG.GOOGLE_MAPS_API_KEY,
        google_maps_map_id: CONFIG.GOOGLE_MAPS_MAP_ID
    };
}

function applyBrandingToDocument() {
    if (document && document.documentElement) {
        document.documentElement.lang = CONFIG.LOCALE || document.documentElement.lang || 'en';
        document.documentElement.dataset.siteName = CONFIG.SITE_NAME || '';
        document.documentElement.dataset.countryName = CONFIG.COUNTRY_NAME || '';
        document.documentElement.dataset.currencyCode = CONFIG.CURRENCY_CODE || '';
    }

    if (document && document.title) {
        document.title = replaceRuntimeText(document.title);
    }

    const selectors = [
        'meta[name="description"]',
        'meta[name="keywords"]',
        'meta[name="author"]',
        'meta[property="og:title"]',
        'meta[property="og:description"]',
        'meta[property="og:image"]',
        'meta[property="twitter:title"]',
        'meta[property="twitter:description"]',
        'meta[name="twitter:image"]',
        'meta[name="twitter:title"]',
        'meta[name="twitter:description"]'
    ];

    selectors.forEach((selector) => {
        const element = document.querySelector(selector);
        if (!element) {
            return;
        }

        const content = element.getAttribute('content');
        if (content) {
            element.setAttribute('content', replaceRuntimeText(content));
        }
    });

    const ogSiteName = document.querySelector('meta[property="og:site_name"]');
    if (ogSiteName) {
        ogSiteName.setAttribute('content', CONFIG.SITE_NAME);
    }

    const ogLocale = document.querySelector('meta[property="og:locale"]');
    if (ogLocale && CONFIG.LOCALE) {
        ogLocale.setAttribute('content', CONFIG.LOCALE.replace(/-/g, '_'));
    }

    const languageMeta = document.querySelector('meta[name="language"]');
    if (languageMeta && CONFIG.LOCALE) {
        languageMeta.setAttribute('content', CONFIG.LOCALE.split('-')[0] || CONFIG.LOCALE);
    }

    const geoRegion = document.querySelector('meta[name="geo.region"]');
    if (geoRegion && CONFIG.GEO_REGION) {
        geoRegion.setAttribute('content', CONFIG.GEO_REGION);
    }

    const geoPlace = document.querySelector('meta[name="geo.placename"]');
    if (geoPlace && (CONFIG.GEO_PLACENAME || CONFIG.COUNTRY_NAME)) {
        geoPlace.setAttribute('content', CONFIG.GEO_PLACENAME || CONFIG.COUNTRY_NAME);
    }

    const geoPosition = document.querySelector('meta[name="geo.position"]');
    if (geoPosition && CONFIG.GEO_POSITION) {
        geoPosition.setAttribute('content', CONFIG.GEO_POSITION);
    }

    const icbm = document.querySelector('meta[name="ICBM"]');
    if (icbm && CONFIG.ICBM) {
        icbm.setAttribute('content', CONFIG.ICBM);
    }

    if (CONFIG.SITE_URL) {
        const path = `${window.location.pathname}${window.location.search}`;
        const absolutePageUrl = new URL(path, `${CONFIG.SITE_URL}/`).toString();

        const canonical = document.querySelector('link[rel="canonical"]');
        if (canonical) {
            canonical.setAttribute('href', absolutePageUrl);
        }

        const ogUrl = document.querySelector('meta[property="og:url"]');
        if (ogUrl) {
            ogUrl.setAttribute('content', absolutePageUrl);
        }
    }

    document.querySelectorAll('script[type="application/ld+json"]').forEach((script) => {
        const raw = script.textContent || '';
        if (!raw.trim()) {
            return;
        }

        try {
            const parsed = JSON.parse(raw);
            const transformed = transformStructuredDataNode(parsed);
            script.textContent = JSON.stringify(transformed);
        } catch (error) {
            if (DEBUG) {
                console.warn('Unable to transform JSON-LD branding tokens', error);
            }
        }
    });

    applyRuntimeTextToBody(document.body);
}

const CONFIG = {
    MODE: MODE,
    DEBUG: DEBUG,
    BASE_URL: getBaseURL(),
    API_URL: getAPIUrl(),
    RECOMMENDATION_API_URL: getRecommendationApiUrl(),
    SITE_NAME: DEFAULT_PUBLIC_SITE_CONFIG.site_name,
    SITE_SHORT_NAME: DEFAULT_PUBLIC_SITE_CONFIG.site_short_name,
    SITE_TAGLINE: DEFAULT_PUBLIC_SITE_CONFIG.site_tagline,
    SITE_DESCRIPTION: DEFAULT_PUBLIC_SITE_CONFIG.site_description,
    SITE_URL: normalizePublicSiteUrl(DEFAULT_PUBLIC_SITE_CONFIG.site_url),
    COUNTRY_NAME: DEFAULT_PUBLIC_SITE_CONFIG.country_name,
    COUNTRY_CODE: DEFAULT_PUBLIC_SITE_CONFIG.country_code,
    COUNTRY_DEMONYM: DEFAULT_PUBLIC_SITE_CONFIG.country_demonym,
    LOCALE: DEFAULT_PUBLIC_SITE_CONFIG.locale,
    CURRENCY_CODE: DEFAULT_PUBLIC_SITE_CONFIG.currency_code,
    CURRENCY_SYMBOL: DEFAULT_PUBLIC_SITE_CONFIG.currency_symbol,
    MARKET_SCOPE_LABEL: DEFAULT_PUBLIC_SITE_CONFIG.market_scope_label,
    SUPPORT_EMAIL: DEFAULT_PUBLIC_SITE_CONFIG.contact_support_email,
    FUEL_PRICE_COUNTRY_SLUG: DEFAULT_PUBLIC_SITE_CONFIG.fuel_price_country_slug,
    GEO_REGION: DEFAULT_PUBLIC_SITE_CONFIG.geo_region,
    GEO_PLACENAME: DEFAULT_PUBLIC_SITE_CONFIG.geo_placename,
    GEO_POSITION: DEFAULT_PUBLIC_SITE_CONFIG.geo_position,
    ICBM: DEFAULT_PUBLIC_SITE_CONFIG.icbm,
    VERSION: '4.1.0',
    USE_CREDENTIALS: true,
    GOOGLE_MAPS_API_KEY: null,
    GOOGLE_MAPS_MAP_ID: null
};

let __runtimePublicConfigPromise = null;
let __runtimePublicConfigLoaded = false;

async function getPublicClientConfig(forceRefresh = false) {
    if (!forceRefresh && __runtimePublicConfigLoaded) {
        return getPublicSiteConfigSnapshot();
    }

    if (!forceRefresh && __runtimePublicConfigPromise) {
        return __runtimePublicConfigPromise;
    }

    __runtimePublicConfigPromise = (async () => {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_public_client_config`, {
                method: 'GET',
                credentials: CONFIG.USE_CREDENTIALS ? 'include' : 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Failed to load runtime config (HTTP ${response.status})`);
            }

            const data = await response.json();
            if (!data || !data.success || !data.config) {
                throw new Error('Runtime config payload is missing');
            }

            applyRuntimeSiteConfig(data.config);
            __runtimePublicConfigLoaded = true;
            return getPublicSiteConfigSnapshot();
        } finally {
            __runtimePublicConfigPromise = null;
        }
    })();

    return __runtimePublicConfigPromise;
}

async function getPublicSiteConfig() {
    try {
        await getPublicClientConfig();
    } catch (error) {
        if (DEBUG) {
            console.warn('Using default runtime site config:', error);
        }
    }

    return getPublicSiteConfigSnapshot();
}

async function getGoogleMapsConfig() {
    if (CONFIG.GOOGLE_MAPS_API_KEY) {
        return {
            apiKey: CONFIG.GOOGLE_MAPS_API_KEY,
            mapId: CONFIG.GOOGLE_MAPS_MAP_ID
        };
    }

    await getPublicClientConfig();

    if (!CONFIG.GOOGLE_MAPS_API_KEY) {
        throw new Error('Google Maps runtime config is missing');
    }

    return {
        apiKey: CONFIG.GOOGLE_MAPS_API_KEY,
        mapId: CONFIG.GOOGLE_MAPS_MAP_ID
    };
}

applyBrandingToDocument();
window.getPublicClientConfig = getPublicClientConfig;
window.getPublicSiteConfig = getPublicSiteConfig;
window.getGoogleMapsConfig = getGoogleMapsConfig;
window.addEventListener('DOMContentLoaded', applyBrandingToDocument, { once: true });
getPublicSiteConfig().catch(() => {});

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

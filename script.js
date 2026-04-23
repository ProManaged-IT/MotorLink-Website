// MotorLink v4.1 - Complete DoneDeal-Style JavaScript
//
// Configuration is loaded from config.js (must be included before this file)
// To switch between UAT and PRODUCTION modes, edit config.js

// Fallback runtime config when config.js is missing (for example 404 on live deploy).
// This keeps the site usable instead of crashing with "CONFIG is not defined".
(function ensureRuntimeConfig() {
    if (typeof window.CONFIG !== 'undefined' && window.CONFIG) {
        return;
    }

    const path = (window.location.pathname || '/').toLowerCase();
    const motorlinkIndex = path.indexOf('/motorlink/');
    const basePath = motorlinkIndex >= 0 ? window.location.pathname.substring(0, motorlinkIndex + '/motorlink/'.length) : '/';

    const normalizedBase = basePath.endsWith('/') ? basePath : `${basePath}/`;
    const origin = window.location.origin || '';

    window.CONFIG = {
        MODE: 'FALLBACK',
        DEBUG: false,
        BASE_URL: normalizedBase,
        API_URL: `${origin}${normalizedBase}api.php`,
        RECOMMENDATION_API_URL: `${origin}${normalizedBase}recommendation_engine.php`,
        USE_CREDENTIALS: true,
        SITE_NAME: 'MotorLink',
        SITE_SHORT_NAME: 'MotorLink',
        COUNTRY_NAME: '',
        COUNTRY_CODE: '',
        COUNTRY_DEMONYM: '',
        LOCALE: 'en',
        CURRENCY_CODE: 'LOCAL',
        CURRENCY_SYMBOL: 'LOCAL',
        GEO_POSITION: ''
    };

    // Intentionally silent in production to avoid console noise.
})();

function getRuntimeSiteName() {
    if (typeof window.CONFIG !== 'undefined' && window.CONFIG) {
        return CONFIG.SITE_NAME || CONFIG.SITE_SHORT_NAME || 'MotorLink';
    }

    return 'MotorLink';
}

function getRuntimeCountryName() {
    if (typeof window.CONFIG !== 'undefined' && window.CONFIG) {
        return (CONFIG.COUNTRY_NAME || '').trim();
    }

    return '';
}

function buildRuntimeAddress(parts) {
    return parts
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(', ');
}

function getRuntimeMapCenter() {
    const raw = typeof window.CONFIG !== 'undefined' && window.CONFIG ? String(CONFIG.GEO_POSITION || '').trim() : '';
    const match = raw.match(/^(-?\d+(?:\.\d+)?)\s*[;,]\s*(-?\d+(?:\.\d+)?)$/);

    if (match) {
        return {
            lat: parseFloat(match[1]),
            lng: parseFloat(match[2])
        };
    }

    return { lat: 0, lng: 0 };
}

// ============================================================================
// MAIN MOTORLINK APPLICATION
// ============================================================================

class MotorLink {
    constructor() {
        this.currentPage = 1;
        this.isLoading = false;
        this.filters = {};
        this.currentUser = null;
        this.hasMorePages = true;
        this.authChecked = false;
        this.userLocation = null; // Store user location for distance sorting
        this.searchDebounceTimer = null; // For debouncing search input
        this.serverRecommendationCache = { ids: [], expiresAt: 0 };
        this.init();
    }
    
    init() {
        this.checkMaintenanceMode();
        
        this.checkAuthentication();
        this.setupEventListeners();
        this.syncActiveNavLink();
        this.initScrollHeader();
        this.setupDashboardCards();
        this.loadInitialData();
        this.setupPagination();
        
        // Start tour for new users
        setTimeout(() => {
            if (!this.currentUser && !localStorage.getItem('tour_completed')) {
                this.startTour();
            }
        }, 2000);
    }

    initScrollHeader() {
        const header = document.querySelector('.header');
        if (!header) return;
        const onScroll = () => {
            header.classList.toggle('scrolled', window.scrollY > 10);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll(); // run immediately in case page loads mid-scroll
    }

    syncHomepageHeaderMode(isLoggedIn) {
        const body = document.body;
        if (!body || !body.classList.contains('index-page')) {
            return;
        }

        body.classList.toggle('has-hero', !isLoggedIn);
    }

    syncActiveNavLink() {
        const nav = document.getElementById('mainNav');
        if (!nav) return;

        const currentPage = (window.location.pathname.split('/').pop() || 'index.html').toLowerCase();
        const pageAliases = {
            '': 'index.html',
            'showroom.html': 'dealers.html',
            'car-hire-company.html': 'car-hire.html'
        };

        const activeHref = pageAliases[currentPage] || currentPage;

        nav.querySelectorAll('a').forEach(link => {
            const href = (link.getAttribute('href') || '').toLowerCase();
            const isMatch = activeHref && href === activeHref;
            link.classList.toggle('active', isMatch);
            if (isMatch) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    async checkMaintenanceMode() {
        try {
            const path = window.location.pathname.toLowerCase();
            if (path.includes('/admin/') || path.endsWith('/maintenance.html') || path.endsWith('maintenance.html')) {
                return;
            }

            const configUrl = `${CONFIG.API_URL}${CONFIG.API_URL.includes('?') ? '&' : '?'}action=get_public_client_config`;
            const configResponse = await fetch(configUrl, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' }
            });
            const configData = await configResponse.json();

            const maintenanceEnabled = !!configData?.config?.maintenance_enabled;
            if (!maintenanceEnabled) {
                return;
            }

            const authResponse = await fetch(`${CONFIG.API_URL}${CONFIG.API_URL.includes('?') ? '&' : '?'}action=check_auth`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' }
            });
            const authData = await authResponse.json();

            const isAdmin = !!(authData?.authenticated && authData?.user?.type === 'admin');
            if (isAdmin) {
                return;
            }

            const message = encodeURIComponent(configData?.config?.maintenance_message || 'We are currently performing scheduled maintenance. Please check back shortly.');
            window.location.href = `${CONFIG.BASE_URL}maintenance.html?message=${message}`;
        } catch (error) {
            // Non-fatal: if guard check fails, API layer still enforces maintenance mode.
            console.warn('Maintenance guard check failed:', error);
        }
    }

    // ============================================================================
    // DASHBOARD METHODS
    // ============================================================================
    
    setupDashboardCards() {
        const dashboardCards = document.querySelectorAll('.dashboard-card[data-action]');
        
        dashboardCards.forEach(card => {
            card.addEventListener('click', (e) => {
                const action = card.getAttribute('data-action');
                this.handleDashboardAction(action);
            });
        });
    }

    handleDashboardAction(action) {
        switch(action) {
            case 'profile':
                this.openProfileModal();
                break;
            case 'listings':
                this.openMyListings();
                break;
            case 'showroom':
                this.openMyShowroom();
                break;
            case 'messages':
                this.openMessages();
                break;
            case 'favorites':
                this.openFavorites();
                break;
        }
    }

    openProfileSettings() {
        this.openProfileModal();
    }

    // ADD THIS NEW METHOD to MotorLink class:
    openProfileModal() {
        const modal = document.getElementById('profileModal');
        if (modal) {
            modal.classList.remove('hidden');
            this.loadProfileData();
        }
    }

    // ADD THIS NEW METHOD to MotorLink class:
    async loadProfileData() {
        try {
            const response = await this.makeAPICall('get_profile');
            if (response.success && response.profile) {
                const profile = response.profile;
                
                // Fill form fields
                const fullName = document.getElementById('profileFullName');
                const email = document.getElementById('profileEmail');
                const phone = document.getElementById('profilePhone');
                const whatsapp = document.getElementById('profileWhatsapp');
                const city = document.getElementById('profileCity');
                const address = document.getElementById('profileAddress');
                
                if (fullName) fullName.value = profile.full_name || '';
                if (email) email.value = profile.email || '';
                if (phone) phone.value = profile.phone || '';
                if (whatsapp) whatsapp.value = profile.whatsapp || '';
                if (city) city.value = profile.city || '';
                if (address) address.value = profile.address || '';
                
                // Clear password fields
                const currentPassword = document.getElementById('currentPassword');
                const newPassword = document.getElementById('newPassword');
                const confirmPassword = document.getElementById('confirmPassword');
                
                if (currentPassword) currentPassword.value = '';
                if (newPassword) newPassword.value = '';
                if (confirmPassword) confirmPassword.value = '';
            }
        } catch (error) {
            this.showToast('Error loading profile data', 'error');
        }
    }
    
    
    openMyListings() {
        // For dealers, redirect to showroom instead
        if (this.currentUser && this.currentUser.type === 'dealer') {
            this.openMyShowroom();
        } else {
            window.location.href = `${CONFIG.BASE_URL}my-listings.html`;
        }
    }

    openMyShowroom() {
        window.location.href = `${CONFIG.BASE_URL}dealer-dashboard.html`;
    }

    openMessages() {
        window.location.href = `${CONFIG.BASE_URL}chat_system.html`;
    }

    openFavorites() {
        window.location.href = `${CONFIG.BASE_URL}favorites.html`;
    }

    // ============================================================================
    // EVENT LISTENERS SETUP
    // ============================================================================
    
    setupEventListeners() {
        // Mobile menu - handled by mobile-menu.js
        // this.setupMobileMenu();
        
        // Search form
        const searchForm = document.querySelector('.search-form');
        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.performSearch();
            });
        }
        
        // Add debounced search on input for efficient fetching
        const searchInputs = document.querySelectorAll('.search-input');
        searchInputs.forEach(input => {
            input.addEventListener('input', (e) => {
                // Sync all search inputs with the same value
                const value = e.target.value;
                searchInputs.forEach(otherInput => {
                    if (otherInput !== e.target) {
                        otherInput.value = value;
                    }
                });
                
                // Clear previous timer
                if (this.searchDebounceTimer) {
                    clearTimeout(this.searchDebounceTimer);
                }
                
                // Set new timer to trigger search after 500ms of no typing
                this.searchDebounceTimer = setTimeout(() => {
                    this.performSearch();
                }, 500);
            });
        });
        
        // Filter controls
        this.setupFilters();
        
        // Category tabs
        this.setupCategoryTabs();
        
        // Clear filters button
        const clearBtn = document.querySelector('.btn-clear-filters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearFilters());
        }
    }
    
    setupMobileMenu() {
        const toggle = document.querySelector('.mobile-menu-toggle');
        const nav = document.querySelector('.nav');
        const userMenu = document.querySelector('.user-menu');

        if (toggle && nav) {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                nav.classList.toggle('active');

                // Also toggle user menu if it exists
                if (userMenu) {
                    if (nav.classList.contains('active')) {
                        setTimeout(() => {
                            userMenu.classList.add('mobile-active');
                        }, 100);
                    } else {
                        userMenu.classList.remove('mobile-active');
                    }
                }

                const icon = toggle.querySelector('i');
                if (nav.classList.contains('active')) {
                    icon.className = 'fas fa-times';
                } else {
                    icon.className = 'fas fa-bars';
                }
            });

            // Close menu when clicking a nav link
            nav.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    nav.classList.remove('active');
                    if (userMenu) userMenu.classList.remove('mobile-active');
                    toggle.querySelector('i').className = 'fas fa-bars';
                });
            });

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!toggle.contains(e.target) && !nav.contains(e.target) &&
                    (!userMenu || !userMenu.contains(e.target))) {
                    nav.classList.remove('active');
                    if (userMenu) userMenu.classList.remove('mobile-active');
                    toggle.querySelector('i').className = 'fas fa-bars';
                }
            });
        }
    }
    
    setupFilters() {
        const filterInputs = document.querySelectorAll('.filter-select, .filter-input');
        const filterForm = document.getElementById('filterForm') || document.querySelector('.filter-form');
        const sortSelect = document.querySelector('.sort-select');
        const findNearbyBtn = document.getElementById('findNearbyBtn');

        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilters();
            });
        }
        
        filterInputs.forEach(input => {
            input.addEventListener('change', () => {
                this.applyFilters();
            });
        });
        
        if (sortSelect) {
            sortSelect.addEventListener('change', () => {
                // Show "Find Nearby" button when "Nearest First" is selected
                if (sortSelect.value === 'nearest' && !this.userLocation) {
                    if (findNearbyBtn) {
                        findNearbyBtn.style.display = 'inline-block';
                    }
                    // Prompt user to enable location
                    this.promptForLocation();
                } else {
                    if (findNearbyBtn) {
                        findNearbyBtn.style.display = 'none';
                    }
                    this.applyFilters();
                }
            });
        }
        
        // Find Nearby button handler
        if (findNearbyBtn) {
            findNearbyBtn.addEventListener('click', () => {
                this.getUserLocation();
            });
        }
        
        // Make-Model dependency (both desktop and mobile)
        const makeSelect = document.getElementById('makeFilter');
        const modelSelect = document.getElementById('modelFilter');
        
        if (makeSelect && modelSelect) {
            makeSelect.addEventListener('change', () => {
                this.loadModels(makeSelect.value, modelSelect);
            });
        }
        
        // Also handle mobile make select changes
        document.addEventListener('change', (e) => {
            if (e.target.id === 'mobile-makeFilter' || (e.target.name === 'make' && e.target.id && e.target.id.includes('mobile-'))) {
                const mobileModelSelect = document.querySelector('#mobile-modelFilter') || document.querySelector('.mobile-filter-tray-content select[name="model"]');
                if (mobileModelSelect) {
                    this.loadModels(e.target.value, mobileModelSelect);
                }
            }
        });
        
        // Mobile filter toggle
        const filterToggle = document.querySelector('.mobile-filter-toggle');
        const sidebar = document.querySelector('.sidebar');
        
        if (filterToggle && sidebar) {
            filterToggle.addEventListener('click', () => {
                const isHidden = sidebar.style.display === 'none';
                if (isHidden) {
                    sidebar.style.display = 'block';
                    sidebar.classList.add('show');
                    filterToggle.innerHTML = '<i class="fas fa-times"></i> Hide Filters';
                } else {
                    sidebar.style.display = 'none';
                    sidebar.classList.remove('show');
                    filterToggle.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
                }
            });
        }
    }
    
    // Get user's current location
    getUserLocation() {
        if (!navigator.geolocation) {
            this.showError('Your browser doesn\'t support location services. Please use the search filters to find nearby cars.');
            return;
        }
        
        const findNearbyBtn = document.getElementById('findNearbyBtn');
        if (findNearbyBtn) {
            findNearbyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting Location...';
            findNearbyBtn.disabled = true;
        }
        
        navigator.geolocation.getCurrentPosition(
            (position) => {
                this.userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                
                if (findNearbyBtn) {
                    findNearbyBtn.innerHTML = '<i class="fas fa-check"></i> Location Set';
                    setTimeout(() => {
                        findNearbyBtn.style.display = 'none';
                    }, 2000);
                }
                
                // Reload listings with distance sorting
                this.applyFilters();
            },
            (error) => {
                let errorMessage = 'Unable to get your location. ';
                
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage += 'Please enable location permissions in your browser settings.';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage += 'Location information is unavailable.';
                        break;
                    case error.TIMEOUT:
                        errorMessage += 'Request timed out. Please try again.';
                        break;
                    default:
                        errorMessage += 'Please try again or use the filters to search by location.';
                }
                
                this.showError(errorMessage);
                
                if (findNearbyBtn) {
                    findNearbyBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Find Nearby';
                    findNearbyBtn.disabled = false;
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    }
    
    // Prompt user to enable location
    promptForLocation() {
        const findNearbyBtn = document.getElementById('findNearbyBtn');
        if (findNearbyBtn && findNearbyBtn.style.display !== 'none') {
            // Button is already visible, user will click it
            return;
        }
        
        // Show a more informative message
        const userConfirmed = confirm(
            'To sort cars by distance, we need to access your current location. ' +
            'Your location is only used locally in your browser and is not stored. ' +
            'Allow location access?'
        );
        
        if (userConfirmed) {
            this.getUserLocation();
        } else {
            // Reset sort to default if user declines
            const sortSelect = document.querySelector('.sort-select');
            if (sortSelect) {
                sortSelect.value = 'newest';
                this.applyFilters();
            }
        }
    }
    
    // Calculate distance between two coordinates (Haversine formula)
    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Earth's radius in km
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
    
    setupCategoryTabs() {
        const tabs = document.querySelectorAll('.category-tab');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Update active tab
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Apply category filter with loading state
                const category = tab.dataset.category;
                this.applyFilterWithLoading('category', category);
            });
        });
    }
    
    async applyFilterWithLoading(filterName, value) {
        // Category tabs are instant client-side filter controls. Keep the page static.
        this.applyFilter(filterName, value);
    }
    
    setupPagination() {
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadListings(false);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (this.hasMorePages) {
                    this.currentPage++;
                    this.loadListings(false);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
    }
    
    updatePaginationUI(pagination) {
        const paginationControls = document.getElementById('paginationControls');
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        const currentPageNum = document.getElementById('currentPageNum');
        const totalPages = document.getElementById('totalPages');
        const mobileTopPageIndicator = document.getElementById('mobileTopPageIndicator');
        
        if (!paginationControls) return;
        
        // Show pagination controls
        paginationControls.style.display = 'flex';
        
        // Update page numbers
        if (currentPageNum) {
            currentPageNum.textContent = this.currentPage;
        }
        
        let resolvedTotalPages = 1;
        if (pagination && pagination.total_pages) {
            resolvedTotalPages = Math.max(1, parseInt(pagination.total_pages, 10) || 1);
        } else {
            // Estimate total pages if not provided
            resolvedTotalPages = Math.max(1, Math.ceil((pagination?.total || 10) / 10));
        }
        if (totalPages) {
            totalPages.textContent = resolvedTotalPages;
        }
        if (mobileTopPageIndicator) {
            mobileTopPageIndicator.textContent = `Page ${this.currentPage} of ${resolvedTotalPages}`;
        }
        
        // Update button states
        if (prevBtn) {
            prevBtn.disabled = this.currentPage <= 1;
        }
        
        if (nextBtn) {
            nextBtn.disabled = !this.hasMorePages;
        }
    }

    // ============================================================================
    // API COMMUNICATION
    // ============================================================================
    
    async makeAPICall(action, params = {}, method = 'GET') {
        try {
            let url = `${CONFIG.API_URL}?action=${action}`;
            let options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                }
            };

            // Only include credentials when appropriate (not in GitHub Codespaces)
            if (CONFIG.USE_CREDENTIALS) {
                options.credentials = 'include';
            }

            // Category/filter listing requests should not trigger the full-page transition loader.
            if (action === 'listings') {
                options.headers['X-Skip-Global-Loader'] = '1';
            }

            if (method === 'GET' && Object.keys(params).length > 0) {
                const urlParams = new URLSearchParams(params);
                url += '&' + urlParams.toString();
            } else if (method === 'POST') {
                options.body = JSON.stringify(params);
            }


            const response = await fetch(url, options);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Check if response is valid JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('API returned non-JSON response');
            }

            const data = await response.json();


            return data;

        } catch (error) {
            throw error;
        }
    }

    normalizeSortValue(value) {
        const normalized = String(value || '').trim().toLowerCase();
        const sortAliases = {
            latest: 'newest',
            newest: 'newest',
            oldest: 'oldest',
            old: 'oldest',
            views: 'most_viewed',
            most_views: 'most_viewed',
            most_viewed: 'most_viewed',
            year: 'year_new',
            year_new: 'year_new',
            year_old: 'year_old',
            year_desc: 'year_new',
            year_asc: 'year_old',
            price_low: 'price_low',
            price_high: 'price_high',
            nearest: 'nearest'
        };

        return sortAliases[normalized] || 'newest';
    }

    // ============================================================================
    // AUTHENTICATION
    // ============================================================================

    async checkAuthentication() {
        // Check localStorage first for immediate UI update (prevents flash)
        const storedAuth = localStorage.getItem('motorlink_authenticated');
        const storedUser = localStorage.getItem('motorlink_user');

        if (storedAuth === 'true' && storedUser) {
            try {
                this.currentUser = JSON.parse(storedUser);
                this.updateUserInterface(true);
            } catch (e) {
                // Invalid stored data, will be fixed by API call
            }
        }

        try {
            const response = await this.makeAPICall('check_auth');

            if (response.success && response.authenticated) {
                this.currentUser = response.user;
                // Store in localStorage for session persistence
                if (this.currentUser) {
                    localStorage.setItem('motorlink_user', JSON.stringify(this.currentUser));
                    localStorage.setItem('motorlink_authenticated', 'true');
                }
                this.updateUserInterface(true);
            } else {
                // API explicitly says not authenticated - clear localStorage and show guest menu
                localStorage.removeItem('motorlink_user');
                localStorage.removeItem('motorlink_authenticated');
                this.currentUser = null;
                this.updateUserInterface(false);
            }
        } catch (error) {
            // Use localStorage as fallback for network errors (API unreachable)
            if (storedAuth === 'true' && storedUser) {
                try {
                    if (!this.currentUser) {
                        this.currentUser = JSON.parse(storedUser);
                        this.updateUserInterface(true);
                    }
                } catch (e) {
                    this.currentUser = null;
                    this.updateUserInterface(false);
                }
            } else {
                this.currentUser = null;
                this.updateUserInterface(false);
            }
        } finally {
            this.authChecked = true;
        }
    }
    
    updateUserInterface(isLoggedIn) {
        const userInfo = document.getElementById('userInfo');
        const guestMenu = document.getElementById('guestMenu');
        const userName = document.getElementById('userName');
        const userDashboard = document.getElementById('userDashboard');
        const profileModal = document.getElementById('profileModal');

        this.syncHomepageHeaderMode(isLoggedIn && !!this.currentUser);

        if (isLoggedIn && this.currentUser) {
            // Get user's display name (first name or full name)
            const displayName = this.currentUser.full_name || this.currentUser.name || this.currentUser.email?.split('@')[0] || 'User';

            // Show user info, hide guest menu
            if (userInfo) userInfo.style.display = 'flex';
            if (guestMenu) guestMenu.style.display = 'none';
            if (userDashboard) userDashboard.classList.remove('hidden');

            // Update all user name elements
            if (userName) userName.textContent = displayName;

            // Also update any other name displays
            document.querySelectorAll('#userName, #dashboardUserName, .user-name-display').forEach(el => {
                el.textContent = displayName;
            });

            // Update user avatar with initials
            if (typeof updateUserAvatar === 'function') {
                updateUserAvatar(displayName);
            }

            // Hide "My Listings" link for dealers (they use the showroom inventory instead)
            const myListingsLink = document.querySelector('a[href="my-listings.html"]');
            if (myListingsLink) {
                if (this.currentUser.type === 'dealer') {
                    myListingsLink.style.display = 'none';
                } else {
                    myListingsLink.style.display = '';
                }
            }

            // Hide dashboard and my listings links when on respective dashboard pages
            const currentPage = window.location.pathname.split('/').pop();
            const isDashboardPage = currentPage === 'dealer-dashboard.html' || 
                                   currentPage === 'garage-dashboard.html' || 
                                   currentPage === 'car-hire-dashboard.html';
            
            if (isDashboardPage) {
                // Hide "My Listings" link when on any dashboard
                if (myListingsLink) {
                    myListingsLink.style.display = 'none';
                }
                
                // Hide dashboard link when already on a dashboard
                const dashboardLinks = document.querySelectorAll('a[href="dealer-dashboard.html"], a[href="garage-dashboard.html"], a[href="car-hire-dashboard.html"]');
                dashboardLinks.forEach(link => {
                    link.style.display = 'none';
                });
            }

            // Add dashboard link based on user type (NOT for dealers - they have card)
            this.addDashboardLink();

            // Customize dashboard cards based on user type
            this.customizeDashboardCards();

            // Update dashboard stats when user logs in
            this.updateDashboardStats();
        } else {
            // Show guest menu, hide user info
            if (userInfo) userInfo.style.display = 'none';
            if (guestMenu) guestMenu.style.display = 'flex';
            if (userDashboard) userDashboard.classList.add('hidden');
            
            // CRITICAL: Remove dashboard link when user is not logged in
            const dashboardLinks = document.querySelectorAll('.dashboard-link');
            dashboardLinks.forEach(link => link.remove());
            
            // Also remove mobile dashboard links
            const mobileDashLinks = document.querySelectorAll('.mobile-dash-link');
            mobileDashLinks.forEach(link => link.remove());
        }

        // Ensure profile modal is hidden by default when UI updates
        if (profileModal) {
            profileModal.classList.add('hidden');
        }
    }

    customizeDashboardCards() {
        if (!this.currentUser) return;

        const listingsCard = document.querySelector('.dashboard-card[data-action="listings"]');
        
        if (!listingsCard) return;

        // For dealers, change "My Listings" to "My Showroom"
        if (this.currentUser.type === 'dealer') {
            listingsCard.setAttribute('data-action', 'showroom');
            const cardIcon = listingsCard.querySelector('.card-icon i');
            const cardTitle = listingsCard.querySelector('.card-content h3');
            const cardDesc = listingsCard.querySelector('.card-content p');
            
            if (cardIcon) cardIcon.className = 'fas fa-store';
            if (cardTitle) cardTitle.textContent = 'My Showroom';
            if (cardDesc) cardDesc.textContent = 'Manage your dealership inventory and showroom';
        }
    }

    addDashboardLink() {
        if (!this.currentUser) return;

        const nav = document.getElementById('mainNav');
        if (!nav) return;

        // Remove existing dashboard link if any
        const existingDashboard = nav.querySelector('.dashboard-link');
        if (existingDashboard) {
            existingDashboard.remove();
        }

        // Determine dashboard URL and text based on user type
        let dashboardUrl = '';
        let dashboardText = '';
        let dashboardIcon = '';

        switch(this.currentUser.type) {
            case 'dealer':
                dashboardUrl = 'dealer-dashboard.html';
                dashboardText = 'My Showroom';
                dashboardIcon = 'fas fa-store';
                break;
            case 'garage':
                dashboardUrl = 'garage-dashboard.html';
                dashboardText = 'My Garage';
                dashboardIcon = 'fas fa-wrench';
                break;
            case 'car_hire':
                dashboardUrl = 'car-hire-dashboard.html';
                dashboardText = 'My Fleet';
                dashboardIcon = 'fas fa-car-side';
                break;
            case 'admin':
                dashboardUrl = 'admin/admin.html';
                dashboardText = 'Admin Panel';
                dashboardIcon = 'fas fa-shield-alt';
                break;
            default:
                // Individual users don't get a special dashboard link
                return;
        }

        // Create and insert the dashboard link
        const dashboardLink = document.createElement('a');
        dashboardLink.href = dashboardUrl;
        dashboardLink.className = 'dashboard-link';
        dashboardLink.innerHTML = `<i class="${dashboardIcon}"></i> <span>${dashboardText}</span>`;

        // Insert after the last nav item
        nav.appendChild(dashboardLink);
        this.syncActiveNavLink();
    }

    async updateDashboardStats() {
        if (!this.currentUser) return;

        // Get localStorage favorites count as fallback
        const localFavorites = JSON.parse(localStorage.getItem('motorlink_favorites') || '[]');
        const localFavoritesCount = localFavorites.length;

        // Update dashboard elements
        const listingsCount = document.getElementById('listingsCount');
        const messagesCount = document.getElementById('messagesCount');
        const favoritesCount = document.getElementById('favoritesCount');
        const dashboardUserName = document.getElementById('dashboardUserName');

        // Set user name immediately
        if (dashboardUserName) dashboardUserName.textContent = this.currentUser.name;

        // Set localStorage favorites immediately as a fallback
        if (favoritesCount) favoritesCount.textContent = localFavoritesCount;

        try {
            const response = await this.makeAPICall('user_stats');
            if (response.success && response.stats) {
                const stats = response.stats;

                if (listingsCount) listingsCount.textContent = stats.listings || 0;
                if (messagesCount) messagesCount.textContent = stats.messages || 0;

                // Use server favorites if available, otherwise keep localStorage count
                if (favoritesCount) {
                    const serverFavorites = stats.favorites || 0;
                    // Use the higher of server or local count (in case sync is pending)
                    favoritesCount.textContent = Math.max(serverFavorites, localFavoritesCount);
                }
            } else if (response && !response.success && response.error) {
                // If we get a 401, it might be a session issue - but don't log out immediately
                // The check_auth endpoint will handle authentication state
                // Don't clear user session here - let check_auth handle it
            }
        } catch (error) {
            // Keep localStorage favorites count on error
            // Don't log out on network errors or API errors
        }
    }

    // ============================================================================
    // DATA LOADING
    // ============================================================================
    
    loadInitialData() {
        this.loadMakes();
        this.loadLocations();
        this.loadStats();
        
        // Only load listings on index page, not on my-listings page
        const isMyListingsPage = window.location.pathname.includes('my-listings');
        if (!isMyListingsPage) {
            this.loadListings();
        }
    }
    
    async loadMakes() {
        try {
            const response = await this.makeAPICall('makes');
            
            if (response.success && Array.isArray(response.makes)) {
                // Store makes for later use (e.g., mobile filter tray)
                this.makes = response.makes;
                
                // Populate all make selects including mobile ones
                const makeSelects = document.querySelectorAll('#makeFilter, #mobile-makeFilter, select[name="make"], select[name="make_id"]');
                makeSelects.forEach(select => {
                    // Clear existing options except first one
                    while (select.children.length > 1) {
                        select.removeChild(select.lastChild);
                    }
                    
                    response.makes.forEach(make => {
                        const option = document.createElement('option');
                        option.value = make.id;
                        option.textContent = make.name;
                        select.appendChild(option);
                    });
                });
            }
        } catch (error) {
        }
    }
    
    async loadModels(makeId, modelSelect) {
        if (!makeId) {
            if (modelSelect) {
                modelSelect.innerHTML = '<option value="">Select Make First</option>';
            }
            // Also clear mobile model select
            const mobileModelSelect = document.querySelector('#mobile-modelFilter') || document.querySelector('.mobile-filter-tray-content select[name="model"]');
            if (mobileModelSelect) {
                mobileModelSelect.innerHTML = '<option value="">Select Make First</option>';
            }
            return;
        }
        
        if (modelSelect) {
            modelSelect.innerHTML = '<option value="">Loading models...</option>';
            modelSelect.disabled = true;
        }
        
        // Also update mobile model select if it exists
        const mobileModelSelect = document.querySelector('#mobile-modelFilter') || document.querySelector('.mobile-filter-tray-content select[name="model"]');
        if (mobileModelSelect) {
            mobileModelSelect.innerHTML = '<option value="">Loading models...</option>';
            mobileModelSelect.disabled = true;
        }
        
        try {
            const response = await this.makeAPICall('models', { make_id: makeId });
            
            if (response.success && Array.isArray(response.models)) {
                const modelSelects = modelSelect ? [modelSelect] : [];
                if (mobileModelSelect) {
                    modelSelects.push(mobileModelSelect);
                }
                
                modelSelects.forEach(select => {
                    select.innerHTML = '<option value="">All Models</option>';
                    response.models.forEach(model => {
                        const option = document.createElement('option');
                        option.value = model.id;
                        option.textContent = model.name;
                        select.appendChild(option);
                    });
                });
            } else {
                if (modelSelect) {
                    modelSelect.innerHTML = '<option value="">No models available</option>';
                }
                if (mobileModelSelect) {
                    mobileModelSelect.innerHTML = '<option value="">No models available</option>';
                }
            }
        } catch (error) {
            if (modelSelect) {
                modelSelect.innerHTML = '<option value="">Error loading models</option>';
            }
            if (mobileModelSelect) {
                mobileModelSelect.innerHTML = '<option value="">Error loading models</option>';
            }
        } finally {
            if (modelSelect) {
                modelSelect.disabled = false;
            }
            if (mobileModelSelect) {
                mobileModelSelect.disabled = false;
            }
        }
    }
        
        async loadLocations() {
            try {
                const response = await this.makeAPICall('locations');
                
                if (response.success && Array.isArray(response.locations)) {
                    // Store locations for later use (e.g., mobile filter tray)
                    this.locations = response.locations;
                    
                    // Populate all location selects including mobile ones
                    const locationSelects = document.querySelectorAll('select[name="location"], #locationFilter, #mobile-locationFilter, select[name="location_id"]');
                    locationSelects.forEach(select => {
                        // Skip if already populated (but always populate mobile selects)
                        const isMobile = select.id && select.id.includes('mobile-');
                        if (!isMobile && select.options.length > 1) return;
                        
                        // Clear mobile selects to ensure fresh data
                        if (isMobile) {
                            while (select.options.length > 1) {
                                select.remove(1);
                            }
                        }
                        
                        response.locations.forEach(location => {
                            const option = document.createElement('option');
                            option.value = select.name && select.name.includes('location_id') ? location.id : location.name;
                            option.textContent = `${location.name}, ${location.region}`;
                            select.appendChild(option);
                        });
                    });
                }
            } catch (error) {
            }
        }
        
        async loadStats() {
            try {
                const response = await this.makeAPICall('stats');
                
                if (response.success && response.stats) {
                    // Update only when API actually provides a value.
                    const updateIfPresent = (elementId, value) => {
                        if (value === null || value === undefined || value === '') return;
                        const num = Number(value);
                        if (!Number.isFinite(num)) return;
                        this.animateCounter(elementId, num);
                    };

                    updateIfPresent('totalCars', response.stats.total_cars);
                    updateIfPresent('totalDealers', response.stats.total_dealers);
                    updateIfPresent('totalGarages', response.stats.total_garages);
                    updateIfPresent('totalCarHire', response.stats.total_car_hire);
                }
            } catch (error) {
                // Keep hero stats unchanged when stats API fails.
            }
        }
        
        async loadListings(resetPage = true) {
            if (this.isLoading) return;
            
            const listingsGrid = document.querySelector('.listings-grid');
            if (!listingsGrid) return;
            
            this.isLoading = true;
            
            // Store current grid height to prevent collapse - only set on grid, not section
            let minHeightSet = false;
            if (resetPage) {
                const currentGridHeight = listingsGrid.offsetHeight;
                const minGridHeight = Math.max(currentGridHeight, 400);
                listingsGrid.style.minHeight = `${minGridHeight}px`;
                minHeightSet = true;
            }
            
            // Keep filtering in-place without dimming overlays.
            if (resetPage) {
                this.currentPage = 1;
                this.hasMorePages = true;
            }
            
            try {
                const filters = this.getCurrentFilters();
                filters.page = this.currentPage;
                filters.limit = 10;
                const activeSort = this.normalizeSortValue(filters.sort || 'newest');
                
                const response = await this.makeAPICall('listings', filters);
                
                if (response.success) {
                    let listings = response.listings || [];
                    
                    // Only boost relevance on "newest" default browsing so explicit sorts remain exact.
                    if (activeSort === 'newest') {
                        listings = this.applyRecommendationBoosting(listings);
                        listings = await this.applyServerRecommendationBoosting(listings);
                    }
                    
                    // Always render (replace) listings for pagination
                    this.renderListings(listings);
                    
                    // Update pagination info
                    if (response.pagination) {
                        this.hasMorePages = response.pagination.has_more || false;
                        
                        const resultsCount = document.querySelector('.results-count');
                        if (resultsCount) {
                            const total = response.pagination.total || 0;
                            resultsCount.textContent = `${total} cars found`;
                        }
                        
                        // Update pagination UI (only updates pagination controls, not container)
                        this.updatePaginationUI(response.pagination);
                    } else {
                        this.hasMorePages = listings.length >= 10;
                        // Update results count if available
                        const resultsCount = document.querySelector('.results-count');
                        if (resultsCount) {
                            resultsCount.textContent = `${listings.length} cars found`;
                        }
                        
                        // Update pagination UI with estimated data (only updates pagination controls, not container)
                        this.updatePaginationUI({ total: listings.length, has_more: this.hasMorePages });
                    }
                } else {
                    throw new Error(response.message || 'Failed to load listings');
                }
            } catch (error) {
                this.showError(error.message || 'Failed to load listings');
            } finally {
                this.isLoading = false;
                
                // Reset min-height after a brief delay to allow content to render
                // Only reset grid, not section
                if (minHeightSet) {
                    setTimeout(() => {
                        listingsGrid.style.minHeight = '';
                    }, 300);
                }
            }
        }

        // ============================================================================
        // RECOMMENDATION ENGINE - Client-side boosting
        // ============================================================================
        
        /**
         * Apply recommendation boosting based on user viewing history
         * Tracks what users look at and boosts similar cars to the top
         */
        applyRecommendationBoosting(listings) {
            if (!listings || listings.length === 0) return listings;
            
            // Get user preferences from localStorage
            const preferences = this.getUserPreferences();
            
            if (!preferences || Object.keys(preferences).length === 0) {
                // No preferences yet, return original order
                return listings;
            }
            
            // Calculate boost score for each listing
            const boostedListings = listings.map(listing => {
                let boostScore = 0;
                
                // Boost based on make preference
                if (preferences.makes && preferences.makes[listing.make_name]) {
                    boostScore += preferences.makes[listing.make_name] * 100;
                }
                
                // Boost based on model preference
                if (preferences.models && preferences.models[listing.model_name]) {
                    boostScore += preferences.models[listing.model_name] * 80;
                }
                
                // Boost based on fuel type preference
                if (preferences.fuel_types && preferences.fuel_types[listing.fuel_type]) {
                    boostScore += preferences.fuel_types[listing.fuel_type] * 50;
                }
                
                // Boost based on transmission preference
                if (preferences.transmissions && preferences.transmissions[listing.transmission]) {
                    boostScore += preferences.transmissions[listing.transmission] * 40;
                }
                
                // Boost based on price range preference
                if (preferences.price_range && preferences.price_range.avg) {
                    const price = parseInt(listing.price);
                    const avgPrice = preferences.price_range.avg;
                    const priceVariance = Math.abs(price - avgPrice) / avgPrice;
                    if (priceVariance < 0.3) { // Within 30% of average
                        boostScore += 60 * (1 - priceVariance);
                    }
                }
                
                // Boost based on year preference
                if (preferences.year_range && preferences.year_range.avg) {
                    const year = parseInt(listing.year);
                    const avgYear = preferences.year_range.avg;
                    const yearDiff = Math.abs(year - avgYear);
                    if (yearDiff <= 3) {
                        boostScore += 30 * (1 - yearDiff / 3);
                    }
                }
                
                return {
                    ...listing,
                    _boostScore: boostScore
                };
            });
            
            // Sort by boost score (descending), keeping original order for items with same score
            boostedListings.sort((a, b) => {
                if (b._boostScore === a._boostScore) return 0;
                return b._boostScore - a._boostScore;
            });
            
            // Remove the boost score before returning
            return boostedListings.map(({ _boostScore, ...listing }) => listing);
        }

        /**
         * Resolve recommendation endpoint with safe fallback to avoid broken learning flow.
         */
        getRecommendationApiUrl() {
            if (CONFIG && CONFIG.RECOMMENDATION_API_URL) {
                return CONFIG.RECOMMENDATION_API_URL;
            }

            if (CONFIG && CONFIG.API_URL && CONFIG.API_URL.includes('api.php')) {
                return CONFIG.API_URL.replace('api.php', 'recommendation_engine.php');
            }

            return 'recommendation_engine.php';
        }

        /**
         * Fetch recommendation IDs from server and cache briefly to reduce network usage.
         */
        async getServerRecommendationIds(forceRefresh = false) {
            // Recommendations are only meaningful for logged-in users
            if (!this.currentUser) return [];

            const now = Date.now();
            if (!forceRefresh && this.serverRecommendationCache.expiresAt > now && this.serverRecommendationCache.ids.length > 0) {
                return this.serverRecommendationCache.ids;
            }

            const recommendationUrl = this.getRecommendationApiUrl();
            const sessionId = this.getOrCreateSessionId();

            try {
                const endpoint = `${recommendationUrl}?action=get_recommendations&type=personalized&limit=30&session_id=${encodeURIComponent(sessionId)}`;
                const response = await fetch(endpoint, {
                    method: 'GET',
                    credentials: 'include'
                });
                const data = await response.json();

                const ids = Array.isArray(data?.recommendations)
                    ? data.recommendations.map(item => String(item.id)).filter(Boolean)
                    : [];

                this.serverRecommendationCache = {
                    ids,
                    expiresAt: now + 3 * 60 * 1000
                };

                return ids;
            } catch (error) {
                this.serverRecommendationCache = { ids: [], expiresAt: now + 30 * 1000 };
                return [];
            }
        }

        /**
         * Boost listings that the recommendation API ranks highly.
         */
        async applyServerRecommendationBoosting(listings) {
            if (!Array.isArray(listings) || listings.length === 0) {
                return listings;
            }

            const recommendedIds = await this.getServerRecommendationIds(false);
            if (!recommendedIds.length) {
                return listings;
            }

            const idPriority = new Map();
            recommendedIds.forEach((id, index) => {
                idPriority.set(String(id), recommendedIds.length - index);
            });

            const enriched = listings.map((listing, index) => ({
                ...listing,
                _serverBoost: idPriority.get(String(listing.id)) || 0,
                _originalOrder: index
            }));

            enriched.sort((a, b) => {
                if (b._serverBoost === a._serverBoost) {
                    return a._originalOrder - b._originalOrder;
                }
                return b._serverBoost - a._serverBoost;
            });

            return enriched.map(({ _serverBoost, _originalOrder, ...listing }) => listing);
        }

        /**
         * Get user preferences from localStorage
         */
        getUserPreferences() {
            try {
                const prefsJson = localStorage.getItem('motorlink_user_preferences');
                if (!prefsJson) return null;
                return JSON.parse(prefsJson);
            } catch (error) {
                return null;
            }
        }

        /**
         * Track user viewing a car listing
         * Called when user clicks on a car card or views a car detail page
         */
        trackCarView(listing) {
            if (!listing) return;
            // Only track for authenticated users
            if (!this.currentUser) return;
            
            try {
                let preferences = this.getUserPreferences() || {
                    makes: {},
                    models: {},
                    colors: {},
                    body_types: {},
                    fuel_types: {},
                    transmissions: {},
                    conditions: {},
                    price_range: { min: Infinity, max: 0, total: 0, count: 0, avg: 0 },
                    year_range: { min: Infinity, max: 0, total: 0, count: 0, avg: 0 },
                    mileage_range: { min: Infinity, max: 0, total: 0, count: 0, avg: 0 },
                    last_updated: Date.now()
                };
                
                // Track make
                if (listing.make_name) {
                    preferences.makes[listing.make_name] = (preferences.makes[listing.make_name] || 0) + 1;
                }
                
                // Track model
                if (listing.model_name) {
                    preferences.models[listing.model_name] = (preferences.models[listing.model_name] || 0) + 1;
                }
                
                // Track color
                if (listing.color) {
                    preferences.colors[listing.color] = (preferences.colors[listing.color] || 0) + 1;
                }
                
                // Track body type
                if (listing.body_type) {
                    preferences.body_types[listing.body_type] = (preferences.body_types[listing.body_type] || 0) + 1;
                }
                
                // Track fuel type
                if (listing.fuel_type) {
                    preferences.fuel_types[listing.fuel_type] = (preferences.fuel_types[listing.fuel_type] || 0) + 1;
                }
                
                // Track transmission
                if (listing.transmission) {
                    preferences.transmissions[listing.transmission] = (preferences.transmissions[listing.transmission] || 0) + 1;
                }
                
                // Track condition
                if (listing.condition) {
                    preferences.conditions[listing.condition] = (preferences.conditions[listing.condition] || 0) + 1;
                }
                
                // Track price range
                if (listing.price) {
                    const price = parseInt(listing.price);
                    preferences.price_range.min = Math.min(preferences.price_range.min, price);
                    preferences.price_range.max = Math.max(preferences.price_range.max, price);
                    preferences.price_range.total += price;
                    preferences.price_range.count++;
                    preferences.price_range.avg = preferences.price_range.total / preferences.price_range.count;
                }
                
                // Track year range
                if (listing.year) {
                    const year = parseInt(listing.year);
                    preferences.year_range.min = Math.min(preferences.year_range.min, year);
                    preferences.year_range.max = Math.max(preferences.year_range.max, year);
                    preferences.year_range.total += year;
                    preferences.year_range.count++;
                    preferences.year_range.avg = preferences.year_range.total / preferences.year_range.count;
                }
                
                // Track mileage range
                if (listing.mileage) {
                    const mileage = parseInt(listing.mileage);
                    preferences.mileage_range.min = Math.min(preferences.mileage_range.min, mileage);
                    preferences.mileage_range.max = Math.max(preferences.mileage_range.max, mileage);
                    preferences.mileage_range.total += mileage;
                    preferences.mileage_range.count++;
                    preferences.mileage_range.avg = preferences.mileage_range.total / preferences.mileage_range.count;
                }
                
                preferences.last_updated = Date.now();
                
                // Save to localStorage (for offline/fallback)
                localStorage.setItem('motorlink_user_preferences', JSON.stringify(preferences));
                
                // Send to server for persistent tracking (both guest and logged-in users)
                this.sendViewTrackingToServer(listing.id, preferences);
                
            } catch (error) {
                console.error('Error tracking car view:', error);
            }
        }

        /**
         * Send view tracking data to server for both guest and logged-in users
         */
        sendViewTrackingToServer(listingId, preferences) {
            // Only send tracking for authenticated users
            if (!this.currentUser) return;

            const recommendationUrl = this.getRecommendationApiUrl();

            // Don't block the main thread - use beacon API or async fetch
            const trackingData = {
                action: 'track_view',
                listing_id: listingId,
                preferences: preferences,
                session_id: this.getOrCreateSessionId(),
                timestamp: Date.now()
            };

            // Use sendBeacon for reliability (works even when page is closing)
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(trackingData)], { type: 'application/json' });
                navigator.sendBeacon(`${recommendationUrl}?action=track_view`, blob);
            } else {
                // Fallback to fetch
                fetch(recommendationUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(trackingData),
                    credentials: 'include',
                    keepalive: true // Important for tracking during page unload
                }).catch(err => {
                    // Silently fail - don't disrupt user experience
                });
            }
        }

        /**
         * Get or create a session ID for guest user tracking
         */
        getOrCreateSessionId() {
            let sessionId = localStorage.getItem('motorlink_session_id');
            if (!sessionId) {
                // Generate a unique session ID
                sessionId = 'guest_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('motorlink_session_id', sessionId);
            }
            return sessionId;
        }

        // ============================================================================
        // LISTINGS RENDERING (Enhanced for BLOB Images)
        // ============================================================================
        
        renderListings(listings) {
            const listingsGrid = document.querySelector('.listings-grid');
            if (!listingsGrid) return;
            
            if (listings.length === 0) {
                listingsGrid.innerHTML = this.getNoResultsHTML();
                return;
            }
            
            // Calculate distance for each listing if user location is available
            if (this.userLocation) {
                listings.forEach(listing => {
                    if (listing.dealer_latitude && listing.dealer_longitude) {
                        listing.distance = this.calculateDistance(
                            this.userLocation.lat,
                            this.userLocation.lng,
                            parseFloat(listing.dealer_latitude),
                            parseFloat(listing.dealer_longitude)
                        );
                    }
                });
                
                // Sort by distance if "nearest" is selected
                const sortSelect = document.querySelector('.sort-select');
                if (sortSelect && sortSelect.value === 'nearest') {
                    const hasDistanceData = listings.some(item => item.distance !== undefined);
                    if (hasDistanceData) {
                        listings.sort((a, b) => {
                            const distA = a.distance !== undefined ? a.distance : 999999;
                            const distB = b.distance !== undefined ? b.distance : 999999;
                            return distA - distB;
                        });
                    } else {
                        sortSelect.value = 'newest';
                        this.showToast('Nearest sort is unavailable right now. Showing newest listings instead.', 'info');
                    }
                }
            }
            
            const html = listings.map(listing => this.createListingHTML(listing)).join('');
            listingsGrid.innerHTML = html;
            
            this.setupListingCards();
        }
        
        appendListings(listings) {
            const listingsGrid = document.querySelector('.listings-grid');
            if (!listingsGrid || listings.length === 0) return;
            
            const html = listings.map(listing => this.createListingHTML(listing)).join('');
            listingsGrid.insertAdjacentHTML('beforeend', html);
            
            this.setupListingCards();
        }
        
        createListingHTML(listing) {
            const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22400%22 height=%22300%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22150%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EImage unavailable%3C/text%3E%3C/svg%3E';
            const rawTitle = String(listing.title || [listing.year, listing.make_name, listing.model_name].filter(Boolean).join(' ') || 'Vehicle Listing').trim();
            const escapedTitle = this.escapeHtml(rawTitle);
            const escapedTitleForJs = rawTitle.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            const locationText = this.escapeHtml(listing.location_name || 'Location not specified');
            const yearText = listing.year || 'N/A';
            const fuelTypeText = listing.fuel_type ? this.capitalize(listing.fuel_type) : 'N/A';
            const listingDataAttr = encodeURIComponent(JSON.stringify({
                id: listing.id,
                make_name: listing.make_name || '',
                model_name: listing.model_name || '',
                year: listing.year || '',
                price: listing.price || '',
                fuel_type: listing.fuel_type || '',
                transmission: listing.transmission || ''
            }));

            // Check for is_featured and is_premium flags
            const isFeatured = listing.is_featured == 1;
            const isPremium = listing.is_premium == 1;

            // Build badge HTML - can show both badges
            let badgeHTML = '';
            if (isPremium) {
                badgeHTML += '<div class="car-badge premium"><i class="fas fa-crown"></i> Premium</div>';
            }
            if (isFeatured) {
                badgeHTML += '<div class="car-badge featured"><i class="fas fa-star"></i> Featured</div>';
            }

            // Build images array for carousel - featured image first
            let images = [];
            let featuredImageId = listing.featured_image_id || null;

            // If the listing has gallery images with IDs, prefer those API-served
            // images before any raw uploads path so stale filenames do not trigger 404s.
            if (!featuredImageId && Array.isArray(listing.images) && listing.images.length > 0) {
                const fallbackImageWithId = listing.images.find(img => img && typeof img === 'object' && img.id);
                if (fallbackImageWithId?.id) {
                    featuredImageId = fallbackImageWithId.id;
                }
            }

            // Prefer API-served image first because it gracefully falls back if file is missing.
            if (featuredImageId) {
                images.push(`${CONFIG.API_URL}?action=image&id=${featuredImageId}`);
            } else if (listing.featured_image) {
                images.push(`${CONFIG.BASE_URL}uploads/${listing.featured_image}`);
            }

            // Add remaining images from images array (skip if it's the featured image)
            if (listing.images && Array.isArray(listing.images) && listing.images.length > 0) {
                listing.images.forEach(img => {
                    let imgUrl = '';
                    if (img.id) {
                        // Skip if this is the featured image we already added
                        if (img.id == featuredImageId) return;
                        // BLOB image - fetch via API
                        imgUrl = `${CONFIG.API_URL}?action=image&id=${img.id}`;
                    } else if (img.filename) {
                        // Skip if matches featured_image filename
                        if (img.filename === listing.featured_image) return;
                        // File-based image
                        imgUrl = `${CONFIG.BASE_URL}uploads/${img.filename}`;
                    } else if (typeof img === 'string') {
                        if (img === listing.featured_image) return;
                        imgUrl = `${CONFIG.BASE_URL}uploads/${img}`;
                    }
                    if (imgUrl && !images.includes(imgUrl)) {
                        images.push(imgUrl);
                    }
                });
            }

            // Fallback to placeholder if no images at all
            if (images.length === 0) {
                images.push(inlinePlaceholder);
            }

            // Limit to 5 images for carousel performance
            images = images.slice(0, 5);

            // Generate carousel HTML
            const hasMultipleImages = images.length > 1;
            // Use image_count from API if available, otherwise use images array length
            const totalImageCount = listing.image_count || listing.images_count || images.length;

            // Image count badge removed
            const imageCountBadge = '';

            // Thumbnail row: up to 3 extra images under the featured image
            const thumbs = images.slice(1, 4);
            const remainingThumbCount = Math.max(0, totalImageCount - (thumbs.length + 1));
            const galleryStripHTML = thumbs.length > 0 ? `
                <div class="car-gallery-strip thumb-count-${thumbs.length}">
                    ${thumbs.map((img, idx) => `
                        <div class="gallery-thumb" onclick="event.stopPropagation(); openImageGallery(${listing.id}, '${escapedTitleForJs}')">
                            <img src="${img}" alt="${escapedTitle}" loading="lazy"
                                 onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                            ${remainingThumbCount > 0 && idx === thumbs.length - 1 ? `<span class="gallery-thumb-more">+${remainingThumbCount}</span>` : ''}
                        </div>
                    `).join('')}
                </div>
            ` : '';

            // Determine seller type based on user_type field
            // Business types: dealer, garage, car_hire are displayed as "Dealer"
            // Individual users are displayed as "Private Seller"
            const userType = listing.user_type || listing.seller_type || 'individual';
            const isBusinessSeller = userType === 'dealer' || userType === 'garage' || userType === 'car_hire';
            const sellerTypeText = isBusinessSeller ? 'Dealer' : 'Private Seller';
            const sellerTypeIcon = isBusinessSeller ? 'fa-building' : 'fa-user';

            return `
                <div class="car-card" data-id="${listing.id}" data-listing="${listingDataAttr}">
                    <div class="car-gallery">
                        <div class="car-gallery-main">
                            <img src="${images[0]}" alt="${escapedTitle}"
                                 onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                            ${badgeHTML}
                            ${imageCountBadge}
                        </div>
                        ${galleryStripHTML}
                    </div>
                    <div class="car-info">
                        <h3 class="car-title">${escapedTitle}</h3>
                        <div class="car-price-section">
                            <div class="car-price">
                                <span class="currency">${CONFIG.CURRENCY_CODE || 'MWK'}</span>
                                <span class="amount">${this.formatNumber(listing.price)}</span>
                            </div>
                            ${listing.negotiable == 1 ? '<span class="negotiable-badge">Negotiable</span>' : ''}
                        </div>
                        <div class="car-seller-info">
                            <i class="fas ${sellerTypeIcon}"></i>
                            <span>${sellerTypeText}</span>
                        </div>
                        <div class="car-details">
                            <div class="car-detail">
                                <i class="fas fa-calendar"></i>
                                <span>${yearText}</span>
                            </div>
                            <div class="car-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>${locationText}</span>
                            </div>
                            ${listing.distance !== undefined ? `
                            <div class="car-detail distance-detail">
                                <i class="fas fa-location-arrow"></i>
                                <span>${listing.distance} km away</span>
                            </div>
                            ` : ''}
                            ${listing.mileage ? `
                            <div class="car-detail">
                                <i class="fas fa-road"></i>
                                <span>${this.formatNumber(listing.mileage)} km</span>
                            </div>
                            ` : ''}
                            <div class="car-detail">
                                <i class="fas fa-gas-pump"></i>
                                <span>${fuelTypeText}</span>
                            </div>
                        </div>
                        <div class="car-meta">
                            <span><i class="fas fa-eye"></i> ${listing.views_count || 0} views</span>
                            <span><i class="fas fa-clock"></i> ${this.timeAgo(listing.created_at)}</span>
                        </div>
                    </div>
                </div>
            `;
        }
        
        setupListingCards() {
            const cards = document.querySelectorAll('.car-card:not([data-setup])');
            
            cards.forEach(card => {
                card.setAttribute('data-setup', 'true');
                card.addEventListener('click', (e) => {
                    const listingId = card.dataset.id;
                    
                    // Track the view for recommendation engine
                    try {
                        const listingPayload = card.dataset.listing ? decodeURIComponent(card.dataset.listing) : '{}';
                        const listingData = JSON.parse(listingPayload);
                        if (listingData && listingData.id) {
                            this.trackCarView(listingData);
                        }
                    } catch (error) {
                        // Silently fail - don't block navigation
                    }
                    
                    window.location.href = `${CONFIG.BASE_URL}car.html?id=${listingId}`;
                });
                
                // Add hover effect
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-4px)';
                });
                
                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0)';
                });
            });
        }

        // ============================================================================
        // FILTER MANAGEMENT
        // ============================================================================
        
        getCurrentFilters() {
            const filters = {};
            
            // Category from active tab
            const activeTab = document.querySelector('.category-tab.active');
            if (activeTab && activeTab.dataset.category && activeTab.dataset.category !== 'all') {
                filters.category = activeTab.dataset.category;
            }
            
            // Search term - keep both search bars synchronized and resolve to one value
            const searchInputs = Array.from(document.querySelectorAll('.search-input'));
            const primarySearchValue = document.querySelector('.listings-search .search-input')?.value.trim() || '';
            const sidebarSearchValue = document.querySelector('.sidebar .search-input')?.value.trim() || '';
            const fallbackSearchValue = searchInputs.map(input => input.value.trim()).find(Boolean) || '';
            const searchValue = primarySearchValue || sidebarSearchValue || fallbackSearchValue;

            if (searchValue) {
                filters.search = searchValue;
                searchInputs.forEach(input => {
                    if (input.value.trim() !== searchValue) {
                        input.value = searchValue;
                    }
                });
            }
            
            // Filter inputs (search is already handled above)
            const filterInputs = document.querySelectorAll('.filter-select, .filter-input:not([name="search"])');
            filterInputs.forEach(input => {
                if (input.value && input.name) {
                    filters[input.name] = input.value;
                }
            });
            
            // Sort
            const sortSelect = document.querySelector('.sort-select');
            if (sortSelect && sortSelect.value) {
                filters.sort = this.normalizeSortValue(sortSelect.value);
            }
            
            return filters;
        }
        
        applyFilter(key, value) {
            this.filters[key] = value;
            this.loadListings();
        }
        
        applyFilters() {
            this.currentPage = 1; // Reset to page 1 when filters change
            this.hasMorePages = true; // Reset pagination state
            this.filters = this.getCurrentFilters();
            this.loadListings();
        }
        
        clearFilters() {
            // Clear filter inputs
            const filterInputs = document.querySelectorAll('.filter-select, .filter-input');
            filterInputs.forEach(input => {
                input.value = '';
            });

            // Clear all search bars (sidebar + top search)
            const searchInputs = document.querySelectorAll('.search-input');
            searchInputs.forEach(input => {
                input.value = '';
            });

            // Reset sort to default and hide nearby helper button
            const sortSelect = document.querySelector('.sort-select');
            if (sortSelect) {
                sortSelect.value = 'newest';
            }
            const findNearbyBtn = document.getElementById('findNearbyBtn');
            if (findNearbyBtn) {
                findNearbyBtn.style.display = 'none';
                findNearbyBtn.disabled = false;
                findNearbyBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Find Nearby';
            }
            
            // Reset category tabs
            const tabs = document.querySelectorAll('.category-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            const allTab = document.querySelector('.category-tab[data-category="all"]');
            if (allTab) {
                allTab.classList.add('active');
            }

            this.filters = {};
            this.applyFilters();
            
            this.showToast('Filters cleared!', 'success');
        }
        
        performSearch() {
            this.applyFilters();
        }

        // ============================================================================
        // TOUR SYSTEM
        // ============================================================================
        
        startTour() {
            const siteName = getRuntimeSiteName();
            const tourSteps = [
                {
                    element: '.hero-search',
                    title: `🚗 Welcome to ${siteName}!`,
                    content: 'Search for your dream car using our powerful search feature.',
                    position: 'bottom'
                },
                {
                    element: '.services-section',
                    title: '🏢 Our Services',
                    content: 'Explore our comprehensive automotive services.',
                    position: 'top'
                },
                {
                    element: '.category-tabs',
                    title: '🎯 Quick Categories',
                    content: 'Browse cars by category for faster results.',
                    position: 'bottom'
                },
                {
                    element: '.listings-section',
                    title: '📋 Browse Results',
                    content: 'Click on any car card to view detailed information.',
                    position: 'top'
                }
            ];
            
            this.showTourSteps(tourSteps);
        }
        
        showTourSteps(steps) {
            // Tour implementation would go here
            // For now, just mark as completed
            localStorage.setItem('tour_completed', 'true');
            this.showToast(`Welcome to ${getRuntimeSiteName()}! 🎉`, 'success');
        }

        // ============================================================================
        // UTILITY FUNCTIONS
        // ============================================================================
        
        animateCounter(elementId, finalValue) {
            const element = document.getElementById(elementId);
            if (!element) return;
            
            // Show value instantly - no animation for better UX
            element.textContent = Math.floor(finalValue).toLocaleString();
        }
        
        showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            const colors = {
                success: '#28a745',
                error: '#dc3545',
                warning: '#ffc107',
                info: '#17a2b8'
            };
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                padding: 16px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                border-left: 4px solid ${colors[type] || colors.info};
                max-width: 350px;
                font-size: 14px;
                animation: slideInRight 0.3s ease;
            `;
            
            toast.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #666;">×</button>
                </div>
            `;
            
            // Add animation styles if not already present
            if (!document.querySelector('#toast-styles')) {
                const style = document.createElement('style');
                style.id = 'toast-styles';
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 5000);
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
        
        capitalize(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
        }
        
        timeAgo(dateString) {
            if (!dateString) return '';
            
            const date = new Date(dateString);
            const now = new Date();
            const diffInSeconds = Math.floor((now - date) / 1000);
            
            if (diffInSeconds < 60) return 'just now';
            if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + ' min ago';
            if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + ' h ago';
            if (diffInSeconds < 2592000) return Math.floor(diffInSeconds / 86400) + ' d ago';
            return date.toLocaleDateString();
        }
        
        getLoadingHTML() {
            return `
                <div class="loading" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                    <div class="app-loader" style="margin: 0 auto 24px;">
                        <div class="app-loader-circle"></div>
                </div>
                    <div class="dots-loader" style="margin-bottom: 20px;">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <h3 style="color: #666; margin: 0 0 8px 0; font-size: 18px; font-weight: 600;">Loading cars...</h3>
                    <p style="color: #999; margin: 0; font-size: 14px;">Please wait while we fetch the latest listings</p>
                </div>
            `;
        }
        
        getNoResultsHTML() {
            return `
                <div class="no-results" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px;">
                    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#fff3e0,#ffe0b2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;box-shadow:0 4px 16px rgba(255,111,0,0.15);">
                        <i class="fas fa-search" style="font-size:28px;color:#ff6f00;"></i>
                    </div>
                    <h3 style="color:#1f2937;font-size:1.15rem;font-weight:700;margin:0 0 8px;">No cars found</h3>
                    <p style="color:#6b7280;font-size:0.9rem;margin:0 0 24px;">Try adjusting your search criteria or browse all categories</p>
                    <button onclick="motorLink.clearFilters()" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:linear-gradient(135deg,#ff6f00,#f57c00);color:#fff;font-size:0.85rem;font-weight:700;border:none;border-radius:999px;cursor:pointer;box-shadow:0 4px 14px rgba(255,111,0,0.35);letter-spacing:0.02em;transition:all 0.2s ease;">
                        <i class="fas fa-undo" style="font-size:0.8rem;"></i> Clear Filters
                    </button>
                </div>
            `;
        }
        
        showError(message) {
            const listingsGrid = document.querySelector('.listings-grid');
            if (listingsGrid) {
                listingsGrid.innerHTML = `
                    <div class="error-state" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: #dc3545; margin-bottom: 20px;"></i>
                        <h3 style="color: #666; margin-bottom: 12px;">Error Loading Cars</h3>
                        <p style="color: #999; margin-bottom: 24px;">${this.escapeHtml(message)}</p>
                        <button class="btn btn-primary" onclick="motorLink.loadListings()" style="padding: 12px 24px; background: #ff6f00; color: white; border: none; border-radius: 6px; cursor: pointer;">
                            <i class="fas fa-refresh"></i> Try Again
                        </button>
                    </div>
                `;
            }
            
            this.showToast(message, 'error');
        }
    }

// ============================================================================
// AUTO‐TITLE POPULATION
// ============================================================================
function setupAutoTitle() {
    const makeSelect      = document.querySelector('select[name="make_id"]');
    const modelSelect     = document.querySelector('select[name="model_id"]');
    const yearSelect      = document.querySelector('select[name="year"]');
    const conditionSelect = document.querySelector('select[name="condition_type"]');
    const titleInput      = document.querySelector('input[name="title"]');

    // Only proceed if we're on sell.html
    if (!makeSelect || !modelSelect || !yearSelect || !conditionSelect || !titleInput) {
        return;
    }

    function updateTitle() {
        const year      = yearSelect.value;
        const make      = makeSelect.selectedOptions[0]?.text;
        const model     = modelSelect.selectedOptions[0]?.text;
        const condition = conditionSelect.selectedOptions[0]?.text;
        
        if (year && make && make !== 'Select Make' && model && model !== 'Select Model' && condition && condition !== 'Select Condition') {
            titleInput.value = `${year} ${make} ${model} – ${condition}`;
            titleInput.dispatchEvent(new Event('input')); // Trigger validation
        }
    }

    [yearSelect, makeSelect, modelSelect, conditionSelect].forEach(el => {
        if (el) el.addEventListener('change', updateTitle);
    });
}



// ============================================================================
// GLOBAL PROFILE MANAGEMENT FUNCTIONS
// ============================================================================

// Global function to open profile settings (called from dashboard)
window.openProfileSettings = function() {
    if (window.motorLink) {
        window.motorLink.openProfileSettings();
    }
}

// Global function to close profile modal
// Update the existing closeProfileModal function:
window.closeProfileModal = function() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// Global function to update profile
async function updateProfile() {
    const form = document.getElementById('profileForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    try {
        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        // Get profile data
        const profileData = {
            full_name: document.getElementById('profileFullName').value.trim(),
            phone: document.getElementById('profilePhone').value.trim(),
            whatsapp: document.getElementById('profileWhatsapp').value.trim(),
            city: document.getElementById('profileCity').value.trim(),
            address: document.getElementById('profileAddress').value.trim()
        };
        
        // Get password data
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        
        // Validate required fields
        if (!profileData.full_name) {
            throw new Error('Full name is required');
        }
        
        // Check if password change is requested
        if (currentPassword || newPassword || confirmPassword) {
            if (!currentPassword) {
                throw new Error('Current password is required to change password');
            }
            if (!newPassword) {
                throw new Error('New password is required');
            }
            if (newPassword.length < 6) {
                throw new Error('New password must be at least 6 characters');
            }
            if (newPassword !== confirmPassword) {
                throw new Error('New passwords do not match');
            }
            
            // Add password data to update
            profileData.current_password = currentPassword;
            profileData.new_password = newPassword;
        }
        
        
        const response = await fetch(`${CONFIG.API_URL}?action=update_profile`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'}),
            body: JSON.stringify(profileData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Profile updated successfully!', 'success');
            
            // Update the user name in the header if it changed
            const userNameElements = document.querySelectorAll('#userName, #dashboardUserName');
            userNameElements.forEach(el => {
                if (el) el.textContent = profileData.full_name;
            });
            
            // Update session data
            if (window.motorLink && window.motorLink.currentUser) {
                window.motorLink.currentUser.name = profileData.full_name;
            }
            
            // Close modal after successful update
            setTimeout(() => {
                closeProfileModal();
            }, 1000);
            
        } else {
            throw new Error(data.message || 'Failed to update profile');
        }
        
    } catch (error) {
        showToast(error.message || 'Error updating profile', 'error');
    } finally {
        // Reset button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Setup profile form event listener
function setupProfileForm() {
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        // Remove the old onsubmit handler and use event listener instead
        profileForm.onsubmit = null;
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateProfile();
        });
    }
}

// ============================================================================
// GLOBAL DASHBOARD FUNCTIONS
// ============================================================================

function openMyListings() {
    window.location.href = `${CONFIG.BASE_URL}my-listings.html`;
}

function openMessages() {
    window.location.href = `${CONFIG.BASE_URL}chat_system.html`;
}

function openFavorites() {
    window.location.href = `${CONFIG.BASE_URL}favorites.html`;
}

// Global logout function
window.logout = async function() {
    try {
        await fetch(`${CONFIG.API_URL}?action=logout`, {
            method: 'POST',
            ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
        });
    } catch (error) {
    } finally {
        // Always clear all local data
        if (window.motorLink) {
            window.motorLink.currentUser = null;
        }

        // Clear all localStorage data
        localStorage.removeItem('motorlink_user');
        localStorage.removeItem('motorlink_authenticated');
        localStorage.removeItem('motorlink_favorites');

        // Clear session storage
        sessionStorage.clear();

        // CRITICAL: Remove dashboard link from DOM before redirect
        // Remove desktop dashboard link
        const dashboardLinks = document.querySelectorAll('.dashboard-link');
        dashboardLinks.forEach(link => link.remove());
        
        // Remove mobile dashboard links
        const mobileDashLinks = document.querySelectorAll('.mobile-dash-link');
        mobileDashLinks.forEach(link => link.remove());
        
        // Also remove any dashboard links by href
        const dashboardHrefLinks = document.querySelectorAll('a[href="dealer-dashboard.html"], a[href="garage-dashboard.html"], a[href="car-hire-dashboard.html"], a[href="admin/admin.html"]');
        dashboardHrefLinks.forEach(link => {
            // Only remove if it's a dashboard link (not if it's part of another structure)
            if (link.classList.contains('dashboard-link') || link.classList.contains('mobile-dash-link')) {
                link.remove();
            }
        });

        // Hide mobile avatar
        if (typeof hideMobileAvatar === 'function') hideMobileAvatar();

        // Redirect to home page
        window.location.href = CONFIG.BASE_URL || 'index.html';
    }
};


// ============================================================================
// CAR DETAIL MANAGER in car.js
// ============================================================================


// ============================================================================
// DEALERS MANAGER
// ============================================================================

class DealersManager {
    constructor() {
        this.dealers = [];
        this.filteredDealers = [];
        this.currentUser = null;
        this.userLocation = null;
        this.geocodedDealers = new Map(); // Cache for geocoded addresses
        this.perPage = 25;
        this.currentPage = 1;
        this.init();
    }

    async init() {
        await this.checkAuthentication();
        this.getUserLocation();
        await this.loadDealers();
        this.setupEventListeners();
        this.setupDelegatedEvents();
        this.setupCardClickEvents();
        this.renderDealers();
        this.populateLocationFilter();
        this.populateSpecializationFilter();
        // Mobile menu handled by mobile-menu.js
        // this.setupMobileMenu();
    }

    async checkAuthentication() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
            });
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
                this.updateUserMenu();
            }
        } catch (error) {
        }
    }

    updateUserMenu() {
        const userInfo = document.getElementById('userInfo');
        const guestMenu = document.getElementById('guestMenu');
        const userName = document.getElementById('userName');

        if (this.currentUser) {
            if (userInfo) userInfo.style.display = 'flex';
            if (guestMenu) guestMenu.style.display = 'none';
            if (userName) userName.textContent = this.currentUser.name;
        }
    }

    async loadDealers() {
    const loadingContainer = document.getElementById('loadingContainer');
    const dealersGrid = document.getElementById('dealersGrid');
    const emptyState = document.getElementById('emptyState');

    try {
        if (loadingContainer) loadingContainer.style.display = 'block';
        if (dealersGrid) dealersGrid.innerHTML = '';
        if (emptyState) emptyState.style.display = 'none';

        const response = await fetch(`${CONFIG.API_URL}?action=dealers`, {
            ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
        });
        
        // Check if response is valid JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            console.error('API returned non-JSON response for dealers');
            return;
        }
        
        const data = await response.json();

        if (data.success && data.dealers) {
            this.dealers = data.dealers;
            this.filteredDealers = [...this.dealers];
            
            // Update hero stats with the loaded dealers
            this.updateHeroStats(this.dealers);
            this.renderDealers();
            this.updateResultsCount();
            this.populateLocationFilter();
            this.populateSpecializationFilter();

            // Geocode addresses and calculate distances if user location is already available
            if (this.userLocation) {
                this.geocodeAndRenderDealers();
            }
        } else {
            throw new Error(data.message || 'Failed to load dealers');
        }
    } catch (error) {
        this.showError('Failed to load dealers. Please try again later.');
    } finally {
        if (loadingContainer) loadingContainer.style.display = 'none';
    }
}

    getUserLocation() {
        if (!navigator.geolocation) {
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            (position) => {
                this.userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                
                // Re-render dealers with distance information if already loaded
                if (this.dealers.length > 0) {
                    this.geocodeAndRenderDealers();
                }
            },
            (error) => {
                // Silently handle geolocation errors
            },
            {
                enableHighAccuracy: false,
                timeout: 10000,
                maximumAge: 300000 // Cache for 5 minutes
            }
        );
    }

    async geocodeDealerAddress(address, locationName) {
        // Validate parameters
        if (!address || !address.trim()) return null;
        
        const fullAddress = buildRuntimeAddress([address, locationName, getRuntimeCountryName()]);
        
        // Check cache first
        if (this.geocodedDealers.has(fullAddress)) {
            return this.geocodedDealers.get(fullAddress);
        }
        
        // Check if Google Maps is available
        if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
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
                        this.geocodedDealers.set(fullAddress, location);
                        resolve(location);
                    } else {
                        resolve(null);
                    }
                });
            });
        } catch (error) {
            console.error('Error geocoding address:', error);
            return null;
        }
    }

    async geocodeAndRenderDealers() {
        if (!this.userLocation) {
            return;
        }
        
        // Geocode addresses for dealers that have addresses
        const geocodePromises = this.dealers
            .filter(dealer => dealer.address)
            .map(async (dealer) => {
                const coords = await this.geocodeDealerAddress(dealer.address, dealer.location_name);
                if (coords) {
                    dealer.latitude = coords.lat;
                    dealer.longitude = coords.lng;
                    dealer.distance = this.calculateDistance(
                        this.userLocation.lat,
                        this.userLocation.lng,
                        coords.lat,
                        coords.lng
                    );
                }
            });
        
        await Promise.all(geocodePromises);
        
        // Re-render dealers with distance information
        this.filteredDealers = [...this.dealers];
        this.renderDealers();
    }

    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Radius of the Earth in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return Math.round((R * c) * 10) / 10; // Distance in km, rounded to 1 decimal
    }

    setupEventListeners() {
        // Filter events
        const applyFiltersBtn = document.getElementById('applyFilters');
        const clearFiltersBtn = document.getElementById('clearFilters');
        const sortSelect = document.getElementById('sortSelect');
        const dealerSearch = document.getElementById('dealerSearch');

        if (applyFiltersBtn) applyFiltersBtn.addEventListener('click', () => this.applyFilters());
        if (clearFiltersBtn) clearFiltersBtn.addEventListener('click', () => this.clearFilters());
        if (sortSelect) sortSelect.addEventListener('change', () => this.applyFilters());
        if (dealerSearch) dealerSearch.addEventListener('input', () => this.applyFilters());

        // Auto-apply on dropdown change
        ['locationFilter', 'specializationFilter', 'verificationFilter'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', () => this.applyFilters());
        });

        // Mobile hero search — syncs to desktop #dealerSearch
        const mobileDealerSearch = document.getElementById('mobileDealerSearch');
        const mobileDealerSearchBtn = document.getElementById('mobileDealerSearchBtn');
        if (mobileDealerSearch && mobileDealerSearchBtn) {
            const doMobileSearch = () => {
                if (dealerSearch) dealerSearch.value = mobileDealerSearch.value.trim();
                this.applyFilters();
            };
            mobileDealerSearchBtn.addEventListener('click', doMobileSearch);
            mobileDealerSearch.addEventListener('input', doMobileSearch);
            mobileDealerSearch.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); doMobileSearch(); }
            });
            if (dealerSearch) {
                dealerSearch.addEventListener('input', () => {
                    if (!dealerSearch.value) mobileDealerSearch.value = '';
                });
            }
        }
    }

    setupDelegatedEvents() {
        const dealersGrid = document.getElementById('dealersGrid');
        if (!dealersGrid) return;
        
        // Use event delegation for contact buttons
        dealersGrid.addEventListener('click', (e) => {
            const contactBtn = e.target.closest('.btn-contact');
            if (contactBtn) {
                e.preventDefault();
                e.stopPropagation();
                const dealerId = contactBtn.getAttribute('data-dealer-id');
                const dealer = this.dealers.find(d => d.id == dealerId);
                if (dealer) {
                    this.openContactModal(dealer);
                }
            }
        });
    }

    setupCardClickEvents() {
        const dealersGrid = document.getElementById('dealersGrid');
        if (!dealersGrid) return;
        
        // Use event delegation for entire card clicks
        dealersGrid.addEventListener('click', (e) => {
            // If click is on contact button, let the contact handler deal with it
            if (e.target.closest('.btn-contact')) {
                return;
            }
            
            // If click is on the card itself (not on buttons), open showroom
            const dealerCard = e.target.closest('.dealer-card');
            if (dealerCard && !e.target.closest('.btn-showroom') && !e.target.closest('.btn-contact')) {
                const dealerId = dealerCard.getAttribute('data-dealer-id');
                const dealer = this.dealers.find(d => d.id == dealerId);
                if (dealer) {
                    window.location.href = `showroom.html?id=${dealer.id}`;
                }
            }
        });
    }

    // applyFilters() and clearFilters() defined in enhanced version below

    sortDealers(sortBy) {
        switch (sortBy) {
            case 'name':
                this.filteredDealers.sort((a, b) => a.business_name.localeCompare(b.business_name));
                break;
            case 'cars':
                this.filteredDealers.sort((a, b) => (b.total_cars || 0) - (a.total_cars || 0));
                break;
            case 'verified':
                this.filteredDealers.sort((a, b) => (b.verified ? 1 : 0) - (a.verified ? 1 : 0));
                break;
            case 'featured':
            default:
                this.filteredDealers.sort((a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0));
                break;
        }
    }

    renderDealers() {
        const dealersGrid = document.getElementById('dealersGrid');
        const emptyState = document.getElementById('emptyState');

        if (!dealersGrid) return;

        if (this.filteredDealers.length === 0) {
            dealersGrid.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';

        const total = this.filteredDealers.length;
        const perPage = this.perPage;
        const page = this.currentPage;
        const paged = perPage === 0 ? this.filteredDealers : this.filteredDealers.slice((page - 1) * perPage, page * perPage);

        dealersGrid.innerHTML = paged.map(dealer => this.createDealerCardHTML(dealer)).join('');

        // Inject pagination below grid
        let existingPag = dealersGrid.parentElement?.querySelector('.ml-pagination');
        if (existingPag) existingPag.remove();
        const pagHtml = this.buildDealersPaginationHTML(total, page, perPage);
        if (pagHtml) {
            const pagDiv = document.createElement('div');
            pagDiv.innerHTML = pagHtml;
            if (pagDiv.firstElementChild) dealersGrid.parentElement.appendChild(pagDiv.firstElementChild);
        }
    }

    buildDealersPaginationHTML(total, page, perPage) {
        if (total === 0) return '';
        const effPer = perPage === 0 ? total : perPage;
        const totalPages = Math.ceil(total / effPer);
        const start = (page - 1) * effPer + 1;
        const end   = Math.min(page * effPer, total);
        return `<div class="ml-pagination">
            <span class="ml-pag-info">Showing ${start}\u2013${end} of ${total}</span>
            <div class="ml-pag-controls">
                <button class="ml-pag-btn" onclick="window.dealersManager.setPage(${page - 1},${perPage})" ${page <= 1 ? 'disabled' : ''}>&#8249; Prev</button>
                <span class="ml-pag-pages">Page ${page} of ${totalPages}</span>
                <button class="ml-pag-btn" onclick="window.dealersManager.setPage(${page + 1},${perPage})" ${page >= totalPages ? 'disabled' : ''}>Next &#8250;</button>
            </div>
            <label class="ml-pag-perpage">Show:&nbsp;<select class="ml-pag-select" onchange="window.dealersManager.setPage(1,parseInt(this.value))">
                <option value="25" ${perPage===25?'selected':''}>25</option>
                <option value="50" ${perPage===50?'selected':''}>50</option>
                <option value="100" ${perPage===100?'selected':''}>100</option>
                <option value="200" ${perPage===200?'selected':''}>200</option>
                <option value="250" ${perPage===250?'selected':''}>250</option>
                <option value="0" ${perPage===0?'selected':''}>All</option>
            </select></label>
        </div>`;
    }

    setPage(page, perPage) {
        const total = this.filteredDealers.length;
        const effPer = perPage === 0 ? total : perPage;
        const totalPages = Math.ceil(total / effPer) || 1;
        this.currentPage = Math.max(1, Math.min(page, totalPages));
        this.perPage = perPage;
        this.renderDealers();
        const grid = document.getElementById('dealersGrid');
        if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    createDealerCardHTML(dealer) {
        const rating = parseFloat(dealer.rating) || 0;
        const totalCars = dealer.total_cars || 0;
        const totalReviews = dealer.total_reviews || 0;
        const yearsEstablished = dealer.years_established || 0;
        const totalSales = dealer.total_sales || 0;
        const isVerified = dealer.verified == 1;
        const isCertified = dealer.certified == 1;
        const isFeatured = dealer.featured == 1;
        const hasDistance = dealer.distance != null;
        const hasAddress = dealer.address && dealer.address.trim() !== '';

        // Calculate distance info (same logic as car-hire.js and garages.js)
        let distanceInfo = '';
        if (hasDistance) {
            let mapsUrl;
            if (dealer.latitude && dealer.longitude) {
                mapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${dealer.latitude},${dealer.longitude}&travelmode=driving`;
            } else {
                const q = encodeURIComponent(`${dealer.business_name || ''} ${dealer.address || dealer.location_name || ''}`.trim());
                mapsUrl = `https://www.google.com/maps/search/?api=1&query=${q}`;
            }
            distanceInfo = `<button type="button" class="loc-chip distance-info clickable-chip" onclick="event.stopPropagation(); window.open('${mapsUrl}', '_blank', 'noopener,noreferrer');" title="Get directions to ${this.escapeHtml(dealer.business_name || '')}"><i class="fas fa-location-arrow"></i> ${dealer.distance.toFixed(1)} km away</button>`;
        }

        // Generate star rating
        const starsHTML = this.generateStarRating(rating);

        return `
            <a href="showroom.html?id=${dealer.id}" class="dealer-business-card-link">
                <div class="dealer-business-card ${isFeatured ? 'featured-dealer' : ''}" data-dealer-id="${dealer.id}">
                    <div class="dealer-card-header">
                        <div class="dealer-header-left" style="display:flex;flex-direction:row;align-items:center;gap:10px;">
                            <div class="card-logo-dp"${dealer.logo_url ? ` style="background-image:url('${dealer.logo_url}')"` : ''}>
                                ${!dealer.logo_url ? '<i class="fas fa-store"></i>' : ''}
                            </div>
                            <h3 class="dealer-business-name">${this.escapeHtml(dealer.business_name)}</h3>
                        </div>
                        <div class="dealer-header-right">
                            ${isFeatured ? `<span class="dealer-status-tag featured-dealer-tag">Featured</span>` : ''}
                            ${isCertified ? `<span class="dealer-status-tag certified-dealer-tag"><i class="fas fa-certificate"></i> Certified</span>` : ''}
                            ${isVerified ? `<span class="dealer-status-tag verified-dealer-tag"><i class="fas fa-check-circle"></i> Verified</span>` : ''}
                        </div>
                    </div>

                    <div class="loc-panel">
                        <div class="loc-chips">
                            <span class="loc-chip"><i class="fas fa-map-marker-alt"></i> ${this.escapeHtml(dealer.location_name)}</span>
                            ${distanceInfo}
                        </div>
                        <div class="loc-stats">
                            <span class="loc-chip"><i class="fas fa-history"></i> ${yearsEstablished > 0 ? 'Est. ' + yearsEstablished : 'New'}</span>
                            <span class="loc-chip"><i class="fas fa-car"></i> ${totalCars > 0 ? totalCars + ' cars' : 'No cars'}</span>
                        </div>
                    </div>
                    ${hasAddress ? `
                        <div class="dealer-address">
                            <i class="fas fa-building"></i>
                            <span>${this.escapeHtml(dealer.address)}</span>
                        </div>
                    ` : ''}

                    <div class="dealer-card-body">
                        ${dealer.specialization ? `
                            ${(() => {
                                try {
                                    const specs = JSON.parse(dealer.specialization);
                                    if (Array.isArray(specs) && specs.length > 0) {
                                        return '<div class="dealer-specializations-display"><div class="dealer-specializations-list">' + specs.slice(0, 6).map(s => `<span class="dealer-spec-tag">${this.escapeHtml(s)}</span>`).join('') + (specs.length > 6 ? `<span class="dealer-spec-tag more-specs">+${specs.length - 6}</span>` : '') + '</div></div>';
                                    }
                                } catch (e) {}
                                return '';
                            })()}
                        ` : ''}
                    </div>

                    <div class="dealer-card-actions">
                        <span class="dealer-action-btn primary">
                            <i class="fas fa-store"></i>
                            <span>Show Showroom</span>
                        </span>
                    </div>
                </div>
            </a>
        `;
    }

    generateStarRating(rating) {
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

    openContactModal(dealer) {
        const modal = document.getElementById('contactModal');
        const title = document.getElementById('contactTitle');
        const content = document.getElementById('contactContent');
        
        if (!modal || !title || !content) return;
        
        title.textContent = `Contact ${dealer.business_name}`;
        
        content.innerHTML = `
            <div style="margin-bottom: 20px;">
                <h4 style="margin-bottom: 15px;">Contact Information</h4>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                    <i class="fas fa-phone" style="color: var(--primary-orange);"></i>
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">Phone Number</div>
                        <div style="font-size: 14px;">${dealer.phone || 'Not provided'}</div>
                    </div>
                </div>
                
                ${dealer.whatsapp ? `
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                    <i class="fab fa-whatsapp" style="color: #25D366;"></i>
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">WhatsApp</div>
                        <div style="font-size: 14px;">${dealer.whatsapp}</div>
                    </div>
                </div>
                ` : ''}
                
                <div style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                    <i class="fas fa-envelope" style="color: var(--primary-blue);"></i>
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">Email</div>
                        <div style="font-size: 14px;">${dealer.email || 'Not provided'}</div>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px;">
                ${dealer.phone ? `<a href="tel:${dealer.phone}" class="btn btn-primary" style="flex: 1; text-align: center;"><i class="fas fa-phone"></i> Call Now</a>` : ''}
                ${dealer.whatsapp ? `<a href="https://wa.me/${dealer.whatsapp.replace(/[^0-9]/g, '')}" class="btn btn-success" style="flex: 1; text-align: center;"><i class="fab fa-whatsapp"></i> WhatsApp</a>` : ''}
            </div>
        `;
        
        modal.style.display = 'flex';
    }

    updateResultsCount() {
        const countElement = document.getElementById('resultsCount');
        if (countElement) {
            countElement.textContent = `Showing ${this.filteredDealers.length} of ${this.dealers.length} dealers`;
        }
    }

    updateTotalDealers(count) {
        const totalElement = document.getElementById('totalDealers');
        if (totalElement) {
            totalElement.textContent = `${count}+`;
        }
    }

    showError(message) {
        const dealersGrid = document.getElementById('dealersGrid');
        if (dealersGrid) {
            dealersGrid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 16px;"></i>
                    <h3>Error Loading Dealers</h3>
                    <p>${message}</p>
                    <button onclick="window.dealersManager.loadDealers()" class="btn btn-primary" style="margin-top: 16px;">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                </div>
            `;
        }
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    setupMobileMenu() {
        const toggle = document.getElementById('mobileToggle');
        const nav = document.getElementById('mainNav');
        const userMenu = document.getElementById('userMenu');

        if (toggle && nav) {
            // Add login/register links to mobile menu
            const addMobileAuthLinks = () => {
                // Remove existing auth links if any
                const existingAuthLinks = nav.querySelectorAll('.mobile-auth-link');
                existingAuthLinks.forEach(link => link.remove());

                // Check if user is logged in
                const userData = this.getUser();

                if (userData) {
                    // User is logged in - add logout button
                    const logoutLink = document.createElement('a');
                    logoutLink.href = '#';
                    logoutLink.className = 'mobile-auth-link';
                    logoutLink.innerHTML = '<i class="fas fa-sign-out-alt"></i> Logout';
                    logoutLink.onclick = (e) => {
                        e.preventDefault();
                        logout();
                        nav.classList.remove('active');
                        const icon = toggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    };
                    nav.appendChild(logoutLink);
                } else {
                    // User not logged in - add login/register links
                    const loginLink = document.createElement('a');
                    loginLink.href = 'login.html';
                    loginLink.className = 'mobile-auth-link';
                    loginLink.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login';
                    nav.appendChild(loginLink);

                    const registerLink = document.createElement('a');
                    registerLink.href = 'register.html';
                    registerLink.className = 'mobile-auth-link';
                    registerLink.innerHTML = '<i class="fas fa-user-plus"></i> Register';
                    nav.appendChild(registerLink);
                }
            };

            toggle.addEventListener('click', () => {
                const isActive = nav.classList.contains('active');

                // Toggle navigation
                nav.classList.toggle('active');

                // Add/update auth links when opening menu
                if (!isActive) {
                    addMobileAuthLinks();
                }

                // Toggle icon
                const icon = toggle.querySelector('i');
                if (!isActive) {
                    icon.className = 'fas fa-times';
                } else {
                    icon.className = 'fas fa-bars';
                }
            });

            // Close menu when clicking nav links
            if (nav) {
                nav.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => {
                        nav.classList.remove('active');
                        if (userMenu) userMenu.classList.remove('mobile-active');
                        const icon = toggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    });
                });
            }

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!nav.contains(e.target) && !userMenu?.contains(e.target) && !toggle.contains(e.target)) {
                    nav.classList.remove('active');
                    if (userMenu) userMenu.classList.remove('mobile-active');
                    const icon = toggle.querySelector('i');
                    icon.className = 'fas fa-bars';
                }
            });
        }
    }
    // Add this method to calculate and update hero statistics
updateHeroStats(dealers) {
    // Total active dealers returned by the API
    const totalDealers = dealers.length;

    // Cars with active listings (sum of total_cars subquery per dealer)
    const totalCars = dealers.reduce((sum, dealer) => sum + (parseInt(dealer.total_cars) || 0), 0);

    // Unique cities/locations covered
    const uniqueCities = [...new Set(dealers.map(dealer => dealer.location_name).filter(Boolean))].length;

    // Dealers with a logo or certified/featured flag — meaningful "with profile" count
    const withProfile = dealers.filter(dealer =>
        dealer.logo_url || dealer.certified == 1 || dealer.featured == 1
    ).length;

    // Update the hero section
    const totalDealersElement    = document.getElementById('totalDealers');
    const totalCarsElement       = document.getElementById('totalCarsAvailable');
    const totalCitiesElement     = document.getElementById('totalCities');
    const featuredDealersElement = document.getElementById('featuredDealers');

    if (totalDealersElement)    totalDealersElement.textContent    = totalDealers + '+';
    if (totalCarsElement)       totalCarsElement.textContent       = totalCars > 0 ? totalCars + '+' : '0';
    if (totalCitiesElement)     totalCitiesElement.textContent     = uniqueCities;
    if (featuredDealersElement) featuredDealersElement.textContent = withProfile + '+';
}

populateLocationFilter() {
    const locationFilter = document.getElementById('locationFilter');
    if (!locationFilter) return;

    while (locationFilter.options.length > 1) locationFilter.remove(1);

    const locations = [...new Set(this.dealers.map(d => d.location_name))]
        .filter(Boolean).sort();

    locations.forEach(loc => {
        const opt = document.createElement('option');
        opt.value = loc;
        opt.textContent = loc;
        locationFilter.appendChild(opt);
    });
}

populateSpecializationFilter() {
    const specFilter = document.getElementById('specializationFilter');
    if (!specFilter) return;

    while (specFilter.options.length > 1) specFilter.remove(1);

    const specs = new Set();
    this.dealers.forEach(dealer => {
        if (!dealer.specialization) return;
        try {
            const arr = typeof dealer.specialization === 'string'
                ? JSON.parse(dealer.specialization)
                : dealer.specialization;
            if (Array.isArray(arr)) arr.forEach(s => { if (s) specs.add(s.trim()); });
        } catch (e) {}
    });

    [...specs].sort().forEach(spec => {
        const opt = document.createElement('option');
        opt.value = spec;
        opt.textContent = spec;
        specFilter.appendChild(opt);
    });
}

applyFilters() {
    const searchTerm = (document.getElementById('dealerSearch')?.value || '').toLowerCase().trim();
    const locationVal = document.getElementById('locationFilter')?.value || '';
    const specializationVal = document.getElementById('specializationFilter')?.value || '';
    const verificationVal = document.getElementById('verificationFilter')?.value || '';
    const sortBy = document.getElementById('sortSelect')?.value || 'featured';

    this.filteredDealers = (this.dealers || []).filter(dealer => {
        // Text search — name, location, address, phone, description
        if (searchTerm) {
            const name  = (dealer.business_name || '').toLowerCase();
            const loc   = (dealer.location_name  || '').toLowerCase();
            const addr  = (dealer.address        || '').toLowerCase();
            const phone = (dealer.phone          || '').toLowerCase();
            const desc  = (dealer.description    || '').toLowerCase();
            if (!name.includes(searchTerm) && !loc.includes(searchTerm) &&
                !addr.includes(searchTerm) && !phone.includes(searchTerm) &&
                !desc.includes(searchTerm)) {
                return false;
            }
        }

        // Location
        if (locationVal && (dealer.location_name || '') !== locationVal) return false;

        // Specialization (JSON array field)
        if (specializationVal) {
            let specs = [];
            try {
                specs = typeof dealer.specialization === 'string'
                    ? JSON.parse(dealer.specialization)
                    : (dealer.specialization || []);
            } catch (e) {}
            if (!Array.isArray(specs) || !specs.some(s => s.trim() === specializationVal)) return false;
        }

        // Verified status (loose comparison — API may return string "1" or number 1)
        if (verificationVal === 'verified' && dealer.verified != 1) return false;

        return true;
    });

    this.sortDealers(sortBy);
    this.currentPage = 1; // Reset page on filter change
    this.renderDealers();
    this.updateResultsCount();
}

clearFilters() {
    ['dealerSearch', 'locationFilter', 'specializationFilter', 'verificationFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    const sortSelect = document.getElementById('sortSelect');
    if (sortSelect) sortSelect.value = 'featured';

    const mobileDealerSearch = document.getElementById('mobileDealerSearch');
    if (mobileDealerSearch) mobileDealerSearch.value = '';

    this.filteredDealers = [...(this.dealers || [])];
    this.sortDealers('featured');
    this.currentPage = 1; // Reset page on clear
    this.renderDealers();
    this.updateResultsCount();
}
}

// ============================================================================
// SHOWROOM MANAGER
// ============================================================================

class ShowroomManager {
    constructor() {
        this.dealerId = null;
        this.dealerData = null;
        this.allCars = [];
        this.currentUser = null;
        this.init();
    }

    init() {
        this.dealerId = this.getDealerIdFromUrl();
        if (!this.dealerId) {
            window.location.href = 'dealers.html';
            return;
        }
        
        this.checkAuthentication();
        this.loadShowroom();
        this.setupEventListeners();
        // Mobile menu handled by mobile-menu.js
        // this.setupMobileMenu();
    }

    getDealerIdFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('id');
    }

    // Authentication
    async checkAuthentication() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
            });
            
            // Check if response is valid JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('API returned non-JSON response');
                return;
            }
            
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
                this.updateUserMenu();
            }
        } catch (error) {
        }
    }

    updateUserMenu() {
        const userInfo = document.getElementById('userInfo');
        const guestMenu = document.getElementById('guestMenu');
        const userName = document.getElementById('userName');

        if (this.currentUser) {
            if (userInfo) userInfo.style.display = 'flex';
            if (guestMenu) guestMenu.style.display = 'none';
            if (userName) userName.textContent = this.currentUser.name;
        }
    }

    async fetchJsonWithRetry(url, options = {}, attempts = 2, timeoutMs = 10000) {
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

                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    throw new Error('API returned non-JSON response');
                }

                return await response.json();
            } catch (error) {
                clearTimeout(timeoutId);
                lastError = error;

                if (attempt < attempts) {
                    await new Promise(resolve => setTimeout(resolve, 300 * attempt));
                }
            }
        }

        throw lastError || new Error('Request failed');
    }

    // Load showroom data
    async loadShowroom() {
        const loadingContainer = document.getElementById('loadingContainer');
        const carsGrid = document.getElementById('carsGrid');
        const emptyState = document.getElementById('emptyState');

        try {
            if (loadingContainer) loadingContainer.style.display = 'block';
            if (carsGrid) carsGrid.innerHTML = '';
            if (emptyState) emptyState.style.display = 'none';

            // Fetch dealer info and listings in parallel — targeted by ID, not all dealers
            const credentials = CONFIG.USE_CREDENTIALS ? {credentials: 'include'} : {};
            const [dealerResult, carsData] = await Promise.all([
                this.fetchJsonWithRetry(
                    `${CONFIG.API_URL}?action=dealer_showroom&dealer_id=${this.dealerId}`,
                    credentials
                ),
                this.fetchJsonWithRetry(
                    `${CONFIG.API_URL}?action=listings&dealer_id=${this.dealerId}`,
                    credentials
                )
            ]);

            if (!dealerResult.success || !dealerResult.dealer) {
                throw new Error(dealerResult.message || 'Dealer not found');
            }

            this.dealerData = dealerResult.dealer;

            if (carsData.success) {
                this.allCars = carsData.listings || [];

                this.displayDealerInfo(this.dealerData);
                this.displayCars(this.allCars);
                this.updatePageTitle(this.dealerData.business_name);
                this.updateShowroomStats(this.allCars);
            } else {
                throw new Error(carsData.message || 'Failed to load cars');
            }
        } catch (error) {
            this.showError('Failed to load showroom. Please try again later.');
        } finally {
            if (loadingContainer) loadingContainer.style.display = 'none';
        }
    }

    // Display comprehensive dealer information
    displayDealerInfo(dealer) {
        // Dealer name - works for both old and new minimal layout
        const dealerName = document.getElementById('dealerName');
        if (dealerName) {
            // Set the text content first
            const nameText = document.createTextNode(dealer.business_name);
            dealerName.innerHTML = '';
            dealerName.appendChild(nameText);
        }

        // Show trust badges
        const verifiedBadge = document.getElementById('verifiedBadge');
        if (verifiedBadge && dealer.verified == 1) {
            verifiedBadge.style.display = 'inline-flex';
        }

        const featuredBadge = document.getElementById('featuredBadge');
        if (featuredBadge && dealer.featured == 1) {
            featuredBadge.style.display = 'inline-flex';
        }

        const certifiedBadge = document.getElementById('certifiedBadge');
        if (certifiedBadge && dealer.certified == 1) {
            certifiedBadge.style.display = 'inline-flex';
        }

        // Show description if available
        const dealerDescription = document.getElementById('dealerDescription');
        const dealerDescriptionText = document.getElementById('dealerDescriptionText');
        if (dealerDescription && dealerDescriptionText && dealer.description) {
            dealerDescriptionText.textContent = dealer.description;
            dealerDescription.style.display = 'block';
        }

        // Location information
        let locationText = '';
        if (dealer.address && dealer.location_name) {
            locationText = `${dealer.address}, ${dealer.location_name}`;
        } else if (dealer.address) {
            locationText = dealer.address;
        } else if (dealer.location_name) {
            locationText = dealer.location_name;
        } else {
            locationText = 'Location not specified';
        }

        const dealerLocation = document.getElementById('dealerLocation');
        if (dealerLocation) dealerLocation.innerHTML = `<i class="fas fa-map-marker-alt"></i> ${locationText}`;

        const dealerPhoneMini = document.getElementById('dealerPhoneMini');
        if (dealerPhoneMini && dealer.phone) {
            dealerPhoneMini.innerHTML = `<i class="fas fa-phone"></i> ${dealer.phone}`;
            dealerPhoneMini.style.display = 'inline-flex';
        }

        const dealerEmailMini = document.getElementById('dealerEmailMini');
        if (dealerEmailMini && dealer.email) {
            dealerEmailMini.innerHTML = `<i class="fas fa-envelope"></i> ${dealer.email}`;
            dealerEmailMini.style.display = 'inline-flex';
        }

        const dealerWebsiteMini = document.getElementById('dealerWebsiteMini');
        if (dealerWebsiteMini && dealer.website) {
            const websiteText = dealer.website.replace(/^https?:\/\/(www\.)?/, '');
            dealerWebsiteMini.innerHTML = `<i class="fas fa-globe"></i> ${websiteText}`;
            dealerWebsiteMini.style.display = 'inline-flex';
        }

        // Rating with proper formatting
        const rating = parseFloat(dealer.rating) || 0;
        const dealerRating = document.getElementById('dealerRating');
        if (dealerRating) dealerRating.textContent = rating > 0 ? rating.toFixed(1) : 'Not rated';

        // Minimal header rating
        const dealerRatingMini = document.getElementById('dealerRatingMini');
        if (dealerRatingMini && rating > 0) {
            dealerRatingMini.innerHTML = `<i class="fas fa-star"></i> ${rating.toFixed(1)}`;
            dealerRatingMini.style.display = 'inline-block';
        }

        // Years in business
        const currentYear = new Date().getFullYear();
        const yearsInBusiness = dealer.years_established ? currentYear - dealer.years_established : 'N/A';
        const yearsElement = document.getElementById('yearsInBusiness');
        if (yearsElement) yearsElement.textContent = yearsInBusiness;

        // Minimal header years
        const yearsInBusinessMini = document.getElementById('yearsInBusinessMini');
        if (yearsInBusinessMini && dealer.years_established) {
            const yearsText = `${currentYear - dealer.years_established} Years`;
            yearsInBusinessMini.innerHTML = `<i class="fas fa-calendar-check"></i> ${yearsText}`;
            yearsInBusinessMini.style.display = 'inline-block';
        }

        // Years stat item in stats section
        const yearsStatItem = document.getElementById('yearsStatItem');
        if (yearsStatItem && dealer.years_established && yearsInBusiness !== 'N/A') {
            yearsStatItem.style.display = 'flex';
        }

        // Quick action buttons in minimal header
        const dealerPhoneLink = document.getElementById('dealerPhoneLink');
        if (dealerPhoneLink && dealer.phone) {
            dealerPhoneLink.href = `tel:${dealer.phone}`;
            dealerPhoneLink.style.display = 'inline-flex';
        }

        const dealerWhatsappLink = document.getElementById('dealerWhatsappLink');
        if (dealerWhatsappLink && (dealer.whatsapp || dealer.phone)) {
            const whatsappNumber = (dealer.whatsapp || dealer.phone).replace(/[^0-9]/g, '');
            dealerWhatsappLink.href = `https://wa.me/${whatsappNumber}`;
            dealerWhatsappLink.style.display = 'inline-flex';
        }

            const dealerEmailLink = document.getElementById('dealerEmailLink');
            if (dealerEmailLink && dealer.email) {
                dealerEmailLink.href = `mailto:${dealer.email}`;
                dealerEmailLink.style.display = 'inline-flex';
            }

            let normalizedWebsiteUrl = '';
            if (dealer.website) {
                normalizedWebsiteUrl = dealer.website.startsWith('http') ? dealer.website : `https://${dealer.website}`;
            }

            const dealerWebsiteLink = document.getElementById('dealerWebsiteLink');
            if (dealerWebsiteLink && normalizedWebsiteUrl) {
                dealerWebsiteLink.href = normalizedWebsiteUrl;
                dealerWebsiteLink.style.display = 'inline-flex';
            }

        const dealerQuickContact = document.getElementById('dealerQuickContact');
        const dealerHeaderPhoneLink = document.getElementById('dealerHeaderPhoneLink');
        const dealerHeaderWhatsappLink = document.getElementById('dealerHeaderWhatsappLink');
        const dealerHeaderWebsiteLink = document.getElementById('dealerHeaderWebsiteLink');
        const dealerHeaderDirectionsLink = document.getElementById('dealerHeaderDirectionsLink');

        if (dealerHeaderPhoneLink && dealer.phone) {
            dealerHeaderPhoneLink.href = `tel:${dealer.phone}`;
            dealerHeaderPhoneLink.style.display = 'inline-flex';
        }

        if (dealerHeaderWhatsappLink && (dealer.whatsapp || dealer.phone)) {
            const whatsappNumber = (dealer.whatsapp || dealer.phone).replace(/[^0-9]/g, '');
            dealerHeaderWhatsappLink.href = `https://wa.me/${whatsappNumber}`;
            dealerHeaderWhatsappLink.style.display = 'inline-flex';
        }

        if (dealerHeaderWebsiteLink && normalizedWebsiteUrl) {
            dealerHeaderWebsiteLink.href = normalizedWebsiteUrl;
            dealerHeaderWebsiteLink.style.display = 'inline-flex';
        }

        if (dealerHeaderDirectionsLink && dealer.address) {
            dealerHeaderDirectionsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(dealer.address)}`;
            dealerHeaderDirectionsLink.style.display = 'inline-flex';
        }

        if (dealerQuickContact && (dealer.phone || dealer.whatsapp || dealer.email || normalizedWebsiteUrl || dealer.address)) {
            dealerQuickContact.style.display = 'block';
        }

        // Contact section near footer - phone
        const phoneItem = document.getElementById('phoneItem');
        const dealerPhone = document.getElementById('dealerPhone');
        if (phoneItem && dealerPhone && dealer.phone) {
            dealerPhone.textContent = dealer.phone;
            phoneItem.style.display = 'flex';
        }

        // Contact section near footer - email
        const emailItem = document.getElementById('emailItem');
        const dealerEmail = document.getElementById('dealerEmail');
        if (emailItem && dealerEmail && dealer.email) {
            dealerEmail.textContent = dealer.email;
            emailItem.style.display = 'flex';
        }

        // Contact section near footer - website
        const websiteItem = document.getElementById('websiteItem');
        const dealerWebsite = document.getElementById('dealerWebsite');
        if (websiteItem && dealerWebsite && normalizedWebsiteUrl) {
            dealerWebsite.href = normalizedWebsiteUrl;
            dealerWebsite.textContent = dealer.website;
            websiteItem.style.display = 'flex';
        }

        // Legacy support - old dealer phone element
        const dealerPhoneLegacy = document.querySelectorAll('#dealerPhone')[0];
        if (dealerPhoneLegacy && !phoneItem) {
            dealerPhoneLegacy.textContent = dealer.phone || 'Not provided';
        }

        const totalCars = document.getElementById('totalCars');
        // Use database count (dealer.total_cars) if available, otherwise fallback to loaded count
        if (totalCars) totalCars.textContent = dealer.total_cars || this.allCars.length;

        // Handle logo
        const dealerLogo = document.getElementById('dealerLogo');
        if (dealerLogo) {
            if (dealer.logo_url) {
                dealerLogo.innerHTML = `<img src="${dealer.logo_url}" alt="${dealer.business_name} logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">`;
                dealerLogo.innerHTML += '<i class="fas fa-store" style="display:none;"></i>';
            } else {
                dealerLogo.innerHTML = '<i class="fas fa-store"></i>';
            }
        }

        // Enhanced contact information display
        this.updateContactInfo(dealer);
        
        // Add dealer description if available
        if (dealer.description) {
            const descriptionElement = document.createElement('div');
            descriptionElement.className = 'dealer-description';
            descriptionElement.innerHTML = `
                <div style="margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 1.1rem;"><i class="fas fa-info-circle"></i> About Us</h4>
                    <p style="margin: 0; line-height: 1.5; font-size: 0.95rem;">${dealer.description}</p>
                </div>
            `;
            const dealerDetails = document.querySelector('.dealer-details');
            if (dealerDetails) dealerDetails.appendChild(descriptionElement);
        }

        // Add specialization if available
        if (dealer.specialization) {
            let specializations = [];
            try {
                if (typeof dealer.specialization === 'string') {
                    specializations = JSON.parse(dealer.specialization);
                } else if (Array.isArray(dealer.specialization)) {
                    specializations = dealer.specialization;
                }

                if (specializations.length > 0) {
                    const headerSpecializations = document.getElementById('dealerSpecializations');
                    if (headerSpecializations) {
                        headerSpecializations.innerHTML = specializations
                            .map(spec => `<span class="dealer-specialization-pill">${spec}</span>`)
                            .join('');
                        headerSpecializations.style.display = 'flex';
                    }

                    const specializationElement = document.createElement('div');
                    specializationElement.className = 'dealer-specialization';
                    specializationElement.innerHTML = `
                        <div style="margin-top: 15px;">
                            <h4 style="margin: 0 0 8px 0; font-size: 1rem; opacity: 0.9;"><i class="fas fa-star"></i> Specializations</h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                ${specializations.map(spec =>
                                    `<span style="background: rgba(255,255,255,0.2); padding: 4px 8px; border-radius: 12px; font-size: 0.8rem;">${spec}</span>`
                                ).join('')}
                            </div>
                        </div>
                    `;
                    const dealerDetails = document.querySelector('.dealer-details');
                    if (dealerDetails) dealerDetails.appendChild(specializationElement);
                }
            } catch (error) {
                // Ignore parsing errors
            }
        }

        // Initialize map with dealer location
        this.initializeDealerMap(dealer);

        // Load reviews section
        const rvContainer = document.getElementById('rv-section-dealer-container');
        const rvInner = document.getElementById('rv-section-dealer-inner');
        if (rvContainer && rvInner && typeof rvRenderSection === 'function') {
            rvContainer.style.display = '';
            // Give inner a stable id for rvRenderSection to refresh itself
            rvInner.id = `rv-section-dealer-${dealer.id}`;
            rvRenderSection(rvInner, 'dealer', dealer.id, dealer.business_name);
        }
    }

    // Initialize Google Map for dealer location
    initializeDealerMap(dealer) {
        const mapContainer = document.getElementById('dealerMapContainer');
        const mapDiv = document.getElementById('map');

        if (!mapContainer || !mapDiv) return;

        // Get full address for geocoding
        let address = '';
        if (dealer.address && dealer.location_name) {
            address = buildRuntimeAddress([dealer.address, dealer.location_name, getRuntimeCountryName()]);
        } else if (dealer.address) {
            address = buildRuntimeAddress([dealer.address, getRuntimeCountryName()]);
        } else if (dealer.location_name) {
            address = buildRuntimeAddress([dealer.location_name, getRuntimeCountryName()]);
        }

        if (!address) {
            // No address available, don't show map
            return;
        }

        // Show map container
        mapContainer.style.display = 'block';

        // Wait for Google Maps to load
        const initMap = () => {
            if (typeof google === 'undefined' || !google.maps) {
                // Google Maps not loaded yet, try again in 500ms
                setTimeout(initMap, 500);
                return;
            }

            // Default center from runtime market config when available
            const defaultCenter = getRuntimeMapCenter();

            // Initialize map
            const map = new google.maps.Map(mapDiv, {
                zoom: 15,
                center: defaultCenter,
                mapTypeControl: true,
                streetViewControl: true,
                fullscreenControl: true
            });

            // Geocode address to get coordinates
            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: address }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    const location = results[0].geometry.location;

                    // Center map on location
                    map.setCenter(location);

                    // Add marker
                    new google.maps.Marker({
                        map: map,
                        position: location,
                        title: dealer.business_name,
                        animation: google.maps.Animation.DROP
                    });

                    // Add info window
                    const infoWindow = new google.maps.InfoWindow({
                        content: `
                            <div style="padding: 8px; max-width: 200px;">
                                <h3 style="margin: 0 0 8px 0; font-size: 1rem; color: #333;">${dealer.business_name}</h3>
                                <p style="margin: 0; font-size: 0.85rem; color: #666;">
                                    <i class="fas fa-map-marker-alt" style="color: var(--primary-green);"></i> ${address}
                                </p>
                                ${dealer.phone ? `<p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #666;">
                                    <i class="fas fa-phone" style="color: var(--primary-green);"></i> ${dealer.phone}
                                </p>` : ''}
                            </div>
                        `
                    });

                    // Open info window by default
                    infoWindow.open(map);
                } else {
                    // Still show a fallback message when geocoding fails
                    mapDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Unable to load map for this location</div>';
                }
            });
        };

        initMap();
    }

    // Update contact information display
    updateContactInfo(dealer) {
        // Email
        if (dealer.email) {
            const dealerEmail = document.getElementById('dealerEmail');
            const dealerEmailContainer = document.getElementById('dealerEmailContainer');
            if (dealerEmail) dealerEmail.textContent = dealer.email;
            if (dealerEmail) dealerEmail.href = `mailto:${dealer.email}`;
            if (dealerEmailContainer) dealerEmailContainer.style.display = 'flex';
        }

        // WhatsApp
        if (dealer.whatsapp) {
            const whatsappNumber = dealer.whatsapp.replace(/\D/g, '');
            const dealerWhatsapp = document.getElementById('dealerWhatsapp');
            const dealerWhatsappContainer = document.getElementById('dealerWhatsappContainer');
            if (dealerWhatsapp) dealerWhatsapp.href = `https://wa.me/${whatsappNumber}`;
            if (dealerWhatsappContainer) dealerWhatsappContainer.style.display = 'flex';
        } else if (dealer.phone) {
            const phoneNumber = dealer.phone.replace(/\D/g, '');
            const dealerWhatsapp = document.getElementById('dealerWhatsapp');
            const dealerWhatsappContainer = document.getElementById('dealerWhatsappContainer');
            if (dealerWhatsapp) dealerWhatsapp.href = `https://wa.me/${phoneNumber}`;
            if (dealerWhatsappContainer) dealerWhatsappContainer.style.display = 'flex';
        }

        // Website
        if (dealer.website) {
            let websiteUrl = dealer.website;
            if (!websiteUrl.startsWith('http')) {
                websiteUrl = 'https://' + websiteUrl;
            }
            const dealerWebsite = document.getElementById('dealerWebsite');
            const dealerWebsiteContainer = document.getElementById('dealerWebsiteContainer');
            if (dealerWebsite) dealerWebsite.href = websiteUrl;
            if (dealerWebsiteContainer) dealerWebsiteContainer.style.display = 'flex';
        }

        // Business hours if available
        if (dealer.business_hours) {
            const businessHoursElement = document.createElement('div');
            businessHoursElement.innerHTML = `
                <div class="contact-item">
                    <i class="fas fa-clock"></i>
                    <span>${dealer.business_hours}</span>
                </div>
            `;
            const dealerContact = document.querySelector('.dealer-contact');
            if (dealerContact) dealerContact.appendChild(businessHoursElement);
        }

        // Social Media Links - New minimal layout in footer area
        const hasSocialMedia = dealer.facebook_url || dealer.instagram_url || dealer.twitter_url || dealer.linkedin_url;
        if (hasSocialMedia) {
            // Build social icons HTML with unified classes
            let socialIconsHTML = '';

            if (dealer.facebook_url) {
                socialIconsHTML += `<a href="${dealer.facebook_url}" target="_blank" rel="noopener noreferrer" class="social-link facebook" title="Facebook"><i class="fab fa-facebook-f"></i></a>`;
            }

            if (dealer.instagram_url) {
                socialIconsHTML += `<a href="${dealer.instagram_url}" target="_blank" rel="noopener noreferrer" class="social-link instagram" title="Instagram"><i class="fab fa-instagram"></i></a>`;
            }

            if (dealer.twitter_url) {
                socialIconsHTML += `<a href="${dealer.twitter_url}" target="_blank" rel="noopener noreferrer" class="social-link twitter" title="Twitter/X"><i class="fab fa-twitter"></i></a>`;
            }

            if (dealer.linkedin_url) {
                socialIconsHTML += `<a href="${dealer.linkedin_url}" target="_blank" rel="noopener noreferrer" class="social-link linkedin" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>`;
            }

            // Populate new minimal layout social icons
            const socialIconsContainer = document.getElementById('socialIconsContainer');
            const dealerSocialMedia = document.getElementById('dealerSocialMedia');
            if (socialIconsContainer && dealerSocialMedia) {
                socialIconsContainer.innerHTML = socialIconsHTML;
                dealerSocialMedia.style.display = 'block';
            }

            // Legacy support - old dealer-contact section (if it exists)
            const dealerContact = document.querySelector('.dealer-contact');
            if (dealerContact && !socialIconsContainer) {
                let socialMediaHTML = '<div class="social-media-section" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">';
                socialMediaHTML += '<h4 style="margin: 0 0 12px 0; font-size: 1rem; display: flex; align-items: center; gap: 8px;"><i class="fas fa-share-alt"></i> Connect With Us</h4>';
                socialMediaHTML += '<div class="social-icons" style="display: flex; gap: 10px; flex-wrap: wrap;">';

                if (dealer.facebook_url) {
                    socialMediaHTML += `
                        <a href="${dealer.facebook_url}" target="_blank" rel="noopener noreferrer" class="social-icon facebook" title="Facebook"
                           style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px;
                                  border-radius: 50%; background: #1877f2; color: white; font-size: 18px; text-decoration: none;
                                  transition: transform 0.2s, opacity 0.2s;">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                    `;
                }

                if (dealer.instagram_url) {
                    socialMediaHTML += `
                        <a href="${dealer.instagram_url}" target="_blank" rel="noopener noreferrer" class="social-icon instagram" title="Instagram"
                           style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px;
                                  border-radius: 50%; background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
                                  color: white; font-size: 18px; text-decoration: none; transition: transform 0.2s, opacity 0.2s;">
                            <i class="fab fa-instagram"></i>
                        </a>
                    `;
                }

                if (dealer.twitter_url) {
                    socialMediaHTML += `
                        <a href="${dealer.twitter_url}" target="_blank" rel="noopener noreferrer" class="social-icon twitter" title="Twitter/X"
                           style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px;
                                  border-radius: 50%; background: #1DA1F2; color: white; font-size: 18px; text-decoration: none;
                                  transition: transform 0.2s, opacity 0.2s;">
                            <i class="fab fa-twitter"></i>
                        </a>
                    `;
                }

                if (dealer.linkedin_url) {
                    socialMediaHTML += `
                        <a href="${dealer.linkedin_url}" target="_blank" rel="noopener noreferrer" class="social-icon linkedin" title="LinkedIn"
                           style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px;
                                  border-radius: 50%; background: #0077b5; color: white; font-size: 18px; text-decoration: none;
                                  transition: transform 0.2s, opacity 0.2s;">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    `;
                }

                socialMediaHTML += '</div></div>';

                const socialMediaElement = document.createElement('div');
                socialMediaElement.innerHTML = socialMediaHTML;
                dealerContact.appendChild(socialMediaElement);
            }
        }
    }

    // Display comprehensive car information
    displayCars(cars) {
        const carsGrid = document.getElementById('carsGrid');
        const emptyState = document.getElementById('emptyState');
        const totalCarsCount = document.getElementById('totalCarsCount');
        const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22400%22 height=%22300%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22150%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EImage unavailable%3C/text%3E%3C/svg%3E';

        if (totalCarsCount) totalCarsCount.textContent = this.allCars.length;

        if (!carsGrid) return;

        if (cars.length === 0) {
            carsGrid.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';

        carsGrid.innerHTML = cars.map(car => {
            let images = [];
            let featuredImageId = car.featured_image_id || null;

            if (!featuredImageId && Array.isArray(car.images) && car.images.length > 0) {
                const fallbackImageWithId = car.images.find(img => img && typeof img === 'object' && img.id);
                if (fallbackImageWithId?.id) {
                    featuredImageId = fallbackImageWithId.id;
                }
            }

            if (featuredImageId) {
                images.push(`${CONFIG.API_URL}?action=image&id=${featuredImageId}`);
            } else if (car.featured_image) {
                images.push(`${CONFIG.BASE_URL}uploads/${car.featured_image}`);
            } else if (car.primary_image) {
                images.push(car.primary_image);
            }

            if (car.images && Array.isArray(car.images) && car.images.length > 0) {
                car.images.forEach(img => {
                    let imgUrl = '';
                    if (img.id) {
                        if (img.id == featuredImageId) return;
                        imgUrl = `${CONFIG.API_URL}?action=image&id=${img.id}`;
                    } else if (img.filename) {
                        if (img.filename === car.featured_image) return;
                        imgUrl = `${CONFIG.BASE_URL}uploads/${img.filename}`;
                    } else if (typeof img === 'string') {
                        if (img === car.featured_image) return;
                        imgUrl = `${CONFIG.BASE_URL}uploads/${img}`;
                    }
                    if (imgUrl && !images.includes(imgUrl)) {
                        images.push(imgUrl);
                    }
                });
            }

            if (images.length === 0) {
                images.push(inlinePlaceholder);
            }

            images = images.slice(0, 5);
            const imageUrl = images[0];
            const totalImageCount = car.image_count || images.length;
            const thumbs = images.slice(1, 4);
            const remainingThumbCount = Math.max(0, totalImageCount - (thumbs.length + 1));
            const galleryStripHTML = thumbs.length > 0 ? `
                <div class="car-gallery-strip thumb-count-${thumbs.length}">
                    ${thumbs.map((img, idx) => `
                        <div class="gallery-thumb" onclick="event.stopPropagation(); openImageGallery(${car.id}, '${(car.title || `${car.year} ${car.make_name} ${car.model_name}`).replace(/'/g, "\\'")}')">
                            <img src="${img}" alt="${car.title || `${car.year} ${car.make_name} ${car.model_name}`}" loading="lazy"
                                 onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                            ${remainingThumbCount > 0 && idx === thumbs.length - 1 ? `<span class="gallery-thumb-more">+${remainingThumbCount}</span>` : ''}
                        </div>
                    `).join('')}
                </div>
            ` : '';

            // Check for is_featured and is_premium flags
            const isFeatured = car.is_featured == 1;
            const isPremium = car.is_premium == 1;

            // Build badge HTML - can show both badges
            let badgeHTML = '';
            if (isPremium) {
                badgeHTML += '<div class="car-badge premium"><i class="fas fa-crown"></i> Premium</div>';
            }
            if (isFeatured) {
                badgeHTML += '<div class="car-badge featured"><i class="fas fa-star"></i> Featured</div>';
            }

            const negotiableText = car.negotiable == 1 ?
                '<span style="color: #28a745; font-size: 0.8rem; font-weight: 600;">✓ Negotiable</span>' :
                '<span style="color: #6c757d; font-size: 0.8rem;">Fixed Price</span>';
            
            // Format price with commas
            const formattedPrice = car.price ? `${CONFIG.CURRENCY_CODE || 'MWK'} ${parseInt(car.price).toLocaleString()}` : 'Price on request';
            
            // Calculate car age
            const carAge = car.year ? new Date().getFullYear() - parseInt(car.year) : 'N/A';
            
            // Enhanced car details
            const enhancedDetails = this.getEnhancedCarDetails(car);

            return `
                <div class="car-card" data-id="${car.id}">
                    <div class="car-gallery">
                        <div class="car-gallery-main">
                            ${imageUrl ? 
                                `<img src="${imageUrl}" alt="${car.title}" onerror="this.onerror=null;this.src='${inlinePlaceholder}';">` :
                                '<i class="fas fa-car"></i>'
                            }
                            ${badgeHTML}
                        </div>
                        ${galleryStripHTML}
                    </div>
                    <div class="car-content">
                        <h3 class="car-title">${car.title || `${car.year} ${car.make_name} ${car.model_name}`}</h3>
                        
                        <div class="car-price-section">
                            <div class="car-price">${formattedPrice}</div>
                            <div class="car-negotiable">${negotiableText}</div>
                        </div>

                        ${car.reference_number ? `
                            <div class="car-reference" style="color: #666; font-size: 14px; margin: 8px 0 4px 0; font-family: 'Courier New', monospace;">Reference: ${car.reference_number}</div>
                            <div class="car-reference" style="color: #666; font-size: 14px; margin-bottom: 8px; font-family: 'Courier New', monospace;">ID: ${car.id}</div>
                        ` : `
                            <div class="car-reference" style="color: #666; font-size: 14px; margin: 8px 0; font-family: 'Courier New', monospace;">ID: ${car.id}</div>
                        `}

                        <!-- Enhanced Car Details -->
                        <div class="car-details-enhanced">
                            <div class="detail-group">
                                <div class="detail-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>${car.year || 'N/A'} (${carAge} ${carAge === 1 ? 'year' : 'years'} old)</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-tachometer-alt"></i>
                                    <span>${car.mileage ? parseInt(car.mileage).toLocaleString() + ' km' : 'Mileage N/A'}</span>
                                </div>
                            </div>
                            <div class="detail-group">
                                <div class="detail-item">
                                    <i class="fas fa-gas-pump"></i>
                                    <span>${car.fuel_type ? car.fuel_type.charAt(0).toUpperCase() + car.fuel_type.slice(1) : 'Fuel N/A'}</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-cogs"></i>
                                    <span>${car.transmission ? car.transmission.charAt(0).toUpperCase() + car.transmission.slice(1) : 'Trans. N/A'}</span>
                                </div>
                            </div>
                            ${enhancedDetails}
                        </div>

                        <!-- Car Condition & Body Type -->
                        <div class="car-features">
                            ${car.condition_type ? 
                                `<span class="feature-tag condition-${car.condition_type}">
                                    <i class="fas fa-check-circle"></i> ${this.getConditionText(car.condition_type)}</span>` : ''
                            }
                            ${car.body_type ? 
                                `<span class="feature-tag">
                                    <i class="fas fa-car-side"></i> ${car.body_type.charAt(0).toUpperCase() + car.body_type.slice(1)}</span>` : ''
                            }
                        </div>

                        <!-- Car Statistics -->
                        <div class="car-meta">
                            <span class="stat-item">
                                <i class="fas fa-eye"></i> ${car.views_count || 0} views
                            </span>
                            <span class="stat-item">
                                <i class="fas fa-clock"></i> ${this.formatTimeAgo(car.created_at)}
                            </span>
                            ${car.views_count > 100 ? 
                                '<span class="stat-item popular-tag"><i class="fas fa-fire"></i> Popular</span>' : ''
                            }
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Add click handlers for car cards - now entire card is clickable
        document.querySelectorAll('.car-card').forEach(card => {
            card.addEventListener('click', function(e) {
                const carId = this.getAttribute('data-id');
                window.location.href = `car.html?id=${carId}`;
            });
            // Add cursor pointer to indicate card is clickable
            card.style.cursor = 'pointer';
        });
    }

    // Get enhanced car details
    getEnhancedCarDetails(car) {
        let details = [];
        
        if (car.exterior_color) {
            details.push(`<div class="detail-item">
                <i class="fas fa-palette"></i>
                <span>Ext: ${car.exterior_color}</span>
            </div>`);
        }
        
        if (car.interior_color) {
            details.push(`<div class="detail-item">
                <i class="fas fa-chair"></i>
                <span>Int: ${car.interior_color}</span>
            </div>`);
        }
        
        if (car.drivetrain) {
            details.push(`<div class="detail-item">
                <i class="fas fa-car-side"></i>
                <span>${car.drivetrain.toUpperCase()}</span>
            </div>`);
        }
        
        if (car.doors && car.seats) {
            details.push(`<div class="detail-item">
                <i class="fas fa-users"></i>
                <span>${car.doors} doors, ${car.seats} seats</span>
            </div>`);
        } else if (car.doors) {
            details.push(`<div class="detail-item">
                <i class="fas fa-door-open"></i>
                <span>${car.doors} doors</span>
            </div>`);
        } else if (car.seats) {
            details.push(`<div class="detail-item">
                <i class="fas fa-users"></i>
                <span>${car.seats} seats</span>
            </div>`);
        }
        
        if (car.engine_size) {
            details.push(`<div class="detail-item">
                <i class="fas fa-cog"></i>
                <span>${car.engine_size}</span>
            </div>`);
        }
        
        if (details.length > 0) {
            return `<div class="detail-group">${details.join('')}</div>`;
        }
        
        return '';
    }

    // Get condition text
    getConditionText(condition) {
        const conditions = {
            'excellent': 'Excellent Condition',
            'very_good': 'Very Good',
            'good': 'Good Condition',
            'fair': 'Fair Condition',
            'poor': 'Needs Work'
        };
        return conditions[condition] || condition;
    }

    // Format time ago
    formatTimeAgo(dateString) {
        if (!dateString) return 'Recently';
        
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);
        
        if (diffInSeconds < 60) return 'Just now';
        if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + ' min ago';
        if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + ' hours ago';
        if (diffInSeconds < 2592000) return Math.floor(diffInSeconds / 86400) + ' days ago';
        return date.toLocaleDateString();
    }

    // Setup event listeners
    setupEventListeners() {
        const sortFilter = document.getElementById('sortFilter');
        const categoryFilter = document.getElementById('categoryFilter');

        if (sortFilter) sortFilter.addEventListener('change', () => this.filterAndSortCars());
        if (categoryFilter) categoryFilter.addEventListener('change', () => this.filterAndSortCars());

        const transmissionFilter = document.getElementById('transmissionFilter');
        const fuelTypeFilter = document.getElementById('fuelTypeFilter');
        const conditionFilter = document.getElementById('conditionFilter');

        if (transmissionFilter) transmissionFilter.addEventListener('change', () => this.filterAndSortCars());
        if (fuelTypeFilter) fuelTypeFilter.addEventListener('change', () => this.filterAndSortCars());
        if (conditionFilter) conditionFilter.addEventListener('change', () => this.filterAndSortCars());

        // Clear filters button
        const clearBtn = document.getElementById('clearShowroomFilters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                ['sortFilter','categoryFilter','transmissionFilter','fuelTypeFilter','conditionFilter'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = id === 'sortFilter' ? 'newest' : '';
                });
                this.filterAndSortCars();
            });
        }
    }

    // Filter and sort cars
    filterAndSortCars() {
        const sortFilter = document.getElementById('sortFilter');
        const categoryFilter = document.getElementById('categoryFilter');
        
        const sortOption = sortFilter ? sortFilter.value : 'newest';
        const categoryFilterValue = categoryFilter ? categoryFilter.value : '';

        let filteredCars = this.allCars.filter(car => {
            const matchesCategory = !categoryFilterValue || car.body_type === categoryFilterValue;

            const transmissionFilter = document.getElementById('transmissionFilter');
            const fuelTypeFilter = document.getElementById('fuelTypeFilter');
            const conditionFilter = document.getElementById('conditionFilter');

            const transmissionVal = transmissionFilter ? transmissionFilter.value : '';
            const fuelTypeVal = fuelTypeFilter ? fuelTypeFilter.value : '';
            const conditionVal = conditionFilter ? conditionFilter.value : '';

            const matchesTransmission = !transmissionVal || (car.transmission || '').toLowerCase() === transmissionVal.toLowerCase();
            const matchesFuel = !fuelTypeVal || (car.fuel_type || '').toLowerCase() === fuelTypeVal.toLowerCase();
            const matchesCondition = !conditionVal || (car.condition_type || '').toLowerCase() === conditionVal.toLowerCase();

            return matchesCategory && matchesTransmission && matchesFuel && matchesCondition;
        });

        // Sort cars
        filteredCars.sort((a, b) => {
            switch (sortOption) {
                case 'price_low':
                    return (parseFloat(a.price) || 0) - (parseFloat(b.price) || 0);
                case 'price_high':
                    return (parseFloat(b.price) || 0) - (parseFloat(a.price) || 0);
                case 'year_new':
                    return (parseInt(b.year) || 0) - (parseInt(a.year) || 0);
                case 'year_old':
                    return (parseInt(a.year) || 0) - (parseInt(b.year) || 0);
                case 'most_viewed':
                    return (b.views_count || 0) - (a.views_count || 0);
                case 'newest':
                default:
                    return new Date(b.created_at || 0) - new Date(a.created_at || 0);
            }
        });

        this.displayCars(filteredCars);
    }

    // Share dealer functionality
    shareDealer() {
        if (navigator.share) {
            navigator.share({
                title: this.dealerData.business_name,
                text: `Check out ${this.dealerData.business_name} on ${getRuntimeSiteName()}`,
                url: window.location.href,
            });
        } else {
            navigator.clipboard.writeText(window.location.href).then(() => {
                alert('Link copied to clipboard!');
            });
        }
    }

    // Contact dealer modal
    showContactModal() {
        const phone = this.dealerData.phone;
        const email = this.dealerData.email;
        const whatsapp = this.dealerData.whatsapp;
        
        let message = `Contact ${this.dealerData.business_name}:\n\nPhone: ${phone || 'Not provided'}`;
        if (email) message += `\nEmail: ${email}`;
        if (whatsapp) message += `\nWhatsApp: ${whatsapp}`;
        
        alert(message);
    }

    // Update page title
    updatePageTitle(dealerName) {
        const siteName = getRuntimeSiteName();
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = `${dealerName} - Showroom | ${siteName}`;
        }
        document.title = `${dealerName} - Showroom | ${siteName}`;
    }

    // Update showroom stats in hero section
    updateShowroomStats(cars) {
        const totalCarsCount = document.getElementById('totalCarsCount');
        const totalSalesCount = document.getElementById('totalSalesCount');
        const salesStatItem = document.getElementById('salesStatItem');
        const showroomStats = document.getElementById('showroomStats');

        if (totalCarsCount) {
            totalCarsCount.textContent = cars.length;
        }

        // Update sales count if dealer data is available
        if (this.dealerData && totalSalesCount) {
            const sales = this.dealerData.total_sales || 0;
            totalSalesCount.textContent = sales;

            // Show/hide sales stat based on whether there are sales
            if (salesStatItem) {
                salesStatItem.style.display = sales > 0 ? 'flex' : 'none';
            }
        }

        if (showroomStats && cars.length > 0) {
            showroomStats.style.display = 'flex';
        }
    }

    // Show error message
    showError(message) {
        const carsGrid = document.getElementById('carsGrid');
        if (carsGrid) {
            carsGrid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 16px;"></i>
                    <h3>Error Loading Showroom</h3>
                    <p>${message}</p>
                    <button onclick="window.showroomManager.loadShowroom()" class="btn btn-primary" style="margin-top: 16px;">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                </div>
            `;
        }
    }

    // Mobile menu
    setupMobileMenu() {
        const toggle = document.getElementById('mobileToggle');
        const nav = document.getElementById('mainNav');
        const userMenu = document.getElementById('userMenu');

        if (toggle && nav) {
            // Add login/register links to mobile menu
            const addMobileAuthLinks = () => {
                // Remove existing auth links if any
                const existingAuthLinks = nav.querySelectorAll('.mobile-auth-link');
                existingAuthLinks.forEach(link => link.remove());

                // Check if user is logged in
                const userData = this.getUser();

                if (userData) {
                    // User is logged in - add logout button
                    const logoutLink = document.createElement('a');
                    logoutLink.href = '#';
                    logoutLink.className = 'mobile-auth-link';
                    logoutLink.innerHTML = '<i class="fas fa-sign-out-alt"></i> Logout';
                    logoutLink.onclick = (e) => {
                        e.preventDefault();
                        logout();
                        nav.classList.remove('active');
                        const icon = toggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    };
                    nav.appendChild(logoutLink);
                } else {
                    // User not logged in - add login/register links
                    const loginLink = document.createElement('a');
                    loginLink.href = 'login.html';
                    loginLink.className = 'mobile-auth-link';
                    loginLink.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login';
                    nav.appendChild(loginLink);

                    const registerLink = document.createElement('a');
                    registerLink.href = 'register.html';
                    registerLink.className = 'mobile-auth-link';
                    registerLink.innerHTML = '<i class="fas fa-user-plus"></i> Register';
                    nav.appendChild(registerLink);
                }
            };

            toggle.addEventListener('click', () => {
                const isActive = nav.classList.contains('active');

                // Toggle navigation
                nav.classList.toggle('active');

                // Add/update auth links when opening menu
                if (!isActive) {
                    addMobileAuthLinks();
                }

                // Toggle icon
                const icon = toggle.querySelector('i');
                if (!isActive) {
                    icon.className = 'fas fa-times';
                } else {
                    icon.className = 'fas fa-bars';
                }
            });

            // Close menu when clicking nav links
            if (nav) {
                nav.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => {
                        nav.classList.remove('active');
                        if (userMenu) userMenu.classList.remove('mobile-active');
                        const icon = toggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    });
                });
            }

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!nav.contains(e.target) && !userMenu?.contains(e.target) && !toggle.contains(e.target)) {
                    nav.classList.remove('active');
                    if (userMenu) userMenu.classList.remove('mobile-active');
                    const icon = toggle.querySelector('i');
                    icon.className = 'fas fa-bars';
                }
            });
        }
    }
}

// ============================================================================
// GLOBAL FUNCTIONS  
// ============================================================================


// Toast notification function (add if not exists)
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(toast => toast.remove());

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };

    const colors = {
        success: '#28a745',
        error: '#dc3545',
        warning: '#ffc107',
        info: '#17a2b8'
    };

    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="${icons[type]}" style="color: ${colors[type]}; font-size: 16px;"></i>
            <span style="flex: 1; line-height: 1.4;">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #999; margin-left: auto;">×</button>
        </div>
    `;

    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        padding: 16px 20px;
        border-radius: 12px;
        box-shadow: 0 6px 25px rgba(0,0,0,0.15);
        z-index: 10000;
        border-left: 4px solid ${colors[type]};
        max-width: 350px;
        font-size: 14px;
        animation: slideInRight 0.4s ease;
    `;

    document.body.appendChild(toast);

    // Auto remove after 5 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 5000);
}

// Close contact modal function
function closeContactModal() {
    const modal = document.getElementById('contactModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Clear all filters function
function clearAllFilters() {
    if (window.dealersManager) {
        window.dealersManager.clearFilters();
    }
}

// Setup mobile filters with slide-in drawer
function setupMobileFilters() {
    const filterToggle = document.querySelector('.mobile-filter-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (!filterToggle || !sidebar) return;

    // Create backdrop overlay
    let backdrop = document.querySelector('.filter-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'filter-backdrop';
        document.body.appendChild(backdrop);
    }

    // Function to open filters
    function openFilters() {
        sidebar.classList.add('active');
        backdrop.classList.add('active');
        filterToggle.innerHTML = '<i class="fas fa-times"></i> Hide Filters';
        filterToggle.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent body scroll
    }

    // Function to close filters
    function closeFilters() {
        sidebar.classList.remove('active');
        backdrop.classList.remove('active');
        filterToggle.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
        filterToggle.classList.remove('active');
        document.body.style.overflow = ''; // Restore body scroll
    }

    // Toggle button click
    filterToggle.addEventListener('click', () => {
        const isActive = sidebar.classList.contains('active');
        if (isActive) {
            closeFilters();
        } else {
            openFilters();
        }
    });

    // Backdrop click to close
    backdrop.addEventListener('click', closeFilters);

    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            closeFilters();
        }
    });
}
// ============================================================================
// INITIALIZATION
// ============================================================================

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize main application FIRST
    window.motorLink = new MotorLink();
    
    // Setup auto-title for sell page
    setupAutoTitle();
    
    // Initialize page-specific managers based on current page
    const path = window.location.pathname;
    
    if (path.includes('dealers.html')) {
        window.dealersManager = new DealersManager();
    }
    
    if (path.includes('showroom.html')) {
        window.showroomManager = new ShowroomManager();
    }
    
    // ADD THIS: Initialize car detail manager
    if (path.includes('car.html')) {
        window.carDetailManager = new CarDetailManager();
    }
    
    // NOTE: SellManager is now handled in sell.js
    // Setup mobile filters
    setupMobileFilters();
});

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('contactModal');
    if (modal && event.target === modal) {
        closeContactModal();
    }
});

// ============================================================================
// CAROUSEL FUNCTIONALITY
// ============================================================================

// Global carousel slide function
window.slideCarousel = function(carouselId, direction) {
    const carousel = document.getElementById(carouselId);
    if (!carousel) return;

    const slides = carousel.querySelector('.car-image-slides');
    const dots = carousel.querySelectorAll('.carousel-dot');
    const totalSlides = carousel.querySelectorAll('.car-image-slide').length;

    let current = parseInt(carousel.dataset.current || 0);
    current += direction;

    // Loop around
    if (current < 0) current = totalSlides - 1;
    if (current >= totalSlides) current = 0;

    // Update slide position
    slides.style.transform = `translateX(-${current * 100}%)`;

    // Update dots
    dots.forEach((dot, idx) => {
        dot.classList.toggle('active', idx === current);
    });

    // Store current index
    carousel.dataset.current = current;
};

// Carousel dot click handler
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('carousel-dot')) {
        e.stopPropagation();
        const dot = e.target;
        const carousel = dot.closest('.car-image-carousel');
        if (!carousel) return;

        const targetIndex = parseInt(dot.dataset.index);
        const slides = carousel.querySelector('.car-image-slides');
        const dots = carousel.querySelectorAll('.carousel-dot');

        // Update slide position
        slides.style.transform = `translateX(-${targetIndex * 100}%)`;

        // Update dots
        dots.forEach((d, idx) => {
            d.classList.toggle('active', idx === targetIndex);
        });

        // Store current index
        carousel.dataset.current = targetIndex;
    }
});

// Touch/swipe support for carousels (Mobile)
document.addEventListener('touchstart', function(e) {
    const carousel = e.target.closest('.car-image-carousel');
    if (!carousel) return;

    carousel.dataset.touchStartX = e.touches[0].clientX;
}, { passive: true });

document.addEventListener('touchend', function(e) {
    const carousel = e.target.closest('.car-image-carousel');
    if (!carousel || !carousel.dataset.touchStartX) return;

    const touchEndX = e.changedTouches[0].clientX;
    const touchStartX = parseFloat(carousel.dataset.touchStartX);
    const diff = touchStartX - touchEndX;

    // Minimum swipe distance of 50px
    if (Math.abs(diff) > 50) {
        if (diff > 0) {
            // Swipe left - next slide
            window.slideCarousel(carousel.id, 1);
        } else {
            // Swipe right - previous slide
            window.slideCarousel(carousel.id, -1);
        }
    }

    delete carousel.dataset.touchStartX;
}, { passive: true });

// Mouse drag support for carousels (Desktop)
document.addEventListener('mousedown', function(e) {
    const carousel = e.target.closest('.car-image-carousel');
    if (!carousel) return;

    // Don't interfere with button clicks
    if (e.target.closest('.carousel-nav') || e.target.closest('.carousel-dot')) return;

    carousel.dataset.mouseStartX = e.clientX;
    carousel.dataset.isDragging = 'false'; // Start as false, set to true on move
    carousel.style.cursor = 'grabbing';

    // Prevent text selection during drag
    e.preventDefault();
});

document.addEventListener('mousemove', function(e) {
    const carousel = e.target.closest('.car-image-carousel');
    if (!carousel || !carousel.dataset.mouseStartX) return;

    // Mark as dragging if mouse moved
    carousel.dataset.isDragging = 'true';

    // Optional: Add visual feedback during drag
    const diff = parseFloat(carousel.dataset.mouseStartX) - e.clientX;
    if (Math.abs(diff) > 10) {
        carousel.style.cursor = 'grabbing';
    }
});

document.addEventListener('mouseup', function(e) {
    const carousel = document.querySelector('.car-image-carousel[data-mouse-start-x]');
    if (!carousel) return;

    const mouseEndX = e.clientX;
    const mouseStartX = parseFloat(carousel.dataset.mouseStartX);
    const diff = mouseStartX - mouseEndX;
    const isDragging = carousel.dataset.isDragging === 'true';

    // Only trigger slide if it was a drag (not just a click)
    // Minimum swipe distance of 50px
    if (isDragging && Math.abs(diff) > 50) {
        if (diff > 0) {
            // Drag left - next slide
            window.slideCarousel(carousel.id, 1);
        } else {
            // Drag right - previous slide
            window.slideCarousel(carousel.id, -1);
        }
    }

    // Reset cursor and cleanup
    carousel.style.cursor = 'grab';
    delete carousel.dataset.mouseStartX;
    delete carousel.dataset.isDragging;
});

// Reset cursor when mouse leaves carousel
document.addEventListener('mouseleave', function(e) {
    if (e.target.classList && e.target.classList.contains('car-image-carousel')) {
        e.target.style.cursor = 'grab';
        delete e.target.dataset.mouseStartX;
        delete e.target.dataset.isDragging;
    }
}, true);

// Update user avatar with initials
function updateUserAvatar(userName) {
    const avatarBtn = document.getElementById('userAvatar');
    if (avatarBtn && userName) {
        // Extract initials from name
        const nameParts = userName.trim().split(/\s+/).filter(n => n.length > 0);
        let initials = '';

        if (nameParts.length >= 2) {
            initials = nameParts[0][0] + nameParts[nameParts.length - 1][0];
        } else if (nameParts.length === 1) {
            initials = nameParts[0].substring(0, 2);
        }

        initials = initials.toUpperCase();

        if (initials) {
            avatarBtn.innerHTML = `<span>${initials}</span>`;
            avatarBtn.title = userName + ' (Online)';
        } else {
            avatarBtn.innerHTML = '<i class="fas fa-user"></i>';
        }

        // Create/update mobile avatar (visible on mobile/tablet in header bar)
        ensureMobileAvatar(initials, userName);
    }
}

function ensureMobileAvatar(initials, userName) {
    let mobileAvatar = document.getElementById('mobileAvatarBtn');
    const headerContainer = document.querySelector('.header-container');
    if (!headerContainer) return;

    if (!mobileAvatar) {
        mobileAvatar = document.createElement('a');
        mobileAvatar.id = 'mobileAvatarBtn';
        mobileAvatar.className = 'mobile-avatar-btn';
        mobileAvatar.href = 'profile.html';
        mobileAvatar.title = userName ? userName + ' (Online)' : 'My Profile';
        // Insert before tablet toggle or mobile toggle
        const tabletToggle = headerContainer.querySelector('.tablet-user-menu-toggle');
        const mobileToggle = headerContainer.querySelector('.mobile-menu-toggle');
        const refEl = tabletToggle || mobileToggle;
        if (refEl) {
            headerContainer.insertBefore(mobileAvatar, refEl);
        } else {
            headerContainer.appendChild(mobileAvatar);
        }
    }

    mobileAvatar.innerHTML = initials
        ? `<span>${initials}</span>`
        : '<i class="fas fa-user"></i>';
    mobileAvatar.title = userName ? userName + ' (Online)' : 'My Profile';
    mobileAvatar.style.display = '';
}

// Hide mobile avatar on logout
function hideMobileAvatar() {
    const el = document.getElementById('mobileAvatarBtn');
    if (el) el.style.display = 'none';
}

// Scroll to top functionality removed - using .back-to-top button from script.js instead

// ============================================================================
// BACK TO TOP BUTTON
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Create back to top button if it doesn't exist
    if (!document.querySelector('.back-to-top')) {
        const backToTop = document.createElement('button');
        backToTop.className = 'back-to-top';
        backToTop.innerHTML = '<i class="fas fa-chevron-up"></i>';
        backToTop.title = 'Back to top';
        backToTop.setAttribute('aria-label', 'Back to top');
        document.body.appendChild(backToTop);

        // Show/hide button on scroll
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        });

        // Scroll to top on click
        backToTop.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
});

// ============================================================================
// Sticky Elements - Enhanced Scroll Behavior
// ============================================================================
// Adds visual feedback when sticky elements are in fixed position

document.addEventListener('DOMContentLoaded', function() {
    // Sticky elements configuration
    const stickyElements = [
        { selector: '.listings-search', threshold: 100 },
        { selector: '.sidebar', threshold: 100 },
        { selector: '.dealers-filters', threshold: 100 },
        { selector: '.garage-filters-container', threshold: 100 },
        { selector: '.filter-section', threshold: 80 }
    ];
    
    // Track scroll position and add 'scrolled' class
    function handleStickyScroll() {
        const scrollY = window.scrollY || window.pageYOffset;
        const isMobile = window.innerWidth <= 768;
        const isTabletOrMobile = window.innerWidth <= 1024;
        
        stickyElements.forEach(config => {
            const elements = document.querySelectorAll(config.selector);
            elements.forEach(element => {
                if (element) {
                    // Skip dealers-filters on mobile - it should be hidden
                    if (config.selector === '.dealers-filters' && isMobile) {
                        return;
                    }

                    // Tablet/mobile uses drawer/toggle filters; do not attach sticky scrolled class.
                    if (isTabletOrMobile && (
                        config.selector === '.sidebar' ||
                        config.selector === '.dealers-filters' ||
                        config.selector === '.garage-filters-container' ||
                        config.selector === '.filter-section'
                    )) {
                        element.classList.remove('scrolled');
                        return;
                    }
                    
                    if (scrollY > config.threshold) {
                        element.classList.add('scrolled');
                    } else {
                        element.classList.remove('scrolled');
                    }
                }
            });
        });
    }
    
    // Throttle scroll events for performance
    let ticking = false;
    function throttledScroll() {
        if (!ticking) {
            window.requestAnimationFrame(() => {
                handleStickyScroll();
                ticking = false;
            });
            ticking = true;
        }
    }
    
    // Add scroll listener
    window.addEventListener('scroll', throttledScroll, { passive: true });
    
    // Initial check
    handleStickyScroll();
});


// ============================================================================
// Admin Portal Integration
// ============================================================================
// If user is logged in as admin on main site, allow seamless admin portal access

function checkAdminAccess() {
    const userDataStr = localStorage.getItem('motorlink_user');
    if (!userDataStr) return false;
    
    try {
        const userData = JSON.parse(userDataStr);
        return userData.type === 'admin';
    } catch (e) {
        return false;
    }
}

// Admin Portal link removed - "Admin Panel" is already shown in the navigation
// The dashboard link system (line 480-498) handles admin access


// Populate Year Range Filters
function populateYearFilters() {
    const currentYear = new Date().getFullYear();
    const startYear = 1950;
    
    const yearFromSelect = document.querySelector('select[name="year_from"]');
    const yearToSelect = document.querySelector('select[name="year_to"]');
    
    if (yearFromSelect) {
        // Populate "From Year" dropdown (oldest to newest)
        for (let year = startYear; year <= currentYear; year++) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearFromSelect.appendChild(option);
        }
    }
    
    if (yearToSelect) {
        // Populate "To Year" dropdown (oldest to newest)
        for (let year = startYear; year <= currentYear; year++) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearToSelect.appendChild(option);
        }
    }
}

// Initialize year filters on page load
document.addEventListener('DOMContentLoaded', populateYearFilters);

// Load AI Chatbot Widget on all pages (only for logged-in users)
async function loadAIChatbot() {
    // Only load if not already loaded
    if (document.getElementById('aiCarChatWidget')) {
        return;
    }
    
    // Check authentication first - only show for logged-in users
    try {
        const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
            ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
        });
        
        // Check if response is valid JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // Not JSON response - likely server configuration issue
            if (CONFIG.DEBUG) {
                console.warn('Chatbot: API returned non-JSON response, skipping chatbot load');
            }
            return;
        }
        
        const data = await response.json();
        
        // Only proceed if user is authenticated
        if (!data.success || !data.authenticated) {
            // Guest user - don't load chatbot
            return;
        }
    } catch (error) {
        // If auth check fails, don't load chatbot (silently fail for better UX)
        if (CONFIG.DEBUG) {
            console.error('Auth check error for chatbot:', error);
        }
        return;
    }
    
    // User is authenticated - proceed to load chatbot
    // Load CSS with low priority to ensure page CSS loads first
    if (!document.querySelector('link[href*="ai-car-chat.css"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'css/ai-car-chat.css';
        link.media = 'print'; // Load with low priority
        link.onload = function() {
            this.media = 'all'; // Switch to all media after load
        };
        document.head.appendChild(link);
    }
    
    // Create widget HTML
    const widgetHTML = `
        <div class="ai-car-chat-widget minimized" id="aiCarChatWidget">
            <div class="ai-chat-header" id="aiChatHeader">
                <div class="ai-chat-header-info">
                    <div class="ai-chat-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ai-chat-title">
                        <h3>MotorLink AI Assistant</h3>
                        <p>Your personal assistant</p>
                    </div>
                </div>
                <div class="ai-chat-header-actions">
                    <button class="ai-chat-header-btn" id="aiChatSaveTranscriptBtn" title="Save transcript" aria-label="Save chat transcript">
                        <i class="fas fa-download"></i>
                    </button>
                    <button class="ai-chat-header-btn ai-chat-header-minimise" id="aiChatMinimizeBtn" title="Minimise" aria-label="Minimise chat">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </div>
            <div class="ai-usage-bar" id="aiUsageBar" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 14px; background:linear-gradient(90deg,#eef4ff,#f7faff); border-bottom:1px solid #e2e8f0; font-size:12px; font-weight:600; color:#334155;">
                <div class="ai-usage-indicator" id="aiUsageIndicator" style="flex:1; min-width:0;">
                    <span id="aiUsageText">Loading usage limits…</span>
                </div>
                <button type="button" id="aiUsageRefreshBtn" title="Refresh usage" aria-label="Refresh usage" style="border:none; background:rgba(255,255,255,0.7); color:#475569; cursor:pointer; border-radius:50%; width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center; font-size:12px;">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
            <div class="ai-chat-body" id="aiChatBody" style="display: none;">
                <div class="ai-chat-messages" id="aiChatMessages">
                    <div class="ai-chat-welcome">
                        <div class="ai-chat-avatar-large">
                            <i class="fas fa-robot"></i>
                        </div>
                        <h3>Hello! I'm MotorLink AI Assistant</h3>
                        <p>I can help you with:</p>
                        <ul>
                            <li><i class="fas fa-check"></i> Car questions and specifications</li>
                            <li><i class="fas fa-check"></i> Finding vehicles on our website</li>
                            <li><i class="fas fa-check"></i> Managing your listings</li>
                            <li><i class="fas fa-check"></i> Garage, dealer, and car hire info</li>
                            <li><i class="fas fa-check"></i> General car advice</li>
                        </ul>
                        <div class="ai-chat-conversation-options ai-chat-welcome-options">
                            <div class="ai-chat-conversation-options-label">Popular quick queries:</div>
                            <div class="ai-chat-conversation-options-list">
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Emergency breakdown assistance near me">Emergency breakdown assistance near me</button>
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Tyre repair near me">Tyre repair near me</button>
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Nearest towing service near me">Nearest towing service near me</button>
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Closest garage open now near me">Closest garage open now near me</button>
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Battery jump start near me">Battery jump start near me</button>
                                <button type="button" class="ai-chat-conversation-option-btn ai-chat-welcome-query-btn" data-conversation-option="Fuel delivery near me">Fuel delivery near me</button>
                            </div>
                        </div>
                        <p class="ai-chat-disclaimer">Just ask me anything!</p>
                    </div>
                </div>
                <div class="ai-chat-input-container">
                    <div class="ai-chat-input-wrapper">
                        <textarea 
                            id="aiChatInput" 
                            placeholder="Ask me anything..." 
                            rows="2"
                            maxlength="1500"
                        ></textarea>
                        <button class="ai-chat-send-btn" id="aiChatSendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div class="ai-chat-char-count">
                        <span id="aiChatCharCount">0</span>/1500
                    </div>
                </div>
            </div>
            <button class="ai-chat-minimized" id="aiChatMinimized" aria-label="Open AI chat">
                <span class="ai-min-icon" aria-hidden="true">
                    <i class="fas fa-comments"></i>
                </span>
                <span class="ai-min-label">AI Chat</span>
                <span class="ai-min-live" aria-hidden="true"></span>
            </button>
        </div>
    `;
    
    // Insert widget before closing body tag
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = widgetHTML;
    const widget = tempDiv.firstElementChild;
    document.body.appendChild(widget);
    // 'loaded' class is added by setupWidgetVisibility() in ai-car-chat.js
    // after auth check — prevents the chat from flashing open before auth resolves
    
    // Load the chatbot script if not already loaded
    if (!window.AICarChat && !document.querySelector('script[src*="ai-car-chat.js"]')) {
        const script = document.createElement('script');
        script.src = 'js/ai-car-chat.js';
        script.async = true;
        script.onload = function() {
            if (window.AICarChat) {
                new AICarChat();
            }
        };
        document.body.appendChild(script);
    } else if (window.AICarChat) {
        // Initialize if already loaded
        new AICarChat();
    }
}

// Load chatbot AFTER page is fully loaded and rendered
// This ensures the page CSS loads first and the page looks good before the chatbot appears
if (document.readyState === 'complete') {
    // Page already loaded, wait a bit for CSS to render, then load chatbot
    setTimeout(loadAIChatbot, 500);
} else {
    // Wait for all resources (including CSS) to load before showing chatbot
    window.addEventListener('load', function() {
        // Additional delay to ensure page is fully rendered
        setTimeout(loadAIChatbot, 500);
    });
}
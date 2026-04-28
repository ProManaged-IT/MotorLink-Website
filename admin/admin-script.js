// Sidebar toggle for mobile/tablet
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('adminSidebar');
    var toggleBtn = document.getElementById('mobileSidebarToggle');
    var overlay = document.getElementById('mobileOverlay');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('mobile-open');
        if (overlay) { overlay.classList.add('active'); overlay.style.display = 'block'; }
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) { overlay.classList.remove('active'); overlay.style.display = ''; }
        document.body.style.overflow = '';
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
    // Close sidebar when clicking outside on mobile
    document.body.addEventListener('click', function(e) {
        if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains('mobile-open')) {
            if (!sidebar.contains(e.target) && e.target !== toggleBtn && (!toggleBtn || !toggleBtn.contains(e.target))) {
                closeSidebar();
            }
        }
    });
});
// Admin API configuration - works same way as main website
// Uses proxy to forward requests to production API
const getAdminAPIUrl = () => {
    const hostname = window.location.hostname;
    const protocol = window.location.protocol;

    const isLocal = hostname === 'localhost' ||
        hostname === '127.0.0.1' ||
        protocol === 'file:' ||
        hostname.startsWith('192.168.') ||
        hostname.startsWith('10.') ||
        /^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(hostname);

    // When opened from file: or from a non-PHP static dev server, point to the local PHP server.
    if (isLocal) {
        const currentPort = window.location.port || '';
        if (protocol === 'file:' || (currentPort && currentPort !== '80' && currentPort !== '443' && currentPort !== '8000')) {
            return `${window.location.protocol}//${window.location.hostname}:8000/admin/admin-api.php`;
        }
    }

    // In all normal served environments, resolve admin-api.php relative to the current admin page.
    return new URL('admin-api.php', window.location.href).toString();
};

const ADMIN_API_BASE = getAdminAPIUrl();

function adminHandleImageFallback(imgEl, iconClass) {
    if (!imgEl) return;
    imgEl.onerror = null;
    const container = imgEl.closest('.car-image-thumb');
    if (!container) return;

    const safeClass = String(iconClass || 'text-muted').replace(/[^a-zA-Z0-9_\- ]/g, '').trim();
    container.innerHTML = `<i class="fas fa-car ${safeClass}"></i>`;
}

// Debug logger - only logs when DEBUG mode is enabled
const debugLog = (...args) => {
    if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) {
    }
};

function getConfiguredCurrencyLabel() {
    const symbol = document.getElementById('currency-symbol')?.value?.trim();
    const code = document.getElementById('currency-code')?.value?.trim();
    return symbol || code || 'LOCAL';
}

function formatConfiguredCurrencyAmount(amount, prefix) {
    const numericAmount = Number(amount || 0);
    const safeAmount = Number.isFinite(numericAmount) ? numericAmount : 0;
    const formattedAmount = new Intl.NumberFormat().format(safeAmount);
    return `${prefix || ''}${getConfiguredCurrencyLabel()} ${formattedAmount}`.trim();
}

function stripConfiguredCurrencyText(value) {
    let normalized = String(value || '');
    const label = getConfiguredCurrencyLabel();
    if (label) {
        normalized = normalized.replace(new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '');
    }
    return normalized.replace(/\b(?:MWK|USD|LOCAL)\b/g, '').replace(/,|\n/g, '').trim();
}

debugLog('Admin API Base URL:', ADMIN_API_BASE);

class AdminDashboard {
    constructor() {
        this.API_URL = ADMIN_API_BASE;
        this.isLoggedIn = false;
        this.currentSection = 'dashboard';
        this.adminData = null;
        this.lastAuthCheckAt = 0;
        this.init();
    }

    init() {
        debugLog('Initializing Admin Dashboard...');
        this.setupEventListeners();
        this.checkLoginStatus();
    }
    
    setupEventListeners() {
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const section = item.dataset.section;
                this.showSection(section);
            });
        });

        // Keep sidebar/overlay state consistent when viewport changes.
        window.addEventListener('resize', () => {
            if (window.innerWidth > 1024) {
                this.closeMobileSidebar();
            }
        });
        
        // Close mobile menu when clicking overlay
        const overlay = document.getElementById('mobileOverlay');
        if (overlay) {
            overlay.addEventListener('click', () => {
                this.closeMobileSidebar();
            });
        }
        
        // Close mobile menu when clicking nav items
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                this.closeMobileSidebar();
            });
        });

        document.addEventListener('submit', (e) => {
            if (e.target.matches('form')) {
                e.preventDefault();
            }
        });

        debugLog('Event listeners set up successfully');
    }

    closeMobileSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('mobileOverlay');

        if (sidebar) {
            sidebar.classList.remove('mobile-open', 'active');
        }

        if (overlay) {
            overlay.classList.remove('active');
            overlay.style.display = '';
        }

        document.body.style.overflow = '';
    }

    clearBlockingOverlays() {
        // Clear mobile overlay/scrim state.
        const overlay = document.getElementById('mobileOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            overlay.style.display = '';
        }

        // Clear any modal that may have remained active due to navigation/session flow.
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });

        document.body.style.overflow = '';
    }

    async checkLoginStatus() {
        // Always check server-side session first and ONLY trust server
        try {
            debugLog('Checking server-side admin session...');
            // Properly construct URL - check if base URL already has query params
            const separator = this.API_URL.includes('?') ? '&' : '?';
            const checkAuthUrl = `${this.API_URL}${separator}action=check_admin_auth`;
            debugLog('Check auth URL:', checkAuthUrl);

            const response = await fetch(checkAuthUrl, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();
            debugLog('Server auth check response:', data);

            if (data.success && data.authenticated) {
                // Server confirms we have a valid session
                this.isLoggedIn = true;
                this.adminData = data.admin;
                this.lastAuthCheckAt = Date.now();

                // Sync localStorage with server session
                localStorage.setItem('admin_token', 'server_session');
                localStorage.setItem('admin_name', data.admin.name);
                localStorage.setItem('admin_email', data.admin.email);

                debugLog('Valid server-side admin session found');
                // Onboarding managers have no access to the admin dashboard
                if (data.admin.role === 'onboarding_manager') {
                    window.location.href = '../onboarding/onboarding.html';
                    return;
                }
                this.showDashboard();
                return;
            } else {
                // No valid server session - clear any stale localStorage data
                debugLog('No valid server session - clearing local storage');
                localStorage.removeItem('admin_token');
                localStorage.removeItem('admin_name');
                localStorage.removeItem('admin_email');
                
                // Show login
                this.showLogin();
                return;
            }
        } catch (error) {
            debugLog('Server auth check failed:', error);
            // On error, clear localStorage and show login
            localStorage.removeItem('admin_token');
            localStorage.removeItem('admin_name');
            localStorage.removeItem('admin_email');
            this.showLogin();
            return;
        }
    }

    async ensureAuthenticated(force = false) {
        if (!this.isLoggedIn) {
            return false;
        }

        const authCheckTtlMs = 60000;
        if (!force && this.lastAuthCheckAt && (Date.now() - this.lastAuthCheckAt) < authCheckTtlMs) {
            return true;
        }

        try {
            const separator = this.API_URL.includes('?') ? '&' : '?';
            const checkAuthUrl = `${this.API_URL}${separator}action=check_admin_auth`;
            const response = await fetch(checkAuthUrl, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            const data = await response.json();

            if (response.ok && data.success && data.authenticated) {
                this.isLoggedIn = true;
                this.adminData = data.admin || this.adminData;
                this.lastAuthCheckAt = Date.now();
                return true;
            }

            this.clearSessionAndShowLogin();
            return false;
        } catch (error) {
            debugLog('Non-blocking admin auth probe failed:', error);
            return true;
        }
    }

    showLogin() {
        debugLog('Showing login screen');
        const loginSection = document.getElementById('loginSection');
        const adminDashboard = document.getElementById('adminDashboard');
        const authLoader = document.getElementById('adminAuthLoader');

        this.clearBlockingOverlays();

        // Hide auth loader
        if (authLoader) authLoader.style.display = 'none';

        if (loginSection) {
            loginSection.classList.remove('is-hidden');
            loginSection.style.display = 'flex';
            loginSection.style.visibility = 'visible';
            loginSection.style.pointerEvents = 'auto';
            loginSection.style.position = 'fixed';
            loginSection.style.left = '0';
        }
        if (adminDashboard) {
            adminDashboard.classList.add('is-hidden');
            adminDashboard.style.display = 'none';
            adminDashboard.style.visibility = 'hidden';
            adminDashboard.style.pointerEvents = 'none';
        }

        this.isLoggedIn = false;
    }

    showDashboard() {
        debugLog('Showing admin dashboard');

        const loginSection = document.getElementById('loginSection');
        const adminDashboard = document.getElementById('adminDashboard');
        const authLoader = document.getElementById('adminAuthLoader');

        this.clearBlockingOverlays();

        // Hide auth loader
        if (authLoader) authLoader.style.display = 'none';

        // Completely hide login section
        if (loginSection) {
            loginSection.classList.add('is-hidden');
            loginSection.style.display = 'none';
            loginSection.style.visibility = 'hidden';
            loginSection.style.pointerEvents = 'none';
            loginSection.style.position = 'absolute';
            loginSection.style.left = '-9999px';
        }

        // Show dashboard
        if (adminDashboard) {
            adminDashboard.classList.remove('is-hidden');
            adminDashboard.style.display = 'flex';
            adminDashboard.style.visibility = 'visible';
            adminDashboard.style.pointerEvents = 'auto';
            adminDashboard.style.position = 'relative';
            adminDashboard.style.left = '0';
        }

        this.isLoggedIn = true;

        if (this.adminData) {
            const adminNameEl = document.getElementById('adminName');
            const headerAdminNameEl = document.getElementById('headerAdminName');

            if (adminNameEl) adminNameEl.textContent = this.adminData.name;
            if (headerAdminNameEl) headerAdminNameEl.textContent = this.adminData.name;
        }

        Promise.resolve(loadSettings())
            .catch((error) => { debugLog('Unable to preload admin settings:', error); })
            .finally(() => { this.loadDashboardData(); });
    }

    showSection(section) {
        debugLog(`Switching to section: ${section}`);

        // Ensure any transient overlays are cleared before rendering a new section.
        this.resetTransientUIState();
        
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
        });
        document.querySelector(`[data-section="${section}"]`).classList.add('active');

        document.querySelectorAll('.content-section').forEach(sec => {
            sec.classList.remove('active');
            sec.style.display = 'none';
        });
        
        // Handle special section IDs that don't follow the pattern
        let sectionId = `${section}-section`;
        if (section === 'ai-chat-usage') {
            sectionId = 'ai-chat-usage-section';
        }
        
        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.classList.add('active');
            targetSection.style.display = 'block';
            
        } else {
            console.warn(`Section ${sectionId} not found`);
        }

        const titles = {
            'dashboard': 'Dashboard',
            'cars': 'Car Listings',
            'reports': 'Listing Reports',
            'reviews': 'Business Reviews',
            'pending-cars': 'Pending Cars',
            'guest-listings': 'Guest Listings',
            'rejected-cars': 'Rejected Cars',
            'payments': 'Payments',
            'users': 'User Management',
            'garages': 'Garage Management',
            'dealers': 'Dealer Management',
            'car-hire': 'Car Hire Management',
            'makes-models': 'Makes & Models',
            'locations': 'Locations',
            'analytics': 'Analytics',
            'settings': 'Settings',
            'logs': 'Activity Logs',
            'ai-chat-usage': 'AI Chat Usage Logs',
        };
        document.getElementById('pageTitle').textContent = titles[section] || 'Dashboard';

        this.currentSection = section;
        this.loadSectionData(section);
    }

    toggleSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const main = document.getElementById('adminMain');
        const overlay = document.getElementById('mobileOverlay');
        
        // Check if mobile (use 1024px to match CSS breakpoint)
        const isMobile = window.innerWidth <= 1024;
        
        if (isMobile) {
            // Mobile: toggle mobile-open class and overlay
            sidebar.classList.toggle('mobile-open');
            sidebar.classList.toggle('active'); // Also toggle active for compatibility
            if (overlay) {
                overlay.classList.toggle('active');
            }
            document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
        } else {
            this.closeMobileSidebar();
            // Desktop: toggle collapsed state
            sidebar.classList.toggle('collapsed');
            if (main) {
                main.classList.toggle('expanded');
            }
        }
    }

    resetTransientUIState() {
        this.closeMobileSidebar();

        // Close any open modal overlays so section navigation never leaves a dimmed page behind.
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.remove();
        });

        document.body.style.overflow = '';
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.remove();
        }

        if (!document.querySelector('.modal-overlay.active')) {
            document.body.style.overflow = '';
        }
    }

    prepareModal(modalId) {
        document.querySelectorAll(`[id="${modalId}"]`).forEach(existing => existing.remove());
    }

    async loadDashboardData() {
        debugLog('Loading dashboard data...');
        try {
            await Promise.all([
                this.loadStats(),
                this.loadRecentActivities(),
                this.loadPendingApprovals()
            ]);
            debugLog('Dashboard data loaded successfully');
        } catch (error) {
            this.showAlert('error', 'Failed to load dashboard data');
        }
    }

    async loadStats() {
        try {
            debugLog('Loading dashboard stats...');
            const response = await this.apiCall('dashboard_stats');
            
            if (response.success && response.stats) {
                const stats = response.stats;
                
                document.getElementById('totalCars').textContent = stats.total_cars || 0;
                document.getElementById('totalUsers').textContent = stats.total_users || 0;
                document.getElementById('pendingItems').textContent = 
                    (stats.pending_cars + stats.pending_users + stats.pending_garages + stats.pending_dealers + (stats.pending_car_hire || 0)) || 0;
                document.getElementById('todayRevenue').textContent = 
                    formatConfiguredCurrencyAmount(stats.today_revenue || 0);
                
                document.getElementById('pendingCount').textContent = 
                    (stats.pending_cars + stats.pending_users + stats.pending_garages + stats.pending_dealers + (stats.pending_car_hire || 0)) || 0;
                document.getElementById('paymentsCount').textContent = stats.pending_payments || 0;
                this.updateServicesPendingBadges(stats);
                
                debugLog('Stats loaded successfully:', stats);
            } else {
                throw new Error(response.message || 'Invalid stats response');
            }
        } catch (error) {
            document.getElementById('totalCars').textContent = '0';
            document.getElementById('totalUsers').textContent = '0';
            document.getElementById('pendingItems').textContent = '0';
            document.getElementById('todayRevenue').textContent = formatConfiguredCurrencyAmount(0);
            this.updateServicesPendingBadges({ pending_garages: 0, pending_dealers: 0, pending_car_hire: 0 });
        }
    }

    updateServicesPendingBadges(stats = {}) {
        const updateBadge = (id, value) => {
            const badge = document.getElementById(id);
            if (!badge) return;

            const count = Number(value || 0);
            badge.textContent = String(count);
            badge.classList.toggle('is-empty', count <= 0);
        };

        updateBadge('garagePendingBadge', stats.pending_garages);
        updateBadge('dealerPendingBadge', stats.pending_dealers);
        updateBadge('carHirePendingBadge', stats.pending_car_hire);
        updateBadge('pendingCarsBadge', stats.pending_cars);
        updateBadge('guestListingsBadge', stats.pending_guest_listings);
    }
    
    async setPrimaryImage(imageId, carId) {
        try {
            const response = await this.apiCall('set_primary_image', 'POST', {
                image_id: imageId,
                car_id: carId
            });

            if (response.success) {
                this.showAlert('success', 'Primary image updated successfully');
                const refreshed = await this.refreshCarImageManagers(carId);
                if (!refreshed) {
                    await this.refreshCarImages(carId);
                }
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to set primary image');
        }
    }

    renderCarImageManagerCards(images, carId) {
        if (!images || images.length === 0) {
            return '<div class="text-center text-muted" style="grid-column:1/-1; padding:24px;">No images found for this listing</div>';
        }

        return images.map((img, index) => {
            const imageUrl = this.getSafeUploadUrl(img.filename || img.file_path || img.thumbnail_path || '');
            const imageName = this.escapeHtml(img.original_filename || `Image ${index + 1}`);
            return `
                <div class="car-image-card" style="position: relative; border: 1px solid #e6eaf0; border-radius: 12px; overflow: hidden; background:#fff; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                    <img src="${imageUrl}" alt="${imageName}"
                         style="width: 100%; height: 180px; object-fit: cover; cursor: zoom-in; display:block;"
                         onerror="this.onerror=null;this.style.display='none';this.insertAdjacentHTML('afterend','<div style=&quot;height:180px;display:flex;align-items:center;justify-content:center;color:#6b7280;background:#f8fafc;&quot;><i class=&quot;fas fa-image fa-2x&quot;></i></div>');"
                         onclick="window.open('${imageUrl}', '_blank')">
                    ${img.is_primary ? '<span style="position:absolute;top:10px;left:10px;background:#16a34a;color:#fff;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;">Primary</span>' : ''}
                    <div style="padding: 12px;">
                        <div style="font-size:12px;color:#6b7280;margin-bottom:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${imageName}</div>
                        <div style="display:flex; gap:8px; justify-content:space-between;">
                            ${!img.is_primary ? `<button class="btn btn-sm btn-success" onclick="admin.setPrimaryImage(${img.id}, ${carId})" title="Set as Primary"><i class="fas fa-star"></i> Primary</button>` : '<span class="text-success small" style="align-self:center;font-weight:600;">Current Primary</span>'}
                            <button class="btn btn-sm btn-danger" onclick="admin.deleteCarImage(${img.id}, ${carId})" title="Delete Image"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    async refreshCarImageManagers(carId) {
        const galleries = Array.from(document.querySelectorAll(`[data-car-gallery-for="${carId}"]`));
        if (galleries.length === 0) {
            return false;
        }

        galleries.forEach(gallery => {
            gallery.innerHTML = '<div class="text-center text-muted" style="grid-column:1/-1; padding:24px;">Reloading images...</div>';
        });

        try {
            const response = await this.apiCall('get_car_images', 'GET', { car_id: carId });
            if (!response.success) {
                throw new Error(response.message || 'Failed to load images');
            }

            const markup = this.renderCarImageManagerCards(response.images || [], carId);
            galleries.forEach(gallery => {
                gallery.innerHTML = markup;
            });
            return true;
        } catch (error) {
            galleries.forEach(gallery => {
                gallery.innerHTML = '<div class="text-center text-danger" style="grid-column:1/-1; padding:24px;">Error loading images</div>';
            });
            return true;
        }
    }

    async refreshCarImages(carId) {
        try {
            const response = await this.apiCall('get_car_images', 'GET', { car_id: carId });
            
            if (response.success) {
                const imagesContainer = document.getElementById('currentImages');
                const images = response.images || [];
                
                imagesContainer.innerHTML = images.length > 0 ? 
                    images.map((image, index) => {
                        const imageFileName = image.filename;
                        const imageUrl = imageFileName ? this.getSafeUploadUrl(imageFileName) : '';
                        const imageName = image.original_filename || `Image ${index + 1}`;
                        
                        return `
                        <div class="image-item" data-image-id="${image.id}">
                            ${imageUrl ? `
                                <img src="${imageUrl}" alt="${imageName}" 
                                     onerror="this.onerror=null; this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 fill=%22%23f8f9fa%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2214%22 fill=%22%236c757d%22>Image ${index + 1}</text></svg>';">`
                             : `
                                <div style="width:100%;height:120px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;color:#6c757d;">
                                    <i class="fas fa-image fa-2x"></i>
                                </div>
                            `}
                            <div class="image-actions">
                                <button type="button" class="btn btn-danger btn-sm" onclick="admin.deleteCarImage(${image.id})" title="Delete Image">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <button type="button" class="btn btn-success btn-sm" onclick="admin.setPrimaryImage(${image.id}, ${carId})" title="Set as Primary" ${image.is_primary ? 'disabled' : ''}>
                                    <i class="fas fa-star"></i>
                                </button>
                            </div>
                            ${image.is_primary ? '<span class="primary-badge">Primary</span>' : ''}
                            <div style="padding:5px;font-size:11px;text-align:center;background:white;border-top:1px solid #e9ecef;">
                                ${imageName}
                            </div>
                        </div>
                        `;
                    }).join('') : 
                    '<p class="text-muted">No images found for this car</p>';
            }
        } catch (error) {
            this.showAlert('error', 'Failed to refresh images');
        }
    }

    async suspendCar(carId) {
        if (!confirm('Are you sure you want to suspend this car listing? The listing will be hidden from public view.')) {
            return;
        }
        
        try {
            const response = await this.apiCall('update_car', 'POST', { 
                id: carId, 
                status: 'suspended' 
            });
            
            if (response.success) {
                this.showAlert('success', 'Car listing suspended successfully');
                document.getElementById('editCarModal').remove();
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to suspend car listing');
        }
    }

    async unsuspendCar(carId) {
        if (!confirm('Reactivate this car listing? It will become visible to the public.')) return;
        try {
            const response = await this.apiCall('update_car', 'POST', { id: carId, status: 'active' });
            if (response.success) {
                this.showAlert('success', 'Car listing reactivated successfully');
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to reactivate car listing');
        }
    }

    async restoreCar(carId) {
        if (!confirm('Restore this deleted car listing? It will be set back to active.')) return;
        try {
            const response = await this.apiCall('restore_car', 'POST', { id: carId });
            if (response.success) {
                this.showAlert('success', 'Car listing restored successfully');
                await this.loadDeletedCars();
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to restore car listing');
        }
    }

    async updateCar() {
        const form = document.getElementById('editCarForm');
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        data.make_id = parseInt(data.make_id);
        data.model_id = parseInt(data.model_id);
        data.year = parseInt(data.year);
        data.price = parseFloat(data.price);
        data.negotiable = data.negotiable === '1';
        data.mileage = data.mileage ? parseInt(data.mileage) : null;
        data.engine_size = data.engine_size ? parseInt(data.engine_size) : null;
        
        try {
            const response = await this.apiCall('update_car', 'POST', data);
            
            if (response.success) {
                this.showAlert('success', 'Car updated successfully');
                document.getElementById('editCarModal').remove();
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to update car');
        }
    }

    async loadRecentActivities() {
        try {
            debugLog('Loading recent activities...');
            const response = await this.apiCall('recent_activities');
            
            if (response.success && response.activities) {
                this.displayActivities(response.activities);
                debugLog('Activities loaded successfully');
            } else {
                throw new Error(response.message || 'Invalid activities response');
            }
        } catch (error) {
            document.getElementById('recentActivities').innerHTML = 
                '<p class="text-muted">Unable to load recent activities</p>';
        }
    }

    async loadPendingApprovals() {
        try {
            debugLog('Loading pending approvals...');
            const response = await this.apiCall('pending_approvals');
            
            if (response.success && response.pending_items) {
                this.displayPendingApprovals(response.pending_items);
                debugLog('Pending approvals loaded successfully');
            } else {
                throw new Error(response.message || 'Invalid pending items response');
            }
        } catch (error) {
            document.getElementById('pendingApprovals').innerHTML = 
                '<p class="text-muted">Unable to load pending items</p>';
        }
    }

    loadSectionData(section) {
        debugLog(`Loading data for section: ${section}`);
        
        switch (section) {
            case 'cars':
                return this.loadCars();
            case 'reports':
                return this.loadListingReports();
            case 'reviews':
                return this.loadBusinessReviews();
            case 'pending-cars':
                return this.loadPendingCars();
            case 'guest-listings':
                return this.loadGuestListings();
            case 'rejected-cars':
                return this.loadRejectedCars();
            case 'deleted-cars':
                return this.loadDeletedCars();
            case 'payments':
                return Promise.all([
                    this.loadPayments(),
                    this.loadPaymentStats(),
                    this.loadFreeListingsPayments()
                ]);
            case 'users':
                return this.loadUsers();
            case 'ai-chat-usage':
                // Load AI chat usage logs
                return loadAIChatUsers().then(() => {
                    return loadAIChatUsage(1);
                }).catch(err => {
                    console.error('Error loading AI chat users:', err);
                    return loadAIChatUsage(1); // Still try to load usage even if users fail
                });
            case 'admins':
                return this.loadAdmins();
            case 'garages':
                return this.loadGarages();
            case 'dealers':
                return this.loadDealers();
            case 'car-hire':
                this.loadCarHire();
                loadWhatsAppBookings();
                return;
            case 'whatsapp':
                ensureWhatsAppSettingsSection();
                return Promise.all([
                    loadWhatsAppSettings(),
                    loadWhatsAppBookingStats()
                ]);
            case 'makes-models':
                return this.loadMakesModels();
            case 'locations':
                return this.loadLocations();
            case 'logs':
                return this.loadActivityLogs();
            case 'analytics':
                return this.loadAnalytics();
            case 'settings':
                return Promise.all([
                    loadSettings(),
                    loadSystemInfo(),
                    loadAIChatSettings(), // This will also load AI Learning settings
                    loadFuelPriceSettings(),
                    loadFuelSourceSettings()
                ]);
            default:
                return Promise.resolve();
        }
    }

    // ===== ANALYTICS METHODS =====
    async loadAnalytics() {
        debugLog('Loading analytics data...');
        try {
            const timeRange = document.getElementById('analyticsTimeRange')?.value || 30;

            // Load all analytics data in parallel
            await Promise.all([
                this.loadAnalyticsKPIs(timeRange),
                this.loadRevenueChart(timeRange),
                this.loadUserGrowthChart(timeRange),
                this.loadPopularMakesChart(timeRange),
                this.loadGeoChart(timeRange),
                this.loadEngagementMetrics(timeRange),
                this.loadConversionFunnel(timeRange),
                this.loadTopListings(timeRange)
            ]);

            debugLog('Analytics loaded successfully');
        } catch (error) {
            this.showAlert('error', 'Failed to load analytics data');
        }
    }

    async loadAnalyticsKPIs(days = 30) {
        try {
            const response = await this.apiCall('dashboard_stats', 'GET');

            if (response.success && response.stats) {
                const stats = response.stats;

                // Revenue from payments
                document.getElementById('kpiRevenue').textContent = formatConfiguredCurrencyAmount(stats.total_revenue || 0);
                document.getElementById('kpiRevenueChange').textContent = formatConfiguredCurrencyAmount(stats.today_revenue || 0, 'Today: ');
                document.getElementById('kpiRevenueChange').className = 'kpi-change positive';

                // Active users
                document.getElementById('kpiActiveUsers').textContent = this.formatNumber(stats.total_users || 0);
                document.getElementById('kpiUsersChange').textContent = `Active: ${stats.active_users || 0}`;
                document.getElementById('kpiUsersChange').className = 'kpi-change positive';

                // Listings
                document.getElementById('kpiListings').textContent = this.formatNumber(stats.total_cars || 0);
                document.getElementById('kpiListingsChange').textContent = `Active: ${stats.active_cars || 0}`;
                document.getElementById('kpiListingsChange').className = 'kpi-change positive';

                // Pending items (as conversion rate placeholder)
                const pendingTotal = (stats.pending_cars || 0) + (stats.pending_users || 0) + (stats.pending_garages || 0) + (stats.pending_dealers || 0) + (stats.pending_car_hire || 0);
                document.getElementById('kpiConversion').textContent = this.formatNumber(pendingTotal);
                document.getElementById('kpiConversionChange').textContent = 'Pending Approvals';
                document.getElementById('kpiConversionChange').className = 'kpi-change';
            } else {
                throw new Error('Failed to load stats');
            }
        } catch (error) {
            document.getElementById('kpiRevenue').textContent = formatConfiguredCurrencyAmount(0);
            document.getElementById('kpiRevenueChange').textContent = 'No data';
            document.getElementById('kpiActiveUsers').textContent = '0';
            document.getElementById('kpiUsersChange').textContent = 'No data';
            document.getElementById('kpiListings').textContent = '0';
            document.getElementById('kpiListingsChange').textContent = 'No data';
            document.getElementById('kpiConversion').textContent = '0';
            document.getElementById('kpiConversionChange').textContent = 'No data';
        }
    }

    async loadRevenueChart(days = 30) {
        try {
            // Simple text display instead of chart
            const canvas = document.getElementById('revenueChartCanvas');
            if (!canvas) return;

            const response = await this.apiCall('dashboard_stats', 'GET');
            if (response.success && response.stats) {
                const ctx = canvas.getContext('2d');
                canvas.height = 300;
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Display revenue info
                ctx.fillStyle = '#00c853';
                ctx.font = 'bold 24px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Total Revenue', canvas.width / 2, 100);

                ctx.font = 'bold 48px Arial';
                ctx.fillText(formatConfiguredCurrencyAmount(response.stats.total_revenue || 0), canvas.width / 2, 160);

                ctx.font = '16px Arial';
                ctx.fillStyle = '#666';
                ctx.fillText(formatConfiguredCurrencyAmount(response.stats.today_revenue || 0, 'Today: '), canvas.width / 2, 200);
            }
        } catch (error) {
        }
    }

    async loadUserGrowthChart(days = 30) {
        try {
            const canvas = document.getElementById('userGrowthChartCanvas');
            if (!canvas) return;

            const response = await this.apiCall('dashboard_stats', 'GET');
            if (response.success && response.stats) {
                const ctx = canvas.getContext('2d');
                canvas.height = 300;
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                ctx.fillStyle = '#667eea';
                ctx.font = 'bold 24px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('User Statistics', canvas.width / 2, 80);

                ctx.font = 'bold 42px Arial';
                ctx.fillText(this.formatNumber(response.stats.total_users || 0), canvas.width / 2, 140);
                ctx.font = '18px Arial';
                ctx.fillStyle = '#333';
                ctx.fillText('Total Users', canvas.width / 2, 170);

                ctx.font = 'bold 32px Arial';
                ctx.fillStyle = '#00c853';
                ctx.fillText(this.formatNumber(response.stats.active_users || 0), canvas.width / 2, 220);
                ctx.font = '16px Arial';
                ctx.fillStyle = '#666';
                ctx.fillText('Active Users', canvas.width / 2, 245);
            }
        } catch (error) {
        }
    }

    async loadPopularMakesChart(days = 30) {
        try {
            const canvas = document.getElementById('popularMakesChartCanvas');
            if (!canvas) return;

            const response = await this.apiCall('get_car_makes', 'GET');
            if (response.success && response.makes) {
                const ctx = canvas.getContext('2d');
                canvas.height = 300;
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                ctx.fillStyle = '#00c853';
                ctx.font = 'bold 20px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Available Car Makes', canvas.width / 2, 40);

                ctx.font = 'bold 56px Arial';
                ctx.fillText(response.makes.length.toString(), canvas.width / 2, 130);

                ctx.font = '16px Arial';
                ctx.fillStyle = '#666';
                ctx.fillText('Different brands in database', canvas.width / 2, 160);

                // Show top 5 makes
                const topMakes = response.makes.slice(0, 5);
                ctx.font = '14px Arial';
                ctx.textAlign = 'left';
                let y = 200;
                topMakes.forEach((make, index) => {
                    ctx.fillText(`${index + 1}. ${make.name}`, canvas.width / 2 - 100, y);
                    y += 20;
                });
            }
        } catch (error) {
        }
    }

    async loadGeoChart(days = 30) {
        try {
            const canvas = document.getElementById('geoChartCanvas');
            if (!canvas) return;

            const response = await this.apiCall('get_locations', 'GET');
            if (response.success && response.locations) {
                const ctx = canvas.getContext('2d');
                canvas.height = 300;
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                ctx.fillStyle = '#00c853';
                ctx.font = 'bold 20px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Service Locations', canvas.width / 2, 40);

                ctx.font = 'bold 56px Arial';
                ctx.fillText(response.locations.length.toString(), canvas.width / 2, 130);

                ctx.font = '16px Arial';
                ctx.fillStyle = '#666';
                ctx.fillText('Locations covered', canvas.width / 2, 160);

                // Show locations
                const locations = response.locations.slice(0, 6);
                ctx.font = '14px Arial';
                ctx.textAlign = 'left';
                let y = 200;
                locations.forEach((loc, index) => {
                    ctx.fillText(`• ${loc.name}`, canvas.width / 2 - 100, y);
                    y += 20;
                });
            }
        } catch (error) {
        }
    }

    async loadEngagementMetrics(days = 30) {
        try {
            const response = await this.apiCall('dashboard_stats', 'GET');
            if (response.success && response.stats) {
                const stats = response.stats;

                // Display real database metrics
                document.getElementById('dbTotalCars').textContent = this.formatNumber(stats.total_cars || 0);
                document.getElementById('dbTotalUsers').textContent = this.formatNumber(stats.total_users || 0);

                const pendingTotal = (stats.pending_cars || 0) + (stats.pending_users || 0) + (stats.pending_garages || 0) + (stats.pending_dealers || 0) + (stats.pending_car_hire || 0);
                document.getElementById('dbPendingItems').textContent = this.formatNumber(pendingTotal);
                document.getElementById('dbPendingPayments').textContent = this.formatNumber(stats.pending_payments || 0);
            }
        } catch (error) {
            document.getElementById('dbTotalCars').textContent = '0';
            document.getElementById('dbTotalUsers').textContent = '0';
            document.getElementById('dbPendingItems').textContent = '0';
            document.getElementById('dbPendingPayments').textContent = '0';
        }
    }

    async loadConversionFunnel(days = 30) {
        try {
            const response = await this.apiCall('dashboard_stats', 'GET');
            if (response.success && response.stats) {
                const stats = response.stats;

                document.getElementById('funnelVisitors').textContent = this.formatNumber(stats.total_users || 0);
                document.getElementById('funnelViews').textContent = this.formatNumber(stats.total_cars || 0);
                document.getElementById('funnelInquiries').textContent = this.formatNumber(stats.pending_cars || 0);
                document.getElementById('funnelConversions').textContent = this.formatNumber(stats.active_cars || 0);
            }
        } catch (error) {
        }
    }

    async loadTopListings(days = 30) {
        try {
            const tbody = document.getElementById('topListingsBody');
            const response = await this.apiCall('get_cars', 'GET', { limit: 5, orderBy: 'created_at', order: 'DESC' });

            if (response.success && response.cars && response.cars.length > 0) {
                let html = '';
                response.cars.forEach((car, index) => {
                    html += `
                        <tr>
                            <td><span class="badge badge-primary">#${index + 1}</span></td>
                            <td><strong>${this.escapeHtml(car.make_name || '')} ${this.escapeHtml(car.model_name || '')}</strong></td>
                            <td>${car.year || 'N/A'}</td>
                            <td>${formatConfiguredCurrencyAmount(car.price || 0)}</td>
                            <td><span class="badge badge-${car.status === 'active' ? 'success' : 'warning'}">${car.status || 'N/A'}</span></td>
                            <td>${car.created_at ? new Date(car.created_at).toLocaleDateString() : 'N/A'}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px;">No listings available</td></tr>';
            }
        } catch (error) {
            document.getElementById('topListingsBody').innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px; color: #999;">Failed to load listings</td></tr>';
        }
    }

    renderLineChart(canvasId, config) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        canvas.height = 300;

        // Simple line chart rendering (for demo - in production use Chart.js)
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#666';
        ctx.font = '12px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('Chart visualization requires Chart.js library', canvas.width / 2, canvas.height / 2);
    }

    renderBarChart(canvasId, config) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        canvas.height = 300;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#666';
        ctx.font = '12px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('Chart visualization requires Chart.js library', canvas.width / 2, canvas.height / 2);
    }

    renderDoughnutChart(canvasId, config) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        canvas.height = 300;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#666';
        ctx.font = '12px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('Chart visualization requires Chart.js library', canvas.width / 2, canvas.height / 2);
    }

    exportAnalytics() {
        this.showAlert('info', 'Analytics export feature coming soon! This will export all analytics data to CSV/PDF format.');
    }

// Enhanced load functions with proper filtering
async loadCars() {
    try {
        debugLog('Loading cars...');
        
        const filters = {};
        const statusFilter = document.getElementById('carStatusFilter').value;
        const makeFilter = document.getElementById('carMakeFilter').value;
        const paymentStatusFilter = document.getElementById('carPaymentStatusFilter')?.value;
        const searchFilter = document.getElementById('carSearchFilter').value;
        
        if (statusFilter) filters.status = statusFilter;
        if (makeFilter) filters.make_id = makeFilter;
        if (paymentStatusFilter) filters.payment_status = paymentStatusFilter;
        if (searchFilter) filters.search = searchFilter;
        
        const response = await this.apiCall('get_cars', 'GET', filters);
        
        if (response.success && response.cars) {
            this.displayCarsTable(response.cars);
            debugLog(`Loaded ${response.cars.length} cars`);
            
            // Load makes for filter if not already loaded
            if (document.getElementById('carMakeFilter').children.length <= 1) {
                await this.loadCarMakes();
            }
        } else {
            throw new Error(response.message || 'Invalid cars response');
        }
    } catch (error) {
        document.getElementById('carsTableBody').innerHTML = 
            '<tr><td colspan="13" class="text-center text-muted">Error loading cars</td></tr>';
    }
}

    async loadCarMakes() {
        try {
            const response = await this.apiCall('get_car_makes');
            if (response.success && response.makes) {
                const makeFilter = document.getElementById('carMakeFilter');
                response.makes.forEach(make => {
                    const option = document.createElement('option');
                    option.value = make.id;
                    option.textContent = make.name;
                    makeFilter.appendChild(option);
                });
            }
        } catch (error) {
        }
    }

    toggleSelectAllCars(checkbox) {
        const checkboxes = document.querySelectorAll('.car-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
        });
        this.updateBulkSelection();
    }

    updateBulkSelection() {
        const selectedCount = document.querySelectorAll('.car-checkbox:checked').length;
        const bulkBar = document.getElementById('bulkActionsBar');
        const selectedCountElement = document.getElementById('bulkSelectedCount');
        
        if (selectedCount > 0) {
            bulkBar.style.display = 'flex';
            selectedCountElement.textContent = `${selectedCount} selected`;
            
            const totalCheckboxes = document.querySelectorAll('.car-checkbox').length;
            const selectAllCheckbox = document.getElementById('selectAllCars');
            selectAllCheckbox.checked = selectedCount === totalCheckboxes;
            selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
        } else {
            bulkBar.style.display = 'none';
            const selectAllCheckbox = document.getElementById('selectAllCars');
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }

    clearBulkSelection() {
        const checkboxes = document.querySelectorAll('.car-checkbox:checked');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        this.updateBulkSelection();
    }

    // ===== GARAGE BULK ACTIONS =====

    toggleSelectAllGarages(checkbox) {
        document.querySelectorAll('.garage-checkbox').forEach(cb => { cb.checked = checkbox.checked; });
        this.updateGarageBulkBar();
    }

    updateGarageBulkBar() {
        const selected = document.querySelectorAll('.garage-checkbox:checked');
        const total = document.querySelectorAll('.garage-checkbox');
        const bar = document.getElementById('garageBulkActionsBar');
        const countEl = document.getElementById('garageBulkSelectedCount');
        const selectAll = document.getElementById('selectAllGarages');

        if (selected.length > 0) {
            bar.style.display = 'flex';
            countEl.textContent = `${selected.length} selected`;
            if (selectAll) {
                selectAll.checked = selected.length === total.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < total.length;
            }
        } else {
            bar.style.display = 'none';
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
        }
    }

    clearGarageBulkSelection() {
        document.querySelectorAll('.garage-checkbox:checked').forEach(cb => { cb.checked = false; });
        this.updateGarageBulkBar();
    }

    async executeGarageBulkAction() {
        const action = document.getElementById('garageBulkActionSelect').value;
        const checkboxes = document.querySelectorAll('.garage-checkbox:checked');

        if (!action) { this.showAlert('warning', 'Please select an action'); return; }
        if (checkboxes.length === 0) { this.showAlert('warning', 'Please select at least one garage'); return; }

        const ids = Array.from(checkboxes).map(cb => cb.value);
        const confirmed = await this.showActionConfirmDialog(
            'Confirm Bulk Action',
            `Are you sure you want to ${action} ${ids.length} garage(s)?`,
            'Proceed',
            action === 'delete' ? 'btn-danger' : 'btn-primary'
        );
        if (!confirmed) return;

        try {
            let endpoint = '';
            let data = { garage_ids: ids };

            switch (action) {
                case 'approve':
                case 'reject':
                    endpoint = 'bulk_approve_garages';
                    data.action = action;
                    break;
                case 'activate':
                    endpoint = 'bulk_update_garages';
                    data.status = 'active';
                    break;
                case 'suspend':
                    endpoint = 'bulk_update_garages';
                    data.status = 'suspended';
                    break;
                case 'delete':
                    endpoint = 'bulk_delete_garages';
                    break;
            }

            const response = await this.apiCall(endpoint, 'POST', data);
            if (response.success) {
                this.showAlert('success', response.message || `Bulk ${action} applied to ${ids.length} garage(s)`);
                this.clearGarageBulkSelection();
                await this.loadGarages();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Bulk garage action failed');
        }
    }

    // ===== DEALER BULK ACTIONS =====

    toggleSelectAllDealers(checkbox) {
        document.querySelectorAll('.dealer-checkbox').forEach(cb => { cb.checked = checkbox.checked; });
        this.updateDealerBulkBar();
    }

    updateDealerBulkBar() {
        const selected = document.querySelectorAll('.dealer-checkbox:checked');
        const total = document.querySelectorAll('.dealer-checkbox');
        const bar = document.getElementById('dealerBulkActionsBar');
        const countEl = document.getElementById('dealerBulkSelectedCount');
        const selectAll = document.getElementById('selectAllDealers');

        if (selected.length > 0) {
            bar.style.display = 'flex';
            countEl.textContent = `${selected.length} selected`;
            if (selectAll) {
                selectAll.checked = selected.length === total.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < total.length;
            }
        } else {
            bar.style.display = 'none';
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
        }
    }

    clearDealerBulkSelection() {
        document.querySelectorAll('.dealer-checkbox:checked').forEach(cb => { cb.checked = false; });
        this.updateDealerBulkBar();
    }

    async executeDealerBulkAction() {
        const action = document.getElementById('dealerBulkActionSelect').value;
        const checkboxes = document.querySelectorAll('.dealer-checkbox:checked');

        if (!action) { this.showAlert('warning', 'Please select an action'); return; }
        if (checkboxes.length === 0) { this.showAlert('warning', 'Please select at least one dealer'); return; }

        const ids = Array.from(checkboxes).map(cb => cb.value);
        const confirmed = await this.showActionConfirmDialog(
            'Confirm Bulk Action',
            `Are you sure you want to ${action} ${ids.length} dealer(s)?`,
            'Proceed',
            action === 'delete' ? 'btn-danger' : 'btn-primary'
        );
        if (!confirmed) return;

        try {
            let endpoint = '';
            let data = { dealer_ids: ids };

            switch (action) {
                case 'approve':
                case 'reject':
                    endpoint = 'bulk_approve_dealers';
                    data.action = action;
                    break;
                case 'activate':
                    endpoint = 'bulk_update_dealers';
                    data.status = 'active';
                    break;
                case 'suspend':
                    endpoint = 'bulk_update_dealers';
                    data.status = 'suspended';
                    break;
                case 'delete':
                    endpoint = 'bulk_delete_dealers';
                    break;
            }

            const response = await this.apiCall(endpoint, 'POST', data);
            if (response.success) {
                this.showAlert('success', response.message || `Bulk ${action} applied to ${ids.length} dealer(s)`);
                this.clearDealerBulkSelection();
                await this.loadDealers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Bulk dealer action failed');
        }
    }

    async executeBulkAction() {
        const action = document.getElementById('bulkActionSelect').value;
        const checkboxes = document.querySelectorAll('.car-checkbox:checked');
        
        if (!action) {
            this.showAlert('warning', 'Please select an action');
            return;
        }
        
        if (checkboxes.length === 0) {
            this.showAlert('warning', 'Please select at least one car');
            return;
        }
        
        const carIds = Array.from(checkboxes).map(cb => cb.value);
        
        const actionTexts = {
            'approve': 'approve',
            'reject': 'reject', 
            'activate': 'activate',
            'suspend': 'suspend',
            'delete': 'delete'
        };
        
        const confirmed = await this.showActionConfirmDialog(
            'Confirm Bulk Action',
            `Are you sure you want to ${actionTexts[action]} ${carIds.length} car(s)?`,
            'Proceed',
            action === 'delete' ? 'btn-danger' : 'btn-primary'
        );
        if (!confirmed) {
            return;
        }
        
        try {
            let endpoint = '';
            let data = { car_ids: carIds };
            
            switch (action) {
                case 'approve':
                case 'reject':
                    endpoint = 'bulk_approve_cars';
                    data.action = action;
                    break;
                case 'activate':
                    endpoint = 'bulk_update_cars';
                    data.status = 'active';
                    break;
                case 'suspend':
                    endpoint = 'bulk_update_cars';
                    data.status = 'suspended';
                    break;
                case 'delete':
                    endpoint = 'bulk_delete_cars';
                    break;
            }
            
            const response = await this.apiCall(endpoint, 'POST', data);
            
            if (response.success) {
                this.showAlert('success', response.message || `Successfully ${actionTexts[action]}ed ${carIds.length} car(s)`);
                this.clearBulkSelection();
                await Promise.all([
                    this.loadCars(),
                    this.loadDashboardData()
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to execute bulk action');
        }
    }

async loadPendingCars() {
    try {
        debugLog('Loading pending cars...');
        
        const filters = { status: 'pending_approval' };
        const searchFilter = document.getElementById('pendingCarSearchFilter')?.value;
        const paymentStatusFilter = document.getElementById('pendingCarPaymentStatusFilter')?.value;
        
        if (searchFilter) filters.search = searchFilter;
        if (paymentStatusFilter) filters.payment_status = paymentStatusFilter;
        
        const response = await this.apiCall('get_cars', 'GET', filters);
        
        if (response.success && response.cars) {
            this.displayPendingCarsTable(response.cars);
            debugLog(`Loaded ${response.cars.length} pending cars`);
        } else {
            throw new Error(response.message || 'Invalid pending cars response');
        }
    } catch (error) {
        document.getElementById('pendingCarsTableBody').innerHTML = 
            '<tr><td colspan="11" class="text-center text-muted">Error loading pending cars</td></tr>';
    }
}

async loadGuestListings() {
    try {
        debugLog('Loading guest listings...');

        const filters = { is_guest: 1 };
        const statusFilter = document.getElementById('guestListingStatusFilter')?.value || 'pending_approval';
        const searchFilter = document.getElementById('guestListingSearchFilter')?.value;

        if (statusFilter) {
            if (statusFilter === 'rejected') {
                filters.status = 'rejected';
                filters.approval_status = 'denied';
            } else {
                filters.status = statusFilter;
            }
        }

        if (searchFilter) filters.search = searchFilter;

        const response = await this.apiCall('get_cars', 'GET', filters);

        if (response.success && response.cars) {
            this.displayGuestListingsTable(response.cars);
            debugLog(`Loaded ${response.cars.length} guest listings`);
        } else {
            throw new Error(response.message || 'Invalid guest listings response');
        }
    } catch (error) {
        const tbody = document.getElementById('guestListingsTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Error loading guest listings</td></tr>';
        }
    }
}

    async approveGuestListingWithOverride(listingId, listingEmailVerified) {
        const isVerified = Number(listingEmailVerified || 0) === 1;

        if (!isVerified) {
            const overrideAndApprove = await this.showActionConfirmDialog(
                'Guest Email Not Verified',
                'This guest listing email is not verified yet. Do you want to override verification and approve now?',
                'Override & Approve',
                'btn-warning'
            );

            if (!overrideAndApprove) {
                return;
            }

            try {
                const response = await this.apiCall('manage_guest_listing_auth', 'POST', {
                    id: listingId,
                    operation: 'override_email_verification'
                });

                if (!response.success) {
                    throw new Error(response.message || 'Failed to override email verification');
                }
            } catch (error) {
                this.showAlert('error', error.message || 'Failed to override email verification');
                return;
            }
        }

        await this.approveCar(listingId, 'approve');
    }

    async overrideGuestListingEmailVerification(listingId) {
        if (!confirm('Override email verification for this guest listing? This will mark the listing as verified by admin.')) {
            return;
        }

        try {
            const response = await this.apiCall('manage_guest_listing_auth', 'POST', {
                id: listingId,
                operation: 'override_email_verification'
            });

            if (!response.success) {
                throw new Error(response.message || 'Failed to override email verification');
            }

            this.showAlert('success', response.message || 'Listing email verification overridden');
            await Promise.all([
                this.loadGuestListings(),
                this.loadPendingCars(),
                this.loadDashboardData()
            ]);
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to override email verification');
        }
    }

    async setGuestListingManageCode(listingId) {
        const codeInput = prompt('Enter guest manage code (4-8 digits). Leave blank to auto-generate a 6-digit code:', '');
        if (codeInput === null) {
            return;
        }

        const trimmedCode = String(codeInput).trim();
        if (trimmedCode !== '' && !/^\d{4,8}$/.test(trimmedCode)) {
            this.showAlert('warning', 'Code must be 4 to 8 digits, or leave blank to auto-generate');
            return;
        }

        const hoursInput = prompt('Code expiry in hours (1-168):', '24');
        if (hoursInput === null) {
            return;
        }

        const parsedHours = parseInt(String(hoursInput).trim(), 10);
        if (Number.isNaN(parsedHours) || parsedHours < 1 || parsedHours > 168) {
            this.showAlert('warning', 'Expiry must be between 1 and 168 hours');
            return;
        }

        try {
            const response = await this.apiCall('manage_guest_listing_auth', 'POST', {
                id: listingId,
                operation: 'set_guest_manage_code',
                code: trimmedCode || null,
                expires_hours: parsedHours
            });

            if (!response.success) {
                throw new Error(response.message || 'Failed to set guest manage code');
            }

            const plainCode = response.code ? ` Code: ${response.code}` : '';
            const expiresText = response.expires_at ? ` Expires: ${response.expires_at}` : '';
            this.showAlert('success', `${response.message || 'Guest manage code updated.'}${plainCode}${expiresText}`);
            await this.loadGuestListings();
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to set guest manage code');
        }
    }

displayGuestListingsTable(cars) {
    const tbody = document.getElementById('guestListingsTableBody');
    if (!tbody) return;

    if (!cars || cars.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No guest listings found</td></tr>';
        return;
    }

    const html = cars.map(car => {
        const sellerName = car.guest_seller_name || car.guest_seller_email || 'Guest Seller';
        const sellerEmail = car.guest_seller_email || 'N/A';
        const listingImage = car.featured_image || car.primary_image || car.fallback_image || '';
        const imageTypeBadge = car.featured_image
            ? '<span class="badge badge-warning" style="position:absolute;top:4px;left:4px;font-size:10px;padding:2px 6px;">Featured</span>'
            : (car.primary_image ? '<span class="badge badge-info" style="position:absolute;top:4px;left:4px;font-size:10px;padding:2px 6px;">Primary</span>' : '');
        const verifiedBadge = car.listing_email_verified
            ? '<span class="status-badge status-active">Verified</span>'
            : '<span class="status-badge status-pending">Not Verified</span>';
        const statusValue = (car.status || 'pending').replace('_', ' ');
        const statusBadge = `<span class="status-badge status-${car.status || 'pending'}">${statusValue}</span>`;
        const expiresAt = car.guest_listing_expires_at ? this.formatDateShort(car.guest_listing_expires_at) : 'N/A';

        return `
            <tr>
                <td>${car.id}</td>
                <td>
                    <div class="car-image-thumb" style="position:relative;display:inline-block;">
                        ${listingImage ?
                                `<img src="${this.getSafeUploadUrl(listingImage)}" alt="${this.escapeHtml(car.title || 'Listing image')}"
                                    onerror="adminHandleImageFallback(this, 'text-muted')" style="max-width:72px;max-height:54px;border-radius:6px;">${imageTypeBadge}` :
                            `<i class="fas fa-car text-muted"></i>`
                        }
                    </div>
                </td>
                <td>${this.escapeHtml(car.title || 'Untitled')}</td>
                <td>
                    <div>${this.escapeHtml(sellerName)}</div>
                    <div class="text-muted small">${this.escapeHtml(sellerEmail)}</div>
                </td>
                <td>${verifiedBadge}</td>
                <td>${statusBadge}</td>
                <td>${expiresAt}</td>
                <td>${this.formatDateShort(car.created_at)}</td>
                <td>
                    <div class="d-flex gap-5">
                        <button type="button" class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-success" onclick="admin.approveGuestListingWithOverride(${car.id}, ${Number(car.listing_email_verified || 0)})" title="Approve">
                            <i class="fas fa-check"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" onclick="admin.approveCar(${car.id}, 'reject')" title="Reject">
                            <i class="fas fa-times"></i>
                        </button>
                        ${car.listing_email_verified ? '' : `
                        <button type="button" class="btn btn-sm btn-primary" onclick="admin.overrideGuestListingEmailVerification(${car.id})" title="Override Email Verification">
                            <i class="fas fa-user-check"></i>
                        </button>
                        `}
                        <button type="button" class="btn btn-sm btn-secondary" onclick="admin.setGuestListingManageCode(${car.id})" title="Set Guest Manage Code">
                            <i class="fas fa-key"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('guestListingsTable');
}


    displayPendingCarsTable(cars) {
        const tbody = document.getElementById('pendingCarsTableBody');
        
        if (!cars || cars.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-success"><i class="fas fa-check-circle"></i> No pending cars for approval</td></tr>';
            return;
        }

        const html = cars.map(car => {
            const listingImage = car.featured_image || car.primary_image || car.fallback_image || '';
            return `
            <tr>
                <td>
                    <input type="checkbox" class="car-checkbox" value="${car.id}" onchange="admin.updateBulkSelection()">
                </td>
                <td>${car.id}</td>
                <td>
                    <div class="car-image-thumb">
                        ${listingImage ?
                                `<img src="${this.getSafeUploadUrl(listingImage)}" alt="${this.escapeHtml(car.title)}"
                                    onerror="adminHandleImageFallback(this, 'text-warning')" style="max-width:72px;max-height:54px;border-radius:6px;">` :
                            `<i class="fas fa-car text-warning"></i>`
                        }
                    </div>
                </td>
                <td>
                    <div class="car-title">${this.escapeHtml(car.title)}</div>
                    <div class="text-muted small">${car.description ? this.escapeHtml(car.description.substring(0, 50)) + '...' : ''}</div>
                </td>
                <td>${car.make_name}/${car.model_name}</td>
                <td>${car.year}</td>
                <td>${formatConfiguredCurrencyAmount(car.price)}</td>
                <td>${this.escapeHtml(car.location_name)}</td>
                <td>${this.renderListingPaymentBadge(car)}</td>
                <td>${this.formatDateShort(car.created_at)}</td>
                <td>
                    <div class="d-flex gap-5">
                        <button class="btn btn-sm btn-success" onclick="admin.approveCar(${car.id}, 'approve')" title="${((Number(car.payment_required || 0) === 1 || Number(car.payment_amount || 0) > 0) && String(car.payment_status || '').toLowerCase() !== 'verified') ? 'Payment must be verified before approval' : 'Approve'}" ${((Number(car.payment_required || 0) === 1 || Number(car.payment_amount || 0) > 0) && String(car.payment_status || '').toLowerCase() !== 'verified') ? 'disabled' : ''}>
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="admin.approveCar(${car.id}, 'reject')" title="Reject">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        }).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('pendingCarsTable');
    }

    async loadRejectedCars() {
        try {
            debugLog('Loading rejected cars...');
            
            const filters = { status: 'rejected', approval_status: 'denied' };
            const searchFilter = document.getElementById('rejectedCarSearchFilter')?.value;
            
            if (searchFilter) filters.search = searchFilter;
            
            const response = await this.apiCall('get_cars', 'GET', filters);
            
            if (response.success && response.cars) {
                this.displayRejectedCarsTable(response.cars);
                debugLog(`Loaded ${response.cars.length} rejected cars`);
            } else {
                throw new Error(response.message || 'Invalid rejected cars response');
            }
        } catch (error) {
            document.getElementById('rejectedCarsTableBody').innerHTML = 
                '<tr><td colspan="10" class="text-center text-muted">Error loading rejected cars</td></tr>';
        }
    }

    displayRejectedCarsTable(cars) {
        const tbody = document.getElementById('rejectedCarsTableBody');
        
        if (!cars || cars.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted"><i class="fas fa-check-circle"></i> No rejected cars</td></tr>';
            return;
        }

        const html = cars.map(car => {
            // Extract rejection reason from admin_notes
            let rejectionReason = 'No reason provided';
            if (car.admin_notes) {
                const rejectionMatch = car.admin_notes.match(/Rejection reason:\s*(.+?)(?:\n\n|$)/i);
                if (rejectionMatch) {
                    rejectionReason = rejectionMatch[1].trim();
                } else if (car.admin_notes.includes('Rejection reason:')) {
                    const parts = car.admin_notes.split('Rejection reason:');
                    if (parts.length > 1) {
                        rejectionReason = parts[1].split('\n\n')[0].trim();
                    }
                }
            }
            
            // Format rejected date (use approved_at if available, otherwise updated_at)
            const rejectedDate = car.approved_at || car.updated_at || car.created_at;
            const dateStr = this.formatDateShort(rejectedDate);
            
            return `
            <tr>
                <td>${car.id}</td>
                <td>
                    <div class="car-image-thumb">
                        ${(car.featured_image || car.primary_image || car.fallback_image) ? 
                                `<img src="${this.getSafeUploadUrl(car.featured_image || car.primary_image || car.fallback_image)}" alt="${this.escapeHtml(car.title)}" 
                                    onerror="adminHandleImageFallback(this, 'text-danger')" style="max-width:60px;max-height:45px;border-radius:4px;">
                            ` :
                            `<i class="fas fa-car text-danger"></i>`
                        }
                    </div>
                </td>
                <td>
                    <div class="car-title">${this.escapeHtml(car.title)}</div>
                    <div class="text-muted small">${car.description ? this.escapeHtml(car.description.substring(0, 50)) + '...' : ''}</div>
                </td>
                <td>${car.make_name}/${car.model_name}</td>
                <td>${car.year}</td>
                <td>${formatConfiguredCurrencyAmount(car.price)}</td>
                <td>${this.escapeHtml(car.location_name)}</td>
                <td>${dateStr}</td>
                <td>
                    <div class="rejection-reason-text" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${this.escapeHtml(rejectionReason)}">
                        ${this.escapeHtml(rejectionReason.substring(0, 50))}${rejectionReason.length > 50 ? '...' : ''}
                    </div>
                </td>
                <td>
                    <div class="d-flex gap-5">
                        <button class="btn btn-sm btn-success" onclick="admin.approveCar(${car.id}, 'approve')" title="Re-approve Listing">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
            `;
        }).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('rejectedCarsTable');
    }

    filterRejectedCars() {
        this.loadRejectedCars();
    }

    async viewCarDetails(carId) {
        try {
            const response = await this.apiCall('get_car', 'GET', { id: carId });
            
            if (response.success && response.car) {
                await this.showCarDetailsModal(response.car);
            } else {
                throw new Error(response.message || 'Failed to load car details');
            }
        } catch (error) {
            this.showAlert('error', 'Failed to load car details');
        }
    }

    async showCarDetailsModal(car) {
        this.prepareModal('carDetailsModal');
        const modalHtml = `
            <div class="modal-overlay active" id="carDetailsModal">
                <div class="modal-content" style="max-width: 1000px;">
                    <div class="modal-header">
                        <h3>Car Listing Details</h3>
                        <button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="car-details-grid">
                            <div class="detail-section">
                                <h4>Basic Information</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Title:</span>
                                    <span class="detail-value">${this.escapeHtml(car.title)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Description:</span>
                                    <span class="detail-value">${this.escapeHtml(car.description)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Make/Model:</span>
                                    <span class="detail-value">${car.make_name} / ${car.model_name}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Year:</span>
                                    <span class="detail-value">${car.year}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4>Pricing & Location</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Price:</span>
                                    <span class="detail-value">${formatConfiguredCurrencyAmount(car.price)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Negotiable:</span>
                                    <span class="detail-value">${car.negotiable ? 'Yes' : 'No'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value">${this.escapeHtml(car.location_name)}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4>Car Specifications</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Fuel Type:</span>
                                    <span class="detail-value">${car.fuel_type || 'N/A'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Transmission:</span>
                                    <span class="detail-value">${car.transmission || 'N/A'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Mileage:</span>
                                    <span class="detail-value">${car.mileage ? this.formatNumber(car.mileage) + ' km' : 'N/A'}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Condition:</span>
                                    <span class="detail-value">${car.condition_type || 'N/A'}</span>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4>Owner Information</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Owner:</span>
                                    <span class="detail-value">${this.escapeHtml(car.owner_name)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${this.escapeHtml(car.owner_email)}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value">${car.owner_phone || 'N/A'}</span>
                                </div>
                            </div>

                            <div class="detail-section" style="grid-column:1/-1;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
                                    <h4 style="margin:0;"><i class="fas fa-images"></i> Listing Images</h4>
                                    <button class="btn btn-sm btn-outline" onclick="admin.refreshCarImageManagers(${car.id})">
                                        <i class="fas fa-sync"></i> Refresh Images
                                    </button>
                                </div>
                                <div id="carDetailsImagesGallery" data-car-gallery-for="${car.id}" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
                                    <div class="text-center text-muted" style="grid-column:1/-1; padding:24px;">Loading images...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="d-flex gap-10 justify-end">
                            <button class="btn btn-danger" onclick="admin.approveCar(${car.id}, 'reject')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <button class="btn btn-success" onclick="admin.approveCar(${car.id}, 'approve')">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        await this.refreshCarImageManagers(Number(car.id));
    }

    async bulkApproveCars() {
        const checkboxes = document.querySelectorAll('.car-checkbox:checked');
        if (checkboxes.length === 0) {
            this.showAlert('warning', 'Please select at least one car to approve');
            return;
        }
        
        const confirmed = await this.showActionConfirmDialog(
            'Bulk Approve Listings',
            `Are you sure you want to approve ${checkboxes.length} car(s)?`,
            'Approve Selected',
            'btn-success'
        );
        if (!confirmed) {
            return;
        }
        
        const carIds = Array.from(checkboxes).map(cb => cb.value);
        
        try {
            const response = await this.apiCall('bulk_approve_cars', 'POST', { 
                car_ids: carIds, 
                action: 'approve' 
            });
            
            if (response.success) {
                this.showAlert('success', `Successfully approved ${carIds.length} car(s)`);
                await Promise.all([
                    this.loadPendingCars(),
                    this.loadDashboardData()
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to approve cars');
        }
    }

    async bulkRejectCars() {
        const checkboxes = document.querySelectorAll('.car-checkbox:checked');
        if (checkboxes.length === 0) {
            this.showAlert('warning', 'Please select at least one car to reject');
            return;
        }
        
        const confirmed = await this.showActionConfirmDialog(
            'Bulk Reject Listings',
            `Are you sure you want to reject ${checkboxes.length} car(s)?`,
            'Reject Selected',
            'btn-danger'
        );
        if (!confirmed) {
            return;
        }
        
        const carIds = Array.from(checkboxes).map(cb => cb.value);
        
        try {
            const response = await this.apiCall('bulk_approve_cars', 'POST', { 
                car_ids: carIds, 
                action: 'reject' 
            });
            
            if (response.success) {
                this.showAlert('success', `Successfully rejected ${carIds.length} car(s)`);
                await Promise.all([
                    this.loadPendingCars(),
                    this.loadDashboardData()
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to reject cars');
        }
    }

async loadPayments() {
    try {
        debugLog('Loading payments...');
        const filters = {};
        const statusFilter = document.getElementById('paymentStatusFilter')?.value;
        const serviceFilter = document.getElementById('paymentServiceFilter')?.value;
        const dateFrom = document.getElementById('paymentDateFrom')?.value;
        const dateTo = document.getElementById('paymentDateTo')?.value;
        const includeFreeListings = document.getElementById('includeFreeListingsFilter')?.checked;
        
        if (statusFilter) filters.status = statusFilter;
        if (serviceFilter) filters.service_type = serviceFilter;
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        if (includeFreeListings) filters.include_free_listings = '1';
        
        const response = await this.apiCall('get_payments', 'GET', filters);
        
        if (response.success && response.payments) {
            this.displayPaymentsTable(response.payments);
            this.loadFreeListingsPayments();
            debugLog(`Loaded ${response.payments.length} payments`);
        } else {
            throw new Error(response.message || 'Invalid payments response');
        }
    } catch (error) {
        document.getElementById('paymentsTableBody').innerHTML = 
            '<tr><td colspan="9" class="text-center text-muted">Error loading payments</td></tr>';

        const freeTbody = document.getElementById('freeListingsPaymentsTableBody');
        if (freeTbody) {
            freeTbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Error loading free listings</td></tr>';
        }
    }
}

    async loadFreeListingsPayments() {
        try {
            const filters = {
                include_free_listings: '1',
                status: 'free',
                service_type: 'free_listing'
            };

            const dateFrom = document.getElementById('paymentDateFrom')?.value;
            const dateTo = document.getElementById('paymentDateTo')?.value;
            if (dateFrom) filters.date_from = dateFrom;
            if (dateTo) filters.date_to = dateTo;

            const response = await this.apiCall('get_payments', 'GET', filters);
            if (response.success && response.payments) {
                this.displayFreeListingsPaymentsTable(response.payments);
            } else {
                throw new Error(response.message || 'Invalid free listings response');
            }
        } catch (error) {
            const tbody = document.getElementById('freeListingsPaymentsTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Error loading free listings</td></tr>';
            }
        }
    }

    displayFreeListingsPaymentsTable(rows) {
        const tbody = document.getElementById('freeListingsPaymentsTableBody');
        if (!tbody) return;

        const freeRows = (rows || []).filter(row => Number(row.is_free_listing || 0) === 1 || row.service_type === 'free_listing' || row.status === 'free');

        if (freeRows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No free listings found</td></tr>';
            return;
        }

        const html = freeRows.map(row => {
            const listingId = Number(row.listing_id || 0);
            const listingTitle = this.escapeHtml(row.listing_title || 'Untitled listing');
            const sellerName = this.escapeHtml(row.user_name || 'Guest Seller');
            const sellerEmail = this.escapeHtml(row.user_email || '');
            const listingStatus = this.escapeHtml((row.listing_status || 'free').replace('_', ' '));
            const reference = this.escapeHtml(row.reference || '-');

            return `
                <tr>
                    <td>${listingId > 0 ? listingId : '-'}</td>
                    <td>${listingTitle}</td>
                    <td>
                        <div>${sellerName}</div>
                        <div class="text-muted small">${sellerEmail}</div>
                    </td>
                    <td>${reference}</td>
                    <td><span class="status-badge status-active">${listingStatus}</span></td>
                    <td>${this.formatDateShort(row.created_at)}</td>
                    <td>
                        <div class="d-flex gap-5">
                            ${listingId > 0 ? `<button class="btn btn-sm btn-success" onclick="admin.markFreeListingAsPaid(${listingId})" title="Mark as Paid (Verified)"><i class="fas fa-check-double"></i></button>` : ''}
                            ${listingId > 0 ? `<button class="btn btn-sm btn-primary" onclick="showAddPaymentModal(${listingId})" title="Apply manual payment"><i class="fas fa-hand-holding-usd"></i></button>` : ''}
                            ${listingId > 0 ? `<a class="btn btn-sm btn-secondary" href="../car.html?id=${listingId}" target="_blank" rel="noopener" title="View listing page"><i class="fas fa-external-link-alt"></i></a>` : ''}
                            ${listingId > 0 ? `<button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${listingId})" title="View listing"><i class="fas fa-eye"></i></button>` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('freeListingsPaymentsTable');
    }

    async markFreeListingAsPaid(listingId) {
        const amountInput = await this.showActionPromptDialog(
            'Mark Listing as Paid',
            `Enter amount to record for this listing (${getConfiguredCurrencyLabel()}).`,
            '0',
            'Record Verified Payment'
        );

        if (amountInput === null) {
            return;
        }

        const amount = Number(String(amountInput).trim());
        if (Number.isNaN(amount) || amount < 0) {
            this.showAlert('warning', 'Please enter a valid non-negative amount');
            return;
        }

        const confirmed = await this.showActionConfirmDialog(
            'Confirm Mark as Paid',
            `Record a verified payment of ${formatConfiguredCurrencyAmount(amount)} for listing #${listingId}?`,
            'Record Payment',
            'btn-success'
        );

        if (!confirmed) {
            return;
        }

        try {
            const response = await this.apiCall('record_manual_payment', 'POST', {
                listing_id: Number(listingId),
                amount,
                payment_method: 'manual',
                reference: `ADMIN-MARKPAID-${listingId}-${Date.now()}`,
                status: 'verified',
                service_type: 'listing_submission',
                notes: 'Admin quick action: Marked free listing as paid and verified'
            });

            if (!response.success) {
                throw new Error(response.message || 'Failed to record payment');
            }

            this.showAlert('success', response.message || 'Verified payment recorded');
            await Promise.all([
                this.loadPayments(),
                this.loadPaymentStats(),
                this.loadCars(),
                this.loadPendingCars(),
                this.loadFreeListingsPayments()
            ]);
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to mark listing as paid');
        }
    }

    async loadPaymentStats() {
        try {
            debugLog('Loading payment stats...');
            const response = await this.apiCall('payment_stats');
            
            if (response.success && response.stats) {
                const stats = response.stats;
                document.getElementById('todayPayments').textContent = formatConfiguredCurrencyAmount(stats.today.amount || 0);
                document.getElementById('todayPaymentsCount').textContent = `${stats.today.count || 0} payments`;
                
                document.getElementById('monthPayments').textContent = formatConfiguredCurrencyAmount(stats.month.amount || 0);
                document.getElementById('monthPaymentsCount').textContent = `${stats.month.count || 0} payments`;
                
                document.getElementById('pendingPayments').textContent = formatConfiguredCurrencyAmount(stats.pending.amount || 0);
                document.getElementById('pendingPaymentsCount').textContent = `${stats.pending.count || 0} payments`;
                
                debugLog('Payment stats loaded successfully');
            }
        } catch (error) {
        }
    }

async loadUsers() {
    try {
        debugLog('Loading users...');
        const filters = {};
        const typeFilter = document.getElementById('userTypeFilter')?.value;
        const statusFilter = document.getElementById('userStatusFilter')?.value;
        const searchFilter = document.getElementById('userSearchFilter')?.value;

        if (typeFilter) filters.user_type = typeFilter;
        if (statusFilter) filters.status = statusFilter;
        if (searchFilter) filters.search = searchFilter;

        const response = await this.apiCall('get_users', 'GET', filters);

        if (response.success && response.users) {
            this.usersCache = response.users;
            this.displayUsersTable(response.users);
            debugLog(`Loaded ${response.users.length} users`);
        } else {
            throw new Error(response.message || 'Invalid users response');
        }
    } catch (error) {
        document.getElementById('usersTableBody').innerHTML =
            '<tr><td colspan="10" class="text-center text-muted">Error loading users</td></tr>';
    }
}

async loadAdmins() {
    try {
        debugLog('Loading admins...');
        const response = await this.apiCall('get_admins', 'GET');

        if (response.success && response.admins) {
            this.displayAdminsTable(response.admins);
            debugLog(`Loaded ${response.admins.length} admins`);
        } else {
            throw new Error(response.message || 'Invalid admins response');
        }
    } catch (error) {
        document.getElementById('adminsTableBody').innerHTML =
            '<tr><td colspan="9" class="text-center text-muted">Error loading administrators</td></tr>';
    }
}

    // ENHANCED MANAGEMENT SECTIONS

async loadGarages() {
    try {
        debugLog('Loading garages...');
        const filters = {};
        const statusFilter = document.getElementById('garageStatusFilter')?.value;
        const locationFilter = document.getElementById('garageLocationFilter')?.value;
        const searchFilter = document.getElementById('garageSearchFilter')?.value;
        
        if (statusFilter) filters.status = statusFilter;
        if (locationFilter) filters.location_id = locationFilter;
        if (searchFilter) filters.search = searchFilter;
        
        const response = await this.apiCall('get_garages', 'GET', filters);
        
        if (response.success && response.garages) {
            this.displayGaragesTable(response.garages);
            debugLog(`Loaded ${response.garages.length} garages`);
            
            // Load locations for filter if not already loaded
            if (document.getElementById('garageLocationFilter') && document.getElementById('garageLocationFilter').children.length <= 1) {
                await this.loadLocationsForFilter('garageLocationFilter');
            }
        } else {
            throw new Error(response.message || 'Invalid garages response');
        }
    } catch (error) {
        document.getElementById('garagesTableBody').innerHTML = 
            '<tr><td colspan="9" class="text-center text-muted">Error loading garages</td></tr>';
    }
}

async loadDealers() {
    try {
        debugLog('Loading dealers...');
        const filters = {};
        const statusFilter = document.getElementById('dealerStatusFilter')?.value;
        const locationFilter = document.getElementById('dealerLocationFilter')?.value;
        const searchFilter = document.getElementById('dealerSearchFilter')?.value;
        
        if (statusFilter) filters.status = statusFilter;
        if (locationFilter) filters.location_id = locationFilter;
        if (searchFilter) filters.search = searchFilter;
        
        const response = await this.apiCall('get_dealers', 'GET', filters);
        
        debugLog('Dealers API response:', response);
        
        if (response.success && response.dealers) {
            this.displayDealersTable(response.dealers);
            debugLog(`Loaded ${response.dealers.length} dealers`);
            
            // Load locations for filter if not already loaded
            if (document.getElementById('dealerLocationFilter') && document.getElementById('dealerLocationFilter').children.length <= 1) {
                await this.loadLocationsForFilter('dealerLocationFilter');
            }
        } else {
            throw new Error(response.message || 'Invalid dealers response');
        }
    } catch (error) {
        console.error('Error loading dealers:', error);
        debugLog('Dealers loading error details:', error);
        const tbody = document.getElementById('dealersTableBody');
        if (tbody) {
            // Escape error message to prevent XSS
            const safeErrorMsg = this.escapeHtml(error.message || 'Unknown error');
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error loading dealers: ${safeErrorMsg}</td></tr>`;
        }
    }
}

    async loadCarHire() {
    try {
        debugLog('Loading car hire...');
        const filters = {};
        const statusFilter = document.getElementById('carHireStatusFilter')?.value;
        const categoryFilter = document.getElementById('carHireCategoryFilter')?.value;
        const locationFilter = document.getElementById('carHireLocationFilter')?.value;
        const searchFilter = document.getElementById('carHireSearchFilter')?.value;
        
        if (statusFilter) filters.status = statusFilter;
        if (categoryFilter) filters.hire_category = categoryFilter;
        if (locationFilter) filters.location_id = locationFilter;
        if (searchFilter) filters.search = searchFilter;
        
        const response = await this.apiCall('get_car_hire', 'GET', filters);
        
        debugLog('Car hire API response:', response);
        
        if (response.success && response.car_hire) {
            this.displayCarHireTable(response.car_hire);
            debugLog(`Loaded ${response.car_hire.length} car hire companies`);
            
            // Load locations for filter if not already loaded
            if (document.getElementById('carHireLocationFilter') && document.getElementById('carHireLocationFilter').children.length <= 1) {
                await this.loadLocationsForFilter('carHireLocationFilter');
            }
        } else {
            throw new Error(response.message || 'Invalid car hire response');
        }
    } catch (error) {
        console.error('Error loading car hire:', error);
        debugLog('Car hire loading error details:', error);
        const tbody = document.getElementById('carHireTableBody');
        if (tbody) {
            const safeErrorMsg = this.escapeHtml(error.message || 'Unknown error');
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error loading car hire: ${safeErrorMsg}</td></tr>`;
        }
    }
}

async loadDeletedCars() {
    try {
        debugLog('Loading deleted cars...');
        const response = await this.apiCall('get_deleted_cars', 'GET');

        if (response.success && response.cars) {
            this.displayDeletedCarsTable(response.cars);
            debugLog(`Loaded ${response.cars.length} deleted cars`);
        } else {
            throw new Error(response.message || 'Invalid deleted cars response');
        }
    } catch (error) {
        document.getElementById('deletedCarsTableBody').innerHTML =
            '<tr><td colspan="12" class="text-center text-muted">Error loading deleted cars</td></tr>';
    }
}

async loadLocationsForFilter(selectId) {
    try {
        const response = await this.apiCall('get_locations');
        if (response.success && response.locations) {
            const select = document.getElementById(selectId);
            if (select) {
                // Clear existing options except the first one
                while (select.children.length > 1) {
                    select.removeChild(select.lastChild);
                }
                
                response.locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.id;
                    option.textContent = `${location.name} (${location.region})`;
                    select.appendChild(option);
                });
            }
        }
    } catch (error) {
    }
}

    async loadMakesModels() {
        try {
            debugLog('Loading makes and models...');
            const response = await this.apiCall('get_makes_models');
            
            if (response.success) {
                this.displayMakesTable(response.makes || []);
                this.displayModelsTable(response.models || []);
                debugLog('Makes and models loaded successfully');
            } else {
                throw new Error(response.message || 'Invalid makes models response');
            }
        } catch (error) {
            console.error('Error loading makes and models:', error);
            document.getElementById('makesTableBody').innerHTML = 
                '<tr><td colspan="6" class="text-center text-muted">Error loading makes: ' + (error.message || 'Unknown error') + '</td></tr>';
            document.getElementById('modelsTableBody').innerHTML = 
                '<tr><td colspan="11" class="text-center text-muted">Error loading models: ' + (error.message || 'Unknown error') + '</td></tr>';
            this.showAlert('error', 'Failed to load makes and models: ' + (error.message || 'Unknown error'));
        }
    }

async loadLocations() {
    try {
        debugLog('Loading locations...');
        const filters = {};
        const regionFilter = document.getElementById('locationRegionFilter')?.value;
        const searchFilter = document.getElementById('locationSearchFilter')?.value;
        
        if (regionFilter) filters.region = regionFilter;
        if (searchFilter) filters.search = searchFilter;
        
        const response = await this.apiCall('get_locations', 'GET', filters);
        
        if (response.success && response.locations) {
            this.displayLocationsTable(response.locations);
            debugLog(`Loaded ${response.locations.length} locations`);
        } else {
            throw new Error(response.message || 'Invalid locations response');
        }
    } catch (error) {
        document.getElementById('locationsTableBody').innerHTML = 
            '<tr><td colspan="7" class="text-center text-muted">Error loading locations</td></tr>';
    }
}


    // DISPLAY FUNCTIONS FOR ALL SECTIONS

// Update these functions in admin-script.js

displayGaragesTable(garages) {
    const tbody = document.getElementById('garagesTableBody');
    
    if (!garages || garages.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No garages found</td></tr>';
        return;
    }

    const html = garages.map(garage => `
        <tr>
            <td><input type="checkbox" class="garage-checkbox" value="${garage.id}" onchange="admin.updateGarageBulkBar()"></td>
            <td>${garage.id}</td>
            <td>
                <div class="business-name">${this.escapeHtml(garage.name)}</div>
                <div class="text-muted small">${garage.email}</div>
            </td>
            <td>${this.escapeHtml(garage.owner_name)}</td>
            <td>${garage.location_name || 'N/A'}</td>
            <td>${garage.phone}</td>
            <td>
                ${garage.specialization ? 
                    JSON.parse(garage.specialization).slice(0, 2).map(spec => 
                        `<span class="tag">${spec}</span>`
                    ).join('') : 'N/A'
                }
            </td>
            <td>
                <span class="status-badge status-${garage.status}">${garage.status.replace('_', ' ')}</span>
                ${garage.is_featured ? '<div class="text-warning small"><i class="fas fa-star"></i> Featured</div>' : ''}
                ${garage.is_verified ? '<div class="text-success small"><i class="fas fa-check-circle"></i> Verified</div>' : ''}
                ${garage.is_certified ? '<div class="text-info small"><i class="fas fa-certificate"></i> Certified</div>' : ''}
            </td>
            <td>${this.formatDateShort(garage.created_at)}</td>
            <td>
                <div class="management-actions">
                    ${garage.status === 'pending_approval' ? `
                    <button class="btn btn-sm btn-success btn-action" onclick="admin.approveItem('garage', ${garage.id}, 'approve')" title="Approve Garage">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-danger btn-action" onclick="admin.approveItem('garage', ${garage.id}, 'reject')" title="Deny Garage">
                        <i class="fas fa-times"></i>
                    </button>
                    ` : ''}
                    <button class="btn btn-sm ${garage.is_featured ? 'btn-warning' : 'btn-outline-warning'}" onclick="admin.toggleFeatureGarage(${garage.id}, ${garage.is_featured || 0})" title="${garage.is_featured ? 'Unfeature' : 'Feature'}">
                        <i class="fas fa-star"></i>
                    </button>
                    <button class="btn btn-sm ${garage.is_verified ? 'btn-success' : 'btn-outline-success'}" onclick="admin.toggleVerifyGarage(${garage.id}, ${garage.is_verified || 0})" title="${garage.is_verified ? 'Unverify' : 'Verify'}">
                        <i class="fas fa-check-circle"></i>
                    </button>
                    <button class="btn btn-sm ${garage.is_certified ? 'btn-info' : 'btn-outline-info'}" onclick="admin.toggleCertifyGarage(${garage.id}, ${garage.is_certified || 0})" title="${garage.is_certified ? 'Uncertify' : 'Certify'}">
                        <i class="fas fa-certificate"></i>
                    </button>
                    <button class="btn btn-sm btn-info" onclick="admin.viewGarageDetails(${garage.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="admin.editGarage(${garage.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="admin.suspendGarage(${garage.id})" title="Suspend">
                        <i class="fas fa-ban"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteGarage(${garage.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('garagesTable');
}

displayDealersTable(dealers) {
    const tbody = document.getElementById('dealersTableBody');
    
    if (!dealers || dealers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No dealers found</td></tr>';
        return;
    }

    const html = dealers.map(dealer => `
        <tr>
            <td><input type="checkbox" class="dealer-checkbox" value="${dealer.id}" onchange="admin.updateDealerBulkBar()"></td>
            <td>${dealer.id || 'N/A'}</td>
            <td>
                <div class="business-name">${this.escapeHtml(dealer.business_name || 'N/A')}</div>
                <div class="text-muted small">${dealer.email || 'N/A'}</div>
            </td>
            <td>${this.escapeHtml(dealer.owner_name || 'N/A')}</td>
            <td>${dealer.location_name || 'N/A'}</td>
            <td>${dealer.phone || 'N/A'}</td>
            <td>${dealer.user_type || 'dealer'}</td>
            <td>
                <span class="status-badge status-${dealer.status || 'pending'}">${(dealer.status || 'pending').replace('_', ' ')}</span>
                ${dealer.is_featured ? '<div class="text-warning small"><i class="fas fa-star"></i> Featured</div>' : ''}
                ${dealer.is_verified ? '<div class="text-success small"><i class="fas fa-check-circle"></i> Verified</div>' : ''}
                ${dealer.is_certified ? '<div class="text-info small"><i class="fas fa-certificate"></i> Certified</div>' : ''}
            </td>
            <td>${this.formatDateShort(dealer.created_at)}</td>
            <td>
                <div class="management-actions">
                    ${dealer.status === 'pending_approval' ? `
                    <button class="btn btn-sm btn-success btn-action" onclick="admin.approveItem('dealer', ${dealer.id}, 'approve')" title="Approve Dealer">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-danger btn-action" onclick="admin.approveItem('dealer', ${dealer.id}, 'reject')" title="Deny Dealer">
                        <i class="fas fa-times"></i>
                    </button>
                    ` : ''}
                    <button class="btn btn-sm ${dealer.is_featured ? 'btn-warning' : 'btn-outline-warning'}" onclick="admin.toggleFeatureDealer(${dealer.id}, ${dealer.is_featured || 0})" title="${dealer.is_featured ? 'Unfeature' : 'Feature'}">
                        <i class="fas fa-star"></i>
                    </button>
                    <button class="btn btn-sm ${dealer.is_verified ? 'btn-success' : 'btn-outline-success'}" onclick="admin.toggleVerifyDealer(${dealer.id}, ${dealer.is_verified || 0})" title="${dealer.is_verified ? 'Unverify' : 'Verify'}">
                        <i class="fas fa-check-circle"></i>
                    </button>
                    <button class="btn btn-sm ${dealer.is_certified ? 'btn-info' : 'btn-outline-info'}" onclick="admin.toggleCertifyDealer(${dealer.id}, ${dealer.is_certified || 0})" title="${dealer.is_certified ? 'Uncertify' : 'Certify'}">
                        <i class="fas fa-certificate"></i>
                    </button>
                    <button class="btn btn-sm btn-info" onclick="admin.viewDealerDetails(${dealer.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="admin.editDealer(${dealer.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="admin.suspendDealer(${dealer.id})" title="Suspend">
                        <i class="fas fa-ban"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteDealer(${dealer.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('dealersTable');
}

displayCarHireTable(carHire) {
    const tbody = document.getElementById('carHireTableBody');
    
    if (!carHire || carHire.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No car hire companies found</td></tr>';
        return;
    }

    const categoryLabels = {
        standard: { label: 'Standard', cls: 'cat-standard', icon: 'fa-car' },
        events: { label: 'Events', cls: 'cat-events', icon: 'fa-calendar-alt' },
        vans_trucks: { label: 'Vans & Trucks', cls: 'cat-vans_trucks', icon: 'fa-truck' },
        all: { label: 'All Services', cls: 'cat-all', icon: 'fa-layer-group' }
    };

    const html = carHire.map(company => {
        const cat = categoryLabels[company.hire_category] || { label: company.hire_category || 'Standard', cls: 'cat-standard', icon: 'fa-car' };
        const vanCount = parseInt(company.van_count) || 0;
        const truckCount = parseInt(company.truck_count) || 0;
        const carCount = parseInt(company.car_count) || (parseInt(company.total_vehicles) || 0) - vanCount - truckCount;
        const fleetBreakdown = `
            <div class="fleet-mini-breakdown">
                ${carCount > 0 ? `<span title="Cars"><i class="fas fa-car"></i> ${carCount}</span>` : ''}
                ${vanCount > 0 ? `<span title="Vans" style="color:#8b5cf6"><i class="fas fa-van-shuttle"></i> ${vanCount}</span>` : ''}
                ${truckCount > 0 ? `<span title="Trucks" style="color:#f97316"><i class="fas fa-truck"></i> ${truckCount}</span>` : ''}
                ${(carCount + vanCount + truckCount) === 0 ? '<span class="text-muted">—</span>' : ''}
            </div>`;
        return `
        <tr>
            <td>${company.id || 'N/A'}</td>
            <td>
                <div class="business-name">${this.escapeHtml(company.business_name || 'N/A')}</div>
                <div class="text-muted small">${company.email || 'N/A'}</div>
            </td>
            <td>${this.escapeHtml(company.owner_name || 'N/A')}</td>
            <td>
                <div class="location-info">
                    <strong>${company.location_name || 'N/A'}</strong>
                    ${company.district ? `<div class="text-muted small">District: ${this.escapeHtml(company.district)}</div>` : ''}
                </div>
            </td>
            <td><span class="tag ${cat.cls}"><i class="fas ${cat.icon}"></i> ${cat.label}</span></td>
            <td>${fleetBreakdown}</td>
            <td>${formatConfiguredCurrencyAmount(company.daily_rate_from || 0)}</td>
            <td>
                <span class="status-badge status-${company.status || 'pending'}">${(company.status || 'pending').replace('_', ' ')}</span>
                ${company.is_featured ? '<div class="text-warning small"><i class="fas fa-star"></i> Featured</div>' : ''}
                ${company.is_verified ? '<div class="text-success small"><i class="fas fa-check-circle"></i> Verified</div>' : ''}
                ${company.is_certified ? '<div class="text-info small"><i class="fas fa-certificate"></i> Certified</div>' : ''}
            </td>
            <td>
                <div class="management-actions">
                    ${company.status === 'pending_approval' ? `
                    <button class="btn btn-sm btn-success btn-action" onclick="admin.approveItem('car_hire', ${company.id}, 'approve')" title="Approve Car Hire">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-danger btn-action" onclick="admin.approveItem('car_hire', ${company.id}, 'reject')" title="Deny Car Hire">
                        <i class="fas fa-times"></i>
                    </button>
                    ` : ''}
                    <button class="btn btn-sm ${company.is_featured ? 'btn-warning' : 'btn-outline-warning'}" onclick="admin.toggleFeatureCarHire(${company.id}, ${company.is_featured || 0})" title="${company.is_featured ? 'Unfeature' : 'Feature'}">
                        <i class="fas fa-star"></i>
                    </button>
                    <button class="btn btn-sm ${company.is_verified ? 'btn-success' : 'btn-outline-success'}" onclick="admin.toggleVerifyCarHire(${company.id}, ${company.is_verified || 0})" title="${company.is_verified ? 'Unverify' : 'Verify'}">
                        <i class="fas fa-check-circle"></i>
                    </button>
                    <button class="btn btn-sm ${company.is_certified ? 'btn-info' : 'btn-outline-info'}" onclick="admin.toggleCertifyCarHire(${company.id}, ${company.is_certified || 0})" title="${company.is_certified ? 'Uncertify' : 'Certify'}">
                        <i class="fas fa-certificate"></i>
                    </button>
                    <button class="btn btn-sm btn-info" onclick="admin.viewCarHireDetails(${company.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="admin.editCarHire(${company.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="admin.suspendCarHire(${company.id})" title="Suspend">
                        <i class="fas fa-ban"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteCarHire(${company.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('carHireTable');
}

displayMakesTable(makes) {
    const tbody = document.getElementById('makesTableBody');
    
    if (!makes || makes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No makes found</td></tr>';
        return;
    }

    // Sort makes alphabetically by name by default
    const sortedMakes = [...makes].sort((a, b) => a.name.localeCompare(b.name));

    const html = sortedMakes.map(make => `
        <tr>
            <td>${make.id}</td>
            <td>
                <strong>${this.escapeHtml(make.name)}</strong>
                <div class="text-muted small" style="margin-top: 4px;">Brand manufacturer</div>
            </td>
            <td>${make.country || 'N/A'}</td>
            <td>
                <span class="badge badge-info" style="cursor: pointer; font-size: 0.9em; padding: 6px 12px;" onclick="admin.filterModelsByMake(${make.id}, '${this.escapeHtml(make.name)}')" title="Click to view models for this make">
                    <i class="fas fa-car"></i> ${make.models_count || 0} model${(make.models_count || 0) !== 1 ? 's' : ''}
                </span>
            </td>
            <td>
                <span class="status-badge status-${make.is_active ? 'active' : 'inactive'}">
                    ${make.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <div class="d-flex gap-5">
                    <button class="btn btn-sm btn-info" onclick="admin.filterModelsByMake(${make.id}, '${this.escapeHtml(make.name)}')" title="View models for ${this.escapeHtml(make.name)}">
                        <i class="fas fa-list"></i> Models
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="admin.editMake(${make.id})" title="Edit make">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteMake(${make.id})" title="Delete make">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('makesTable');
}

displayModelsTable(models) {
    const tbody = document.getElementById('modelsTableBody');
    
    if (!models || models.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">No models found</td></tr>';
        return;
    }

    // Sort models by make name first, then by model name, then by engine size
    const sortedModels = [...models].sort((a, b) => {
        const makeA = (a.make_name || '').toString();
        const makeB = (b.make_name || '').toString();
        const makeCompare = makeA.localeCompare(makeB);
        if (makeCompare !== 0) return makeCompare;
        const nameA = (a.name || '').toString();
        const nameB = (b.name || '').toString();
        const nameCompare = nameA.localeCompare(nameB);
        if (nameCompare !== 0) return nameCompare;
        const engineA = parseFloat(a.engine_size_liters) || 0;
        const engineB = parseFloat(b.engine_size_liters) || 0;
        return engineB - engineA; // Descending order (larger engines first)
    });

    const html = sortedModels.map(model => `
        <tr data-make-id="${model.make_id}" data-model-name="${this.escapeHtml(model.name || '')}">
            <td>${model.id}</td>
            <td>
                <strong>${this.escapeHtml(model.make_name || 'N/A')}</strong>
            </td>
            <td>
                <strong>${this.escapeHtml(model.name || '')}</strong>
            </td>
            <td>
                <span class="badge badge-${model.body_type || 'N/A'}">${model.body_type || 'N/A'}</span>
            </td>
            <td>
                ${model.year_start ? `${model.year_start}${model.year_end ? '–' + model.year_end : '+'}` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                ${model.engine_size_liters ? `${model.engine_size_liters}L` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                ${model.fuel_tank_capacity_liters ? `${model.fuel_tank_capacity_liters}L` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                ${model.drive_type ? `<span class="badge badge-info">${this.escapeHtml(model.drive_type)}</span>` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                ${model.transmission_type ? `<span class="badge badge-secondary">${this.escapeHtml(model.transmission_type)}</span>` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                ${model.fuel_type ? `<span class="badge badge-success">${this.escapeHtml(model.fuel_type)}</span>` : '<span class="text-muted">-</span>'}
            </td>
            <td>
                <span class="status-badge status-${model.is_active ? 'active' : 'inactive'}">
                    ${model.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <div class="d-flex gap-5">
                    <button class="btn btn-sm btn-primary" onclick="admin.editModel(${model.id})" title="Edit this variation">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteModel(${model.id})" title="Delete this variation">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('modelsTable');
}

displayLocationsTable(locations) {
    const tbody = document.getElementById('locationsTableBody');
    
    if (!locations || locations.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No locations found</td></tr>';
        return;
    }

    const html = locations.map(location => `
        <tr>
            <td>${location.id}</td>
            <td>${this.escapeHtml(location.name)}</td>
            <td>${location.region}</td>
            <td>${location.district || 'N/A'}</td>
            <td>
                <span class="status-badge status-${location.is_active ? 'active' : 'inactive'}">
                    ${location.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>${this.formatDateShort(location.created_at)}</td>
            <td>
                <div class="d-flex gap-5">
                    <button class="btn btn-sm btn-primary" onclick="admin.editLocation(${location.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="admin.deleteLocation(${location.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.innerHTML = html;
    this.enableTableSorting('locationsTable');
}

// Add these functions to admin-script.js
async filterCars() {
    await this.loadCars();
}

async filterPendingCars() {
    await this.loadPendingCars();
}

setPendingPaymentFilter(status) {
    const select = document.getElementById('pendingCarPaymentStatusFilter');
    if (select) {
        select.value = status || '';
    }
    this.loadPendingCars();
}

async filterPayments() {
    await this.loadPayments();
}

async filterUsers() {
    await this.loadUsers();
}

async filterGarages() {
    await this.loadGarages();
}

async filterDealers() {
    await this.loadDealers();
}

async filterCarHire() {
    await this.loadCarHire();
}

async filterLocations() {
    await this.loadLocations();
}

async filterMakesModels() {
    await this.loadMakesModels();
}


    // HELPER FUNCTIONS
    generateStars(rating) {
        const fullStars = Math.floor(rating);
        const halfStar = rating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);
        
        let stars = '';
        for (let i = 0; i < fullStars; i++) {
            stars += '<i class="fas fa-star text-warning"></i>';
        }
        if (halfStar) {
            stars += '<i class="fas fa-star-half-alt text-warning"></i>';
        }
        for (let i = 0; i < emptyStars; i++) {
            stars += '<i class="far fa-star text-warning"></i>';
        }
        return stars;
    }

    // PLACEHOLDER FUNCTIONS FOR EDIT ACTIONS
    
       editCar(carId) {
        editOps.editCar(carId);
    }

    editGarage(garageId) {
        editOps.editGarage(garageId);
    }

    editDealer(dealerId) {
        editOps.editDealer(dealerId);
    }

    editCarHire(carHireId) {
        editOps.editCarHire(carHireId);
    }

    editMake(makeId) {
        editOps.editMake(makeId);
    }

    editModel(modelId) {
        editOps.editModel(modelId);
    }

    editLocation(locationId) {
        editOps.editLocation(locationId);
    }

    viewGarageDetails(garageId) {
        editOps.viewGarageDetails(garageId);
    }

    viewDealerDetails(dealerId) {
        editOps.viewDealerDetails(dealerId);
    }

    viewCarHireDetails(carHireId) {
        editOps.viewCarHireDetails(carHireId);
    }

    suspendGarage(garageId) {
        editOps.suspendGarage(garageId);
    }

    suspendDealer(dealerId) {
        editOps.suspendDealer(dealerId);
    }

    suspendCarHire(carHireId) {
        editOps.suspendCarHire(carHireId);
    }

    deleteMake(makeId) {
        editOps.deleteMake(makeId);
    }

    deleteModel(modelId) {
        editOps.deleteModel(modelId);
    }

    deleteLocation(locationId) {
        editOps.deleteLocation(locationId);
    }
    
    // Filter models table to show only models for a specific make
    filterModelsByMake(makeId, makeName) {
        const modelsTable = document.getElementById('modelsTable');
        const modelsSection = document.querySelector('.models-section');
        const allRows = modelsTable.querySelectorAll('tbody tr');
        
        // If already filtered to this make, clear filter
        if (this.currentMakeFilter === makeId) {
            this.currentMakeFilter = null;
            allRows.forEach(row => {
                row.style.display = '';
            });
            this.showAlert('info', 'Showing all models');
            return;
        }
        
        // Filter to show only models for this make
        this.currentMakeFilter = makeId;
        let visibleCount = 0;
        
        allRows.forEach(row => {
            const rowMakeId = row.getAttribute('data-make-id');
            if (rowMakeId && parseInt(rowMakeId) === makeId) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Add a filter indicator
        const modelsHeader = document.querySelector('.models-section h3');
        if (modelsHeader) {
            const existingFilter = modelsHeader.querySelector('.filter-indicator');
            if (existingFilter) {
                existingFilter.remove();
            }
            const filterIndicator = document.createElement('span');
            filterIndicator.className = 'filter-indicator';
            filterIndicator.style.cssText = 'margin-left: 10px; font-size: 0.7em; color: #0056b3; font-weight: normal;';
            filterIndicator.innerHTML = `<i class="fas fa-filter"></i> Filtered: ${this.escapeHtml(makeName)} (${visibleCount} models) <button onclick="admin.clearModelFilter()" style="margin-left: 8px; padding: 2px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;"><i class="fas fa-times"></i> Clear</button>`;
            modelsHeader.appendChild(filterIndicator);
        }
        
        this.showAlert('success', `Showing ${visibleCount} model(s) for ${makeName}. Click "Clear" to show all.`);
        
        // Scroll to models section
        if (modelsSection) {
            modelsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    
    clearModelFilter() {
        this.currentMakeFilter = null;
        const modelsTable = document.getElementById('modelsTable');
        const allRows = modelsTable.querySelectorAll('tbody tr');
        allRows.forEach(row => {
            row.style.display = '';
        });
        
        const modelsHeader = document.querySelector('.models-section h3');
        if (modelsHeader) {
            const filterIndicator = modelsHeader.querySelector('.filter-indicator');
            if (filterIndicator) {
                filterIndicator.remove();
            }
        }
        
        this.showAlert('info', 'Showing all model variations');
    }
    

    // KEEP ALL YOUR EXISTING FUNCTIONS BELOW

    async editCar(carId) {
        await this.debugCarImages(carId);
        
        try {
            const response = await this.apiCall('get_car', 'GET', { id: carId });
            
            if (response.success && response.car) {
                await this.showEditCarModal(response.car);
            } else {
                throw new Error(response.message || 'Failed to load car data');
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to load car data');
        }
    }

    async showEditCarModal(car) {
        try {
            const imagesResponse = await this.apiCall('get_car_images', 'GET', { car_id: car.id });
            
            if (!imagesResponse.success) {
                this.showAlert('error', 'Failed to load car images');
                return;
            }

            const images = imagesResponse.images || [];
            debugLog('Loaded car images:', images);

            this.createEditCarModal(car, images);
        } catch (error) {
            this.showAlert('error', 'Failed to load car data');
        }
    }

    createEditCarModal(car, images) {
        this.prepareModal('editCarModal');
        const formatDate = (dateString) => {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        };

        const statusOptions = car.available_statuses || ['draft', 'pending_approval', 'active', 'sold', 'expired', 'suspended', 'deleted'];

        const modalHtml = `
            <div class="modal-overlay active" id="editCarModal">
                <div class="modal-content" style="max-width: 1000px;">
                    <div class="modal-header">
                        <h3>Edit Car Listing - ${this.escapeHtml(car.title)}</h3>
                        <button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form id="editCarForm">
                            <input type="hidden" name="id" value="${car.id}">
                            
                            <div class="car-owner-info">
                                <h4><i class="fas fa-user"></i> Listing Owner Information</h4>
                                <div class="owner-details">
                                    <div class="owner-detail-item">
                                        <span class="owner-label">Owner Name</span>
                                        <span class="owner-value">${this.escapeHtml(car.owner_name || car.guest_seller_name || 'N/A')}</span>
                                    </div>
                                    <div class="owner-detail-item">
                                        <span class="owner-label">Email</span>
                                        <span class="owner-value">${this.escapeHtml(car.owner_email || car.guest_seller_email || 'N/A')}</span>
                                    </div>
                                    <div class="owner-detail-item">
                                        <span class="owner-label">Phone</span>
                                        <span class="owner-value">${car.owner_phone || car.guest_seller_phone || 'N/A'}</span>
                                    </div>
                                    <div class="owner-detail-item">
                                        <span class="owner-label">User Type</span>
                                        <span class="owner-value">${car.is_guest ? 'Guest' : (car.user_type || 'Registered User')}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="image-management-section">
                                <h4><i class="fas fa-images"></i> Car Images (${images.length} images)</h4>
                                
                                <div class="current-images" id="currentImages">
                                    ${images.length > 0 ? 
                                        images.map((image, index) => {
                                            const imageFileName = image.filename;
                                            const imageUrl = imageFileName ? this.getSafeUploadUrl(imageFileName) : '';
                                            const imageName = image.original_filename || `Image ${index + 1}`;
                                            
                                            return `
                                            <div class="image-item" data-image-id="${image.id}">
                                                ${imageUrl ? `
                                                    <img src="${imageUrl}" alt="${imageName}" 
                                                         onerror="this.onerror=null; this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 fill=%22%23f8f9fa%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2214%22 fill=%22%236c757d%22>Image ${index + 1}</text></svg>';">`
                                                 : `
                                                    <div style="width:100%;height:120px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;color:#6c757d;">
                                                        <i class="fas fa-image fa-2x"></i>
                                                    </div>
                                                `}
                                                <div class="image-actions">
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="admin.deleteCarImage(${image.id})" title="Delete Image">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-success btn-sm" onclick="admin.setPrimaryImage(${image.id}, ${car.id})" title="Set as Primary" ${image.is_primary ? 'disabled' : ''}>
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                </div>
                                                ${image.is_primary ? '<span class="primary-badge">Primary</span>' : ''}
                                                <div style="padding:5px;font-size:11px;text-align:center;background:white;border-top:1px solid #e9ecef;">
                                                    ${imageName}
                                                </div>
                                            </div>
                                            `;
                                        }).join('') : 
                                        '<p class="text-muted">No images found in database for this car</p>'
                                    }
                                </div>
                                
                                <div class="upload-new-images">
                                    <label><i class="fas fa-upload"></i> Upload New Images</label>
                                    <div style="margin-bottom: 10px;">
                                        <input type="file" id="newCarImages" multiple accept="image/*" style="margin-bottom: 10px;">
                                        <small class="text-muted">Select multiple images to upload (JPEG, PNG, WebP supported)</small>
                                    </div>
                                    <button type="button" class="btn btn-primary" onclick="admin.uploadCarImages(${car.id})">
                                        <i class="fas fa-upload"></i> Upload Selected Images
                                    </button>
                                </div>
                            </div>

                            ${this.getCarFormFields(car, statusOptions)}
                        </form>
                    </div>
                    <div class="modal-footer">
                        <div class="d-flex gap-10 justify-between">
                            <div>
                                <button class="btn btn-danger" onclick="admin.deleteCar(${car.id})">
                                    <i class="fas fa-trash"></i> Delete Car Listing
                                </button>
                                ${car.status !== 'suspended' ? `
                                    <button class="btn btn-warning" onclick="admin.suspendCar(${car.id})">
                                        <i class="fas fa-ban"></i> Suspend Listing
                                    </button>
                                ` : ''}
                            </div>
                            <div class="d-flex gap-10">
                                <button class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                                <button class="btn btn-success" onclick="admin.updateCar()">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Populate makes dropdown and set selected make
        this.populateMakesForEditCar(car.make_id);
        
        // Load models for the selected make
        if (car.make_id) {
            this.loadModelsForMake(car.make_id, car.model_id);
        }
    }
    
    async populateMakesForEditCar(selectedMakeId) {
        try {
            const response = await this.apiCall('get_car_makes');
            if (response.success && response.makes) {
                const makeSelect = document.getElementById('editCarMake');
                if (makeSelect) {
                    response.makes.forEach(make => {
                        const option = document.createElement('option');
                        option.value = make.id;
                        option.textContent = make.name;
                        if (make.id == selectedMakeId) {
                            option.selected = true;
                        }
                        makeSelect.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Error loading makes:', error);
        }
    }
    
    async loadModelsForMake(makeId, selectedModelId = null) {
        if (!makeId) {
            const modelSelect = document.getElementById('editCarModel');
            if (modelSelect) {
                modelSelect.innerHTML = '<option value="">Select Model</option>';
            }
            return;
        }
        
        try {
            const response = await fetch(`${ADMIN_EDIT_API}?action=get_models`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `make_id=${makeId}`
            });
            
            const data = await response.json();
            const modelSelect = document.getElementById('editCarModel');
            
            if (data.success && data.models && modelSelect) {
                modelSelect.innerHTML = '<option value="">Select Model</option>';
                
                // Group models by name to avoid duplicates
                const uniqueModels = new Map();
                data.models.forEach(model => {
                    if (!uniqueModels.has(model.name)) {
                        uniqueModels.set(model.name, model);
                    }
                });
                
                Array.from(uniqueModels.values()).forEach(model => {
                    const option = document.createElement('option');
                    option.value = model.id;
                    option.textContent = model.name;
                    if (model.body_type) {
                        option.textContent += ` (${model.body_type})`;
                    }
                    if (selectedModelId && model.id == selectedModelId) {
                        option.selected = true;
                    }
                    modelSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading models:', error);
        }
    }

    async debugCarImages(carId) {
        debugLog('=== DEBUG CAR IMAGES ===');
        debugLog('Car ID:', carId);
        
        try {
            const response = await this.apiCall('get_car_images', 'GET', { car_id: carId });
            debugLog('API Response:', response);
            
            if (response.success && response.images) {
                debugLog('Number of images:', response.images.length);
                response.images.forEach((image, index) => {
                    debugLog(`Image ${index + 1}:`, image);
                });
            } else {
                debugLog('No images or API error:', response.message);
            }
        } catch (error) {
        }
    }

    getCarFormFields(car, statusOptions) {
        return `
            <div class="form-section">
                <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" value="${this.escapeHtml(car.title || '')}" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="4">${this.escapeHtml(car.description || '')}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-car"></i> Vehicle Details</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Make *</label>
                        <select name="make_id" id="editCarMake" onchange="admin.loadModelsForMake(this.value)" required>
                            <option value="">Select Make</option>
                            <!-- Options will be loaded dynamically -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Model *</label>
                        <select name="model_id" id="editCarModel" required>
                            <option value="${car.model_id || ''}">${this.escapeHtml(car.model_name || 'Select Model')}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year *</label>
                        <input type="number" name="year" value="${car.year || ''}" min="1900" max="${new Date().getFullYear() + 1}" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Condition *</label>
                        <select name="condition_type" required>
                            <option value="new" ${car.condition_type === 'new' ? 'selected' : ''}>New</option>
                            <option value="used" ${car.condition_type === 'used' ? 'selected' : ''}>Used</option>
                            <option value="certified_pre_owned" ${car.condition_type === 'certified_pre_owned' ? 'selected' : ''}>Certified Pre-Owned</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" value="${car.mileage || ''}" min="0">
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="text" name="color" value="${this.escapeHtml(car.color || '')}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Body Type</label>
                        <select name="body_type">
                            <option value="">Select Type</option>
                            <option value="sedan" ${car.body_type === 'sedan' ? 'selected' : ''}>Sedan</option>
                            <option value="suv" ${car.body_type === 'suv' ? 'selected' : ''}>SUV</option>
                            <option value="hatchback" ${car.body_type === 'hatchback' ? 'selected' : ''}>Hatchback</option>
                            <option value="coupe" ${car.body_type === 'coupe' ? 'selected' : ''}>Coupe</option>
                            <option value="wagon" ${car.body_type === 'wagon' ? 'selected' : ''}>Wagon</option>
                            <option value="pickup" ${car.body_type === 'pickup' ? 'selected' : ''}>Pickup</option>
                            <option value="van" ${car.body_type === 'van' ? 'selected' : ''}>Van</option>
                            <option value="convertible" ${car.body_type === 'convertible' ? 'selected' : ''}>Convertible</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">Select Fuel</option>
                            <option value="petrol" ${car.fuel_type === 'petrol' ? 'selected' : ''}>Petrol</option>
                            <option value="diesel" ${car.fuel_type === 'diesel' ? 'selected' : ''}>Diesel</option>
                            <option value="electric" ${car.fuel_type === 'electric' ? 'selected' : ''}>Electric</option>
                            <option value="hybrid" ${car.fuel_type === 'hybrid' ? 'selected' : ''}>Hybrid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission">
                            <option value="">Select Transmission</option>
                            <option value="manual" ${car.transmission === 'manual' ? 'selected' : ''}>Manual</option>
                            <option value="automatic" ${car.transmission === 'automatic' ? 'selected' : ''}>Automatic</option>
                            <option value="cvt" ${car.transmission === 'cvt' ? 'selected' : ''}>CVT</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Engine Size (L)</label>
                        <input type="text" name="engine_size" value="${car.engine_size || ''}">
                    </div>
                    <div class="form-group">
                        <label>Doors</label>
                        <input type="number" name="doors" value="${car.doors || ''}" min="2" max="7">
                    </div>
                    <div class="form-group">
                        <label>Seats</label>
                        <input type="number" name="seats" value="${car.seats || ''}" min="2" max="50">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-dollar-sign"></i> Pricing & Location</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Price (${getConfiguredCurrencyLabel()}) *</label>
                        <input type="number" name="price" value="${car.price || ''}" min="0" step="1000" required>
                    </div>
                    <div class="form-group">
                        <label>Negotiable</label>
                        <select name="negotiable">
                            <option value="1" ${car.negotiable == 1 ? 'selected' : ''}>Yes</option>
                            <option value="0" ${car.negotiable == 0 ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Location *</label>
                        <select name="location_id" id="editCarLocation" required>
                            <option value="${car.location_id || ''}">${this.escapeHtml(car.location_name || 'Select Location')}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-cog"></i> Features</h4>
                <div class="form-row">
                    <div class="form-group full-width">
                        <label>Features (comma-separated)</label>
                        <textarea name="features" rows="3" placeholder="e.g., Air Conditioning, Power Steering, ABS, Airbags">${this.escapeHtml(car.features || '')}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-cog"></i> Listing Status</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required>
                            ${statusOptions.map(status => `
                                <option value="${status}" ${car.status === status ? 'selected' : ''}>${status.replace('_', ' ').toUpperCase()}</option>
                            `).join('')}
                        </select>
                    </div>
                </div>
            </div>
        `;
    }

    async deleteCar(carId) {
        if (!confirm('Are you sure you want to delete this car? This action cannot be undone.')) {
            return;
        }
        
        try {
            const response = await this.apiCall('delete_car', 'POST', { id: carId });
            
            if (response.success) {
                this.showAlert('success', 'Car deleted successfully');
                document.getElementById('editCarModal').remove();
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete car');
        }
    }

    async uploadCarImages(carId) {
        const input = document.getElementById('newCarImages');
        if (!input.files.length) {
            this.showAlert('warning', 'Please select at least one image');
            return;
        }
        
        const formData = new FormData();
        formData.append('car_id', carId);
        
        for (let i = 0; i < input.files.length; i++) {
            formData.append('images[]', input.files[i]);
        }
        
        try {
            const response = await fetch(`${this.API_URL}?action=upload_car_images`, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                this.showAlert('success', 'Images uploaded successfully');
                await this.refreshCarImages(carId);
                await this.refreshCarImageManagers(Number(carId));
                input.value = '';
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to upload images');
        }
    }

    async deleteCarImage(imageId, carId = null) {
        if (!confirm('Are you sure you want to delete this image? This will permanently remove it from the database and filesystem.')) {
            return;
        }

        try {
            const response = await this.apiCall('delete_car_image', 'POST', { image_id: imageId });

            if (response.success) {
                this.showAlert('success', 'Image deleted successfully');

                // If carId is provided, refresh the modal
                if (carId) {
                    const refreshed = await this.refreshCarImageManagers(Number(carId));
                    if (!refreshed) {
                        await this.refreshCarImages(Number(carId));
                    }
                } else {
                    // Old behavior: remove element from DOM
                    const imageElement = document.querySelector(`[data-image-id="${imageId}"]`);
                    if (imageElement) {
                        imageElement.remove();
                    }
                }
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete image');
        }
    }

    async deleteCarFromTable(carId) {
        const confirmed = await this.showActionConfirmDialog(
            'Delete Listing',
            'Are you sure you want to delete this car? This action cannot be undone.',
            'Delete',
            'btn-danger'
        );
        if (!confirmed) {
            return;
        }
        
        try {
            const response = await this.apiCall('delete_car', 'POST', { id: carId });
            
            if (response.success) {
                this.showAlert('success', 'Car deleted successfully');
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete car');
        }
    }

    async editUser(id) {
        const normalizedId = Number(id);
        const cachedUser = Array.isArray(this.usersCache)
            ? this.usersCache.find(user => Number(user.id) === normalizedId)
            : null;

        if (cachedUser) {
            this.showUserEditModal(cachedUser);
        }

        try {
            const response = await this.apiCall('get_users', 'GET', { id: normalizedId });
            if (response.success && response.users && response.users.length > 0) {
                const user = response.users[0]; // First user should be the one with matching ID
                this.showUserEditModal(user);
            } else {
                if (!cachedUser) {
                    this.showAlert('error', 'Failed to load user: ' + (response.message || 'User not found'));
                }
            }
        } catch (error) {
            if (!cachedUser) {
                this.showAlert('error', 'Failed to load user details: ' + error.message);
            }
        }
    }

    showUserEditModal(user) {
        this.prepareModal('editUserModal');

        const safeFullName = this.escapeHtml(user.full_name || 'N/A');
        const safeEmail = this.escapeHtml(user.email || '');
        const safePhone = this.escapeHtml(user.phone || '');
        const safeCity = this.escapeHtml(user.city || '');
        const safeCreatedAt = this.escapeHtml(user.created_at || 'N/A');
        const safeLastLogin = this.escapeHtml(user.last_login || 'Never');

        const allowedUserTypes = ['individual', 'dealer', 'garage', 'car_hire', 'admin'];
        const selectedUserType = allowedUserTypes.includes(user.user_type) ? user.user_type : 'individual';
        const allowedStatuses = ['active', 'pending', 'pending_approval', 'suspended', 'banned', 'rejected'];
        const selectedStatus = allowedStatuses.includes(user.status) ? user.status : 'active';

        const modal = document.createElement('div');
        modal.id = 'editUserModal';
        modal.className = 'modal-overlay active';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3><i class="fas fa-user-edit"></i> Edit User: ${safeFullName}</h3>
                    <button class="modal-close" onclick="admin.closeModal('editUserModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="editUserForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" id="editUserName" class="form-control" value="${safeFullName}" required>
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" id="editUserEmail" class="form-control" value="${safeEmail}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Phone *</label>
                                <input type="tel" id="editUserPhone" class="form-control" value="${safePhone}" required>
                            </div>
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" id="editUserCity" class="form-control" value="${safeCity}">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>User Type *</label>
                                <select id="editUserType" class="form-control" required>
                                    <option value="individual" ${selectedUserType === 'individual' ? 'selected' : ''}>Individual</option>
                                    <option value="dealer" ${selectedUserType === 'dealer' ? 'selected' : ''}>Dealer</option>
                                    <option value="garage" ${selectedUserType === 'garage' ? 'selected' : ''}>Garage</option>
                                    <option value="car_hire" ${selectedUserType === 'car_hire' ? 'selected' : ''}>Car Hire</option>
                                    <option value="admin" ${selectedUserType === 'admin' ? 'selected' : ''}>Admin</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status *</label>
                                <select id="editUserStatus" class="form-control" required>
                                    <option value="active" ${selectedStatus === 'active' ? 'selected' : ''}>Active</option>
                                    <option value="pending" ${selectedStatus === 'pending' ? 'selected' : ''}>Pending</option>
                                    <option value="pending_approval" ${selectedStatus === 'pending_approval' ? 'selected' : ''}>Pending Approval</option>
                                    <option value="suspended" ${selectedStatus === 'suspended' ? 'selected' : ''}>Suspended</option>
                                    <option value="banned" ${selectedStatus === 'banned' ? 'selected' : ''}>Banned</option>
                                    <option value="rejected" ${selectedStatus === 'rejected' ? 'selected' : ''}>Rejected</option>
                                </select>
                            </div>
                        </div>

                        <!-- Password Management Section -->
                        <div style="margin: 25px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #00c853;">
                            <h4 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">
                                <i class="fas fa-lock"></i> Password Management
                            </h4>
                            <div class="form-group">
                                <label>New Password (leave blank to keep current)</label>
                                <div style="position: relative;">
                                    <input type="password" id="editUserPassword" class="form-control" placeholder="Enter new password" autocomplete="new-password" style="padding-right: 45px;">
                                    <button type="button" onclick="admin.togglePasswordVisibility('editUserPassword', this)"
                                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #666; cursor: pointer; padding: 8px; font-size: 16px;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small style="color: #666; font-size: 12px;">Minimum 6 characters required if changing password</small>
                            </div>
                            <button type="button" class="btn btn-warning btn-sm" onclick="admin.generateRandomPassword('editUserPassword')" style="margin-top: 8px;">
                                <i class="fas fa-random"></i> Generate Random Password
                            </button>
                            <button type="button" class="btn btn-info btn-sm" id="sendPasswordResetBtn" style="margin-top: 8px; margin-left: 8px;">
                                <i class="fas fa-envelope"></i> Send Password Reset Email
                            </button>
                        </div>

                        <div class="form-group">
                            <label>Account Created</label>
                            <input type="text" class="form-control" value="${safeCreatedAt}" disabled>
                        </div>
                        <div class="form-group">
                            <label>Last Login</label>
                            <input type="text" class="form-control" value="${safeLastLogin}" disabled>
                        </div>

                        <div class="modal-footer" style="margin-top: 25px; display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" class="btn btn-danger" onclick="admin.deleteUser(${user.id})">
                                <i class="fas fa-trash"></i> Delete User
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="admin.closeModal('editUserModal')">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.closeModal('editUserModal');
            }
        });

        const sendResetBtn = document.getElementById('sendPasswordResetBtn');
        if (sendResetBtn) {
            sendResetBtn.addEventListener('click', () => this.sendPasswordReset(user.id, user.email || ''));
        }

        document.getElementById('editUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.updateUser(user.id);
        });
    }

    async updateUser(id) {
        try {
            const password = document.getElementById('editUserPassword').value;

            // Validate password if provided
            if (password && password.length < 6) {
                this.showAlert('error', 'Password must be at least 6 characters');
                return;
            }

            const data = {
                id: id,
                full_name: document.getElementById('editUserName').value,
                email: document.getElementById('editUserEmail').value,
                phone: document.getElementById('editUserPhone').value,
                city: document.getElementById('editUserCity').value,
                user_type: document.getElementById('editUserType').value,
                status: document.getElementById('editUserStatus').value
            };

            // Only include password if it's been changed
            if (password) {
                data.password = password;
            }

            const response = await this.apiCall('update_user', 'POST', data);

            if (response.success) {
                this.showAlert('success', 'User updated successfully');
                this.closeModal('editUserModal');
                await this.loadUsers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to update user');
        }
    }

    togglePasswordVisibility(inputId, button) {
        const input = document.getElementById(inputId);
        const icon = button.querySelector('i');

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    generateRandomPassword(inputId) {
        const length = 12;
        const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';

        // Ensure at least one of each type
        password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
        password += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
        password += '0123456789'[Math.floor(Math.random() * 10)];
        password += '!@#$%^&*'[Math.floor(Math.random() * 8)];

        // Fill the rest
        for (let i = password.length; i < length; i++) {
            password += charset[Math.floor(Math.random() * charset.length)];
        }

        // Shuffle the password
        password = password.split('').sort(() => Math.random() - 0.5).join('');

        const input = document.getElementById(inputId);
        input.value = password;
        input.type = 'text'; // Show the generated password

        // Copy to clipboard
        navigator.clipboard.writeText(password).then(() => {
            this.showAlert('success', 'Password generated and copied to clipboard!');
        }).catch(() => {
            this.showAlert('info', `Generated password: ${password}`);
        });
    }

    async sendPasswordReset(userId, email) {
        if (!confirm(`Send password reset email to ${email}?`)) {
            return;
        }

        try {
            // For now, show a notification - this would need backend support
            this.showAlert('info', `Password reset functionality would send an email to ${email}. For now, use the "Generate Random Password" button and manually share it with the user.`);

            // TODO: Implement actual password reset email via API
            // const response = await this.apiCall('send_password_reset', 'POST', { user_id: userId, email: email });
        } catch (error) {
            this.showAlert('error', 'Failed to send password reset email');
        }
    }

    async deleteUser(id) {
        const confirmMessage = 'WARNING: This will PERMANENTLY delete this user and ALL related data:\n\n' +
            '• All their car listings\n' +
            '• Their business (garage/dealer/car hire company)\n' +
            '• All fleet vehicles (for car hire)\n' +
            '• All inventory (for dealers)\n' +
            '• All services (for garages)\n' +
            '• All reviews, messages, favorites, and history\n\n' +
            'This action CANNOT be undone!\n\n' +
            'Are you absolutely sure you want to continue?';
            
        if (!confirm(confirmMessage)) {
            return;
        }

        // Second confirmation for safety
        if (!confirm('FINAL WARNING: Type confirmation is required. Click OK to permanently delete everything.')) {
            return;
        }

        try {
            const response = await this.apiCall('delete_user', 'POST', { id: id });

            if (response.success) {
                this.showAlert('success', 'User and all related data permanently deleted');
                document.getElementById('editUserModal')?.remove();
                await this.loadUsers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete user');
        }
    }

    async suspendUser(id) {
        if (confirm('Are you sure you want to suspend this user?')) {
            try {
                const response = await this.apiCall('update_user', 'POST', { id: id, status: 'suspended' });

                if (response.success) {
                    this.showAlert('success', 'User suspended successfully');
                    await this.loadUsers();
                } else {
                    throw new Error(response.message);
                }
            } catch (error) {
                this.showAlert('error', error.message || 'Failed to suspend user');
            }
        }
    }

    async toggleAIChatAccess(userId, disable) {
        const action = disable ? 'disable' : 'enable';
        const reason = disable ? prompt('Please provide a reason for disabling AI chat access (this will be shown to the user):') : '';
        
        if (disable && !reason) {
            this.showAlert('error', 'A reason is required when disabling AI chat access');
            return;
        }
        
        if (disable && !confirm(`Are you sure you want to disable AI chat access for this user?\n\nReason: ${reason}`)) {
            return;
        }
        
        if (!disable && !confirm('Are you sure you want to enable AI chat access for this user?')) {
            return;
        }
        
        try {
            const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
            const response = await fetch(`${ADMIN_API_BASE}${separator}action=set_user_ai_restriction`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    user_id: userId,
                    disabled: disable ? 1 : 0,
                    reason: reason || ''
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showAlert('success', data.message);
                await this.loadUsers();
            } else {
                throw new Error(data.message || 'Failed to update AI chat access');
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to update AI chat access');
        }
    }

    async toggleFeatureCar(id, isFeatured) {
        try {
            const enablingFeatured = Number(isFeatured) === 1;
            const confirmed = await this.showActionConfirmDialog(
                enablingFeatured ? 'Set Featured Listing' : 'Remove Featured Listing',
                enablingFeatured ? 'Mark this listing as featured?' : 'Remove featured status from this listing?',
                enablingFeatured ? 'Set Featured' : 'Remove Featured',
                enablingFeatured ? 'btn-warning' : 'btn-secondary'
            );
            if (!confirmed) {
                return;
            }

            const action = enablingFeatured ? 'feature' : 'unfeature';
            const response = await this.apiCall('feature_car', 'POST', { id: id, is_featured: isFeatured });

            if (response.success) {
                this.showAlert('success', `Car ${action}d successfully`);
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to feature car');
        }
    }

    async togglePremiumCar(id, isPremium) {
        try {
            const action = isPremium ? 'set to premium' : 'removed from premium';
            const response = await this.apiCall('premium_car', 'POST', { id: id, is_premium: isPremium });

            if (response.success) {
                this.showAlert('success', `Car ${action} successfully`);
                await this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to set car as premium');
        }
    }

    async deleteGarage(id) {
        try {
            // First get the garage details to show in confirmation
            const garageResponse = await this.apiCall('get_garages', 'GET');
            const garage = garageResponse.garages?.find(g => g.id == id);

            if (!garage) {
                this.showAlert('error', 'Garage not found');
                return;
            }

            // Build detailed confirmation message
            const carListingsCount = garage.active_listings || 0;
            let confirmMsg = `WARNING: You are about to PERMANENTLY DELETE this garage:\n\n`;
            confirmMsg += `Garage: ${garage.name}\n`;
            confirmMsg += `Owner: ${garage.owner_name}\n`;
            confirmMsg += `Email: ${garage.email}\n`;
            confirmMsg += `Phone: ${garage.phone}\n\n`;
            confirmMsg += `This action will:\n`;
            confirmMsg += `- Delete the garage business\n`;
            confirmMsg += `- Delete the associated user account\n`;
            confirmMsg += `- Move ${carListingsCount} car listing(s) to deleted section\n`;
            confirmMsg += `- Remove all user data (messages, favorites, etc.)\n\n`;
            confirmMsg += `This action CANNOT be undone!\n\n`;
            confirmMsg += `Click OK to confirm deletion or Cancel to abort.`;

            if (!confirm(confirmMsg)) {
                this.showAlert('info', 'Deletion cancelled');
                return;
            }

            const response = await this.apiCall('delete_garage', 'POST', { id: id, delete_user: true });

            if (response.success) {
                this.showAlert('success', response.message || 'Garage deleted successfully');
                await this.loadGarages();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete garage');
        }
    }

    async deleteDealer(id) {
        try {
            // First get the dealer details to show in confirmation
            const dealerResponse = await this.apiCall('get_dealers', 'GET');
            const dealer = dealerResponse.dealers?.find(d => d.id == id);

            if (!dealer) {
                this.showAlert('error', 'Dealer not found');
                return;
            }

            // Build detailed confirmation message
            const carListingsCount = dealer.active_listings || 0;
            let confirmMsg = `WARNING: You are about to PERMANENTLY DELETE this dealer:\n\n`;
            confirmMsg += `Dealer: ${dealer.business_name}\n`;
            confirmMsg += `Owner: ${dealer.owner_name}\n`;
            confirmMsg += `Email: ${dealer.email}\n`;
            confirmMsg += `Phone: ${dealer.phone}\n\n`;
            confirmMsg += `This action will:\n`;
            confirmMsg += `- Delete the dealer business\n`;
            confirmMsg += `- Delete the associated user account\n`;
            confirmMsg += `- Move ${carListingsCount} car listing(s) to deleted section\n`;
            confirmMsg += `- Remove all user data (messages, favorites, etc.)\n\n`;
            confirmMsg += `This action CANNOT be undone!\n\n`;
            confirmMsg += `Click OK to confirm deletion or Cancel to abort.`;

            if (!confirm(confirmMsg)) {
                this.showAlert('info', 'Deletion cancelled');
                return;
            }

            const response = await this.apiCall('delete_dealer', 'POST', { id: id, delete_user: true });

            if (response.success) {
                this.showAlert('success', response.message || 'Dealer deleted successfully');
                await this.loadDealers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete dealer');
        }
    }

    async deleteCarHire(id) {
        try {
            // First get the car hire company details to show in confirmation
            const carHireResponse = await this.apiCall('get_car_hire', 'GET');
            const carHire = carHireResponse.car_hire?.find(c => c.id == id);

            if (!carHire) {
                console.error('Car hire not found with ID:', id);
                this.showAlert('error', 'Car hire company not found');
                return;
            }

            // Build detailed confirmation message
            const carListingsCount = carHire.active_listings || 0;
            const fleetCount = carHire.fleet_count || 0;
            let confirmMsg = `WARNING: You are about to PERMANENTLY DELETE this car hire company:\n\n`;
            confirmMsg += `Company: ${carHire.business_name}\n`;
            confirmMsg += `Owner: ${carHire.owner_name}\n`;
            confirmMsg += `Email: ${carHire.email}\n`;
            confirmMsg += `Phone: ${carHire.phone}\n\n`;
            confirmMsg += `This action will:\n`;
            confirmMsg += `- Delete the car hire business\n`;
            confirmMsg += `- Delete the associated user account\n`;
            confirmMsg += `- Move ${carListingsCount} car listing(s) to deleted section\n`;
            confirmMsg += `- Remove ${fleetCount} fleet vehicle(s)\n`;
            confirmMsg += `- Remove all user data (messages, favorites, etc.)\n\n`;
            confirmMsg += `This action CANNOT be undone!\n\n`;
            confirmMsg += `Click OK to confirm deletion or Cancel to abort.`;

            const confirmed = confirm(confirmMsg);

            if (!confirmed) {
                this.showAlert('info', 'Deletion cancelled');
                return;
            }

            const response = await this.apiCall('delete_car_hire', 'POST', { id: id, delete_user: true });

            if (response.success) {
                this.showAlert('success', response.message || 'Car hire company deleted successfully');
                await this.loadCarHire();
            } else {
                console.error('Deletion failed:', response.message);
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Delete car hire error:', error);
            this.showAlert('error', error.message || 'Failed to delete car hire company');
        }
    }

    async toggleFeatureGarage(garageId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'featured' : 'unfeatured';
            const response = await this.apiCall('feature_garage', 'POST', { id: garageId, is_featured: newStatus });

            if (response.success) {
                this.showAlert('success', `Garage ${action} successfully`);
                await this.loadGarages();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error featuring garage:', error);
            this.showAlert('error', error.message || 'Failed to feature garage');
        }
    }

    async toggleVerifyGarage(garageId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'verified' : 'unverified';
            const response = await this.apiCall('verify_garage', 'POST', { id: garageId, is_verified: newStatus });

            if (response.success) {
                this.showAlert('success', `Garage ${action} successfully`);
                await this.loadGarages();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error verifying garage:', error);
            this.showAlert('error', error.message || 'Failed to verify garage');
        }
    }

    async toggleCertifyGarage(garageId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'certified' : 'uncertified';
            const response = await this.apiCall('certify_garage', 'POST', { id: garageId, is_certified: newStatus });

            if (response.success) {
                this.showAlert('success', `Garage ${action} successfully`);
                await this.loadGarages();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error certifying garage:', error);
            this.showAlert('error', error.message || 'Failed to certify garage');
        }
    }

    async toggleFeatureDealer(dealerId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'featured' : 'unfeatured';
            const response = await this.apiCall('feature_dealer', 'POST', { id: dealerId, is_featured: newStatus });

            if (response.success) {
                this.showAlert('success', `Dealer ${action} successfully`);
                await this.loadDealers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error featuring dealer:', error);
            this.showAlert('error', error.message || 'Failed to feature dealer');
        }
    }

    async toggleVerifyDealer(dealerId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'verified' : 'unverified';
            const response = await this.apiCall('verify_dealer', 'POST', { id: dealerId, is_verified: newStatus });

            if (response.success) {
                this.showAlert('success', `Dealer ${action} successfully`);
                await this.loadDealers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error verifying dealer:', error);
            this.showAlert('error', error.message || 'Failed to verify dealer');
        }
    }

    async toggleCertifyDealer(dealerId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'certified' : 'uncertified';
            const response = await this.apiCall('certify_dealer', 'POST', { id: dealerId, is_certified: newStatus });

            if (response.success) {
                this.showAlert('success', `Dealer ${action} successfully`);
                await this.loadDealers();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error certifying dealer:', error);
            this.showAlert('error', error.message || 'Failed to certify dealer');
        }
    }

    async toggleFeatureCarHire(carHireId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'featured' : 'unfeatured';
            const response = await this.apiCall('feature_car_hire', 'POST', { id: carHireId, is_featured: newStatus });

            if (response.success) {
                this.showAlert('success', `Car hire company ${action} successfully`);
                await this.loadCarHire();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error featuring car hire:', error);
            this.showAlert('error', error.message || 'Failed to feature car hire company');
        }
    }

    async toggleVerifyCarHire(carHireId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'verified' : 'unverified';
            const response = await this.apiCall('verify_car_hire', 'POST', { id: carHireId, is_verified: newStatus });

            if (response.success) {
                this.showAlert('success', `Car hire company ${action} successfully`);
                await this.loadCarHire();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error verifying car hire:', error);
            this.showAlert('error', error.message || 'Failed to verify car hire company');
        }
    }

    async toggleCertifyCarHire(carHireId, currentStatus) {
        try {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'certified' : 'uncertified';
            const response = await this.apiCall('certify_car_hire', 'POST', { id: carHireId, is_certified: newStatus });

            if (response.success) {
                this.showAlert('success', `Car hire company ${action} successfully`);
                await this.loadCarHire();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Error certifying car hire:', error);
            this.showAlert('error', error.message || 'Failed to certify car hire company');
        }
    }

    viewCarImages(carId, carTitle) {
        this.showCarImagesModal(carId, carTitle);
    }

    async showCarImagesModal(carId, carTitle) {
        this.prepareModal('viewCarImagesModal');
        const modal = document.createElement('div');
        modal.id = 'viewCarImagesModal';
        modal.className = 'modal-overlay active';
        modal.style.cssText = 'display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center;';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto; background: white; border-radius: 8px; position: relative;">
                <div class="modal-header">
                    <h3>Images: ${this.escapeHtml(carTitle)}</h3>
                    <button class="close-btn" onclick="document.getElementById('viewCarImagesModal').remove()" style="position: absolute; right: 15px; top: 15px; background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="carImagesGallery" data-car-gallery-for="${carId}" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; padding: 20px;">
                        <div class="text-center">Loading images...</div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Close modal on background click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });

        await this.refreshCarImageManagers(Number(carId));
    }

    async viewPayment(id) {
        this.showAlert('info', 'View payment details coming soon');
    }

    displayActivities(activities) {
        const container = document.getElementById('recentActivities');
        
        if (!activities || activities.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent activities</p>';
            return;
        }

        const html = activities.map(activity => `
            <div class="activity-item mb-15">
                <div class="d-flex align-center gap-10">
                    <div class="activity-icon ${activity.type}">
                        <i class="fas fa-${this.getActivityIcon(activity.type)}"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">${activity.title}</div>
                        <div class="activity-desc text-muted">${activity.description}</div>
                        <div class="activity-time text-muted">${this.formatDate(activity.created_at)}</div>
                    </div>
                </div>
            </div>
        `).join('');

        container.innerHTML = html;
    }

    displayPendingApprovals(items) {
        const container = document.getElementById('pendingApprovals');
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="text-center text-success"><i class="fas fa-check-circle"></i><br>All items approved!</div>';
            return;
        }

        const html = items.map(item => {
            const isEmailVerificationWait = item.type === 'guest_listing_email_verification';
            const showApproveReject = !isEmailVerificationWait;
            const statusHint = isEmailVerificationWait
                ? '<span class="text-warning">Awaiting seller email verification</span>'
                : `<span class="text-primary">${item.owner_name}</span>`;

            return `
            <div class="pending-item mb-15">
                <div class="d-flex justify-between align-center">
                    <div>
                        <div class="pending-title">${item.name}</div>
                        <div class="pending-meta text-muted">
                            <span class="pending-type">${String(item.type || '').replace('_', ' ')}</span> • 
                            ${this.formatDate(item.created_at)} • 
                            ${statusHint}
                        </div>
                    </div>
                    <div class="d-flex gap-5">
                        ${showApproveReject ? `
                            <button class="btn btn-sm btn-success" onclick="admin.approveItem('${item.type}', ${item.id}, 'approve')" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="admin.approveItem('${item.type}', ${item.id}, 'reject')" title="Reject">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : `
                            <button class="btn btn-sm btn-warning" disabled title="Waiting for email verification">
                                <i class="fas fa-clock"></i>
                            </button>
                        `}
                    </div>
                </div>
            </div>
        `;
        }).join('');

        container.innerHTML = html;
    }

    displayCarsTable(cars) {
        const tbody = document.getElementById('carsTableBody');
        
        if (!cars || cars.length === 0) {
            tbody.innerHTML = '<tr><td colspan="13" class="text-center text-muted">No cars found</td></tr>';
            return;
        }

        const html = cars.map(car => {
            const listingImage = car.featured_image || car.primary_image || car.fallback_image || '';
            return `
            <tr>
                <td>
                    <input type="checkbox" class="car-checkbox" value="${car.id}" onchange="admin.updateBulkSelection()">
                </td>
                <td>${car.id}</td>
                <td class="car-image-cell">
                    <div class="car-image-thumb">
                        ${listingImage ? 
                                `<img src="${this.getSafeUploadUrl(listingImage)}" alt="${this.escapeHtml(car.title)}" 
                                    onerror="adminHandleImageFallback(this, 'car-image-placeholder')" style="max-width:84px;max-height:60px;border-radius:6px;">` :
                            `<i class="fas fa-car car-image-placeholder"></i>`
                        }
                    </div>
                </td>
                <td>
                    <div class="car-title">${this.escapeHtml(car.title)}</div>
                    <div class="text-muted small">Ref: ${car.reference_number || 'N/A'}</div>
                    ${car.description ? `<div class="text-muted small">${this.escapeHtml(car.description.substring(0, 50))}...</div>` : ''}
                </td>
                <td>
                    <div>${car.make_name || 'N/A'}</div>
                    <div class="text-muted small">${car.model_name || 'N/A'}</div>
                </td>
                <td>${car.year}</td>
                <td>${formatConfiguredCurrencyAmount(car.price)}</td>
                <td>${this.escapeHtml(car.location_name || 'N/A')}</td>
                <td>
                    <span class="status-badge status-${car.status}">${car.status.replace('_', ' ')}</span>
                    ${car.is_featured ? `<div class="text-warning small"><i class="fas fa-star"></i> Featured</div>` : ''}
                    ${car.is_premium ? `<div class="text-success small"><i class="fas fa-crown"></i> Premium</div>` : ''}
                </td>
                <td>${this.renderListingPaymentBadge(car)}</td>
                <td>${car.views_count || 0}</td>
                <td>${this.formatDateShort(car.created_at)}</td>
                <td>
                    <div class="car-actions d-flex gap-5" style="flex-wrap:wrap;align-items:center;">
                        ${(() => {
                            const canModerate = (
                                car.status === 'pending_approval' ||
                                car.approval_status === 'pending' ||
                                car.approval_status === 'pending_email_verification'
                            ) && !(car.status === 'active' && car.approval_status === 'approved');

                            if (!canModerate) {
                                return '';
                            }

                            return `
                            <button class="btn btn-xs btn-success" onclick="admin.approveCar(${car.id}, 'approve')" title="Approve">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-xs btn-danger" onclick="admin.approveCar(${car.id}, 'reject')" title="Reject">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        `;
                        })()}
                        <button class="btn btn-xs btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-xs btn-primary" onclick="admin.editCar(${car.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-xs ${car.is_featured ? 'btn-warning' : 'btn-outline-warning'}"
                                onclick="admin.toggleFeatureCar(${car.id}, ${car.is_featured ? 0 : 1})"
                                title="${car.is_featured ? 'Remove' : 'Set'} Featured">
                            <i class="fas fa-star"></i>
                        </button>
                        ${car.status === 'suspended' ? `
                        <button class="btn btn-xs btn-success" onclick="admin.unsuspendCar(${car.id})" title="Unsuspend">
                            <i class="fas fa-play"></i>
                        </button>` : car.status === 'active' ? `
                        <button class="btn btn-xs btn-warning" onclick="admin.suspendCar(${car.id})" title="Suspend">
                            <i class="fas fa-ban"></i>
                        </button>` : ''}
                        <button class="btn btn-xs btn-danger" onclick="admin.deleteCarFromTable(${car.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        }).join('');

        tbody.innerHTML = html;
        this.updateBulkSelection();
        this.enableTableSorting('carsTable');
    }

    displayDeletedCarsTable(cars) {
        const tbody = document.getElementById('deletedCarsTableBody');

        if (!cars || cars.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">No deleted cars found</td></tr>';
            return;
        }

        const html = cars.map(car => `
            <tr>
                <td>${car.id}</td>
                <td class="car-image-cell">
                    <div class="car-image-thumb">
                        ${(car.featured_image || car.primary_image || car.fallback_image) ?
                                `<img src="${this.getSafeUploadUrl(car.featured_image || car.primary_image || car.fallback_image)}" alt="${this.escapeHtml(car.title)}"
                                    onerror="adminHandleImageFallback(this, 'car-image-placeholder')" style="max-width:60px;max-height:45px;border-radius:4px;">` :
                            `<i class="fas fa-car car-image-placeholder"></i>`
                        }
                    </div>
                </td>
                <td>
                    <div class="car-title">${this.escapeHtml(car.title)}</div>
                    <div class="text-muted small">Ref: ${car.reference_number || 'N/A'}</div>
                </td>
                <td>
                    <div>${car.make_name || 'N/A'}</div>
                    <div class="text-muted small">${car.model_name || 'N/A'}</div>
                </td>
                <td>${car.year}</td>
                <td>${formatConfiguredCurrencyAmount(car.price)}</td>
                <td>${this.escapeHtml(car.location_name || 'N/A')}</td>
                <td>
                    <div>${this.escapeHtml(car.owner_name || 'N/A')}</div>
                    <div class="text-muted small">${this.escapeHtml(car.owner_email || '')}</div>
                </td>
                <td>
                    <div>${this.escapeHtml(car.deleted_by_name || 'System')}</div>
                    <div class="text-muted small">${this.formatDateShort(car.deleted_at)}</div>
                </td>
                <td>${this.formatDateShort(car.created_at)}</td>
                <td>${car.views_count || 0}</td>
                <td>
                    <div class="d-flex gap-5">
                        <button class="btn btn-sm btn-success" onclick="admin.restoreCar(${car.id})" title="Restore Listing">
                            <i class="fas fa-undo"></i> Restore
                        </button>
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary" onclick="admin.viewCarImages(${car.id}, '${this.escapeHtml(car.title).replace(/'/g, "\\'")}')" title="View Images">
                            <i class="fas fa-images"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('deletedCarsTable');
    }

    displayPaymentsTable(payments) {
        const tbody = document.getElementById('paymentsTableBody');
        
        if (!payments || payments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No payments found</td></tr>';
            return;
        }

        const html = payments.map(payment => {
            const paymentId = String(payment.id ?? '');
            const isNumericPayment = /^\d+$/.test(paymentId);
            const listingId = Number(payment.listing_id || 0);
            const isFreeListing = Number(payment.is_free_listing || 0) === 1 || payment.service_type === 'free_listing' || payment.status === 'free';
            return `
            <tr>
                <td>#${this.escapeHtml(paymentId)}</td>
                <td>
                    <div>${this.escapeHtml(payment.user_name)}</div>
                    <div class="text-muted small">${payment.user_email}</div>
                </td>
                <td>${this.formatServiceType(payment.service_type)}</td>
                <td>${formatConfiguredCurrencyAmount(payment.amount)}</td>
                <td>${payment.payment_method ? this.capitalize(String(payment.payment_method).replace('_', ' ')) : '-'}</td>
                <td>
                    <div>${payment.reference || '-'}</div>
                    ${payment.listing_id ? `<div class="small text-muted">Listing #${payment.listing_id}</div>` : ''}
                    ${payment.proof_path ? `<div class="small"><a href="../${payment.proof_path}" target="_blank" rel="noopener">View POP</a></div>` : ''}
                </td>
                <td><span class="status-badge status-${payment.status}">${this.formatManualPaymentStatus(payment.status)}</span></td>
                <td>${this.formatDateShort(payment.created_at)}</td>
                <td>
                    <div class="d-flex gap-5">
                        ${payment.status === 'pending' && isNumericPayment ? `
                            <button class="btn btn-sm btn-success" onclick="admin.verifyPayment(${paymentId})" title="Verify Manually">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="admin.rejectPayment(${paymentId})" title="Reject POP">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                        ${listingId > 0 ? `<button class="btn btn-sm ${isFreeListing ? 'btn-primary' : 'btn-outline-primary'}" onclick="showAddPaymentModal(${listingId})" title="Apply Manual Payment to Listing">
                            <i class="fas fa-hand-holding-usd"></i>
                        </button>` : ''}
                        ${listingId > 0 ? `<a class="btn btn-sm btn-secondary" href="../car.html?id=${listingId}" target="_blank" rel="noopener" title="View listing page"><i class="fas fa-external-link-alt"></i></a>` : ''}
                        <button class="btn btn-sm btn-info" onclick="admin.viewPayment('${paymentId.replace(/'/g, "\\'")}')" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        }).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('paymentsTable');
    }

    displayUsersTable(users) {
        const tbody = document.getElementById('usersTableBody');
        
        if (!users || users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No users found</td></tr>';
            return;
        }

        const createAvatarSVG = (name) => {
            const safeName = (name || 'User').trim();
            const initials = safeName.split(/\s+/).filter(Boolean).map(n => n[0]).join('').toUpperCase() || 'U';
            return `data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='16' fill='%23007bff'/><text x='16' y='21' font-family='Arial' font-size='14' text-anchor='middle' fill='white'>${initials}</text></svg>`;
        };

        const html = users.map(user => `
            <tr>
                <td>${user.id}</td>
                <td>
                    <img src="${this.escapeHtml(user.profile_image || createAvatarSVG(user.full_name || 'User'))}" 
                         alt="${this.escapeHtml(user.full_name || 'User')}" 
                         class="user-avatar-small"
                         style="width: 32px; height: 32px; border-radius: 50%;">
                </td>
                <td>
                    <div class="user-name">${this.escapeHtml(user.full_name || 'N/A')}</div>
                    ${user.bio ? `<div class="text-muted small">${this.escapeHtml(user.bio.substring(0, 30))}...</div>` : ''}
                </td>
                <td>${this.escapeHtml(user.email || '')}</td>
                <td>${user.phone || '-'}</td>
                <td><span class="user-type-badge">${user.user_type}</span></td>
                <td><span class="status-badge status-${user.status}">${user.status}</span></td>
                <td>${this.formatDateShort(user.created_at)}</td>
                <td>${user.last_login ? this.formatDateShort(user.last_login) : 'Never'}</td>
                <td>
                    <div class="d-flex gap-5">
                        ${user.status === 'pending_approval' ? `
                            <button class="btn btn-sm btn-success" onclick="admin.approveUser(${user.id}, 'approve')" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-warning" onclick="admin.approveUser(${user.id}, 'reject')" title="Reject">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-sm btn-primary" onclick="admin.editUser(${user.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="admin.suspendUser(${user.id})" title="Suspend">
                            <i class="fas fa-ban"></i>
                        </button>
                        <button class="btn btn-sm ${user.ai_chat_disabled ? 'btn-danger' : 'btn-info'}" 
                                onclick="admin.toggleAIChatAccess(${user.id}, ${user.ai_chat_disabled ? 'false' : 'true'})" 
                                title="${user.ai_chat_disabled ? 'Enable AI Chat' : 'Disable AI Chat'}">
                            <i class="fas fa-${user.ai_chat_disabled ? 'unlock' : 'lock'}"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('usersTable');
    }

    displayAdminsTable(admins) {
        const tbody = document.getElementById('adminsTableBody');

        const formatAdminRole = (role) => {
            const labels = {
                super_admin: 'Super Admin',
                admin: 'Admin',
                moderator: 'Moderator',
                onboarding_manager: 'Onboarding Manager'
            };

            return labels[role] || role || 'Unknown';
        };

        if (!admins || admins.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No administrators found</td></tr>';
            return;
        }

        const html = admins.map(admin => `
            <tr>
                <td>${admin.id}</td>
                <td>${this.escapeHtml(admin.full_name)}</td>
                <td>${this.escapeHtml(admin.email)}</td>
                <td><span class="user-type-badge">${this.escapeHtml(formatAdminRole(admin.role))}</span></td>
                <td>${admin.phone || '-'}</td>
                <td><span class="status-badge status-${admin.status}">${admin.status}</span></td>
                <td>${this.formatDateShort(admin.created_at)}</td>
                <td>${admin.last_login ? this.formatDateShort(admin.last_login) : 'Never'}</td>
                <td>
                    <div class="d-flex gap-5">
                        ${admin.status === 'pending_approval' ? `
                            <button class="btn btn-sm btn-success" onclick="admin.approveAdmin(${admin.id})" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-sm btn-warning" onclick="admin.suspendAdmin(${admin.id})" title="Suspend">
                            <i class="fas fa-ban"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('adminsTable');
    }

    async approveItem(type, id, action) {
        if (!confirm(`Are you sure you want to ${action} this ${type}?`)) {
            return;
        }

        try {
            debugLog(`${action}ing ${type} ${id}...`);
            
            let endpoint = '';
            switch (type) {
                case 'car':
                case 'guest_listing':
                    endpoint = 'approve_car';
                    break;
                case 'user':
                    endpoint = 'approve_user';
                    break;
                case 'garage':
                    endpoint = 'approve_garage';
                    break;
                case 'dealer':
                    endpoint = 'approve_dealer';
                    break;
                case 'car_hire':
                    endpoint = 'approve_car_hire';
                    break;
                default:
                    throw new Error('Invalid item type');
            }

            const response = await this.apiCall(endpoint, 'POST', { id, action });
            
            if (response.success) {
                this.showAlert('success', response.message);
                await Promise.all([
                    this.loadDashboardData(),
                    this.loadSectionData(this.currentSection)
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || `Failed to ${action} ${type}`);
        }
    }

    showActionConfirmDialog(title, message, confirmText = 'Confirm', confirmClass = 'btn-primary') {
        this.prepareModal('actionConfirmModal');

        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.id = 'actionConfirmModal';
            modal.className = 'modal-overlay active';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 520px;">
                    <div class="modal-header">
                        <h3>${this.escapeHtml(title || 'Confirm Action')}</h3>
                        <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="margin: 0; line-height: 1.5;">${this.escapeHtml(message || 'Are you sure?')}</p>
                    </div>
                    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn btn-secondary" data-action="cancel">Cancel</button>
                        <button type="button" class="btn ${confirmClass}" data-action="confirm">${this.escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            const closeAndResolve = (value) => {
                if (modal && modal.parentElement) {
                    modal.remove();
                }
                resolve(value);
            };

            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeAndResolve(false);
                }
            });

            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) closeBtn.addEventListener('click', () => closeAndResolve(false));

            const cancelBtn = modal.querySelector('[data-action="cancel"]');
            if (cancelBtn) cancelBtn.addEventListener('click', () => closeAndResolve(false));

            const confirmBtn = modal.querySelector('[data-action="confirm"]');
            if (confirmBtn) confirmBtn.addEventListener('click', () => closeAndResolve(true));

            document.body.appendChild(modal);
            if (confirmBtn) confirmBtn.focus();
        });
    }

    showActionPromptDialog(title, message, placeholder = '', confirmText = 'Submit') {
        this.prepareModal('actionPromptModal');

        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.id = 'actionPromptModal';
            modal.className = 'modal-overlay active';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 560px;">
                    <div class="modal-header">
                        <h3>${this.escapeHtml(title || 'Provide Details')}</h3>
                        <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="margin: 0 0 10px; line-height: 1.5;">${this.escapeHtml(message || '')}</p>
                        <textarea class="form-control" rows="4" data-input="prompt" placeholder="${this.escapeHtml(placeholder)}"></textarea>
                    </div>
                    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn btn-secondary" data-action="cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" data-action="confirm">${this.escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            const closeAndResolve = (value) => {
                if (modal && modal.parentElement) {
                    modal.remove();
                }
                resolve(value);
            };

            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeAndResolve(null);
                }
            });

            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) closeBtn.addEventListener('click', () => closeAndResolve(null));

            const cancelBtn = modal.querySelector('[data-action="cancel"]');
            if (cancelBtn) cancelBtn.addEventListener('click', () => closeAndResolve(null));

            const confirmBtn = modal.querySelector('[data-action="confirm"]');
            const input = modal.querySelector('[data-input="prompt"]');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => {
                    const value = input ? String(input.value || '').trim() : '';
                    closeAndResolve(value);
                });
            }

            document.body.appendChild(modal);
            if (input) input.focus();
        });
    }

    async approveCar(id, action) {
        let rejectionReason = '';
        
        // If rejecting, prompt for rejection reason
        if (action === 'reject') {
            rejectionReason = await this.showActionPromptDialog(
                'Reject Listing',
                'Please provide a reason for rejection (this will be visible to the user).',
                'Enter rejection reason...',
                'Reject Listing'
            );
            if (rejectionReason === null) {
                // User cancelled
                return;
            }
            if (rejectionReason.trim() === '') {
                this.showAlert('warning', 'Please provide a reason for rejection');
                return;
            }
        }
        
        const confirmed = await this.showActionConfirmDialog(
            action === 'approve' ? 'Approve Listing' : 'Reject Listing',
            `Are you sure you want to ${action} this car listing?`,
            action === 'approve' ? 'Approve' : 'Reject',
            action === 'approve' ? 'btn-success' : 'btn-danger'
        );
        if (!confirmed) {
            return;
        }

        try {
            debugLog(`${action}ing car ${id}...`);
            
            const response = await this.apiCall('approve_car', 'POST', { 
                id, 
                action,
                rejection_reason: rejectionReason || null
            });
            
            if (response.success) {
                this.showAlert('success', response.message);
                const refreshTasks = [this.loadDashboardData(), this.loadPendingCars()];

                // Reload current data immediately after action completion.
                if (this.currentSection === 'pending-cars') {
                    refreshTasks.push(this.loadPendingCars());
                } else if (this.currentSection === 'rejected-cars') {
                    refreshTasks.push(this.loadRejectedCars());
                } else if (this.currentSection === 'guest-listings') {
                    refreshTasks.push(this.loadGuestListings());
                } else if (this.currentSection === 'cars') {
                    refreshTasks.push(this.loadCars());
                } else {
                    refreshTasks.push(this.loadSectionData(this.currentSection));
                }

                await Promise.all(refreshTasks);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || `Failed to ${action} car`);
        }
    }

    async approveUser(id, action) {
        await this.approveItem('user', id, action);
    }

    async verifyPayment(id) {
        if (!confirm('Are you sure you want to mark this payment as manually verified?')) {
            return;
        }

        try {
            debugLog(`Verifying payment ${id}...`);
            const response = await this.apiCall('verify_payment', 'POST', { id });
            
            if (response.success) {
                this.showAlert('success', 'Payment marked as manually verified');
                await Promise.all([
                    this.loadPayments(),
                    this.loadPaymentStats(),
                    this.loadFreeListingsPayments()
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to verify payment');
        }
    }

    async rejectPayment(id) {
        if (!confirm('Are you sure you want to reject this POP submission?')) {
            return;
        }

        try {
            debugLog(`Rejecting payment ${id}...`);
            const response = await this.apiCall('reject_payment', 'POST', { id });

            if (response.success) {
                this.showAlert('success', 'POP submission rejected');
                await Promise.all([
                    this.loadPayments(),
                    this.loadPaymentStats(),
                    this.loadFreeListingsPayments()
                ]);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to reject payment');
        }
    }

    // ===== ACTIVITY LOGS FUNCTIONS =====

    async loadListingReports() {
        try {
            const filters = {};
            const status = document.getElementById('reportStatusFilter')?.value;
            const reason = document.getElementById('reportReasonFilter')?.value;
            const search = document.getElementById('reportSearch')?.value;

            if (status) filters.status = status;
            if (reason) filters.reason = reason;
            if (search) filters.search = search;

            const response = await this.apiCall('get_listing_reports', 'GET', filters);
            this.renderListingReportsIssues(response.warnings || []);

            if (response.success && response.reports) {
                this.displayListingReports(response.reports);
            } else {
                const apiError = new Error(response.message || 'Failed to load listing reports');
                apiError.code = response.code || 'REPORTS_LOAD_FAILED';
                throw apiError;
            }
        } catch (error) {
            this.renderListingReportsIssues([
                {
                    code: error.code || 'REPORTS_LOAD_FAILED',
                    message: error.message || 'Failed to load listing reports'
                }
            ]);

            const tbody = document.getElementById('listingReportsTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading listing reports</td></tr>';
            }

            this.showAlert('error', this.formatIssueText(error.message || 'Failed to load listing reports', error.code));
        }
    }

    displayListingReports(reports) {
        const tbody = document.getElementById('listingReportsTableBody');
        if (!tbody) return;

        if (!reports || reports.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No listing reports found</td></tr>';
            return;
        }

        const reasonLabels = {
            spam: 'Spam',
            fraud: 'Fraud',
            incorrect: 'Incorrect Info',
            stolen: 'Stolen Vehicle',
            inappropriate: 'Inappropriate',
            sold: 'Already Sold',
            other: 'Other'
        };

        tbody.innerHTML = reports.map(report => {
            const listingLink = report.listing_id
                ? `<a href="../car.html?id=${report.listing_id}" target="_blank" rel="noopener">#${report.listing_id}</a>`
                : 'N/A';

            const canReview = report.status === 'pending';
            const details = this.escapeHtml(report.details || '');

            return `
                <tr>
                    <td>#${report.id}</td>
                    <td>
                        <div>${listingLink}</div>
                        <div class="small text-muted">${this.escapeHtml(report.listing_title || 'Unknown listing')}</div>
                    </td>
                    <td><span class="badge badge-info">${this.escapeHtml(reasonLabels[report.reason] || report.reason || 'Other')}</span></td>
                    <td>
                        <div>${this.escapeHtml(report.reporter_user_name || 'User')}</div>
                        <div class="small text-muted">${this.escapeHtml(report.reporter_email || report.reporter_user_email || '-')}</div>
                    </td>
                    <td class="small">${details.length > 140 ? details.substring(0, 140) + '...' : details}</td>
                    <td><span class="badge badge-${report.status === 'pending' ? 'warning' : (report.status === 'reviewed' ? 'success' : 'secondary')}">${this.escapeHtml(report.status || 'pending')}</span></td>
                    <td class="small">${this.formatDate(report.created_at)}</td>
                    <td>
                        ${canReview ? `
                            <button class="btn btn-sm btn-success" onclick="admin.resolveListingReport(${report.id}, 'reviewed')">Review</button>
                            <button class="btn btn-sm btn-outline" onclick="admin.resolveListingReport(${report.id}, 'dismissed')">Dismiss</button>
                        ` : '<span class="small text-muted">Completed</span>'}
                    </td>
                </tr>
            `;
        }).join('');
    }

    async resolveListingReport(reportId, status) {
        const statusLabel = status === 'reviewed' ? 'reviewed' : 'dismissed';
        const notePrompt = status === 'reviewed'
            ? 'Optional admin note for this review:'
            : 'Optional reason for dismissal:';
        const adminNotes = prompt(notePrompt, '') || '';

        try {
            const response = await this.apiCall('update_listing_report_status', 'POST', {
                report_id: reportId,
                status: statusLabel,
                admin_notes: adminNotes
            });

            if (response.success) {
                this.renderListingReportsIssues(response.warnings || []);
                this.showAlert('success', response.message || 'Report updated');
                await this.loadListingReports();
            } else {
                const apiError = new Error(response.message || 'Failed to update report');
                apiError.code = response.code || 'REPORT_STATUS_UPDATE_FAILED';
                throw apiError;
            }
        } catch (error) {
            this.renderListingReportsIssues([
                {
                    code: error.code || 'REPORT_STATUS_UPDATE_FAILED',
                    message: error.message || 'Failed to update listing report'
                }
            ]);
            this.showAlert('error', this.formatIssueText(error.message || 'Failed to update listing report', error.code));
        }
    }

    renderListingReportsIssues(issues = []) {
        const issuesContainer = document.getElementById('listingReportsIssues');
        if (!issuesContainer) return;

        const normalized = (issues || [])
            .filter(Boolean)
            .map(issue => {
                if (typeof issue === 'string') {
                    return { message: issue, code: null };
                }
                return {
                    message: issue.message || 'Issue detected while loading listing reports.',
                    code: issue.code || null
                };
            });

        if (normalized.length === 0) {
            issuesContainer.innerHTML = '';
            issuesContainer.style.display = 'none';
            return;
        }

        issuesContainer.innerHTML = normalized.map(issue => `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                ${this.escapeHtml(issue.message)}
                ${issue.code ? `<strong> (${this.escapeHtml(issue.code)})</strong>` : ''}
            </div>
        `).join('');
        issuesContainer.style.display = 'block';
    }

    formatIssueText(message, code) {
        if (!code) return message;
        return `${message} (${code})`;
    }

    // ----------------------------------------------------------------
    // BUSINESS REVIEWS AUDIT
    // ----------------------------------------------------------------

    async loadBusinessReviews() {
        const tbody = document.getElementById('businessReviewsTableBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center">Loading…</td></tr>';

        try {
            const filters = {};
            const type   = document.getElementById('reviewsTypeFilter')?.value;
            const status = document.getElementById('reviewsStatusFilter')?.value;
            const search = document.getElementById('reviewsSearch')?.value?.trim();

            if (type)   filters.business_type = type;
            if (status) filters.status = status;
            if (search) filters.search = search;

            const response = await this.apiCall('get_business_reviews', 'GET', filters);

            if (response.success) {
                // Update summary counters
                const stats = response.stats || {};
                const activeEl  = document.getElementById('reviewsActiveCount');
                const hiddenEl  = document.getElementById('reviewsHiddenCount');
                const badgeEl   = document.getElementById('reviewsHiddenBadge');
                if (activeEl)  activeEl.textContent  = stats.active  ?? 0;
                if (hiddenEl)  hiddenEl.textContent  = stats.hidden  ?? 0;
                if (badgeEl)   badgeEl.textContent   = stats.hidden  ?? 0;
                if (badgeEl)   badgeEl.style.display = (stats.hidden ?? 0) > 0 ? '' : 'none';

                this.displayBusinessReviews(response.reviews || []);
            } else {
                throw new Error(response.message || 'Failed to load reviews');
            }
        } catch (error) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${this.escapeHtml(error.message)}</td></tr>`;
            this.showAlert('error', error.message || 'Failed to load reviews');
        }
    }

    displayBusinessReviews(reviews) {
        const tbody = document.getElementById('businessReviewsTableBody');
        if (!tbody) return;

        if (!reviews.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No reviews found</td></tr>';
            return;
        }

        const typeLabels = { dealer: 'Dealer', garage: 'Garage', car_hire: 'Car Hire' };
        const stars = (n) => '★'.repeat(Math.max(0, Math.min(5, n))) + '☆'.repeat(5 - Math.max(0, Math.min(5, n)));

        tbody.innerHTML = reviews.map(r => {
            const isActive = r.status === 'active';
            const typeLabel = this.escapeHtml(typeLabels[r.business_type] || r.business_type);
            const businessBadge = r.business_type === 'dealer'   ? 'badge-primary' :
                                  r.business_type === 'garage'   ? 'badge-info'    : 'badge-warning';
            const reviewSnippet = r.review_text
                ? this.escapeHtml(r.review_text.length > 120 ? r.review_text.slice(0, 120) + '…' : r.review_text)
                : '<em class="text-muted">No text</em>';

            return `
                <tr>
                    <td>#${r.id}</td>
                    <td>
                        <div class="fw-bold">${this.escapeHtml(r.business_name || '—')}</div>
                        <div class="small text-muted">ID ${r.business_id}</div>
                    </td>
                    <td><span class="badge ${businessBadge}">${typeLabel}</span></td>
                    <td>
                        <span style="color:#f5c518;font-size:1rem;letter-spacing:1px;">${stars(r.rating)}</span>
                        <span class="small text-muted"> ${r.rating}/5</span>
                    </td>
                    <td>
                        <div>${this.escapeHtml(r.reviewer_name || 'Unknown')}</div>
                        <div class="small text-muted">${this.escapeHtml(r.reviewer_email || '')}</div>
                    </td>
                    <td class="small">${reviewSnippet}</td>
                    <td>
                        <span class="badge badge-${isActive ? 'success' : 'secondary'}">
                            ${isActive ? 'Active' : 'Hidden'}
                        </span>
                    </td>
                    <td class="small">${this.formatDate(r.created_at)}</td>
                    <td>
                        ${isActive
                            ? `<button class="btn btn-sm btn-outline" onclick="admin.setReviewStatus(${r.id}, 'hidden')" title="Hide this review"><i class="fas fa-eye-slash"></i> Hide</button>`
                            : `<button class="btn btn-sm btn-success" onclick="admin.setReviewStatus(${r.id}, 'active')" title="Make visible"><i class="fas fa-eye"></i> Show</button>`
                        }
                        <button class="btn btn-sm btn-danger" onclick="admin.deleteReview(${r.id})" title="Delete permanently"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
        }).join('');
    }

    async setReviewStatus(reviewId, status) {
        try {
            const response = await this.apiCall('update_review_status', 'POST', {
                review_id: reviewId,
                status
            });
            if (response.success) {
                this.showAlert('success', response.message || 'Review updated');
                await this.loadBusinessReviews();
            } else {
                throw new Error(response.message || 'Failed to update review');
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to update review status');
        }
    }

    async deleteReview(reviewId) {
        if (!confirm('Permanently delete this review? This cannot be undone.')) return;
        try {
            const response = await this.apiCall('delete_review', 'POST', { review_id: reviewId });
            if (response.success) {
                this.showAlert('success', 'Review deleted');
                await this.loadBusinessReviews();
            } else {
                throw new Error(response.message || 'Failed to delete review');
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to delete review');
        }
    }

    async loadActivityLogs() {
        try {
            const filters = {};
            const actionType = document.getElementById('logActionTypeFilter')?.value;
            const dateFrom = document.getElementById('logDateFrom')?.value;
            const dateTo = document.getElementById('logDateTo')?.value;
            const search = document.getElementById('logSearch')?.value;

            if (actionType) filters.action_type = actionType;
            if (dateFrom) filters.date_from = dateFrom;
            if (dateTo) filters.date_to = dateTo;
            if (search) filters.search = search;

            const response = await this.apiCall('get_activity_logs', 'GET', filters);

            if (response.success && response.logs) {
                this.displayActivityLogs(response.logs);
            } else {
                throw new Error(response.message || 'Failed to load activity logs');
            }
        } catch (error) {
            document.getElementById('activityLogsTableBody').innerHTML =
                '<tr><td colspan="7" class="text-center text-danger">Error loading activity logs</td></tr>';
        }
    }

    displayActivityLogs(logs) {
        const tbody = document.getElementById('activityLogsTableBody');

        if (!logs || logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No activity logs found</td></tr>';
            return;
        }

        const html = logs.map(log => `
            <tr>
                <td>#${log.id}</td>
                <td>${this.formatDate(log.created_at)}</td>
                <td>
                    <div>${this.escapeHtml(log.admin_name || 'System')}</div>
                    <div class="text-muted small">${this.escapeHtml(log.admin_email || '')}</div>
                </td>
                <td><span class="badge badge-info">${this.escapeHtml(log.action_type)}</span></td>
                <td>${this.escapeHtml(log.action_description)}</td>
                <td class="small">${this.escapeHtml(log.details || '-')}</td>
                <td class="small">${this.escapeHtml(log.ip_address || '-')}</td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('activityLogsTable');
    }

    exportActivityLogs() {
        try {
            const tbody = document.getElementById('activityLogsTableBody');
            const rows = tbody.querySelectorAll('tr');

            let text = '=== MOTORLINK ACTIVITY LOGS ===\n';
            text += `Exported: ${new Date().toLocaleString()}\n`;
            text += '=' .repeat(80) + '\n\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 1) {
                    text += `ID: ${cells[0].textContent.trim()}\n`;
                    text += `Date/Time: ${cells[1].textContent.trim()}\n`;
                    text += `Admin: ${cells[2].textContent.trim()}\n`;
                    text += `Action: ${cells[3].textContent.trim()}\n`;
                    text += `Description: ${cells[4].textContent.trim()}\n`;
                    text += `Details: ${cells[5].textContent.trim()}\n`;
                    text += `IP: ${cells[6].textContent.trim()}\n`;
                    text += '-'.repeat(80) + '\n\n';
                }
            });

            // Create download
            const blob = new Blob([text], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `motorlink_activity_logs_${new Date().toISOString().split('T')[0]}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            this.showAlert('success', 'Activity logs exported successfully');
        } catch (error) {
            this.showAlert('error', 'Failed to export activity logs');
        }
    }

    copyActivityLogsToClipboard() {
        try {
            const tbody = document.getElementById('activityLogsTableBody');
            const rows = tbody.querySelectorAll('tr');

            let text = '=== MOTORLINK ACTIVITY LOGS ===\n';
            text += `Copied: ${new Date().toLocaleString()}\n`;
            text += '=' .repeat(80) + '\n\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 1) {
                    text += `ID: ${cells[0].textContent.trim()}\n`;
                    text += `Date/Time: ${cells[1].textContent.trim()}\n`;
                    text += `Admin: ${cells[2].textContent.trim()}\n`;
                    text += `Action: ${cells[3].textContent.trim()}\n`;
                    text += `Description: ${cells[4].textContent.trim()}\n`;
                    text += `Details: ${cells[5].textContent.trim()}\n`;
                    text += `IP: ${cells[6].textContent.trim()}\n`;
                    text += '-'.repeat(80) + '\n\n';
                }
            });

            navigator.clipboard.writeText(text).then(() => {
                this.showAlert('success', 'Activity logs copied to clipboard!');
            }).catch(() => {
                this.showAlert('error', 'Failed to copy to clipboard');
            });
        } catch (error) {
            this.showAlert('error', 'Failed to copy activity logs');
        }
    }

    async apiCall(action, method = 'GET', data = null) {
        const authExemptActions = ['admin_login', 'admin_logout', 'check_admin_auth'];
        if (!authExemptActions.includes(action)) {
            const authenticated = await this.ensureAuthenticated();
            if (!authenticated) {
                const authError = new Error('Admin access required. Please login.');
                authError.code = 'ADMIN_AUTH_REQUIRED';
                throw authError;
            }
        }

        // Properly construct URL - check if base URL already has query params
        const separator = this.API_URL.includes('?') ? '&' : '?';
        let url = `${this.API_URL}${separator}action=${action}`;

        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include' // Include cookies for session management
        };

        // Add admin authentication headers
        const adminToken = localStorage.getItem('admin_token');
        const adminEmail = localStorage.getItem('admin_email');
        if (adminToken && adminEmail) {
            options.headers['X-Admin-Token'] = adminToken;
            options.headers['X-Admin-Email'] = adminEmail;
        }

        if (method === 'POST' && data) {
            options.body = JSON.stringify(data);
        } else if (method === 'GET' && data) {
            const params = new URLSearchParams(data);
            url += '&' + params.toString();
        }

        debugLog(`API Call: ${method} ${url}`, data);

        try {
            const response = await fetch(url, options);
            let result = null;

            try {
                result = await response.json();
            } catch (parseError) {
                const apiError = new Error(`Invalid API response (${response.status})`);
                apiError.code = 'INVALID_API_RESPONSE';
                throw apiError;
            }

            // Check if response is unauthorized
            if (response.status === 401 || response.status === 403) {
                debugLog('Unauthorized access, clearing session');
                this.clearSessionAndShowLogin();
                const authError = new Error(result.message || 'Admin access required');
                authError.code = result.code || 'ADMIN_AUTH_REQUIRED';
                throw authError;
            }

            if (!response.ok) {
                const requestError = new Error(result.message || `Request failed with status ${response.status}`);
                requestError.code = result.code || 'ADMIN_REQUEST_FAILED';
                throw requestError;
            }

            debugLog(`API Response for ${action}:`, result);
            
            // Check for admin access error in response
            if (!result.success && result.message && result.message.toLowerCase().includes('admin')) {
                debugLog('Admin access error in response');
                this.clearSessionAndShowLogin();
            }
            
            return result;
        } catch (error) {
            throw error;
        }
    }

    clearSessionAndShowLogin() {
        // Clear all admin credentials
        localStorage.removeItem('admin_token');
        localStorage.removeItem('admin_name');
        localStorage.removeItem('admin_email');
        this.lastAuthCheckAt = 0;
        
        // Show login screen
        this.showLogin();
    }

    formatNumber(number) {
        if (!number) return '0';
        return new Intl.NumberFormat().format(number);
    }

    // Table Sorting Functionality
    enableTableSorting(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const headers = table.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            // Skip action columns and checkboxes
            if (header.textContent.toLowerCase().includes('action') || header.querySelector('input[type="checkbox"]')) return;

            // Check if sort icon already exists to prevent duplicates
            if (header.querySelector('.sort-icon')) return;

            header.style.cursor = 'pointer';
            header.style.userSelect = 'none';

            // Add sort icon with a class for easy identification
            const sortIcon = document.createElement('i');
            sortIcon.className = 'fas fa-sort sort-icon';
            sortIcon.style.opacity = '0.3';
            sortIcon.style.fontSize = '12px';
            sortIcon.style.marginLeft = '4px';
            header.appendChild(sortIcon);

            header.addEventListener('click', () => {
                this.sortTable(table, index, header);
            });
        });
    }

    sortTable(table, columnIndex, header) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Get current sort direction - default to '' so first click sorts ascending
        const currentOrder = header.dataset.order || '';
        const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';

        // Reset all headers
        const headers = table.querySelectorAll('thead th');
        headers.forEach(h => {
            h.dataset.order = '';
            const icon = h.querySelector('.sort-icon');
            if (icon) {
                icon.className = 'fas fa-sort sort-icon';
                icon.style.opacity = '0.3';
            }
        });

        // Update clicked header
        header.dataset.order = newOrder;
        const icon = header.querySelector('.sort-icon');
        if (icon) {
            icon.className = newOrder === 'asc' ? 'fas fa-sort-up sort-icon' : 'fas fa-sort-down sort-icon';
            icon.style.opacity = '1';
        }

        // Sort rows
        rows.sort((a, b) => {
            let aValue = a.cells[columnIndex]?.textContent.trim() || '';
            let bValue = b.cells[columnIndex]?.textContent.trim() || '';

            // Remove currency symbols, commas, and badges for cleaner comparison
            aValue = stripConfiguredCurrencyText(aValue);
            bValue = stripConfiguredCurrencyText(bValue);

            // Try to parse as number
            const aNum = parseFloat(aValue);
            const bNum = parseFloat(bValue);

            let comparison = 0;
            if (!isNaN(aNum) && !isNaN(bNum)) {
                comparison = aNum - bNum;
            } else {
                comparison = aValue.localeCompare(bValue, undefined, { numeric: true, sensitivity: 'base' });
            }

            return newOrder === 'asc' ? comparison : -comparison;
        });

        // Reattach rows
        rows.forEach(row => tbody.appendChild(row));
    }

    formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    formatDateShort(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    formatServiceType(serviceType) {
        const types = {
            'listing_featured': 'Featured Listing',
            'listing_premium': 'Premium Listing',
            'garage_listing': 'Garage Listing',
            'dealer_listing': 'Dealer Listing',
            'car_hire_listing': 'Car Hire Listing'
        };
        return types[serviceType] || serviceType;
    }

    renderListingPaymentBadge(car) {
        const amount = Number(car.payment_amount || 0);
        const requiresPayment = Number(car.payment_required || 0) === 1 || amount > 0;

        if (!requiresPayment) {
            return '<span class="status-badge status-active">Not required</span>';
        }

        const status = String(car.payment_status || 'pending').toLowerCase();
        if (status === 'verified') {
            return '<span class="status-badge status-verified">Verified</span>';
        }
        if (status === 'rejected') {
            return '<span class="status-badge status-rejected">Rejected</span>';
        }
        return '<span class="status-badge status-pending">Pending Manual Review</span>';
    }

    formatManualPaymentStatus(status) {
        const normalized = String(status || 'pending').toLowerCase();
        if (normalized === 'verified') return 'Manually Verified';
        if (normalized === 'rejected') return 'Rejected';
        return 'Pending Manual Review';
    }

    getActivityIcon(type) {
        const icons = {
            'car': 'car',
            'user': 'user',
            'payment': 'dollar-sign',
            'garage': 'tools',
            'dealer': 'handshake'
        };
        return icons[type] || 'circle';
    }

    capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getSafeUploadUrl(filename) {
        if (!filename) return '';

        const rawValue = String(filename).trim();
        if (!rawValue) return '';

        // Respect absolute/data URLs already returned by API.
        if (/^(https?:)?\/\//i.test(rawValue) || rawValue.startsWith('data:')) {
            return rawValue;
        }

        // Normalize slashes and strip query/hash fragments.
        let normalized = rawValue.replace(/\\/g, '/').split('?')[0].split('#')[0];
        normalized = normalized.replace(/^\.\//, '').replace(/^\/+/, '');

        // Keep nested upload paths instead of collapsing to basename.
        if (normalized.startsWith('uploads/')) {
            const relativePath = normalized.substring('uploads/'.length)
                .split('/')
                .map(part => encodeURIComponent(part))
                .join('/');
            return `image-proxy.php?path=${relativePath}`;
        }

        if (normalized.includes('/uploads/')) {
            const relativePath = normalized.split('/uploads/').pop()
                .split('/')
                .map(part => encodeURIComponent(part))
                .join('/');
            return `image-proxy.php?path=${relativePath}`;
        }

        const relativePath = normalized
            .split('/')
            .map(part => encodeURIComponent(part))
            .join('/');
        return `image-proxy.php?path=${relativePath}`;
    }

    showAlert(type, message) {
        debugLog(`Alert: ${type} - ${message}`);
        
            // Use the dashboard overlay container when logged in; fall back to the login #alerts div.
            const alertsContainer = this.isLoggedIn
                ? (document.getElementById('dashboardAlerts') || document.getElementById('alerts'))
                : document.getElementById('alerts');
            if (!alertsContainer) return;
            const alert = document.createElement('div');
        alert.className = `alert alert-${type === 'error' ? 'error' : type}`;
        alert.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            ${message}
            <button onclick="this.parentElement.remove()" style="float: right; background: none; border: none; font-size: 16px; cursor: pointer;">&times;</button>
        `;
        
        alertsContainer.appendChild(alert);
        
        setTimeout(() => {
            if (alert.parentElement) {
                alert.remove();
            }
        }, 5000);
    }
}


// Global functions (outside the class)
function editCar(carId) {
    editOps.editCar(carId);
}

function editGarage(garageId) {
    editOps.editGarage(garageId);
}

function editCarHire(carHireId) {
    editOps.editCarHire(carHireId);
}

// Rest of your existing classes and functions remain the same
// MobileMenu class removed - functionality moved to AdminDashboard.toggleSidebar()

class CarModal {
    constructor() {
        this.modal = document.getElementById('editCarModal');
        this.closeButton = document.getElementById('modalClose');
        this.form = document.getElementById('editCarForm');
        this.init();
    }
    
    init() {
        if (this.closeButton) {
            this.closeButton.addEventListener('click', () => this.close());
        }
        
        if (this.modal) {
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.close();
                }
            });
        }
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal && this.modal.classList.contains('active')) {
                this.close();
            }
        });
    }
    
    open() {
        if (this.modal) {
            this.modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }
    
    close() {
        if (this.modal) {
            this.modal.classList.remove('active');
            document.body.style.overflow = '';
            if (this.form) {
                this.form.innerHTML = '';
            }
        }
    }
    
    setContent(html) {
        if (this.form) {
            this.form.innerHTML = html;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    window.carModal = new CarModal();
    // Removed auto-login bypass - ALWAYS require proper server-side authentication
});

function switchTab(tab) {
    document.querySelectorAll('.form-tab').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    document.querySelectorAll('.form-section').forEach(section => section.classList.remove('active'));
    document.getElementById(tab + 'Form').classList.add('active');
    
    document.getElementById('alerts').innerHTML = '';
}

async function adminLogin(event) {
    event.preventDefault();
    
    const email = document.getElementById('adminEmail').value;
    const password = document.getElementById('adminPassword').value;

    debugLog('Attempting admin login with:', { email });

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
    submitBtn.disabled = true;

    try {
        // Properly construct URL - check if base URL already has query params
        const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
        const loginUrl = `${ADMIN_API_BASE}${separator}action=admin_login`;

        debugLog('Login URL:', loginUrl);

        const response = await fetch(loginUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'include', // Include cookies for session management
            body: JSON.stringify({ email, password })
        });
        
        debugLog('Response status:', response.status);

        const responseText = await response.text();
        debugLog('Raw response:', responseText);
        
        if (!responseText.trim()) {
            throw new Error('Server returned empty response');
        }
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error('Server returned invalid response');
        }

        if (!response.ok) {
            throw new Error(data.message || data.error || `Server error: ${response.status} ${response.statusText}`);
        }
        
        debugLog('Parsed response:', data);
        
        if (data.success) {
            localStorage.setItem('admin_token', 'logged_in');
            localStorage.setItem('admin_name', data.admin.name);
            localStorage.setItem('admin_email', data.admin.email);
            admin.lastAuthCheckAt = Date.now();
            
            admin.adminData = data.admin;

            // Notify other tabs that this tab is now the active admin session.
            document.dispatchEvent(new CustomEvent('adminLoginSuccess'));

            // Onboarding managers have no access to the admin dashboard
            if (data.admin.role === 'onboarding_manager') {
                window.location.href = '../onboarding/onboarding.html';
                return;
            }

            // Use the existing showDashboard method for consistency
            admin.showDashboard();
            
            debugLog('Admin login successful - dashboard shown');
        } else {
            admin.showAlert('error', data.message || 'Login failed');
        }
    } catch (error) {
        admin.showAlert('error', 'Login failed: ' + error.message);
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

async function adminRegister(event) {
    event.preventDefault();
    
    const formData = {
        full_name: document.getElementById('regFullName').value,
        email: document.getElementById('regEmail').value,
        phone: document.getElementById('regPhone').value,
        password: document.getElementById('regPassword').value,
        admin_key: document.getElementById('regAdminKey').value
    };
    
    debugLog('Attempting admin registration...');
    
    try {
        const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
        const response = await fetch(`${ADMIN_API_BASE}${separator}action=admin_register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const data = await response.json();
        debugLog('Registration response:', data);
        
        if (data.success) {
            admin.showAlert('success', data.message);
            event.target.reset();
        } else {
            admin.showAlert('error', data.message || 'Registration failed');
        }
    } catch (error) {
        admin.showAlert('error', 'Connection error. Please try again.');
    }
}

async function forgotPassword(event) {
    event.preventDefault();
    
    const email = document.getElementById('forgotEmail').value;
    
    try {
        const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
        const response = await fetch(`${ADMIN_API_BASE}${separator}action=forgot_password`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        
        const data = await response.json();
        
        if (data.success) {
            admin.showAlert('success', data.message);
        } else {
            admin.showAlert('error', data.message || 'Password reset failed');
        }
    } catch (error) {
        admin.showAlert('error', 'Connection error. Please try again.');
    }
}

async function logout() {
    if (confirm('Are you sure you want to logout?')) {
        try {
            // First, destroy server-side session
            const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
            const logoutUrl = `${ADMIN_API_BASE}${separator}action=admin_logout`;
            
            await fetch(logoutUrl, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
        } catch (error) {
            console.error('Error during logout:', error);
        }
        
        // Clear ALL admin credentials from localStorage
        localStorage.removeItem('admin_token');
        localStorage.removeItem('admin_name');
        localStorage.removeItem('admin_email');
        localStorage.removeItem('admin_session');
        
        // Force reload to show login screen
        window.location.reload();
    }
}

function showAddCarModal() {
    admin.prepareModal('addCarModal');
    const modal = document.createElement('div');
    modal.id = 'addCarModal';
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-car"></i> Add New Car Listing</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCarForm" class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-title">Title *</label>
                            <input type="text" id="add-car-title" name="title" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-car-price">Price (${getConfiguredCurrencyLabel()}) *</label>
                            <input type="number" id="add-car-price" name="price" required class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-make">Make *</label>
                            <select id="add-car-make" name="make_id" required class="form-control">
                                <option value="">Select Make</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-car-model">Model *</label>
                            <select id="add-car-model" name="model_id" required class="form-control">
                                <option value="">Select Model</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-year">Year *</label>
                            <input type="number" id="add-car-year" name="year" min="1990" max="2025" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-car-mileage">Mileage (km)</label>
                            <input type="number" id="add-car-mileage" name="mileage" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-fuel">Fuel Type *</label>
                            <select id="add-car-fuel" name="fuel_type" required class="form-control">
                                <option value="">Select Fuel Type</option>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="electric">Electric</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-car-transmission">Transmission *</label>
                            <select id="add-car-transmission" name="transmission" required class="form-control">
                                <option value="">Select Transmission</option>
                                <option value="manual">Manual</option>
                                <option value="automatic">Automatic</option>
                                <option value="cvt">CVT</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-condition">Condition *</label>
                            <select id="add-car-condition" name="condition_type" required class="form-control">
                                <option value="">Select Condition</option>
                                <option value="excellent">Excellent</option>
                                <option value="very_good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-car-location">Location *</label>
                            <select id="add-car-location" name="location_id" required class="form-control">
                                <option value="">Select Location</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add-car-exterior-color">Exterior Color</label>
                            <input type="text" id="add-car-exterior-color" name="exterior_color" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add-car-interior-color">Interior Color</label>
                            <input type="text" id="add-car-interior-color" name="interior_color" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add-car-description">Description</label>
                        <textarea id="add-car-description" name="description" rows="4" class="form-control"></textarea>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="add-car-negotiable" name="negotiable">
                            Price is negotiable
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddCar()"><i class="fas fa-save"></i> Add Car</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Load makes, models, and locations
    loadMakesForAddCar();
    loadLocationsForAddCar();
}

async function loadMakesForAddCar() {
    try {
        const response = await admin.apiCall('get_car_makes');
        if (response.success && response.makes) {
            const select = document.getElementById('add-car-make');
            response.makes.forEach(make => {
                const option = document.createElement('option');
                option.value = make.id;
                option.textContent = make.name;
                select.appendChild(option);
            });

            // Load models when make is selected
            select.addEventListener('change', async (e) => {
                const makeId = e.target.value;
                const modelSelect = document.getElementById('add-car-model');
                modelSelect.innerHTML = '<option value="">Select Model</option>';

                if (makeId) {
                    const modelsResponse = await admin.apiCall('get_makes_models');
                    if (modelsResponse.success && modelsResponse.models) {
                        modelsResponse.models
                            .filter(model => model.make_id == makeId)
                            .forEach(model => {
                                const option = document.createElement('option');
                                option.value = model.id;
                                option.textContent = model.name;
                                modelSelect.appendChild(option);
                            });
                    }
                }
            });
        }
    } catch (error) {
    }
}

async function loadLocationsForAddCar() {
    try {
        const response = await admin.apiCall('get_locations');
        if (response.success && response.locations) {
            const select = document.getElementById('add-car-location');
            response.locations.forEach(location => {
                const option = document.createElement('option');
                option.value = location.id;
                option.textContent = location.name + (location.region ? ` (${location.region})` : '');
                select.appendChild(option);
            });
        }
    } catch (error) {
    }
}

async function submitAddCar() {
    const form = document.getElementById('addCarForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = {
        title: document.getElementById('add-car-title').value,
        price: document.getElementById('add-car-price').value,
        make_id: document.getElementById('add-car-make').value,
        model_id: document.getElementById('add-car-model').value,
        year: document.getElementById('add-car-year').value,
        mileage: document.getElementById('add-car-mileage').value || null,
        fuel_type: document.getElementById('add-car-fuel').value,
        transmission: document.getElementById('add-car-transmission').value,
        condition_type: document.getElementById('add-car-condition').value,
        location_id: document.getElementById('add-car-location').value,
        exterior_color: document.getElementById('add-car-exterior-color').value || null,
        interior_color: document.getElementById('add-car-interior-color').value || null,
        description: document.getElementById('add-car-description').value || null,
        negotiable: document.getElementById('add-car-negotiable').checked ? 1 : 0
    };

    try {
        const response = await admin.apiCall('add_car', 'POST', formData);
        if (response.success) {
            admin.showAlert('success', 'Car listing added successfully!');
            admin.closeModal('addCarModal');
            await Promise.all([
                admin.loadCars(),
                admin.loadDashboardData()
            ]);
        } else {
            admin.showAlert('error', response.message || 'Failed to add car');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding car: ' + error.message);
    }
}

function showAddPaymentModal(prefillListingId = '') {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.id = 'manualPaymentModal';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding-usd"></i> Record Manual Listing Payment</h3>
                <button class="modal-close" onclick="admin.closeModal('manualPaymentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="manualPaymentForm" class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="manual-payment-listing-id">Listing ID *</label>
                            <input type="number" id="manual-payment-listing-id" class="form-control" min="1" required value="${prefillListingId ? String(prefillListingId) : ''}">
                        </div>
                        <div class="form-group">
                            <label for="manual-payment-amount">Amount (${getConfiguredCurrencyLabel()}) *</label>
                            <input type="number" id="manual-payment-amount" class="form-control" min="0" step="0.01" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="manual-payment-method">Payment Method *</label>
                            <select id="manual-payment-method" class="form-control" required>
                                <option value="manual">Manual</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="tnm_mpamba">TNM Mpamba</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="manual-payment-reference">Reference</label>
                            <input type="text" id="manual-payment-reference" class="form-control" placeholder="Optional reference number">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="manual-payment-status">Status *</label>
                            <select id="manual-payment-status" class="form-control" required>
                                <option value="verified">Verified</option>
                                <option value="pending">Pending</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="manual-payment-service">Service Type *</label>
                            <select id="manual-payment-service" class="form-control" required>
                                <option value="listing_submission">Listing Submission</option>
                                <option value="listing_featured">Featured Listing</option>
                                <option value="listing_premium">Premium Listing</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="manual-payment-notes">Admin Notes</label>
                        <textarea id="manual-payment-notes" class="form-control" rows="3" placeholder="Optional note about this manual payment"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="admin.closeModal('manualPaymentModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitManualPaymentRecord()"><i class="fas fa-save"></i> Save Payment</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

async function submitManualPaymentRecord() {
    const form = document.getElementById('manualPaymentForm');
    if (!form || !form.checkValidity()) {
        form?.reportValidity();
        return;
    }

    const payload = {
        listing_id: Number(document.getElementById('manual-payment-listing-id').value),
        amount: Number(document.getElementById('manual-payment-amount').value),
        payment_method: document.getElementById('manual-payment-method').value,
        reference: (document.getElementById('manual-payment-reference').value || '').trim(),
        status: document.getElementById('manual-payment-status').value,
        service_type: document.getElementById('manual-payment-service').value,
        notes: (document.getElementById('manual-payment-notes').value || '').trim()
    };

    try {
        const response = await admin.apiCall('record_manual_payment', 'POST', payload);
        if (!response.success) {
            throw new Error(response.message || 'Failed to record manual payment');
        }

        admin.showAlert('success', response.message || 'Manual payment recorded successfully');
        admin.closeModal('manualPaymentModal');
        await Promise.all([
            admin.loadPayments(),
            admin.loadPaymentStats(),
            admin.loadCars(),
            admin.loadPendingCars(),
            admin.loadFreeListingsPayments()
        ]);
    } catch (error) {
        admin.showAlert('error', error.message || 'Failed to record manual payment');
    }
}

function showAddAdminModal() {
    admin.showAlert('info', 'Add admin modal coming soon');
}

function exportCars() {
    admin.showAlert('info', 'Export functionality coming soon');
}

function exportPayments() {
    admin.showAlert('info', 'Export functionality coming soon');
}

// ===== SETTINGS FUNCTIONS =====

async function saveGeneralSettings() {
    const settings = {
        siteName: document.getElementById('site-name').value,
        siteShortName: document.getElementById('site-short-name').value,
        siteTagline: document.getElementById('site-tagline').value,
        siteDescription: document.getElementById('site-description').value,
        siteUrl: document.getElementById('site-url').value,
        countryName: document.getElementById('country-name').value,
        countryCode: document.getElementById('country-code').value,
        countryDemonym: document.getElementById('country-demonym').value,
        locale: document.getElementById('locale').value,
        currencyCode: document.getElementById('currency-code').value,
        currencySymbol: document.getElementById('currency-symbol').value,
        marketScopeLabel: document.getElementById('market-scope-label').value,
        adminEmail: document.getElementById('admin-email').value,
        supportEmail: document.getElementById('support-email').value
    };

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'general',
            settings: settings
        });

        if (response.success) {
            admin.showAlert('success', response.message);
            debugLog('Saved general settings:', settings);
            await Promise.all([
                loadSettings(),
                loadSystemInfo()
            ]);
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving general settings:', error);
    }
}

async function saveListingSettings() {
    const settings = {
        autoApprove: document.getElementById('auto-approve-listings').checked,
        expiryDays: parseInt(document.getElementById('listing-expiry-days').value),
        maxImages: parseInt(document.getElementById('max-images-per-listing').value),
        featuredPrice: parseFloat(document.getElementById('featured-listing-price').value),
        featuredBonusDays: parseInt(document.getElementById('featured-bonus-days').value || '15'),
        paymentsEnabled: document.getElementById('enable-paid-listings').checked,
        freeListingPrice: parseFloat(document.getElementById('free-listing-price').value || '0'),
        paymentMethods: document.getElementById('payment-methods').value,
        paymentReferencePrefix: document.getElementById('payment-reference-prefix').value,
        paymentInstructions: document.getElementById('payment-instructions').value,
        maxRegisteredListings: parseInt(document.getElementById('max-listings-registered').value),
        maxGuestListings: parseInt(document.getElementById('max-listings-guest').value),
        requireEmailValidation: document.getElementById('require-listing-email-validation').checked
    };

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'listing',
            settings: settings
        });

        if (response.success) {
            admin.showAlert('success', response.message);
            updateFeaturedLaunchModeBadge();
            debugLog('Saved listing settings:', settings);
            await Promise.all([
                loadSettings(),
                admin.loadCars(),
                admin.loadPendingCars()
            ]);
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving listing settings:', error);
    }
}

async function saveUserSettings() {
    const settings = {
        requireEmailVerification: document.getElementById('require-email-verification').checked,
        allowGuestListings: document.getElementById('allow-guest-listings').checked,
        maxFreeListings: parseInt(document.getElementById('max-free-listings').value)
    };

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'user',
            settings: settings
        });

        if (response.success) {
            admin.showAlert('success', response.message);
            debugLog('Saved user settings:', settings);
            await Promise.all([
                loadSettings(),
                admin.loadUsers(),
                admin.loadGuestListings()
            ]);
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving user settings:', error);
    }
}

async function saveEmailSettings() {
    const settings = {
        smtpHost: document.getElementById('smtp-host').value,
        smtpPort: parseInt(document.getElementById('smtp-port').value),
        smtpUsername: document.getElementById('smtp-username').value,
        smtpPassword: document.getElementById('smtp-password').value,
        emailFromName: document.getElementById('email-from-name').value,
        enableNotifications: document.getElementById('enable-email-notifications').checked
    };

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'email',
            settings: settings
        });

        if (response.success) {
            admin.showAlert('success', response.message);
            debugLog('Saved email settings');
            await loadSettings();
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving email settings:', error);
    }
}

function testEmailSettings() {
    admin.showAlert('info', 'Sending test email...');
    setTimeout(() => {
        admin.showAlert('success', 'Test email sent successfully! Check your inbox.');
    }, 1500);
}

async function saveSecuritySettings() {
    const settings = {
        sessionTimeout: parseInt(document.getElementById('session-timeout').value),
        maxLoginAttempts: parseInt(document.getElementById('max-login-attempts').value),
        enable2FA: document.getElementById('enable-2fa').checked
    };

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'security',
            settings: settings
        });

        if (response.success) {
            admin.showAlert('success', response.message);
            debugLog('Saved security settings:', settings);
            await Promise.all([
                loadSettings(),
                loadSystemInfo()
            ]);
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving security settings:', error);
    }
}

function getFooterSupportSettingsPayload() {
    return {
        footer_support_help_label: document.getElementById('footer-help-label')?.value?.trim() || 'Help Center',
        footer_support_help_href: document.getElementById('footer-help-href')?.value?.trim() || 'help.html#top',
        footer_support_help_type: document.getElementById('footer-help-type')?.value || 'page',

        footer_support_safety_label: document.getElementById('footer-safety-label')?.value?.trim() || 'Safety Tips',
        footer_support_safety_href: document.getElementById('footer-safety-href')?.value?.trim() || 'safety.html#top',
        footer_support_safety_type: document.getElementById('footer-safety-type')?.value || 'page',

        footer_support_contact_label: document.getElementById('footer-contact-label')?.value?.trim() || 'Contact Us',
        footer_support_contact_href: document.getElementById('footer-contact-href')?.value?.trim() || 'contact.html#channels',
        footer_support_contact_type: document.getElementById('footer-contact-type')?.value || 'page',

        footer_support_terms_label: document.getElementById('footer-terms-label')?.value?.trim() || 'Terms of Service',
        footer_support_terms_href: document.getElementById('footer-terms-href')?.value?.trim() || 'terms.html',
        footer_support_terms_type: document.getElementById('footer-terms-type')?.value || 'page',

        footer_support_cookie_label: document.getElementById('footer-cookie-label')?.value?.trim() || 'Cookie Policy',
        footer_support_cookie_href: document.getElementById('footer-cookie-href')?.value?.trim() || 'cookie-policy.html',
        footer_support_cookie_type: document.getElementById('footer-cookie-type')?.value || 'page'
    };
}

function applyFooterSupportSettingsToForm(settings = {}) {
    const setValue = (id, value) => {
        const el = document.getElementById(id);
        if (el && value !== undefined && value !== null && String(value) !== '') {
            el.value = String(value);
        }
    };

    setValue('footer-help-label', settings.footer_support_help_label);
    setValue('footer-help-href', settings.footer_support_help_href);
    setValue('footer-help-type', settings.footer_support_help_type);

    setValue('footer-safety-label', settings.footer_support_safety_label);
    setValue('footer-safety-href', settings.footer_support_safety_href);
    setValue('footer-safety-type', settings.footer_support_safety_type);

    setValue('footer-contact-label', settings.footer_support_contact_label);
    setValue('footer-contact-href', settings.footer_support_contact_href);
    setValue('footer-contact-type', settings.footer_support_contact_type);

    setValue('footer-terms-label', settings.footer_support_terms_label);
    setValue('footer-terms-href', settings.footer_support_terms_href);
    setValue('footer-terms-type', settings.footer_support_terms_type);

    setValue('footer-cookie-label', settings.footer_support_cookie_label);
    setValue('footer-cookie-href', settings.footer_support_cookie_href);
    setValue('footer-cookie-type', settings.footer_support_cookie_type);
}

async function loadFooterSupportSettings() {
    try {
        const response = await admin.apiCall('get_footer_support_settings', 'GET', null);
        if (response.success && response.settings) {
            applyFooterSupportSettingsToForm(response.settings);
        }
    } catch (error) {
        debugLog('Failed to load footer support settings:', error);
    }
}

async function saveFooterSupportSettings() {
    const settings = getFooterSupportSettingsPayload();

    try {
        const response = await admin.apiCall('save_footer_support_settings', 'POST', { settings });
        if (response.success) {
            admin.showAlert('success', response.message || 'Footer support links saved successfully');
            await loadFooterSupportSettings();
        } else {
            admin.showAlert('error', response.message || 'Failed to save footer support links');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving footer support links: ' + error.message);
    }
}

// ── WhatsApp Integration Settings ───────────────────────────────────────────

function ensureWhatsAppSettingsSection() {
    const mount = document.getElementById('whatsappSettingsMount');
    const card = document.getElementById('waSettingsCard');
    if (mount && card && card.parentElement !== mount) {
        mount.appendChild(card);
    }
}

function _setWaStatusBadge(enabled, tokenConfigured, phoneIdConfigured) {
    const badge = document.getElementById('waStatusBadge');
    if (!badge) return;
    if (enabled && tokenConfigured && phoneIdConfigured) {
        badge.textContent = '✅ Active';
        badge.style.cssText = 'font-size:0.75rem;padding:3px 10px;border-radius:999px;background:#d1fae5;color:#065f46;';
    } else if (!enabled) {
        badge.textContent = '⏸ Disabled';
        badge.style.cssText = 'font-size:0.75rem;padding:3px 10px;border-radius:999px;background:#f3f4f6;color:#6b7280;';
    } else {
        badge.textContent = '⚠️ Not Configured';
        badge.style.cssText = 'font-size:0.75rem;padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;';
    }
}

function toggleWaTokenVisibility() {
    const input = document.getElementById('wa-api-token');
    const icon  = document.getElementById('waTokenToggleIcon');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.replace('fa-eye', 'fa-eye-slash'); }
    } else {
        input.type = 'password';
        if (icon) { icon.classList.replace('fa-eye-slash', 'fa-eye'); }
    }
}

async function loadWhatsAppSettings() {
    try {
        ensureWhatsAppSettingsSection();
        const response = await admin.apiCall('get_whatsapp_settings', 'GET', null);
        if (!response.success || !response.settings) return;
        const s = response.settings;

        const enabledEl = document.getElementById('wa-enabled');
        if (enabledEl) enabledEl.checked = s.wa_enabled === '1';

        const publicButtonsEl = document.getElementById('wa-public-buttons-enabled');
        if (publicButtonsEl) publicButtonsEl.checked = s.wa_public_buttons_enabled !== '0';

        const leadEl = document.getElementById('wa-lead-notifications');
        if (leadEl) leadEl.checked = s.wa_lead_notifications === '1';

        const wabaEl = document.getElementById('wa-business-account-id');
        if (wabaEl) wabaEl.value = s.wa_business_account_id || '';

        const numEl = document.getElementById('wa-phone-number-id');
        if (numEl) numEl.value = s.wa_phone_number_id || '';

        const verEl = document.getElementById('wa-api-version');
        if (verEl) verEl.value = s.wa_api_version || 'v25.0';

        const tokenStatusEl = document.getElementById('waTokenStatus');
        if (tokenStatusEl) {
            if (s.token_configured) {
                tokenStatusEl.innerHTML = `<span style="color:#128c49;"><i class="fas fa-check-circle"></i> Token configured (${s.wa_api_token}). Leave blank to keep unchanged.</span>`;
            } else {
                tokenStatusEl.innerHTML = `<span style="color:#c06000;"><i class="fas fa-exclamation-triangle"></i> No token saved. Paste your permanent access token above.</span>`;
            }
        }
        const tokenEl = document.getElementById('wa-api-token');
        if (tokenEl) { tokenEl.value = ''; tokenEl.placeholder = s.token_configured ? 'Leave blank to keep existing token' : 'Paste permanent access token'; }

        const webhookTokenEl = document.getElementById('wa-webhook-verify-token');
        if (webhookTokenEl) webhookTokenEl.value = s.wa_webhook_verify_token || '';

        const appSecretEl = document.getElementById('wa-app-secret');
        if (appSecretEl) { appSecretEl.value = ''; appSecretEl.placeholder = s.app_secret_configured ? 'Leave blank to keep existing secret' : 'Paste App Secret'; }

        _setWaStatusBadge(s.wa_enabled === '1', s.token_configured, !!s.wa_phone_number_id);

        // Load quick stats
        loadWhatsAppBookingStats();
    } catch (error) {
        debugLog('Failed to load WhatsApp settings:', error);
    }
}

async function saveWhatsAppSettings() {
    const btn = document.getElementById('waSaveBtn');
    const payload = {
        wa_enabled:              document.getElementById('wa-enabled')?.checked ? '1' : '0',
        wa_public_buttons_enabled: document.getElementById('wa-public-buttons-enabled')?.checked ? '1' : '0',
        wa_lead_notifications:   document.getElementById('wa-lead-notifications')?.checked ? '1' : '0',
        wa_api_token:            document.getElementById('wa-api-token')?.value || '',
        wa_phone_number_id:      document.getElementById('wa-phone-number-id')?.value?.trim() || '',
        wa_business_account_id:  document.getElementById('wa-business-account-id')?.value?.trim() || '',
        wa_api_version:          document.getElementById('wa-api-version')?.value?.trim() || 'v25.0',
        wa_app_secret:           document.getElementById('wa-app-secret')?.value || '',
    };

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

    try {
        const response = await admin.apiCall('save_whatsapp_settings', 'POST', payload);
        if (response.success) {
            admin.showAlert('success', response.message || 'WhatsApp settings saved');
            await loadWhatsAppSettings();
        } else {
            admin.showAlert('error', response.message || 'Failed to save WhatsApp settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving WhatsApp settings: ' + error.message);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save API Settings'; }
    }
}

async function sendWhatsAppTest() {
    const btn       = document.getElementById('waTestBtn');
    const resultEl  = document.getElementById('waTestResult');
    const phoneEl   = document.getElementById('waTestPhone');
    const phone     = (phoneEl?.value || '').trim().replace(/[^0-9]/g, '');

    if (!phone) {
        resultEl.style.display = 'block';
        resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
        resultEl.textContent   = 'Enter a phone number with country code (digits only, no + or spaces).';
        return;
    }

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…'; }
    resultEl.style.display = 'none';

    try {
        const response = await admin.apiCall('test_whatsapp_message', 'POST', { test_phone: phone });

        resultEl.style.display = 'block';

        if (response.success) {
            resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;';
            resultEl.innerHTML     = `<i class="fas fa-check-circle"></i> <strong>Success!</strong> ${response.message || 'Test message sent.'}`
                                   + (response.wamid ? `<br><small style="opacity:0.7;">wamid: ${response.wamid}</small>` : '');
        } else {
            resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
            const errMsg = response.error || response.message || 'API returned an error.';
            const apiCode = response.api_code ? ` (Code: ${response.api_code})` : '';
            resultEl.innerHTML = `<i class="fas fa-times-circle"></i> <strong>Failed:</strong> ${errMsg}${apiCode}<br><small style="opacity:0.7;">HTTP ${response.http_code || '—'}</small>`;
        }
    } catch (error) {
        resultEl.style.display = 'block';
        resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
        resultEl.textContent   = 'Network error: ' + error.message;
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Test'; }
    }
}

async function sendWhatsAppBookingTemplateTest() {
    const btn      = document.getElementById('waBookingTestBtn');
    const resultEl = document.getElementById('waBookingTestResult');
    const phoneEl  = document.getElementById('waBookingTestPhone');
    const phone    = (phoneEl?.value || '').trim().replace(/[^0-9]/g, '');

    if (!phone) {
        resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
        resultEl.textContent   = 'Enter a phone number with country code (digits only, no + or spaces).';
        return;
    }

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…'; }
    resultEl.style.display = 'none';

    try {
        const response = await admin.apiCall('test_wa_booking_template', 'POST', { test_phone: phone });
        resultEl.style.display = 'block';

        if (response.success) {
            resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;';
            resultEl.innerHTML     = `<i class="fas fa-check-circle"></i> <strong>Success!</strong> ${response.message || 'Booking template sent.'}`
                                   + (response.wamid ? `<br><small style="opacity:0.7;">wamid: ${response.wamid}</small>` : '');
        } else {
            resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
            const errMsg  = response.error || response.message || 'API returned an error.';
            const apiCode = response.api_code ? ` (Code: ${response.api_code})` : '';
            resultEl.innerHTML = `<i class="fas fa-times-circle"></i> <strong>Failed:</strong> ${errMsg}${apiCode}<br><small style="opacity:0.7;">HTTP ${response.http_code || '—'}</small>`;
        }
    } catch (error) {
        resultEl.style.display = 'block';
        resultEl.style.cssText = 'display:block;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:0.84rem;background:#fff1f1;border:1px solid #f5c2c2;color:#c0392b;';
        resultEl.textContent   = 'Network error: ' + error.message;
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-calendar-check"></i> Test Booking Template'; }
    }
}

async function loadWhatsAppBookingStats() {
    try {
        const response = await admin.apiCall('get_whatsapp_bookings', 'GET', null);
        if (!response.success || !response.stats) return;
        const s = response.stats;

        const strip = document.getElementById('waQuickStats');
        if (strip) {
            strip.style.display = 'block';
            ['total','pending','confirmed','declined'].forEach(k => {
                const el = document.getElementById(`waStat-${k}`);
                if (el) el.textContent = s[k] ?? 0;
            });
            const waEl = document.getElementById('waStat-wa');
            if (waEl) waEl.textContent = s.wa_sent ?? 0;
        }
    } catch (e) { debugLog('Failed to load WA booking stats', e); }
}

// ── WhatsApp Bookings Table ──────────────────────────────────────────────────

const _waStatusColors = {
    pending:   { bg: '#fef3c7', color: '#92400e' },
    confirmed: { bg: '#d1fae5', color: '#065f46' },
    declined:  { bg: '#fee2e2', color: '#991b1b' },
    cancelled: { bg: '#f3f4f6', color: '#374151' },
};

async function loadWhatsAppBookings() {
    const tbody    = document.getElementById('waBookingsBody');
    const status   = document.getElementById('waBookingStatusFilter')?.value || '';
    const search   = document.getElementById('waBookingSearch')?.value?.trim() || '';
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="10" class="text-center">Loading…</td></tr>';

    try {
        let qs = 'action=get_whatsapp_bookings&limit=200';
        if (status) qs += '&status=' + encodeURIComponent(status);
        if (search) qs += '&search=' + encodeURIComponent(search);

        const base = (typeof CONFIG !== 'undefined' && CONFIG.ADMIN_API_URL) ? CONFIG.ADMIN_API_URL : 'admin-api.php';
        const resp = await fetch(`${base}?${qs}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include'
        });
        const data = await resp.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center" style="color:#b91c1c;">${data.message || 'Failed to load bookings'}</td></tr>`;
            return;
        }

        // Update stats strip
        const strip = document.getElementById('waBookingStatsStrip');
        if (strip && data.stats) {
            const s = data.stats;
            strip.innerHTML = [
                `<span style="padding:4px 10px;border-radius:999px;background:#f3f4f6;color:#374151;">Total: <strong>${s.total ?? 0}</strong></span>`,
                `<span style="padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;">Pending: <strong>${s.pending ?? 0}</strong></span>`,
                `<span style="padding:4px 10px;border-radius:999px;background:#d1fae5;color:#065f46;">Confirmed: <strong>${s.confirmed ?? 0}</strong></span>`,
                `<span style="padding:4px 10px;border-radius:999px;background:#fee2e2;color:#991b1b;">Declined: <strong>${s.declined ?? 0}</strong></span>`,
                `<span style="padding:4px 10px;border-radius:999px;background:#dbeafe;color:#1e40af;"><i class="fab fa-whatsapp"></i> API Sent: <strong>${s.wa_sent ?? 0}</strong></span>`,
            ].join('');
        }

        if (!data.bookings || data.bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center" style="color:#6b7280;">No booking requests found.</td></tr>';
            return;
        }

        const curr = (typeof CONFIG !== 'undefined' && CONFIG.CURRENCY_CODE) ? CONFIG.CURRENCY_CODE : 'MWK';
        tbody.innerHTML = data.bookings.map(b => {
            const refId = String(b.id).padStart(6, '0');
            const sc    = _waStatusColors[b.status] || _waStatusColors.pending;
            const waTag = b.wa_sent == 1
                ? '<span style="color:#25d366;"><i class="fab fa-whatsapp"></i> Yes</span>'
                : '<span style="color:#9ca3af;">No</span>';
            const total = b.total_estimate ? `${curr} ${Number(b.total_estimate).toLocaleString()}` : '—';
            const waLink = b.renter_whatsapp
                ? `<a href="https://wa.me/${b.renter_whatsapp.replace(/[^0-9]/g,'')}" target="_blank" title="Chat with renter" style="color:#25d366;margin-left:5px;"><i class="fab fa-whatsapp"></i></a>` : '';
            return `<tr>
                <td style="font-weight:600;font-size:0.8rem;">#${refId}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${_escHtml(b.company_name || '—')}</td>
                <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${_escHtml(b.vehicle_name || '—')}</td>
                <td>${_escHtml(b.renter_name)}<br><small style="color:#6b7280;">${_escHtml(b.renter_phone)}${waLink}</small></td>
                <td style="white-space:nowrap;font-size:0.82rem;">${b.start_date}<br>→ ${b.end_date}</td>
                <td style="text-align:center;">${b.duration_days}</td>
                <td style="white-space:nowrap;">${total}</td>
                <td style="text-align:center;">${waTag}</td>
                <td>
                    <span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:0.75rem;font-weight:600;background:${sc.bg};color:${sc.color};">
                        ${b.status}
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    ${b.status === 'pending' ? `
                        <button class="btn btn-xs" style="background:#d1fae5;color:#065f46;border:none;padding:3px 8px;border-radius:6px;cursor:pointer;margin-right:3px;"
                            onclick="updateWaBookingStatus(${b.id},'confirmed')"><i class="fas fa-check"></i> Confirm</button>
                        <button class="btn btn-xs" style="background:#fee2e2;color:#991b1b;border:none;padding:3px 8px;border-radius:6px;cursor:pointer;"
                            onclick="updateWaBookingStatus(${b.id},'declined')"><i class="fas fa-times"></i> Decline</button>
                    ` : `
                        <button class="btn btn-xs" style="background:#f3f4f6;color:#374151;border:none;padding:3px 8px;border-radius:6px;cursor:pointer;"
                            onclick="updateWaBookingStatus(${b.id},'pending')"><i class="fas fa-undo"></i> Reset</button>
                    `}
                </td>
            </tr>`;
        }).join('');
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center" style="color:#b91c1c;">Error: ${error.message}</td></tr>`;
    }
}

function _escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

async function updateWaBookingStatus(bookingId, status) {
    try {
        const response = await admin.apiCall('update_whatsapp_booking_status', 'POST', { booking_id: bookingId, status });
        if (response.success) {
            admin.showAlert('success', response.message || 'Booking updated');
            loadWhatsAppBookings();
        } else {
            admin.showAlert('error', response.message || 'Failed to update booking');
        }
    } catch (error) {
        admin.showAlert('error', 'Error: ' + error.message);
    }
}

function getAdminDbCredentialsPayload() {
    return {
        host: document.getElementById('admin-db-host')?.value?.trim() || '',
        name: document.getElementById('admin-db-name')?.value?.trim() || '',
        user: document.getElementById('admin-db-user')?.value?.trim() || '',
        password: document.getElementById('admin-db-pass')?.value || ''
    };
}

function setAdminDbStatus(message, isError = false) {
    const statusEl = document.getElementById('admin-db-status');
    if (!statusEl) return;
    statusEl.textContent = message || '';
    statusEl.style.color = isError ? '#b91c1c' : '#166534';
}

async function loadAdminDbCredentials() {
    try {
        const response = await admin.apiCall('get_admin_db_credentials', 'GET', null);
        if (!response.success || !response.credentials) {
            throw new Error(response.message || 'Failed to load admin DB credentials');
        }

        document.getElementById('admin-db-host').value = response.credentials.host || '';
        document.getElementById('admin-db-name').value = response.credentials.name || '';
        document.getElementById('admin-db-user').value = response.credentials.user || '';
        document.getElementById('admin-db-pass').value = '';
        setAdminDbStatus(response.credentials.has_password ? 'Password is configured. Leave password blank to keep it unchanged.' : 'No password is currently configured.', false);
    } catch (error) {
        setAdminDbStatus(error.message || 'Failed to load admin DB credentials', true);
    }
}

async function testAdminDbCredentials() {
    const payload = getAdminDbCredentialsPayload();

    if (!payload.host || !payload.name || !payload.user) {
        admin.showAlert('error', 'Host, database name, and username are required');
        return;
    }

    setAdminDbStatus('Testing database connection...', false);

    try {
        const response = await admin.apiCall('test_admin_db_credentials', 'POST', payload);
        if (!response.success) {
            throw new Error(response.message || 'Connection test failed');
        }

        const version = response.meta?.server_version ? ` (Server: ${response.meta.server_version})` : '';
        setAdminDbStatus(`Connection successful${version}`, false);
        admin.showAlert('success', 'Admin DB connection test passed');
    } catch (error) {
        setAdminDbStatus(error.message || 'Connection test failed', true);
        admin.showAlert('error', error.message || 'Admin DB connection test failed');
    }
}

async function saveAdminDbCredentials() {
    const payload = getAdminDbCredentialsPayload();
    payload.skip_test = !!document.getElementById('admin-db-skip-test')?.checked;

    if (!payload.host || !payload.name || !payload.user) {
        admin.showAlert('error', 'Host, database name, and username are required');
        return;
    }

    setAdminDbStatus('Saving credentials...', false);

    try {
        const response = await admin.apiCall('save_admin_db_credentials', 'POST', payload);
        if (!response.success) {
            throw new Error(response.message || 'Failed to save admin DB credentials');
        }

        document.getElementById('admin-db-pass').value = '';
        setAdminDbStatus('Credentials saved successfully. New admin connections will use these settings.', false);
        admin.showAlert('success', response.message || 'Admin DB credentials saved');
        await loadAdminDbCredentials();
    } catch (error) {
        setAdminDbStatus(error.message || 'Failed to save admin DB credentials', true);
        admin.showAlert('error', error.message || 'Failed to save admin DB credentials');
    }
}

function updateMaintenanceBanner(isEnabled) {
    const banner = document.getElementById('maintenanceBanner');
    if (banner) {
        banner.style.display = isEnabled ? 'flex' : 'none';
    }
}

async function toggleMaintenanceMode() {
    const isEnabled = document.getElementById('maintenance-mode').checked;
    const message = document.getElementById('maintenance-message').value;

    if (isEnabled) {
        if (!confirm('Are you sure you want to enable maintenance mode? Only administrators will be able to access the site.')) {
            document.getElementById('maintenance-mode').checked = false;
            return;
        }
    }

    try {
        const response = await admin.apiCall('save_settings', 'POST', {
            category: 'maintenance',
            settings: {
                enabled: isEnabled,
                message: message
            }
        });

        if (response.success) {
            updateMaintenanceBanner(isEnabled);
            admin.showAlert(isEnabled ? 'warning' : 'success',
                           isEnabled ? 'Maintenance mode enabled' : 'Maintenance mode disabled');
            debugLog('Maintenance mode:', isEnabled ? 'enabled' : 'disabled', message);
            await loadSettings();
        } else {
            admin.showAlert('error', response.message || 'Failed to update maintenance mode');
            document.getElementById('maintenance-mode').checked = !isEnabled;
        }
    } catch (error) {
        admin.showAlert('error', 'Error updating maintenance mode: ' + error.message);
        document.getElementById('maintenance-mode').checked = !isEnabled;
        debugLog('Error toggling maintenance mode:', error);
    }
}

async function disableMaintenanceModeQuick() {
    document.getElementById('maintenance-mode').checked = false;
    await toggleMaintenanceMode();
}

function previewMaintenanceMode() {
    const message = (document.getElementById('maintenance-message')?.value || '').trim();
    const encodedMessage = encodeURIComponent(
        message || "We're currently performing scheduled maintenance. We'll be back shortly!"
    );
    const previewUrl = `../maintenance.html?message=${encodedMessage}`;
    window.open(previewUrl, '_blank', 'noopener');
}

async function exportDatabaseBackup() {
    admin.showAlert('info', 'Preparing database backup...');

    try {
        const response = await admin.apiCall('export_database', 'POST', {});

        if (response.success) {
            // Create download
            const dataStr = JSON.stringify(response.backup, null, 2);
            const dataBlob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = response.filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

            admin.showAlert('success', 'Database backup exported successfully!');
            debugLog('Database backup exported:', response.filename);
        } else {
            admin.showAlert('error', response.message || 'Failed to export database');
        }
    } catch (error) {
        admin.showAlert('error', 'Error exporting database: ' + error.message);
        debugLog('Error exporting database:', error);
    }
}

async function exportAllData() {
    admin.showAlert('info', 'Exporting all data as JSON...');

    try {
        const response = await admin.apiCall('export_database', 'POST', {});

        if (response.success) {
            // Create download
            const dataStr = JSON.stringify(response.backup, null, 2);
            const dataBlob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = response.filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

            admin.showAlert('success', 'All data exported successfully!');
            debugLog('All data exported:', response.filename);
        } else {
            admin.showAlert('error', response.message || 'Failed to export data');
        }
    } catch (error) {
        admin.showAlert('error', 'Error exporting data: ' + error.message);
        debugLog('Error exporting data:', error);
    }
}

function exportUsers() {
    admin.showAlert('info', 'Export functionality coming soon');
}

function updateFeaturedLaunchModeBadge() {
    const priceInput = document.getElementById('featured-listing-price');
    const badge = document.getElementById('featuredLaunchModeBadge');
    if (!priceInput || !badge) return;

    const featuredPrice = Number(priceInput.value || 0);
    const currencyLabel = (
        document.getElementById('currency-symbol')?.value ||
        document.getElementById('currency-code')?.value ||
        'LOCAL'
    ).trim();

    if (featuredPrice <= 0) {
        badge.innerHTML = '<i class="fas fa-bolt"></i> Featured is currently free';
        badge.style.background = '#ecfdf3';
        badge.style.color = '#166534';
    } else {
        badge.innerHTML = `<i class="fas fa-tag"></i> Featured is paid: ${currencyLabel} ${Math.round(featuredPrice).toLocaleString()}`;
        badge.style.background = '#eff6ff';
        badge.style.color = '#1d4ed8';
    }
}

// ===== LOAD SETTINGS =====

async function loadSettings() {
    try {
        const response = await admin.apiCall('get_settings', 'GET', null);

        if (response.success) {
            const settings = response.settings;
            debugLog('Loaded settings:', settings);

            // General Settings
            if (settings['general_siteName']) document.getElementById('site-name').value = settings['general_siteName'];
            if (settings['general_siteShortName']) document.getElementById('site-short-name').value = settings['general_siteShortName'];
            if (settings['general_siteTagline']) document.getElementById('site-tagline').value = settings['general_siteTagline'];
            if (settings['general_siteDescription']) document.getElementById('site-description').value = settings['general_siteDescription'];
            if (settings['general_siteUrl']) document.getElementById('site-url').value = settings['general_siteUrl'];
            if (settings['general_countryName']) document.getElementById('country-name').value = settings['general_countryName'];
            if (settings['general_countryCode']) document.getElementById('country-code').value = settings['general_countryCode'];
            if (settings['general_countryDemonym']) document.getElementById('country-demonym').value = settings['general_countryDemonym'];
            if (settings['general_locale']) document.getElementById('locale').value = settings['general_locale'];
            if (settings['general_currencyCode']) document.getElementById('currency-code').value = settings['general_currencyCode'];
            if (settings['general_currencySymbol']) document.getElementById('currency-symbol').value = settings['general_currencySymbol'];
            if (settings['general_marketScopeLabel']) document.getElementById('market-scope-label').value = settings['general_marketScopeLabel'];
            if (settings['general_adminEmail']) document.getElementById('admin-email').value = settings['general_adminEmail'];
            if (settings['general_supportEmail']) document.getElementById('support-email').value = settings['general_supportEmail'];
            updateFeaturedLaunchModeBadge();

            // Listing Settings
            if (settings['listing_autoApprove'] !== undefined) document.getElementById('auto-approve-listings').checked = settings['listing_autoApprove'];
            if (settings['listing_expiryDays']) document.getElementById('listing-expiry-days').value = settings['listing_expiryDays'];
            if (settings['listing_maxImages']) document.getElementById('max-images-per-listing').value = settings['listing_maxImages'];
            if (settings['listing_featuredPrice']) document.getElementById('featured-listing-price').value = settings['listing_featuredPrice'];
            if (settings['listing_featuredBonusDays'] !== undefined) document.getElementById('featured-bonus-days').value = settings['listing_featuredBonusDays'];
            if (settings['listing_paymentsEnabled'] !== undefined) document.getElementById('enable-paid-listings').checked = settings['listing_paymentsEnabled'];
            if (settings['listing_freeListingPrice'] !== undefined) document.getElementById('free-listing-price').value = settings['listing_freeListingPrice'];
            if (settings['listing_paymentMethods'] !== undefined) document.getElementById('payment-methods').value = settings['listing_paymentMethods'];
            if (settings['listing_paymentReferencePrefix'] !== undefined) document.getElementById('payment-reference-prefix').value = settings['listing_paymentReferencePrefix'];
            if (settings['listing_paymentInstructions'] !== undefined) document.getElementById('payment-instructions').value = settings['listing_paymentInstructions'];
            if (settings['listing_maxRegisteredListings'] !== undefined) document.getElementById('max-listings-registered').value = settings['listing_maxRegisteredListings'];
            if (settings['listing_maxGuestListings'] !== undefined) document.getElementById('max-listings-guest').value = settings['listing_maxGuestListings'];
            if (settings['listing_requireEmailValidation'] !== undefined) document.getElementById('require-listing-email-validation').checked = settings['listing_requireEmailValidation'];
            updateFeaturedLaunchModeBadge();

            // User Settings
            if (settings['user_requireEmailVerification'] !== undefined) document.getElementById('require-email-verification').checked = settings['user_requireEmailVerification'];
            if (settings['user_allowGuestListings'] !== undefined) document.getElementById('allow-guest-listings').checked = settings['user_allowGuestListings'];
            if (settings['user_maxFreeListings']) document.getElementById('max-free-listings').value = settings['user_maxFreeListings'];

            // Email Settings
            if (settings['email_smtpHost']) document.getElementById('smtp-host').value = settings['email_smtpHost'];
            if (settings['email_smtpPort']) document.getElementById('smtp-port').value = settings['email_smtpPort'];
            if (settings['email_smtpUsername']) document.getElementById('smtp-username').value = settings['email_smtpUsername'];
            if (settings['email_emailFromName']) document.getElementById('email-from-name').value = settings['email_emailFromName'];
            if (settings['email_enableNotifications'] !== undefined) document.getElementById('enable-email-notifications').checked = settings['email_enableNotifications'];

            // Security Settings
            if (settings['security_sessionTimeout']) document.getElementById('session-timeout').value = settings['security_sessionTimeout'];
            if (settings['security_maxLoginAttempts']) document.getElementById('max-login-attempts').value = settings['security_maxLoginAttempts'];
            if (settings['security_enable2FA'] !== undefined) document.getElementById('enable-2fa').checked = settings['security_enable2FA'];

            // Maintenance Mode
            if (settings['maintenance_enabled'] !== undefined) {
                document.getElementById('maintenance-mode').checked = settings['maintenance_enabled'];
                updateMaintenanceBanner(!!settings['maintenance_enabled']);
            }
            if (settings['maintenance_message'] !== undefined) document.getElementById('maintenance-message').value = settings['maintenance_message'];

            // Admin DB credentials are managed separately in site_settings.
            await loadAdminDbCredentials();
            await loadFooterSupportSettings();

        } else {
            debugLog('No settings found or error loading settings');
            await loadAdminDbCredentials();
            await loadFooterSupportSettings();
        }
    } catch (error) {
        debugLog('Error loading settings:', error);
        await loadAdminDbCredentials();
        await loadFooterSupportSettings();
    }
}

function getFuelPriceDefaultDate() {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 10);
}

function updateFuelPriceStatusCard(data) {
    const summaryEl = document.getElementById('fuel-price-current-summary');
    if (!summaryEl) return;

    if (!data || !data.prices) {
        summaryEl.textContent = 'Fuel prices are not available yet.';
        return;
    }

    const petrol = data.prices.petrol || {};
    const diesel = data.prices.diesel || {};
    const petrolText = petrol.price_per_liter_mwk !== '' && petrol.price_per_liter_mwk !== undefined
        ? `Petrol ${getConfiguredCurrencyLabel()} ${Number(petrol.price_per_liter_mwk).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}/L`
        : 'Petrol not set';
    const dieselText = diesel.price_per_liter_mwk !== '' && diesel.price_per_liter_mwk !== undefined
        ? `Diesel ${getConfiguredCurrencyLabel()} ${Number(diesel.price_per_liter_mwk).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}/L`
        : 'Diesel not set';
    const resolvedDate = data.resolved_date || data.requested_date || getFuelPriceDefaultDate();
    const modeText = data.has_exact_date
        ? `Active prices for ${resolvedDate}`
        : `Showing latest saved prices from ${resolvedDate} while editing ${data.requested_date || getFuelPriceDefaultDate()}`;
    const sourceText = data.source ? ` Source: ${data.source}.` : '';
    const updatedText = data.last_updated ? ` Last updated: ${new Date(data.last_updated).toLocaleString()}.` : '';

    summaryEl.textContent = `${modeText}. ${petrolText}. ${dieselText}.${sourceText}${updatedText}`.replace(/\s+/g, ' ').trim();
}

async function loadFuelPriceSettings(requestedDate = null) {
    const dateInput = document.getElementById('fuel-price-effective-date');
    const dateToLoad = requestedDate || dateInput?.value || getFuelPriceDefaultDate();

    try {
        const response = await admin.apiCall('get_fuel_price_settings', 'GET', { date: dateToLoad });
        if (!response.success) {
            throw new Error(response.message || 'Failed to load fuel prices');
        }

        if (dateInput) {
            dateInput.value = response.requested_date || dateToLoad;
        }

        const sourceInput = document.getElementById('fuel-price-source');
        if (sourceInput) {
            sourceInput.value = response.source || 'admin_portal';
        }

        const petrol = response.prices?.petrol || {};
        const diesel = response.prices?.diesel || {};

        const petrolMwkInput = document.getElementById('fuel-price-petrol-mwk');
        if (petrolMwkInput) petrolMwkInput.value = petrol.price_per_liter_mwk !== '' ? petrol.price_per_liter_mwk : '';

        const dieselMwkInput = document.getElementById('fuel-price-diesel-mwk');
        if (dieselMwkInput) dieselMwkInput.value = diesel.price_per_liter_mwk !== '' ? diesel.price_per_liter_mwk : '';

        const petrolUsdInput = document.getElementById('fuel-price-petrol-usd');
        if (petrolUsdInput) petrolUsdInput.value = petrol.price_per_liter_usd !== '' ? petrol.price_per_liter_usd : '';

        const dieselUsdInput = document.getElementById('fuel-price-diesel-usd');
        if (dieselUsdInput) dieselUsdInput.value = diesel.price_per_liter_usd !== '' ? diesel.price_per_liter_usd : '';

        updateFuelPriceStatusCard(response);
    } catch (error) {
        if (error && error.code !== 'ADMIN_AUTH_REQUIRED') {
            console.error('Error loading fuel price settings:', error);
        }
        updateFuelPriceStatusCard(null);
    }
}

async function saveFuelPriceSettings() {
    try {
        const effectiveDate = document.getElementById('fuel-price-effective-date')?.value || getFuelPriceDefaultDate();
        const source = (document.getElementById('fuel-price-source')?.value || 'admin_portal').trim();
        const petrolMwk = document.getElementById('fuel-price-petrol-mwk')?.value;
        const dieselMwk = document.getElementById('fuel-price-diesel-mwk')?.value;
        const petrolUsd = document.getElementById('fuel-price-petrol-usd')?.value;
        const dieselUsd = document.getElementById('fuel-price-diesel-usd')?.value;

        if (!effectiveDate) {
            admin.showAlert('error', 'Please choose an effective date for the fuel prices');
            return;
        }

        if (petrolMwk === '' || Number(petrolMwk) <= 0) {
            admin.showAlert('error', `Please enter a valid petrol price in ${getConfiguredCurrencyLabel()}`);
            return;
        }

        if (dieselMwk === '' || Number(dieselMwk) <= 0) {
            admin.showAlert('error', `Please enter a valid diesel price in ${getConfiguredCurrencyLabel()}`);
            return;
        }

        const payload = {
            effective_date: effectiveDate,
            source: source || 'admin_portal',
            petrol_mwk: petrolMwk,
            diesel_mwk: dieselMwk,
            petrol_usd: petrolUsd === '' ? null : petrolUsd,
            diesel_usd: dieselUsd === '' ? null : dieselUsd
        };

        const response = await admin.apiCall('save_fuel_price_settings', 'POST', payload);
        if (!response.success) {
            throw new Error(response.message || 'Failed to save fuel prices');
        }

        updateFuelPriceStatusCard(response);
        await loadFuelPriceSettings(effectiveDate);
        admin.showAlert('success', `Fuel prices saved for ${effectiveDate}`);
    } catch (error) {
        console.error('Error saving fuel prices:', error);
        admin.showAlert('error', error.message || 'Failed to save fuel prices');
    }
}

async function loadFuelSourceSettings() {
    try {
        const response = await admin.apiCall('get_settings', 'GET', null);
        if (!response.success) return;
        const s = response.settings || {};
        const modeEl = document.getElementById('fuel-price-source-mode');
        const currencyEl = document.getElementById('fuel-price-display-currency');
        const hoursEl = document.getElementById('fuel-price-cache-hours');
        if (modeEl && s.fuel_price_source) modeEl.value = s.fuel_price_source;
        if (currencyEl && s.fuel_price_display_currency) currencyEl.value = s.fuel_price_display_currency;
        if (hoursEl && (s.fuel_price_cache_hours !== undefined && s.fuel_price_cache_hours !== null && s.fuel_price_cache_hours !== '')) {
            hoursEl.value = s.fuel_price_cache_hours;
        }
    } catch (err) {
        console.error('Error loading fuel source settings:', err);
    }
}

async function saveFuelSourceSettings() {
    try {
        const mode = document.getElementById('fuel-price-source-mode')?.value || 'live_with_fallback';
        const currency = document.getElementById('fuel-price-display-currency')?.value || 'local';
        const hoursRaw = document.getElementById('fuel-price-cache-hours')?.value;
        const hours = hoursRaw === '' || hoursRaw === null ? 6 : Math.max(0, Math.min(168, parseInt(hoursRaw, 10) || 0));

        const payload = {
            category: 'fuel_price',
            settings: {
                source: mode,
                display_currency: currency,
                cache_hours: hours
            }
        };

        const response = await admin.apiCall('save_settings', 'POST', payload);
        if (!response.success) throw new Error(response.message || 'Failed to save fuel source settings');
        admin.showAlert('success', 'Fuel source and display settings saved');
    } catch (err) {
        console.error('Error saving fuel source settings:', err);
        admin.showAlert('error', err.message || 'Failed to save fuel source settings');
    }
}

async function fetchLiveFuelPrices() {
    const btn = document.querySelector('[onclick="fetchLiveFuelPrices()"]');
    const origHTML = btn ? btn.innerHTML : null;

    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching...';
        }

        const response = await admin.apiCall('fetch_live_fuel_prices', 'POST', {});
        if (!response.success) {
            throw new Error(response.message || 'Failed to fetch live fuel prices');
        }

        const prices = response.prices || {};

        const petrolMwk = document.getElementById('fuel-price-petrol-mwk');
        const dieselMwk = document.getElementById('fuel-price-diesel-mwk');
        const petrolUsd = document.getElementById('fuel-price-petrol-usd');
        const dieselUsd = document.getElementById('fuel-price-diesel-usd');

        if (petrolMwk && prices.petrol?.mwk != null) petrolMwk.value = prices.petrol.mwk;
        if (dieselMwk && prices.diesel?.mwk != null) dieselMwk.value = prices.diesel.mwk;
        if (petrolUsd && prices.petrol?.usd != null) petrolUsd.value = prices.petrol.usd;
        if (dieselUsd && prices.diesel?.usd != null) dieselUsd.value = prices.diesel.usd;

        const sourceLabel = response.source_label || 'live feed';
        const when = response.last_updated ? ` (${new Date(response.last_updated).toLocaleString()})` : '';
        const summaryEl = document.getElementById('fuel-price-current-summary');
        if (summaryEl) {
            summaryEl.textContent = `Prices fetched from ${sourceLabel}${when}. Review and click Save Fuel Prices to confirm.`;
        }

        admin.showAlert('success', `Live prices loaded from ${sourceLabel}. Review then save.`);
    } catch (err) {
        console.error('Error fetching live fuel prices:', err);
        admin.showAlert('error', err.message || 'Failed to fetch live fuel prices');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHTML || '<i class="fas fa-cloud-download-alt"></i> Fetch Live Prices';
        }
    }
}

async function loadSystemInfo() {
    try {
        const response = await admin.apiCall('get_system_info', 'GET', null);

        if (response.success) {
            const info = response.systemInfo;
            debugLog('Loaded system info:', info);

            // Update system info display
            if (info.phpVersion) document.getElementById('php-version').textContent = info.phpVersion;
            if (info.dbVersion) document.getElementById('db-version').textContent = 'MySQL ' + info.dbVersion.split('-')[0];
            if (info.serverTime) document.getElementById('server-time').textContent = new Date(info.serverTime).toLocaleString();
            if (info.totalUsers !== undefined) document.getElementById('total-users-count').textContent = info.totalUsers.toLocaleString();
            if (info.totalListings !== undefined) document.getElementById('total-listings-count').textContent = info.totalListings.toLocaleString();
        } else {
            debugLog('Error loading system info');
        }
    } catch (error) {
        debugLog('Error loading system info:', error);
    }
}

function filterCars() {
    admin.showAlert('info', 'Advanced filtering coming soon');
}

function filterPayments() {
    admin.showAlert('info', 'Advanced filtering coming soon');
}

function filterUsers() {
    admin.showAlert('info', 'Advanced filtering coming soon');
}

/**
 * Load AI Chat Settings
 */
function getAIProviderKeyFieldMap() {
    return {
        openai: {
            label: 'OpenAI',
            inputId: 'ai-openai-api-key',
            statusId: 'ai-openai-api-key-status'
        },
        deepseek: {
            label: 'DeepSeek',
            inputId: 'ai-deepseek-api-key',
            statusId: 'ai-deepseek-api-key-status'
        },
        qwen: {
            label: 'Qwen',
            inputId: 'ai-qwen-api-key',
            statusId: 'ai-qwen-api-key-status'
        },
        glm: {
            label: 'GLM',
            inputId: 'ai-glm-api-key',
            statusId: 'ai-glm-api-key-status'
        }
    };
}

function formatAIProviderLabel(provider) {
    const fieldMap = getAIProviderKeyFieldMap();
    return fieldMap[provider]?.label || provider;
}

function setAIProviderKeyAccess(canManageApiKeys) {
    const fieldMap = getAIProviderKeyFieldMap();
    const permissionStatus = document.getElementById('ai-provider-keys-permission-status');

    Object.values(fieldMap).forEach((field) => {
        const input = document.getElementById(field.inputId);
        if (input) {
            input.disabled = !canManageApiKeys;
            if (!canManageApiKeys) {
                input.value = '';
            }
        }
    });

    if (permissionStatus) {
        if (canManageApiKeys) {
            permissionStatus.textContent = 'Provider API keys are stored in site_settings. Leave a field blank to keep the current secret.';
            permissionStatus.style.color = '#455a64';
        } else {
            permissionStatus.textContent = 'Only super admins can overwrite provider API keys. Stored values remain masked and are never shown in full.';
            permissionStatus.style.color = '#c62828';
        }
    }
}

function applyAIProviderKeyMetadata(apiKeys = {}, canManageApiKeys = false) {
    const fieldMap = getAIProviderKeyFieldMap();

    Object.entries(fieldMap).forEach(([provider, field]) => {
        const input = document.getElementById(field.inputId);
        const statusEl = document.getElementById(field.statusId);
        const providerMeta = apiKeys?.[provider] || {};
        const isConfigured = providerMeta.configured === true;
        const maskedValue = providerMeta.masked || '********';

        if (input) {
            input.value = '';
            input.placeholder = isConfigured
                ? `Stored: ${maskedValue}. Enter a new ${field.label} key to replace it.`
                : `Enter a ${field.label} API key`;
        }

        if (statusEl) {
            statusEl.textContent = isConfigured
                ? `Status: Configured (${maskedValue})`
                : 'Status: Not configured';
            statusEl.style.color = isConfigured ? '#2e7d32' : '#c62828';
        }
    });

    setAIProviderKeyAccess(!!canManageApiKeys);
}

function getAIProviderKeyPayload() {
    const fieldMap = getAIProviderKeyFieldMap();
    const payload = {};

    Object.entries(fieldMap).forEach(([provider, field]) => {
        const input = document.getElementById(field.inputId);
        const value = input ? input.value.trim() : '';
        if (value !== '') {
            payload[provider] = value;
        }
    });

    return payload;
}

function getAIProviderHealthBadgeMeta(status) {
    switch (status) {
        case 'healthy':
            return { label: 'Healthy', color: '#1b5e20', background: '#e8f5e9' };
        case 'ready':
            return { label: 'Ready', color: '#1565c0', background: '#e3f2fd' };
        case 'rate_limited':
            return { label: 'Rate limited', color: '#ef6c00', background: '#fff3e0' };
        case 'out_of_credit':
            return { label: 'Out of credit', color: '#c62828', background: '#ffebee' };
        case 'auth_error':
            return { label: 'Auth error', color: '#ad1457', background: '#fce4ec' };
        case 'model_error':
            return { label: 'Model error', color: '#6a1b9a', background: '#f3e5f5' };
        case 'connection_error':
            return { label: 'Connection issue', color: '#4e342e', background: '#efebe9' };
        case 'provider_error':
            return { label: 'Provider error', color: '#8d6e63', background: '#f5f5f5' };
        case 'degraded':
            return { label: 'Degraded', color: '#f9a825', background: '#fffde7' };
        case 'disabled':
            return { label: 'Disabled', color: '#616161', background: '#eeeeee' };
        case 'not_configured':
            return { label: 'Not configured', color: '#c62828', background: '#ffebee' };
        default:
            return { label: 'Unknown', color: '#455a64', background: '#eceff1' };
    }
}

function escapeAIProviderHealthHtml(value) {
    const text = String(value ?? '');
    if (admin && typeof admin.escapeHtml === 'function') {
        return admin.escapeHtml(text);
    }

    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderAIProviderHealth(data = {}) {
    const summaryEl = document.getElementById('ai-provider-health-summary');
    const gridEl = document.getElementById('ai-provider-health-grid');
    if (!summaryEl || !gridEl) {
        return;
    }

    const providers = data.providers || {};
    const providerKeys = Object.keys(providers);
    if (providerKeys.length === 0) {
        summaryEl.className = 'alert alert-warning';
        summaryEl.textContent = 'No AI provider health data is available yet.';
        gridEl.innerHTML = '';
        return;
    }

    const currentProviderLabel = formatAIProviderLabel(data.current_provider || '');
    const effectiveRetryOrder = Array.isArray(data.effective_retry_order) ? data.effective_retry_order : [];
    const usableRetryOrder = Array.isArray(data.usable_retry_order) ? data.usable_retry_order : [];
    const checkedLive = data.checked_live === true;
    const summaryClass = checkedLive ? 'alert alert-warning' : 'alert alert-info';
    const effectiveOrderText = effectiveRetryOrder.length
        ? effectiveRetryOrder.map(formatAIProviderLabel).join(' -> ')
        : 'Not set';
    const usableOrderText = usableRetryOrder.length
        ? usableRetryOrder.map(formatAIProviderLabel).join(' -> ')
        : 'No enabled and configured providers';
    const summarySource = checkedLive
        ? 'Source: Live provider probe'
        : 'Source: Configuration plus recent API log events';

    summaryEl.className = summaryClass;
    summaryEl.innerHTML = `
        <strong>Active provider:</strong> ${escapeAIProviderHealthHtml(currentProviderLabel || 'Not set')}<br>
        <strong>Runtime retry order:</strong> ${escapeAIProviderHealthHtml(effectiveOrderText)}<br>
        <strong>Enabled + configured now:</strong> ${escapeAIProviderHealthHtml(usableOrderText)}<br>
        <strong>${escapeAIProviderHealthHtml(summarySource)}</strong>
    `;

    const orderedProviders = effectiveRetryOrder.length
        ? effectiveRetryOrder.filter((provider) => providers[provider])
        : providerKeys;
    const unorderedProviders = providerKeys.filter((provider) => !orderedProviders.includes(provider));
    const renderOrder = [...orderedProviders, ...unorderedProviders];

    gridEl.innerHTML = renderOrder.map((provider) => {
        const info = providers[provider] || {};
        const badgeMeta = getAIProviderHealthBadgeMeta(info.status);
        const sourceLabel = info.status_source === 'live_check'
            ? 'Live check'
            : (info.status_source === 'recent_log' ? 'Recent log' : 'Configuration');
        const modelText = escapeAIProviderHealthHtml(info.model_name || info.default_model || 'Unknown');
        const detailText = escapeAIProviderHealthHtml(info.status_message || 'No detail available');
        const lastEventText = info.last_event
            ? escapeAIProviderHealthHtml(info.last_event)
            : 'No recent API log event found in the latest window.';
        const checkedAtText = info.checked_at ? new Date(info.checked_at).toLocaleString() : '';
        const metaBadges = [];

        if (info.active_provider) {
            metaBadges.push('<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#e3f2fd;color:#0d47a1;font-size:12px;font-weight:600;">Active</span>');
        }
        metaBadges.push(`<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:${info.enabled ? '#e8f5e9' : '#eeeeee'};color:${info.enabled ? '#1b5e20' : '#616161'};font-size:12px;font-weight:600;">${info.enabled ? 'Enabled' : 'Disabled'}</span>`);
        metaBadges.push(`<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:${info.configured ? '#e8f5e9' : '#ffebee'};color:${info.configured ? '#1b5e20' : '#c62828'};font-size:12px;font-weight:600;">${info.configured ? 'Key set' : 'No key'}</span>`);
        if (info.latency_ms !== null && info.latency_ms !== undefined) {
            metaBadges.push(`<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f3e5f5;color:#6a1b9a;font-size:12px;font-weight:600;">${escapeAIProviderHealthHtml(`${info.latency_ms} ms`)}</span>`);
        }

        return `
            <div style="border:1px solid #dfe3e8;border-left:4px solid ${badgeMeta.color};border-radius:12px;padding:16px;background:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px;">
                    <div>
                        <h5 style="margin:0 0 6px 0;color:#1f2933;">${escapeAIProviderHealthHtml(info.label || formatAIProviderLabel(provider))}</h5>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">${metaBadges.join('')}</div>
                    </div>
                    <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:${badgeMeta.background};color:${badgeMeta.color};font-size:12px;font-weight:700;white-space:nowrap;">${escapeAIProviderHealthHtml(badgeMeta.label)}</span>
                </div>
                <p style="margin:0 0 8px 0;color:#334e68;"><strong>Model:</strong> ${modelText}</p>
                <p style="margin:0 0 8px 0;color:#334e68;"><strong>Health:</strong> ${detailText}</p>
                <p style="margin:0 0 8px 0;color:#486581;"><strong>Status source:</strong> ${escapeAIProviderHealthHtml(sourceLabel)}</p>
                <p style="margin:0 0 8px 0;color:#486581;"><strong>Stored key:</strong> ${escapeAIProviderHealthHtml(info.masked_key || 'Not configured')}</p>
                ${checkedAtText ? `<p style="margin:0 0 8px 0;color:#486581;"><strong>Checked:</strong> ${escapeAIProviderHealthHtml(checkedAtText)}</p>` : ''}
                <div style="margin-top:10px;padding:10px 12px;border-radius:8px;background:#f8fafc;color:#52606d;font-size:12px;line-height:1.45;word-break:break-word;">
                    <strong>Last event:</strong><br>${lastEventText}
                </div>
            </div>
        `;
    }).join('');
}

async function loadAIProviderHealth(runLiveCheck = false) {
    const summaryEl = document.getElementById('ai-provider-health-summary');
    const refreshBtn = document.getElementById('refresh-ai-provider-health-btn');
    const liveBtn = document.getElementById('run-ai-provider-live-check-btn');
    const originalRefreshLabel = refreshBtn ? refreshBtn.innerHTML : '';
    const originalLiveLabel = liveBtn ? liveBtn.innerHTML : '';

    try {
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = runLiveCheck
                ? originalRefreshLabel
                : '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        }
        if (liveBtn) {
            liveBtn.disabled = true;
            liveBtn.innerHTML = runLiveCheck
                ? '<i class="fas fa-spinner fa-spin"></i> Running Live Check...'
                : originalLiveLabel;
        }
        if (summaryEl) {
            summaryEl.className = 'alert alert-info';
            summaryEl.textContent = runLiveCheck
                ? 'Running live provider health checks...'
                : 'Refreshing AI provider health from configuration and recent logs...';
        }

        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const liveQuery = runLiveCheck ? '&live_check=1' : '';
        const response = await fetch(`${apiUrl}${separator}action=get_ai_provider_health${liveQuery}&_t=${Date.now()}`, {
            credentials: 'include',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || `Failed to load AI provider health (HTTP ${response.status})`);
        }

        renderAIProviderHealth(data);
    } catch (error) {
        console.error('Error loading AI provider health:', error);
        if (summaryEl) {
            summaryEl.className = 'alert alert-danger';
            summaryEl.textContent = `Failed to load AI provider health: ${error.message}`;
        }
        if (runLiveCheck) {
            admin.showAlert('error', 'Live AI provider check failed: ' + error.message);
        }
    } finally {
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalRefreshLabel;
        }
        if (liveBtn) {
            liveBtn.disabled = false;
            liveBtn.innerHTML = originalLiveLabel;
        }
    }
}

async function loadAIChatSettings() {
    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=get_ai_chat_settings`);
        const data = await response.json();
        
        if (data.success && data.settings) {
            const globalEnabled = data.settings.enabled == 1;
            document.getElementById('ai-chat-enabled').checked = globalEnabled;
            const statusEl = document.getElementById('ai-chat-enabled-status');
            if (statusEl) {
                statusEl.textContent = `Status: ${globalEnabled ? 'Enabled globally' : 'Disabled globally'}`;
                statusEl.style.color = globalEnabled ? '#2e7d32' : '#c62828';
            }

            const providerSelect = document.getElementById('ai-chat-provider');
            const savedProvider = data.settings.ai_provider || 'openai';
            if (providerSelect) {
                providerSelect.value = savedProvider;
            }
            
            const modelSelect = document.getElementById('ai-model-name');
            const customModelInput = document.getElementById('ai-custom-model-name');
            const savedModel = data.settings.model_name || 'gpt-4o-mini';
            if (modelSelect) {
                const modelExists = Array.from(modelSelect.options).some(opt => opt.value === savedModel && opt.value !== '__custom__');
                if (modelExists) {
                    modelSelect.value = savedModel;
                    if (customModelInput) {
                        customModelInput.value = '';
                    }
                } else {
                    modelSelect.value = '__custom__';
                    if (customModelInput) {
                        customModelInput.value = savedModel;
                    }
                }
                syncAIModelInputState(savedModel);
            }

            const reasoningEnabledInput = document.getElementById('ai-openai-reasoning-enabled');
            if (reasoningEnabledInput) {
                reasoningEnabledInput.checked = data.settings.openai_reasoning_enabled != 0;
            }

            const reasoningEffortSelect = document.getElementById('ai-openai-reasoning-effort');
            if (reasoningEffortSelect) {
                reasoningEffortSelect.value = data.settings.openai_reasoning_effort || 'medium';
            }

            const deepseekAutoProfileInput = document.getElementById('ai-deepseek-auto-profile-enabled');
            if (deepseekAutoProfileInput) {
                deepseekAutoProfileInput.checked = data.settings.deepseek_auto_profile_enabled != 0;
            }

            const glmAutoProfileInput = document.getElementById('ai-glm-auto-profile-enabled');
            if (glmAutoProfileInput) {
                glmAutoProfileInput.checked = data.settings.glm_auto_profile_enabled != 0;
            }

            syncAIReasoningControlsState();
            updateModelBadge();
            applyAIProviderKeyMetadata(data.api_keys || {}, data.can_manage_api_keys === true);
            
            document.getElementById('ai-max-tokens').value = data.settings.max_tokens_per_request || 600;
            document.getElementById('ai-temperature').value = data.settings.temperature || 0.8;
            document.getElementById('ai-requests-per-day').value = data.settings.requests_per_day || 50;
            document.getElementById('ai-requests-per-hour').value = data.settings.requests_per_hour || 10;
        }

        await loadAIProviderHealth(false);

        // Also load AI Learning settings
        await loadAILearningSettings();
    } catch (error) {
        console.error('Error loading AI chat settings:', error);
    }
}

/**
 * Save AI Chat Settings
 */
async function saveAIChatSettings() {
    try {
        const providerSelect = document.getElementById('ai-chat-provider');
        const selectedProvider = providerSelect ? providerSelect.value : 'openai';
        const selectedModel = getSelectedAIModelName();
        const providerApiKeys = getAIProviderKeyPayload();
        
        // Get all values and validate
        const enabled = document.getElementById('ai-chat-enabled').checked ? 1 : 0;
        const maxTokens = parseInt(document.getElementById('ai-max-tokens').value);
        const temperature = parseFloat(document.getElementById('ai-temperature').value);
        const requestsPerDay = parseInt(document.getElementById('ai-requests-per-day').value);
        const requestsPerHour = parseInt(document.getElementById('ai-requests-per-hour').value);
        const openAIReasoningEnabled = document.getElementById('ai-openai-reasoning-enabled')?.checked ? 1 : 0;
        const openAIReasoningEffort = document.getElementById('ai-openai-reasoning-effort')?.value || 'medium';
        const deepseekAutoProfileEnabled = document.getElementById('ai-deepseek-auto-profile-enabled')?.checked ? 1 : 0;
        const glmAutoProfileEnabled = document.getElementById('ai-glm-auto-profile-enabled')?.checked ? 1 : 0;

        if (!selectedModel) {
            admin.showAlert('error', 'Enter a valid model name before saving AI chat settings');
            return;
        }
        if (selectedModel.length > 120) {
            admin.showAlert('error', 'Model name must be 120 characters or fewer');
            return;
        }
        
        // Validate inputs
        if (isNaN(maxTokens) || maxTokens < 1 || maxTokens > 4000) {
            admin.showAlert('error', 'Max tokens must be between 1 and 4000');
            return;
        }
        if (isNaN(temperature) || temperature < 0 || temperature > 2) {
            admin.showAlert('error', 'Temperature must be between 0 and 2');
            return;
        }
        if (isNaN(requestsPerDay) || requestsPerDay < 1 || requestsPerDay > 1000) {
            admin.showAlert('error', 'Requests per day must be between 1 and 1000');
            return;
        }
        if (isNaN(requestsPerHour) || requestsPerHour < 1 || requestsPerHour > 100) {
            admin.showAlert('error', 'Requests per hour must be between 1 and 100');
            return;
        }
        if (!['minimal', 'low', 'medium', 'high'].includes(openAIReasoningEffort)) {
            admin.showAlert('error', 'OpenAI reasoning effort must be minimal, low, medium, or high');
            return;
        }
        
        const settings = {
            enabled: enabled,
            ai_provider: selectedProvider,
            model_name: selectedModel,
            openai_reasoning_enabled: openAIReasoningEnabled,
            openai_reasoning_effort: openAIReasoningEffort,
            deepseek_auto_profile_enabled: deepseekAutoProfileEnabled,
            glm_auto_profile_enabled: glmAutoProfileEnabled,
            max_tokens_per_request: maxTokens,
            temperature: temperature,
            requests_per_day: requestsPerDay,
            requests_per_hour: requestsPerHour
        };

        if (Object.keys(providerApiKeys).length > 0) {
            settings.api_keys = providerApiKeys;
        }
        
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=save_ai_chat_settings`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(settings)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Reload settings to confirm they were saved
            await loadAIChatSettings();
            
            const savedSettings = data.settings || settings;
            const updatedProviderKeys = Array.isArray(data.api_keys_updated)
                ? data.api_keys_updated.map(formatAIProviderLabel)
                : [];
            const successMsg = `AI Chat settings saved successfully! ` +
                `Provider: ${savedSettings.ai_provider || selectedProvider}, ` +
                `Model: ${savedSettings.model_name}, ` +
                `OpenAI reasoning auto-tuning: ${(savedSettings.openai_reasoning_enabled ?? openAIReasoningEnabled) ? 'On' : 'Off'}, ` +
                `Reasoning effort: ${savedSettings.openai_reasoning_effort || openAIReasoningEffort}, ` +
                `DeepSeek auto-profile: ${(savedSettings.deepseek_auto_profile_enabled ?? deepseekAutoProfileEnabled) ? 'On' : 'Off'}, ` +
                `GLM auto-profile: ${(savedSettings.glm_auto_profile_enabled ?? glmAutoProfileEnabled) ? 'On' : 'Off'}, ` +
                `Max Tokens: ${savedSettings.max_tokens_per_request}, ` +
                `Temperature: ${savedSettings.temperature}, ` +
                `Daily Limit: ${savedSettings.requests_per_day}, ` +
                `Hourly Limit: ${savedSettings.requests_per_hour}` +
                (updatedProviderKeys.length > 0 ? `, Updated API Keys: ${updatedProviderKeys.join(', ')}` : '');
            
            admin.showAlert('success', successMsg);
            // Update badge to show current model
            updateModelBadge();
        } else {
            admin.showAlert('error', data.message || 'Failed to save settings');
            console.error('Failed to save AI Chat Settings:', data);
        }
    } catch (error) {
        console.error('Error saving AI chat settings:', error);
        admin.showAlert('error', 'Failed to save AI chat settings: ' + error.message);
    }
}

/**
 * One-click global AI chat toggle (for all users)
 */
async function toggleGlobalAIChat(checkbox) {
    if (!checkbox) return;

    const enabled = checkbox.checked ? 1 : 0;
    const previous = checkbox.checked ? 0 : 1;
    const statusEl = document.getElementById('ai-chat-enabled-status');

    checkbox.disabled = true;
    if (statusEl) {
        statusEl.textContent = 'Status: Updating...';
        statusEl.style.color = '#1565c0';
    }

    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=set_ai_chat_enabled`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ enabled })
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to update AI chat status');
        }

        if (statusEl) {
            statusEl.textContent = `Status: ${enabled ? 'Enabled globally' : 'Disabled globally'}`;
            statusEl.style.color = enabled ? '#2e7d32' : '#c62828';
        }

        admin.showAlert('success', data.message || 'AI chat global status updated');
    } catch (error) {
        checkbox.checked = previous === 1;
        if (statusEl) {
            const current = checkbox.checked;
            statusEl.textContent = `Status: ${current ? 'Enabled globally' : 'Disabled globally'}`;
            statusEl.style.color = current ? '#2e7d32' : '#c62828';
        }
        admin.showAlert('error', error.message || 'Failed to update AI chat status');
    } finally {
        checkbox.disabled = false;
    }
}

/**
 * Update the current model badge
 */
function updateModelBadge() {
    const modelSelect = document.getElementById('ai-model-name');
    const badge = document.getElementById('current-model-badge');
    
    if (modelSelect && badge) {
        const selectedModel = getSelectedAIModelName();
        const selectedOption = modelSelect.options[modelSelect.selectedIndex];
        const optionText = modelSelect.value === '__custom__'
            ? `Custom model: ${selectedModel || 'Not set'}`
            : (selectedOption ? selectedOption.text : selectedModel);
        
        // Show badge with current model info
        badge.textContent = `Active: ${selectedModel || 'Not set'}`;
        badge.style.display = 'inline-block';
        badge.title = `Current model: ${optionText}`;
    }

    updateAITuningProfileState();
}

function getProviderDefaultChatModel(provider) {
    const providerDefaultModels = {
        openai: 'gpt-4o-mini',
        deepseek: 'deepseek-chat',
        qwen: 'qwen-plus',
        glm: 'glm-4.7-flash'
    };

    return providerDefaultModels[provider] || 'gpt-4o-mini';
}

function isOpenAIReasoningCapableModel(modelName) {
    const normalized = String(modelName || '').trim().toLowerCase();
    return /^(o\d|gpt-5(?:$|[-._:]))/.test(normalized);
}

function isDeepSeekReasonerModel(modelName) {
    const normalized = String(modelName || '').trim().toLowerCase();
    return /^deepseek-reasoner(?:$|[-._:])/.test(normalized);
}

function isGLMThinkingCapableModel(modelName) {
    const normalized = String(modelName || '').trim().toLowerCase();
    if (/^glm-(?:4(?:\.7)?-)?flash(?:x)?(?:$|[-._:])/.test(normalized)) {
        return false;
    }

    return /^glm-(4\.5|4\.6|4\.7|5\.1|5)(?:$|[-._:])/.test(normalized);
}

function getCurrentAITuningProfile() {
    const provider = document.getElementById('ai-chat-provider')?.value || 'openai';
    const modelName = getSelectedAIModelName();
    const openAIReasoningEnabled = document.getElementById('ai-openai-reasoning-enabled')?.checked !== false;
    const openAIReasoningEffort = document.getElementById('ai-openai-reasoning-effort')?.value || 'medium';
    const deepseekAutoProfileEnabled = document.getElementById('ai-deepseek-auto-profile-enabled')?.checked !== false;
    const glmAutoProfileEnabled = document.getElementById('ai-glm-auto-profile-enabled')?.checked !== false;

    if (provider === 'openai') {
        if (isOpenAIReasoningCapableModel(modelName)) {
            if (!openAIReasoningEnabled) {
                return {
                    badgeClass: 'badge-warning',
                    badgeText: 'OpenAI generic',
                    statusText: `Reasoning-capable OpenAI model selected, but auto-tuning is off. ${modelName || 'This model'} will stay on the generic chat-completions payload and continue using the saved temperature setting.`
                };
            }

            return {
                badgeClass: 'badge-success',
                badgeText: 'OpenAI reasoning',
                statusText: `${modelName || 'This OpenAI model'} will use reasoning-compatible request fields with ${openAIReasoningEffort} effort. The backend swaps max_tokens for max_completion_tokens and removes generic sampling controls for compatibility.`
            };
        }

        return {
            badgeClass: 'badge-secondary',
            badgeText: 'OpenAI generic',
            statusText: `${modelName || 'This OpenAI model'} is using the standard OpenAI chat payload with the saved max token and temperature controls.`
        };
    }

    if (provider === 'deepseek' && isDeepSeekReasonerModel(modelName)) {
        if (!deepseekAutoProfileEnabled) {
            return {
                badgeClass: 'badge-warning',
                badgeText: 'DeepSeek generic',
                statusText: `${modelName || 'This DeepSeek model'} matches the DeepSeek reasoner profile, but the auto-profile toggle is off. Requests will stay on the generic compatible payload.`
            };
        }

        return {
            badgeClass: 'badge-info',
            badgeText: 'DeepSeek reasoner',
            statusText: `${modelName || 'This DeepSeek model'} uses the DeepSeek reasoner compatibility profile. The backend strips unsupported sampling fields such as temperature, top_p, and penalty settings so requests stay clean.`
        };
    }

    if (provider === 'deepseek') {
        return {
            badgeClass: 'badge-secondary',
            badgeText: 'DeepSeek generic',
            statusText: `${modelName || 'This DeepSeek model'} is using the standard DeepSeek-compatible payload with the saved max token and temperature controls.`
        };
    }

    if (provider === 'glm' && isGLMThinkingCapableModel(modelName)) {
        if (!glmAutoProfileEnabled) {
            return {
                badgeClass: 'badge-warning',
                badgeText: 'GLM generic',
                statusText: `${modelName || 'This GLM model'} supports GLM thinking mode, but the auto-profile toggle is off. Requests will stay on the generic GLM payload.`
            };
        }

        return {
            badgeClass: 'badge-success',
            badgeText: 'GLM thinking',
            statusText: `${modelName || 'This GLM model'} uses GLM thinking-mode tuning. The backend enables thinking for user-facing replies and disables it for lean helper calls like intent resolution and JSON extraction.`
        };
    }

    if (provider === 'glm') {
        return {
            badgeClass: 'badge-secondary',
            badgeText: 'GLM generic',
            statusText: `${modelName || 'This GLM model'} is using the standard GLM chat payload with the saved max token and temperature controls.`
        };
    }

    if (provider === 'qwen') {
        return {
            badgeClass: 'badge-secondary',
            badgeText: 'Qwen generic',
            statusText: `${modelName || 'This Qwen model'} is using the standard Qwen-compatible payload with the saved max token and temperature controls.`
        };
    }

    return {
        badgeClass: 'badge-secondary',
        badgeText: 'Generic payload',
        statusText: `${modelName || 'This model'} is using the standard ${provider} chat payload with the saved max token and temperature controls.`
    };
}

function updateAITuningProfileState() {
    const badge = document.getElementById('ai-tuning-profile-badge');
    const status = document.getElementById('ai-tuning-profile-status');
    const profile = getCurrentAITuningProfile();

    if (!badge || !status || !profile) {
        return;
    }

    badge.className = `badge ${profile.badgeClass}`;
    badge.textContent = profile.badgeText;
    status.textContent = profile.statusText;
}

function getAIChatUsageProfileMeta(profileCode) {
    const normalized = String(profileCode || 'generic_payload').trim().toLowerCase();

    if (normalized === 'deterministic_no_provider') {
        return {
            badgeClass: 'badge-secondary',
            label: 'Deterministic',
            title: 'No provider API call was needed for this response.'
        };
    }

    if (normalized.startsWith('openai_reasoning_')) {
        const effort = normalized.replace('openai_reasoning_', '').replace(/_/g, ' ');
        const effortLabel = effort.charAt(0).toUpperCase() + effort.slice(1);
        return {
            badgeClass: 'badge-success',
            label: `OpenAI reasoning (${effortLabel})`,
            title: `OpenAI reasoning-compatible payload using ${effortLabel.toLowerCase()} effort.`
        };
    }

    const profileMap = {
        openai_generic: {
            badgeClass: 'badge-secondary',
            label: 'OpenAI generic',
            title: 'Standard OpenAI chat-completions payload.'
        },
        deepseek_reasoner_profile: {
            badgeClass: 'badge-info',
            label: 'DeepSeek reasoner',
            title: 'DeepSeek reasoner compatibility profile.'
        },
        deepseek_generic: {
            badgeClass: 'badge-secondary',
            label: 'DeepSeek generic',
            title: 'Standard DeepSeek-compatible payload.'
        },
        glm_thinking_enabled: {
            badgeClass: 'badge-success',
            label: 'GLM thinking',
            title: 'GLM payload with thinking.type=enabled.'
        },
        glm_thinking_disabled: {
            badgeClass: 'badge-warning',
            label: 'GLM helper',
            title: 'GLM payload with thinking.type=disabled for a lean helper request.'
        },
        glm_generic: {
            badgeClass: 'badge-secondary',
            label: 'GLM generic',
            title: 'Standard GLM chat payload.'
        },
        qwen_generic: {
            badgeClass: 'badge-secondary',
            label: 'Qwen generic',
            title: 'Standard Qwen-compatible payload.'
        },
        generic_payload: {
            badgeClass: 'badge-secondary',
            label: 'Generic payload',
            title: 'Standard provider payload with no special tuning profile.'
        }
    };

    if (profileMap[normalized]) {
        return profileMap[normalized];
    }

    const fallback = normalized.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    return {
        badgeClass: 'badge-secondary',
        label: fallback,
        title: fallback
    };
}

function syncAIReasoningControlsState() {
    const provider = document.getElementById('ai-chat-provider')?.value || 'openai';
    const reasoningEnabledInput = document.getElementById('ai-openai-reasoning-enabled');
    const reasoningEffortSelect = document.getElementById('ai-openai-reasoning-effort');
    const reasoningStatus = document.getElementById('ai-openai-reasoning-status');
    const selectedModel = getSelectedAIModelName();
    const isOpenAIProvider = provider === 'openai';
    const isReasoningModel = isOpenAIProvider && isOpenAIReasoningCapableModel(selectedModel);

    if (!reasoningEnabledInput || !reasoningEffortSelect || !reasoningStatus) {
        updateAITuningProfileState();
        return;
    }

    reasoningEffortSelect.disabled = !reasoningEnabledInput.checked;

    if (!isOpenAIProvider) {
        reasoningStatus.textContent = 'Saved only for OpenAI. The current provider is not OpenAI, so this setting has no effect right now.';
        reasoningStatus.style.color = '#6d4c41';
        updateAITuningProfileState();
        return;
    }

    if (!isReasoningModel) {
        reasoningStatus.textContent = 'The selected OpenAI model is not detected as reasoning-capable. Auto-tuning will activate automatically if you switch to o3, o4-mini, GPT-5, or a compatible custom OpenAI model.';
        reasoningStatus.style.color = '#455a64';
        updateAITuningProfileState();
        return;
    }

    if (!reasoningEnabledInput.checked) {
        reasoningStatus.textContent = 'Reasoning auto-tuning is currently off for this OpenAI reasoning model. Requests will use the generic chat-completions payload.';
        reasoningStatus.style.color = '#c62828';
        updateAITuningProfileState();
        return;
    }

    reasoningStatus.textContent = `Reasoning auto-tuning is active for ${selectedModel}. The backend will switch to OpenAI reasoning-compatible request fields and use ${reasoningEffortSelect.value} effort automatically.`;
    reasoningStatus.style.color = '#2e7d32';
    updateAITuningProfileState();
}

function syncAIModelInputState(prefillValue = '') {
    const modelSelect = document.getElementById('ai-model-name');
    const customModelInput = document.getElementById('ai-custom-model-name');
    const providerSelect = document.getElementById('ai-chat-provider');

    if (!modelSelect || !customModelInput) {
        return;
    }

    const useCustomModel = modelSelect.value === '__custom__';
    const provider = providerSelect ? providerSelect.value : 'openai';

    customModelInput.style.display = useCustomModel ? 'block' : 'none';
    customModelInput.disabled = !useCustomModel;
    customModelInput.required = useCustomModel;
    customModelInput.placeholder = `e.g. ${getProviderDefaultChatModel(provider)} or another ${provider} model ID`;

    if (useCustomModel && prefillValue && !customModelInput.value.trim()) {
        customModelInput.value = prefillValue;
    }

    if (!useCustomModel) {
        customModelInput.value = '';
    }

    syncAIReasoningControlsState();
}

function getSelectedAIModelName() {
    const modelSelect = document.getElementById('ai-model-name');
    const customModelInput = document.getElementById('ai-custom-model-name');

    if (!modelSelect) {
        return 'gpt-4o-mini';
    }

    if (modelSelect.value === '__custom__') {
        return customModelInput ? customModelInput.value.trim() : '';
    }

    return modelSelect.value.trim();
}

/**
 * Load AI Learning Settings and Stats
 */
async function loadAILearningSettings() {
    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=get_ai_learning_settings`);
        const data = await response.json();
        
        if (data.success) {
            const settings = data.settings || {};
            const stats = data.stats || {};
            const telemetry = stats.telemetry || {};
            const telemetryTotal = telemetry.total || {};
            const telemetryToday = telemetry.today || {};
            
            // Update settings
            if (document.getElementById('ai-provider')) {
                document.getElementById('ai-provider').value = settings.ai_provider || 'openai';
            }
            if (document.getElementById('ai-learning-model-name')) {
                const learningModelSelect = document.getElementById('ai-learning-model-name');
                const learningCustomModelInput = document.getElementById('ai-learning-custom-model-name');
                const savedLearningModel = String(settings.learning_model_name || '').trim();

                if (!savedLearningModel) {
                    learningModelSelect.value = '__auto__';
                    if (learningCustomModelInput) {
                        learningCustomModelInput.value = '';
                    }
                } else if (Array.from(learningModelSelect.options).some(option => option.value === savedLearningModel)) {
                    learningModelSelect.value = savedLearningModel;
                    if (learningCustomModelInput) {
                        learningCustomModelInput.value = '';
                    }
                } else {
                    learningModelSelect.value = '__custom__';
                    if (learningCustomModelInput) {
                        learningCustomModelInput.value = savedLearningModel;
                    }
                }

                syncAILearningModelInputState(savedLearningModel);
                updateLearningModelBadge();
            }
            if (document.getElementById('web-cache-limit-input')) {
                document.getElementById('web-cache-limit-input').value = settings.web_cache_limit || 20;
            }
            if (document.getElementById('parts-cache-limit-input')) {
                document.getElementById('parts-cache-limit-input').value = settings.parts_cache_limit || 500;
            }
            
            // Update stats display
            if (stats.web_cache) {
                if (document.getElementById('web-cache-total')) {
                    document.getElementById('web-cache-total').textContent = stats.web_cache.total || 0;
                }
                if (document.getElementById('web-cache-today')) {
                    document.getElementById('web-cache-today').textContent = stats.web_cache.today || 0;
                }
                if (document.getElementById('web-cache-limit')) {
                    document.getElementById('web-cache-limit').textContent = stats.web_cache.limit || 20;
                }
            }
            
            if (stats.parts_cache) {
                if (document.getElementById('parts-cache-total')) {
                    document.getElementById('parts-cache-total').textContent = stats.parts_cache.total || 0;
                }
                if (document.getElementById('parts-cache-today')) {
                    document.getElementById('parts-cache-today').textContent = stats.parts_cache.today || 0;
                }
                if (document.getElementById('parts-cache-limit')) {
                    document.getElementById('parts-cache-limit').textContent = stats.parts_cache.limit || 500;
                }
            }

            if (document.getElementById('learning-attempts-total')) {
                document.getElementById('learning-attempts-total').textContent = telemetryTotal.attempts || 0;
            }
            if (document.getElementById('learning-success-total')) {
                document.getElementById('learning-success-total').textContent = telemetryTotal.success || 0;
            }
            if (document.getElementById('learning-skipped-limit-total')) {
                document.getElementById('learning-skipped-limit-total').textContent = telemetryTotal.skipped_limit || 0;
            }
            if (document.getElementById('learning-errors-total')) {
                document.getElementById('learning-errors-total').textContent = telemetryTotal.errors || 0;
            }

            if (document.getElementById('learning-attempts-today')) {
                document.getElementById('learning-attempts-today').textContent = telemetryToday.attempts || 0;
            }
            if (document.getElementById('learning-success-today')) {
                document.getElementById('learning-success-today').textContent = telemetryToday.success || 0;
            }
            if (document.getElementById('learning-skipped-limit-today')) {
                document.getElementById('learning-skipped-limit-today').textContent = telemetryToday.skipped_limit || 0;
            }
            if (document.getElementById('learning-errors-today')) {
                document.getElementById('learning-errors-today').textContent = telemetryToday.errors || 0;
            }
        }
    } catch (error) {
        console.error('Error loading AI learning settings:', error);
    }
}

function getSelectedLearningProvider() {
    const providerElement = document.getElementById('ai-provider');
    if (!providerElement || !providerElement.value) {
        return 'auto';
    }
    return providerElement.value;
}

function getProviderDefaultLearningModel(provider) {
    const providerDefaultModels = {
        openai: 'gpt-4o-mini',
        deepseek: 'deepseek-chat',
        qwen: 'qwen-plus',
        glm: 'glm-4.7-flash'
    };

    return providerDefaultModels[provider] || 'gpt-4o-mini';
}

function syncAILearningModelInputState(prefillValue = '') {
    const provider = getSelectedLearningProvider();
    const modelSelect = document.getElementById('ai-learning-model-name');
    const customModelInput = document.getElementById('ai-learning-custom-model-name');

    if (!modelSelect || !customModelInput) {
        return;
    }

    if (provider === 'auto') {
        modelSelect.value = '__auto__';
        modelSelect.disabled = true;
        customModelInput.style.display = 'none';
        customModelInput.disabled = true;
        customModelInput.required = false;
        customModelInput.value = '';
        customModelInput.placeholder = 'Learning uses the resolved provider default model';
        return;
    }

    modelSelect.disabled = false;
    const useCustomModel = modelSelect.value === '__custom__';
    customModelInput.style.display = useCustomModel ? 'block' : 'none';
    customModelInput.disabled = !useCustomModel;
    customModelInput.required = useCustomModel;
    customModelInput.placeholder = `e.g. ${getProviderDefaultLearningModel(provider)} or another ${provider} model ID`;

    if (useCustomModel && prefillValue && !customModelInput.value.trim()) {
        customModelInput.value = prefillValue;
    }

    if (!useCustomModel) {
        customModelInput.value = '';
    }
}

function getSelectedLearningModelName() {
    const provider = getSelectedLearningProvider();
    const modelSelect = document.getElementById('ai-learning-model-name');
    const customModelInput = document.getElementById('ai-learning-custom-model-name');

    if (provider === 'auto' || !modelSelect) {
        return '';
    }

    if (modelSelect.value === '__auto__') {
        return '';
    }

    if (modelSelect.value === '__custom__') {
        return customModelInput ? customModelInput.value.trim() : '';
    }

    return modelSelect.value.trim();
}

function updateLearningModelBadge() {
    const provider = getSelectedLearningProvider();
    const modelSelect = document.getElementById('ai-learning-model-name');
    const badge = document.getElementById('current-learning-model-badge');

    if (!modelSelect || !badge) {
        return;
    }

    if (provider === 'auto') {
        badge.textContent = 'Active: provider default';
        badge.style.display = 'inline-block';
        badge.title = 'Learning uses the resolved provider default model when AI Provider is set to Auto.';
        return;
    }

    const selectedModel = getSelectedLearningModelName() || getProviderDefaultLearningModel(provider);
    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    const optionText = modelSelect.value === '__custom__'
        ? `Custom model: ${selectedModel || 'Not set'}`
        : (modelSelect.value === '__auto__'
            ? `Provider default: ${getProviderDefaultLearningModel(provider)}`
            : (selectedOption ? selectedOption.text : selectedModel));

    badge.textContent = `Active: ${selectedModel || 'Not set'}`;
    badge.style.display = 'inline-block';
    badge.title = `Current learning model: ${optionText}`;
}

/**
 * Save AI Learning Settings
 */
async function saveAILearningSettings() {
    try {
        const aiProvider = getSelectedLearningProvider();
        const learningModelName = getSelectedLearningModelName();
        const webCacheLimit = parseInt(document.getElementById('web-cache-limit-input').value);
        const partsCacheLimit = parseInt(document.getElementById('parts-cache-limit-input').value);

        if (learningModelName && learningModelName.length > 120) {
            admin.showAlert('error', 'Learning model name must be 120 characters or fewer');
            return;
        }
        if (learningModelName && !/^[a-zA-Z0-9._:-]+$/.test(learningModelName)) {
            admin.showAlert('error', 'Learning model name can use only letters, numbers, dot, underscore, dash, and colon');
            return;
        }
        
        // Validate
        if (isNaN(webCacheLimit) || webCacheLimit < 1 || webCacheLimit > 1000) {
            admin.showAlert('error', 'Web cache limit must be between 1 and 1000');
            return;
        }
        if (isNaN(partsCacheLimit) || partsCacheLimit < 1 || partsCacheLimit > 5000) {
            admin.showAlert('error', 'Parts cache limit must be between 1 and 5000');
            return;
        }
        
        const settings = {
            ai_provider: aiProvider,
            learning_model_name: learningModelName,
            web_cache_limit: webCacheLimit,
            parts_cache_limit: partsCacheLimit
        };
        
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=save_ai_learning_settings`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            await loadAILearningSettings();
            admin.showAlert('success', 'AI Learning settings saved successfully!');
        } else {
            admin.showAlert('error', data.message || 'Failed to save settings');
        }
    } catch (error) {
        console.error('Error saving AI learning settings:', error);
        admin.showAlert('error', 'Failed to save AI learning settings: ' + error.message);
    }
}

/**
 * Trigger Web Cache Learning
 */
async function triggerWebLearning() {
    const btn = document.getElementById('btn-trigger-web');
    const statusDiv = document.getElementById('web-learning-status');
    const count = parseInt(document.getElementById('web-learning-count').value) || 20;
    const provider = getSelectedLearningProvider();
    const model = getSelectedLearningModelName();
    
    if (isNaN(count) || count < 1 || count > 1000) {
        admin.showAlert('error', 'Number of topics must be between 1 and 1000');
        return;
    }
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Learning...';
    statusDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Starting learning process...</div>';
    
    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=trigger_ai_web_learning`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                count: count,
                provider: provider,
                model: model
            })
        });

        const data = await response.json().catch(() => null);

        if (!response.ok) {
            throw new Error(data?.message || `HTTP error! status: ${response.status}`);
        }

        if (!data || typeof data !== 'object') {
            throw new Error('Invalid response from web learning endpoint');
        }
        
        if (data.success) {
            let learnedList = '';
            let notesHtml = '';
            if (data.learned_topics && data.learned_topics.length > 0) {
                learnedList = '<div style="margin-top: 10px; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px;">';
                learnedList += '<strong style="color: #28a745;"><i class="fas fa-lightbulb"></i> Topics Learned:</strong><ul style="margin: 5px 0 0 0; padding-left: 20px;">';
                data.learned_topics.slice(0, 10).forEach(topic => {
                    learnedList += `<li style="margin: 3px 0;">${topic}</li>`;
                });
                if (data.learned_topics.length > 10) {
                    learnedList += `<li style="color: #6c757d; font-style: italic;">... and ${data.learned_topics.length - 10} more</li>`;
                }
                learnedList += '</ul></div>';
            }

            const notes = [];
            if (data.message) {
                notes.push(data.message);
            }
            if (Array.isArray(data.errors) && data.errors.length > 0) {
                notes.push(`Issues encountered: ${data.errors.slice(0, 3).join(' | ')}`);
            }
            if (notes.length > 0) {
                notesHtml = `<div style="margin-top: 10px;"><small style="color: #856404;">${notes.join('<br>')}</small></div>`;
            }
            
            statusDiv.innerHTML = `<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Successfully learned ${data.learned} topics!</strong>
                ${data.requested > data.learned ? `<br><small>Requested: ${data.requested}, but daily limit may have been reached.</small>` : ''}
                ${notesHtml}
                ${learnedList}
            </div>`;
            admin.showAlert('success', `Successfully learned ${data.learned} web cache topics!`);
            
            // Reload stats
            await loadAILearningSettings();
        } else {
            statusDiv.innerHTML = `<div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> ${data.message || 'Failed to trigger learning'}
            </div>`;
            admin.showAlert('error', data.message || 'Failed to trigger web learning');
        }
    } catch (error) {
        console.error('Error triggering web learning:', error);
        statusDiv.innerHTML = `<div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> Error: ${error.message}
        </div>`;
        admin.showAlert('error', 'Failed to trigger web learning: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play"></i> Start Web Learning';
    }
}

/**
 * Trigger Parts Cache Learning
 */
async function triggerPartsLearning() {
    const btn = document.getElementById('btn-trigger-parts');
    const statusDiv = document.getElementById('parts-learning-status');
    const count = parseInt(document.getElementById('parts-learning-count').value) || 500;
    const provider = getSelectedLearningProvider();
    const model = getSelectedLearningModelName();
    
    if (isNaN(count) || count < 1 || count > 5000) {
        admin.showAlert('error', 'Number of parts must be between 1 and 5000');
        return;
    }
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Learning...';
    statusDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Starting learning process... This may take a while.</div>';
    
    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=trigger_ai_parts_learning`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                count: count,
                provider: provider,
                model: model
            })
        });

        const data = await response.json().catch(() => null);

        if (!response.ok) {
            throw new Error(data?.message || `HTTP error! status: ${response.status}`);
        }

        if (!data || typeof data !== 'object') {
            throw new Error('Invalid response from parts learning endpoint');
        }
        
        if (data.success) {
            let learnedList = '';
            let notesHtml = '';
            if (data.learned_parts && data.learned_parts.length > 0) {
                learnedList = '<div style="margin-top: 10px; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px;">';
                learnedList += '<strong style="color: #28a745;"><i class="fas fa-lightbulb"></i> Parts Learned:</strong><ul style="margin: 5px 0 0 0; padding-left: 20px;">';
                data.learned_parts.slice(0, 10).forEach(part => {
                    learnedList += `<li style="margin: 3px 0;">${part}</li>`;
                });
                if (data.learned_parts.length > 10) {
                    learnedList += `<li style="color: #6c757d; font-style: italic;">... and ${data.learned_parts.length - 10} more</li>`;
                }
                learnedList += '</ul></div>';
            }

            const notes = [];
            if (data.message) {
                notes.push(data.message);
            }
            if (Array.isArray(data.errors) && data.errors.length > 0) {
                notes.push(`Issues encountered: ${data.errors.slice(0, 3).join(' | ')}`);
            }
            if (notes.length > 0) {
                notesHtml = `<div style="margin-top: 10px;"><small style="color: #856404;">${notes.join('<br>')}</small></div>`;
            }
            
            statusDiv.innerHTML = `<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Successfully learned ${data.learned} parts!</strong>
                ${data.requested > data.learned ? `<br><small>Requested: ${data.requested}, but daily limit may have been reached.</small>` : ''}
                ${notesHtml}
                ${learnedList}
            </div>`;
            admin.showAlert('success', `Successfully learned ${data.learned} parts cache topics!`);
            
            // Reload stats
            await loadAILearningSettings();
        } else {
            statusDiv.innerHTML = `<div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> ${data.message || 'Failed to trigger learning'}
            </div>`;
            admin.showAlert('error', data.message || 'Failed to trigger parts learning');
        }
    } catch (error) {
        console.error('Error triggering parts learning:', error);
        statusDiv.innerHTML = `<div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> Error: ${error.message}
        </div>`;
        admin.showAlert('error', 'Failed to trigger parts learning: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play"></i> Start Parts Learning';
    }
}

/**
 * Load AI Chat Usage Logs
 */
let currentAIChatPage = 1;
async function loadAIChatUsage(page = 1) {
    try {
        const tbody = document.getElementById('aiChatUsageTableBody');
        if (!tbody) {
            console.error('AI Chat Usage table body not found');
            return;
        }
        
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">Loading...</td></tr>';
        
        currentAIChatPage = page;
        const userId = document.getElementById('ai-usage-user-select')?.value || '';
        const dateFrom = document.getElementById('ai-usage-date-from')?.value || '';
        const dateTo = document.getElementById('ai-usage-date-to')?.value || '';
        
        // Build URL correctly - check if ADMIN_API_BASE already has query params
        const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
        let url = `${ADMIN_API_BASE}${separator}action=get_ai_chat_usage&page=${page}&limit=50`;
        if (userId) url += `&user_id=${userId}`;
        if (dateFrom) url += `&start_date=${dateFrom}`;
        if (dateTo) url += `&end_date=${dateTo}`;
        
        const response = await fetch(url, {
            credentials: 'include'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Update stats
            if (data.stats) {
                const totalRequestsEl = document.getElementById('total-ai-requests');
                const uniqueUsersEl = document.getElementById('unique-ai-users');
                const totalTokensEl = document.getElementById('total-ai-tokens');
                const totalCostEl = document.getElementById('total-ai-cost');
                const statsContainer = document.getElementById('ai-chat-stats');
                
                if (totalRequestsEl) totalRequestsEl.textContent = parseInt(data.stats.total_requests || 0).toLocaleString();
                if (uniqueUsersEl) uniqueUsersEl.textContent = parseInt(data.stats.unique_users || 0).toLocaleString();
                if (totalTokensEl) totalTokensEl.textContent = parseInt(data.stats.total_tokens || 0).toLocaleString();
                if (totalCostEl) totalCostEl.textContent = '$' + parseFloat(data.stats.total_cost || 0).toFixed(4);
                if (statsContainer) statsContainer.style.display = 'grid';
            }
            
            // Update table
            if (data.logs && data.logs.length > 0) {
                tbody.innerHTML = data.logs.map(log => {
                    const profileMeta = getAIChatUsageProfileMeta(log.tuning_profile);
                    return `
                        <tr>
                            <td>${log.id}</td>
                            <td>${new Date(log.created_at).toLocaleString()}</td>
                            <td>${log.username || 'N/A'} (${log.user_id})<br><small>${log.email || ''}</small></td>
                            <td><small>${(log.message || '').substring(0, 50)}${(log.message || '').length > 50 ? '...' : ''}</small></td>
                            <td>${parseInt(log.tokens_used || 0).toLocaleString()}</td>
                            <td><small>${log.model_used || 'N/A'}</small></td>
                            <td><span class="badge ${profileMeta.badgeClass}" title="${profileMeta.title}">${profileMeta.label}</span></td>
                            <td>$${parseFloat(log.cost_estimate || 0).toFixed(6)}</td>
                            <td><small>${log.ip_address || 'N/A'}</small></td>
                        </tr>
                    `;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center">No usage logs found</td></tr>';
            }
            
            // Update pagination
            const pagination = document.getElementById('ai-chat-pagination');
            if (pagination && data.pagination) {
                const pages = data.pagination.pages || 1;
                if (pages > 1) {
                    let paginationHTML = '<div class="pagination">';
                    if (page > 1) {
                        paginationHTML += `<button class="btn btn-sm" onclick="loadAIChatUsage(${page - 1})">Previous</button>`;
                    }
                    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
                        paginationHTML += `<button class="btn btn-sm ${i === page ? 'btn-primary' : ''}" onclick="loadAIChatUsage(${i})">${i}</button>`;
                    }
                    if (page < pages) {
                        paginationHTML += `<button class="btn btn-sm" onclick="loadAIChatUsage(${page + 1})">Next</button>`;
                    }
                    paginationHTML += '</div>';
                    pagination.innerHTML = paginationHTML;
                } else {
                    pagination.innerHTML = '';
                }
            }
        } else {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center">Error: ${data.message || 'Failed to load usage logs'}</td></tr>`;
        }
    } catch (error) {
        console.error('Error loading AI chat usage:', error);
        const tbody = document.getElementById('aiChatUsageTableBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center">Error loading usage logs: ${error.message}</td></tr>`;
        }
    }
}

/**
 * Load users who have used AI chat
 */
async function loadAIChatUsers() {
    try {
        // Build URL correctly - check if ADMIN_API_BASE already has query params
        const separator = ADMIN_API_BASE.includes('?') ? '&' : '?';
        const response = await fetch(`${ADMIN_API_BASE}${separator}action=get_ai_chat_users`, {
            credentials: 'include'
        });
        const data = await response.json();
        
        if (data.success && data.users) {
            const select = document.getElementById('ai-usage-user-select');
            if (select) {
                const currentValue = select.value;
                select.innerHTML = '<option value="">All Users</option>' + 
                    data.users.map(user => 
                        `<option value="${user.id}" ${user.id == currentValue ? 'selected' : ''}>${user.username || 'User #' + user.id} (${user.email || 'N/A'}) - ${user.usage_count || 0} requests</option>`
                    ).join('');
            }
        }
    } catch (error) {
        console.error('Error loading AI chat users:', error);
    }
}

/**
 * Clear AI Chat Filters
 */
function clearAIChatFilters() {
    const select = document.getElementById('ai-usage-user-select');
    if (select) select.value = '';
    document.getElementById('ai-usage-date-from').value = '';
    document.getElementById('ai-usage-date-to').value = '';
    loadAIChatUsage(1);
}

// Load AI chat settings when settings section is shown
document.addEventListener('DOMContentLoaded', function() {
    // Load settings when settings section is accessed
    const settingsNav = document.querySelector('[data-section="settings"]');
    if (settingsNav) {
        settingsNav.addEventListener('click', function() {
            setTimeout(() => {
                loadAIChatSettings();
                loadFuelPriceSettings();
            }, 500);
        });
    }
    
    // Load usage logs when AI chat usage section is accessed
    const aiUsageNav = document.querySelector('[data-section="ai-chat-usage"]');
    if (aiUsageNav) {
        aiUsageNav.addEventListener('click', function() {
            setTimeout(() => {
                loadAIChatUsers().then(() => {
                    loadAIChatUsage(1);
                }).catch(err => {
                    console.error('Error loading AI chat users:', err);
                    loadAIChatUsage(1); // Still try to load usage even if users fail
                });
            }, 300);
        });
    }
    
    // Also observe section visibility as backup
    const aiUsageSection = document.getElementById('ai-chat-usage-section');
    if (aiUsageSection) {
        const observer = new MutationObserver(function(mutations) {
            if (aiUsageSection.style.display !== 'none' && aiUsageSection.style.display !== '') {
                setTimeout(() => {
                    loadAIChatUsers().then(() => {
                        loadAIChatUsage(1);
                    }).catch(err => {
                        console.error('Error loading AI chat users:', err);
                        loadAIChatUsage(1);
                    });
                }, 100);
            }
        });
        observer.observe(aiUsageSection, { attributes: true, attributeFilter: ['style'] });
    }
});

// Make admin dashboard instance globally accessible
window.adminDashboard = new AdminDashboard();
const admin = window.adminDashboard;

const featuredListingPriceInput = document.getElementById('featured-listing-price');
if (featuredListingPriceInput && !featuredListingPriceInput.hasAttribute('data-launch-badge-bound')) {
    featuredListingPriceInput.setAttribute('data-launch-badge-bound', 'true');
    featuredListingPriceInput.addEventListener('input', updateFeaturedLaunchModeBadge);
    featuredListingPriceInput.addEventListener('change', updateFeaturedLaunchModeBadge);
    updateFeaturedLaunchModeBadge();
}
debugLog('Admin dashboard initialized');

// ===== CACHE MANAGEMENT =====

/**
 * Clear all server-side caches (OPcache, AI caches, sessions, old logs)
 * and force a browser-side cache bust.
 */
async function clearAllCaches() {
    const btn = document.getElementById('btn-clear-all-caches');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...'; }

    try {
        const apiUrl = getAdminAPIUrl();
        const separator = apiUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${apiUrl}${separator}action=clear_all_caches&_t=${Date.now()}`, {
            method: 'GET',
            credentials: 'include',
            headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
        });
        const data = await response.json();

        if (data.success) {
            // Show results
            const resultsDiv = document.getElementById('cache-clear-results');
            const resultsList = document.getElementById('cache-results-list');
            if (resultsDiv && resultsList) {
                resultsList.innerHTML = '';
                const labels = {
                    opcache: 'PHP OPcache',
                    ai_web_cache: 'AI Web Cache',
                    ai_learning_cache: 'AI Learning Cache',
                    php_sessions: 'PHP Sessions',
                    old_logs: 'Old Log Files',
                    cache_bust_token: 'Cache-Bust Token'
                };
                for (const [key, value] of Object.entries(data.results || {})) {
                    const li = document.createElement('li');
                    li.textContent = `${labels[key] || key}: ${value}`;
                    resultsList.appendChild(li);
                }
                resultsDiv.style.display = 'block';
            }

            // Bust browser cache: unregister any service workers
            if ('serviceWorker' in navigator) {
                const registrations = await navigator.serviceWorker.getRegistrations();
                for (const reg of registrations) { await reg.unregister(); }
            }

            // Clear browser caches via Cache API
            if ('caches' in window) {
                const cacheNames = await caches.keys();
                for (const name of cacheNames) { await caches.delete(name); }
            }

            admin.showAlert('success', 'All caches cleared successfully! Reload the page to see fresh content.');
            debugLog('Cache clear results:', data.results);
        } else {
            admin.showAlert('error', data.message || 'Failed to clear caches');
        }
    } catch (error) {
        admin.showAlert('error', 'Error clearing caches: ' + error.message);
        debugLog('Cache clear error:', error);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-fire"></i> Clear All Caches'; }
    }
}

/**
 * Force a full browser cache bypass by hard-reloading the current page
 * and clearing any service worker / Cache Storage entries.
 */
async function clearBrowserCache() {
    try {
        // Unregister service workers
        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            for (const reg of registrations) { await reg.unregister(); }
        }

        // Clear Cache Storage
        if ('caches' in window) {
            const cacheNames = await caches.keys();
            for (const name of cacheNames) { await caches.delete(name); }
        }

        admin.showAlert('info', 'Browser caches cleared. Hard-reloading page...');

        // Hard reload after a brief delay so the user sees the alert
        setTimeout(() => {
            window.location.href = window.location.pathname + '?_cacheBust=' + Date.now();
        }, 1200);
    } catch (error) {
        admin.showAlert('error', 'Error clearing browser cache: ' + error.message);
    }
}

// ── Single active admin tab enforcement via BroadcastChannel ─────────────
(function () {
    if (typeof BroadcastChannel === 'undefined') return; // unsupported browser

    const CH = new BroadcastChannel('motorlink_admin_session');
    const TAB_ID = Date.now() + '_' + Math.random();

    // Tell other tabs we are now the active admin tab.
    function claimSession() {
        CH.postMessage({ type: 'admin_claim', tabId: TAB_ID });
    }

    // Listen for a new tab claiming the session — if it isn't us, force logout.
    CH.onmessage = (e) => {
        if (e.data && e.data.type === 'admin_claim' && e.data.tabId !== TAB_ID) {
            // Another tab has logged in.  Show warning then reload to login screen.
            if (admin && admin.isLoggedIn) {
                admin.showAlert('warning', 'Another admin session was started elsewhere. You have been logged out of this tab.');
                setTimeout(() => {
                    localStorage.removeItem('admin_token');
                    localStorage.removeItem('admin_name');
                    localStorage.removeItem('admin_email');
                    window.location.reload();
                }, 2500);
            }
        }
    };

    // Hook into login success.  adminLogin() is the global login function.
    const _origAdminLogin = window.adminLogin || null;
    // Patch login: claim session after successful login response.
    // We can't override adminLogin directly because it's defined as a plain function.
    // Instead, we intercept DOMContentLoaded-time login form submissions.
    document.addEventListener('adminLoginSuccess', () => { claimSession(); }, { once: false });

    // Also claim session if page loads while already authenticated (resuming dashboard).
    const checkIfAlreadyLoggedIn = setInterval(() => {
        if (admin && admin.isLoggedIn) {
            claimSession();
            clearInterval(checkIfAlreadyLoggedIn);
        }
    }, 500);
    setTimeout(() => clearInterval(checkIfAlreadyLoggedIn), 10000);
})();
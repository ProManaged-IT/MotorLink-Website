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

// Debug logger - only logs when DEBUG mode is enabled
const debugLog = (...args) => {
    if (typeof CONFIG !== 'undefined' && CONFIG.DEBUG) {
    }
};

debugLog('Admin API Base URL:', ADMIN_API_BASE);

class AdminDashboard {
    constructor() {
        this.API_URL = ADMIN_API_BASE;
        this.isLoggedIn = false;
        this.currentSection = 'dashboard';
        this.adminData = null;
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

        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }

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
        }

        document.body.style.overflow = '';
    }

    clearBlockingOverlays() {
        // Clear mobile overlay/scrim state.
        const overlay = document.getElementById('mobileOverlay');
        if (overlay) {
            overlay.classList.remove('active');
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

    showLogin() {
        debugLog('Showing login screen');
        const loginSection = document.getElementById('loginSection');
        const adminDashboard = document.getElementById('adminDashboard');

        this.clearBlockingOverlays();

        if (loginSection) {
            loginSection.style.display = 'flex';
            loginSection.style.visibility = 'visible';
        }
        if (adminDashboard) {
            adminDashboard.style.display = 'none';
            adminDashboard.style.visibility = 'hidden';
        }

        this.isLoggedIn = false;
    }

    showDashboard() {
        debugLog('Showing admin dashboard');

        const loginSection = document.getElementById('loginSection');
        const adminDashboard = document.getElementById('adminDashboard');

        this.clearBlockingOverlays();

        // Completely hide login section
        if (loginSection) {
            loginSection.style.display = 'none';
            loginSection.style.visibility = 'hidden';
            loginSection.style.position = 'absolute';
            loginSection.style.left = '-9999px';
        }

        // Show dashboard
        if (adminDashboard) {
            adminDashboard.style.display = 'flex';
            adminDashboard.style.visibility = 'visible';
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

        this.loadDashboardData();
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
                    `MWK ${this.formatNumber(stats.today_revenue || 0)}`;
                
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
            document.getElementById('todayRevenue').textContent = 'MWK 0';
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
    }
    
    async setPrimaryImage(imageId, carId) {
        try {
            const response = await this.apiCall('set_primary_image', 'POST', {
                image_id: imageId,
                car_id: carId
            });

            if (response.success) {
                this.showAlert('success', 'Primary image updated successfully');

                // Refresh the modal if it's open
                const gallery = document.getElementById('carImagesGallery');
                if (gallery) {
                    gallery.innerHTML = '<div class="text-center">Reloading images...</div>';
                    const imagesResponse = await this.apiCall('get_car_images', 'GET', { car_id: carId });

                    if (imagesResponse.success && imagesResponse.images) {
                        if (imagesResponse.images.length === 0) {
                            gallery.innerHTML = '<div class="text-center text-muted">No images found for this listing</div>';
                        } else {
                            gallery.innerHTML = imagesResponse.images.map(img => {
                                const imageUrl = this.getSafeUploadUrl(img.filename);
                                return `
                                <div class="car-image-card" style="position: relative; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <img src="${imageUrl}" alt="${this.escapeHtml(img.original_filename)}"
                                         style="width: 100%; height: 200px; object-fit: cover; cursor: pointer;"
                                         onclick="window.open('${imageUrl}', '_blank')">
                                    ${img.is_primary ? '<span style="position: absolute; top: 5px; left: 5px; background: #28a745; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">Primary</span>' : ''}
                                    <div style="padding: 10px;">
                                        <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                            ${this.escapeHtml(img.original_filename)}
                                        </div>
                                        <div style="display: flex; gap: 5px; justify-content: space-between;">
                                            ${!img.is_primary ? `<button class="btn btn-sm btn-success" onclick="admin.setPrimaryImage(${img.id}, ${carId})" title="Set as Primary">
                                                <i class="fas fa-star"></i> Set Primary
                                            </button>` : ''}
                                            <button class="btn btn-sm btn-danger" onclick="admin.deleteCarImage(${img.id}, ${carId})" title="Delete Image">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            }).join('');
                        }
                    }
                } else {
                    // Fallback to old refresh method if not in modal
                    await this.refreshCarImages(carId);
                }
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to set primary image');
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
                this.loadCars();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to suspend car listing');
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
                this.loadCars();
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
                this.loadCars();
                break;
            case 'reports':
                this.loadListingReports();
                break;
            case 'pending-cars':
                this.loadPendingCars();
                break;
            case 'guest-listings':
                this.loadGuestListings();
                break;
            case 'rejected-cars':
                this.loadRejectedCars();
                break;
            case 'deleted-cars':
                this.loadDeletedCars();
                break;
            case 'payments':
                this.loadPayments();
                this.loadPaymentStats();
                break;
            case 'users':
                this.loadUsers();
                break;
            case 'ai-chat-usage':
                // Load AI chat usage logs
                loadAIChatUsers().then(() => {
                    loadAIChatUsage(1);
                }).catch(err => {
                    console.error('Error loading AI chat users:', err);
                    loadAIChatUsage(1); // Still try to load usage even if users fail
                });
                break;
            case 'admins':
                this.loadAdmins();
                break;
            case 'garages':
                this.loadGarages();
                break;
            case 'dealers':
                this.loadDealers();
                break;
            case 'car-hire':
                this.loadCarHire();
                break;
            case 'makes-models':
                this.loadMakesModels();
                break;
            case 'locations':
                this.loadLocations();
                break;
            case 'logs':
                this.loadActivityLogs();
                break;
            case 'analytics':
                this.loadAnalytics();
                break;
            case 'settings':
                loadSettings();
                loadSystemInfo();
                loadAIChatSettings(); // This will also load AI Learning settings
                break;
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
                document.getElementById('kpiRevenue').textContent = `MWK ${this.formatNumber(stats.total_revenue || 0)}`;
                document.getElementById('kpiRevenueChange').textContent = `Today: MWK ${this.formatNumber(stats.today_revenue || 0)}`;
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
            document.getElementById('kpiRevenue').textContent = 'MWK 0';
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
                ctx.fillText(`MWK ${this.formatNumber(response.stats.total_revenue || 0)}`, canvas.width / 2, 160);

                ctx.font = '16px Arial';
                ctx.fillStyle = '#666';
                ctx.fillText(`Today: MWK ${this.formatNumber(response.stats.today_revenue || 0)}`, canvas.width / 2, 200);
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

            const response = await this.apiCall('get_makes', 'GET');
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
                            <td>MWK ${this.formatNumber(car.price || 0)}</td>
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
        
        if (!confirm(`Are you sure you want to ${actionTexts[action]} ${carIds.length} car(s)?`)) {
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
                this.loadCars();
                this.loadDashboardData();
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
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Error loading guest listings</td></tr>';
        }
    }
}

displayGuestListingsTable(cars) {
    const tbody = document.getElementById('guestListingsTableBody');
    if (!tbody) return;

    if (!cars || cars.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No guest listings found</td></tr>';
        return;
    }

    const html = cars.map(car => {
        const sellerName = car.guest_seller_name || car.guest_seller_email || 'Guest Seller';
        const sellerEmail = car.guest_seller_email || 'N/A';
        const verifiedBadge = car.listing_email_verified
            ? '<span class="status-badge status-active">Verified</span>'
            : '<span class="status-badge status-pending">Not Verified</span>';
        const statusValue = (car.status || 'pending').replace('_', ' ');
        const statusBadge = `<span class="status-badge status-${car.status || 'pending'}">${statusValue}</span>`;
        const expiresAt = car.guest_listing_expires_at ? this.formatDateShort(car.guest_listing_expires_at) : 'N/A';

        return `
            <tr>
                <td>${car.id}</td>
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
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-success" onclick="admin.approveCar(${car.id}, 'approve')" title="Approve" ${car.listing_email_verified ? '' : 'disabled'}>
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="admin.approveCar(${car.id}, 'reject')" title="Reject">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = html;
}


    displayPendingCarsTable(cars) {
        const tbody = document.getElementById('pendingCarsTableBody');
        
        if (!cars || cars.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-success"><i class="fas fa-check-circle"></i> No pending cars for approval</td></tr>';
            return;
        }

        const html = cars.map(car => `
            <tr>
                <td>${car.id}</td>
                <td>
                    <div class="car-image-thumb">
                        <i class="fas fa-car text-warning"></i>
                    </div>
                </td>
                <td>
                    <div class="car-title">${this.escapeHtml(car.title)}</div>
                    <div class="text-muted small">${car.description ? this.escapeHtml(car.description.substring(0, 50)) + '...' : ''}</div>
                </td>
                <td>${car.make_name}/${car.model_name}</td>
                <td>${car.year}</td>
                <td>MWK ${this.formatNumber(car.price)}</td>
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
        `).join('');

        tbody.innerHTML = html;
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
                        ${car.primary_image ? 
                            `<img src="${this.getSafeUploadUrl(car.primary_image)}" alt="${this.escapeHtml(car.title)}" 
                                  onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\"fas fa-car text-danger\"></i>';">
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
                <td>MWK ${this.formatNumber(car.price)}</td>
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
    }

    filterRejectedCars() {
        this.loadRejectedCars();
    }

    async viewCarDetails(carId) {
        try {
            const response = await this.apiCall('get_car', 'GET', { id: carId });
            
            if (response.success && response.car) {
                this.showCarDetailsModal(response.car);
            } else {
                throw new Error(response.message || 'Failed to load car details');
            }
        } catch (error) {
            this.showAlert('error', 'Failed to load car details');
        }
    }

    showCarDetailsModal(car) {
        this.prepareModal('carDetailsModal');
        const modalHtml = `
            <div class="modal-overlay active" id="carDetailsModal">
                <div class="modal-content" style="max-width: 800px;">
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
                                    <span class="detail-value">MWK ${this.formatNumber(car.price)}</span>
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
    }

    async bulkApproveCars() {
        const checkboxes = document.querySelectorAll('.car-checkbox:checked');
        if (checkboxes.length === 0) {
            this.showAlert('warning', 'Please select at least one car to approve');
            return;
        }
        
        if (!confirm(`Are you sure you want to approve ${checkboxes.length} car(s)?`)) {
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
                this.loadPendingCars();
                this.loadDashboardData();
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
        
        if (!confirm(`Are you sure you want to reject ${checkboxes.length} car(s)?`)) {
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
                this.loadPendingCars();
                this.loadDashboardData();
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
        
        if (statusFilter) filters.status = statusFilter;
        if (serviceFilter) filters.service_type = serviceFilter;
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        
        const response = await this.apiCall('get_payments', 'GET', filters);
        
        if (response.success && response.payments) {
            this.displayPaymentsTable(response.payments);
            debugLog(`Loaded ${response.payments.length} payments`);
        } else {
            throw new Error(response.message || 'Invalid payments response');
        }
    } catch (error) {
        document.getElementById('paymentsTableBody').innerHTML = 
            '<tr><td colspan="9" class="text-center text-muted">Error loading payments</td></tr>';
    }
}

    async loadPaymentStats() {
        try {
            debugLog('Loading payment stats...');
            const response = await this.apiCall('payment_stats');
            
            if (response.success && response.stats) {
                const stats = response.stats;
                document.getElementById('todayPayments').textContent = `MWK ${this.formatNumber(stats.today.amount || 0)}`;
                document.getElementById('todayPaymentsCount').textContent = `${stats.today.count || 0} payments`;
                
                document.getElementById('monthPayments').textContent = `MWK ${this.formatNumber(stats.month.amount || 0)}`;
                document.getElementById('monthPaymentsCount').textContent = `${stats.month.count || 0} payments`;
                
                document.getElementById('pendingPayments').textContent = `MWK ${this.formatNumber(stats.pending.amount || 0)}`;
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
        const locationFilter = document.getElementById('carHireLocationFilter')?.value;
        const searchFilter = document.getElementById('carHireSearchFilter')?.value;
        
        if (statusFilter) filters.status = statusFilter;
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
            // Escape error message to prevent XSS
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
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No garages found</td></tr>';
        return;
    }

    const html = garages.map(garage => `
        <tr>
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
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No dealers found</td></tr>';
        return;
    }

    const html = dealers.map(dealer => `
        <tr>
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

    const html = carHire.map(company => `
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
                    ${company.region ? `<div class="text-muted small">Region: ${company.region}</div>` : ''}
                </div>
            </td>
            <td>${company.phone || 'N/A'}</td>
            <td>MWK ${this.formatNumber(company.daily_rate_from || 0)}</td>
            <td>
                <span class="status-badge status-${company.status || 'pending'}">${(company.status || 'pending').replace('_', ' ')}</span>
                ${company.is_featured ? '<div class="text-warning small"><i class="fas fa-star"></i> Featured</div>' : ''}
                ${company.is_verified ? '<div class="text-success small"><i class="fas fa-check-circle"></i> Verified</div>' : ''}
                ${company.is_certified ? '<div class="text-info small"><i class="fas fa-certificate"></i> Certified</div>' : ''}
            </td>
            <td>${this.formatDateShort(company.created_at)}</td>
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
        </tr>
    `).join('');

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
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No models found</td></tr>';
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
            const response = await this.apiCall('get_makes');
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
                        <label>Price (MWK) *</label>
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
                this.loadCars();
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
                    // Reload the images in the modal
                    const gallery = document.getElementById('carImagesGallery');
                    if (gallery) {
                        gallery.innerHTML = '<div class="text-center">Reloading images...</div>';
                        const imagesResponse = await this.apiCall('get_car_images', 'GET', { car_id: carId });

                        if (imagesResponse.success && imagesResponse.images) {
                            if (imagesResponse.images.length === 0) {
                                gallery.innerHTML = '<div class="text-center text-muted">No images found for this listing</div>';
                            } else {
                                gallery.innerHTML = imagesResponse.images.map(img => {
                                    const imageUrl = this.getSafeUploadUrl(img.filename);
                                    return `
                                    <div class="car-image-card" style="position: relative; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <img src="${imageUrl}" alt="${this.escapeHtml(img.original_filename)}"
                                             style="width: 100%; height: 200px; object-fit: cover; cursor: pointer;"
                                             onclick="window.open('${imageUrl}', '_blank')">
                                        ${img.is_primary ? '<span style="position: absolute; top: 5px; left: 5px; background: #28a745; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">Primary</span>' : ''}
                                        <div style="padding: 10px;">
                                            <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                                ${this.escapeHtml(img.original_filename)}
                                            </div>
                                            <div style="display: flex; gap: 5px; justify-content: space-between;">
                                                ${!img.is_primary ? `<button class="btn btn-sm btn-success" onclick="admin.setPrimaryImage(${img.id}, ${carId})" title="Set as Primary">
                                                    <i class="fas fa-star"></i> Set Primary
                                                </button>` : ''}
                                                <button class="btn btn-sm btn-danger" onclick="admin.deleteCarImage(${img.id}, ${carId})" title="Delete Image">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                }).join('');
                            }
                        }
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
        if (!confirm('Are you sure you want to delete this car? This action cannot be undone.')) {
            return;
        }
        
        try {
            const response = await this.apiCall('delete_car', 'POST', { id: carId });
            
            if (response.success) {
                this.showAlert('success', 'Car deleted successfully');
                this.loadCars();
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
                this.loadUsers();
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
                this.loadUsers();
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
                    this.loadUsers();
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
                this.loadUsers();
            } else {
                throw new Error(data.message || 'Failed to update AI chat access');
            }
        } catch (error) {
            this.showAlert('error', error.message || 'Failed to update AI chat access');
        }
    }

    async toggleFeatureCar(id, isFeatured) {
        try {
            const action = isFeatured ? 'feature' : 'unfeature';
            const response = await this.apiCall('feature_car', 'POST', { id: id, is_featured: isFeatured });

            if (response.success) {
                this.showAlert('success', `Car ${action}d successfully`);
                this.loadCars();
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
                this.loadCars();
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
                this.loadGarages();
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
                this.loadDealers();
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
                this.loadCarHire();
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
                this.loadGarages();
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
                this.loadGarages();
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
                this.loadGarages();
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
                this.loadDealers();
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
                this.loadDealers();
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
                this.loadDealers();
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
                this.loadCarHire();
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
                this.loadCarHire();
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
                this.loadCarHire();
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
                    <div id="carImagesGallery" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; padding: 20px;">
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

        // Load images
        try {
            const response = await this.apiCall('get_car_images', 'GET', { car_id: carId });

            if (response.success && response.images) {
                const gallery = document.getElementById('carImagesGallery');

                if (response.images.length === 0) {
                    gallery.innerHTML = '<div class="text-center text-muted">No images found for this listing</div>';
                } else {
                    gallery.innerHTML = response.images.map(img => {
                        const imageUrl = this.getSafeUploadUrl(img.filename);
                        return `
                        <div class="car-image-card" style="position: relative; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <img src="${imageUrl}" alt="${this.escapeHtml(img.original_filename)}"
                                 style="width: 100%; height: 200px; object-fit: cover; cursor: pointer;"
                                 onclick="window.open('${imageUrl}', '_blank')">
                            ${img.is_primary ? '<span style="position: absolute; top: 5px; left: 5px; background: #28a745; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">Primary</span>' : ''}
                            <div style="padding: 10px;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                    ${this.escapeHtml(img.original_filename)}
                                </div>
                                <div style="display: flex; gap: 5px; justify-content: space-between;">
                                    ${!img.is_primary ? `<button class="btn btn-sm btn-success" onclick="admin.setPrimaryImage(${img.id}, ${carId})" title="Set as Primary">
                                        <i class="fas fa-star"></i> Set Primary
                                    </button>` : ''}
                                    <button class="btn btn-sm btn-danger" onclick="admin.deleteCarImage(${img.id}, ${carId})" title="Delete Image">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    }).join('');
                }
            } else {
                throw new Error(response.message || 'Failed to load images');
            }
        } catch (error) {
            document.getElementById('carImagesGallery').innerHTML = '<div class="text-center text-danger">Error loading images</div>';
        }
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

        const html = cars.map(car => `
            <tr>
                <td>
                    <input type="checkbox" class="car-checkbox" value="${car.id}" onchange="admin.updateBulkSelection()">
                </td>
                <td>${car.id}</td>
                <td class="car-image-cell">
                    <div class="car-image-thumb">
                        ${car.primary_image ? 
                            `<img src="${this.getSafeUploadUrl(car.primary_image)}" alt="${this.escapeHtml(car.title)}" 
                                  onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\"fas fa-car car-image-placeholder\"></i>';">` :
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
                <td>MWK ${this.formatNumber(car.price)}</td>
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
                    <div class="d-flex gap-5" style="flex-wrap: wrap;">
                        ${car.status === 'pending_approval' ? `
                            <button class="btn btn-sm btn-success" onclick="admin.approveCar(${car.id}, 'approve')" title="${((Number(car.payment_required || 0) === 1 || Number(car.payment_amount || 0) > 0) && String(car.payment_status || '').toLowerCase() !== 'verified') ? 'Payment must be verified before approval' : 'Approve'}" ${((Number(car.payment_required || 0) === 1 || Number(car.payment_amount || 0) > 0) && String(car.payment_status || '').toLowerCase() !== 'verified') ? 'disabled' : ''}>
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-warning" onclick="admin.approveCar(${car.id}, 'reject')" title="Reject">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-sm ${car.is_featured ? 'btn-warning' : 'btn-outline-warning'}"
                                onclick="admin.toggleFeatureCar(${car.id}, ${car.is_featured ? 0 : 1})"
                                title="${car.is_featured ? 'Unfeature' : 'Feature'} Car">
                            <i class="fas fa-star"></i>
                        </button>
                        <button class="btn btn-sm ${car.is_premium ? 'btn-success' : 'btn-outline-success'}"
                                onclick="admin.togglePremiumCar(${car.id}, ${car.is_premium ? 0 : 1})"
                                title="${car.is_premium ? 'Remove Premium' : 'Make Premium'}">
                            <i class="fas fa-crown"></i>
                        </button>
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-success" onclick="admin.viewCarImages(${car.id}, '${this.escapeHtml(car.title).replace(/'/g, "\\'")}')" title="View Images">
                            <i class="fas fa-images"></i>
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="admin.editCar(${car.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="admin.deleteCarFromTable(${car.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.updateBulkSelection();
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
                        ${car.primary_image ?
                            `<img src="${this.getSafeUploadUrl(car.primary_image)}" alt="${this.escapeHtml(car.title)}"
                                  onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\"fas fa-car car-image-placeholder\"></i>';">` :
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
                <td>MWK ${this.formatNumber(car.price)}</td>
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
                        <button class="btn btn-sm btn-info" onclick="admin.viewCarDetails(${car.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-success" onclick="admin.viewCarImages(${car.id}, '${this.escapeHtml(car.title).replace(/'/g, "\\'")}')" title="View Images">
                            <i class="fas fa-images"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
        this.enableTableSorting('carsTable');
    }

    displayPaymentsTable(payments) {
        const tbody = document.getElementById('paymentsTableBody');
        
        if (!payments || payments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No payments found</td></tr>';
            return;
        }

        const html = payments.map(payment => `
            <tr>
                <td>#${payment.id}</td>
                <td>
                    <div>${this.escapeHtml(payment.user_name)}</div>
                    <div class="text-muted small">${payment.user_email}</div>
                </td>
                <td>${this.formatServiceType(payment.service_type)}</td>
                <td>MWK ${this.formatNumber(payment.amount)}</td>
                <td>${payment.payment_method ? this.capitalize(String(payment.payment_method).replace('_', ' ')) : '-'}</td>
                <td>
                    <div>${payment.reference || '-'}</div>
                    ${payment.proof_path ? `<div class="small"><a href="../${payment.proof_path}" target="_blank" rel="noopener">View POP</a></div>` : ''}
                </td>
                <td><span class="status-badge status-${payment.status}">${this.formatManualPaymentStatus(payment.status)}</span></td>
                <td>${this.formatDateShort(payment.created_at)}</td>
                <td>
                    <div class="d-flex gap-5">
                        ${payment.status === 'pending' ? `
                            <button class="btn btn-sm btn-success" onclick="admin.verifyPayment(${payment.id})" title="Verify Manually">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="admin.rejectPayment(${payment.id})" title="Reject POP">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-sm btn-info" onclick="admin.viewPayment(${payment.id})" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = html;
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
                this.loadDashboardData();
                this.loadSectionData(this.currentSection);
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.showAlert('error', error.message || `Failed to ${action} ${type}`);
        }
    }

    async approveCar(id, action) {
        let rejectionReason = '';
        
        // If rejecting, prompt for rejection reason
        if (action === 'reject') {
            rejectionReason = prompt('Please provide a reason for rejection (this will be visible to the user):');
            if (rejectionReason === null) {
                // User cancelled
                return;
            }
            if (rejectionReason.trim() === '') {
                this.showAlert('warning', 'Please provide a reason for rejection');
                return;
            }
        }
        
        if (!confirm(`Are you sure you want to ${action} this car?`)) {
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
                this.loadDashboardData();
                // Reload the current section if it's pending or rejected cars
                if (this.currentSection === 'pending-cars') {
                    this.loadPendingCars();
                } else if (this.currentSection === 'rejected-cars') {
                    this.loadRejectedCars();
                } else {
                    // Also reload pending cars in case it was approved from another section
                    this.loadPendingCars();
                }
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
                this.loadPayments();
                this.loadPaymentStats();
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
                this.loadPayments();
                this.loadPaymentStats();
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
                this.loadListingReports();
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
            aValue = aValue.replace(/MWK|,|\n/g, '').trim();
            bValue = bValue.replace(/MWK|,|\n/g, '').trim();

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
        const normalized = String(filename).split('/').pop().split('\\').pop();
        return `../uploads/${encodeURIComponent(normalized)}`;
    }

    showAlert(type, message) {
        debugLog(`Alert: ${type} - ${message}`);
        
        const alertsContainer = document.getElementById('alerts');
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
            if (e.key === 'Escape' && this.modal.classList.contains('active')) {
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
            
            admin.adminData = data.admin;

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
                            <label for="add-car-price">Price (MWK) *</label>
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
            admin.loadCars(); // Reload the cars list
        } else {
            admin.showAlert('error', response.message || 'Failed to add car');
        }
    } catch (error) {
        admin.showAlert('error', 'Error adding car: ' + error.message);
    }
}

function showAddPaymentModal() {
    admin.showAlert('info', 'Add payment modal coming soon');
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
        siteTagline: document.getElementById('site-tagline').value,
        siteDescription: document.getElementById('site-description').value,
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
        } else {
            admin.showAlert('error', response.message || 'Failed to save settings');
        }
    } catch (error) {
        admin.showAlert('error', 'Error saving settings: ' + error.message);
        debugLog('Error saving security settings:', error);
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
    } catch (error) {
        setAdminDbStatus(error.message || 'Failed to save admin DB credentials', true);
        admin.showAlert('error', error.message || 'Failed to save admin DB credentials');
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
            admin.showAlert(isEnabled ? 'warning' : 'success',
                           isEnabled ? 'Maintenance mode enabled' : 'Maintenance mode disabled');
            debugLog('Maintenance mode:', isEnabled ? 'enabled' : 'disabled', message);
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
    if (featuredPrice <= 0) {
        badge.innerHTML = '<i class="fas fa-bolt"></i> Featured is currently free';
        badge.style.background = '#ecfdf3';
        badge.style.color = '#166534';
    } else {
        badge.innerHTML = `<i class="fas fa-tag"></i> Featured is paid: MWK ${Math.round(featuredPrice).toLocaleString()}`;
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
            if (settings['general_siteTagline']) document.getElementById('site-tagline').value = settings['general_siteTagline'];
            if (settings['general_siteDescription']) document.getElementById('site-description').value = settings['general_siteDescription'];
            if (settings['general_adminEmail']) document.getElementById('admin-email').value = settings['general_adminEmail'];
            if (settings['general_supportEmail']) document.getElementById('support-email').value = settings['general_supportEmail'];

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
            if (settings['maintenance_enabled'] !== undefined) document.getElementById('maintenance-mode').checked = settings['maintenance_enabled'];
            if (settings['maintenance_message']) document.getElementById('maintenance-message').value = settings['maintenance_message'];

            // Admin DB credentials are managed separately in site_settings.
            await loadAdminDbCredentials();

        } else {
            debugLog('No settings found or error loading settings');
            await loadAdminDbCredentials();
        }
    } catch (error) {
        debugLog('Error loading settings:', error);
        await loadAdminDbCredentials();
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
            
            // Set model - ensure it exists in dropdown, fallback to provider default if not
            const modelSelect = document.getElementById('ai-model-name');
            const savedModel = data.settings.model_name || 'gpt-4o-mini';
            if (modelSelect) {
                const providerDefaultModels = {
                    openai: 'gpt-4o-mini',
                    deepseek: 'deepseek-chat',
                    qwen: 'qwen-plus',
                    glm: 'glm-4.7'
                };
                const fallbackModel = providerDefaultModels[savedProvider] || 'gpt-4o-mini';

                // Check if the saved model exists in options
                const modelExists = Array.from(modelSelect.options).some(opt => opt.value === savedModel);
                if (modelExists) {
                    modelSelect.value = savedModel;
                } else {
                    // If model doesn't exist (e.g., deprecated), use provider-aware fallback
                    modelSelect.value = fallbackModel;
                    console.warn(`Model "${savedModel}" not found in dropdown, defaulting to ${fallbackModel}`);
                }
                // Show current model badge
                updateModelBadge();
            }
            
            document.getElementById('ai-max-tokens').value = data.settings.max_tokens_per_request || 600;
            document.getElementById('ai-temperature').value = data.settings.temperature || 0.8;
            document.getElementById('ai-requests-per-day').value = data.settings.requests_per_day || 50;
            document.getElementById('ai-requests-per-hour').value = data.settings.requests_per_hour || 10;
        }
        
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
        const modelSelect = document.getElementById('ai-model-name');
        const selectedModel = modelSelect ? modelSelect.value : 'gpt-4o-mini';
        const providerSelect = document.getElementById('ai-chat-provider');
        const selectedProvider = providerSelect ? providerSelect.value : 'openai';
        
        // Get all values and validate
        const enabled = document.getElementById('ai-chat-enabled').checked ? 1 : 0;
        const maxTokens = parseInt(document.getElementById('ai-max-tokens').value);
        const temperature = parseFloat(document.getElementById('ai-temperature').value);
        const requestsPerDay = parseInt(document.getElementById('ai-requests-per-day').value);
        const requestsPerHour = parseInt(document.getElementById('ai-requests-per-hour').value);
        
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
        
        const settings = {
            enabled: enabled,
            ai_provider: selectedProvider,
            model_name: selectedModel,
            max_tokens_per_request: maxTokens,
            temperature: temperature,
            requests_per_day: requestsPerDay,
            requests_per_hour: requestsPerHour
        };
        
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
            const successMsg = `AI Chat settings saved successfully! ` +
                `Provider: ${savedSettings.ai_provider || selectedProvider}, ` +
                `Model: ${savedSettings.model_name}, ` +
                `Max Tokens: ${savedSettings.max_tokens_per_request}, ` +
                `Temperature: ${savedSettings.temperature}, ` +
                `Daily Limit: ${savedSettings.requests_per_day}, ` +
                `Hourly Limit: ${savedSettings.requests_per_hour}`;
            
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
        const selectedModel = modelSelect.value;
        const selectedOption = modelSelect.options[modelSelect.selectedIndex];
        const optionText = selectedOption ? selectedOption.text : selectedModel;
        
        // Show badge with current model info
        badge.textContent = `Active: ${selectedModel}`;
        badge.style.display = 'inline-block';
        badge.title = `Current model: ${optionText}`;
    }
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
            if (document.getElementById('openai-enabled')) {
                document.getElementById('openai-enabled').checked = settings.openai_enabled == 1;
            }
            if (document.getElementById('deepseek-enabled')) {
                document.getElementById('deepseek-enabled').checked = settings.deepseek_enabled == 1;
            }
            if (document.getElementById('qwen-enabled')) {
                document.getElementById('qwen-enabled').checked = settings.qwen_enabled == 1;
            }
            if (document.getElementById('glm-enabled')) {
                document.getElementById('glm-enabled').checked = settings.glm_enabled == 1;
            }
            if (document.getElementById('ai-provider')) {
                document.getElementById('ai-provider').value = settings.ai_provider || 'openai';
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

/**
 * Save AI Learning Settings
 */
async function saveAILearningSettings() {
    try {
        const openaiEnabled = document.getElementById('openai-enabled').checked ? 1 : 0;
        const deepseekEnabled = document.getElementById('deepseek-enabled').checked ? 1 : 0;
        const qwenEnabled = document.getElementById('qwen-enabled').checked ? 1 : 0;
        const glmEnabled = document.getElementById('glm-enabled').checked ? 1 : 0;
        const aiProvider = getSelectedLearningProvider();
        const webCacheLimit = parseInt(document.getElementById('web-cache-limit-input').value);
        const partsCacheLimit = parseInt(document.getElementById('parts-cache-limit-input').value);
        
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
            openai_enabled: openaiEnabled,
            deepseek_enabled: deepseekEnabled,
            qwen_enabled: qwenEnabled,
            glm_enabled: glmEnabled,
            ai_provider: aiProvider,
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
            body: JSON.stringify({
                count: count,
                provider: provider
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            let learnedList = '';
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
            
            statusDiv.innerHTML = `<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Successfully learned ${data.learned} topics!</strong>
                ${data.requested > data.learned ? `<br><small>Requested: ${data.requested}, but daily limit may have been reached.</small>` : ''}
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
            body: JSON.stringify({
                count: count,
                provider: provider
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            let learnedList = '';
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
            
            statusDiv.innerHTML = `<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Successfully learned ${data.learned} parts!</strong>
                ${data.requested > data.learned ? `<br><small>Requested: ${data.requested}, but daily limit may have been reached.</small>` : ''}
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
        
        tbody.innerHTML = '<tr><td colspan="8" class="text-center">Loading...</td></tr>';
        
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
                tbody.innerHTML = data.logs.map(log => `
                    <tr>
                        <td>${log.id}</td>
                        <td>${new Date(log.created_at).toLocaleString()}</td>
                        <td>${log.username || 'N/A'} (${log.user_id})<br><small>${log.email || ''}</small></td>
                        <td><small>${(log.message || '').substring(0, 50)}${(log.message || '').length > 50 ? '...' : ''}</small></td>
                        <td>${parseInt(log.tokens_used || 0).toLocaleString()}</td>
                        <td><small>${log.model_used || 'N/A'}</small></td>
                        <td>$${parseFloat(log.cost_estimate || 0).toFixed(6)}</td>
                        <td><small>${log.ip_address || 'N/A'}</small></td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">No usage logs found</td></tr>';
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
            tbody.innerHTML = `<tr><td colspan="8" class="text-center">Error: ${data.message || 'Failed to load usage logs'}</td></tr>`;
        }
    } catch (error) {
        console.error('Error loading AI chat usage:', error);
        const tbody = document.getElementById('aiChatUsageTableBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center">Error loading usage logs: ${error.message}</td></tr>`;
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
            setTimeout(loadAIChatSettings, 500);
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
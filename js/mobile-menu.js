// ============================================================================
// Unified Mobile Menu Handler
// ============================================================================
// Works across all pages of the website for consistent mobile navigation

// Function to highlight active page in navigation
function highlightActivePage(nav) {
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    const parentNavByPage = {
        'showroom.html': 'dealers.html',
        'car-hire-company.html': 'car-hire.html'
    };
    const activePage = parentNavByPage[currentPage] || currentPage;
    const navLinks = nav.querySelectorAll('a[href]');

    navLinks.forEach(link => {
        const rawHref = link.getAttribute('href') || '';
        const linkHref = rawHref.split('?')[0].split('#')[0];

        // Check if this link matches the current page
        if (linkHref === activePage ||
            (activePage === '' && linkHref === 'index.html') ||
            (activePage === '/' && linkHref === 'index.html')) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

// Cleanup function to remove all mobile menu clones
function cleanupMobileMenuClones(nav) {
    if (!nav) return;
    
    // Remove all cloned mobile menu elements
    const mobileClones = nav.querySelectorAll('#guestMenuMobile, #userInfoMobile, .mobile-dashboard-links, .mobile-dash-link, .logout-link');
    mobileClones.forEach(element => element.remove());
}

// ============================================================================
// Build rich tablet account dropdown panel
// ============================================================================
function buildTabletPanel(userMenu) {
    const existing = userMenu.querySelector('.tablet-menu-panel');
    if (existing) existing.remove();

    const isLoggedIn = localStorage.getItem('motorlink_authenticated') === 'true';
    let userData = null;
    try { userData = JSON.parse(localStorage.getItem('motorlink_user') || 'null'); } catch(e) {}

    const panel = document.createElement('div');
    panel.className = 'tablet-menu-panel';

    if (isLoggedIn && userData) {
        const name = userData.full_name || userData.name || (userData.email || '').split('@')[0] || 'User';
        const parts = name.trim().split(/\s+/).filter(n => n.length > 0);
        const initials = parts.length >= 2
            ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
            : name.substring(0, 2).toUpperCase();

        const type = userData.type || '';
        const typeLabel = { dealer: 'Dealer', garage: 'Garage', car_hire: 'Car Hire', admin: 'Admin' }[type] || 'Member';

        // Read live stat counts from the DOM (populated by updateDashboardStats)
        const msgCount  = parseInt((document.getElementById('messagesCount')  || {}).textContent) || 0;
        const lstCount  = parseInt((document.getElementById('listingsCount')  || {}).textContent) || 0;
        const favCount  = parseInt((document.getElementById('favoritesCount') || {}).textContent)
                       || (JSON.parse(localStorage.getItem('motorlink_favorites') || '[]')).length;

        const dashMap = { dealer: ['dealer-dashboard.html','My Showroom','fas fa-store'], garage: ['garage-dashboard.html','My Garage','fas fa-wrench'], car_hire: ['car-hire-dashboard.html','My Fleet','fas fa-car-side'], admin: ['admin/admin.html','Admin Panel','fas fa-shield-alt'] };
        const dash = dashMap[type];

        panel.innerHTML = `
            <div class="tpanel-header">
                <div class="tpanel-avatar">${initials}</div>
                <div class="tpanel-identity">
                    <span class="tpanel-name">${name}</span>
                    <span class="tpanel-type">${typeLabel}</span>
                </div>
            </div>
            <div class="tpanel-stats">
                <a href="chat_system.html" class="tpanel-stat">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                    ${msgCount > 0 ? `<span class="tpanel-badge">${msgCount > 99 ? '99+' : msgCount}</span>` : ''}
                </a>
                ${type !== 'dealer' ? `
                <a href="my-listings.html" class="tpanel-stat">
                    <i class="fas fa-car"></i>
                    <span>Listings</span>
                    ${lstCount > 0 ? `<span class="tpanel-badge">${lstCount}</span>` : ''}
                </a>` : ''}
                <a href="favorites.html" class="tpanel-stat">
                    <i class="fas fa-heart"></i>
                    <span>Saved</span>
                    ${favCount > 0 ? `<span class="tpanel-badge">${favCount > 99 ? '99+' : favCount}</span>` : ''}
                </a>
            </div>
            ${dash ? `<a href="${dash[0]}" class="tpanel-link"><i class="${dash[2]}"></i> ${dash[1]}</a>` : ''}
            <a href="profile.html" class="tpanel-link"><i class="fas fa-user-cog"></i> Profile Settings</a>
            <button class="tpanel-logout"><i class="fas fa-sign-out-alt"></i> Logout</button>
        `;

        panel.querySelector('.tpanel-logout').addEventListener('click', function() {
            if (typeof window.logout === 'function') {
                window.logout();
            } else {
                localStorage.removeItem('motorlink_user');
                localStorage.removeItem('motorlink_authenticated');
                localStorage.removeItem('motorlink_favorites');
                sessionStorage.clear();
                window.location.href = 'index.html';
            }
        });
    } else {
        panel.innerHTML = `
            <div class="tpanel-guest">
                <i class="fas fa-user-circle"></i>
                <p>Sign in to your account</p>
            </div>
            <a href="login.html" class="tpanel-link tpanel-login"><i class="fas fa-sign-in-alt"></i> Login</a>
            <a href="register.html" class="tpanel-link tpanel-register"><i class="fas fa-user-plus"></i> Create Account</a>
        `;
    }

    userMenu.appendChild(panel);
}

function initMobileMenu() {
    const toggle = document.getElementById('mobileToggle');
    const nav = document.getElementById('mainNav');
    const userMenu = document.getElementById('userMenu');
    let tabletUserMenuToggle = document.getElementById('tabletUserMenuToggle');

    const MOBILE_MAX_WIDTH = 768;
    const TABLET_MAX_WIDTH = 1024;
    const DESKTOP_MIN_WIDTH = 1025;

    const isMobileViewport = () => window.innerWidth <= MOBILE_MAX_WIDTH;
    const isTabletViewport = () => window.innerWidth > MOBILE_MAX_WIDTH && window.innerWidth <= TABLET_MAX_WIDTH;
    const isDesktopViewport = () => window.innerWidth >= DESKTOP_MIN_WIDTH;

    const closeTabletUserMenu = () => {
        if (userMenu) {
            userMenu.classList.remove('tablet-open');
        }
        if (tabletUserMenuToggle) {
            tabletUserMenuToggle.classList.remove('active');
        }
    };

    const setMobileMenuOpenState = (isOpen) => {
        document.body.classList.toggle('mobile-menu-open', Boolean(isOpen));
    };

    const resetToggleIcon = () => {
        const icon = toggle ? toggle.querySelector('i') : null;
        if (!icon) return;

        icon.className = 'fas fa-bars';
        icon.style.transform = 'rotate(0deg)';
    };

    // Ensure tablet account toggle exists on pages that have user menu but no explicit button.
    if (!tabletUserMenuToggle && userMenu) {
        const headerContainer = userMenu.closest('.header-container');
        if (headerContainer) {
            tabletUserMenuToggle = document.createElement('button');
            tabletUserMenuToggle.className = 'tablet-user-menu-toggle';
            tabletUserMenuToggle.id = 'tabletUserMenuToggle';
            tabletUserMenuToggle.setAttribute('aria-label', 'Toggle account menu');
            tabletUserMenuToggle.innerHTML = '<i class="fas fa-ellipsis-v"></i>';

            if (toggle && toggle.parentNode === headerContainer) {
                headerContainer.insertBefore(tabletUserMenuToggle, toggle);
            } else {
                headerContainer.appendChild(tabletUserMenuToggle);
            }
        }
    }

    const cleanupTransientOverlayState = () => {
        const staleBackdrop = document.getElementById('menu-backdrop');
        if (staleBackdrop) {
            staleBackdrop.remove();
        }

        if (nav) {
            nav.classList.remove('active');
        }

        if (toggle) {
            toggle.classList.remove('active');
        }

        if (userMenu) {
            userMenu.classList.remove('active');
        }

        closeTabletUserMenu();
        setMobileMenuOpenState(false);
        resetToggleIcon();
        document.body.style.overflow = '';
    };

    // Defensive cleanup for navigation restores/login redirects.
    cleanupTransientOverlayState();
    window.addEventListener('pageshow', cleanupTransientOverlayState);

    if (tabletUserMenuToggle && userMenu) {
        tabletUserMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const willOpen = !userMenu.classList.contains('tablet-open');
            closeTabletUserMenu();

            if (willOpen) {
                buildTabletPanel(userMenu);
                userMenu.classList.add('tablet-open');
                tabletUserMenuToggle.classList.add('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (!userMenu.classList.contains('tablet-open')) return;

            if (!userMenu.contains(e.target) && !tabletUserMenuToggle.contains(e.target)) {
                closeTabletUserMenu();
            }
        });

        window.addEventListener('resize', function() {
            if (!isTabletViewport()) {
                closeTabletUserMenu();
            }
        });
    }

    if (!toggle || !nav) {
        return;
    }

    // Highlight active page in navigation
    highlightActivePage(nav);

    // Cleanup any existing clones before adding new ones
    cleanupMobileMenuClones(nav);

    // Clone user menu items into nav for mobile drawer only
    if (userMenu && (isMobileViewport() || document.body.classList.contains('header-force-mobile'))) {
        const userInfo = userMenu.querySelector('#userInfo');
        const guestMenu = userMenu.querySelector('#guestMenu');

        // Check if user is actually logged in by checking localStorage
        // Don't check element visibility as desktop header may be hidden on mobile
        const isLoggedIn = localStorage.getItem('motorlink_authenticated') === 'true' &&
                          localStorage.getItem('motorlink_user');

        if (isLoggedIn) {
            // User is logged in - show user info and dashboard links
            const clone = userInfo.cloneNode(true);
            clone.id = 'userInfoMobile';
            nav.appendChild(clone);

            // Get user type from localStorage to show appropriate dashboard
            const userData = JSON.parse(localStorage.getItem('motorlink_user'));
            const userType = userData?.type;

            // Check if dashboard links already exist (to prevent duplicates)
            const existingDashboardLinks = nav.querySelector('.mobile-dashboard-links');
            if (existingDashboardLinks) {
                existingDashboardLinks.remove();
            }

            // Remove any existing dashboard-link elements from desktop nav that got cloned
            const desktopDashboardLinks = nav.querySelectorAll('.dashboard-link');
            desktopDashboardLinks.forEach(link => link.remove());

            // Add user dashboard links to mobile menu when logged in
            const dashboardLinks = document.createElement('div');
            dashboardLinks.className = 'mobile-dashboard-links';

            // Build dashboard link based on user type
            let dashboardHTML = '';
            switch(userType) {
                case 'dealer':
                    dashboardHTML = `
                        <a href="dealer-dashboard.html" class="mobile-dash-link">
                            <i class="fas fa-store"></i> My Showroom
                        </a>
                    `;
                    break;
                case 'garage':
                    dashboardHTML = `
                        <a href="garage-dashboard.html" class="mobile-dash-link">
                            <i class="fas fa-wrench"></i> My Garage
                        </a>
                    `;
                    break;
                case 'car_hire':
                    dashboardHTML = `
                        <a href="car-hire-dashboard.html" class="mobile-dash-link">
                            <i class="fas fa-car-side"></i> My Fleet
                        </a>
                    `;
                    break;
                case 'admin':
                    dashboardHTML = `
                        <a href="admin/admin.html" class="mobile-dash-link">
                            <i class="fas fa-shield-alt"></i> Admin Panel
                        </a>
                    `;
                    break;
                // Individual users don't get a dashboard link
            }

            dashboardLinks.innerHTML = dashboardHTML + `
                <a href="my-listings.html" class="mobile-dash-link">
                    <i class="fas fa-car"></i> My Listings
                </a>
                <a href="profile.html" class="mobile-dash-link">
                    <i class="fas fa-user-cog"></i> Profile Settings
                </a>
                <a href="favorites.html" class="mobile-dash-link">
                    <i class="fas fa-heart"></i> Favorites
                </a>
            `;
            nav.appendChild(dashboardLinks);

            // Add logout button
            const logoutLink = document.createElement('a');
            logoutLink.href = '#';
            logoutLink.className = 'mobile-dash-link logout-link';
            logoutLink.innerHTML = '<i class="fas fa-sign-out-alt"></i> Logout';
            logoutLink.addEventListener('click', function(e) {
                e.preventDefault();
                // CRITICAL: Remove dashboard links before logout
                const dashboardLinks = document.querySelectorAll('.dashboard-link, .mobile-dash-link, .mobile-dashboard-links');
                dashboardLinks.forEach(link => link.remove());
                
                // Trigger logout
                if (typeof window.logout === 'function') {
                    window.logout();
                } else if (typeof window.authManager !== 'undefined' && window.authManager.logout) {
                    window.authManager.logout();
                } else {
                    // Fallback logout - clear all data
                    localStorage.removeItem('motorlink_user');
                    localStorage.removeItem('motorlink_authenticated');
                    localStorage.removeItem('motorlink_favorites');
                    sessionStorage.clear();
                    window.location.href = 'index.html';
                }
            });
            nav.appendChild(logoutLink);
        } else if (guestMenu) {
            // User is NOT logged in - show login/register options
            // CRITICAL: Remove any dashboard links that might still be present
            const dashboardLinks = nav.querySelectorAll('.dashboard-link, .mobile-dash-link, .mobile-dashboard-links');
            dashboardLinks.forEach(link => link.remove());
            
            const clone = guestMenu.cloneNode(true);
            clone.id = 'guestMenuMobile';
            nav.appendChild(clone);
        }
    }

    // Toggle menu on button click
    toggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const isActive = nav.classList.contains('active');

        if (isActive) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    // Close menu when clicking outside
    document.addEventListener('click', function(e) {
        if (nav.classList.contains('active')) {
            if (!nav.contains(e.target) && !toggle.contains(e.target)) {
                closeMenu();
            }
        }
    });

    // Handle window resize - cleanup clones when switching to desktop
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            const isDesktop = isDesktopViewport();

            if (!isTabletViewport()) {
                closeTabletUserMenu();
            }
            
            // Close menu if switching to desktop
            if (isDesktop && nav.classList.contains('active')) {
                closeMenu();
            }
            
            // Cleanup mobile clones when switching to desktop
            // CSS will handle hiding them, but we remove from DOM to prevent conflicts
            if (isDesktop) {
                cleanupMobileMenuClones(nav);
            }
            // Note: Mobile clones are only added once on init, not on resize
            // They remain in DOM but are hidden by CSS on desktop
        }, 150);
    });

    // Close menu when navigating
    const navLinks = nav.querySelectorAll('a');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (nav.classList.contains('active')) {
                closeMenu();
            }
        });
    });

    function openMenu() {
        nav.classList.add('active');
        toggle.classList.add('active');
        if (userMenu) {
            userMenu.classList.add('active');
        }
        setMobileMenuOpenState(true);
        
        // Change icon to X with faster animation (180deg for smoother transition)
        const icon = toggle.querySelector('i');
        if (icon) {
            icon.style.transform = 'rotate(180deg)';
            setTimeout(() => {
                icon.className = 'fas fa-times';
                icon.style.transform = 'rotate(0deg)';
            }, 100);
        }
        
        // Add backdrop overlay with faster fade
        const backdrop = document.createElement('div');
        backdrop.id = 'menu-backdrop';
        backdrop.style.cssText = `
            position: fixed;
            top: 64px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9998;
            opacity: 0;
            transition: opacity 0.2s ease;
        `;
        document.body.appendChild(backdrop);
        
        // Fade in backdrop faster
        setTimeout(() => {
            backdrop.style.opacity = '1';
        }, 5);
        
        // Close menu when clicking backdrop
        backdrop.addEventListener('click', closeMenu);
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
        
    }

    function closeMenu() {
        nav.classList.remove('active');
        toggle.classList.remove('active');
        if (userMenu) {
            userMenu.classList.remove('active');
        }
        setMobileMenuOpenState(false);
        
        // Change icon back to hamburger with faster animation (180deg for smoother transition)
        const icon = toggle.querySelector('i');
        if (icon) {
            icon.style.transform = 'rotate(-180deg)';
            setTimeout(() => {
                icon.className = 'fas fa-bars';
                icon.style.transform = 'rotate(0deg)';
            }, 100);
        }
        
        // Remove backdrop with faster fade
        const backdrop = document.getElementById('menu-backdrop');
        if (backdrop) {
            backdrop.style.opacity = '0';
            setTimeout(() => {
                backdrop.remove();
            }, 200);
        }

        // Restore body scroll
        document.body.style.overflow = '';
        
    }

}

// ============================================================================
// Move Descriptions Outside Header on Mobile
// ============================================================================
function moveDescriptionsOnMobile() {
    // Only run on mobile and smaller tablet screens
    if (window.innerWidth > 1024) return;

    // Handle showroom.html - move dealer-description outside header
    const dealerDescription = document.getElementById('dealerDescription');
    const showroomHeader = document.querySelector('.showroom-header-minimal');

    if (dealerDescription && showroomHeader && dealerDescription.style.display !== 'none') {
        // Check if not already moved
        if (dealerDescription.closest('.showroom-header-minimal')) {
            // Create a wrapper section for the description
            const descriptionSection = document.createElement('section');
            descriptionSection.className = 'dealer-description-section mobile-only';
            descriptionSection.style.cssText = `
                background: #f8f9fa;
                padding: 20px 16px;
                margin: 0;
            `;

            // Move the description element
            descriptionSection.appendChild(dealerDescription.cloneNode(true));
            dealerDescription.style.display = 'none'; // Hide original

            // Insert after the header
            showroomHeader.parentNode.insertBefore(descriptionSection, showroomHeader.nextSibling);
        }
    }

    // Handle car-hire-company.html - move company-description outside header
    const companyHeader = document.querySelector('.company-header');
    const companyDescription = companyHeader ? companyHeader.querySelector('.company-description') : null;

    if (companyDescription && companyDescription.style.display !== 'none') {
        // Check if not already moved
        if (companyDescription.closest('.company-header')) {
            // Create a wrapper section for the description
            const descriptionSection = document.createElement('section');
            descriptionSection.className = 'company-description-section mobile-only';
            descriptionSection.style.cssText = `
                background: #f8f9fa;
                padding: 20px 16px;
                margin: 0;
            `;

            // Move the description element
            descriptionSection.appendChild(companyDescription.cloneNode(true));
            companyDescription.style.display = 'none'; // Hide original

            // Insert after the header
            companyHeader.parentNode.insertBefore(descriptionSection, companyHeader.nextSibling);
        }
    }
}

// ============================================================================
// Add Page Indicator/Breadcrumb Navigation
// ============================================================================
function addPageIndicatorToMobileMenu() {
    const existingIndicator = document.querySelector('.mobile-page-indicator');
    if (existingIndicator) {
        existingIndicator.remove();
    }

    // Retired: this breadcrumb chip competes with the header/nav on smaller screens.
    return;

    const nav = document.getElementById('mainNav');
    if (!nav) return;

    // Get current page
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';

    // Define page hierarchy/tree
    const pageTree = {
        'index.html': { name: 'Home', icon: 'fas fa-home', parent: null },
        'dealers.html': { name: 'Dealers', icon: 'fas fa-store', parent: null },
        'showroom.html': { name: 'Showroom', icon: 'fas fa-car', parent: 'dealers.html' },
        'car-hire.html': { name: 'Car Hire', icon: 'fas fa-car-side', parent: null },
        'car-hire-company.html': { name: 'Company', icon: 'fas fa-building', parent: 'car-hire.html' },
        'garages.html': { name: 'Garages', icon: 'fas fa-wrench', parent: null },
        'sell.html': { name: 'Sell Your Car', icon: 'fas fa-dollar-sign', parent: null },
        'about.html': { name: 'About Us', icon: 'fas fa-info-circle', parent: null },
        'contact.html': { name: 'Contact', icon: 'fas fa-envelope', parent: null },
        'car-database.html': { name: 'Know Your Car', icon: 'fas fa-car', parent: null },
        'login.html': { name: 'Login', icon: 'fas fa-sign-in-alt', parent: null },
        'register.html': { name: 'Register', icon: 'fas fa-user-plus', parent: null },
        'profile.html': { name: 'Profile', icon: 'fas fa-user', parent: null },
        'my-listings.html': { name: 'My Listings', icon: 'fas fa-list', parent: null },
        'favorites.html': { name: 'Favorites', icon: 'fas fa-heart', parent: null }
    };

    // Check if indicator already exists
    let pageIndicator = document.querySelector('.mobile-page-indicator');
    if (pageIndicator) {
        pageIndicator.remove();
    }

    // Create page indicator
    pageIndicator = document.createElement('div');
    pageIndicator.className = 'mobile-page-indicator';
    
    // Set inline styles immediately to prevent FOUC (Flash of Unstyled Content)
    pageIndicator.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); border-bottom: 2px solid rgba(0, 0, 0, 0.1); font-size: 0.85rem; font-weight: 500; line-height: 1.4; color: rgba(255, 255, 255, 0.9); overflow-x: auto; white-space: nowrap; margin-bottom: 0; min-height: 44px; max-height: 44px; box-sizing: border-box; width: 100%; flex-shrink: 0;';

    const currentPageInfo = pageTree[currentPage];
    if (currentPageInfo) {
        let breadcrumbHTML = '';

        // Build breadcrumb trail
        if (currentPageInfo.parent && pageTree[currentPageInfo.parent]) {
            const parentInfo = pageTree[currentPageInfo.parent];
            breadcrumbHTML = `
                <a href="${currentPageInfo.parent}">
                    <i class="${parentInfo.icon}"></i>
                    <span>${parentInfo.name}</span>
                </a>
                <i class="fas fa-chevron-right"></i>
            `;
        }

        breadcrumbHTML += `
            <span class="current-page">
                <i class="${currentPageInfo.icon}"></i>
                <span>${currentPageInfo.name}</span>
            </span>
        `;

        pageIndicator.innerHTML = breadcrumbHTML;

        // Insert after the header
        const header = document.querySelector('header');
        if (header) {
            header.parentNode.insertBefore(pageIndicator, header.nextSibling);
            
            // Force a reflow to ensure styles are applied immediately
            void pageIndicator.offsetHeight;
            
            // Ensure CSS is applied by checking computed styles
            requestAnimationFrame(() => {
                const computedStyle = window.getComputedStyle(pageIndicator);
                if (computedStyle.fontSize === '0px' || !computedStyle.fontSize) {
                    // CSS not loaded yet, wait a bit and reapply inline styles
                    setTimeout(() => {
                        pageIndicator.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); border-bottom: 2px solid rgba(0, 0, 0, 0.1); font-size: 0.85rem; font-weight: 500; line-height: 1.4; color: rgba(255, 255, 255, 0.9); overflow-x: auto; white-space: nowrap; margin-bottom: 0; min-height: 44px; max-height: 44px; box-sizing: border-box; width: 100%; flex-shrink: 0;';
                    }, 10);
                }
            });
        }

        // Add pulse animation style if not already added
        if (!document.querySelector('#page-indicator-animation')) {
            const style = document.createElement('style');
            style.id = 'page-indicator-animation';
            style.textContent = `
                @keyframes pulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.7; transform: scale(1.2); }
                }
            `;
            document.head.appendChild(style);
        }
    }
}

// ============================================================================
// Initialize all mobile enhancements
// ============================================================================
function initMobileEnhancements() {
    initMobileMenu();
    moveDescriptionsOnMobile();
    addPageIndicatorToMobileMenu();
    initHeaderOverflowWatcher();

    // Re-run on resize for mobile descriptions
    // Note: Resize handler for menu cleanup is in initMobileMenu()
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            // Only move descriptions, don't re-init menu
            moveDescriptionsOnMobile();
        }, 250);
    });
}

// ============================================================================
// Dynamic header overflow watcher
// ----------------------------------------------------------------------------
// This keeps the header stable by switching to the mobile menu only when the
// rendered header content truly cannot fit. It uses a small release buffer so
// the layout does not flicker between desktop and mobile states.
// ============================================================================
function initHeaderOverflowWatcher() {
    const header = document.querySelector('.header-container');
    if (!header) return;

    const nav = document.getElementById('mainNav');
    const userMenu = document.getElementById('userMenu');

    const FORCE_CLASS = 'header-force-mobile';
    const MOBILE_MAX_WIDTH = 768;
    const RELEASE_BUFFER = 48;
    let releaseThreshold = 0;
    let rafId = null;
    let wasForcedMobile = false;

    /* ── Ensure mobile clones exist inside nav when entering force-mobile ── */
    const ensureMobileClones = () => {
        if (!nav || !userMenu) return;
        if (nav.querySelector('#guestMenuMobile') || nav.querySelector('#userInfoMobile')) return;

        const isLoggedIn = localStorage.getItem('motorlink_authenticated') === 'true' &&
                          localStorage.getItem('motorlink_user');

        if (isLoggedIn) {
            const userInfo = userMenu.querySelector('#userInfo');
            if (userInfo) {
                const clone = userInfo.cloneNode(true);
                clone.id = 'userInfoMobile';
                nav.appendChild(clone);
            }
        } else {
            const guestMenu = userMenu.querySelector('#guestMenu');
            if (guestMenu) {
                const clone = guestMenu.cloneNode(true);
                clone.id = 'guestMenuMobile';
                nav.appendChild(clone);
            }
        }
    };

    /* ── Remove force-mobile clones when returning to normal layout ── */
    const removeForceMobileClones = () => {
        if (!nav) return;
        // Only remove if we're NOT in actual mobile viewport (mobile init owns its clones)
        if (window.innerWidth <= MOBILE_MAX_WIDTH) return;
        cleanupMobileMenuClones(nav);
    };

    const getVisibleChildren = () => Array.from(header.children).filter((child) => {
        const styles = window.getComputedStyle(child);
        return styles.display !== 'none' && styles.visibility !== 'hidden';
    });

    const getRequiredWidth = () => {
        const styles = window.getComputedStyle(header);
        const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
        const children = getVisibleChildren();

        return children.reduce((total, child) => {
            return total + Math.ceil(child.getBoundingClientRect().width);
        }, 0) + (Math.max(0, children.length - 1) * gap) + 12;
    };

    const updateHeaderMode = () => {
        if (document.body.classList.contains('mobile-menu-open')) return;

        if (window.innerWidth <= MOBILE_MAX_WIDTH) {
            document.body.classList.remove(FORCE_CLASS);
            wasForcedMobile = false;
            return;
        }

        const isForced = document.body.classList.contains(FORCE_CLASS);

        if (!isForced) {
            const requiredWidth = getRequiredWidth();
            const availableWidth = header.clientWidth;

            if (requiredWidth > availableWidth) {
                releaseThreshold = requiredWidth + RELEASE_BUFFER;
                document.body.classList.add(FORCE_CLASS);
                ensureMobileClones();
                wasForcedMobile = true;
            }

            return;
        }

        /* ── Release check ──
           Force-mobile hides nav + user-menu so we can't measure them while
           the class is active.  Temporarily remove it, trigger a synchronous
           reflow to measure the real desktop/tablet layout, then re-add if
           items still overflow.  Because this all happens within a single JS
           turn (inside a rAF callback), the browser will NOT paint the
           intermediate state — no visual flicker.                          */
        document.body.classList.remove(FORCE_CLASS);
        // Force synchronous reflow so getRequiredWidth reads the true layout
        const requiredWidth = getRequiredWidth();
        const availableWidth = header.clientWidth;

        if (requiredWidth > availableWidth) {
            // Still overflows — stay in force-mobile (re-add same frame)
            releaseThreshold = requiredWidth + RELEASE_BUFFER;
            document.body.classList.add(FORCE_CLASS);
        } else {
            // Items fit — release force-mobile for good
            if (wasForcedMobile) {
                removeForceMobileClones();
                wasForcedMobile = false;
            }
        }
    };

    const scheduleUpdate = () => {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = requestAnimationFrame(() => requestAnimationFrame(updateHeaderMode));
    };

    scheduleUpdate();
    window.addEventListener('load', scheduleUpdate);
    window.addEventListener('resize', scheduleUpdate, { passive: true });

    if (typeof ResizeObserver === 'function') {
        const ro = new ResizeObserver(scheduleUpdate);
        ro.observe(header);
        getVisibleChildren().forEach(child => ro.observe(child));
    }

    if (typeof MutationObserver === 'function') {
        const mo = new MutationObserver(scheduleUpdate);
        mo.observe(header, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true
        });
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileEnhancements);
} else {
    initMobileEnhancements();
}

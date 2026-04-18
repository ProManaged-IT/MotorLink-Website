// ============================================================================
// Unified Navigation Loader
// ============================================================================
// Injects the canonical navigation HTML into every page's <header class="header">
// This guarantees 100% consistency across all pages — nav links, user menu,
// login/logout, mobile toggle, tablet toggle, and accessibility attributes.
// Runs synchronously before mobile-menu.js and script.js.
//
// Pages that should NOT have this nav (login.html, register.html, forgot-password.html,
// admin/admin.html, onboarding/) use their own headers and don't include this script.

(function () {
    'use strict';

    var header = document.querySelector('header.header');
    if (!header) return; // No header on this page (auth pages, admin, etc.)

    var body = document.body;
    var currentPage = (window.location.pathname.split('/').pop() || 'index.html').toLowerCase();
    var heroSurface = findHeroSurface();
    var breadcrumbMarkup = buildBreadcrumbMarkup(currentPage);
    var heroMode = !!heroSurface && !breadcrumbMarkup;
    var initialSiteName = (window.CONFIG && CONFIG.SITE_NAME) ? CONFIG.SITE_NAME : 'MotorLink';
    var initialSiteShortName = (window.CONFIG && (CONFIG.SITE_SHORT_NAME || CONFIG.SITE_NAME)) ? (CONFIG.SITE_SHORT_NAME || CONFIG.SITE_NAME) : 'MotorLink';

    header.innerHTML =
        '<div class="container">' +
            '<div class="header-container">' +
                '<a href="index.html" class="logo" title="' + escapeHtml(initialSiteName) + ' - Home">' +
                    '<i class="fas fa-car"></i> <span class="logo-text">' + escapeHtml(initialSiteShortName) + '</span>' +
                '</a>' +

                '<nav class="nav" id="mainNav" role="navigation" aria-label="Main navigation">' +
                    '<a href="index.html">Home</a>' +
                    '<a href="car-database.html">Know Your Car</a>' +
                    '<a href="garages.html">Garages</a>' +
                    '<a href="dealers.html">Dealers</a>' +
                    '<a href="car-hire.html">Car Hire</a>' +
                    '<a href="sell.html">Sell Car</a>' +
                    '<a href="guest-manage.html">Manage Guest Listing</a>' +
                '</nav>' +

                '<div class="user-menu" id="userMenu">' +
                    '<div id="userInfo" style="display: none;">' +
                        '<a href="profile.html" class="user-avatar-btn" title="My Profile" id="userAvatar">' +
                            '<i class="fas fa-user"></i>' +
                        '</a>' +
                        '<button onclick="logout()" class="btn btn-outline-primary btn-sm">' +
                            '<i class="fas fa-sign-out-alt"></i> Logout' +
                        '</button>' +
                    '</div>' +
                    '<div id="guestMenu">' +
                        '<a href="login.html" class="btn btn-outline-primary btn-sm login-icon-btn" title="Login">' +
                            '<i class="fas fa-sign-in-alt"></i> <span class="btn-label">Login</span>' +
                        '</a>' +
                        '<a href="register.html" class="btn btn-primary btn-sm register-icon-btn" title="Register">' +
                            '<i class="fas fa-user-plus"></i> <span class="btn-label">Register</span>' +
                        '</a>' +
                    '</div>' +
                '</div>' +

                '<button class="tablet-user-menu-toggle" id="tabletUserMenuToggle" aria-label="Toggle account menu">' +
                    '<i class="fas fa-ellipsis-v"></i>' +
                '</button>' +

                '<button class="mobile-menu-toggle" id="mobileToggle" aria-label="Toggle mobile menu">' +
                    '<i class="fas fa-bars"></i>' +
                '</button>' +
            '</div>' +
        '</div>';

    if (heroMode) {
        body.classList.add('has-hero');
    } else {
        body.classList.remove('has-hero');
    }

    injectBreadcrumbs(header, breadcrumbMarkup, heroMode);
    syncRuntimeBranding(getRuntimeSiteConfig());

    if (typeof window.getPublicSiteConfig === 'function') {
        window.getPublicSiteConfig().then(syncRuntimeBranding).catch(function () {});
    }

    window.addEventListener('motorlink:site-config-ready', function (event) {
        syncRuntimeBranding(event && event.detail ? event.detail : getRuntimeSiteConfig());
    });

    function injectBreadcrumbs(headerElement, markup, heroMode) {
        var existingWrap = document.querySelector('.page-breadcrumbs-wrap');
        if (existingWrap) {
            existingWrap.remove();
        }

        body.classList.remove('has-breadcrumbs');

        if (!markup) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'page-breadcrumbs-wrap';

        if (heroMode) {
            wrap.classList.add('page-breadcrumbs-wrap--hero');
        }

        wrap.innerHTML =
            '<div class="container">' +
                '<nav class="page-breadcrumbs" aria-label="Breadcrumb">' + markup + '</nav>' +
            '</div>';

        headerElement.insertAdjacentElement('afterend', wrap);
        body.classList.add('has-breadcrumbs');
    }

    function buildBreadcrumbMarkup(page) {
        var meta = getPageMeta(page);

        if (!meta || page === 'index.html') {
            return '';
        }

        var parts = [];
        parts.push('<a class="page-breadcrumbs__link" href="index.html">Home</a>');

        if (meta.parent) {
            var parentMeta = getPageMeta(meta.parent);

            if (parentMeta) {
                parts.push('<span class="page-breadcrumbs__sep" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>');
                parts.push('<a class="page-breadcrumbs__link" href="' + escapeHtml(meta.parent) + '">' + escapeHtml(parentMeta.label) + '</a>');
            }
        }

        parts.push('<span class="page-breadcrumbs__sep" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>');
        parts.push('<span class="page-breadcrumbs__current" aria-current="page">' + escapeHtml(meta.label) + '</span>');

        return parts.join('');
    }

    function getPageMeta(page) {
        var pages = {
            'index.html': { label: 'Home' },
            'car-database.html': { label: 'Know Your Car' },
            'car-hire.html': { label: 'Car Hire' },
            'car-hire-company.html': { label: 'Company Profile', parent: 'car-hire.html' },
            'car-hire-dashboard.html': { label: 'Fleet Dashboard' },
            'car.html': { label: 'Vehicle Details' },
            'chat_system.html': { label: 'Messages' },
            'contact.html': { label: 'Contact' },
            'cookie-policy.html': { label: 'Cookie Policy' },
            'dealer-dashboard.html': { label: 'Dealer Dashboard' },
            'dealers.html': { label: 'Dealers' },
            'favorites.html': { label: 'Favorites' },
            'garage-dashboard.html': { label: 'Garage Dashboard' },
            'garages.html': { label: 'Garages' },
            'guest-manage.html': { label: 'Manage Guest Listing', parent: 'sell.html' },
            'help.html': { label: 'Help Center' },
            'my-listings.html': { label: 'My Listings' },
            'profile.html': { label: 'Profile' },
            'safety.html': { label: 'Safety' },
            'sell.html': { label: 'Sell Car' },
            'showroom.html': { label: 'Showroom', parent: 'dealers.html' },
            'terms.html': { label: 'Terms & Privacy' }
        };

        return pages[page] || {
            label: deriveLabel(page)
        };
    }

    function deriveLabel(page) {
        return (page || 'Page')
            .replace(/\.html$/i, '')
            .replace(/[-_]+/g, ' ')
            .replace(/\b\w/g, function (char) {
                return char.toUpperCase();
            });
    }

    function findHeroSurface() {
        var heroSurfaces = document.querySelectorAll('.contact-hero, .support-hero, .safety-hero, .showroom-header-minimal, .hero');

        for (var index = 0; index < heroSurfaces.length; index++) {
            if (isVisibleHeroSurface(heroSurfaces[index])) {
                return heroSurfaces[index];
            }
        }

        return null;
    }

    function isVisibleHeroSurface(element) {
        if (!element) {
            return false;
        }

        if (element.closest('[hidden], .hidden')) {
            return false;
        }

        if (!element.getClientRects().length) {
            return false;
        }

        var styles = window.getComputedStyle(element);
        return styles.display !== 'none' && styles.visibility !== 'hidden';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getRuntimeSiteConfig() {
        if (!window.CONFIG) {
            return {};
        }

        return {
            site_name: CONFIG.SITE_NAME,
            site_short_name: CONFIG.SITE_SHORT_NAME
        };
    }

    function syncRuntimeBranding(siteConfig) {
        var logo = header.querySelector('.logo');
        var logoText = header.querySelector('.logo-text');
        var siteName = (siteConfig && siteConfig.site_name) || initialSiteName;
        var siteShortName = (siteConfig && (siteConfig.site_short_name || siteConfig.site_name)) || initialSiteShortName;

        if (logo) {
            logo.title = siteName + ' - Home';
        }

        if (logoText) {
            logoText.textContent = siteShortName;
        }
    }
})();

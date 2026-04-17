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

    header.innerHTML =
        '<div class="container">' +
            '<div class="header-container">' +
                '<a href="index.html" class="logo" title="MotorLink Malawi - Home">' +
                    '<i class="fas fa-car"></i> MotorLink' +
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
})();

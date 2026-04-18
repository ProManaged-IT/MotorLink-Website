/**
 * Page Transition Manager - App-Like Page Transitions
 * Creates smooth transitions between pages for a native app feel
 */

class PageTransitionManager {
    constructor() {
        this.isTransitioning = false;
        this.activeAsyncRequests = 0;
        this.lastUserActionAt = 0;
        this.asyncHideTimer = null;
        this.defaultAsyncMessage = 'Please wait...';
        this.init();
    }

    init() {
        this.resetPageVisualState();
        this.injectLoaderStyles();

        // Create page loader overlay
        this.createPageLoader();

        // Track user actions so loader appears for user-triggered async work
        this.trackUserActions();

        // Hook async transports (fetch/XHR)
        this.patchFetch();
        this.patchXMLHttpRequest();

        // Show loader for classic form submissions
        this.interceptFormSubmissions();
        
        // Intercept link clicks for smooth transitions
        this.interceptLinks();
        
        // Handle browser back/forward
        window.addEventListener('popstate', () => {
            this.resetPageVisualState();
            this.showPageLoader();
            setTimeout(() => this.hidePageLoader(), 300);
        });
        
        // Hide loader on page load
        window.addEventListener('load', () => {
            this.resetPageVisualState();
            setTimeout(() => this.hidePageLoader(), 100);
        });

        // BFCache/back-forward restores may not fire full load again.
        window.addEventListener('pageshow', () => {
            this.isTransitioning = false;
            this.activeAsyncRequests = 0;
            this.resetPageVisualState();
            this.hidePageLoader();
        });
        
        // Hide loader if page is already loaded
        if (document.readyState === 'complete') {
            this.resetPageVisualState();
            setTimeout(() => this.hidePageLoader(), 100);
        }

        // Expose global helpers for page-specific scripts
        window.showGlobalLoader = (message) => this.showPageLoader(message || this.defaultAsyncMessage);
        window.hideGlobalLoader = () => this.hidePageLoader();
    }

    injectLoaderStyles() {
        if (document.getElementById('pageLoaderInlineStyles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'pageLoaderInlineStyles';
        style.textContent = `
            .page-loader {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.94);
                z-index: 99999;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                opacity: 1;
                transition: opacity 0.25s ease-out;
                backdrop-filter: blur(1px);
            }

            .page-loader.fade-out {
                opacity: 0;
                pointer-events: none;
            }

            .app-loader {
                width: 56px;
                height: 56px;
                position: relative;
                margin-bottom: 16px;
            }

            .app-loader-circle {
                width: 100%;
                height: 100%;
                border: 4px solid rgba(0, 200, 83, 0.18);
                border-top-color: #00c853;
                border-radius: 50%;
                animation: appSpinner 0.8s linear infinite;
            }

            @keyframes appSpinner {
                to { transform: rotate(360deg); }
            }

            .dots-loader {
                display: flex;
                gap: 8px;
                align-items: center;
                justify-content: center;
            }

            .dots-loader span {
                width: 10px;
                height: 10px;
                background: #00c853;
                border-radius: 50%;
                animation: dotsBounce 1.3s ease-in-out infinite;
            }

            .dots-loader span:nth-child(1) { animation-delay: 0s; }
            .dots-loader span:nth-child(2) { animation-delay: 0.2s; }
            .dots-loader span:nth-child(3) { animation-delay: 0.4s; }

            @keyframes dotsBounce {
                0%, 80%, 100% {
                    transform: scale(0.8);
                    opacity: 0.45;
                }
                40% {
                    transform: scale(1.2);
                    opacity: 1;
                }
            }

            .page-loader-message {
                margin-top: 14px;
                color: #6b7280;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                max-width: 80vw;
            }
        `;

        document.head.appendChild(style);
    }

    createPageLoader() {
        if (document.getElementById('pageLoader')) {
            return;
        }

        const loader = document.createElement('div');
        loader.id = 'pageLoader';
        loader.className = 'page-loader';
        loader.innerHTML = `
            <div class="app-loader">
                <div class="app-loader-circle"></div>
            </div>
            <div class="dots-loader">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <p class="page-loader-message" id="pageLoaderMessage">Loading...</p>
        `;
        document.body.appendChild(loader);
        
        // Hide loader after initial load
        setTimeout(() => this.hidePageLoader(), 300);
    }

    showPageLoader(message = 'Loading...') {
        const loader = document.getElementById('pageLoader');
        if (loader) {
            const messageNode = document.getElementById('pageLoaderMessage');
            if (messageNode) {
                messageNode.textContent = message;
            }

            loader.style.display = 'flex';
            loader.classList.remove('fade-out');
            loader.style.opacity = '1';
        }
    }

    hidePageLoader() {
        const loader = document.getElementById('pageLoader');
        if (loader) {
            loader.classList.add('fade-out');
            setTimeout(() => {
                loader.style.display = 'none';
                this.resetPageVisualState();
            }, 300);
        }
    }

    resetPageVisualState() {
        document.body.style.opacity = '';
        document.body.style.transition = '';
    }

    trackUserActions() {
        const isChatbotEvent = (event) => {
            const target = event && event.target;
            return target instanceof Element && (
                target.id === 'aiChatInput' ||
                target.id === 'aiChatSendBtn' ||
                Boolean(target.closest('#aiCarChatWidget'))
            );
        };

        const markAction = (event) => {
            if (isChatbotEvent(event)) {
                return;
            }
            this.lastUserActionAt = Date.now();
        };

        document.addEventListener('click', markAction, true);
        document.addEventListener('submit', markAction, true);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                if (isChatbotEvent(e)) {
                    return;
                }
                markAction();
            }
        }, true);
    }

    wasRecentUserAction() {
        return Date.now() - this.lastUserActionAt < 1800;
    }

    shouldTrackRequestUrl(url) {
        if (!url || typeof url !== 'string') {
            return true;
        }

        // Ignore static assets to prevent loader flicker on CSS/JS/image loads.
        return !/\.(png|jpe?g|gif|webp|svg|ico|css|js|woff2?|ttf|map)(\?|$)/i.test(url);
    }

    beginAsyncLoading(message) {
        this.activeAsyncRequests += 1;

        if (this.asyncHideTimer) {
            clearTimeout(this.asyncHideTimer);
            this.asyncHideTimer = null;
        }

        this.showPageLoader(message || this.defaultAsyncMessage);
    }

    endAsyncLoading() {
        this.activeAsyncRequests = Math.max(0, this.activeAsyncRequests - 1);

        if (this.activeAsyncRequests === 0) {
            this.asyncHideTimer = setTimeout(() => {
                this.hidePageLoader();
            }, 120);
        }
    }

    patchFetch() {
        if (window.__motorlinkFetchPatched || typeof window.fetch !== 'function') {
            return;
        }

        const originalFetch = window.fetch.bind(window);

        window.fetch = async (...args) => {
            const target = args[0];
            const init = args[1];
            const url = typeof target === 'string' ? target : (target && target.url ? target.url : '');

            let skipGlobalLoader = false;
            if (!skipGlobalLoader && typeof Request !== 'undefined' && target instanceof Request) {
                skipGlobalLoader = target.headers.get('X-Skip-Global-Loader') === '1';
            }

            if (init && init.headers) {
                if (typeof Headers !== 'undefined' && init.headers instanceof Headers) {
                    skipGlobalLoader = init.headers.get('X-Skip-Global-Loader') === '1';
                } else if (Array.isArray(init.headers)) {
                    skipGlobalLoader = init.headers.some(([key, value]) =>
                        String(key).toLowerCase() === 'x-skip-global-loader' && String(value) === '1'
                    );
                } else if (typeof init.headers === 'object') {
                    const headerValue = init.headers['X-Skip-Global-Loader'] || init.headers['x-skip-global-loader'];
                    skipGlobalLoader = String(headerValue) === '1';
                }
            }

            const shouldTrack = !skipGlobalLoader && this.wasRecentUserAction() && this.shouldTrackRequestUrl(url);

            if (shouldTrack) {
                this.beginAsyncLoading();
            }

            try {
                return await originalFetch(...args);
            } finally {
                if (shouldTrack) {
                    this.endAsyncLoading();
                }
            }
        };

        window.__motorlinkFetchPatched = true;
    }

    patchXMLHttpRequest() {
        if (window.__motorlinkXHRPatched || typeof XMLHttpRequest === 'undefined') {
            return;
        }

        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function(method, url, ...rest) {
            this.__loaderUrl = typeof url === 'string' ? url : '';
            return originalOpen.call(this, method, url, ...rest);
        };

        XMLHttpRequest.prototype.send = function(...args) {
            const manager = window.pageTransitionManager;
            const shouldTrack = manager && manager.wasRecentUserAction() && manager.shouldTrackRequestUrl(this.__loaderUrl || '');

            if (shouldTrack) {
                manager.beginAsyncLoading();

                const done = () => {
                    this.removeEventListener('loadend', done);
                    manager.endAsyncLoading();
                };

                this.addEventListener('loadend', done);
            }

            return originalSend.apply(this, args);
        };

        window.__motorlinkXHRPatched = true;
    }

    interceptFormSubmissions() {
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            // Never show full-page loader for chatbot interactions.
            if (
                form.closest('#aiCarChatWidget') ||
                form.querySelector('#aiChatInput') ||
                (document.activeElement && document.activeElement.id === 'aiChatInput')
            ) {
                return;
            }

            // Optional opt-out for any future lightweight forms.
            if (form.hasAttribute('data-skip-global-loader')) {
                return;
            }

            // Skip GET forms (usually simple filters/search where full overlay is unnecessary).
            const method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method === 'get') {
                return;
            }

            const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitter) {
                submitter.setAttribute('aria-busy', 'true');
                submitter.setAttribute('disabled', 'disabled');
            }

            this.showPageLoader('Submitting...');

            // Safety hide for JS-only handlers that prevent navigation and never call fetch.
            setTimeout(() => {
                this.hidePageLoader();
                if (submitter) {
                    submitter.removeAttribute('disabled');
                    submitter.removeAttribute('aria-busy');
                }
            }, 12000);
        }, true);
    }

    interceptLinks() {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            
            // Skip if:
            // - Not a link
            // - External link
            // - Anchor link
            // - Download link
            // - Already transitioning
            // - Ctrl/Cmd/Ctrl+Shift clicked (new tab)
            if (!link || 
                this.isTransitioning ||
                link.target === '_blank' ||
                link.hasAttribute('download') ||
                link.href.startsWith('mailto:') ||
                link.href.startsWith('tel:') ||
                link.href.includes('#') && !link.href.includes('.html#') ||
                e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            
            const href = link.getAttribute('href');

            // Skip pure same-page hash navigation (e.g. sidebar links: href="#fleet")
            if (!href || href.startsWith('#')) {
                return;
            }
            
            // Only intercept internal links
            if (href && 
                (href.endsWith('.html') || 
                 href.startsWith('/') || 
                 (!href.startsWith('http') && !href.startsWith('//')))) {
                
                // Skip admin and special pages
                if (href.includes('admin') || 
                    href.includes('onboarding') ||
                    href.includes('api') ||
                    href.includes('proxy')) {
                    return;
                }
                
                e.preventDefault();
                this.handleNavigation(href);
            }
        });
    }

    handleNavigation(href) {
        if (this.isTransitioning) return;

        // Never animate same-page hash navigation
        if (href && href.startsWith('#')) {
            return;
        }
        
        this.isTransitioning = true;
        this.showPageLoader('Loading page...');
        
        // Add fade out animation to body
        document.body.style.opacity = '0.7';
        document.body.style.transition = 'opacity 0.2s ease-out';
        
        // Navigate after short delay
        setTimeout(() => {
            window.location.href = href;
        }, 200);
    }

    // Manual navigation trigger (for programmatic navigation)
    navigate(url) {
        this.handleNavigation(url);
    }
}

// Initialize page transitions
let pageTransitionManager;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        pageTransitionManager = new PageTransitionManager();
        window.pageTransitionManager = pageTransitionManager;
    });
} else {
    pageTransitionManager = new PageTransitionManager();
    window.pageTransitionManager = pageTransitionManager;
}

// Export for use in other scripts
window.PageTransitionManager = PageTransitionManager;


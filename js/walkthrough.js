/**
 * MotorLink — Interactive Product Walkthrough
 * --------------------------------------------
 *   - Contextual tooltips / spotlight tour
 *   - Auto-runs on first login and first visit after registration
 *   - Dismissible forever per-user (DB) + localStorage for guests
 *   - No external dependencies
 *   - Admin-toggleable (server-side feature flag)
 */
(function () {
    'use strict';

    // Only run on index.html (home page)
    const path = (window.location.pathname || '/').toLowerCase();
    if (!(path === '/' || path.endsWith('/index.html') || path.endsWith('/'))) {
        return;
    }

    const LS_COMPLETED_KEY = 'motorlink_walkthrough_completed';
    const LS_DISMISSED_KEY = 'motorlink_walkthrough_dismissed';
    const API_BASE = (window.location.hostname === 'localhost' || window.location.hostname.startsWith('127.')) ? 'proxy.php' : 'api.php';

    // ─── Tour steps ───────────────────────────────────────────────────────
    // Each step targets an element on index.html; falls back gracefully if missing.
    const STEPS = [
        {
            selector: '.hero-search, #heroSearch, .search-form, .search-container',
            title: 'Find Your Perfect Car',
            body:  'Search thousands of vehicles by make, model, price, or location. Start typing or use filters to narrow down your ideal ride.',
            icon:  'fa-magnifying-glass',
            position: 'bottom'
        },
        {
            selector: 'a[href*="showroom"], a[href*="car-database"], nav a:nth-child(1)',
            title: 'Browse Our Showroom',
            body:  'Explore our complete inventory of cars for sale across Malawi — from budget-friendly to luxury.',
            icon:  'fa-car',
            position: 'bottom'
        },
        {
            selector: 'a[href*="car-hire"]',
            title: 'Car Hire Services',
            body:  'Need a car for a day, a wedding, or a long trip? Browse verified car hire companies with instant WhatsApp booking.',
            icon:  'fa-key',
            position: 'bottom'
        },
        {
            selector: 'a[href*="dealers"]',
            title: 'Trusted Dealers',
            body:  'Connect with certified dealers across Malawi. Check their ratings, inventory, and get direct contact details.',
            icon:  'fa-building',
            position: 'bottom'
        },
        {
            selector: 'a[href*="garages"]',
            title: 'Find a Garage',
            body:  'Servicing, repairs, breakdowns — find nearby garages offering the services your car needs.',
            icon:  'fa-wrench',
            position: 'bottom'
        },
        {
            selector: '#aiChatToggle, .ai-chat-toggle, [data-ai-chat]',
            title: 'Meet Your AI Assistant',
            body:  'Ask anything about cars, prices, or services. Our AI assistant understands natural questions like "find me a 7-seater under 3M in Lilongwe".',
            icon:  'fa-robot',
            position: 'left'
        },
        {
            selector: 'a[href*="sell"]',
            title: 'Sell Your Car',
            body:  'Ready to sell? List your vehicle in minutes and reach thousands of buyers across the country.',
            icon:  'fa-tag',
            position: 'bottom'
        }
    ];

    let currentStep = 0;
    let overlay = null;
    let spotlight = null;
    let tooltip = null;
    let resizeHandler = null;

    // ─── Entry point ──────────────────────────────────────────────────────
    function init() {
        // Quick localStorage shortcut
        try {
            if (localStorage.getItem(LS_COMPLETED_KEY) === '1' || localStorage.getItem(LS_DISMISSED_KEY) === '1') {
                return;
            }
        } catch (e) {}

        fetch(`${API_BASE}?action=get_walkthrough_state`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success) return;
                if (!data.should_show) {
                    try { localStorage.setItem(LS_COMPLETED_KEY, '1'); } catch (e) {}
                    return;
                }
                // Delay so the page has painted
                setTimeout(start, 1200);
            })
            .catch(() => {});
    }

    // ─── Styles injection ─────────────────────────────────────────────────
    function ensureStyles() {
        if (document.getElementById('ml-wt-styles')) return;
        const style = document.createElement('style');
        style.id = 'ml-wt-styles';
        style.textContent = `
            .ml-wt-overlay {
                position: fixed; inset: 0;
                background: rgba(15, 23, 42, 0.55);
                z-index: 99998;
                opacity: 0;
                transition: opacity 0.3s ease;
                pointer-events: auto;
            }
            .ml-wt-overlay.show { opacity: 1; }
            .ml-wt-spotlight {
                position: fixed;
                border-radius: 12px;
                box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.65), 0 0 0 4px rgba(102, 126, 234, 0.6);
                z-index: 99999;
                pointer-events: none;
                transition: all 0.35s cubic-bezier(0.65, 0, 0.35, 1);
                background: transparent;
            }
            .ml-wt-tooltip {
                position: fixed;
                max-width: 340px;
                width: calc(100vw - 40px);
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 25px 60px rgba(0,0,0,0.4);
                z-index: 100000;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                overflow: hidden;
                opacity: 0;
                transform: translateY(10px);
                transition: opacity 0.25s ease, transform 0.25s ease;
            }
            .ml-wt-tooltip.show { opacity: 1; transform: translateY(0); }
            .ml-wt-head {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 18px 20px 14px;
                position: relative;
            }
            .ml-wt-close {
                position: absolute;
                top: 10px; right: 10px;
                background: rgba(255,255,255,0.2);
                border: 0;
                width: 30px; height: 30px;
                border-radius: 50%;
                color: #fff;
                font-size: 18px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s;
            }
            .ml-wt-close:hover { background: rgba(255,255,255,0.35); transform: rotate(90deg); }
            .ml-wt-step-icon {
                width: 42px; height: 42px;
                border-radius: 50%;
                background: rgba(255,255,255,0.25);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                margin-bottom: 8px;
            }
            .ml-wt-title { margin: 0; font-size: 1.1rem; font-weight: 700; }
            .ml-wt-body {
                padding: 16px 20px 18px;
                color: #334155;
                font-size: 0.92rem;
                line-height: 1.55;
            }
            .ml-wt-footer {
                padding: 10px 20px 18px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                border-top: 1px solid #f1f5f9;
                padding-top: 14px;
            }
            .ml-wt-progress {
                display: flex;
                gap: 5px;
                align-items: center;
            }
            .ml-wt-dot {
                width: 7px; height: 7px;
                border-radius: 50%;
                background: #e2e8f0;
                transition: all 0.2s;
            }
            .ml-wt-dot.active { background: #667eea; width: 18px; border-radius: 4px; }
            .ml-wt-actions {
                display: flex;
                gap: 8px;
            }
            .ml-wt-btn {
                padding: 8px 14px;
                border: 0;
                border-radius: 8px;
                font-weight: 600;
                font-size: 0.82rem;
                cursor: pointer;
                min-height: 36px;
                transition: all 0.2s;
                font-family: inherit;
            }
            .ml-wt-btn-skip {
                background: transparent;
                color: #94a3b8;
            }
            .ml-wt-btn-skip:hover { color: #475569; }
            .ml-wt-btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                box-shadow: 0 3px 10px rgba(102,126,234,0.35);
            }
            .ml-wt-btn-primary:hover { transform: translateY(-1px); }
            .ml-wt-btn-ghost {
                background: #f1f5f9;
                color: #475569;
            }
            .ml-wt-btn-ghost:hover { background: #e2e8f0; }
            .ml-wt-arrow {
                position: absolute;
                width: 16px; height: 16px;
                background: #fff;
                transform: rotate(45deg);
            }
            .ml-wt-arrow.top    { top: -8px; }
            .ml-wt-arrow.bottom { bottom: -8px; background: #fff; }
            .ml-wt-arrow.left   { left: -8px; }
            .ml-wt-arrow.right  { right: -8px; }

            @media (max-width: 520px) {
                .ml-wt-tooltip {
                    max-width: none;
                    left: 50% !important;
                    transform: translateX(-50%) translateY(10px);
                    bottom: 20px !important;
                    top: auto !important;
                }
                .ml-wt-tooltip.show { transform: translateX(-50%) translateY(0); }
                .ml-wt-arrow { display: none; }
            }
        `;
        document.head.appendChild(style);
    }

    // ─── Start ────────────────────────────────────────────────────────────
    function start() {
        ensureStyles();
        currentStep = 0;

        overlay = document.createElement('div');
        overlay.className = 'ml-wt-overlay';
        overlay.addEventListener('click', () => showStep(currentStep + 1));
        document.body.appendChild(overlay);

        spotlight = document.createElement('div');
        spotlight.className = 'ml-wt-spotlight';
        document.body.appendChild(spotlight);

        tooltip = document.createElement('div');
        tooltip.className = 'ml-wt-tooltip';
        document.body.appendChild(tooltip);

        requestAnimationFrame(() => overlay.classList.add('show'));

        resizeHandler = () => { if (overlay) showStep(currentStep, true); };
        window.addEventListener('resize', resizeHandler);
        window.addEventListener('scroll', resizeHandler, { passive: true });

        showStep(0);
    }

    // ─── Render step ──────────────────────────────────────────────────────
    function showStep(index, silent) {
        if (index >= STEPS.length) {
            finish(true);
            return;
        }
        currentStep = index;
        const step = STEPS[index];
        let target = document.querySelector(step.selector);

        // If the target is missing on this page, skip silently
        if (!target) {
            showStep(index + 1, silent);
            return;
        }

        const rect = target.getBoundingClientRect();
        const pad = 8;

        // Position spotlight
        spotlight.style.top    = (rect.top - pad) + 'px';
        spotlight.style.left   = (rect.left - pad) + 'px';
        spotlight.style.width  = (rect.width  + pad * 2) + 'px';
        spotlight.style.height = (rect.height + pad * 2) + 'px';

        // Smooth-scroll the element into view if it's off-screen
        if (rect.top < 50 || rect.bottom > window.innerHeight - 50) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => { if (overlay) showStep(index, true); }, 400);
            return;
        }

        // Build tooltip content
        tooltip.innerHTML = `
            <div class="ml-wt-head">
                <button class="ml-wt-close" aria-label="Close tour">&times;</button>
                <div class="ml-wt-step-icon"><i class="fas ${step.icon || 'fa-lightbulb'}"></i></div>
                <h3 class="ml-wt-title">${step.title}</h3>
            </div>
            <div class="ml-wt-body">${step.body}</div>
            <div class="ml-wt-footer">
                <div class="ml-wt-progress">
                    ${STEPS.map((_, i) => `<span class="ml-wt-dot${i === index ? ' active' : ''}"></span>`).join('')}
                </div>
                <div class="ml-wt-actions">
                    ${index > 0 ? '<button class="ml-wt-btn ml-wt-btn-ghost" data-wt-prev>Back</button>' : '<button class="ml-wt-btn ml-wt-btn-skip" data-wt-skip>Skip</button>'}
                    <button class="ml-wt-btn ml-wt-btn-primary" data-wt-next>
                        ${index === STEPS.length - 1 ? '<i class="fas fa-check"></i> Finish' : 'Next <i class="fas fa-arrow-right"></i>'}
                    </button>
                </div>
            </div>
        `;

        // Wire buttons
        tooltip.querySelector('.ml-wt-close').onclick = () => finish(false);
        const skipBtn = tooltip.querySelector('[data-wt-skip]');
        if (skipBtn) skipBtn.onclick = () => finish(false);
        const prevBtn = tooltip.querySelector('[data-wt-prev]');
        if (prevBtn) prevBtn.onclick = (e) => { e.stopPropagation(); showStep(index - 1); };
        tooltip.querySelector('[data-wt-next]').onclick = (e) => { e.stopPropagation(); showStep(index + 1); };

        // Position tooltip below or above target
        const tt = tooltip;
        tt.style.visibility = 'hidden';
        tt.classList.add('show');
        const ttRect = tt.getBoundingClientRect();
        let top, left;

        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;

        if (spaceBelow > ttRect.height + 20 || spaceBelow > spaceAbove) {
            top = rect.bottom + 14;
        } else {
            top = rect.top - ttRect.height - 14;
        }
        left = rect.left + (rect.width / 2) - (ttRect.width / 2);
        left = Math.max(10, Math.min(window.innerWidth - ttRect.width - 10, left));
        top  = Math.max(10, Math.min(window.innerHeight - ttRect.height - 10, top));

        tt.style.top  = top + 'px';
        tt.style.left = left + 'px';
        tt.style.visibility = 'visible';
    }

    // ─── Finish / Dismiss ─────────────────────────────────────────────────
    function finish(completed) {
        try {
            localStorage.setItem(completed ? LS_COMPLETED_KEY : LS_DISMISSED_KEY, '1');
        } catch (e) {}

        fetch(`${API_BASE}?action=complete_walkthrough`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ completed: completed })
        }).catch(() => {});

        if (overlay) { overlay.classList.remove('show'); setTimeout(() => overlay && overlay.remove(), 300); }
        if (spotlight) spotlight.remove();
        if (tooltip)   tooltip.remove();
        if (resizeHandler) {
            window.removeEventListener('resize', resizeHandler);
            window.removeEventListener('scroll', resizeHandler);
        }
        overlay = spotlight = tooltip = null;
    }

    // ─── Expose manual trigger for "Take the tour" button ────────────────
    window.startMotorLinkWalkthrough = function () {
        try { localStorage.removeItem(LS_COMPLETED_KEY); localStorage.removeItem(LS_DISMISSED_KEY); } catch (e) {}
        start();
    };

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

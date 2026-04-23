/**
 * MotorLink — Interactive Product Walkthrough
 * ─────────────────────────────────────────────
 *   · Spotlight + contextual tooltip tour (7 steps on index.html)
 *   · Tooltip has a CSS arrow that POINTS at the highlighted target
 *   · Tooltip transitions (fade + move) between steps
 *   · Back / Next / Skip / Finish all work correctly
 *   · Clicking the dark overlay does NOT advance the step (only buttons do)
 *   · Brand green theme — no purple
 *   · Admin-toggleable via site_settings
 */
(function () {
    'use strict';

    // Only run on homepage
    const _path = (window.location.pathname || '/').toLowerCase().replace(/\/+$/, '');
    if (_path !== '' && _path !== '/index.html' && !_path.endsWith('/index.html')) return;

    const LS_DONE = 'motorlink_walkthrough_completed';
    const LS_SKIP = 'motorlink_walkthrough_dismissed';
    const API_BASE = (window.location.hostname === 'localhost' || window.location.hostname.startsWith('127.')) ? 'proxy.php' : 'api.php';

    // ── Steps ────────────────────────────────────────────────────────────
    const STEPS = [
        {
            selector: '.hero-search, #heroSearch, .search-form, .search-container, form[role="search"]',
            title: 'Find Your Perfect Car',
            body:  'Search thousands of vehicles by make, model, price, or location. Start typing or use the smart filters.',
            icon:  'fa-magnifying-glass'
        },
        {
            selector: '#listingsGrid, .listings-section, .listings-grid',
            title: 'Browse Our Showroom',
            body:  'Explore our full inventory — from budget-friendly to luxury cars available across Malawi.',
            icon:  'fa-car-side'
        },
        {
            selector: 'a[href*="car-hire"]',
            title: 'Car Hire Services',
            body:  'Need wheels for a wedding, trip, or business? Book instantly via WhatsApp with verified hire companies.',
            icon:  'fa-key'
        },
        {
            selector: 'a[href*="dealers"]',
            title: 'Trusted Dealers',
            body:  'Browse certified dealers, see their ratings and full inventory in one tap.',
            icon:  'fa-store'
        },
        {
            selector: 'a[href*="garages"]',
            title: 'Find a Garage',
            body:  'Service, repairs, or a breakdown? Find a nearby garage that covers exactly what you need.',
            icon:  'fa-wrench'
        },
        {
            selector: '#aiChatToggle, .ai-chat-toggle, [data-ai-chat], .ai-car-chat-widget',
            title: 'Your AI Car Assistant',
            body:  'Ask anything in plain language — "7-seater under MK3M in Blantyre". Our AI finds it for you. Available to registered users — <a href="register.html" style="color:#00c853;font-weight:600;">sign up free</a> to unlock.',
            icon:  'fa-robot'
        },
        {
            selector: 'a[href*="sell"]',
            title: 'Sell Your Car',
            body:  'List your car in minutes and reach thousands of serious buyers across the country.',
            icon:  'fa-tag'
        }
    ];

    let step      = 0;
    let overlay   = null;
    let spotlight = null;
    let card      = null;         // the tooltip card
    let resizeRaf = null;

    // ── Styles ───────────────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('ml-wt-css')) return;
        const s = document.createElement('style');
        s.id = 'ml-wt-css';
        s.textContent = `
            #ml-wt-overlay {
                position: fixed; inset: 0;
                background: rgba(10,20,30,0.62);
                z-index: 99990;
                opacity: 0;
                transition: opacity 0.3s ease;
                pointer-events: all;
                cursor: default;
            }
            #ml-wt-overlay.wt-on { opacity: 1; }

            #ml-wt-spot {
                position: fixed;
                border-radius: 10px;
                box-shadow: 0 0 0 9999px rgba(10,20,30,0.62),
                            0 0 0 3px #00c853,
                            0 0 24px rgba(0,200,83,0.4);
                z-index: 99991;
                pointer-events: none;
                transition: top 0.38s cubic-bezier(0.65,0,0.35,1),
                            left 0.38s cubic-bezier(0.65,0,0.35,1),
                            width 0.38s cubic-bezier(0.65,0,0.35,1),
                            height 0.38s cubic-bezier(0.65,0,0.35,1);
                background: transparent;
            }

            #ml-wt-card {
                position: fixed;
                width: 320px;
                max-width: calc(100vw - 32px);
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 20px 50px rgba(0,0,0,0.35);
                z-index: 99992;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                overflow: visible;
                opacity: 0;
                transform: scale(0.94);
                transition: opacity 0.22s ease, transform 0.22s ease,
                            top 0.35s cubic-bezier(0.65,0,0.35,1),
                            left 0.35s cubic-bezier(0.65,0,0.35,1);
                pointer-events: all;
            }
            #ml-wt-card.wt-show { opacity: 1; transform: scale(1); }
            #ml-wt-card.wt-fade { opacity: 0; transform: scale(0.94); }

            .wt-card-inner { border-radius: 16px; overflow: hidden; }

            .wt-head {
                background: linear-gradient(135deg, #00c853 0%, #00a843 100%);
                color: #fff;
                padding: 16px 18px 13px;
                position: relative;
            }
            .wt-head-row { display: flex; align-items: center; gap: 12px; }
            .wt-head-icon {
                width: 40px; height: 40px;
                border-radius: 50%;
                background: rgba(255,255,255,0.22);
                display: flex; align-items: center; justify-content: center;
                font-size: 17px; flex-shrink: 0;
            }
            .wt-head-title { font-size: 1rem; font-weight: 700; margin: 0; flex: 1; line-height: 1.3; }
            .wt-close {
                background: rgba(255,255,255,0.2);
                border: 0; width: 28px; height: 28px; border-radius: 50%;
                color: #fff; font-size: 17px; cursor: pointer;
                display: flex; align-items: center; justify-content: center;
                transition: all 0.2s; flex-shrink: 0;
            }
            .wt-close:hover { background: rgba(255,255,255,0.38); transform: rotate(90deg); }

            .wt-body {
                padding: 14px 18px 16px;
                color: #374151; font-size: 0.9rem; line-height: 1.6;
            }

            .wt-footer {
                display: flex; align-items: center; justify-content: space-between;
                padding: 10px 16px 14px; border-top: 1px solid #f3f4f6;
            }
            .wt-dots { display: flex; gap: 5px; align-items: center; }
            .wt-dot  {
                width: 6px; height: 6px; border-radius: 50%;
                background: #d1d5db; transition: all 0.2s;
            }
            .wt-dot.on { background: #00c853; width: 16px; border-radius: 3px; }

            .wt-btns { display: flex; gap: 7px; }
            .wt-btn {
                padding: 7px 14px; border: 0; border-radius: 8px;
                font-weight: 600; font-size: 0.8rem; cursor: pointer;
                min-height: 34px; transition: all 0.18s;
                font-family: inherit; display: flex; align-items: center; gap: 5px;
                white-space: nowrap;
            }
            .wt-btn-ghost { background: #f3f4f6; color: #6b7280; }
            .wt-btn-ghost:hover { background: #e5e7eb; color: #374151; }
            .wt-btn-skip  { background: transparent; color: #9ca3af; padding-left: 6px; }
            .wt-btn-skip:hover { color: #6b7280; }
            .wt-btn-primary {
                background: linear-gradient(135deg, #00c853 0%, #00a843 100%);
                color: #fff; box-shadow: 0 3px 10px rgba(0,200,83,0.3);
            }
            .wt-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(0,200,83,0.4); }

            /* Arrow pointing from card toward the target element */
            .wt-arrow {
                position: absolute;
                width: 0; height: 0;
                pointer-events: none;
            }
            /* Arrow below spotlight (card is above) — points DOWN */
            .wt-arrow-bottom {
                bottom: -11px; left: var(--arrow-x, 50%); transform: translateX(-50%);
                border-left: 11px solid transparent;
                border-right: 11px solid transparent;
                border-top: 12px solid #fff;
                filter: drop-shadow(0 3px 4px rgba(0,0,0,0.12));
            }
            /* Arrow above spotlight (card is below) — points UP */
            .wt-arrow-top {
                top: -11px; left: var(--arrow-x, 50%); transform: translateX(-50%);
                border-left: 11px solid transparent;
                border-right: 11px solid transparent;
                border-bottom: 12px solid #00c853;
            }
            /* Arrow to right (card is to left) — points RIGHT */
            .wt-arrow-right {
                right: -11px; top: var(--arrow-y, 50%); transform: translateY(-50%);
                border-top: 11px solid transparent;
                border-bottom: 11px solid transparent;
                border-left: 12px solid #fff;
                filter: drop-shadow(3px 0 4px rgba(0,0,0,0.12));
            }
            /* Arrow to left (card is to right) — points LEFT */
            .wt-arrow-left {
                left: -11px; top: var(--arrow-y, 50%); transform: translateY(-50%);
                border-top: 11px solid transparent;
                border-bottom: 11px solid transparent;
                border-right: 12px solid #00c853;
            }

            .wt-step-label {
                font-size: 0.73rem; color: rgba(255,255,255,0.8);
                margin-top: 2px; font-weight: 500;
            }

            @media (max-width: 860px) {
                #ml-wt-card {
                    width: min(calc(100vw - 32px), 380px);
                    max-width: calc(100vw - 32px);
                    position: fixed !important;
                    bottom: calc(env(safe-area-inset-bottom, 0px) + 16px) !important;
                    left: 50% !important;
                    transform: translateX(-50%) !important;
                    top: auto !important;
                    right: auto !important;
                    transition: opacity 0.22s ease, transform 0.22s ease;
                }
                #ml-wt-card.wt-show {
                    opacity: 1;
                    transform: translateX(-50%) scale(1) !important;
                }
                #ml-wt-card.wt-fade {
                    opacity: 0;
                    transform: translateX(-50%) scale(0.94) !important;
                }
                .wt-arrow { display: none; }
                #ml-wt-spot {
                    /* Keep spotlight visible above the bottom-docked card */
                    max-height: calc(100vh - 220px);
                }
                .wt-body {
                    font-size: 0.88rem;
                    padding: 12px 16px 14px;
                    max-height: 120px;
                    overflow-y: auto;
                }
                .wt-btn {
                    min-height: 40px;
                    padding: 9px 16px;
                    font-size: 0.83rem;
                }
            }
        `;
        document.head.appendChild(s);
    }

    // ── Entry ────────────────────────────────────────────────────────────
    function init() {
        // Always ask the server first — a DB reset must override any stale localStorage flag
        fetch(`${API_BASE}?action=get_walkthrough_state`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d || !d.success) return;

                if (d.should_show) {
                    // Server says show — clear any stale "done/skip" flags so the tour runs
                    try {
                        localStorage.removeItem(LS_DONE);
                        localStorage.removeItem(LS_SKIP);
                    } catch(e){}
                    setTimeout(launch, 1500);
                } else {
                    // Server says done — cache that locally to avoid future API calls
                    try { localStorage.setItem(LS_DONE, '1'); } catch(e){}
                }
            })
            .catch(() => {
                // Network error — fall back to localStorage check (guests / offline)
                try {
                    if (localStorage.getItem(LS_DONE) === '1' || localStorage.getItem(LS_SKIP) === '1') return;
                } catch(e){}
            });
    }

    // ── Launch ───────────────────────────────────────────────────────────
    function launch() {
        injectStyles();
        step = 0;

        overlay = document.createElement('div');
        overlay.id = 'ml-wt-overlay';
        // Clicking the dark area does NOT advance — intentional
        document.body.appendChild(overlay);

        spotlight = document.createElement('div');
        spotlight.id = 'ml-wt-spot';
        document.body.appendChild(spotlight);

        card = document.createElement('div');
        card.id = 'ml-wt-card';
        document.body.appendChild(card);

        requestAnimationFrame(() => overlay.classList.add('wt-on'));

        const onResize = () => {
            if (resizeRaf) cancelAnimationFrame(resizeRaf);
            resizeRaf = requestAnimationFrame(() => positionCurrentStep());
        };
        window.addEventListener('resize', onResize);
        window.addEventListener('scroll', onResize, { passive: true });
        overlay._cleanup = () => {
            window.removeEventListener('resize', onResize);
            window.removeEventListener('scroll', onResize);
        };

        showStep(0, false);
    }

    // ── Navigate between steps ───────────────────────────────────────────
    function isMobileLayout() {
        return window.innerWidth <= 860;
    }

    function showStep(index, animate) {
        if (index < 0) return;
        if (index >= STEPS.length) { finish(true); return; }

        const s = STEPS[index];
        const target = document.querySelector(s.selector);
        if (!target) { showStep(index + 1, animate); return; }

        step = index;

        const rect = target.getBoundingClientRect();
        // On mobile the card is docked to bottom (~220px tall), so leave that clearance
        const bottomReserved = isMobileLayout() ? 230 : 70;
        const needsScroll = rect.top < 70 || rect.bottom > window.innerHeight - bottomReserved;

        if (needsScroll) {
            // Fade card out, scroll, then render at new position
            if (card) card.classList.add('wt-fade');
            // On mobile scroll target to upper-third so it's visible above the docked card
            target.scrollIntoView({ behavior: 'smooth', block: isMobileLayout() ? 'start' : 'center' });
            setTimeout(() => { if (overlay) renderStep(index); }, 520);
        } else {
            renderStep(index, animate);
        }
    }

    function renderStep(index) {
        const s = STEPS[index];
        const target = document.querySelector(s.selector);
        if (!target) { showStep(index + 1, false); return; }

        const rect = target.getBoundingClientRect();
        const pad = 8;

        // Move spotlight
        spotlight.style.top    = (rect.top    - pad) + 'px';
        spotlight.style.left   = (rect.left   - pad) + 'px';
        spotlight.style.width  = (rect.width  + pad * 2) + 'px';
        spotlight.style.height = (rect.height + pad * 2) + 'px';

        // Build card HTML (hidden first for measurement)
        card.classList.remove('wt-show');
        card.classList.add('wt-fade');
        card.innerHTML = buildCardHTML(index, s);

        // Wire buttons BEFORE measurement
        wireButtons(index);

        // Measure, position, then reveal
        card.style.visibility = 'hidden';
        card.style.top  = '0px';
        card.style.left = '0px';
        requestAnimationFrame(() => {
            const pos = computePosition(rect, card.offsetWidth, card.offsetHeight, pad);
            card.style.top  = pos.top  + 'px';
            card.style.left = pos.left + 'px';

            // Set arrow position offsets
            card.style.setProperty('--arrow-x', pos.arrowX + 'px');
            card.style.setProperty('--arrow-y', pos.arrowY + 'px');

            // Update arrow class
            const existing = card.querySelector('.wt-arrow');
            if (existing) existing.className = 'wt-arrow ' + pos.arrowClass;

            card.style.visibility = 'visible';
            card.classList.remove('wt-fade');
            requestAnimationFrame(() => card && card.classList.add('wt-show'));
        });
    }

    function positionCurrentStep() {
        if (step < 0 || step >= STEPS.length) return;
        const s = STEPS[step];
        const target = document.querySelector(s.selector);
        if (!target) return;
        const pad = 8;
        const rect = target.getBoundingClientRect();
        spotlight.style.top    = (rect.top    - pad) + 'px';
        spotlight.style.left   = (rect.left   - pad) + 'px';
        spotlight.style.width  = (rect.width  + pad * 2) + 'px';
        spotlight.style.height = (rect.height + pad * 2) + 'px';

        const pos = computePosition(rect, card.offsetWidth, card.offsetHeight, pad);
        card.style.top  = pos.top  + 'px';
        card.style.left = pos.left + 'px';
        card.style.setProperty('--arrow-x', pos.arrowX + 'px');
        card.style.setProperty('--arrow-y', pos.arrowY + 'px');
        const arrow = card.querySelector('.wt-arrow');
        if (arrow) arrow.className = 'wt-arrow ' + pos.arrowClass;
    }

    // ── Position algorithm ───────────────────────────────────────────────
    function computePosition(rect, cW, cH, pad) {
        const vW = window.innerWidth;
        const vH = window.innerHeight;
        const gap = 14 + pad;  // gap from spotlight edge to card

        const spaceBelow = vH - rect.bottom - gap;
        const spaceAbove = rect.top        - gap;
        const spaceRight = vW - rect.right  - gap;
        const spaceLeft  = rect.left       - gap;

        let top, left, arrowClass, arrowX = 0, arrowY = 0;

        // Target centre
        const tCx = rect.left + rect.width  / 2;
        const tCy = rect.top  + rect.height / 2;

        if (spaceBelow >= cH || spaceBelow >= spaceAbove) {
            // Card below target — arrow points UP (toward target)
            top  = rect.bottom + gap;
            left = tCx - cW / 2;
            arrowClass = 'wt-arrow-top';
            arrowX = Math.max(22, Math.min(cW - 22, tCx - Math.max(16, Math.min(vW - cW - 16, left))));
        } else if (spaceAbove >= cH) {
            // Card above target — arrow points DOWN
            top  = rect.top - gap - cH;
            left = tCx - cW / 2;
            arrowClass = 'wt-arrow-bottom';
            arrowX = Math.max(22, Math.min(cW - 22, tCx - Math.max(16, Math.min(vW - cW - 16, left))));
        } else if (spaceRight >= cW) {
            // Card to right — arrow points LEFT
            left = rect.right + gap;
            top  = tCy - cH / 2;
            arrowClass = 'wt-arrow-left';
            arrowY = Math.max(22, Math.min(cH - 22, tCy - Math.max(16, Math.min(vH - cH - 16, top))));
        } else {
            // Card to left — arrow points RIGHT
            left = rect.left - gap - cW;
            top  = tCy - cH / 2;
            arrowClass = 'wt-arrow-right';
            arrowY = Math.max(22, Math.min(cH - 22, tCy - Math.max(16, Math.min(vH - cH - 16, top))));
        }

        // Clamp within viewport
        left = Math.max(16, Math.min(vW - cW - 16, left));
        top  = Math.max(16, Math.min(vH - cH - 16, top));

        return { top, left, arrowClass, arrowX, arrowY };
    }

    // ── Build card HTML ──────────────────────────────────────────────────
    function buildCardHTML(index, s) {
        const total = STEPS.filter(st => document.querySelector(st.selector)).length;
        const localIdx = STEPS.slice(0, index + 1).filter(st => document.querySelector(st.selector)).length - 1;
        const dots = STEPS
            .filter(st => document.querySelector(st.selector))
            .map((_, i) => `<span class="wt-dot${i === localIdx ? ' on' : ''}"></span>`)
            .join('');

        const isLast = STEPS.slice(index + 1).every(st => !document.querySelector(st.selector));
        const isFirst = STEPS.slice(0, index).every(st => !document.querySelector(st.selector));

        return `
            <div class="wt-card-inner">
                <div class="wt-head">
                    <div class="wt-head-row">
                        <div class="wt-head-icon"><i class="fas ${s.icon || 'fa-lightbulb'}"></i></div>
                        <div style="flex:1;min-width:0;">
                            <h3 class="wt-head-title">${s.title}</h3>
                            <div class="wt-step-label">Step ${localIdx + 1} of ${total}</div>
                        </div>
                        <button class="wt-close" data-wt="close" aria-label="End tour">&times;</button>
                    </div>
                </div>
                <div class="wt-body">${s.body}</div>
                <div class="wt-footer">
                    <div class="wt-dots">${dots}</div>
                    <div class="wt-btns">
                        ${isFirst
                            ? '<button class="wt-btn wt-btn-skip"  data-wt="skip">Skip tour</button>'
                            : '<button class="wt-btn wt-btn-ghost" data-wt="prev"><i class="fas fa-arrow-left"></i> Back</button>'
                        }
                        <button class="wt-btn wt-btn-primary" data-wt="next">
                            ${isLast ? '<i class="fas fa-check"></i> Finish' : 'Next <i class="fas fa-arrow-right"></i>'}
                        </button>
                    </div>
                </div>
            </div>
            <div class="wt-arrow"></div>
        `;
    }

    // ── Wire buttons (clean: no delegation races) ───────────────────────
    function wireButtons(index) {
        // Use data-wt attributes for safe delegation on the card
        card.onclick = function (e) {
            const btn = e.target.closest('[data-wt]');
            if (!btn) return;
            e.stopPropagation();
            const action = btn.dataset.wt;
            if (action === 'next')  { transition(() => showStep(index + 1, true)); }
            if (action === 'prev')  { transition(() => showStep(index - 1, true)); }
            if (action === 'skip')  finish(false);
            if (action === 'close') finish(false);
        };
    }

    function transition(fn) {
        if (!card) return;
        card.classList.remove('wt-show');
        card.classList.add('wt-fade');
        setTimeout(fn, 200);
    }

    // ── Finish ───────────────────────────────────────────────────────────
    function finish(completed) {
        try { localStorage.setItem(completed ? LS_DONE : LS_SKIP, '1'); } catch(e){}

        fetch(`${API_BASE}?action=complete_walkthrough`, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ completed })
        }).catch(() => {});

        if (overlay) {
            overlay.classList.remove('wt-on');
            if (overlay._cleanup) overlay._cleanup();
            setTimeout(() => { overlay && overlay.remove(); spotlight && spotlight.remove(); card && card.remove(); overlay = spotlight = card = null; }, 350);
        }
    }

    // ── Public API ───────────────────────────────────────────────────────
    window.startMotorLinkWalkthrough = function () {
        // Clean up any existing instance first
        if (overlay) finish(false);
        try { localStorage.removeItem(LS_DONE); localStorage.removeItem(LS_SKIP); } catch(e){}
        setTimeout(launch, 100);
    };

    // ── Boot ─────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
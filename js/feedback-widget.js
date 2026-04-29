/**
 * MotorLink — Visitor Feedback Widget
 * ------------------------------------
 *   - On FIRST EVER visit: shows a countdown pill (visible timer) before opening
 *   - On return visits: same countdown pill respects cooldown
 *   - Exit-intent (mouse leaves top / tab hidden) opens immediately
 *   - localStorage cooldown: dismissed / submitted
 *   - No external dependencies · Brand green theme
 */
(function () {
    'use strict';

    const LS_DISMISSED_KEY = 'motorlink_feedback_dismissed_until';
    const LS_SUBMITTED_KEY = 'motorlink_feedback_submitted_until';
    const API_BASE = (window.location.hostname === 'localhost' || window.location.hostname.startsWith('127.')) ? 'proxy.php' : 'api.php';

    let config    = null;
    let shown     = false;      // modal shown
    let pillEl    = null;       // countdown pill
    let pillTimer = null;       // setInterval handle
    let remainSec = 0;          // countdown seconds remaining
    let delayTimer = null;      // setTimeout handle for silent delay

    // ─── Skip admin / auth pages ──────────────────────────────────────────
    const path = window.location.pathname.toLowerCase();
    if (path.includes('/admin/') || path.endsWith('/login.html') || path.endsWith('/register.html')
        || path.endsWith('/reset-password.php') || path.endsWith('/verify-email.php')) {
        return;
    }

    // ─── Cooldown helpers ─────────────────────────────────────────────────
    function inCooldown() {
        try {
            const now = Date.now();
            const d = parseInt(localStorage.getItem(LS_DISMISSED_KEY) || '0', 10);
            const s = parseInt(localStorage.getItem(LS_SUBMITTED_KEY) || '0', 10);
            return (d && d > now) || (s && s > now);
        } catch (e) { return false; }
    }
    function setCooldown(key, days) {
        try { localStorage.setItem(key, String(Date.now() + days * 86400000)); } catch (e) {}
    }

    // ─── Boot ─────────────────────────────────────────────────────────────
    fetch(`${API_BASE}?action=get_feedback_config`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success || !data.enabled) return;
            config = data;
            if (inCooldown()) return;
            ensureStyles();
            scheduleCountdown();
        })
        .catch(() => {});

    // ─── Inject CSS once ─────────────────────────────────────────────────
    function ensureStyles() {
        if (document.getElementById('ml-feedback-styles')) return;
        const link = document.createElement('link');
        link.id   = 'ml-feedback-styles';
        link.rel  = 'stylesheet';
        link.href = 'css/feedback-widget.css';
        document.head.appendChild(link);
    }

    // ─── Show the visible countdown pill ─────────────────────────────────
    function scheduleCountdown() {
        const delaySec = Math.max(5, (config.delay_minutes || 5) * 60);

        // Show pill after a short page-settle delay
        setTimeout(() => {
            if (shown || inCooldown()) return;
            showPill(delaySec);
        }, 3000); // show pill 3s after load

        // Exit-intent hooks (open modal immediately, no pill needed)
        if (config.show_on_unload) {
            document.addEventListener('mouseleave', onExitIntent);
            document.addEventListener('visibilitychange', onVisibilityChange);
        }
    }

    // ─── Countdown Pill ──────────────────────────────────────────────────
    function showPill(seconds) {
        if (shown || pillEl) return;
        remainSec = seconds;

        pillEl = document.createElement('div');
        pillEl.className = 'ml-fb-pill';
        pillEl.setAttribute('role', 'complementary');
        pillEl.setAttribute('aria-label', 'Feedback countdown');

        renderPill();
        document.body.appendChild(pillEl);
        requestAnimationFrame(() => pillEl && pillEl.classList.add('ml-fb-pill-show'));

        // Tick every second
        pillTimer = setInterval(() => {
            remainSec--;
            if (remainSec <= 0) {
                clearInterval(pillTimer);
                pillTimer = null;
                dismissPill(false); // hide pill
                openModal();
            } else {
                updatePillTimer();
            }
        }, 1000);
    }

    function renderPill() {
        if (!pillEl) return;
        const totalSec = Math.max(5, (config.delay_minutes || 5) * 60);
        pillEl.innerHTML = `
            <div class="ml-fb-pill-top">
                <div class="ml-fb-pill-icon"><i class="fas fa-comment-dots"></i></div>
                <div>
                    <div class="ml-fb-pill-text">How's your experience?</div>
                    <div class="ml-fb-pill-timer" id="ml-fb-timer">${formatTime(remainSec)}</div>
                </div>
                <button class="ml-fb-pill-dismiss" id="ml-fb-pill-x" aria-label="Dismiss">&times;</button>
            </div>
            <div class="ml-fb-pill-track">
                <div class="ml-fb-pill-bar" id="ml-fb-bar" style="width:0%"></div>
            </div>
            <button class="ml-fb-pill-btn" id="ml-fb-pill-open">
                <i class="fas fa-pen-to-square"></i> Give Feedback Now
            </button>
        `;
        pillEl.querySelector('#ml-fb-pill-x').addEventListener('click', () => {
            dismissPill(true); // user explicitly dismissed
        });
        pillEl.querySelector('#ml-fb-pill-open').addEventListener('click', () => {
            clearInterval(pillTimer); pillTimer = null;
            dismissPill(false);
            openModal();
        });
        // Start the progress bar
        requestAnimationFrame(() => {
            const bar = document.getElementById('ml-fb-bar');
            if (bar) {
                bar.style.transition = 'none';
                bar.style.width = '0%';
                requestAnimationFrame(() => {
                    if (bar) {
                        bar.style.transition = `width ${totalSec}s linear`;
                        bar.style.width = '100%';
                    }
                });
            }
        });
    }

    function updatePillTimer() {
        const timerEl = document.getElementById('ml-fb-timer');
        if (timerEl) timerEl.textContent = formatTime(remainSec);
    }

    function dismissPill(userAction) {
        if (!pillEl) return;
        if (userAction) {
            setCooldown(LS_DISMISSED_KEY, config ? config.cooldown_days : 7);
            clearInterval(pillTimer); pillTimer = null;
        }
        pillEl.classList.add('ml-fb-pill-hide');
        setTimeout(() => { if (pillEl) { pillEl.remove(); pillEl = null; } }, 350);
    }

    function formatTime(sec) {
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    // ─── Exit intent ─────────────────────────────────────────────────────
    function onExitIntent(e) {
        if (shown || e.clientY > 0) return;
        clearInterval(pillTimer); pillTimer = null;
        dismissPill(false);
        openModal();
    }

    function onVisibilityChange() {
        if (shown || document.visibilityState !== 'hidden') return;
        if (!window._mlFbHideAt) { window._mlFbHideAt = Date.now(); return; }
        if (Date.now() - window._mlFbHideAt > 2500) {
            clearInterval(pillTimer); pillTimer = null;
            dismissPill(false);
            openModal();
        }
    }

    // ─── Build & open modal ───────────────────────────────────────────────
    function openModal() {
        if (shown) return;
        shown = true;
        document.removeEventListener('mouseleave', onExitIntent);
        document.removeEventListener('visibilitychange', onVisibilityChange);

        const overlay = document.createElement('div');
        overlay.className = 'ml-fb-overlay';
        overlay.innerHTML = `
            <div class="ml-fb-modal" role="dialog" aria-modal="true" aria-labelledby="mlFbTitle">
                <button class="ml-fb-close" aria-label="Close">&times;</button>
                <div class="ml-fb-header">
                    <div class="ml-fb-icon"><i class="fas fa-comment-dots"></i></div>
                    <h3 id="mlFbTitle">How's your experience?</h3>
                    <p>Your feedback helps us improve MotorLink for everyone in Malawi.</p>
                </div>
                <form class="ml-fb-form" id="mlFbForm" novalidate>
                    <div class="ml-fb-field">
                        <label>Overall rating</label>
                        <div class="ml-fb-stars" role="radiogroup" aria-label="Star rating">
                            ${[1,2,3,4,5].map(n => `<button type="button" class="ml-fb-star" data-val="${n}" aria-label="${n} star${n>1?'s':''}"><i class="far fa-star"></i></button>`).join('')}
                        </div>
                    </div>
                    <div class="ml-fb-field">
                        <label for="mlFbCat">Category</label>
                        <select id="mlFbCat" name="category">
                            <option value="general">General feedback</option>
                            <option value="suggestion">Suggestion</option>
                            <option value="compliment">Compliment</option>
                            <option value="bug">Report a bug</option>
                            <option value="complaint">Complaint</option>
                            <option value="feature_request">Feature request</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="ml-fb-field">
                        <label for="mlFbMsg">Your message</label>
                        <textarea id="mlFbMsg" name="message" rows="4" maxlength="2000"
                            placeholder="Tell us what's on your mind..." required></textarea>
                    </div>
                    <div class="ml-fb-field">
                        <label for="mlFbEmail">Email <span class="ml-fb-optional">(optional — for a reply)</span></label>
                        <input type="email" id="mlFbEmail" name="email" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <div class="ml-fb-result" id="mlFbResult" style="display:none;"></div>
                    <div class="ml-fb-actions">
                        <button type="button" class="ml-fb-btn ml-fb-btn-secondary" id="mlFbLater">Maybe later</button>
                        <button type="submit"  class="ml-fb-btn ml-fb-btn-primary"    id="mlFbSubmit">
                            <i class="fas fa-paper-plane"></i> Send Feedback
                        </button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ml-fb-show'));

        // Star rating
        let rating = 0;
        const stars = overlay.querySelectorAll('.ml-fb-star');
        stars.forEach(s => {
            s.addEventListener('click', () => { rating = parseInt(s.dataset.val, 10); paintStars(stars, rating); });
            s.addEventListener('mouseenter', () => paintStars(stars, parseInt(s.dataset.val, 10)));
        });
        overlay.querySelector('.ml-fb-stars').addEventListener('mouseleave', () => paintStars(stars, rating));

        overlay.querySelector('.ml-fb-close').addEventListener('click', () => dismiss(overlay));
        overlay.querySelector('#mlFbLater').addEventListener('click', () => dismiss(overlay));
        overlay.addEventListener('click', e => { if (e.target === overlay) dismiss(overlay); });
        overlay.querySelector('#mlFbForm').addEventListener('submit', e => { e.preventDefault(); doSubmit(overlay, rating); });
    }

    function paintStars(stars, count) {
        stars.forEach((s, i) => {
            const ico = s.querySelector('i');
            const on = i < count;
            ico.className = on ? 'fas fa-star' : 'far fa-star';
            s.classList.toggle('active', on);
        });
    }

    function dismiss(overlay) {
        setCooldown(LS_DISMISSED_KEY, config ? config.cooldown_days : 7);
        closeOverlay(overlay);
    }

    function closeOverlay(overlay) {
        overlay.classList.remove('ml-fb-show');
        setTimeout(() => overlay.remove(), 280);
    }

    async function doSubmit(overlay, rating) {
        const btn  = overlay.querySelector('#mlFbSubmit');
        const res  = overlay.querySelector('#mlFbResult');
        const msg  = overlay.querySelector('#mlFbMsg').value.trim();
        const email   = overlay.querySelector('#mlFbEmail').value.trim();
        const category = overlay.querySelector('#mlFbCat').value;

        if (msg.length < 3) { showResult(res, 'error', 'Please enter at least 3 characters.'); return; }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        let recaptchaToken = '';
        try {
            recaptchaToken = typeof window.getRecaptchaToken === 'function'
                ? await window.getRecaptchaToken('submit_feedback')
                : '';
        } catch (error) {
            showResult(res, 'error', 'Security check could not load. Please refresh and try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Feedback';
            return;
        }

        fetch(`${API_BASE}?action=submit_feedback`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                rating,
                category,
                message: msg,
                email,
                page_url: window.location.href,
                recaptcha_token: recaptchaToken,
                recaptcha_action: 'submit_feedback'
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                showResult(res, 'success', data.message || 'Thank you! Your feedback has been received.');
                setCooldown(LS_SUBMITTED_KEY, config ? config.cooldown_days : 30);
                setTimeout(() => closeOverlay(overlay), 1800);
            } else {
                showResult(res, 'error', (data && data.message) || 'Failed to submit. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Feedback';
            }
        })
        .catch(() => {
            showResult(res, 'error', 'Network error — please check your connection.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Feedback';
        });
    }

    function showResult(el, kind, msg) {
        el.style.display = 'block';
        el.className = 'ml-fb-result ml-fb-result-' + kind;
        el.textContent = msg;
    }

    // ─── Manual trigger from footer / help menu ───────────────────────────
    window.openMotorLinkFeedback = function () {
        clearInterval(pillTimer); pillTimer = null;
        dismissPill(false);
        if (!config) {
            fetch(`${API_BASE}?action=get_feedback_config`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => { if (d && d.success) { config = d; shown = false; openModal(); } });
        } else {
            shown = false;
            openModal();
        }
    };
})();

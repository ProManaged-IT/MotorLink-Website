/**
 * MotorLink — Visitor Feedback Widget
 * ------------------------------------
 *   - Loads public config from api.php?action=get_feedback_config
 *   - Triggers modal after N minutes OR on page unload (admin configurable)
 *   - Respects localStorage cooldown (user dismissed / submitted)
 *   - Works for authenticated and guest users
 *   - No external dependencies
 */
(function () {
    'use strict';

    const LS_DISMISSED_KEY  = 'motorlink_feedback_dismissed_until';
    const LS_SUBMITTED_KEY  = 'motorlink_feedback_submitted_until';
    const API_BASE          = (window.location.hostname === 'localhost' || window.location.hostname.startsWith('127.')) ? 'proxy.php' : 'api.php';

    let config = null;
    let shown  = false;
    let delayTimer = null;

    // ─── Skip on admin pages / login / modals intensive pages ─────────────
    const path = window.location.pathname.toLowerCase();
    if (path.includes('/admin/') || path.endsWith('/login.html') || path.endsWith('/register.html')
        || path.endsWith('/reset-password.php') || path.endsWith('/verify-email.php')) {
        return;
    }

    // ─── Cooldown check ───────────────────────────────────────────────────
    function inCooldown() {
        try {
            const dUntil = parseInt(localStorage.getItem(LS_DISMISSED_KEY) || '0', 10);
            const sUntil = parseInt(localStorage.getItem(LS_SUBMITTED_KEY) || '0', 10);
            const now = Date.now();
            return (dUntil && dUntil > now) || (sUntil && sUntil > now);
        } catch (e) { return false; }
    }

    function setCooldown(key, days) {
        try {
            const until = Date.now() + (days * 24 * 60 * 60 * 1000);
            localStorage.setItem(key, String(until));
        } catch (e) {}
    }

    // ─── Load config ──────────────────────────────────────────────────────
    fetch(`${API_BASE}?action=get_feedback_config`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success || !data.enabled) return;
            config = data;
            if (inCooldown()) return;
            scheduleTriggers();
        })
        .catch(() => { /* fail silent */ });

    // ─── Schedule the time-based + unload trigger ─────────────────────────
    function scheduleTriggers() {
        if (config.delay_minutes > 0) {
            delayTimer = setTimeout(openModal, config.delay_minutes * 60 * 1000);
        }
        if (config.show_on_unload) {
            // Desktop: show on mouseleave top edge (exit intent)
            document.addEventListener('mouseleave', onMouseLeave);
            // Mobile fallback: when tab becomes hidden
            document.addEventListener('visibilitychange', onVisibilityChange);
        }
    }

    function onMouseLeave(e) {
        if (shown || e.clientY > 0) return;
        openModal();
    }

    function onVisibilityChange() {
        if (shown || document.visibilityState !== 'hidden') return;
        // Give it a brief delay — ignore rapid tab switches
        if (!window._mlFeedbackHideAt) {
            window._mlFeedbackHideAt = Date.now();
        } else if (Date.now() - window._mlFeedbackHideAt > 2000) {
            openModal();
        }
    }

    // ─── Inject styles once ───────────────────────────────────────────────
    function ensureStyles() {
        if (document.getElementById('ml-feedback-styles')) return;
        const link = document.createElement('link');
        link.id   = 'ml-feedback-styles';
        link.rel  = 'stylesheet';
        link.href = 'css/feedback-widget.css';
        document.head.appendChild(link);
    }

    // ─── Build & open modal ───────────────────────────────────────────────
    function openModal() {
        if (shown) return;
        shown = true;
        if (delayTimer) { clearTimeout(delayTimer); delayTimer = null; }
        ensureStyles();

        const overlay = document.createElement('div');
        overlay.className = 'ml-fb-overlay';
        overlay.innerHTML = `
            <div class="ml-fb-modal" role="dialog" aria-modal="true" aria-labelledby="mlFbTitle">
                <button class="ml-fb-close" aria-label="Close feedback">&times;</button>
                <div class="ml-fb-header">
                    <div class="ml-fb-icon"><i class="fas fa-comment-dots"></i></div>
                    <h3 id="mlFbTitle">How's your experience?</h3>
                    <p>We'd love to hear from you — your feedback helps us improve MotorLink.</p>
                </div>
                <form class="ml-fb-form" id="mlFbForm">
                    <div class="ml-fb-field ml-fb-rating">
                        <label>Overall rating</label>
                        <div class="ml-fb-stars" role="radiogroup" aria-label="Rating">
                            ${[1,2,3,4,5].map(n => `<button type="button" class="ml-fb-star" data-val="${n}" aria-label="${n} star${n>1?'s':''}"><i class="far fa-star"></i></button>`).join('')}
                        </div>
                    </div>
                    <div class="ml-fb-field">
                        <label for="mlFbCategory">Category</label>
                        <select id="mlFbCategory" name="category">
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
                        <label for="mlFbMessage">Your message</label>
                        <textarea id="mlFbMessage" name="message" rows="4" maxlength="2000"
                            placeholder="Tell us what's on your mind..." required></textarea>
                    </div>
                    <div class="ml-fb-field">
                        <label for="mlFbEmail">Email <span class="ml-fb-optional">(optional, if you'd like a reply)</span></label>
                        <input type="email" id="mlFbEmail" name="email" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <div class="ml-fb-result" id="mlFbResult" style="display:none;"></div>
                    <div class="ml-fb-actions">
                        <button type="button" class="ml-fb-btn ml-fb-btn-secondary" id="mlFbLater">Maybe later</button>
                        <button type="submit" class="ml-fb-btn ml-fb-btn-primary" id="mlFbSubmit">
                            <i class="fas fa-paper-plane"></i> Send Feedback
                        </button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ml-fb-show'));

        // Star rating interaction
        let currentRating = 0;
        const stars = overlay.querySelectorAll('.ml-fb-star');
        stars.forEach(star => {
            star.addEventListener('click', () => {
                currentRating = parseInt(star.dataset.val, 10);
                paintStars(stars, currentRating);
            });
            star.addEventListener('mouseenter', () => paintStars(stars, parseInt(star.dataset.val, 10)));
        });
        overlay.querySelector('.ml-fb-stars').addEventListener('mouseleave', () => paintStars(stars, currentRating));

        overlay.querySelector('.ml-fb-close').addEventListener('click', () => dismiss(overlay));
        overlay.querySelector('#mlFbLater').addEventListener('click', () => dismiss(overlay));
        overlay.addEventListener('click', e => { if (e.target === overlay) dismiss(overlay); });

        overlay.querySelector('#mlFbForm').addEventListener('submit', e => {
            e.preventDefault();
            submitFeedback(overlay, currentRating);
        });
    }

    function paintStars(stars, count) {
        stars.forEach((s, i) => {
            const icon = s.querySelector('i');
            if (i < count) { icon.classList.remove('far'); icon.classList.add('fas'); s.classList.add('active'); }
            else           { icon.classList.remove('fas'); icon.classList.add('far'); s.classList.remove('active'); }
        });
    }

    function dismiss(overlay) {
        setCooldown(LS_DISMISSED_KEY, config ? config.cooldown_days : 7);
        closeOverlay(overlay);
    }

    function closeOverlay(overlay) {
        overlay.classList.remove('ml-fb-show');
        setTimeout(() => overlay.remove(), 250);
        document.removeEventListener('mouseleave', onMouseLeave);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    function submitFeedback(overlay, rating) {
        const btn     = overlay.querySelector('#mlFbSubmit');
        const result  = overlay.querySelector('#mlFbResult');
        const message = overlay.querySelector('#mlFbMessage').value.trim();
        const email   = overlay.querySelector('#mlFbEmail').value.trim();
        const category= overlay.querySelector('#mlFbCategory').value;

        if (message.length < 3) {
            showResult(result, 'error', 'Please enter a message (at least 3 characters).');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        fetch(`${API_BASE}?action=submit_feedback`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                rating, category, message, email,
                page_url: window.location.href
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                showResult(result, 'success', data.message || 'Thank you for your feedback!');
                setCooldown(LS_SUBMITTED_KEY, config ? config.cooldown_days : 30);
                setTimeout(() => closeOverlay(overlay), 1800);
            } else {
                showResult(result, 'error', (data && data.message) || 'Failed to submit. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Feedback';
            }
        })
        .catch(err => {
            showResult(result, 'error', 'Network error. Please check your connection.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Feedback';
        });
    }

    function showResult(el, kind, msg) {
        el.style.display = 'block';
        el.className = 'ml-fb-result ml-fb-result-' + kind;
        el.textContent = msg;
    }

    // ─── Expose manual trigger for footer "Leave feedback" link ──────────
    window.openMotorLinkFeedback = function () {
        // Allow manual trigger to bypass cooldown
        if (!config) {
            fetch(`${API_BASE}?action=get_feedback_config`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => { if (d.success && d.enabled) { config = d; shown = false; openModal(); } });
        } else {
            shown = false;
            openModal();
        }
    };
})();

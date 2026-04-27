/**
 * reviews.js — Shared 5-star review rendering + submission module.
 * Depends on: CONFIG (config.js) only — fully self-contained fetch helper included.
 */

/* ------------------------------------------------------------------ */
/* Private fetch helper — self-contained, no external dependency       */
/* ------------------------------------------------------------------ */

async function _rvFetch(url, options = {}) {
    const defaults = { credentials: 'include' };
    const merged   = Object.assign({}, defaults, options);
    const resp = await fetch(url, merged);
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const ct = resp.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('Non-JSON response');
    return resp.json();
}

/* ------------------------------------------------------------------ */
/* Helpers                                                              */
/* ------------------------------------------------------------------ */

function rvStars(rating, interactive = false, name = 'rating') {
    if (!interactive) {
        let html = '<span class="rv-stars" aria-label="' + rating + ' out of 5 stars">';
        for (let i = 1; i <= 5; i++) {
            const cls = i <= rating ? 'fas fa-star rv-star filled' : (i - 0.5 <= rating ? 'fas fa-star-half-alt rv-star half' : 'far fa-star rv-star empty');
            html += `<i class="${cls}"></i>`;
        }
        html += '</span>';
        return html;
    }

    // Interactive star picker
    let html = `<div class="rv-star-picker" role="radiogroup" aria-label="Select rating">`;
    for (let i = 1; i <= 5; i++) {
        const checked = Number(rating) === i ? ' checked' : '';
        html += `<input type="radio" class="rv-star-input" id="rv-star-${name}-${i}" name="${name}" value="${i}" required${checked}>
                 <label class="rv-star-label" for="rv-star-${name}-${i}" title="${i} star${i > 1 ? 's' : ''}">
                     <i class="fas fa-star"></i>
                 </label>`;
    }
    html += `</div>`;
    return html;
}

function rvRatingLabel(avg) {
    if (avg >= 4.5) return 'Excellent';
    if (avg >= 3.5) return 'Very Good';
    if (avg >= 2.5) return 'Good';
    if (avg >= 1.5) return 'Fair';
    return 'Poor';
}

function rvTimeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)   return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short' });
}

/* ------------------------------------------------------------------ */
/* Render full reviews section into a container element                */
/* ------------------------------------------------------------------ */

async function rvRenderSection(container, businessType, businessId, businessName) {
    if (!container) return;

    container.innerHTML = `<div class="rv-loading"><i class="fas fa-spinner fa-spin"></i> Loading reviews…</div>`;

    let data;
    try {
        data = await _rvFetch(`${CONFIG.API_URL}?action=get_reviews&business_type=${businessType}&business_id=${businessId}`);
    } catch (e) {
        container.innerHTML = `<p class="rv-error">Could not load reviews.</p>`;
        return;
    }

    if (!data.success) {
        container.innerHTML = `<p class="rv-error">Could not load reviews.</p>`;
        return;
    }

    const { reviews, aggregate } = data;

    // Check if current user can submit
    let authUser = null;
    try {
        const authData = await _rvFetch(`${CONFIG.API_URL}?action=check_auth`);
        if (authData.authenticated) authUser = authData.user;
    } catch (_) {}

    let existingReview = null;
    if (authUser) {
        try {
            const chk = await _rvFetch(`${CONFIG.API_URL}?action=check_user_review&business_type=${businessType}&business_id=${businessId}`);
            existingReview = chk.existing_review || null;
        } catch (_) {}
    }

    container.innerHTML = rvBuildHTML(reviews, aggregate, businessType, businessId, businessName, authUser, existingReview);
    rvBindEvents(container, businessType, businessId, businessName);
}

/* ------------------------------------------------------------------ */
/* Build HTML                                                           */
/* ------------------------------------------------------------------ */

function rvBuildHTML(reviews, aggregate, businessType, businessId, businessName, authUser, existingReview) {
    const { total, average, distribution } = aggregate;

    // --- Summary bar ---
    let summaryHTML = '';
    if (total > 0) {
        const pct = (v) => total > 0 ? Math.round((v / total) * 100) : 0;
        summaryHTML = `
        <div class="rv-summary">
            <div class="rv-score-block">
                <div class="rv-score-big">${average.toFixed(1)}</div>
                <div class="rv-score-stars">${rvStars(Math.round(average * 2) / 2)}</div>
                <div class="rv-score-label">${rvRatingLabel(average)}</div>
                <div class="rv-score-count">${total} review${total !== 1 ? 's' : ''}</div>
            </div>
            <div class="rv-dist-bars">
                ${[5,4,3,2,1].map(star => `
                    <div class="rv-dist-row">
                        <span class="rv-dist-label">${star} <i class="fas fa-star"></i></span>
                        <div class="rv-dist-track"><div class="rv-dist-fill" style="width:${pct(distribution[star])}%"></div></div>
                        <span class="rv-dist-count">${distribution[star]}</span>
                    </div>
                `).join('')}
            </div>
        </div>`;
    } else {
        summaryHTML = `<div class="rv-no-reviews"><i class="far fa-comment-dots"></i><p>No reviews yet. Be the first to leave one!</p></div>`;
    }

    // --- Write review form ---
    let formHTML = '';
    if (authUser) {
        const hasExisting = !!existingReview;
        formHTML = `
        <div class="rv-form-wrap">
            <h4 class="rv-form-title">${hasExisting ? 'Update Your Review' : 'Write a Review'}</h4>
            <form class="rv-form" data-business-type="${businessType}" data-business-id="${businessId}">
                <div class="rv-form-stars">
                    <label>Your rating</label>
                    ${rvStars(hasExisting ? existingReview.rating : 0, true, `rv_${businessType}_${businessId}`)}
                    ${hasExisting ? `<input type="hidden" name="${`rv_${businessType}_${businessId}`}" value="${existingReview.rating}" id="rv-hidden-rating-${businessType}-${businessId}">` : ''}
                </div>
                <textarea class="rv-textarea" name="review_text" placeholder="Share your experience (optional, max 2000 characters)…" maxlength="2000">${hasExisting && existingReview.review_text ? rvEscapeAttr(existingReview.review_text) : ''}</textarea>
                <div class="rv-form-actions">
                    <button type="submit" class="rv-submit-btn">
                        <i class="fas fa-paper-plane"></i> ${hasExisting ? 'Update Review' : 'Submit Review'}
                    </button>
                    <span class="rv-form-status"></span>
                </div>
            </form>
        </div>`;
    } else {
        formHTML = `
        <div class="rv-login-prompt">
            <i class="fas fa-lock"></i>
            <span><a href="login.html">Log in</a> to leave a review for ${rvEscapeHtml(businessName)}.</span>
        </div>`;
    }

    // --- Review list ---
    let listHTML = '';
    if (reviews.length > 0) {
        listHTML = `<div class="rv-list">` + reviews.map(r => `
            <div class="rv-item">
                <div class="rv-item-header">
                    <span class="rv-reviewer-avatar"><i class="fas fa-user-circle"></i></span>
                    <span class="rv-reviewer-name">${rvEscapeHtml(r.reviewer_name || 'Anonymous')}</span>
                    <span class="rv-item-stars">${rvStars(r.rating)}</span>
                    <span class="rv-item-time">${rvTimeAgo(r.created_at)}</span>
                </div>
                ${r.review_text ? `<p class="rv-item-text">${rvEscapeHtml(r.review_text)}</p>` : ''}
            </div>
        `).join('') + `</div>`;
    }

    return `
        <div class="rv-section-inner">
            <h3 class="rv-section-title"><i class="fas fa-star"></i> Reviews & Ratings</h3>
            ${summaryHTML}
            ${formHTML}
            ${listHTML}
        </div>`;
}

/* ------------------------------------------------------------------ */
/* Bind form events                                                     */
/* ------------------------------------------------------------------ */

function rvBindEvents(container, businessType, businessId, businessName) {
    const form = container.querySelector('.rv-form');
    if (!form) return;

    // Star picker interaction
    const picker = form.querySelector('.rv-star-picker');
    if (picker) {
        picker.addEventListener('change', () => {
            const selected = parseInt(picker.querySelector('input:checked')?.value || 0);
            picker.querySelectorAll('.rv-star-label').forEach((lbl, idx) => {
                lbl.classList.toggle('active', idx < selected);
            });
        });
        // Set initial state for existing review
        const existing = picker.querySelector('input:checked');
        if (existing) existing.dispatchEvent(new Event('change', { bubbles: true }));
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const status = form.querySelector('.rv-form-status');
        const btn = form.querySelector('.rv-submit-btn');

        const ratingInput = form.querySelector('input[name^="rv_"]:checked') ||
                            form.querySelector('input[id^="rv-hidden-rating-"]');
        const rating = ratingInput ? parseInt(ratingInput.value) : 0;
        const reviewText = form.querySelector('.rv-textarea')?.value?.trim() || '';

        if (rating < 1 || rating > 5) {
            status.textContent = 'Please select a star rating.';
            status.className = 'rv-form-status error';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
        status.textContent = '';
        status.className = 'rv-form-status';

        try {
            const resp = await fetch(CONFIG.API_URL + '?action=submit_review', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ business_type: businessType, business_id: businessId, rating, review_text: reviewText })
            });
            const data = await resp.json();

            if (data.success) {
                status.textContent = '✓ Review saved. Refreshing…';
                status.className = 'rv-form-status success';
                // Reload the section
                const section = document.getElementById(`rv-section-${businessType}-${businessId}`);
                if (section) await rvRenderSection(section, businessType, businessId, businessName);
            } else {
                status.textContent = data.message || 'Failed to submit. Please try again.';
                status.className = 'rv-form-status error';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Review';
            }
        } catch (err) {
            status.textContent = 'Network error. Please try again.';
            status.className = 'rv-form-status error';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Review';
        }
    });
}

/* ------------------------------------------------------------------ */
/* Security helpers                                                     */
/* ------------------------------------------------------------------ */

function rvEscapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function rvEscapeAttr(str) {
    return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/* ------------------------------------------------------------------ */
/* Export on window for inline script use                              */
/* ------------------------------------------------------------------ */
window.rvRenderSection = rvRenderSection;
window.rvStars = rvStars;

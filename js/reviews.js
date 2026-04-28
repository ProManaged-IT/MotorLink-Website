/**
 * reviews.js - shared business review renderer and submit form.
 * Supports dealers, garages, and car hire businesses.
 */

async function _rvFetch(url, options = {}) {
    const response = await fetch(url, Object.assign({ credentials: 'include' }, options));
    const contentType = response.headers.get('content-type') || '';
    const data = contentType.includes('application/json') ? await response.json() : null;

    if (!response.ok) {
        const message = data?.message || `Request failed with HTTP ${response.status}`;
        const error = new Error(message);
        error.status = response.status;
        error.data = data;
        throw error;
    }

    if (!data) {
        throw new Error('The server returned an unexpected response.');
    }

    return data;
}

function rvEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function rvStars(rating, interactive = false, name = 'rating') {
    const value = Number(rating || 0);

    if (!interactive) {
        let html = `<span class="rv-stars" aria-label="${value} out of 5 stars">`;
        for (let index = 1; index <= 5; index++) {
            const icon = index <= value ? 'fas fa-star rv-star filled'
                : (index - 0.5 <= value ? 'fas fa-star-half-alt rv-star half' : 'far fa-star rv-star empty');
            html += `<i class="${icon}"></i>`;
        }
        return html + '</span>';
    }

    let html = '<div class="rv-star-picker" role="radiogroup" aria-label="Select rating">';
    for (let index = 1; index <= 5; index++) {
        const checked = Number(value) === index ? ' checked' : '';
        html += `
            <input type="radio" class="rv-star-input" id="rv-star-${name}-${index}" name="${name}" value="${index}" required${checked}>
            <label class="rv-star-label" for="rv-star-${name}-${index}" title="${index} star${index > 1 ? 's' : ''}">
                <i class="fas fa-star"></i>
            </label>`;
    }
    return html + '</div>';
}

function rvRatingLabel(avg) {
    if (avg >= 4.5) return 'Excellent';
    if (avg >= 3.5) return 'Very good';
    if (avg >= 2.5) return 'Good';
    if (avg >= 1.5) return 'Fair';
    return 'Needs attention';
}

function rvTimeAgo(dateStr) {
    const timestamp = Date.parse(dateStr || '');
    if (!timestamp) return '';

    const diff = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)}d ago`;
    return new Date(timestamp).toLocaleDateString(undefined, { year: 'numeric', month: 'short' });
}

function rvNormalizeAggregate(aggregate = {}) {
    const distribution = aggregate.distribution || {};
    return {
        total: Number(aggregate.total || 0),
        average: Number(aggregate.average || 0),
        distribution: {
            1: Number(distribution[1] ?? distribution['1'] ?? 0),
            2: Number(distribution[2] ?? distribution['2'] ?? 0),
            3: Number(distribution[3] ?? distribution['3'] ?? 0),
            4: Number(distribution[4] ?? distribution['4'] ?? 0),
            5: Number(distribution[5] ?? distribution['5'] ?? 0)
        }
    };
}

async function rvRenderSection(container, businessType, businessId, businessName, options = {}) {
    if (!container) return;

    container.dataset.businessType = businessType;
    container.dataset.businessId = businessId;
    container.dataset.businessName = businessName || '';
    container.innerHTML = '<div class="rv-loading"><i class="fas fa-spinner fa-spin"></i><span>Loading reviews</span></div>';

    let data;
    try {
        data = await _rvFetch(`${CONFIG.API_URL}?action=get_reviews&business_type=${encodeURIComponent(businessType)}&business_id=${encodeURIComponent(businessId)}`);
    } catch (error) {
        container.innerHTML = rvBuildError(error.message || 'Could not load reviews.');
        return;
    }

    if (!data.success) {
        container.innerHTML = rvBuildError(data.message || 'Could not load reviews.');
        return;
    }

    if (data.reviews_enabled === false && !options.forceVisible) {
        container.innerHTML = '<div class="rv-disabled"><i class="fas fa-eye-slash"></i><span>Reviews are not shown for this business right now.</span></div>';
        return;
    }

    let authUser = null;
    try {
        const authData = await _rvFetch(`${CONFIG.API_URL}?action=check_auth`);
        authUser = authData.authenticated ? authData.user : null;
    } catch (_) {}

    let existingReview = null;
    if (authUser) {
        try {
            const checkData = await _rvFetch(`${CONFIG.API_URL}?action=check_user_review&business_type=${encodeURIComponent(businessType)}&business_id=${encodeURIComponent(businessId)}`);
            existingReview = checkData.existing_review || null;
        } catch (_) {}
    }

    container.innerHTML = rvBuildHTML(data.reviews || [], rvNormalizeAggregate(data.aggregate), businessType, businessId, businessName, authUser, existingReview);
    rvBindEvents(container, businessType, businessId, businessName);
}

function rvBuildError(message) {
    return `
        <div class="rv-error">
            <i class="fas fa-circle-exclamation"></i>
            <div>
                <strong>Reviews could not load</strong>
                <p>${rvEscapeHtml(message)}</p>
            </div>
        </div>`;
}

function rvBuildHTML(reviews, aggregate, businessType, businessId, businessName, authUser, existingReview) {
    const total = aggregate.total;
    const average = aggregate.average;
    const distribution = aggregate.distribution;
    const reviewName = rvEscapeHtml(businessName || 'this business');
    const formTitle = existingReview ? 'Update your review' : 'Write a review';

    const distributionRows = [5, 4, 3, 2, 1].map(star => {
        const count = distribution[star] || 0;
        const pct = total > 0 ? Math.round((count / total) * 100) : 0;
        return `
            <div class="rv-dist-row">
                <span>${star}<i class="fas fa-star"></i></span>
                <div class="rv-dist-track"><div class="rv-dist-fill" style="width:${pct}%"></div></div>
                <b>${count}</b>
            </div>`;
    }).join('');

    const formHtml = authUser ? `
        <form class="rv-form" data-business-type="${businessType}" data-business-id="${businessId}">
            <div class="rv-form-heading">
                <h4>${formTitle}</h4>
                <span>Your feedback helps other drivers choose with confidence.</span>
            </div>
            <div class="rv-form-stars">
                ${rvStars(existingReview ? existingReview.rating : 0, true, `rv_${businessType}_${businessId}`)}
            </div>
            <textarea class="rv-textarea" name="review_text" placeholder="Share what stood out: service, communication, vehicle quality, pricing..." maxlength="2000">${existingReview?.review_text ? rvEscapeHtml(existingReview.review_text) : ''}</textarea>
            <div class="rv-form-actions">
                <button type="submit" class="rv-submit-btn" data-default-label="${existingReview ? 'Update Review' : 'Submit Review'}">
                    <i class="fas fa-paper-plane"></i> ${existingReview ? 'Update Review' : 'Submit Review'}
                </button>
                <span class="rv-form-status" aria-live="polite"></span>
            </div>
        </form>` : `
        <div class="rv-login-prompt">
            <i class="fas fa-lock"></i>
            <span><a href="login.html">Log in</a> to leave a review for ${reviewName}.</span>
        </div>`;

    const listHtml = reviews.length ? `
        <div class="rv-list">
            ${reviews.map(review => `
                <article class="rv-item">
                    <div class="rv-reviewer-avatar">${rvEscapeHtml((review.reviewer_name || 'A').trim().charAt(0) || 'A')}</div>
                    <div class="rv-item-content">
                        <div class="rv-item-topline">
                            <strong>${rvEscapeHtml(review.reviewer_name || 'Anonymous')}</strong>
                            <span>${rvTimeAgo(review.created_at)}</span>
                        </div>
                        <div class="rv-item-stars">${rvStars(Number(review.rating || 0))}</div>
                        ${review.review_text ? `<p>${rvEscapeHtml(review.review_text)}</p>` : '<p class="rv-muted">No written comment.</p>'}
                    </div>
                </article>`).join('')}
        </div>` : `
        <div class="rv-empty">
            <i class="far fa-comment-dots"></i>
            <strong>No reviews yet</strong>
            <span>Be the first to share your experience.</span>
        </div>`;

    return `
        <div class="rv-section-inner" id="rv-section-${businessType}-${businessId}">
            <div class="rv-header">
                <div>
                    <h3><i class="fas fa-star"></i> Customer Reviews</h3>
                    <p>Ratings and feedback from MotorLink users.</p>
                </div>
                <div class="rv-score-pill">
                    <strong>${total ? average.toFixed(1) : 'New'}</strong>
                    <span>${total ? `${rvRatingLabel(average)} - ${total} review${total === 1 ? '' : 's'}` : 'No ratings yet'}</span>
                </div>
            </div>
            <div class="rv-layout">
                <aside class="rv-summary-card">
                    <div class="rv-score-big">${total ? average.toFixed(1) : '0.0'}</div>
                    <div>${rvStars(Math.round(average * 2) / 2)}</div>
                    <p>${total} review${total === 1 ? '' : 's'}</p>
                    <div class="rv-dist-bars">${distributionRows}</div>
                </aside>
                <div class="rv-main">
                    ${formHtml}
                    ${listHtml}
                </div>
            </div>
        </div>`;
}

function rvBindEvents(container, businessType, businessId, businessName) {
    const form = container.querySelector('.rv-form');
    if (!form) return;

    const picker = form.querySelector('.rv-star-picker');
    const syncStars = () => {
        const selected = Number(picker?.querySelector('input:checked')?.value || 0);
        picker?.querySelectorAll('.rv-star-label').forEach((label, index) => {
            label.classList.toggle('active', index < selected);
        });
    };

    if (picker) {
        picker.addEventListener('change', syncStars);
        syncStars();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const status = form.querySelector('.rv-form-status');
        const button = form.querySelector('.rv-submit-btn');
        const defaultLabel = button?.dataset.defaultLabel || 'Submit Review';
        const ratingInput = form.querySelector('input[name^="rv_"]:checked');
        const rating = ratingInput ? Number(ratingInput.value) : 0;
        const reviewText = form.querySelector('.rv-textarea')?.value?.trim() || '';

        if (rating < 1 || rating > 5) {
            status.textContent = 'Select a star rating first.';
            status.className = 'rv-form-status error';
            return;
        }

        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving';
        status.textContent = '';
        status.className = 'rv-form-status';

        try {
            const data = await _rvFetch(`${CONFIG.API_URL}?action=submit_review`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ business_type: businessType, business_id: Number(businessId), rating, review_text: reviewText })
            });

            if (!data.success) {
                throw new Error(data.message || 'Failed to save review.');
            }

            status.textContent = 'Review saved. Refreshing...';
            status.className = 'rv-form-status success';
            window.dispatchEvent(new CustomEvent('motorlink:reviews-updated', { detail: { businessType, businessId } }));
            await rvRenderSection(container, businessType, businessId, businessName);
        } catch (error) {
            const needsLogin = error.status === 401 || (error.status === 403 && /log|auth/i.test(error.message || ''));
            status.innerHTML = needsLogin ? '<a href="login.html">Log in</a> to submit a review.' : rvEscapeHtml(error.message || 'Network error. Please try again.');
            status.className = 'rv-form-status error';
            button.disabled = false;
            button.innerHTML = `<i class="fas fa-paper-plane"></i> ${rvEscapeHtml(defaultLabel)}`;
        }
    });
}

window.rvRenderSection = rvRenderSection;
window.rvStars = rvStars;

/**
 * Business owner review management for dealer, garage, and car hire dashboards.
 */
(function() {
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getConfig(root) {
        return {
            businessType: root.dataset.businessType,
            requestKey: root.dataset.requestKey || 'business_id',
            storageKey: root.dataset.storageKey || '',
            selectId: root.dataset.selectId || ''
        };
    }

    function getSelectedBusinessId(config, root) {
        const select = config.selectId ? document.getElementById(config.selectId) : null;
        return select?.value || (config.storageKey ? localStorage.getItem(config.storageKey) : '') || root.dataset.selectedBusinessId || '';
    }

    function buildQuery(config, root) {
        const params = new URLSearchParams({
            action: 'get_my_business_reviews',
            business_type: config.businessType
        });
        const selectedId = getSelectedBusinessId(config, root);
        if (selectedId) params.set(config.requestKey, selectedId);
        return params.toString();
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, Object.assign({ credentials: 'include' }, options));
        const contentType = response.headers.get('content-type') || '';
        const data = contentType.includes('application/json') ? await response.json() : null;
        if (!response.ok || !data) {
            throw new Error(data?.message || `HTTP ${response.status}`);
        }
        return data;
    }

    function starMarkup(rating) {
        const value = Number(rating || 0);
        let html = '<span class="rv-stars">';
        for (let index = 1; index <= 5; index++) {
            const icon = index <= value ? 'fas fa-star rv-star filled' : 'far fa-star rv-star empty';
            html += `<i class="${icon}"></i>`;
        }
        return html + '</span>';
    }

    function formatDate(value) {
        const timestamp = Date.parse(value || '');
        if (!timestamp) return '';
        return new Date(timestamp).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderRoot(root, payload) {
        const aggregate = payload.aggregate || {};
        const reviews = Array.isArray(payload.reviews) ? payload.reviews : [];
        const showReviews = payload.settings?.show_reviews !== false;
        const business = payload.business || {};
        root.dataset.selectedBusinessId = business.id || root.dataset.selectedBusinessId || '';

        root.innerHTML = `
            <div class="owner-review-toolbar">
                <div>
                    <h3>${escapeHtml(business.name || 'Business reviews')}</h3>
                    <p>Control whether reviews show publicly and monitor every customer review for this business.</p>
                </div>
                <button type="button" class="owner-review-toggle ${showReviews ? 'is-on' : ''}" data-review-toggle aria-pressed="${showReviews ? 'true' : 'false'}">
                    <span class="toggle-dot"><i class="fas ${showReviews ? 'fa-eye' : 'fa-eye-slash'}"></i></span>
                    <span>${showReviews ? 'Public reviews on' : 'Public reviews off'}</span>
                </button>
            </div>
            <div class="owner-review-summary">
                <div class="owner-review-metric"><span>Average</span><strong>${aggregate.total ? Number(aggregate.average || 0).toFixed(1) : '0.0'}</strong></div>
                <div class="owner-review-metric"><span>Total</span><strong>${Number(aggregate.total || 0)}</strong></div>
                <div class="owner-review-metric"><span>Active</span><strong>${Number(aggregate.active || 0)}</strong></div>
                <div class="owner-review-metric"><span>Hidden</span><strong>${Number(aggregate.hidden || 0)}</strong></div>
            </div>
            <div class="owner-review-list">
                ${reviews.length ? reviews.map(review => `
                    <article class="owner-review-card">
                        <div>
                            <div class="owner-review-card-header">
                                <strong>${escapeHtml(review.reviewer_name || 'Anonymous')}</strong>
                                ${starMarkup(review.rating)}
                                <span class="owner-review-date">${formatDate(review.created_at)}</span>
                            </div>
                            <p>${review.review_text ? escapeHtml(review.review_text) : '<span class="rv-muted">No written comment.</span>'}</p>
                        </div>
                        <span class="owner-review-status ${escapeHtml(review.status || 'active')}">${escapeHtml(review.status || 'active')}</span>
                    </article>`).join('') : `
                    <div class="owner-review-empty">
                        <i class="far fa-comment-dots"></i>
                        <span>No reviews yet for this business.</span>
                    </div>`}
            </div>`;
    }

    async function loadOwnerReviews(root) {
        if (!root) return;
        const config = getConfig(root);
        if (!config.businessType) return;

        root.innerHTML = '<div class="owner-review-empty"><i class="fas fa-spinner fa-spin"></i><span>Loading reviews...</span></div>';
        try {
            const data = await fetchJson(`${CONFIG.API_URL}?${buildQuery(config, root)}`);
            if (!data.success) throw new Error(data.message || 'Failed to load reviews.');
            renderRoot(root, data);
        } catch (error) {
            root.innerHTML = `<div class="owner-review-error"><i class="fas fa-circle-exclamation"></i><span>${escapeHtml(error.message || 'Failed to load reviews.')}</span></div>`;
        }
    }

    async function updateVisibility(root, showReviews) {
        const config = getConfig(root);
        const selectedId = getSelectedBusinessId(config, root);
        const payload = {
            business_type: config.businessType,
            business_id: Number(selectedId || root.dataset.selectedBusinessId || 0),
            show_reviews: showReviews ? 1 : 0
        };
        if (selectedId) payload[config.requestKey] = Number(selectedId);

        await fetchJson(`${CONFIG.API_URL}?action=update_business_review_settings`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        await loadOwnerReviews(root);
    }

    function init() {
        const roots = Array.from(document.querySelectorAll('[data-owner-reviews]'));
        roots.forEach(loadOwnerReviews);

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-review-toggle]');
            if (!button) return;
            const root = button.closest('[data-owner-reviews]');
            if (!root || button.disabled) return;

            button.disabled = true;
            const nextState = button.getAttribute('aria-pressed') !== 'true';
            try {
                await updateVisibility(root, nextState);
            } catch (error) {
                root.insertAdjacentHTML('afterbegin', `<div class="owner-review-error"><i class="fas fa-circle-exclamation"></i><span>${escapeHtml(error.message || 'Failed to update review visibility.')}</span></div>`);
                button.disabled = false;
            }
        });

        document.addEventListener('change', (event) => {
            const changedId = event.target?.id || '';
            roots
                .filter(root => root.dataset.selectId === changedId)
                .forEach(root => setTimeout(() => loadOwnerReviews(root), 0));
        });

        window.addEventListener('motorlink:reviews-updated', () => {
            roots.forEach(loadOwnerReviews);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.MotorLinkOwnerReviews = { refreshAll: () => document.querySelectorAll('[data-owner-reviews]').forEach(loadOwnerReviews) };
})();

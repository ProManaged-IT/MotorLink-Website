/**
 * Favorites Page - MotorLink
 * Displays and manages the user's saved cars.
 */

class FavoritesManager {
    constructor() {
        this.savedListings = [];
        this.listings = [];
        this.filteredListings = [];
        this.isLoggedIn = false;
        this.searchTerm = '';
        this.sortValue = 'newest_saved';
        this.controlsBound = false;
        this.init();
    }

    async init() {
        this.bindUIEvents();
        await this.checkAuth();
        await this.loadFavorites();
        await this.loadRecommendations();
    }

    bindUIEvents() {
        if (this.controlsBound) return;

        const searchInput = document.getElementById('favoritesSearch');
        if (searchInput) {
            searchInput.addEventListener('input', (event) => {
                this.searchTerm = String(event.target.value || '').trim();
                this.applyFiltersAndRender();
            });
        }

        const sortSelect = document.getElementById('favoritesSort');
        if (sortSelect) {
            sortSelect.addEventListener('change', (event) => {
                this.sortValue = String(event.target.value || 'newest_saved');
                this.applyFiltersAndRender();
            });
        }

        const clearAllBtn = document.getElementById('clearAllFavoritesBtn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', () => {
                this.clearAllFavorites();
            });
        }

        this.controlsBound = true;
    }

    setLoadingState(isLoading) {
        const loadingState = document.getElementById('loadingState');
        if (!loadingState) return;

        loadingState.classList.toggle('hidden', !isLoading);
    }

    async checkAuth() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                credentials: 'include'
            });
            const data = await response.json();
            this.isLoggedIn = data.success && data.authenticated;
        } catch (error) {
            this.isLoggedIn = localStorage.getItem('motorlink_authenticated') === 'true';
        }
    }

    loadSavedIds() {
        const saved = JSON.parse(localStorage.getItem('motorlink_favorites') || '[]');
        this.savedListings = Array.isArray(saved)
            ? saved.map((id) => parseInt(id, 10)).filter(Number.isFinite)
            : [];
    }

    decorateListings(listings) {
        if (!Array.isArray(listings)) return [];

        return listings.map((listing, index) => ({
            ...listing,
            _savedOrder: index
        }));
    }

    async loadFavorites() {
        this.setLoadingState(true);
        this.hideEmptyState();

        if (this.isLoggedIn) {
            try {
                const response = await fetch(`${CONFIG.API_URL}?action=get_favorites`, {
                    credentials: 'include'
                });
                const data = await response.json();

                if (data.success && Array.isArray(data.listings) && data.listings.length > 0) {
                    this.listings = this.decorateListings(data.listings);
                    this.savedListings = this.listings
                        .map((listing) => parseInt(listing.id, 10))
                        .filter(Number.isFinite);
                    localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));

                    this.setLoadingState(false);
                    this.applyFiltersAndRender();
                    return;
                }
            } catch (error) {
                // Fallback to local cache below.
            }
        }

        this.loadSavedIds();

        if (this.savedListings.length === 0) {
            this.listings = [];
            this.filteredListings = [];
            this.setLoadingState(false);
            this.showEmptyState();
            return;
        }

        await this.loadListingsDetails();
    }

    async loadListingsDetails() {
        this.setLoadingState(true);

        try {
            const promises = this.savedListings.map((id) =>
                fetch(`${CONFIG.API_URL}?action=listing&id=${id}`)
                    .then((res) => res.json())
                    .catch(() => null)
            );

            const results = await Promise.all(promises);
            const loadedListings = results
                .filter((result) => result && result.success && result.listing)
                .map((result) => result.listing);

            this.listings = this.decorateListings(loadedListings);
            this.setLoadingState(false);

            if (this.listings.length === 0) {
                localStorage.setItem('motorlink_favorites', '[]');
                this.savedListings = [];
                this.showEmptyState();
                return;
            }

            this.applyFiltersAndRender();
        } catch (error) {
            const loadingState = document.getElementById('loadingState');
            if (loadingState) {
                loadingState.classList.remove('hidden');
                loadingState.innerHTML = `
                    <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc2626; margin-bottom: 14px;"></i>
                    <h3 style="margin: 0 0 8px; color: #173b2f;">Could not load saved cars</h3>
                    <p style="margin: 0 0 16px; color: #4b5f58;">Please check your connection and try again.</p>
                    <button class="btn btn-primary" onclick="location.reload()">Retry</button>
                `;
            }
        }
    }

    matchesSearch(listing, query) {
        if (!query) return true;

        const searchableFields = [
            listing.title,
            listing.make_name,
            listing.model_name,
            listing.location_name,
            listing.fuel_type,
            listing.transmission,
            listing.condition_type,
            listing.year,
            listing.price
        ]
            .filter((value) => value !== null && value !== undefined)
            .map((value) => String(value).toLowerCase());

        return searchableFields.some((value) => value.includes(query));
    }

    sortListings(listings, sortValue) {
        const sorted = [...listings];

        switch (sortValue) {
            case 'price_low':
                sorted.sort((a, b) => (parseFloat(a.price) || 0) - (parseFloat(b.price) || 0));
                break;
            case 'price_high':
                sorted.sort((a, b) => (parseFloat(b.price) || 0) - (parseFloat(a.price) || 0));
                break;
            case 'year_new':
                sorted.sort((a, b) => (parseInt(b.year, 10) || 0) - (parseInt(a.year, 10) || 0));
                break;
            case 'year_old':
                sorted.sort((a, b) => (parseInt(a.year, 10) || 0) - (parseInt(b.year, 10) || 0));
                break;
            case 'title_az':
                sorted.sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
                break;
            case 'newest_saved':
            default:
                sorted.sort((a, b) => (a._savedOrder || 0) - (b._savedOrder || 0));
                break;
        }

        return sorted;
    }

    applyFiltersAndRender() {
        const totalListings = this.listings.length;
        this.toggleClearAllButton(totalListings > 0);

        if (totalListings === 0) {
            this.filteredListings = [];
            this.updateStats();
            this.updateSummary();
            this.showEmptyState();
            return;
        }

        const query = String(this.searchTerm || '').toLowerCase();
        const filtered = this.sortListings(
            this.listings.filter((listing) => this.matchesSearch(listing, query)),
            this.sortValue
        );

        this.filteredListings = filtered;
        this.updateStats();
        this.updateSummary();
        this.hideEmptyState();

        if (filtered.length === 0) {
            this.renderNoResultsState();
            return;
        }

        this.renderListings(filtered);
    }

    resolveListingImageUrl(listing) {
        if (listing.featured_image) {
            return `${CONFIG.BASE_URL}uploads/${listing.featured_image}`;
        }
        if (listing.featured_image_id) {
            return `${CONFIG.API_URL}?action=image&id=${listing.featured_image_id}`;
        }
        if (Array.isArray(listing.images) && listing.images.length > 0) {
            const image = listing.images[0];
            if (image.id) {
                return `${CONFIG.API_URL}?action=image&id=${image.id}`;
            }
            if (image.filename) {
                return `${CONFIG.BASE_URL}uploads/${image.filename}`;
            }
        }
        return '';
    }

    sanitizePhoneNumber(phone) {
        return String(phone || '').replace(/[^0-9+]/g, '');
    }

    buildCardHTML(listing) {
        const listingId = parseInt(listing.id, 10);
        if (!Number.isFinite(listingId)) return '';

        const isSold = String(listing.status || '').toLowerCase() === 'sold';
        const imageUrl = this.resolveListingImageUrl(listing);
        const title = this.escapeHtml(listing.title || 'Untitled Listing');
        const yearText = listing.year || 'N/A';
        const mileageText = listing.mileage ? `${this.formatNumber(listing.mileage)} km` : 'Mileage N/A';
        const locationText = this.escapeHtml(listing.location_name || 'Location N/A');
        const fuelText = this.escapeHtml((listing.fuel_type || 'Fuel N/A').toString().toUpperCase());
        const priceText = this.formatNumber(listing.price);
        const safePhone = this.sanitizePhoneNumber(listing.contact_phone || listing.seller_phone || listing.phone);

        return `
            <article class="car-card ${isSold ? 'is-sold' : ''}" data-id="${listingId}" tabindex="0" role="article" aria-label="${title}">
                <div class="car-image">
                    ${imageUrl
                        ? `<img src="${imageUrl}" alt="${title}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                           <div class="placeholder" style="display: none;"><i class="fas fa-car"></i></div>`
                        : '<div class="placeholder"><i class="fas fa-car"></i></div>'}

                    ${isSold ? `
                        <div class="sold-badge" title="This car is marked as sold">
                            <i class="fas fa-tag"></i> SOLD
                        </div>
                    ` : ''}

                    <button class="remove-btn" data-action="remove" data-id="${listingId}" title="Remove from saved" aria-label="Remove ${title} from saved cars">
                        <i class="fas fa-heart"></i>
                    </button>
                </div>

                <div class="car-info">
                    <h3 class="car-title">${title}</h3>
                    <div class="car-price"><span class="currency">${CONFIG.CURRENCY_CODE || 'MWK'}</span>${priceText}</div>

                    <div class="car-meta">
                        <span><i class="fas fa-calendar"></i> ${yearText}</span>
                        <span><i class="fas fa-tachometer-alt"></i> ${mileageText}</span>
                        <span><i class="fas fa-map-marker-alt"></i> ${locationText}</span>
                        <span><i class="fas fa-gas-pump"></i> ${fuelText}</span>
                    </div>
                </div>

                <div class="car-actions">
                    <a href="car.html?id=${listingId}" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <button class="btn btn-outline-danger" data-action="remove" data-id="${listingId}" title="Remove from saved">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                    ${safePhone
                        ? `<a href="tel:${safePhone}" class="btn btn-outline-primary">
                               <i class="fas fa-phone"></i> Call Seller
                           </a>`
                        : ''}
                </div>
            </article>
        `;
    }

    renderListings(listings) {
        const grid = document.getElementById('favoritesGrid');
        if (!grid) return;

        grid.innerHTML = listings
            .map((listing) => this.buildCardHTML(listing))
            .filter(Boolean)
            .join('');

        this.bindCardInteractions();
    }

    bindCardInteractions() {
        const cards = document.querySelectorAll('.favorites-grid .car-card');

        cards.forEach((card) => {
            if (card.dataset.bound === '1') return;
            card.dataset.bound = '1';

            card.addEventListener('click', (event) => {
                if (event.target.closest('a, button')) return;
                const listingId = parseInt(card.dataset.id || '', 10);
                if (Number.isFinite(listingId)) {
                    this.goToListing(listingId);
                }
            });

            card.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                if (event.target.closest('a, button')) return;

                event.preventDefault();
                const listingId = parseInt(card.dataset.id || '', 10);
                if (Number.isFinite(listingId)) {
                    this.goToListing(listingId);
                }
            });
        });

        const removeButtons = document.querySelectorAll('.favorites-grid [data-action="remove"]');
        removeButtons.forEach((button) => {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';

            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const listingId = parseInt(button.dataset.id || '', 10);
                if (Number.isFinite(listingId)) {
                    this.removeFavorite(listingId);
                }
            });
        });
    }

    goToListing(listingId) {
        window.location.href = `car.html?id=${listingId}`;
    }

    renderNoResultsState() {
        const grid = document.getElementById('favoritesGrid');
        if (!grid) return;

        grid.innerHTML = `
            <div class="no-results-state">
                <i class="fas fa-search"></i>
                <h3>No matches found</h3>
                <p>Try a different keyword or clear your search to see all saved cars.</p>
                <button type="button" class="btn btn-outline-primary btn-sm no-results-reset">
                    <i class="fas fa-rotate-left"></i> Clear Search
                </button>
            </div>
        `;

        const resetBtn = grid.querySelector('.no-results-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.searchTerm = '';
                const searchInput = document.getElementById('favoritesSearch');
                if (searchInput) searchInput.value = '';
                this.applyFiltersAndRender();
            });
        }
    }

    async syncUnsaveWithServer(listingId) {
        if (!this.isLoggedIn) return true;

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=unsave_listing`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ listing_id: listingId })
            });

            const data = await response.json();
            return !!data.success;
        } catch (error) {
            return false;
        }
    }

    async removeFavorite(listingId) {
        const listingIdInt = parseInt(listingId, 10);
        if (!Number.isFinite(listingIdInt)) return;

        this.savedListings = this.savedListings.filter((id) => parseInt(id, 10) !== listingIdInt);
        localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));

        this.listings = this.listings.filter((listing) => parseInt(listing.id, 10) !== listingIdInt);
        this.filteredListings = this.filteredListings.filter((listing) => parseInt(listing.id, 10) !== listingIdInt);

        this.applyFiltersAndRender();

        const synced = await this.syncUnsaveWithServer(listingIdInt);
        if (!synced && this.isLoggedIn) {
            this.showToast('Removed locally. Could not sync with server right now.', 'error');
            return;
        }

        this.showToast('Removed from saved cars', 'info');
    }

    async clearAllFavorites() {
        if (this.listings.length === 0) return;

        const confirmed = confirm('Clear all saved cars? This will remove every item from your favorites list.');
        if (!confirmed) return;

        const idsToRemove = [...this.savedListings];

        localStorage.setItem('motorlink_favorites', '[]');
        this.savedListings = [];
        this.listings = [];
        this.filteredListings = [];
        this.searchTerm = '';

        const searchInput = document.getElementById('favoritesSearch');
        if (searchInput) searchInput.value = '';

        this.applyFiltersAndRender();

        if (this.isLoggedIn && idsToRemove.length > 0) {
            const syncResults = await Promise.allSettled(
                idsToRemove.map((id) => this.syncUnsaveWithServer(id))
            );

            const failedCount = syncResults.filter((result) => {
                return result.status !== 'fulfilled' || result.value !== true;
            }).length;

            if (failedCount > 0) {
                this.showToast('Favorites cleared locally. Some server sync actions failed.', 'error');
                return;
            }
        }

        this.showToast('All saved cars cleared', 'success');
    }

    showEmptyState() {
        this.setLoadingState(false);

        const grid = document.getElementById('favoritesGrid');
        if (grid) {
            grid.innerHTML = '';
        }

        const emptyState = document.getElementById('emptyState');
        if (emptyState) {
            emptyState.classList.remove('hidden');
        }

        this.updateStats();
        this.updateSummary();
        this.toggleClearAllButton(false);
    }

    hideEmptyState() {
        const emptyState = document.getElementById('emptyState');
        if (emptyState) {
            emptyState.classList.add('hidden');
        }
    }

    updateStats() {
        const totalElement = document.getElementById('totalFavorites');
        if (totalElement) {
            totalElement.textContent = this.listings.length;
        }
    }

    updateSummary() {
        const countChip = document.getElementById('favoritesCountChip');
        if (countChip) {
            countChip.innerHTML = `<i class="fas fa-heart"></i> ${this.listings.length} saved`;
        }

        const visibleChip = document.getElementById('favoritesVisibleChip');
        if (visibleChip) {
            visibleChip.innerHTML = `<i class="fas fa-filter"></i> ${this.filteredListings.length} visible`;
        }
    }

    toggleClearAllButton(hasListings) {
        const clearAllBtn = document.getElementById('clearAllFavoritesBtn');
        if (!clearAllBtn) return;
        clearAllBtn.disabled = !hasListings;
    }

    async loadRecommendations() {
        try {
            const recommendationUrl = this.getRecommendationApiUrl();
            const sessionId = this.getOrCreateSessionId();
            const patterns = this.analyzePatterns();

            if (patterns) {
                await fetch(recommendationUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        action: 'store_preferences',
                        session_id: sessionId,
                        preferences: patterns
                    })
                }).catch(() => {});
            }

            let response = await fetch(`${recommendationUrl}?action=get_recommendations&type=personalized&limit=6&session_id=${encodeURIComponent(sessionId)}`, {
                credentials: 'include'
            });

            let data = await response.json();
            let recommendations = (data.success && Array.isArray(data.recommendations)) ? data.recommendations : [];

            if (recommendations.length === 0) {
                response = await fetch(`${recommendationUrl}?action=get_recommendations&type=trending&limit=6&session_id=${encodeURIComponent(sessionId)}`, {
                    credentials: 'include'
                });
                data = await response.json();
                recommendations = (data.success && Array.isArray(data.recommendations)) ? data.recommendations : [];
            }

            const filtered = recommendations
                .filter((recommendation) => !this.savedListings.includes(parseInt(recommendation.id, 10)))
                .slice(0, 3);

            if (filtered.length > 0) {
                this.renderRecommendations(filtered);
            }
        } catch (error) {
            console.error('Failed to load recommendations:', error);
        }
    }

    analyzePatterns() {
        if (this.listings.length < 2) {
            return null;
        }

        const makes = {};
        const priceRanges = [];
        const years = [];
        const fuelTypes = {};
        const transmissions = {};

        this.listings.forEach((listing) => {
            const make = listing.make_name || listing.make;
            if (make) makes[make] = (makes[make] || 0) + 1;

            if (listing.price) priceRanges.push(parseInt(listing.price, 10));
            if (listing.year) years.push(parseInt(listing.year, 10));

            if (listing.fuel_type) fuelTypes[listing.fuel_type] = (fuelTypes[listing.fuel_type] || 0) + 1;
            if (listing.transmission) transmissions[listing.transmission] = (transmissions[listing.transmission] || 0) + 1;
        });

        const mostCommonMake = Object.keys(makes).length > 0
            ? Object.keys(makes).reduce((a, b) => (makes[a] > makes[b] ? a : b))
            : null;

        const avgPrice = priceRanges.length > 0
            ? priceRanges.reduce((a, b) => a + b, 0) / priceRanges.length
            : null;

        const avgYear = years.length > 0
            ? Math.round(years.reduce((a, b) => a + b, 0) / years.length)
            : null;

        return {
            preferredMake: mostCommonMake,
            avgPrice,
            priceMin: avgPrice ? avgPrice * 0.7 : null,
            priceMax: avgPrice ? avgPrice * 1.3 : null,
            avgYear,
            yearMin: avgYear ? avgYear - 3 : null,
            yearMax: avgYear ? avgYear + 2 : null,
            preferredFuel: Object.keys(fuelTypes).length > 0
                ? Object.keys(fuelTypes).reduce((a, b) => (fuelTypes[a] > fuelTypes[b] ? a : b))
                : null,
            preferredTransmission: Object.keys(transmissions).length > 0
                ? Object.keys(transmissions).reduce((a, b) => (transmissions[a] > transmissions[b] ? a : b))
                : null
        };
    }

    getRecommendationApiUrl() {
        if (CONFIG && CONFIG.RECOMMENDATION_API_URL) {
            return CONFIG.RECOMMENDATION_API_URL;
        }

        if (CONFIG && CONFIG.API_URL && CONFIG.API_URL.includes('api.php')) {
            return CONFIG.API_URL.replace('api.php', 'recommendation_engine.php');
        }

        return 'recommendation_engine.php';
    }

    getOrCreateSessionId() {
        let sessionId = localStorage.getItem('motorlink_session_id');
        if (!sessionId) {
            sessionId = 'guest_' + Date.now() + '_' + Math.random().toString(36).substring(2, 11);
            localStorage.setItem('motorlink_session_id', sessionId);
        }
        return sessionId;
    }

    renderRecommendations(recommendations) {
        const section = document.getElementById('recommendationsSection');
        const grid = document.getElementById('recommendationsGrid');

        if (!section || !grid) return;

        grid.innerHTML = recommendations.map((listing) => {
            let imageUrl = '';
            if (listing.featured_image) {
                imageUrl = `${CONFIG.BASE_URL}uploads/${listing.featured_image}`;
            } else if (listing.featured_image_id) {
                imageUrl = `${CONFIG.API_URL}?action=image&id=${listing.featured_image_id}`;
            }

            return `
                <div class="car-card" onclick="window.location.href='car.html?id=${listing.id}'" style="cursor: pointer; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.2s, box-shadow 0.2s;">
                    <div class="car-image" style="position: relative; height: 200px; overflow: hidden;">
                        ${imageUrl
                            ? `<img src="${imageUrl}" alt="${this.escapeHtml(listing.title)}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                               <div class="placeholder" style="display: none; width: 100%; height: 100%; background: #f3f4f6; justify-content: center; align-items: center;"><i class="fas fa-car" style="font-size: 48px; color: #d1d5db;"></i></div>`
                            : '<div class="placeholder" style="width: 100%; height: 100%; background: #f3f4f6; display: flex; justify-content: center; align-items: center;"><i class="fas fa-car" style="font-size: 48px; color: #d1d5db;"></i></div>'}
                        <div style="position: absolute; top: 12px; right: 12px; background: rgba(0, 200, 83, 0.9); color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                            <i class="fas fa-thumbs-up"></i> Recommended
                        </div>
                    </div>
                    <div style="padding: 16px;">
                        <h3 style="font-size: 1rem; font-weight: 700; color: #1f2937; margin: 0 0 8px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${this.escapeHtml(listing.title)}</h3>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #00c853; margin-bottom: 12px;">
                            ${CONFIG.CURRENCY_CODE || 'MWK'} ${this.formatNumber(listing.price)}
                        </div>
                        <div style="display: flex; gap: 12px; font-size: 0.75rem; color: #6b7280; margin-bottom: 12px;">
                            <span><i class="fas fa-calendar"></i> ${listing.year || 'N/A'}</span>
                            ${listing.mileage ? `<span><i class="fas fa-tachometer-alt"></i> ${this.formatNumber(listing.mileage)} km</span>` : ''}
                        </div>
                        <button onclick="event.stopPropagation(); favoritesManager.saveRecommendation(${listing.id})" style="width: 100%; padding: 8px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; color: #374151; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                            <i class="far fa-heart"></i> Save This Car
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        section.style.display = 'block';
    }

    async saveRecommendation(listingId) {
        const listingIdInt = parseInt(listingId, 10);
        if (!Number.isFinite(listingIdInt)) return;

        if (!this.savedListings.includes(listingIdInt)) {
            this.savedListings.push(listingIdInt);
            localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));

            this.showToast('Added to saved cars!', 'success');

            setTimeout(() => {
                window.location.reload();
            }, 800);

            if (this.isLoggedIn) {
                try {
                    await fetch(`${CONFIG.API_URL}?action=save_listing`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ listing_id: listingIdInt })
                    });
                } catch (error) {
                    console.error('Failed to sync recommendation save with server:', error);
                }
            }
        }
    }

    showToast(message, type = 'info') {
        const iconByType = {
            success: 'check-circle',
            error: 'triangle-exclamation',
            info: 'circle-info'
        };

        const colorByType = {
            success: '#0f9f60',
            error: '#c0264d',
            info: '#1d70b8'
        };

        const toast = document.createElement('div');
        toast.className = 'favorites-toast';
        toast.innerHTML = `
            <i class="fas fa-${iconByType[type] || iconByType.info}"></i>
            <span>${this.escapeHtml(message)}</span>
        `;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            max-width: min(360px, calc(100vw - 28px));
            padding: 12px 16px;
            background: ${colorByType[type] || colorByType.info};
            color: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.25);
            animation: favorites-toast-in 0.24s ease;
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'favorites-toast-out 0.24s ease';
            setTimeout(() => {
                if (toast.parentElement) toast.remove();
            }, 240);
        }, 2300);
    }

    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    formatNumber(num) {
        const value = parseFloat(num);
        if (!Number.isFinite(value)) return '0';
        return Math.round(value).toLocaleString();
    }
}

let favoritesManager;
document.addEventListener('DOMContentLoaded', () => {
    favoritesManager = new FavoritesManager();
    window.favoritesManager = favoritesManager;
});

const style = document.createElement('style');
style.textContent = `
    @keyframes favorites-toast-in {
        from { transform: translateX(18px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes favorites-toast-out {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(18px); opacity: 0; }
    }
`;
document.head.appendChild(style);


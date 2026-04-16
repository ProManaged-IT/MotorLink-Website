/**
 * Favorites Page - MotorLink
 * Displays user's saved cars
 */

class FavoritesManager {
    constructor() {
        this.savedListings = [];
        this.listings = [];
        this.isLoggedIn = false;
        this.init();
    }

    async init() {
        await this.checkAuth();
        await this.loadFavorites();
        await this.loadRecommendations();
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
        this.savedListings = JSON.parse(localStorage.getItem('motorlink_favorites') || '[]');
    }

    async loadFavorites() {
        const loadingState = document.getElementById('loadingState');

        // Try to load from server first if logged in
        if (this.isLoggedIn) {
            try {
                const response = await fetch(`${CONFIG.API_URL}?action=get_favorites`, {
                    credentials: 'include'
                });
                const data = await response.json();

                if (data.success && data.listings && data.listings.length > 0) {
                    this.listings = data.listings;

                    // Sync localStorage with server data
                    this.savedListings = this.listings.map(l => parseInt(l.id));
                    localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));

                    loadingState.classList.add('hidden');
                    this.renderListings();
                    return;
                }
            } catch (error) {
            }
        }

        // Fallback to localStorage
        this.loadSavedIds();

        if (this.savedListings.length === 0) {
            loadingState.classList.add('hidden');
            this.showEmptyState();
            return;
        }

        await this.loadListingsDetails();
    }

    async loadListingsDetails() {
        const loadingState = document.getElementById('loadingState');

        try {
            // Fetch details for each saved listing
            const promises = this.savedListings.map(id =>
                fetch(`${CONFIG.API_URL}?action=listing&id=${id}`)
                    .then(res => res.json())
                    .catch(() => null)
            );

            const results = await Promise.all(promises);

            // Filter successful responses
            this.listings = results
                .filter(r => r && r.success && r.listing)
                .map(r => r.listing);

            loadingState.classList.add('hidden');

            if (this.listings.length === 0) {
                // All saved listings might have been deleted
                localStorage.setItem('motorlink_favorites', '[]');
                this.showEmptyState();
                return;
            }

            this.renderListings();
        } catch (error) {
            loadingState.innerHTML = `
                <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc2626; margin-bottom: 16px;"></i>
                <p>Error loading saved cars. Please try again.</p>
                <button class="btn btn-primary" onclick="location.reload()">Retry</button>
            `;
        }
    }

    renderListings() {
        const grid = document.getElementById('favoritesGrid');
        
        // Update hero stat
        this.updateStats();

        grid.innerHTML = this.listings.map(listing => {
            const isSold = String(listing.status || '').toLowerCase() === 'sold';

            // Get image URL
            let imageUrl = '';
            if (listing.featured_image) {
                imageUrl = `${CONFIG.BASE_URL}uploads/${listing.featured_image}`;
            } else if (listing.featured_image_id) {
                imageUrl = `${CONFIG.API_URL}?action=image&id=${listing.featured_image_id}`;
            } else if (listing.images && listing.images.length > 0) {
                const img = listing.images[0];
                if (img.id) {
                    imageUrl = `${CONFIG.API_URL}?action=image&id=${img.id}`;
                } else if (img.filename) {
                    imageUrl = `${CONFIG.BASE_URL}uploads/${img.filename}`;
                }
            }

            return `
                <div class="car-card ${isSold ? 'is-sold' : ''}" data-id="${listing.id}" onclick="window.location.href='car.html?id=${listing.id}'">
                    <div class="car-image">
                        ${imageUrl
                            ? `<img src="${imageUrl}" alt="${this.escapeHtml(listing.title)}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                               <div class="placeholder" style="display: none;"><i class="fas fa-car"></i></div>`
                            : '<div class="placeholder"><i class="fas fa-car"></i></div>'}
                        ${isSold ? `
                            <div class="sold-badge" title="This car is marked as sold">
                                <i class="fas fa-tag"></i> SOLD
                            </div>
                        ` : ''}
                        <button class="remove-btn" onclick="event.stopPropagation(); favoritesManager.removeFavorite(${listing.id})" title="Remove from saved">
                            <i class="fas fa-heart"></i>
                        </button>
                    </div>
                    <div class="car-info">
                        <h3 class="car-title">${this.escapeHtml(listing.title)}</h3>
                        <div class="car-price">
                            <span class="currency">MWK</span> ${this.formatNumber(listing.price)}
                        </div>
                        <div class="car-meta">
                            <span><i class="fas fa-calendar"></i> ${listing.year || 'N/A'}</span>
                            <span><i class="fas fa-tachometer-alt"></i> ${listing.mileage ? this.formatNumber(listing.mileage) + ' km' : 'N/A'}</span>
                            <span><i class="fas fa-map-marker-alt"></i> ${listing.location_name || 'N/A'}</span>
                        </div>
                    </div>
                    <div class="car-actions" onclick="event.stopPropagation()">
                        <a href="car.html?id=${listing.id}" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <button class="btn btn-outline-danger" onclick="favoritesManager.removeFavorite(${listing.id})" title="Remove from saved">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                        ${listing.contact_phone ? `
                            <a href="tel:${listing.contact_phone}" class="btn btn-outline-primary">
                                <i class="fas fa-phone"></i> Call
                            </a>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');
    }

    removeFavorite(listingId) {
        // Remove from localStorage
        this.savedListings = this.savedListings.filter(id => id !== listingId);
        localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));

        // Remove from current list
        this.listings = this.listings.filter(l => l.id != listingId);

        // Update UI
        if (this.listings.length === 0) {
            this.showEmptyState();
        } else {
            this.renderListings();
        }

        this.showToast('Removed from saved cars', 'info');

        // Try to sync with server
        try {
            const storedAuth = localStorage.getItem('motorlink_authenticated');
            if (storedAuth === 'true') {
                fetch(`${CONFIG.API_URL}?action=unsave_listing`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ listing_id: listingId })
                });
            }
        } catch (error) {
        }
    }

    showEmptyState() {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('favoritesGrid').innerHTML = '';
        document.getElementById('emptyState').classList.remove('hidden');
        // Update stats to 0
        this.updateStats();
    }
    
    updateStats() {
        const totalElement = document.getElementById('totalFavorites');
        if (totalElement) {
            totalElement.textContent = this.listings.length;
        }
    }
    
    async loadRecommendations() {
        try {
            const recommendationUrl = this.getRecommendationApiUrl();
            const sessionId = this.getOrCreateSessionId();

            // Analyze saved cars to find patterns and persist as an explicit preference hint.
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

            // Always fetch recommendations (personalized first, trending fallback).
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

            // Filter out cars already in favorites and limit visual payload.
            const filtered = recommendations
                .filter(rec => !this.savedListings.includes(parseInt(rec.id)))
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
            return null; // Need at least 2 saved cars to find patterns
        }
        
        // Analyze common characteristics
        const makes = {};
        const priceRanges = [];
        const years = [];
        const fuelTypes = {};
        const transmissions = {};
        
        this.listings.forEach(listing => {
            // Count makes
            const make = listing.make_name || listing.make;
            if (make) makes[make] = (makes[make] || 0) + 1;
            
            // Collect prices
            if (listing.price) priceRanges.push(parseInt(listing.price));
            
            // Collect years
            if (listing.year) years.push(parseInt(listing.year));
            
            // Count fuel types
            if (listing.fuel_type) fuelTypes[listing.fuel_type] = (fuelTypes[listing.fuel_type] || 0) + 1;
            
            // Count transmissions
            if (listing.transmission) transmissions[listing.transmission] = (transmissions[listing.transmission] || 0) + 1;
        });
        
        // Find dominant patterns
        const mostCommonMake = Object.keys(makes).length > 0 
            ? Object.keys(makes).reduce((a, b) => makes[a] > makes[b] ? a : b)
            : null;
        
        const avgPrice = priceRanges.length > 0
            ? priceRanges.reduce((a, b) => a + b, 0) / priceRanges.length
            : null;
        
        const avgYear = years.length > 0
            ? Math.round(years.reduce((a, b) => a + b, 0) / years.length)
            : null;
        
        return {
            preferredMake: mostCommonMake,
            avgPrice: avgPrice,
            priceMin: avgPrice ? avgPrice * 0.7 : null,
            priceMax: avgPrice ? avgPrice * 1.3 : null,
            avgYear: avgYear,
            yearMin: avgYear ? avgYear - 3 : null,
            yearMax: avgYear ? avgYear + 2 : null,
            preferredFuel: Object.keys(fuelTypes).length > 0
                ? Object.keys(fuelTypes).reduce((a, b) => fuelTypes[a] > fuelTypes[b] ? a : b)
                : null,
            preferredTransmission: Object.keys(transmissions).length > 0
                ? Object.keys(transmissions).reduce((a, b) => transmissions[a] > transmissions[b] ? a : b)
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
        
        grid.innerHTML = recommendations.map(listing => {
            // Get image URL
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
                            MWK ${this.formatNumber(listing.price)}
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
        // Add to savedListings
        if (!this.savedListings.includes(listingId)) {
            this.savedListings.push(listingId);
            localStorage.setItem('motorlink_favorites', JSON.stringify(this.savedListings));
            
            this.showToast('Added to saved cars!', 'success');
            
            // Reload to update the display
            setTimeout(() => {
                window.location.reload();
            }, 1000);
            
            // Sync with server if logged in
            if (this.isLoggedIn) {
                try {
                    await fetch(`${CONFIG.API_URL}?action=save_listing`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ listing_id: listingId })
                    });
                } catch (error) {
                    console.error('Failed to sync with server:', error);
                }
            }
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'success' ? '#10b981' : '#3b82f6'};
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatNumber(num) {
        if (!num) return '0';
        return parseInt(num).toLocaleString();
    }
}

// Initialize
let favoritesManager;
document.addEventListener('DOMContentLoaded', () => {
    favoritesManager = new FavoritesManager();
    window.favoritesManager = favoritesManager;
});

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

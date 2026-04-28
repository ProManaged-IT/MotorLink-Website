// ============================================================================
// Car Detail Page - Individual Car Listing View
// ============================================================================
// Displays detailed information about a specific car listing
// Includes image gallery, specifications, contact options, and similar cars
// Uses global CONFIG from config.js for API endpoints
// ============================================================================

// Enhanced CarDetailManager with working image gallery
class CarDetailManager {
    constructor() {
        this.listingId = this.getListingIdFromUrl();
        this.currentImageIndex = 0;
        this.listingImages = [];
        this.isFullscreen = false;
        this.recommendationsLoaded = false;
        this.init();
    }

    init() {
        if (!this.listingId) {
            this.showError('Invalid listing ID');
            return;
        }
        this.loadCarDetail();
    }

    getListingIdFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('id');
    }

    async loadCarDetail() {
        try {
            // Fetch user info if not already available
            if (!this.getCurrentUser()) {
                await this.fetchCurrentUser();
            }
            
            const response = await fetch(`${CONFIG.API_URL}?action=listing&id=${this.listingId}`);
            const data = await response.json();

            if (data.success && data.listing) {
                this.renderCarDetail(data.listing);
                this.updatePageSEO(data.listing);
                this.checkSavedStatus();
            } else {
                this.showError(data.message || 'Failed to load car details');
            }
        } catch (error) {
            this.showError('Network error. Please try again.');
        }
    }

    async fetchCurrentUser() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                credentials: 'include'
            });
            const data = await response.json();
            
            if (data.success && data.authenticated && data.user) {
                // Store in window.motorLink if it exists
                if (window.motorLink) {
                    window.motorLink.currentUser = data.user;
                }
                // Also store in localStorage
                localStorage.setItem('motorlink_user', JSON.stringify(data.user));
                localStorage.setItem('motorlink_authenticated', 'true');
            }
        } catch (error) {
            // Silently fail - will use localStorage fallback
        }
    }

    updatePageSEO(listing) {
        const make = listing.make_name || listing.make || '';
        const model = listing.model_name || listing.model || '';
        const year = listing.year || '';
        const price = listing.price ? `${CONFIG.CURRENCY_CODE || 'MWK'} ${parseInt(listing.price).toLocaleString()}` : null;
        const location = listing.location_name || listing.district || 'Malawi';
        const pageUrl = window.location.href;
        const title = listing.title || `${year} ${make} ${model}`.trim() || 'Car for Sale';
        const pageTitle = `${title} — MotorLink | Buy in ${location}`;
        const desc = [
            price ? `${title} for ${price}.` : `${title} for sale.`,
            listing.mileage ? `${parseInt(listing.mileage).toLocaleString()} km.` : '',
            listing.transmission ? `${listing.transmission} transmission.` : '',
            `Listed on MotorLink, Malawi's #1 car marketplace.`
        ].filter(Boolean).join(' ');

        // First image URL
        let imgUrl = 'https://promanaged-it.com/motorlink/motorlink-ad-banner.png';
        if (listing.images && listing.images.length > 0) {
            imgUrl = `${CONFIG.API_URL}?action=image&id=${listing.images[0].id}`;
        }

        // Update <title> and meta tags
        document.title = pageTitle;
        const setMeta = (sel, attr, val) => { const el = document.querySelector(sel); if (el) el.setAttribute(attr, val); };
        setMeta('meta[name="description"]', 'content', desc);
        setMeta('#canonicalTag', 'href', pageUrl);
        setMeta('#ogUrl', 'content', pageUrl);
        setMeta('#ogTitle', 'content', pageTitle);
        setMeta('#ogDescription', 'content', desc);
        setMeta('#ogImage', 'content', imgUrl);
        setMeta('#twitterTitle', 'content', pageTitle);
        setMeta('#twitterDescription', 'content', desc);
        setMeta('#twitterImage', 'content', imgUrl);

        // Update JSON-LD with Vehicle schema
        const ldEl = document.getElementById('listingJsonLd');
        if (ldEl) {
            const ld = {
                "@context": "https://schema.org",
                "@graph": [
                    {
                        "@type": "Vehicle",
                        "name": title,
                        "url": pageUrl,
                        "description": desc,
                        "brand": {"@type": "Brand", "name": make},
                        "model": model,
                        "modelDate": year ? String(year) : undefined,
                        "mileageFromOdometer": listing.mileage ? {"@type": "QuantitativeValue", "value": parseInt(listing.mileage), "unitCode": "KMT"} : undefined,
                        "vehicleTransmission": listing.transmission || undefined,
                        "fuelType": listing.fuel_type || undefined,
                        "image": imgUrl,
                        "offers": {
                            "@type": "Offer",
                            "priceCurrency": CONFIG.CURRENCY_CODE || "MWK",
                            "price": listing.price ? String(listing.price) : undefined,
                            "availability": "https://schema.org/InStock",
                            "seller": {"@type": "Person", "name": listing.seller_name || "Private Seller"},
                            "areaServed": {"@type": "Country", "name": "Malawi"}
                        }
                    },
                    {
                        "@type": "BreadcrumbList",
                        "itemListElement": [
                            {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://promanaged-it.com/motorlink/"},
                            {"@type": "ListItem", "position": 2, "name": "Cars for Sale", "item": "https://promanaged-it.com/motorlink/index.html"},
                            {"@type": "ListItem", "position": 3, "name": title, "item": pageUrl}
                        ]
                    }
                ]
            };
            // Remove undefined fields
            ldEl.textContent = JSON.stringify(ld, (k, v) => v === undefined ? undefined : v, 2);
        }
    }

    renderCarDetail(listing) {
        const carContent = document.getElementById('carContent');
        if (!carContent) return;
        const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22400%22 height=%22300%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22150%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EImage unavailable%3C/text%3E%3C/svg%3E';

        // Store listing data and images for gallery
        this.currentListing = listing;
        this.listingImages = listing.images || [];
        
        // Check if current user owns this listing
        const currentUser = this.getCurrentUser();
        const isLoggedIn = !!currentUser;
        const isOwnListing = currentUser && listing.user_id && parseInt(currentUser.id) === parseInt(listing.user_id);
        
        // Format price with commas
        const formattedPrice = listing.price ? `${CONFIG.CURRENCY_CODE || 'MWK'} ${parseInt(listing.price).toLocaleString()}` : 'Price on request';
        
        // Generate HTML for images
        let imagesHTML = '';
        let thumbnailsHTML = '';
        
        if (this.listingImages.length > 0) {
            imagesHTML = this.listingImages.map((image, index) => `
                <img src="${CONFIG.API_URL}?action=image&id=${image.id}"
                     alt="${listing.title}"
                     class="main-image ${index === 0 ? 'active' : ''}"
                     data-index="${index}"
                     onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
            `).join('');

            thumbnailsHTML = this.listingImages.map((image, index) => `
                <img src="${CONFIG.API_URL}?action=image&id=${image.id}"
                     alt="${listing.title}"
                     class="thumbnail ${index === 0 ? 'active' : ''}"
                     data-index="${index}"
                     onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
            `).join('');
        } else {
            imagesHTML = '<div class="no-image"><i class="fas fa-car"></i><span>No Image Available</span></div>';
            thumbnailsHTML = '';
        }

        // Generate HTML for car details
        carContent.innerHTML = `
            <div class="car-detail-grid">
                <div class="car-content-main">
                    <div class="image-gallery">
                        <div class="main-image-container">
                            ${imagesHTML}
                            ${this.listingImages.length > 1 ? `
                            <button class="gallery-nav gallery-nav-prev" id="galleryPrev">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="gallery-nav gallery-nav-next" id="galleryNext">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <div class="image-counter">
                                <span id="currentImageNum">1</span> / <span id="totalImages">${this.listingImages.length}</span>
                            </div>
                            ` : ''}
                            ${this.listingImages.length > 0 ? `
                            <button class="fullscreen-toggle" id="fullscreenToggle">
                                <i class="fas fa-expand"></i> Full Screen
                            </button>
                            ` : ''}
                        </div>
                        ${this.listingImages.length > 1 ? `
                        <div class="thumbnail-strip">
                            ${thumbnailsHTML}
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="car-info">
                        <div class="car-header">
                            <h1 class="car-title">${this.escapeHtml(listing.title)}</h1>
                            <div class="car-price-section">
                                <div class="car-price">${formattedPrice}</div>
                                ${listing.negotiable == 1 ? '<div class="listing-badge">Negotiable</div>' : ''}
                            </div>
                            ${listing.reference_number ? `
                                <div class="car-reference">Reference: ${listing.reference_number}</div>
                                <div class="car-reference" style="margin-top: 4px;">ID: ${listing.id}</div>
                            ` : `
                                <div class="car-reference">ID: ${listing.id}</div>
                            `}
                        </div>
                        
                        <div class="car-details-grid">
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-calendar"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Year</div>
                                    <div class="detail-value">${listing.year || 'N/A'}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-road"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Mileage</div>
                                    <div class="detail-value">${listing.mileage ? this.formatNumber(listing.mileage) + ' km' : 'N/A'}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-gas-pump"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Fuel Type</div>
                                    <div class="detail-value">${listing.fuel_type ? this.capitalize(listing.fuel_type) : 'N/A'}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-cog"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Transmission</div>
                                    <div class="detail-value">${listing.transmission ? this.capitalize(listing.transmission) : 'N/A'}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-car"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Condition</div>
                                    <div class="detail-value">${listing.condition_type ? this.capitalize(listing.condition_type.replace('_', ' ')) : 'N/A'}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-palette"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Exterior Color</div>
                                    <div class="detail-value">${listing.exterior_color || 'N/A'}</div>
                                </div>
                            </div>
                            ${listing.interior_color ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-couch"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Interior Color</div>
                                    <div class="detail-value">${listing.interior_color}</div>
                                </div>
                            </div>
                            ` : ''}
                            ${listing.seats ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-chair"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Seats</div>
                                    <div class="detail-value">${listing.seats} Seats</div>
                                </div>
                            </div>
                            ` : ''}
                            ${listing.doors ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-door-open"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Doors</div>
                                    <div class="detail-value">${listing.doors} Doors</div>
                                </div>
                            </div>
                            ` : ''}
                            ${listing.engine_size ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-tachometer-alt"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Engine Size</div>
                                    <div class="detail-value">${listing.engine_size}</div>
                                </div>
                            </div>
                            ` : ''}
                            ${listing.drivetrain ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Drivetrain</div>
                                    <div class="detail-value">${listing.drivetrain ? listing.drivetrain.toUpperCase() : ''}</div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                        
                        ${listing.description ? `
                            <div class="car-description">
                                <h3>Description</h3>
                                <p>${this.escapeHtml(listing.description)}</p>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="car-sidebar">
                    <div class="contact-card">
                        <div class="contact-header">
                            <h3>Seller Information</h3>
                            <div class="seller-type">${listing.seller_type === 'dealer' ? 'Dealer' : listing.seller_type === 'garage' ? 'Garage' : listing.seller_type === 'car_hire' ? 'Car Hire' : 'Private Seller'}</div>
                        </div>

                        <div class="contact-details">
                            ${listing.business_name ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Business Name</div>
                                    <div class="detail-value">${this.escapeHtml(listing.business_name)}</div>
                                </div>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Contact Person</div>
                                    <div class="detail-value">${this.escapeHtml(listing.contact_name || 'N/A')}</div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Phone</div>
                                    <div class="detail-value">${this.escapeHtml(listing.contact_phone || 'N/A')}</div>
                                </div>
                            </div>
                            ${listing.contact_email ? `
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Email</div>
                                    <div class="detail-value">${this.escapeHtml(listing.contact_email)}</div>
                                </div>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Location</div>
                                    <div class="detail-value">${this.escapeHtml(listing.location_name)}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="contact-actions">
                            ${listing.contact_phone ? `
                                <a href="tel:${listing.contact_phone}" class="contact-btn primary">
                                    <i class="fas fa-phone"></i> Call Seller
                                </a>
                            ` : ''}
                            ${listing.contact_phone ? `
                                <a href="https://wa.me/${listing.contact_phone.replace(/[^0-9]/g, '')}?text=Hi, I'm interested in your car listing: ${encodeURIComponent(listing.title)}"
                                   class="contact-btn secondary" target="_blank">
                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                </a>
                            ` : ''}
                            ${!isOwnListing && Number(listing.user_id) > 0 && isLoggedIn ? `
                            <a href="chat_system.html?listing=${listing.id}&seller=${listing.user_id}" class="contact-btn outline">
                                <i class="fas fa-comments"></i> Send Message
                            </a>
                            ` : !isOwnListing && Number(listing.user_id) > 0 ? `
                            <button class="contact-btn outline" onclick="carDetailManager.requestLoginForAction('send messages')">
                                <i class="fas fa-comments"></i> Send Message
                            </button>
                            ` : ''}
                            ${!isOwnListing ? `
                            <button class="contact-btn save-btn" id="saveListingBtn" data-listing-id="${listing.id}" onclick="carDetailManager.toggleSave()">
                                <i class="far fa-heart"></i> Save
                            </button>
                            ` : ''}
                            ${!isOwnListing ? `
                            <button class="contact-btn report-btn" onclick="carDetailManager.openReportModal(${listing.id})" title="Report this listing">
                                <i class="fas fa-flag"></i> Report
                            </button>
                            ` : ''}
                        </div>
                        
                        <div class="safety-tips">
                            <h4><i class="fas fa-shield-alt"></i> Safety Tips</h4>
                            <ul>
                                <li>Meet in a public place</li>
                                <li>Inspect the car thoroughly</li>
                                <li>Verify all documents</li>
                                <li>Never pay before seeing the car</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="car-meta-info">
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Views</div>
                                <div class="detail-value">${listing.views_count || 0}</div>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Listed</div>
                                <div class="detail-value">${this.timeAgo(listing.created_at)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            ${listing.user_id && (listing.seller_type === 'dealer' || listing.seller_type === 'garage' || listing.seller_type === 'car_hire') ? `
            <div class="dealer-showroom-section" id="dealerShowroom">
                <div class="showroom-header">
                    <h2><i class="fas fa-store"></i> More from ${this.escapeHtml(listing.business_name || 'this seller')}</h2>
                    ${listing.dealer_id ? `
                        <a href="showroom.html?dealer_id=${listing.dealer_id}" class="view-all-link">
                            View All Listings <i class="fas fa-arrow-right"></i>
                        </a>
                    ` : ''}
                </div>
                <div class="showroom-loading">
                    <div class="loading-spinner"></div>
                    <p>Loading other listings...</p>
                </div>
                <div class="showroom-grid" id="showroomGrid"></div>
            </div>
            ` : ''}

            <div class="dealer-showroom-section" id="recommendedCarsSection" style="display:none;">
                <div class="showroom-header">
                    <h2><i class="fas fa-magic"></i> Recommended For You</h2>
                </div>
                <div class="showroom-grid" id="recommendedCarsGrid"></div>
            </div>
        `;

        // Set up event listeners for the gallery
        this.setupGalleryEvents();

        // Load dealer's other listings if applicable
        if (listing.user_id && (listing.seller_type === 'dealer' || listing.seller_type === 'garage' || listing.seller_type === 'car_hire')) {
            this.loadDealerOtherListings(listing.user_id);
        }

        // Learn from this view and show personalized options frequently.
        this.trackViewAndLoadRecommendations(listing);
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

    updateLocalPreferenceSnapshot(listing) {
        try {
            const prefs = JSON.parse(localStorage.getItem('motorlink_user_preferences') || '{}');

            if (!prefs.makes) prefs.makes = {};
            if (!prefs.models) prefs.models = {};
            if (!prefs.fuel_types) prefs.fuel_types = {};
            if (!prefs.transmissions) prefs.transmissions = {};
            if (!prefs.body_types) prefs.body_types = {};
            if (!prefs.price_range) prefs.price_range = { min: Number.MAX_SAFE_INTEGER, max: 0, total: 0, count: 0, avg: 0 };
            if (!prefs.year_range) prefs.year_range = { min: Number.MAX_SAFE_INTEGER, max: 0, total: 0, count: 0, avg: 0 };
            if (!prefs.mileage_range) prefs.mileage_range = { min: Number.MAX_SAFE_INTEGER, max: 0, total: 0, count: 0, avg: 0 };

            if (listing.make_name) prefs.makes[listing.make_name] = (prefs.makes[listing.make_name] || 0) + 2;
            if (listing.model_name) prefs.models[listing.model_name] = (prefs.models[listing.model_name] || 0) + 2;
            if (listing.fuel_type) prefs.fuel_types[listing.fuel_type] = (prefs.fuel_types[listing.fuel_type] || 0) + 2;
            if (listing.transmission) prefs.transmissions[listing.transmission] = (prefs.transmissions[listing.transmission] || 0) + 2;
            if (listing.body_type) prefs.body_types[listing.body_type] = (prefs.body_types[listing.body_type] || 0) + 1;

            const price = parseInt(listing.price, 10);
            if (Number.isFinite(price) && price > 0) {
                prefs.price_range.min = Math.min(Number(prefs.price_range.min) || Number.MAX_SAFE_INTEGER, price);
                prefs.price_range.max = Math.max(Number(prefs.price_range.max) || 0, price);
                prefs.price_range.total = (Number(prefs.price_range.total) || 0) + price;
                prefs.price_range.count = (Number(prefs.price_range.count) || 0) + 1;
                prefs.price_range.avg = prefs.price_range.total / prefs.price_range.count;
            }

            const year = parseInt(listing.year, 10);
            if (Number.isFinite(year) && year > 1900) {
                prefs.year_range.min = Math.min(Number(prefs.year_range.min) || Number.MAX_SAFE_INTEGER, year);
                prefs.year_range.max = Math.max(Number(prefs.year_range.max) || 0, year);
                prefs.year_range.total = (Number(prefs.year_range.total) || 0) + year;
                prefs.year_range.count = (Number(prefs.year_range.count) || 0) + 1;
                prefs.year_range.avg = prefs.year_range.total / prefs.year_range.count;
            }

            const mileage = parseInt(listing.mileage, 10);
            if (Number.isFinite(mileage) && mileage >= 0) {
                prefs.mileage_range.min = Math.min(Number(prefs.mileage_range.min) || Number.MAX_SAFE_INTEGER, mileage);
                prefs.mileage_range.max = Math.max(Number(prefs.mileage_range.max) || 0, mileage);
                prefs.mileage_range.total = (Number(prefs.mileage_range.total) || 0) + mileage;
                prefs.mileage_range.count = (Number(prefs.mileage_range.count) || 0) + 1;
                prefs.mileage_range.avg = prefs.mileage_range.total / prefs.mileage_range.count;
            }

            prefs.last_updated = Date.now();
            localStorage.setItem('motorlink_user_preferences', JSON.stringify(prefs));
            return prefs;
        } catch (error) {
            return null;
        }
    }

    trackViewAndLoadRecommendations(listing) {
        if (!listing || !listing.id) return;

        const sessionId = this.getOrCreateSessionId();
        const recommendationUrl = this.getRecommendationApiUrl();
        const preferences = this.updateLocalPreferenceSnapshot(listing);

        const payload = {
            action: 'track_view',
            listing_id: listing.id,
            session_id: sessionId,
            preferences: preferences || undefined,
            timestamp: Date.now()
        };

        try {
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                navigator.sendBeacon(`${recommendationUrl}?action=track_view`, blob);
            } else {
                fetch(recommendationUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    keepalive: true,
                    body: JSON.stringify(payload)
                }).catch(() => {});
            }
        } catch (error) {
            // Silently ignore tracking errors.
        }

        this.loadPersonalizedRecommendations(listing.id);
    }

    async loadPersonalizedRecommendations(currentListingId) {
        if (this.recommendationsLoaded) return;

        const recommendationUrl = this.getRecommendationApiUrl();
        const sessionId = this.getOrCreateSessionId();

        try {
            let endpoint = `${recommendationUrl}?action=get_recommendations&type=personalized&limit=6&session_id=${encodeURIComponent(sessionId)}&exclude_listing_id=${encodeURIComponent(currentListingId)}`;
            let response = await fetch(endpoint, { credentials: 'include' });
            let data = await response.json();

            let recommendations = Array.isArray(data?.recommendations) ? data.recommendations : [];

            if (recommendations.length === 0) {
                endpoint = `${recommendationUrl}?action=get_recommendations&type=trending&limit=6&session_id=${encodeURIComponent(sessionId)}&exclude_listing_id=${encodeURIComponent(currentListingId)}`;
                response = await fetch(endpoint, { credentials: 'include' });
                data = await response.json();
                recommendations = Array.isArray(data?.recommendations) ? data.recommendations : [];
            }

            recommendations = recommendations.filter(item => parseInt(item.id, 10) !== parseInt(currentListingId, 10)).slice(0, 6);

            if (recommendations.length > 0) {
                this.renderPersonalizedRecommendations(recommendations);
                this.recommendationsLoaded = true;
            }
        } catch (error) {
            // Recommendation loading is best-effort.
        }
    }

    renderPersonalizedRecommendations(listings) {
        const section = document.getElementById('recommendedCarsSection');
        const grid = document.getElementById('recommendedCarsGrid');
        if (!section || !grid || !Array.isArray(listings) || listings.length === 0) {
            return;
        }

        const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22400%22 height=%22300%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22150%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EImage unavailable%3C/text%3E%3C/svg%3E';

        grid.innerHTML = listings.map(listing => {
            const imageUrl = listing.featured_image_id
                ? `${CONFIG.API_URL}?action=image&id=${listing.featured_image_id}`
                : inlinePlaceholder;
            const formattedPrice = listing.price ? `${CONFIG.CURRENCY_CODE || 'MWK'} ${parseInt(listing.price, 10).toLocaleString()}` : 'Price on request';

            return `
                <div class="showroom-card" onclick="window.location.href='car.html?id=${listing.id}'">
                    <div class="showroom-card-image">
                        <img src="${imageUrl}" alt="${this.escapeHtml(listing.title || '')}" onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                        <span class="listing-badge premium">Recommended</span>
                    </div>
                    <div class="showroom-card-content">
                        <h3 class="showroom-card-title">${this.escapeHtml(listing.title || '')}</h3>
                        <div class="showroom-card-price">${formattedPrice}</div>
                        <div class="showroom-card-details">
                            <span><i class="fas fa-calendar"></i> ${listing.year || 'N/A'}</span>
                            <span><i class="fas fa-road"></i> ${listing.mileage ? this.formatNumber(listing.mileage) + ' km' : 'N/A'}</span>
                        </div>
                        <div class="showroom-card-footer">
                            <span><i class="fas fa-gas-pump"></i> ${listing.fuel_type ? this.capitalize(listing.fuel_type) : 'N/A'}</span>
                            <span><i class="fas fa-map-marker-alt"></i> ${this.escapeHtml(listing.location_name || 'N/A')}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        section.style.display = 'block';
    }

    setupGalleryEvents() {
        // Thumbnail click events
        const thumbnails = document.querySelectorAll('.thumbnail');
        thumbnails.forEach(thumb => {
            thumb.addEventListener('click', (e) => {
                e.stopPropagation();
                const index = parseInt(thumb.getAttribute('data-index'));
                this.showImage(index);
            });
        });

        // Navigation arrows click events
        const prevBtn = document.getElementById('galleryPrev');
        const nextBtn = document.getElementById('galleryNext');

        if (prevBtn) {
            prevBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.prevImage();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.nextImage();
            });
        }

        // Fullscreen toggle
        const fullscreenToggle = document.getElementById('fullscreenToggle');
        if (fullscreenToggle) {
            fullscreenToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleFullscreen();
            });
        }

        // Main image click to toggle fullscreen
        const mainImageContainer = document.querySelector('.main-image-container');
        if (mainImageContainer) {
            mainImageContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('main-image')) {
                    this.toggleFullscreen();
                }
            });
        }

        // Keyboard navigation - works outside fullscreen too for accessibility
        document.addEventListener('keydown', (e) => {
            // Check if user is typing in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.key === 'Escape' && this.isFullscreen) this.exitFullscreen();
            if (e.key === 'ArrowRight') this.nextImage();
            if (e.key === 'ArrowLeft') this.prevImage();
        });

        // Fullscreen change event
        document.addEventListener('fullscreenchange', () => {
            this.isFullscreen = !!(document.fullscreenElement || 
                                 document.webkitFullscreenElement || 
                                 document.msFullscreenElement);
        });
    }

    showImage(index) {

        // Update active states for main images
        const mainImages = document.querySelectorAll('.main-image');
        mainImages.forEach(img => img.classList.remove('active'));

        // Show selected image
        const selectedImage = document.querySelector(`.main-image[data-index="${index}"]`);
        if (selectedImage) {
            selectedImage.classList.add('active');
        }

        // Update active states for thumbnails
        const thumbnails = document.querySelectorAll('.thumbnail');
        thumbnails.forEach(thumb => thumb.classList.remove('active'));

        const selectedThumb = document.querySelector(`.thumbnail[data-index="${index}"]`);
        if (selectedThumb) {
            selectedThumb.classList.add('active');
            // Scroll thumbnail into view if needed
            selectedThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        // Update image counter
        const currentImageNum = document.getElementById('currentImageNum');
        if (currentImageNum) {
            currentImageNum.textContent = index + 1;
        }

        this.currentImageIndex = index;
    }

    nextImage() {
        if (this.listingImages.length === 0) return;
        const nextIndex = (this.currentImageIndex + 1) % this.listingImages.length;
        this.showImage(nextIndex);
    }

    prevImage() {
        if (this.listingImages.length === 0) return;
        const prevIndex = (this.currentImageIndex - 1 + this.listingImages.length) % this.listingImages.length;
        this.showImage(prevIndex);
    }

    toggleFullscreen() {
        if (this.isFullscreen) {
            this.exitFullscreen();
        } else {
            this.enterFullscreen();
        }
    }

    enterFullscreen() {
        const mainImageContainer = document.querySelector('.main-image-container');
        if (!mainImageContainer) return;
        
        if (mainImageContainer.requestFullscreen) {
            mainImageContainer.requestFullscreen();
        } else if (mainImageContainer.webkitRequestFullscreen) {
            mainImageContainer.webkitRequestFullscreen();
        } else if (mainImageContainer.msRequestFullscreen) {
            mainImageContainer.msRequestFullscreen();
        }
        
        this.isFullscreen = true;
        const fullscreenToggle = document.getElementById('fullscreenToggle');
        if (fullscreenToggle) {
            fullscreenToggle.innerHTML = '<i class="fas fa-compress"></i> Exit Full Screen';
        }
    }

    exitFullscreen() {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }

        this.isFullscreen = false;
        const fullscreenToggle = document.getElementById('fullscreenToggle');
        if (fullscreenToggle) {
            fullscreenToggle.innerHTML = '<i class="fas fa-expand"></i> Full Screen';
        }
    }

    async loadDealerOtherListings(userId) {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=dealer_other_listings&user_id=${userId}&current_listing_id=${this.listingId}&limit=6`);
            const data = await response.json();

            const loadingDiv = document.querySelector('.showroom-loading');
            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success && data.listings && data.listings.length > 0) {
                this.renderDealerListings(data.listings);
            } else {
                // Hide the section if no other listings
                const showroomSection = document.getElementById('dealerShowroom');
                if (showroomSection) showroomSection.style.display = 'none';
            }
        } catch (error) {
            const loadingDiv = document.querySelector('.showroom-loading');
            if (loadingDiv) loadingDiv.style.display = 'none';
        }
    }

    renderDealerListings(listings) {
        const grid = document.getElementById('showroomGrid');
        if (!grid) return;
        const inlinePlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22400%22 height=%22300%22 fill=%22%23f3f4f6%22/%3E%3Ctext x=%22200%22 y=%22150%22 text-anchor=%22middle%22 font-family=%22Arial,sans-serif%22 font-size=%2216%22 fill=%226b7280%22%3EImage unavailable%3C/text%3E%3C/svg%3E';

        grid.innerHTML = listings.map(listing => {
            const imageUrl = listing.featured_image_id
                ? `${CONFIG.API_URL}?action=image&id=${listing.featured_image_id}`
                : inlinePlaceholder;

            const formattedPrice = listing.price ? `${CONFIG.CURRENCY_CODE || 'MWK'} ${parseInt(listing.price).toLocaleString()}` : 'Price on request';

            return `
                <div class="showroom-card" onclick="window.location.href='car.html?id=${listing.id}'">
                    <div class="showroom-card-image">
                        <img src="${imageUrl}" alt="${this.escapeHtml(listing.title)}"
                             onerror="this.onerror=null;this.src='${inlinePlaceholder}';">
                        ${listing.listing_type === 'featured' || listing.listing_type === 'premium' ? `
                            <span class="listing-badge ${listing.listing_type}">${this.capitalize(listing.listing_type)}</span>
                        ` : ''}
                    </div>
                    <div class="showroom-card-content">
                        <h3 class="showroom-card-title">${this.escapeHtml(listing.title)}</h3>
                        <div class="showroom-card-price">${formattedPrice}</div>
                        <div class="showroom-card-details">
                            <span><i class="fas fa-calendar"></i> ${listing.year || 'N/A'}</span>
                            <span><i class="fas fa-road"></i> ${listing.mileage ? this.formatNumber(listing.mileage) + ' km' : 'N/A'}</span>
                        </div>
                        <div class="showroom-card-footer">
                            <span><i class="fas fa-gas-pump"></i> ${listing.fuel_type ? this.capitalize(listing.fuel_type) : 'N/A'}</span>
                            <span><i class="fas fa-cog"></i> ${listing.transmission ? this.capitalize(listing.transmission) : 'N/A'}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    showError(message) {
        const carContent = document.getElementById('carContent');
        if (carContent) {
            carContent.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error Loading Car</h3>
                    <p>${message}</p>
                    <button onclick="window.history.back()" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </button>
                </div>
            `;
        }
    }

    // Utility functions
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatNumber(number) {
        if (!number) return '0';
        return new Intl.NumberFormat().format(number);
    }

    capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

    timeAgo(dateString) {
        if (!dateString) return '';

        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);

        if (diffInSeconds < 60) return 'just now';
        if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + ' min ago';
        if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + ' hours ago';
        if (diffInSeconds < 2592000) return Math.floor(diffInSeconds / 86400) + ' days ago';
        return date.toLocaleDateString();
    }

    // Check if listing is saved and update button state
    async checkSavedStatus() {
        const savedListings = JSON.parse(localStorage.getItem('motorlink_favorites') || '[]');
        const isSaved = savedListings.includes(parseInt(this.listingId));
        this.updateSaveButton(isSaved);
    }

    updateSaveButton(isSaved) {
        const btn = document.getElementById('saveListingBtn');
        if (btn) {
            if (isSaved) {
                btn.innerHTML = '<i class="fas fa-heart"></i> Saved';
                btn.classList.add('saved');
            } else {
                btn.innerHTML = '<i class="far fa-heart"></i> Save';
                btn.classList.remove('saved');
            }
        }
    }

    async toggleSave() {
        const btn = document.getElementById('saveListingBtn');
        if (!btn) return;

        const currentUser = this.getCurrentUser();
        if (!currentUser) {
            this.requestLoginForAction('save listings');
            return;
        }

        const listingId = parseInt(this.listingId);
        let savedListings = JSON.parse(localStorage.getItem('motorlink_favorites') || '[]');
        const isSaved = savedListings.includes(listingId);

        if (isSaved) {
            // Remove from favorites
            savedListings = savedListings.filter(id => id !== listingId);
            this.showToast('Removed from saved cars', 'info');
        } else {
            // Add to favorites
            savedListings.push(listingId);
            this.showToast('Added to saved cars!', 'success');
        }

        localStorage.setItem('motorlink_favorites', JSON.stringify(savedListings));
        this.updateSaveButton(!isSaved);

        // Sync with server for authenticated users only
        try {
            await fetch(`${CONFIG.API_URL}?action=${isSaved ? 'unsave_listing' : 'save_listing'}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ listing_id: listingId })
            });
        } catch (error) {
        }
    }

    requestLoginForAction(actionLabel = 'perform this action') {
        if (confirm(`You must be logged in to ${actionLabel}. Would you like to go to the login page?`)) {
            window.location.href = 'login.html?redirect=' + encodeURIComponent(window.location.href);
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
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

    openReportModal(listingId) {
        // Check if user is logged in
        const currentUser = this.getCurrentUser();
        if (!currentUser) {
            this.requestLoginForAction('report a listing');
            return;
        }
        
        const modal = document.getElementById('reportModal');
        if (!modal) return;
        
        document.getElementById('reportListingId').value = listingId;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Setup form submit handler
        const form = document.getElementById('reportForm');
        form.onsubmit = (e) => this.handleReportSubmit(e);
    }

    getCurrentUser() {
        // Try to get current user from window.motorLink (global app instance from script.js)
        if (window.motorLink && window.motorLink.currentUser) {
            return window.motorLink.currentUser;
        }
        
        // Try window.app as fallback
        if (window.app && window.app.currentUser) {
            return window.app.currentUser;
        }
        
        // Try localStorage as fallback
        try {
            const storedUser = localStorage.getItem('motorlink_user');
            if (storedUser) {
                return JSON.parse(storedUser);
            }
        } catch (e) {
            // Invalid JSON
        }
        
        return null;
    }

    closeReportModal() {
        const modal = document.getElementById('reportModal');
        if (!modal) return;
        
        modal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Reset form
        const form = document.getElementById('reportForm');
        if (form) form.reset();
    }

    async handleReportSubmit(e) {
        e.preventDefault();

        const currentUser = this.getCurrentUser();
        if (!currentUser) {
            this.closeReportModal();
            this.requestLoginForAction('report a listing');
            return;
        }
        
        const formData = new FormData(e.target);
        const reportData = {
            listing_id: formData.get('listing_id'),
            reason: formData.get('reason'),
            details: formData.get('details'),
            email: formData.get('email') || null
        };

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=report_listing`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(reportData)
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Report submitted successfully. Thank you for helping us keep MotorLink safe!', 'success');
                this.closeReportModal();
            } else {
                this.showToast(data.message || 'Failed to submit report. Please try again.', 'error');
            }
        } catch (error) {
            this.showToast('An error occurred. Please try again later.', 'error');
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.carDetailManager = new CarDetailManager();
});
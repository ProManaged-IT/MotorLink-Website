/**
 * Dynamic Footer Loader
 * Loads footer content from site_settings database table
 */

class FooterLoader {
    constructor() {
        this.settings = {};
        this.footerModalElement = null;
        this.handleEscapeKey = this.handleEscapeKey.bind(this);
        this.init();
    }

    async init() {
        await this.loadSettings();
        this.renderFooter();
    }

    async loadSettings() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=site_settings`, {
                ...(CONFIG.USE_CREDENTIALS && {credentials: 'include'})
            });
            const data = await response.json();
            
            if (data.success && data.settings) {
                this.settings = data.settings;
            } else {
                this.useDefaults();
            }
        } catch (error) {
            this.useDefaults();
        }
    }

    useDefaults() {
        // Fallback defaults if API fails
        const siteName = (window.CONFIG && CONFIG.SITE_NAME) ? CONFIG.SITE_NAME : 'MotorLink';
        const countryName = (window.CONFIG && CONFIG.COUNTRY_NAME) ? CONFIG.COUNTRY_NAME : '';
        const supportEmail = (window.CONFIG && CONFIG.SUPPORT_EMAIL) ? CONFIG.SUPPORT_EMAIL : 'support@example.com';
        const currentYear = new Date().getFullYear();

        this.settings = {
            general: {
                site_name: siteName,
                country_name: countryName
            },
            footer: {
                footer_about_text: `${siteName} helps people buy, sell, hire, and manage vehicles with confidence.`,
                footer_copyright: `© ${currentYear} ${siteName}. All rights reserved.`,
                footer_support_help_label: 'Help Center',
                footer_support_help_href: 'help.html#top',
                footer_support_help_type: 'page',
                footer_support_safety_label: 'Safety Tips',
                footer_support_safety_href: 'safety.html#top',
                footer_support_safety_type: 'page',
                footer_support_contact_label: 'Contact Us',
                footer_support_contact_href: 'contact.html#channels',
                footer_support_contact_type: 'page',
                footer_support_terms_label: 'Terms of Service',
                footer_support_terms_href: 'terms.html',
                footer_support_terms_type: 'page',
                footer_support_cookie_label: 'Cookie Policy',
                footer_support_cookie_href: 'cookie-policy.html',
                footer_support_cookie_type: 'page'
            },
            contact: {
                contact_phone: '+265 991 234 567',
                contact_email: supportEmail
            },
            social: {}
        };
    }

    escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    sanitizeUrl(url, fallback = '#') {
        const raw = String(url ?? '').trim();
        if (!raw) return fallback;

        const lower = raw.toLowerCase();
        if (lower.startsWith('javascript:') || lower.startsWith('data:')) {
            return fallback;
        }

        const safeAbsolute = /^(https?:|mailto:|tel:)/i;
        const safeRelative = /^(\.?\.\/|\/)?[a-z0-9_\-./]+(\?[a-z0-9_\-=&%]+)?(#[a-z0-9_\-]+)?$/i;
        const safeAnchor = /^#[a-z0-9_\-]+$/i;

        if (safeAbsolute.test(raw) || safeRelative.test(raw) || safeAnchor.test(raw)) {
            return raw;
        }

        return fallback;
    }

    normalizePhoneForTel(phone) {
        return String(phone ?? '').replace(/\s+/g, '');
    }

    normalizePhoneForWhatsApp(phone) {
        return String(phone ?? '').replace(/[^0-9]/g, '');
    }

    buildSupportLinks(footerInfo) {
        const defaults = [
            { key: 'help', label: 'Help Center', href: 'help.html#top', type: 'page' },
            { key: 'safety', label: 'Safety Tips', href: 'safety.html#top', type: 'page' },
            { key: 'contact', label: 'Contact Us', href: 'contact.html#channels', type: 'page' },
            { key: 'terms', label: 'Terms of Service', href: 'terms.html', type: 'page' },
            { key: 'cookie', label: 'Cookie Policy', href: 'cookie-policy.html', type: 'page' }
        ];

        return defaults.map((item) => {
            const prefix = `footer_support_${item.key}`;
            const label = (footerInfo[`${prefix}_label`] || item.label).trim();
            const href = (footerInfo[`${prefix}_href`] || item.href).trim();
            const type = (footerInfo[`${prefix}_type`] || item.type).trim().toLowerCase();
            const modalTitle = (footerInfo[`${prefix}_modal_title`] || label).trim();
            const modalContent = (footerInfo[`${prefix}_modal_content`] || '').trim();

            return {
                key: item.key,
                label,
                href,
                type: type === 'modal' ? 'modal' : 'page',
                modalTitle,
                modalContent
            };
        });
    }

    renderFooter() {
        const footer = document.querySelector('.footer');
        if (!footer) return;

        const container = footer.querySelector('.container');
        if (!container) return;

        const general = this.settings.general || {};
        const footer_info = this.settings.footer || {};
        const contact = this.settings.contact || {};
        const business = this.settings.business || {};
        const social = this.settings.social || {};
        const currentYear = new Date().getFullYear();

        const siteName = general.site_name || (window.CONFIG && CONFIG.SITE_NAME) || 'MotorLink';
        const countryName = general.country_name || (window.CONFIG && CONFIG.COUNTRY_NAME) || '';
        const aboutText = footer_info.footer_about_text || `${siteName} helps people buy, sell, hire, and manage vehicles with confidence.`;
        const copyright = footer_info.footer_copyright || `© ${currentYear} ${siteName}. All rights reserved.`;
        const supportLinks = this.buildSupportLinks(footer_info);
        
        const phone = contact.contact_phone || '';
        const phoneSecondary = contact.contact_phone_secondary || '';
        const email = contact.contact_email || '';
        const whatsapp = contact.contact_whatsapp || '';
        
        const address = business.business_address || '';
        const city = business.business_city || '';
        const district = business.business_district || '';
        
        const hoursWeekday = business.business_hours_weekday || '';
        const hoursSaturday = business.business_hours_saturday || '';
        const hoursSunday = business.business_hours_sunday || '';

        const siteNameSafe = this.escapeHtml(siteName);
        const aboutTextSafe = this.escapeHtml(aboutText);
        const copyrightSafe = this.escapeHtml(copyright);

        // Build social links HTML
        let socialLinksHTML = '';
        if (social.social_facebook) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_facebook))}" class="soc-facebook" target="_blank" rel="noopener" aria-label="Follow us on Facebook" title="${siteNameSafe} Facebook"><i class="fab fa-facebook-f"></i></a>`;
        }
        if (social.social_twitter) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_twitter))}" class="soc-twitter" target="_blank" rel="noopener" aria-label="Follow us on Twitter" title="${siteNameSafe} Twitter"><i class="fab fa-twitter"></i></a>`;
        }
        if (social.social_instagram) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_instagram))}" class="soc-instagram" target="_blank" rel="noopener" aria-label="Follow us on Instagram" title="${siteNameSafe} Instagram"><i class="fab fa-instagram"></i></a>`;
        }
        if (social.social_linkedin) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_linkedin))}" class="soc-linkedin" target="_blank" rel="noopener" aria-label="Connect on LinkedIn" title="${siteNameSafe} LinkedIn"><i class="fab fa-linkedin"></i></a>`;
        }
        if (social.social_whatsapp) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_whatsapp))}" class="soc-whatsapp" target="_blank" rel="noopener" aria-label="Contact us on WhatsApp" title="${siteNameSafe} WhatsApp"><i class="fab fa-whatsapp"></i></a>`;
        }
        if (social.social_youtube) {
            socialLinksHTML += `<a href="${this.escapeHtml(this.sanitizeUrl(social.social_youtube))}" class="soc-youtube" target="_blank" rel="noopener" aria-label="Subscribe on YouTube" title="${siteNameSafe} YouTube"><i class="fab fa-youtube"></i></a>`;
        }

        const supportLinksHTML = supportLinks.map((link, index) => {
            const label = this.escapeHtml(link.label);

            if (link.type === 'modal') {
                return `<li><a href="#" class="footer-modal-trigger" data-footer-modal-index="${index}">${label}</a></li>`;
            }

            return `<li><a href="${this.escapeHtml(this.sanitizeUrl(link.href, '#'))}">${label}</a></li>`;
        }).join('');

        // Build footer HTML
        container.innerHTML = `
            <div class="footer-content">
                <div class="footer-section">
                    <h4><i class="fas fa-car"></i> ${siteNameSafe}</h4>
                    <p>${aboutTextSafe}</p>
                    ${socialLinksHTML ? `<div class="social-links">${socialLinksHTML}</div>` : ''}
                </div>
                
                <div class="footer-section">
                    <h4><i class="fas fa-link"></i> Quick Links</h4>
                    <ul>
                        <li><a href="index.html">Browse Cars</a></li>
                        <li><a href="sell.html">Sell Your Car</a></li>
                        <li><a href="guest-manage.html">Manage Guest Listing</a></li>
                        <li><a href="garages.html">Find Garages</a></li>
                        <li><a href="dealers.html">Car Dealers</a></li>
                        <li><a href="car-hire.html">Car Hire</a></li>
                        <li><a href="car-database.html">Know Your Car</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h4><i class="fas fa-life-ring"></i> Support & Help</h4>
                    <ul>
                        ${supportLinksHTML}
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4><i class="fas fa-phone"></i> Contact Us</h4>
                    ${email ? `<p><i class="fas fa-envelope"></i> <strong>Email:</strong> <a href="mailto:${this.escapeHtml(email)}">${this.escapeHtml(email)}</a></p>` : ''}
                    ${phone ? `<p><i class="fas fa-phone"></i> <strong>Phone:</strong> <a href="tel:${this.escapeHtml(this.normalizePhoneForTel(phone))}">${this.escapeHtml(phone)}</a></p>` : ''}
                    ${phoneSecondary ? `<p><i class="fas fa-mobile-alt"></i> <strong>Alt:</strong> <a href="tel:${this.escapeHtml(this.normalizePhoneForTel(phoneSecondary))}">${this.escapeHtml(phoneSecondary)}</a></p>` : ''}
                    ${whatsapp ? `<p><i class="fab fa-whatsapp"></i> <strong>WhatsApp:</strong> <a href="https://wa.me/${this.escapeHtml(this.normalizePhoneForWhatsApp(whatsapp))}" target="_blank" rel="noopener">${this.escapeHtml(whatsapp)}</a></p>` : ''}
                    
                    ${address || city ? `
                    <div style="margin-top: 15px;">
                        <h5><i class="fas fa-map-marker-alt"></i> Location</h5>
                        ${address ? `<p>${this.escapeHtml(address)}</p>` : ''}
                        ${city ? `<p>${this.escapeHtml(city)}${district && district !== city ? `, ${this.escapeHtml(district)}` : ''}${countryName ? `, ${this.escapeHtml(countryName)}` : ''}</p>` : ''}
                    </div>
                    ` : ''}
                    
                    ${hoursWeekday ? `
                    <div style="margin-top: 15px;">
                        <h5><i class="fas fa-clock"></i> Hours</h5>
                        <p style="font-size: 13px;">${this.escapeHtml(hoursWeekday)}</p>
                        ${hoursSaturday ? `<p style="font-size: 13px;">${this.escapeHtml(hoursSaturday)}</p>` : ''}
                        ${hoursSunday ? `<p style="font-size: 13px;">${this.escapeHtml(hoursSunday)}</p>` : ''}
                    </div>
                    ` : ''}
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>${copyrightSafe}</p>
                <p style="margin-top: 10px; font-size: 14px;">
                    <i class="fas fa-shield-alt"></i> Safe Trading • 
                    <i class="fas fa-mobile-alt"></i> Mobile Friendly • 
                    <i class="fas fa-globe"></i> Hosted by <a href="https://promanaged-it.com" target="_blank" rel="noopener">ProManaged IT</a>
                </p>
            </div>
        `;

        this.bindFooterModalTriggers(supportLinks);
    }

    bindFooterModalTriggers(supportLinks) {
        const triggers = document.querySelectorAll('.footer-modal-trigger');
        if (!triggers.length) {
            this.closeFooterModal();
            return;
        }

        this.ensureFooterModal();

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                const index = Number(trigger.getAttribute('data-footer-modal-index'));
                const link = supportLinks[index];
                if (!link || link.type !== 'modal') {
                    return;
                }
                this.openFooterModal(link.modalTitle || link.label, link.modalContent || 'Details are not available right now.');
            });
        });
    }

    ensureFooterModal() {
        if (!document.getElementById('footerSupportModalStyles')) {
            const style = document.createElement('style');
            style.id = 'footerSupportModalStyles';
            style.textContent = `
                .footer-support-modal-backdrop {
                    position: fixed;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.6);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 16px;
                    z-index: 10000;
                }
                .footer-support-modal-backdrop.is-open {
                    display: flex;
                }
                .footer-support-modal-dialog {
                    width: min(560px, 100%);
                    max-height: 85vh;
                    overflow: auto;
                    background: #ffffff;
                    border-radius: 12px;
                    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.22);
                    padding: 20px;
                }
                .footer-support-modal-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 10px;
                    margin-bottom: 12px;
                }
                .footer-support-modal-title {
                    margin: 0;
                    font-size: 1.1rem;
                    color: #111827;
                }
                .footer-support-modal-close {
                    border: 0;
                    background: transparent;
                    font-size: 1.35rem;
                    line-height: 1;
                    cursor: pointer;
                    color: #4b5563;
                }
                .footer-support-modal-body {
                    color: #374151;
                    line-height: 1.65;
                    font-size: 0.97rem;
                    white-space: normal;
                }
            `;
            document.head.appendChild(style);
        }

        if (this.footerModalElement) {
            return;
        }

        const modal = document.createElement('div');
        modal.className = 'footer-support-modal-backdrop';
        modal.id = 'footerSupportModal';
        modal.innerHTML = `
            <div class="footer-support-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="footerSupportModalTitle">
                <div class="footer-support-modal-header">
                    <h5 class="footer-support-modal-title" id="footerSupportModalTitle"></h5>
                    <button type="button" class="footer-support-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="footer-support-modal-body" id="footerSupportModalBody"></div>
            </div>
        `;

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                this.closeFooterModal();
            }
        });

        const closeButton = modal.querySelector('.footer-support-modal-close');
        if (closeButton) {
            closeButton.addEventListener('click', () => this.closeFooterModal());
        }

        document.body.appendChild(modal);
        this.footerModalElement = modal;
    }

    openFooterModal(title, content) {
        this.ensureFooterModal();
        if (!this.footerModalElement) return;

        const titleElement = this.footerModalElement.querySelector('#footerSupportModalTitle');
        const bodyElement = this.footerModalElement.querySelector('#footerSupportModalBody');
        if (titleElement) {
            titleElement.textContent = title;
        }
        if (bodyElement) {
            bodyElement.innerHTML = this.escapeHtml(content).replace(/\n/g, '<br>');
        }

        this.footerModalElement.classList.add('is-open');
        document.addEventListener('keydown', this.handleEscapeKey);
    }

    closeFooterModal() {
        if (!this.footerModalElement) return;
        this.footerModalElement.classList.remove('is-open');
        document.removeEventListener('keydown', this.handleEscapeKey);
    }

    handleEscapeKey(event) {
        if (event.key === 'Escape') {
            this.closeFooterModal();
        }
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new FooterLoader();
    });
} else {
    // DOM already loaded
    new FooterLoader();
}

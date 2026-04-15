(function () {
    class SupportPages {
        constructor() {
            this.settings = {};
            this.init();
        }

        async init() {
            await this.loadSettings();
            this.populateContactData();
        }

        async loadSettings() {
            try {
                const response = await fetch(`${CONFIG.API_URL}?action=site_settings`, {
                    ...(CONFIG.USE_CREDENTIALS && { credentials: 'include' })
                });
                const data = await response.json();
                if (data && data.success) {
                    this.settings = {
                        ...(data.flat || {}),
                        ...((data.settings && data.settings.general) || {}),
                        ...((data.settings && data.settings.footer) || {}),
                        ...((data.settings && data.settings.contact) || {}),
                        ...((data.settings && data.settings.business) || {})
                    };
                }
            } catch (error) {
                this.settings = {};
            }
        }

        getValue(key, fallback = '') {
            const value = this.settings[key];
            if (value === undefined || value === null || String(value).trim() === '') {
                return fallback;
            }
            return String(value).trim();
        }

        applyText(selector, value) {
            document.querySelectorAll(selector).forEach((el) => {
                el.textContent = value;
            });
        }

        applyLink(selector, href, text) {
            document.querySelectorAll(selector).forEach((el) => {
                if (el.tagName === 'A') {
                    el.setAttribute('href', href);
                }
                el.textContent = text;
            });
        }

        populateContactData() {
            const supportEmail = this.getValue('contact_support_email', this.getValue('contact_email', 'support@motorlink.mw'));
            const mainEmail = this.getValue('contact_email', supportEmail);
            const phone = this.getValue('contact_phone', '+265 991 234 567');
            const whatsapp = this.getValue('contact_whatsapp', phone);
            const address = this.getValue('business_address', '');
            const city = this.getValue('business_city', '');
            const district = this.getValue('business_district', '');
            const weekdayHours = this.getValue('business_hours_weekday', 'Mon - Fri: 08:00 - 17:00');
            const saturdayHours = this.getValue('business_hours_saturday', 'Sat: 09:00 - 13:00');
            const sundayHours = this.getValue('business_hours_sunday', 'Sun: Closed');

            const telNumber = phone.replace(/\s+/g, '');
            const whatsappDigits = whatsapp.replace(/[^0-9]/g, '');
            const locationLine = [city, district && district !== city ? district : ''].filter(Boolean).join(', ');

            this.applyText('.js-main-email-text', mainEmail);
            this.applyLink('.js-main-email-link', `mailto:${mainEmail}`, mainEmail);

            this.applyText('.js-support-email-text', supportEmail);
            this.applyLink('.js-support-email-link', `mailto:${supportEmail}`, supportEmail);

            this.applyText('.js-phone-text', phone);
            this.applyLink('.js-phone-link', `tel:${telNumber}`, phone);

            this.applyText('.js-whatsapp-text', whatsapp);
            this.applyLink('.js-whatsapp-link', `https://wa.me/${whatsappDigits}`, whatsapp);

            this.applyText('.js-address-text', address || 'MotorLink Malawi');
            this.applyText('.js-location-text', locationLine || 'Malawi');
            this.applyText('.js-hours-weekday-text', weekdayHours);
            this.applyText('.js-hours-saturday-text', saturdayHours);
            this.applyText('.js-hours-sunday-text', sundayHours);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new SupportPages());
    } else {
        new SupportPages();
    }
})();

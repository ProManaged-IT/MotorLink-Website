/**
 * car-hire-booking.js
 * WhatsApp booking flow for car hire fleet vehicles.
 * Opens a modal, collects renter details + dates, POSTs to the API,
 * then prompts the customer to follow up with the owner on WhatsApp.
 */

(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────────
    let _modal       = null;    // DOM element
    let _booking     = {};      // current booking context
    let _submitting  = false;

    // ── API base ───────────────────────────────────────────────────────────────
    function _apiBase() {
        if (typeof CONFIG !== 'undefined' && CONFIG.API_BASE_URL) {
            return CONFIG.API_BASE_URL;
        }
        const h = window.location.hostname;
        return (h === 'localhost' || h === '127.0.0.1') ? 'proxy.php' : 'api.php';
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    function _esc(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function _fmt(num) {
        return Number(num || 0).toLocaleString();
    }

    function _today() {
        return new Date().toISOString().split('T')[0];
    }

    function _minEndDate(startVal) {
        if (!startVal) return _today();
        const d = new Date(startVal);
        d.setDate(d.getDate() + 1);
        return d.toISOString().split('T')[0];
    }

    // ── Build modal DOM ────────────────────────────────────────────────────────
    function _buildModal() {
        if (document.getElementById('waHireBookingModal')) {
            return document.getElementById('waHireBookingModal');
        }
        const el = document.createElement('div');
        el.id = 'waHireBookingModal';
        el.className = 'wabk-overlay';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-labelledby', 'wabkTitle');
        el.innerHTML = `
            <div class="wabk-sheet" id="wabkSheet">
                <!-- Header -->
                <div class="wabk-header">
                    <div class="wabk-header-icon"><i class="fab fa-whatsapp"></i></div>
                    <div class="wabk-header-text">
                        <h2 id="wabkTitle">Book via WhatsApp</h2>
                        <p id="wabkSubtitle">Fill in your details — we'll notify the owner instantly</p>
                    </div>
                    <button class="wabk-close" id="wabkCloseBtn" aria-label="Close booking form">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Vehicle Summary Banner -->
                <div class="wabk-vehicle-banner" id="wabkVehicleBanner">
                    <div class="wabk-vehicle-info">
                        <span class="wabk-vehicle-name" id="wabkVehicleName"></span>
                        <span class="wabk-vehicle-rate" id="wabkVehicleRate"></span>
                    </div>
                </div>

                <!-- Form -->
                <div class="wabk-body" id="wabkFormArea">
                    <form id="wabkForm" novalidate autocomplete="off">
                        <!-- Dates row -->
                        <div class="wabk-row">
                            <div class="wabk-field">
                                <label for="wabkStartDate"><i class="fas fa-calendar-check"></i> Pick-up Date</label>
                                <input type="date" id="wabkStartDate" name="start_date" class="wabk-input" required>
                            </div>
                            <div class="wabk-field">
                                <label for="wabkEndDate"><i class="fas fa-calendar-times"></i> Return Date</label>
                                <input type="date" id="wabkEndDate" name="end_date" class="wabk-input" required>
                            </div>
                        </div>

                        <!-- Duration + cost summary -->
                        <div class="wabk-cost-summary" id="wabkCostSummary" style="display:none;">
                            <div class="wabk-cost-row">
                                <span><i class="fas fa-clock"></i> Duration</span>
                                <strong id="wabkDuration">—</strong>
                            </div>
                            <div class="wabk-cost-row total">
                                <span><i class="fas fa-receipt"></i> Estimated Total</span>
                                <strong id="wabkTotal">—</strong>
                            </div>
                        </div>

                        <!-- Renter details -->
                        <div class="wabk-field">
                            <label for="wabkName"><i class="fas fa-user"></i> Your Name</label>
                            <input type="text" id="wabkName" name="renter_name" class="wabk-input"
                                   placeholder="Full name" required maxlength="150">
                        </div>
                        <div class="wabk-row">
                            <div class="wabk-field">
                                <label for="wabkPhone"><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="tel" id="wabkPhone" name="renter_phone" class="wabk-input"
                                       placeholder="+265 888 000 000" required maxlength="30">
                            </div>
                            <div class="wabk-field">
                                <label for="wabkWaPhone"><i class="fab fa-whatsapp"></i> WhatsApp (optional)</label>
                                <input type="tel" id="wabkWaPhone" name="renter_whatsapp" class="wabk-input"
                                       placeholder="Same as phone?" maxlength="30">
                            </div>
                        </div>
                        <div class="wabk-field">
                            <label for="wabkNotes"><i class="fas fa-comment-alt"></i> Special Requests (optional)</label>
                            <textarea id="wabkNotes" name="special_requests" class="wabk-input wabk-textarea"
                                      rows="2" placeholder="Airport pickup, child seat, driver needed…" maxlength="500"></textarea>
                        </div>

                        <!-- Error msg -->
                        <div class="wabk-error" id="wabkError" style="display:none;"></div>

                        <!-- Submit -->
                        <button type="submit" class="wabk-submit" id="wabkSubmitBtn">
                            <i class="fab fa-whatsapp"></i>
                            <span id="wabkSubmitLabel">Send Booking Request</span>
                        </button>
                    </form>
                </div>

                <!-- Success state (replaces form) -->
                <div class="wabk-success" id="wabkSuccess" style="display:none;">
                    <div class="wabk-success-icon"><i class="fas fa-check-circle"></i></div>
                    <h3>Booking Request Sent!</h3>
                    <p id="wabkSuccessMsg">Your request has been submitted. The car hire owner has been notified via WhatsApp.</p>
                    <div class="wabk-success-actions">
                        <a id="wabkDeepLink" class="wabk-btn-wa" href="#" target="_blank" rel="noopener" style="display:none;">
                            <i class="fab fa-whatsapp"></i> Open WhatsApp Chat with Owner
                        </a>
                        <button class="wabk-btn-secondary" onclick="window.carHireBooking.close()">
                            Close
                        </button>
                    </div>

                    <!-- Bot-like options panel -->
                    <div class="wabk-bot-hint">
                        <p class="wabk-bot-hint-label"><i class="fas fa-robot"></i> The owner will receive these reply options on WhatsApp:</p>
                        <div class="wabk-bot-options">
                            <div class="wabk-bot-option"><span class="opt-num">1</span> ✅ Accept &amp; confirm booking</div>
                            <div class="wabk-bot-option"><span class="opt-num">2</span> ❌ Decline booking</div>
                            <div class="wabk-bot-option"><span class="opt-num">3</span> 📅 Propose different dates</div>
                            <div class="wabk-bot-option"><span class="opt-num">4</span> 📞 Call you directly</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(el);
        _modal = el;
        _attachEvents(el);
        return el;
    }

    // ── Attach modal events ───────────────────────────────────────────────────
    function _attachEvents(el) {
        // Close on overlay click
        el.addEventListener('click', function (e) {
            if (e.target === el) window.carHireBooking.close();
        });
        // Close button
        document.getElementById('wabkCloseBtn').addEventListener('click', function () {
            window.carHireBooking.close();
        });
        // ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && el.classList.contains('wabk-visible')) {
                window.carHireBooking.close();
            }
        });
        // Start date change → update end date min + recalculate
        document.getElementById('wabkStartDate').addEventListener('change', function () {
            const endInput = document.getElementById('wabkEndDate');
            endInput.min = _minEndDate(this.value);
            if (endInput.value && endInput.value <= this.value) {
                endInput.value = _minEndDate(this.value);
            }
            _recalcCost();
        });
        document.getElementById('wabkEndDate').addEventListener('change', _recalcCost);
        // Form submit
        document.getElementById('wabkForm').addEventListener('submit', function (e) {
            e.preventDefault();
            _submitBooking();
        });
    }

    // ── Recalculate cost summary ───────────────────────────────────────────────
    function _recalcCost() {
        const startVal  = document.getElementById('wabkStartDate').value;
        const endVal    = document.getElementById('wabkEndDate').value;
        const summary   = document.getElementById('wabkCostSummary');
        const durEl     = document.getElementById('wabkDuration');
        const totalEl   = document.getElementById('wabkTotal');
        const currency  = (typeof CONFIG !== 'undefined' && CONFIG.CURRENCY_CODE) ? CONFIG.CURRENCY_CODE : 'MWK';

        if (!startVal || !endVal) { summary.style.display = 'none'; return; }

        const start = new Date(startVal);
        const end   = new Date(endVal);
        const days  = Math.round((end - start) / 86400000);

        if (days <= 0) { summary.style.display = 'none'; return; }

        const total = days * (_booking.dailyRate || 0);
        durEl.textContent   = days + ' day' + (days > 1 ? 's' : '');
        totalEl.textContent = currency + ' ' + _fmt(total);
        summary.style.display = 'flex';
    }

    // ── Submit booking ─────────────────────────────────────────────────────────
    function _submitBooking() {
        if (_submitting) return;

        const errEl  = document.getElementById('wabkError');
        errEl.style.display = 'none';

        const name  = document.getElementById('wabkName').value.trim();
        const phone = document.getElementById('wabkPhone').value.trim();
        const waNum = document.getElementById('wabkWaPhone').value.trim();
        const notes = document.getElementById('wabkNotes').value.trim();
        const start = document.getElementById('wabkStartDate').value;
        const end   = document.getElementById('wabkEndDate').value;

        if (!name)  { _showError('Please enter your name.'); return; }
        if (!phone) { _showError('Please enter your phone number.'); return; }
        if (!start) { _showError('Please select a pick-up date.'); return; }
        if (!end)   { _showError('Please select a return date.'); return; }
        if (end <= start) { _showError('Return date must be after pick-up date.'); return; }

        _submitting = true;
        const btn   = document.getElementById('wabkSubmitBtn');
        const label = document.getElementById('wabkSubmitLabel');
        btn.disabled   = true;
        label.textContent = 'Sending…';

        const payload = {
            company_id:       _booking.companyId,
            fleet_id:         _booking.fleetId,
            vehicle_name:     _booking.vehicleName,
            daily_rate:       _booking.dailyRate,
            renter_name:      name,
            renter_phone:     phone,
            renter_whatsapp:  waNum || null,
            start_date:       start,
            end_date:         end,
            special_requests: notes || null,
        };

        fetch(_apiBase() + '?action=car_hire_book_whatsapp', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            _submitting = false;
            btn.disabled   = false;
            label.textContent = 'Send Booking Request';

            if (!data.success) {
                _showError(data.error || data.message || 'Booking failed. Please try again.');
                return;
            }
            _showSuccess(data);
        })
        .catch(function (err) {
            _submitting = false;
            btn.disabled   = false;
            label.textContent = 'Send Booking Request';
            _showError('Network error. Please check your connection and try again.');
        });
    }

    function _showError(msg) {
        const errEl = document.getElementById('wabkError');
        errEl.textContent   = msg;
        errEl.style.display = 'block';
    }

    function _showSuccess(data) {
        document.getElementById('wabkFormArea').style.display  = 'none';
        document.getElementById('wabkSuccess').style.display   = 'block';

        let msgText;
        if (data.wa_sent) {
            msgText = 'Your request has been submitted and the owner has been notified via WhatsApp. You should hear back shortly.';
        } else {
            msgText = 'Your booking request (Ref #' + String(data.booking_id).padStart(6, '0') + ') has been submitted. '
                    + 'Open WhatsApp below to follow up with the owner directly.';
        }
        document.getElementById('wabkSuccessMsg').textContent = msgText;

        if (data.wa_deep_link) {
            const deepLink   = document.getElementById('wabkDeepLink');
            deepLink.href    = data.wa_deep_link;
            deepLink.style.display = 'inline-flex';
        }
    }

    // ── Public API ─────────────────────────────────────────────────────────────
    window.carHireBooking = {
        /**
         * Open the booking modal.
         * @param {number} fleetId
         * @param {number} companyId
         * @param {string} vehicleName
         * @param {number} dailyRate
         */
        open: function (fleetId, companyId, vehicleName, dailyRate) {
            _booking = { fleetId, companyId, vehicleName, dailyRate };
            _modal   = _buildModal();

            // Reset form state
            document.getElementById('wabkForm').reset();
            document.getElementById('wabkError').style.display   = 'none';
            document.getElementById('wabkFormArea').style.display = 'block';
            document.getElementById('wabkSuccess').style.display  = 'none';
            document.getElementById('wabkCostSummary').style.display = 'none';

            // Set date min constraints
            const today = _today();
            document.getElementById('wabkStartDate').min = today;
            document.getElementById('wabkEndDate').min   = _minEndDate(today);

            // Update vehicle banner
            document.getElementById('wabkVehicleName').textContent = vehicleName;
            const currency = (typeof CONFIG !== 'undefined' && CONFIG.CURRENCY_CODE) ? CONFIG.CURRENCY_CODE : 'MWK';
            document.getElementById('wabkVehicleRate').textContent = currency + ' ' + _fmt(dailyRate) + '/day';

            _modal.classList.add('wabk-visible');
            document.body.classList.add('wabk-body-lock');

            // Focus first field
            setTimeout(function () {
                const f = document.getElementById('wabkStartDate');
                if (f) f.focus();
            }, 80);
        },

        close: function () {
            if (_modal) {
                _modal.classList.remove('wabk-visible');
                document.body.classList.remove('wabk-body-lock');
            }
        },
    };
})();

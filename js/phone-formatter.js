/**
 * Phone Number Formatter
 * Formats phone numbers as user types using the site's configured dial code (CONFIG.PHONE_DIAL_CODE).
 */

function getPhoneDialCode() {
    return (window.CONFIG && typeof CONFIG.PHONE_DIAL_CODE === 'string')
        ? CONFIG.PHONE_DIAL_CODE.replace(/\D/g, '')
        : '';
}

function formatLocalPhone(input) {
    let value = input.value.replace(/\D/g, '');
    const dialCode = getPhoneDialCode();

    if (dialCode !== '') {
        // Strip leading 0 and add dial code, or add dial code if missing
        if (value.startsWith('0')) {
            value = dialCode + value.substring(1);
        } else if (!value.startsWith(dialCode) && value.length > 0) {
            value = dialCode + value;
        }

        // Limit to dial code length + 9 local digits
        value = value.substring(0, dialCode.length + 9);
    }

    // Format: +[dialCode] XXX XXX XXX or plain groups of 3
    let formatted = '';
    if (dialCode !== '' && value.length > 0) {
        formatted = '+' + value.substring(0, dialCode.length);
        const local = value.substring(dialCode.length);
        if (local.length > 0) {
            formatted += ' ' + local.substring(0, 3);
        }
        if (local.length > 3) {
            formatted += ' ' + local.substring(3, 6);
        }
        if (local.length > 6) {
            formatted += ' ' + local.substring(6, 9);
        }
    } else if (value.length > 0) {
        // No dial code configured — format in groups of 3
        for (let i = 0; i < value.length; i += 3) {
            if (i > 0) formatted += ' ';
            formatted += value.substring(i, Math.min(i + 3, value.length));
        }
    }

    input.value = formatted;
}

// Backward compatibility alias
const formatMalawiPhone = formatLocalPhone;

function setupPhoneFormatting() {
    const phoneInputs = document.querySelectorAll('input[type="tel"]');

    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            formatLocalPhone(this);
        });

        input.addEventListener('paste', function() {
            setTimeout(() => formatLocalPhone(this), 10);
        });

        if (!input.placeholder) {
            input.placeholder = '+XXX XXX XXX XXX';
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupPhoneFormatting);
} else {
    setupPhoneFormatting();
}

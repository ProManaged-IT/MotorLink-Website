/**
 * Suppress Chrome Extension Errors
 *
 * This script suppresses harmless console errors caused by browser extensions
 * trying to inject scripts or communicate with the page. These errors are:
 * "Unchecked runtime.lastError: Could not establish connection. Receiving end does not exist."
 *
 * These errors come from external browser extensions (not our code) and are harmless
 * but clutter the console. This script filters them out.
 */

(function() {
    'use strict';

    // Store original console methods
    const originalError = console.error;
    const originalWarn = console.warn;

    // List of error patterns to suppress
    const suppressPatterns = [
        /Could not establish connection\. Receiving end does not exist/i,
        /Unchecked runtime\.lastError/i,
        /Extension context invalidated/i,
        /chrome-extension:/i,
        /AbortError.*Transition was skipped/i,
        /Transition was skipped/i
    ];

    // Check if a message matches any suppress pattern
    function shouldSuppress(message) {
        const msgStr = String(message);
        return suppressPatterns.some(pattern => pattern.test(msgStr));
    }

    // Override console.error
    console.error = function(...args) {
        // Check if this is an extension error
        if (args.length > 0 && shouldSuppress(args[0])) {
            // Silently ignore extension errors
            return;
        }
        // Pass through all other errors
        originalError.apply(console, args);
    };

    // Override console.warn
    console.warn = function(...args) {
        // Check if this is an extension warning
        if (args.length > 0 && shouldSuppress(args[0])) {
            // Silently ignore extension warnings
            return;
        }
        // Pass through all other warnings
        originalWarn.apply(console, args);
    };

    // Suppress window error events from extensions
    const originalWindowError = window.onerror;
    window.onerror = function(message, source, lineno, colno, error) {
        // Suppress extension-related errors
        if (shouldSuppress(message) || (source && source.includes('chrome-extension://'))) {
            return true; // Suppress the error
        }
        // Pass through to original handler if it exists
        if (originalWindowError) {
            return originalWindowError.apply(this, arguments);
        }
        return false;
    };

    // Suppress unhandledrejection events from extensions
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && shouldSuppress(String(event.reason))) {
            event.preventDefault();
        }
    });

})();

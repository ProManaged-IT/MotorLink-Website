/**
 * AI Chatbot Loader - Adds chatbot widget to all pages
 * This script dynamically loads the chatbot widget on every page
 * Loads AFTER page is fully rendered to ensure page CSS loads first
 */

(function() {
    'use strict';
    
    // Function to load chatbot (called after page is ready)
    async function loadChatbot() {
        // Only load if not already loaded
        if (document.getElementById('aiCarChatWidget')) {
            return;
        }
        
        // Check authentication first - only show for logged-in users (same as script.js)
        try {
            const apiUrl = (typeof CONFIG !== 'undefined' && CONFIG.API_URL) ? CONFIG.API_URL : '/motorlink/api.php';
            const response = await fetch(`${apiUrl}?action=check_auth`, {
                credentials: 'include'
            });
            const data = await response.json();
            
            // Only proceed if user is authenticated
            if (!data.success || !data.authenticated) {
                // Guest user - don't load chatbot
                return;
            }
        } catch (error) {
            // If auth check fails, don't load chatbot
            console.error('Auth check error for chatbot:', error);
            return;
        }

        // Respect global AI chat enable/disable setting.
        try {
            const statusResponse = await fetch(`${apiUrl}?action=get_ai_chat_status`, {
                credentials: 'include'
            });
            const statusData = await statusResponse.json();
            if (!statusData.success || Number(statusData.enabled) !== 1) {
                return;
            }
        } catch (error) {
            // If status check fails, continue to avoid false negatives from transient network issues.
            console.warn('AI chat status check failed, continuing with loader:', error);
        }
        
        // User is authenticated - proceed to load chatbot
        // Load CSS with low priority to ensure page CSS loads first
        if (!document.querySelector('link[href*="ai-car-chat.css"]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'css/ai-car-chat.css';
            link.media = 'print'; // Load with low priority
            link.onload = function() {
                this.media = 'all'; // Switch to all media after load
            };
            document.head.appendChild(link);
        }
    
    // Create widget HTML
    const widgetHTML = `
        <div class="ai-car-chat-widget minimized" id="aiCarChatWidget">
            <div class="ai-chat-header" id="aiChatHeader">
                <div class="ai-chat-header-info">
                    <div class="ai-chat-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ai-chat-title">
                        <h3>MotorLink AI Assistant</h3>
                        <p>Your personal assistant</p>
                        <div class="ai-usage-indicator" id="aiUsageIndicator" style="font-size: 11px; margin-top: 6px; padding: 4px 8px; background: #f5f5f5; border-radius: 12px; display: inline-block;">
                            <span id="aiUsageText">Loading usage...</span>
                        </div>
                    </div>
                </div>
                <div class="ai-chat-header-actions">
                    <button class="ai-chat-header-btn ai-chat-header-minimise" id="aiChatMinimizeBtn" title="Minimise" aria-label="Minimise chat">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </div>
            <div class="ai-chat-body" id="aiChatBody" style="display: none;">
                <div class="ai-chat-messages" id="aiChatMessages">
                    <div class="ai-chat-welcome">
                        <div class="ai-chat-avatar-large">
                            <i class="fas fa-robot"></i>
                        </div>
                        <h3>Hello! I'm MotorLink AI Assistant</h3>
                        <p>I can help you with:</p>
                        <ul>
                            <li><i class="fas fa-check"></i> Car questions and specifications</li>
                            <li><i class="fas fa-check"></i> Finding vehicles on our website</li>
                            <li><i class="fas fa-check"></i> Managing your listings</li>
                            <li><i class="fas fa-check"></i> Garage, dealer, and car hire info</li>
                            <li><i class="fas fa-check"></i> General car advice</li>
                        </ul>
                        <p class="ai-chat-disclaimer">Just ask me anything!</p>
                    </div>
                </div>
                <div class="ai-chat-input-container">
                    <div class="ai-chat-input-wrapper">
                        <textarea 
                            id="aiChatInput" 
                            placeholder="Ask me anything..." 
                            rows="1"
                            maxlength="500"
                        ></textarea>
                        <button class="ai-chat-send-btn" id="aiChatSendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div class="ai-chat-char-count">
                        <span id="aiChatCharCount">0</span>/500
                    </div>
                </div>
            </div>
            <button class="ai-chat-minimized" id="aiChatMinimized" aria-label="Open AI chat">
                <span class="ai-min-icon" aria-hidden="true">
                    <i class="fas fa-comments"></i>
                </span>
                <span class="ai-min-label">AI Chat</span>
                <span class="ai-min-live" aria-hidden="true"></span>
            </button>
        </div>
    `;
    
    // Insert widget before closing body tag
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = widgetHTML;
    const widget = tempDiv.firstElementChild;
    document.body.appendChild(widget);
    // 'loaded' class is added by setupWidgetVisibility() in ai-car-chat.js
    // after auth check — prevents the chat from flashing open before auth resolves

    // Load the chatbot script if not already loaded
    if (!window.AICarChat && !document.querySelector('script[src*="ai-car-chat.js"]')) {
        const script = document.createElement('script');
        script.src = 'js/ai-car-chat.js';
        script.async = true;
        script.onload = function() {
            if (window.AICarChat) {
                new AICarChat();
            }
        };
        document.body.appendChild(script);
    } else if (window.AICarChat) {
        // Initialize if already loaded
        new AICarChat();
    }
    }
    
    // Load chatbot AFTER page is fully loaded and rendered
    // This ensures the page CSS loads first and the page looks good before the chatbot appears
    if (document.readyState === 'complete') {
        // Page already loaded, wait a bit for CSS to render, then load chatbot
        setTimeout(loadChatbot, 500);
    } else {
        // Wait for all resources (including CSS) to load before showing chatbot
        window.addEventListener('load', function() {
            // Additional delay to ensure page is fully rendered
            setTimeout(loadChatbot, 500);
        });
    }
})();


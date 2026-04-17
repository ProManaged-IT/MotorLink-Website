/**
 * MotorLink AI Assistant Chat Widget
 * Provides AI-powered assistance for car-related questions
 * Requires user authentication
 */

class AICarChat {
    constructor() {
        this.isMinimized = false;
        this.isOpen = false;
        this.conversationHistory = [];
        this.currentUser = null;
        this.isSending = false;
        this.retryCount = 0;
        this.maxRetries = 5; // Reasonable retry limit — stop after 5 attempts
        this.baseRetryDelay = 2000; // Start with 2 seconds
        this.maxRetryDelay = 15000; // Max 15 seconds between retries
        this.currentRetryTimeout = null;
        this.pendingMessage = null;
        this._lastDragWasMove = false;
        this.init();
    }

    async init() {
        await this.checkAuth();
        this.bindEvents();
        this.setupWidgetVisibility();
        this.restoreConversation(); // Restore chat across page navigations
        await this.loadUsageIndicator();
    }

    async checkAuth() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                headers: {
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
            } else {
                this.currentUser = null;
            }
        } catch (error) {
            console.error('Auth check error:', error);
            this.currentUser = null;
        }
    }

    /**
     * Save conversation to sessionStorage so it persists across page navigations
     */
    saveConversation() {
        try {
            sessionStorage.setItem('ai_chat_history', JSON.stringify(this.conversationHistory.slice(-20)));
            const messagesContainer = document.getElementById('aiChatMessages');
            if (messagesContainer) {
                sessionStorage.setItem('ai_chat_messages_html', messagesContainer.innerHTML);
            }
        } catch (e) { /* sessionStorage full or unavailable */ }
    }

    /**
     * Restore conversation from sessionStorage after page navigation
     */
    restoreConversation() {
        try {
            const savedHistory = sessionStorage.getItem('ai_chat_history');
            const savedHTML = sessionStorage.getItem('ai_chat_messages_html');
            if (savedHistory && savedHTML) {
                this.conversationHistory = JSON.parse(savedHistory);
                const messagesContainer = document.getElementById('aiChatMessages');
                if (messagesContainer && this.conversationHistory.length > 0) {
                    messagesContainer.innerHTML = savedHTML;
                    // Re-bind interactions on restored messages
                    messagesContainer.querySelectorAll('.ai-chat-message').forEach(msg => {
                        this.bindMessageInteractions(msg);
                    });
                }
            }
        } catch (e) { /* ignore parse errors */ }
    }

    setupWidgetVisibility() {
        const widget = document.getElementById('aiCarChatWidget');
        if (!widget) return;

        if (!this.currentUser) {
            // Keep widget invisible for guests — do NOT add 'loaded'
            widget.style.display = 'none';
        } else {
            // Show widget for logged-in users
            widget.style.display = 'block';
            // Check if dismissed this session
            if (sessionStorage.getItem('ai_chat_dismissed') === '1') {
                widget.classList.add('dismissed');
                widget.classList.add('loaded');
                // Create restore button
                if (!document.getElementById('aiChatRestore')) {
                    const restoreBtn = document.createElement('button');
                    restoreBtn.id = 'aiChatRestore';
                    restoreBtn.className = 'ai-chat-restore';
                    restoreBtn.title = 'Show AI Assistant';
                    restoreBtn.innerHTML = '<i class="fas fa-robot"></i>';
                    restoreBtn.addEventListener('click', () => this.restoreChat());
                    document.body.appendChild(restoreBtn);
                }
                return;
            }
            // Ensure minimized state (widget HTML already has it, but sync JS state)
            const chatBody = document.getElementById('aiChatBody');
            if (chatBody) chatBody.style.display = 'none';
            widget.classList.add('minimized'); // idempotent — pre-applied in HTML template
            this.isMinimized = true;
            this.isOpen = false;
            // Reveal only after minimized state is confirmed
            widget.classList.add('loaded');

            // Restore last drag position (within viewport)
            const savedPos = sessionStorage.getItem('ai_chat_pos');
            if (savedPos) {
                try {
                    const { left, top } = JSON.parse(savedPos);
                    const vw = window.innerWidth;
                    const vh = window.innerHeight;
                    const safeLeft = Math.max(0, Math.min(left, vw - 80));
                    const safeTop  = Math.max(0, Math.min(top,  vh - 60));
                    widget.style.bottom = 'auto';
                    widget.style.right  = 'auto';
                    widget.style.left   = safeLeft + 'px';
                    widget.style.top    = safeTop  + 'px';
                } catch (e) { /* ignore bad stored value */ }
            }
        }
    }

    bindEvents() {
        const chatHeader    = document.getElementById('aiChatHeader');
        const minimizeBtn   = document.getElementById('aiChatMinimizeBtn');
        const chatMinimized = document.getElementById('aiChatMinimized');
        const chatInput     = document.getElementById('aiChatInput');
        const chatSendBtn   = document.getElementById('aiChatSendBtn');

        // Header click → toggle open/minimise (skip when drag moved the widget or a header button was clicked)
        if (chatHeader) {
            chatHeader.addEventListener('click', (e) => {
                if (this._lastDragWasMove) return;
                if (e.target.closest('.ai-chat-header-actions')) return;
                this.toggleChat();
            });
        }

        // Chevron-down button → minimise
        if (minimizeBtn) {
            minimizeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.minimizeChat();
            });
        }

        // FAB bubble → open chat
        if (chatMinimized) {
            chatMinimized.addEventListener('click', (e) => {
                if (this._fabDragWasMove) return;
                e.stopPropagation();
                e.preventDefault();
                this.openChat();
            });
        }

        if (chatSendBtn) {
            chatSendBtn.addEventListener('click', () => {
                this.sendMessage(0, 'button');
            });
        }

        if (chatInput) {
            chatInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage(0, 'enter');
                }
            });

            chatInput.addEventListener('input', (e) => {
                this.updateCharCount(e.target.value.length);
                this.autoResizeTextarea(e.target);
            });
        }

        this.ensureInputStatusElement();
        this.initDrag();
    }

    initDrag() {
        const widget = document.getElementById('aiCarChatWidget');
        const header = document.getElementById('aiChatHeader');
        const fabBtn = document.getElementById('aiChatMinimized');
        if (!widget || !header) return;

        let dragging  = false;
        let hasMoved  = false;
        let fromFab   = false;
        let startX, startY, startLeft, startTop;

        const begin = (e, isFab) => {
            if (e.button !== undefined && e.button !== 0) return;
            if (!isFab && e.target.closest('.ai-chat-header-actions')) return;
            // FAB drag only on mobile/tablet (≤1024px)
            if (isFab && window.innerWidth > 1024) return;

            dragging = true;
            hasMoved = false;
            fromFab  = isFab;

            const rect = widget.getBoundingClientRect();
            startLeft = rect.left;
            startTop  = rect.top;
            startX    = e.clientX;
            startY    = e.clientY;

            widget.style.bottom = 'auto';
            widget.style.right  = 'auto';
            widget.style.left   = startLeft + 'px';
            widget.style.top    = startTop  + 'px';
            widget.classList.add('dragging');
            e.currentTarget.setPointerCapture(e.pointerId);
        };

        const move = (e) => {
            if (!dragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) hasMoved = true;
            if (!hasMoved) return;

            const vw = window.innerWidth;
            const vh = window.innerHeight;
            let newLeft = Math.max(0, Math.min(startLeft + dx, vw - widget.offsetWidth));
            let newTop  = Math.max(0, Math.min(startTop  + dy, vh - widget.offsetHeight));

            widget.style.left = newLeft + 'px';
            widget.style.top  = newTop  + 'px';
        };

        const end = () => {
            if (!dragging) return;
            dragging = false;
            widget.classList.remove('dragging');

            if (hasMoved) {
                const key = fromFab ? 'ai_fab_pos' : 'ai_chat_pos';
                sessionStorage.setItem(key, JSON.stringify({
                    left: parseInt(widget.style.left),
                    top:  parseInt(widget.style.top)
                }));
                if (fromFab) {
                    this._fabDragWasMove = true;
                    setTimeout(() => { this._fabDragWasMove = false; }, 150);
                } else {
                    this._lastDragWasMove = true;
                    setTimeout(() => { this._lastDragWasMove = false; }, 100);
                }
            }
            fromFab = false;
        };

        // Header drag (all screen sizes)
        header.addEventListener('pointerdown',   (e) => begin(e, false));
        header.addEventListener('pointermove',   move);
        header.addEventListener('pointerup',     end);
        header.addEventListener('pointercancel', end);

        // FAB drag (mobile/tablet only — guarded inside begin)
        if (fabBtn) {
            fabBtn.addEventListener('pointerdown',   (e) => begin(e, true));
            fabBtn.addEventListener('pointermove',   move);
            fabBtn.addEventListener('pointerup',     end);
            fabBtn.addEventListener('pointercancel', end);
        }

        // Restore saved header-drag position on reload
        const savedPos = sessionStorage.getItem('ai_chat_pos');
        if (savedPos) {
            try {
                const pos = JSON.parse(savedPos);
                widget.style.left   = pos.left + 'px';
                widget.style.top    = pos.top  + 'px';
                widget.style.bottom = 'auto';
                widget.style.right  = 'auto';
            } catch (_) {}
        }
    }

    toggleChat() {
        if (this.isMinimized) {
            this.openChat();
        } else {
            this.minimizeChat();
        }
    }

    openChat() {
        const chatBody     = document.getElementById('aiChatBody');
        const widget       = document.getElementById('aiCarChatWidget');
        const minimizedBtn = document.getElementById('aiChatMinimized');

        if (chatBody && widget) {
            // Always open at CSS default position (bottom-left)
            widget.style.left   = '';
            widget.style.top    = '';
            widget.style.bottom = '';
            widget.style.right  = '';

            chatBody.style.display = 'flex';
            widget.classList.remove('minimized');
            if (minimizedBtn) minimizedBtn.style.display = 'none';
            this.isMinimized = false;
            this.isOpen = true;

            const chatInput = document.getElementById('aiChatInput');
            if (chatInput) setTimeout(() => chatInput.focus(), 100);
        }
    }

    minimizeChat() {
        const chatBody    = document.getElementById('aiChatBody');
        const widget      = document.getElementById('aiCarChatWidget');
        const minimizedBtn = document.getElementById('aiChatMinimized');

        if (chatBody && widget) {
            chatBody.style.display = 'none';
            widget.classList.add('minimized');
            if (minimizedBtn) minimizedBtn.style.display = 'flex';
            this.isMinimized = true;
            this.isOpen = false;
        }
    }

    dismissChat() {
        const widget = document.getElementById('aiCarChatWidget');
        if (widget) {
            widget.classList.add('dismissed');
            sessionStorage.setItem('ai_chat_dismissed', '1');
            // Create restore button
            if (!document.getElementById('aiChatRestore')) {
                const restoreBtn = document.createElement('button');
                restoreBtn.id = 'aiChatRestore';
                restoreBtn.className = 'ai-chat-restore';
                restoreBtn.title = 'Show AI Assistant';
                restoreBtn.innerHTML = '<i class="fas fa-robot"></i>';
                restoreBtn.addEventListener('click', () => this.restoreChat());
                document.body.appendChild(restoreBtn);
            }
        }
    }

    restoreChat() {
        const widget = document.getElementById('aiCarChatWidget');
        if (widget) {
            widget.classList.remove('dismissed');
            sessionStorage.removeItem('ai_chat_dismissed');
        }
        const restoreBtn = document.getElementById('aiChatRestore');
        if (restoreBtn) restoreBtn.remove();
    }

    updateCharCount(count) {
        const charCount = document.getElementById('aiChatCharCount');
        if (charCount) {
            charCount.textContent = count;
            if (count > 450) {
                charCount.style.color = '#f44336';
            } else if (count > 400) {
                charCount.style.color = '#ff9800';
            } else {
                charCount.style.color = '#999';
            }
        }
    }

    autoResizeTextarea(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
    }

    ensureInputStatusElement() {
        const inputContainer = document.querySelector('.ai-chat-input-container');
        if (!inputContainer) return null;

        let status = document.getElementById('aiChatInputStatus');
        if (status) return status;

        status = document.createElement('div');
        status.id = 'aiChatInputStatus';
        status.className = 'ai-chat-input-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;
        status.innerHTML = `
            <span class="ai-chat-input-status-dots" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
            <span class="ai-chat-input-status-text">Sending...</span>
        `;

        const charCount = inputContainer.querySelector('.ai-chat-char-count');
        if (charCount) {
            inputContainer.insertBefore(status, charCount);
        } else {
            inputContainer.appendChild(status);
        }

        return status;
    }

    setInputSendingState(isSending, retryAttempt = 0) {
        const inputContainer = document.querySelector('.ai-chat-input-container');
        const status = this.ensureInputStatusElement();
        if (!inputContainer || !status) return;

        if (isSending) {
            inputContainer.classList.add('ai-chat-input-sending');
            const label = retryAttempt > 0
                ? `Reconnecting (${Math.min(retryAttempt, this.maxRetries)}/${this.maxRetries})...`
                : 'Sending...';
            const textNode = status.querySelector('.ai-chat-input-status-text');
            if (textNode) {
                textNode.textContent = label;
            }
            status.hidden = false;
            return;
        }

        inputContainer.classList.remove('ai-chat-input-sending');
        status.hidden = true;
    }

    resetInputSendState(input, sendBtn) {
        this.isSending = false;
        input.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        this.setInputSendingState(false);
    }

    async sendMessage(retryAttempt = 0, triggerSource = 'button') {
        // Check authentication before sending
        if (!this.currentUser) {
            this.showError('Please log in to use MotorLink AI Assistant.');
            const login = confirm('You need to be logged in to use MotorLink AI Assistant. Would you like to go to the login page?');
            if (login) {
                window.location.href = 'login.html?redirect=' + encodeURIComponent(window.location.pathname);
            }
            return;
        }

        const input = document.getElementById('aiChatInput');
        const sendBtn = document.getElementById('aiChatSendBtn');

        if (!input) return;

        const inputMessage = input.value.trim();
        const message = retryAttempt > 0 ? (this.pendingMessage || inputMessage) : inputMessage;

        if (!message) return;

        // Prevent multiple simultaneous sends (unless it's a retry)
        if (this.isSending && retryAttempt === 0) {
            return;
        }
        
        // Clear any existing retry timeout when starting a new send
        if (this.currentRetryTimeout && retryAttempt === 0) {
            clearTimeout(this.currentRetryTimeout);
            this.currentRetryTimeout = null;
        }

        // Only clear input and add to UI on first attempt
        if (retryAttempt === 0) {
            this.pendingMessage = message;
            // Clear input
            input.value = '';
            this.updateCharCount(0);
            this.autoResizeTextarea(input);

            // Add user message to UI
            this.addMessage('user', message);

            // Add to conversation history
            this.conversationHistory.push({ role: 'user', content: message });
        }

        // Disable input while sending
        input.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        this.isSending = true;
        this.setInputSendingState(true, retryAttempt);

        // Show compact in-chat typing indicator
        this.showTypingIndicator();

        try {
            const controller = new AbortController();
            // Increase timeout for retries to give more time
            const timeoutDuration = retryAttempt > 0 ? 90000 : 60000; // 90s for retries, 60s for initial
            const timeoutId = setTimeout(() => controller.abort('Request timeout'), timeoutDuration);

            const response = await fetch(`${CONFIG.API_URL}?action=ai_car_chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // Chatbot messages should never trigger the full-page transition loader.
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include',
                signal: controller.signal,
                body: JSON.stringify({
                    message: message,
                    conversation_history: this.conversationHistory.slice(-10) // Last 10 messages for context
                })
            });

            clearTimeout(timeoutId);

            const data = await response.json();

            this.hideTypingIndicator();

            if (data.success && data.response) {
                this.resetInputSendState(input, sendBtn);
                this.retryCount = 0;
                this.pendingMessage = null;

                // Check if this is a search result
                if (data.search_results && data.search_results.length > 0) {
                    // Car listings search results (for sale)
                    this.addMessage('ai', data.response, data.search_results, data.total_results);
                } else if (data.car_hire_companies && data.car_hire_companies.length > 0) {
                    // Car hire search results
                    this.addMessage('ai', data.response, null, data.total_results, null, data.car_hire_companies);
                } else if (data.garages && data.garages.length > 0) {
                    // Garage search results
                    this.addMessage('ai', data.response, null, data.total_results, data.garages);
                } else {
                    // Regular AI response
                    this.addMessage('ai', data.response);
                }

                // Add to conversation history
                this.conversationHistory.push({ role: 'assistant', content: data.response });
                
                // Update usage indicator after successful message
                if (this.updateUsageAfterMessage) {
                    await this.loadUsageIndicator();
                }
            } else {
                // Check if it's an authentication error
                if (response.status === 401 || (data.message && data.message.includes('Authentication required'))) {
                    this.currentUser = null;
                    this.setupWidgetVisibility();
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.showError('Your session has expired. Please log in again.');
                    setTimeout(() => {
                        window.location.href = 'login.html?redirect=' + encodeURIComponent(window.location.pathname);
                    }, 2000);
                } else if (response.status === 403) {
                    // User's AI chat is disabled - show the reason
                    const reason = data.message || 'Your access to MotorLink AI Assistant has been temporarily disabled. Please contact support for assistance.';
                    this.showError(reason, true); // true = persistent error, don't auto-hide
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                } else if (response.status === 429) {
                    // Rate limit exceeded - don't retry, show clear message
                    const rateLimitMessage = data.message || 'You\'ve reached your rate limit for AI chat requests. Please wait a bit before trying again.';
                    this.showError(rateLimitMessage, true); // true = persistent error, don't auto-hide
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.retryCount = 0; // Reset retry count
                } else {
                    // Handle API errors with retry logic
                    const isRetryableError = response.status === 503 || response.status >= 500;
                    
                    if (isRetryableError) {
                        // For retryable errors, keep retrying with exponential backoff
                        if (retryAttempt < this.maxRetries) {
                            // Exponential backoff with jitter: baseDelay * 2^attempt + random(0-1000ms)
                            const exponentialDelay = this.baseRetryDelay * Math.pow(2, Math.min(retryAttempt, 5)); // Cap exponential growth
                            const jitter = Math.random() * 1000;
                            const delay = Math.min(exponentialDelay + jitter, this.maxRetryDelay);
                            
                            const retryMessage = retryAttempt === 0 
                                ? `Service temporarily unavailable. Retrying automatically...`
                                : `Still retrying... (attempt ${retryAttempt + 1}/${this.maxRetries})`;
                            
                            this.showError(retryMessage);
                            this.setInputSendingState(true, retryAttempt + 1);
                            
                            // Clear any existing timeout
                            if (this.currentRetryTimeout) {
                                clearTimeout(this.currentRetryTimeout);
                            }
                            
                            // Retry after delay
                            this.currentRetryTimeout = setTimeout(() => {
                                this.currentRetryTimeout = null;
                                this.sendMessage(retryAttempt + 1, triggerSource);
                            }, delay);
                            
                            return; // Don't re-enable input, keep it disabled during retries
                        } else {
                            // Max retries reached — stop retrying and re-enable input
                            this.resetInputSendState(input, sendBtn);
                            this.pendingMessage = null;
                            this.retryCount = 0;
                            this.showError('Service is temporarily unavailable. Please try again later.');
                        }
                    } else {
                        // Handle non-retryable errors - re-enable input
                        if (this.currentRetryTimeout) {
                            clearTimeout(this.currentRetryTimeout);
                            this.currentRetryTimeout = null;
                        }
                        this.resetInputSendState(input, sendBtn);
                        this.pendingMessage = null;
                        const errorMessage = data.message || 'Failed to get response from AI Assistant. Please try again.';
                        this.showError(errorMessage);
                        this.retryCount = 0;
                    }
                }
            }
        } catch (error) {
            this.hideTypingIndicator();
            console.error('AI Chat Error:', error);

            // Check if it's a timeout
            if (error.name === 'AbortError') {
                if (retryAttempt < this.maxRetries) {
                    const exponentialDelay = this.baseRetryDelay * Math.pow(2, Math.min(retryAttempt, 5));
                    const jitter = Math.random() * 1000;
                    const delay = Math.min(exponentialDelay + jitter, this.maxRetryDelay);
                    
                    this.showError(`Request timed out. Retrying automatically... (attempt ${retryAttempt + 1}/${this.maxRetries})`);
                    this.setInputSendingState(true, retryAttempt + 1);
                    
                    if (this.currentRetryTimeout) {
                        clearTimeout(this.currentRetryTimeout);
                    }
                    
                    this.currentRetryTimeout = setTimeout(() => {
                        this.currentRetryTimeout = null;
                        this.sendMessage(retryAttempt + 1, triggerSource);
                    }, delay);
                    return;
                } else {
                    // Max retries reached — stop and re-enable input
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.retryCount = 0;
                    this.showError('Request timed out. Please check your connection and try again.');
                }
            }

            // Check if it's a network error
            if (error.name === 'TypeError' || error.name === 'NetworkError' || error.message.includes('fetch') || error.message.includes('Failed to fetch')) {
                if (retryAttempt < this.maxRetries) {
                    // Retry network errors with exponential backoff
                    const exponentialDelay = this.baseRetryDelay * Math.pow(2, Math.min(retryAttempt, 5));
                    const jitter = Math.random() * 1000;
                    const delay = Math.min(exponentialDelay + jitter, this.maxRetryDelay);
                    
                    this.showError(`Network error. Retrying automatically... (attempt ${retryAttempt + 1}/${this.maxRetries})`);
                    this.setInputSendingState(true, retryAttempt + 1);
                    
                    if (this.currentRetryTimeout) {
                        clearTimeout(this.currentRetryTimeout);
                    }
                    
                    this.currentRetryTimeout = setTimeout(() => {
                        this.currentRetryTimeout = null;
                        this.sendMessage(retryAttempt + 1, triggerSource);
                    }, delay);
                    return;
                } else {
                    // Max retries reached — stop and re-enable input
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.retryCount = 0;
                    this.showError('Network error. Please check your connection and try again.');
                }
            }

            // Other errors - don't retry, just show error
            // Clear retry timeout if exists
            if (this.currentRetryTimeout) {
                clearTimeout(this.currentRetryTimeout);
                this.currentRetryTimeout = null;
            }

            this.resetInputSendState(input, sendBtn);
            this.pendingMessage = null;
            this.showError('An unexpected error occurred. Please try again.');
            this.retryCount = 0;
        }
    }
    
    /**
     * Cancel any pending retries
     */
    cancelRetries() {
        if (this.currentRetryTimeout) {
            clearTimeout(this.currentRetryTimeout);
            this.currentRetryTimeout = null;
        }
        this.retryCount = 0;
    }

    addMessage(role, content, searchResults = null, totalResults = 0, garages = null, carHireCompanies = null) {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer) return;

        // Remove welcome message if present
        const welcome = messagesContainer.querySelector('.ai-chat-welcome');
        if (welcome) {
            welcome.remove();
        }

        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-chat-message ${role}`;

        const time = new Date().toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });

        const avatarIcon = role === 'user' ? 'fas fa-user' : 'fas fa-robot';

        // Format content with markdown links and line breaks
        let formattedContent = this.formatMessageContent(content);

        if (garages && garages.length > 0) {
            formattedContent += this.buildGarageResultsMarkup(garages, totalResults);
        }

        if (carHireCompanies && carHireCompanies.length > 0) {
            formattedContent += this.buildCarHireResultsMarkup(carHireCompanies, totalResults);
        }

        if (searchResults && searchResults.length > 0) {
            formattedContent += this.buildListingsResultsMarkup(searchResults, totalResults);
        }

        const feedbackId = Date.now();

        messageDiv.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="${avatarIcon}"></i>
            </div>
            <div class="ai-chat-message-content">
                <div class="ai-chat-message-bubble">${formattedContent}</div>
                <div class="ai-chat-message-time">${time}</div>
                ${role === 'ai' ? this.buildFeedbackMarkup(feedbackId) : ''}
            </div>
        `;

        messagesContainer.appendChild(messageDiv);

        this.bindMessageInteractions(messageDiv);
        
        this.scrollToBottom();
        this.saveConversation();
    }

    buildFeedbackMarkup(messageId) {
        return `
            <div class="ai-chat-feedback">
                <span class="ai-chat-feedback-label">Was this helpful?</span>
                <button class="ai-chat-feedback-btn" data-feedback="helpful" data-message-id="${messageId}" type="button">
                    <i class="fas fa-thumbs-up"></i> Yes
                </button>
                <button class="ai-chat-feedback-btn" data-feedback="not-helpful" data-message-id="${messageId}" type="button">
                    <i class="fas fa-thumbs-down"></i> No
                </button>
            </div>
        `;
    }

    buildGarageResultsMarkup(garages, totalResults) {
        let html = '<section class="ai-chat-results" data-results-type="garage">';
        html += `<div class="ai-chat-results-header">Found ${totalResults} garage${totalResults > 1 ? 's' : ''}</div>`;
        html += '<div class="ai-chat-results-list">';

        garages.slice(0, 5).forEach((garage) => {
            const garageId = garage.id || '';
            const garageName = this.escapeHtml(garage.name || 'Garage');
            const location = garage.location_name ? this.escapeHtml(garage.location_name) : '';
            const phone = garage.phone ? this.escapeHtml(garage.phone) : '';

            let servicesList = [];
            if (garage.services_list) {
                servicesList = Array.isArray(garage.services_list) ? garage.services_list : [];
            } else if (garage.services) {
                try {
                    servicesList = typeof garage.services === 'string' ? JSON.parse(garage.services) : garage.services;
                    if (!Array.isArray(servicesList)) servicesList = [];
                } catch (e) {
                    servicesList = [];
                }
            }

            const servicesStr = servicesList.slice(0, 3).map((s) => this.escapeHtml(s)).join(', ');

            html += `
                <article class="ai-chat-result-card ai-chat-search-result-item" data-card-type="garage" data-garage-id="${garageId}" tabindex="0" role="button" aria-label="Open ${garageName}">
                    <div class="ai-chat-result-title">${garageName}</div>
                    <div class="ai-chat-result-meta">
                        ${location ? `<span>📍 ${location}</span>` : ''}
                        ${phone ? `<span>📞 ${phone}</span>` : ''}
                        ${servicesStr ? `<span>🔧 ${servicesStr}${servicesList.length > 3 ? ' and more' : ''}</span>` : ''}
                    </div>
                </article>
            `;
        });

        html += '</div>';

        if (totalResults > 5) {
            html += `<button type="button" class="ai-chat-view-more" data-view-more="garages">View all ${totalResults} garages on website</button>`;
        }

        html += '</section>';
        return html;
    }

    buildCarHireResultsMarkup(companies, totalResults) {
        let html = '<section class="ai-chat-results" data-results-type="car-hire">';
        html += `<div class="ai-chat-results-header">Found ${totalResults} car hire compan${totalResults > 1 ? 'ies' : 'y'}</div>`;
        html += '<div class="ai-chat-results-list">';

        companies.slice(0, 5).forEach((company) => {
            const companyId = company.id || '';
            const companyName = this.escapeHtml(company.business_name || 'Car Hire Company');
            const location = company.location_name ? this.escapeHtml(company.location_name) : '';
            const phone = company.phone ? this.escapeHtml(company.phone) : '';

            let vehiclesText = '';
            if (company.matching_vehicles && company.matching_vehicles.length > 0) {
                const vehicleCount = company.matching_vehicles.length;
                vehiclesText = `${vehicleCount} vehicle${vehicleCount > 1 ? 's' : ''} available`;
                const rate = parseInt(company.matching_vehicles[0].daily_rate || 0, 10);
                if (!Number.isNaN(rate) && rate > 0) {
                    vehiclesText += ` from MWK ${rate.toLocaleString()}/day`;
                }
            } else if (company.total_vehicles > 0) {
                vehiclesText = `${company.total_vehicles} vehicle${company.total_vehicles > 1 ? 's' : ''} available`;
            }

            html += `
                <article class="ai-chat-result-card ai-chat-search-result-item" data-card-type="car-hire" data-car-hire-id="${companyId}" tabindex="0" role="button" aria-label="Open ${companyName}">
                    <div class="ai-chat-result-title">${companyName}</div>
                    <div class="ai-chat-result-meta">
                        ${location ? `<span>📍 ${location}</span>` : ''}
                        ${phone ? `<span>📞 ${phone}</span>` : ''}
                        ${vehiclesText ? `<span>🚗 ${this.escapeHtml(vehiclesText)}</span>` : ''}
                    </div>
                </article>
            `;
        });

        html += '</div>';

        if (totalResults > 5) {
            html += `<button type="button" class="ai-chat-view-more" data-view-more="car-hire">View all ${totalResults} car hire companies on website</button>`;
        }

        html += '</section>';
        return html;
    }

    buildListingsResultsMarkup(searchResults, totalResults) {
        const first = searchResults[0] || {};
        const firstMakeId = first.make_id || '';
        const firstModelId = first.model_id || '';

        let html = '<section class="ai-chat-results" data-results-type="listings">';
        html += `<div class="ai-chat-results-header">Found ${totalResults} result${totalResults > 1 ? 's' : ''}</div>`;
        html += '<div class="ai-chat-results-list">';

        searchResults.slice(0, 5).forEach((listing) => {
            const listingId = listing.id || '';
            const makeName = this.escapeHtml(listing.make_name || 'Vehicle');
            const modelName = this.escapeHtml(listing.model_name || 'Model');
            const year = this.escapeHtml(String(listing.year || 'N/A'));
            const priceValue = parseInt(listing.price || 0, 10);
            const price = !Number.isNaN(priceValue) && priceValue > 0 ? priceValue.toLocaleString() : 'Price on request';
            const location = listing.location_name ? this.escapeHtml(listing.location_name) : '';

            html += `
                <article class="ai-chat-result-card ai-chat-search-result-item" data-card-type="listing" data-listing-id="${listingId}" tabindex="0" role="button" aria-label="Open ${makeName} ${modelName}">
                    <div class="ai-chat-result-title">${makeName} ${modelName} (${year})</div>
                    <div class="ai-chat-result-price">MWK ${price}</div>
                    <div class="ai-chat-result-meta">
                        ${location ? `<span>📍 ${location}</span>` : ''}
                    </div>
                    <div class="ai-chat-result-actions">
                        <button type="button" class="ai-chat-action-btn ai-chat-action-btn-view" data-action="view" data-listing-id="${listingId}">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="ai-chat-action-btn ai-chat-action-btn-save" data-action="save" data-listing-id="${listingId}">
                            <i class="fas fa-bookmark"></i> Save
                        </button>
                        <button type="button" class="ai-chat-action-btn ai-chat-action-btn-contact" data-action="contact" data-listing-id="${listingId}">
                            <i class="fas fa-phone"></i> Contact
                        </button>
                    </div>
                </article>
            `;
        });

        html += '</div>';

        if (totalResults > 5) {
            html += `<button type="button" class="ai-chat-view-more" data-view-more="listings" data-make-id="${firstMakeId}" data-model-id="${firstModelId}">View all ${totalResults} results on website</button>`;
        }

        html += '</section>';
        return html;
    }

    bindMessageInteractions(messageDiv) {
        messageDiv.addEventListener('click', (e) => {
            const actionBtn = e.target.closest('.ai-chat-action-btn');
            if (actionBtn) {
                e.stopPropagation();
                const action = actionBtn.getAttribute('data-action');
                const listingId = actionBtn.getAttribute('data-listing-id');
                this.handleQuickAction(action, listingId);
                return;
            }

            const feedbackBtn = e.target.closest('.ai-chat-feedback-btn');
            if (feedbackBtn) {
                e.stopPropagation();
                const feedback = feedbackBtn.getAttribute('data-feedback');
                const messageId = feedbackBtn.getAttribute('data-message-id');
                this.handleFeedback(feedback, messageId, messageDiv);
                return;
            }

            const viewMoreBtn = e.target.closest('.ai-chat-view-more');
            if (viewMoreBtn) {
                const viewMoreType = viewMoreBtn.getAttribute('data-view-more');
                if (viewMoreType === 'garages') {
                    window.open('garages.html', '_blank');
                } else if (viewMoreType === 'car-hire') {
                    window.open('car-hire.html', '_blank');
                } else if (viewMoreType === 'listings') {
                    const params = new URLSearchParams();
                    const makeId = viewMoreBtn.getAttribute('data-make-id');
                    const modelId = viewMoreBtn.getAttribute('data-model-id');
                    if (makeId) params.append('make', makeId);
                    if (modelId) params.append('model', modelId);
                    const query = params.toString();
                    window.open(`index.html${query ? `?${query}` : ''}`, '_blank');
                }
                return;
            }

            const card = e.target.closest('.ai-chat-search-result-item');
            if (!card || e.target.closest('.ai-chat-action-btn')) {
                return;
            }

            const type = card.getAttribute('data-card-type');
            if (type === 'listing') {
                const listingId = card.getAttribute('data-listing-id');
                if (listingId) {
                    window.open(`car.html?id=${listingId}`, '_blank');
                }
            } else if (type === 'garage') {
                const garageId = card.getAttribute('data-garage-id');
                if (garageId) {
                    window.open(`garages.html?id=${garageId}`, '_blank');
                }
            } else if (type === 'car-hire') {
                const companyId = card.getAttribute('data-car-hire-id');
                if (companyId) {
                    window.open(`car-hire-company.html?id=${companyId}`, '_blank');
                }
            }
        });

        messageDiv.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }

            const card = e.target.closest('.ai-chat-search-result-item');
            if (!card) {
                return;
            }

            e.preventDefault();
            card.click();
        });
    }

    showLoading() {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer) return;

        const loadingDiv = document.createElement('div');
        loadingDiv.className = 'ai-chat-message ai';
        loadingDiv.id = 'aiChatLoading';
        loadingDiv.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-chat-message-content">
                <div class="ai-chat-message-bubble">
                    <div class="ai-chat-loading">
                        <div class="ai-chat-loading-dot"></div>
                        <div class="ai-chat-loading-dot"></div>
                        <div class="ai-chat-loading-dot"></div>
                    </div>
                </div>
            </div>
        `;

        messagesContainer.appendChild(loadingDiv);
        this.scrollToBottom();
    }

    hideLoading() {
        const loading = document.getElementById('aiChatLoading');
        if (loading) {
            loading.remove();
        }
    }

    showError(message, persistent = false) {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer) return;

        // Remove existing errors first
        const existingErrors = messagesContainer.querySelectorAll('.ai-chat-error');
        existingErrors.forEach(err => err.remove());

        const errorDiv = document.createElement('div');
        errorDiv.className = 'ai-chat-error';
        
        // Check if it's a retry message
        const isRetryMessage = message.includes('Retrying') || message.includes('retrying') || message.includes('attempt');
        
        if (isRetryMessage) {
            // Add loading indicator for retry messages
            errorDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div class="ai-chat-loading" style="padding: 0;">
                        <div class="ai-chat-loading-dot"></div>
                        <div class="ai-chat-loading-dot"></div>
                        <div class="ai-chat-loading-dot"></div>
                    </div>
                    <span>${message}</span>
                </div>
            `;
        } else {
            // For persistent errors (like disabled access), add an icon and make it more prominent
            if (persistent) {
                errorDiv.innerHTML = `
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <i class="fas fa-exclamation-triangle" style="color: #ff6b6b; font-size: 18px; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; margin-bottom: 4px;">Access Restricted</div>
                            <div>${this.escapeHtml(message)}</div>
                        </div>
                    </div>
                `;
            } else {
                errorDiv.textContent = message;
            }
        }

        messagesContainer.appendChild(errorDiv);
        this.scrollToBottom();

        // Don't auto-remove retry messages or persistent errors
        if (!isRetryMessage && !persistent) {
            setTimeout(() => {
                if (errorDiv.parentNode) {
                    errorDiv.remove();
                }
            }, 8000);
        }
    }

    scrollToBottom() {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Show typing indicator when bot is processing
     */
    showTypingIndicator() {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer) return;
        
        // Remove existing typing indicator
        const existing = document.getElementById('aiChatTypingIndicator');
        if (existing) existing.remove();
        
        const typingDiv = document.createElement('div');
        typingDiv.className = 'ai-chat-message ai';
        typingDiv.id = 'aiChatTypingIndicator';
        typingDiv.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-chat-message-content">
                <div class="ai-chat-message-bubble ai-chat-typing-indicator">
                    <span class="ai-chat-typing-label">MotorLink AI is typing</span>
                    <div class="ai-chat-typing-dots">
                        <span></span>
                        <span></span>
                        <span></span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(typingDiv);
        this.scrollToBottom();
        
    }
    
    /**
     * Hide typing indicator
     */
    hideTypingIndicator() {
        const typing = document.getElementById('aiChatTypingIndicator');
        if (typing) {
            typing.remove();
        }
    }
    
    /**
     * Handle quick action buttons
     */
    async handleQuickAction(action, listingId) {
        if (!listingId) return;
        
        try {
            switch(action) {
                case 'view':
                    window.open(`car.html?id=${listingId}`, '_blank');
                    break;
                    
                case 'save':
                    // Save to favorites
                    const saveResponse = await fetch(`${CONFIG.API_URL}?action=save_listing`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Skip-Global-Loader': '1'
                        },
                        credentials: 'include',
                        body: JSON.stringify({ listing_id: listingId })
                    });
                    
                    const saveData = await saveResponse.json();
                    if (saveData.success) {
                        this.showToast('Car saved to favorites!', 'success');
                    } else {
                        this.showToast(saveData.message || 'Failed to save car', 'error');
                    }
                    break;
                    
                case 'contact':
                    // Open contact modal or redirect to listing
                    window.open(`car.html?id=${listingId}#contact`, '_blank');
                    break;
                    
                default:
                    console.warn('Unknown action:', action);
            }
        } catch (error) {
            console.error('Error handling quick action:', error);
            this.showToast('An error occurred. Please try again.', 'error');
        }
    }
    
    /**
     * Handle user feedback
     */
    async handleFeedback(feedback, messageId, messageElement) {
        // Update button states
        const feedbackContainer = messageElement.querySelector('.ai-chat-feedback');
        if (feedbackContainer) {
            const buttons = feedbackContainer.querySelectorAll('.ai-chat-feedback-btn');
            buttons.forEach(btn => {
                btn.disabled = true;
                if (btn.getAttribute('data-feedback') === feedback) {
                    btn.classList.add(feedback === 'helpful' ? 'ai-chat-feedback-btn-selected-helpful' : 'ai-chat-feedback-btn-selected-unhelpful');
                } else {
                    btn.classList.add('ai-chat-feedback-btn-muted');
                }
            });

            // Show thank you message
            const thankYou = document.createElement('span');
            thankYou.className = 'ai-chat-feedback-thanks';
            thankYou.textContent = feedback === 'helpful'
                ? '✓ Thanks! I\'ll remember this.'
                : '✓ Got it — I\'ll do better.';
            feedbackContainer.appendChild(thankYou);
            setTimeout(() => thankYou.remove(), 3500);
        }

        // Extract AI response text from this message element
        const bubble = messageElement.querySelector('.ai-chat-message-bubble');
        const aiResponseText = bubble ? (bubble.innerText || bubble.textContent || '').trim() : '';

        // Find the most recent user message that preceded this AI message
        // Walk conversationHistory backwards from the matching assistant entry
        let userMessageText = '';
        let foundAI = false;
        for (let i = this.conversationHistory.length - 1; i >= 0; i--) {
            const entry = this.conversationHistory[i];
            if (!foundAI && entry.role === 'assistant') {
                // Match by approximate content (first 80 chars)
                const entrySnip = (entry.content || '').substring(0, 80).toLowerCase();
                const bubbleSnip = aiResponseText.substring(0, 80).toLowerCase();
                if (entrySnip === bubbleSnip || i === this.conversationHistory.length - 1) {
                    foundAI = true;
                    continue;
                }
            }
            if (foundAI && entry.role === 'user') {
                userMessageText = (entry.content || '').trim();
                break;
            }
        }

        // Fallback: use the last user message
        if (!userMessageText) {
            for (let i = this.conversationHistory.length - 1; i >= 0; i--) {
                if (this.conversationHistory[i].role === 'user') {
                    userMessageText = (this.conversationHistory[i].content || '').trim();
                    break;
                }
            }
        }

        // Send to backend for learning — fire-and-forget
        try {
            await fetch(`${CONFIG.API_URL}?action=ai_chat_feedback`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include',
                body: JSON.stringify({
                    feedback,
                    user_message: userMessageText,
                    ai_response:  aiResponseText
                })
            });
        } catch (_) { /* Silently fail — feedback must never block the user */ }
    }

    async loadUsageIndicator() {
        if (!this.currentUser) {
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_ai_chat_usage_remaining`, {
                headers: {
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include'
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.usage) {
                    const usage = data.usage;
                    const usageIndicator = document.getElementById('aiUsageIndicator');
                    const usageText = document.getElementById('aiUsageText');
                    
                    if (usageIndicator && usageText) {
                        const remaining = usage.remaining;
                        const limit = usage.daily_limit;
                        const percentage = usage.percentage_used;
                        
                        // Color coding: green (>50% left), orange (25-50%), red (<25%)
                        let color = '#4caf50'; // Green
                        let bgColor = '#e8f5e9'; // Light green background
                        let icon = '✓';
                        
                        if (percentage >= 75) {
                            color = '#f44336'; // Red
                            bgColor = '#ffebee'; // Light red background
                            icon = '⚠';
                        } else if (percentage >= 50) {
                            color = '#ff9800'; // Orange
                            bgColor = '#fff3e0'; // Light orange background
                            icon = '⚠';
                        }
                        
                        // Update text with icon
                        usageText.innerHTML = `<span style="font-weight: 600; color: ${color};">${icon}</span> <span style="color: ${color};">${remaining}</span> / <span style="color: #666;">${limit}</span> requests left`;
                        
                        // Apply styling to the indicator container
                        usageIndicator.style.cssText = `
                            font-size: 11px;
                            margin-top: 6px;
                            padding: 4px 8px;
                            background: ${bgColor};
                            border-radius: 12px;
                            display: inline-block;
                            border: 1px solid ${color}40;
                            font-weight: 500;
                        `;
                        
                        // Update after each message is sent
                        this.updateUsageAfterMessage = true;
                    }
                }
            }
        } catch (error) {
            console.error('Error loading usage indicator:', error);
            // Hide usage indicator on error
            const usageIndicator = document.getElementById('aiUsageIndicator');
            if (usageIndicator) {
                usageIndicator.style.display = 'none';
            }
        }
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        // Create toast element
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            bottom: 100px;
            left: 20px;
            background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#2196F3'};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10001;
            font-size: 14px;
            max-width: 300px;
            animation: slideIn 0.3s ease;
        `;
        toast.textContent = message;
        
        // Add animation if not exists
        if (!document.getElementById('aiChatToastAnimation')) {
            const style = document.createElement('style');
            style.id = 'aiChatToastAnimation';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(-100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(-100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        
        // Remove after 3 seconds
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }, 3000);
    }

    /**
     * Format message content: parse markdown links and convert to HTML
     */
    formatMessageContent(content) {
        // First escape HTML to prevent XSS
        let formatted = this.escapeHtml(content);

        // --- Markdown rendering (order matters) ---

        // Headers: ### Header → <strong>Header</strong> (with line break)
        formatted = formatted.replace(/^(#{1,3})\s+(.+)$/gm, (m, hashes, text) => {
            return `<strong>${text}</strong>`;
        });

        // Bold: **text** or __text__
        formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        formatted = formatted.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // Italic: *text* or _text_ (but not inside words like file_name)
        formatted = formatted.replace(/(?<!\w)\*([^*\n]+?)\*(?!\w)/g, '<em>$1</em>');
        formatted = formatted.replace(/(?<!\w)_([^_\n]+?)_(?!\w)/g, '<em>$1</em>');

        // Inline code: `code`
        formatted = formatted.replace(/`([^`]+?)`/g, '<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:0.9em;">$1</code>');

        // Markdown links: [text](url)
        formatted = formatted.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, text, url) => {
            let fullUrl = url;
            if (url.startsWith('/')) {
                fullUrl = window.location.origin + url;
            } else if (!url.startsWith('http://') && !url.startsWith('https://')) {
                const baseUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
                fullUrl = baseUrl + (url.startsWith('./') ? url.substring(1) : '/' + url);
            }
            return `<a href="${fullUrl}" target="_blank" rel="noopener noreferrer" class="ai-chat-link">${text}</a>`;
        });

        // Unordered list items: - item or * item (at start of line)
        formatted = formatted.replace(/^[\-\*]\s+(.+)$/gm, '• $1');

        // Numbered list items: 1. item (at start of line) — keep as-is, just ensure spacing
        formatted = formatted.replace(/^(\d+)\.\s+(.+)$/gm, '$1. $2');

        // Horizontal rule: --- or ***
        formatted = formatted.replace(/^[\-\*]{3,}$/gm, '<hr style="border:none;border-top:1px solid #ddd;margin:8px 0;">');

        // Convert line breaks to <br>
        formatted = formatted.replace(/\n/g, '<br>');

        return formatted;
    }
}

// Initialize AI Chat when DOM is ready
let aiCarChat;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        aiCarChat = new AICarChat();
    });
} else {
    aiCarChat = new AICarChat();
}

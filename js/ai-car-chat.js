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
        this.maxRetries = 1; // Prefer fast failure over long retry loops
        this.baseRetryDelay = 800;
        this.maxRetryDelay = 1800;
        this.currentRetryTimeout = null;
        this.currentSendFailsafeTimeout = null;
        this.pendingMessage = null;
        this._lastDragWasMove = false;
        this.storagePrefix = 'ai_chat';
        this.storageVersion = 2;
        this.authSessionKey = 'session';
        this.locationContext = null;
        this.locationContextUpdatedAt = 0;
        this.locationContextPromise = null;
        this.locationCacheTtlMs = 10 * 60 * 1000;
        this._conversationSaveTimeout = null;
        this._persistEventsBound = false;
        this.init();
    }

    async init() {
        await this.checkAuth();
        this.clearLegacyConversationState();
        this.bindEvents();
        this.setupWidgetVisibility();
        await this.restoreConversation(); // Restore chat across page navigations/reloads
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
                this.authSessionKey = (typeof data.auth_session_key === 'string' && data.auth_session_key.trim() !== '')
                    ? data.auth_session_key.trim()
                    : 'session';
            } else {
                this.currentUser = null;
                this.authSessionKey = 'anonymous';
            }
        } catch (error) {
            console.error('Auth check error:', error);
            this.currentUser = null;
            this.authSessionKey = 'anonymous';
        }
    }

    /**
     * Save conversation to sessionStorage so it persists across page navigations
     */
    getStorageKey(suffix, useLegacyFormat = false) {
        if (useLegacyFormat) {
            return `${this.storagePrefix}_${suffix}`;
        }

        const userId = this.currentUser && this.currentUser.id ? String(this.currentUser.id) : 'anonymous';
        const authKey = this.authSessionKey ? String(this.authSessionKey) : 'session';
        return `${this.storagePrefix}_${userId}_${authKey}_${suffix}`;
    }

    clearLegacyConversationState() {
        const legacyKeys = [
            'ai_chat_history',
            'ai_chat_messages_html',
            'ai_chat_dismissed',
            'ai_chat_pos',
            'ai_fab_pos'
        ];

        for (const key of legacyKeys) {
            sessionStorage.removeItem(key);
        }
    }

    getDismissedStorageKeys() {
        return [this.getStorageKey('dismissed'), 'ai_chat_dismissed'];
    }

    isChatDismissed() {
        return this.getDismissedStorageKeys().some((key) => sessionStorage.getItem(key) === '1');
    }

    getPositionStorageKeys(preferFabPosition = false) {
        // widget_pos is the unified key written by the new drag code.
        // The legacy fab_pos / chat_pos keys are kept for backwards compatibility.
        return preferFabPosition
            ? [this.getStorageKey('widget_pos'), this.getStorageKey('fab_pos'), this.getStorageKey('chat_pos'), 'ai_fab_pos', 'ai_chat_pos']
            : [this.getStorageKey('widget_pos'), this.getStorageKey('chat_pos'), this.getStorageKey('fab_pos'), 'ai_chat_pos', 'ai_fab_pos'];
    }

    serializeMessagesHtml(messagesContainer) {
        if (!messagesContainer) {
            return '';
        }

        const clone = messagesContainer.cloneNode(true);
        clone.querySelectorAll('#aiChatTypingIndicator, .ai-chat-error').forEach((element) => element.remove());
        return clone.innerHTML;
    }

    queueConversationSave(delay = 120) {
        if (this._conversationSaveTimeout) {
            clearTimeout(this._conversationSaveTimeout);
        }

        this._conversationSaveTimeout = setTimeout(() => {
            this._conversationSaveTimeout = null;
            this.saveConversation();
        }, delay);
    }

    getLegacyConversationState() {
        try {
            const savedHistory = sessionStorage.getItem('ai_chat_history');
            const savedHTML = sessionStorage.getItem('ai_chat_messages_html');
            const history = savedHistory ? JSON.parse(savedHistory) : [];

            if (!Array.isArray(history) || history.length === 0) {
                return null;
            }

            return {
                version: 1,
                history,
                messagesHtml: savedHTML || '',
                isMinimized: true,
                isOpen: false,
                dismissed: sessionStorage.getItem('ai_chat_dismissed') === '1',
                draft: '',
                scrollTop: null
            };
        } catch (error) {
            return null;
        }
    }

    getSavedConversationState() {
        try {
            const raw = sessionStorage.getItem(this.getStorageKey('state'));
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && Array.isArray(parsed.history)) {
                    return parsed;
                }
            }
        } catch (error) {
            console.warn('Could not parse saved AI chat state:', error);
        }

        return null;
    }

    saveConversation() {
        if (!this.currentUser) {
            return;
        }

        try {
            const messagesContainer = document.getElementById('aiChatMessages');
            const input = document.getElementById('aiChatInput');
            const widget = document.getElementById('aiCarChatWidget');
            const dismissed = widget ? widget.classList.contains('dismissed') : this.isChatDismissed();

            const state = {
                version: this.storageVersion,
                history: this.conversationHistory.slice(-20),
                messagesHtml: this.serializeMessagesHtml(messagesContainer),
                isMinimized: this.isMinimized,
                isOpen: this.isOpen,
                dismissed,
                draft: input ? input.value : '',
                scrollTop: messagesContainer ? messagesContainer.scrollTop : 0,
                updatedAt: Date.now()
            };

            sessionStorage.setItem(this.getStorageKey('state'), JSON.stringify(state));

            if (dismissed) {
                sessionStorage.setItem(this.getStorageKey('dismissed'), '1');
            } else {
                sessionStorage.removeItem(this.getStorageKey('dismissed'));
            }
        } catch (e) { /* sessionStorage full or unavailable */ }
    }

    /**
     * Restore conversation from sessionStorage after page navigation
     */
    async restoreConversation() {
        if (!this.currentUser) {
            return;
        }

        try {
            const savedState = this.getSavedConversationState();
            const messagesContainer = document.getElementById('aiChatMessages');
            const chatInput = document.getElementById('aiChatInput');

            if (savedState && Array.isArray(savedState.history) && savedState.history.length > 0) {
                this.conversationHistory = savedState.history;

                if (messagesContainer) {
                    if (typeof savedState.messagesHtml === 'string' && savedState.messagesHtml.trim() !== '') {
                        messagesContainer.innerHTML = savedState.messagesHtml;
                        messagesContainer.querySelectorAll('.ai-chat-message').forEach((msg) => {
                            this.bindMessageInteractions(msg);
                        });
                    } else {
                        this.renderConversationHistory(savedState.history);
                    }
                }

                if (chatInput && typeof savedState.draft === 'string') {
                    chatInput.value = savedState.draft;
                    this.updateCharCount(chatInput.value.length);
                    this.autoResizeTextarea(chatInput);
                }

                if (savedState.dismissed) {
                    this.dismissChat(true);
                } else if (savedState.isOpen) {
                    this.openChat({ focus: false, saveState: false });
                } else {
                    this.minimizeChat({ saveState: false });
                }

                if (messagesContainer) {
                    requestAnimationFrame(() => {
                        if (typeof savedState.scrollTop === 'number' && Number.isFinite(savedState.scrollTop)) {
                            const maxScrollTop = Math.max(0, messagesContainer.scrollHeight - messagesContainer.clientHeight);
                            messagesContainer.scrollTop = Math.max(0, Math.min(savedState.scrollTop, maxScrollTop));
                        } else {
                            this.scrollToBottom();
                        }
                    });
                }

                return;
            }

            // No history, but restore open/close state if it was intentionally open.
            if (savedState && savedState.isOpen && !savedState.dismissed) {
                this.openChat({ focus: false, saveState: false });
            }
        } catch (e) { /* ignore parse errors */ }
    }

    renderConversationHistory(history) {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer || !Array.isArray(history) || history.length === 0) {
            return;
        }

        messagesContainer.innerHTML = '';
        history.forEach((item) => {
            if (!item || !item.role || !item.content) {
                return;
            }

            this.addMessage(item.role, item.content, null, 0, null, null, {
                persist: false,
                scroll: false,
                includeFeedback: false
            });
        });
    }

    async fetchSessionHistoryFromServer() {
        if (!this.currentUser) {
            return [];
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_ai_chat_session_history`, {
                headers: {
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include'
            });
            const data = await response.json();

            if (!data.success || !Array.isArray(data.history)) {
                return [];
            }

            return data.history.filter((item) => {
                return item && (item.role === 'user' || item.role === 'assistant') && typeof item.content === 'string' && item.content.trim() !== '';
            });
        } catch (error) {
            console.warn('Could not hydrate AI chat session history from server:', error);
            return [];
        }
    }

    getSavedWidgetPosition(preferFabPosition = false) {
        const keys = this.getPositionStorageKeys(preferFabPosition);

        for (const key of keys) {
            const raw = sessionStorage.getItem(key);
            if (!raw) continue;

            try {
                const parsed = JSON.parse(raw);
                const left = Number(parsed.left);
                const top = Number(parsed.top);

                if (Number.isFinite(left) && Number.isFinite(top)) {
                    return { left, top };
                }
            } catch (e) {
                /* ignore malformed saved position */
            }
        }

        return null;
    }

    restoreWidgetPosition(widget, preferFabPosition = false) {
        if (!widget) return;

        const savedPos = this.getSavedWidgetPosition(preferFabPosition);
        if (!savedPos) return;

        const fallbackWidth = preferFabPosition ? 72 : 320;
        const fallbackHeight = preferFabPosition ? 56 : 120;
        const widgetWidth = Math.max(widget.offsetWidth || 0, fallbackWidth);
        const widgetHeight = Math.max(widget.offsetHeight || 0, fallbackHeight);
        const maxLeft = Math.max(0, window.innerWidth - widgetWidth);
        const maxTop = Math.max(0, window.innerHeight - widgetHeight);
        const safeLeft = Math.max(0, Math.min(savedPos.left, maxLeft));
        const safeTop = Math.max(0, Math.min(savedPos.top, maxTop));

        widget.style.bottom = 'auto';
        widget.style.right = 'auto';
        widget.style.left = `${safeLeft}px`;
        widget.style.top = `${safeTop}px`;
    }

    setupWidgetVisibility() {
        const widget = document.getElementById('aiCarChatWidget');
        if (!widget) return;

        if (!this.currentUser) {
            // Keep widget invisible for guests — do NOT add 'loaded'
            widget.style.display = 'none';
            widget.classList.remove('loaded');
        } else {
            // Show widget for logged-in users
            // Use '' (not 'block') so the CSS display: grid can take effect when the
            // widget opens. An inline 'block' would override the grid rule.
            widget.style.display = '';
            // Check if dismissed this session
            if (this.isChatDismissed()) {
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
            this.restoreWidgetPosition(widget, true);
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
                // Guard: skip toggle while a message is being sent (prevents phantom-tap
                // dismiss on mobile after the virtual keyboard send key is pressed)
                if (this.isSending) return;
                if (this._blockHeaderToggle) return;
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

        // Usage refresh button → reload indicator on demand
        const usageRefreshBtn = document.getElementById('aiUsageRefreshBtn');
        if (usageRefreshBtn) {
            usageRefreshBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const icon = usageRefreshBtn.querySelector('i');
                if (icon) icon.style.animation = 'spin 0.8s linear';
                await this.loadUsageIndicator();
                setTimeout(() => { if (icon) icon.style.animation = ''; }, 900);
            });
        }

        // Save transcript button
        const saveTranscriptBtn = document.getElementById('aiChatSaveTranscriptBtn');
        if (saveTranscriptBtn) {
            saveTranscriptBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.saveTranscript();
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

        const chatMessages = document.getElementById('aiChatMessages');
        if (chatMessages && chatMessages.dataset.welcomeQueryBound !== '1') {
            chatMessages.dataset.welcomeQueryBound = '1';
            chatMessages.addEventListener('click', (e) => {
                const quickBtn = e.target.closest('.ai-chat-welcome-query-btn');
                if (!quickBtn) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();
                const optionText = quickBtn.getAttribute('data-conversation-option') || quickBtn.textContent || '';
                this.handleConversationOption(optionText);
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
                this.queueConversationSave();
            });

            this.autoResizeTextarea(chatInput);
            this.updateCharCount(chatInput.value.length);
        }

        this.ensureInputStatusElement();
        this.initDrag();
        this.bindPersistenceEvents();
        this.bindScrollContainment();
    }

    bindPersistenceEvents() {
        if (this._persistEventsBound) {
            return;
        }

        const persist = () => this.saveConversation();

        window.addEventListener('pagehide', persist);
        window.addEventListener('beforeunload', persist);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                persist();
            }
        });

        this._persistEventsBound = true;
    }

    bindScrollContainment() {
        this.bindScrollableElement(document.getElementById('aiChatMessages'), true);
        this.bindScrollableElement(document.getElementById('aiChatInput'), false);
    }

    bindScrollableElement(element, shouldTrackState) {
        if (!element || element.dataset.aiScrollBound === '1') {
            return;
        }

        element.dataset.aiScrollBound = '1';

        const canElementScroll = () => element.scrollHeight > element.clientHeight + 1;
        const isAtTop = () => element.scrollTop <= 0;
        const isAtBottom = () => Math.ceil(element.scrollTop + element.clientHeight) >= element.scrollHeight;

        element.addEventListener('wheel', (event) => {
            if (!this.isOpen) {
                return;
            }

            if (!canElementScroll()) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            const scrollingUp = event.deltaY < 0;
            const scrollingDown = event.deltaY > 0;

            if ((scrollingUp && isAtTop()) || (scrollingDown && isAtBottom())) {
                event.preventDefault();
            }

            event.stopPropagation();

            if (shouldTrackState) {
                this.queueConversationSave(140);
            }
        }, { passive: false });

        let touchStartY = 0;
        element.addEventListener('touchstart', (event) => {
            if (event.touches && event.touches[0]) {
                touchStartY = event.touches[0].clientY;
            }
        }, { passive: true });

        element.addEventListener('touchmove', (event) => {
            if (!this.isOpen) {
                return;
            }

            if (!canElementScroll()) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            const currentY = event.touches && event.touches[0] ? event.touches[0].clientY : touchStartY;
            const deltaY = touchStartY - currentY;
            const pullingDown = deltaY < 0;
            const pushingUp = deltaY > 0;

            if ((pullingDown && isAtTop()) || (pushingUp && isAtBottom())) {
                event.preventDefault();
            }

            event.stopPropagation();
        }, { passive: false });

        if (shouldTrackState) {
            element.addEventListener('scroll', () => {
                this.queueConversationSave(160);
            }, { passive: true });
        }
    }

    initDrag() {
        const widget = document.getElementById('aiCarChatWidget');
        const header = document.getElementById('aiChatHeader');
        const fabBtn = document.getElementById('aiChatMinimized');
        if (!widget) return;

        const EDGE_GAP   = 12;  // px gap kept between FAB and screen edge when snapping
        const HEADER_H   = 64; // reserved top clearance (header bar)
        const MOVE_THRESH = 4;  // px movement before a drag is recognised

        // ── FAB DRAG — works on ALL screen sizes ─────────────────────────────
        if (fabBtn) {
            let fabDragging = false;
            let fabMoved    = false;
            let fabStartPX, fabStartPY, fabStartLeft, fabStartTop;

            const onFabDown = (e) => {
                if (e.button !== undefined && e.button !== 0) return;
                const rect = widget.getBoundingClientRect();
                fabStartLeft = rect.left;
                fabStartTop  = rect.top;
                fabStartPX   = e.clientX;
                fabStartPY   = e.clientY;
                fabDragging  = true;
                fabMoved     = false;

                widget.style.bottom     = 'auto';
                widget.style.right      = 'auto';
                widget.style.left       = fabStartLeft + 'px';
                widget.style.top        = fabStartTop  + 'px';
                widget.style.transition = 'none'; // kill any snap transition

                try { fabBtn.setPointerCapture(e.pointerId); } catch (_) {}
                document.addEventListener('pointermove',   onFabMove);
                document.addEventListener('pointerup',     onFabUp);
                document.addEventListener('pointercancel', onFabUp);
            };

            const onFabMove = (e) => {
                if (!fabDragging) return;
                const dx = e.clientX - fabStartPX;
                const dy = e.clientY - fabStartPY;
                if (Math.abs(dx) > MOVE_THRESH || Math.abs(dy) > MOVE_THRESH) fabMoved = true;
                if (!fabMoved) return;

                const vw   = window.innerWidth;
                const vh   = window.innerHeight;
                const fabW = widget.offsetWidth  || 50;
                const fabH = widget.offsetHeight || 50;

                widget.style.left = Math.max(EDGE_GAP, Math.min(fabStartLeft + dx, vw - fabW - EDGE_GAP)) + 'px';
                widget.style.top  = Math.max(HEADER_H, Math.min(fabStartTop  + dy, vh - fabH - EDGE_GAP)) + 'px';
            };

            const onFabUp = () => {
                if (!fabDragging) return;
                fabDragging = false;
                document.removeEventListener('pointermove',   onFabMove);
                document.removeEventListener('pointerup',     onFabUp);
                document.removeEventListener('pointercancel', onFabUp);

                if (fabMoved) {
                    const isMobile = window.innerWidth <= 1024;

                    if (isMobile) {
                        // ── Edge-snap: spring the FAB to the nearest side ──
                        const vw   = window.innerWidth;
                        const vh   = window.innerHeight;
                        const fabW = widget.offsetWidth  || 50;
                        const fabH = widget.offsetHeight || 50;

                        const curLeft   = parseFloat(widget.style.left) || 0;
                        const curTop    = parseFloat(widget.style.top)  || 0;
                        const midX      = curLeft + fabW / 2;
                        const snapLeft  = midX < vw / 2
                            ? EDGE_GAP
                            : vw - fabW - EDGE_GAP;
                        const snapTop   = Math.max(HEADER_H, Math.min(curTop, vh - fabH - EDGE_GAP));

                        // Apply spring transition before updating coordinates
                        widget.style.transition = 'left 0.3s cubic-bezier(0.34,1.56,0.64,1), top 0.2s ease';
                        widget.style.left = snapLeft + 'px';
                        widget.style.top  = snapTop  + 'px';
                        setTimeout(() => { widget.style.transition = ''; }, 350);

                        // Persist snapped position
                        const key = this.getStorageKey('widget_pos');
                        sessionStorage.setItem(key, JSON.stringify({ left: snapLeft, top: snapTop }));
                    } else {
                        // Desktop: free-position, no snap
                        const key = this.getStorageKey('widget_pos');
                        sessionStorage.setItem(key, JSON.stringify({
                            left: parseInt(widget.style.left, 10),
                            top:  parseInt(widget.style.top,  10)
                        }));
                    }

                    this._fabDragWasMove  = true;
                    this._lastDragWasMove = true;
                    setTimeout(() => {
                        this._fabDragWasMove  = false;
                        this._lastDragWasMove = false;
                    }, isMobile ? 400 : 200);
                }
            };

            fabBtn.addEventListener('pointerdown', onFabDown);
        }

        // ── HEADER DRAG — desktop only (widget is full-screen on mobile/tablet) ──
        if (header && window.innerWidth > 1024) {
            let hDragging = false;
            let hMoved    = false;
            let hStartPX, hStartPY, hStartLeft, hStartTop;

            const onHdrDown = (e) => {
                if (e.button !== undefined && e.button !== 0) return;
                if (e.target.closest('.ai-chat-header-actions')) return;

                const rect = widget.getBoundingClientRect();
                hStartLeft = rect.left;
                hStartTop  = rect.top;
                hStartPX   = e.clientX;
                hStartPY   = e.clientY;
                hDragging  = true;
                hMoved     = false;

                widget.style.bottom = 'auto';
                widget.style.right  = 'auto';
                widget.style.left   = hStartLeft + 'px';
                widget.style.top    = hStartTop  + 'px';
                widget.classList.add('dragging');

                try { header.setPointerCapture(e.pointerId); } catch (_) {}
                document.addEventListener('pointermove',   onHdrMove);
                document.addEventListener('pointerup',     onHdrUp);
                document.addEventListener('pointercancel', onHdrUp);
            };

            const onHdrMove = (e) => {
                if (!hDragging) return;
                const dx = e.clientX - hStartPX;
                const dy = e.clientY - hStartPY;
                if (Math.abs(dx) > MOVE_THRESH || Math.abs(dy) > MOVE_THRESH) hMoved = true;
                if (!hMoved) return;

                const vw = window.innerWidth;
                const vh = window.innerHeight;
                widget.style.left = Math.max(0, Math.min(hStartLeft + dx, vw - widget.offsetWidth))  + 'px';
                widget.style.top  = Math.max(0, Math.min(hStartTop  + dy, vh - widget.offsetHeight)) + 'px';
            };

            const onHdrUp = () => {
                if (!hDragging) return;
                hDragging = false;
                widget.classList.remove('dragging');
                document.removeEventListener('pointermove',   onHdrMove);
                document.removeEventListener('pointerup',     onHdrUp);
                document.removeEventListener('pointercancel', onHdrUp);

                if (hMoved) {
                    const key = this.getStorageKey('widget_pos');
                    sessionStorage.setItem(key, JSON.stringify({
                        left: parseInt(widget.style.left, 10),
                        top:  parseInt(widget.style.top,  10)
                    }));
                    this._lastDragWasMove = true;
                    setTimeout(() => { this._lastDragWasMove = false; }, 200);
                }
            };

            header.addEventListener('pointerdown', onHdrDown);
        }
    }

    toggleChat() {
        if (this.isMinimized) {
            this.openChat();
        } else {
            this.minimizeChat();
        }
    }

    openChat(options = {}) {
        const { focus = true, saveState = true } = options;
        const chatBody     = document.getElementById('aiChatBody');
        const widget       = document.getElementById('aiCarChatWidget');
        const minimizedBtn = document.getElementById('aiChatMinimized');

        if (chatBody && widget) {
            chatBody.style.display = 'flex';
            widget.classList.remove('minimized');
            if (minimizedBtn) minimizedBtn.style.display = 'none';
            this.isMinimized = false;
            this.isOpen = true;

            // Snap the open widget back to the CSS default position (bottom-left).
            // Drag is only for repositioning the minimised FAB bubble; the open widget
            // should not inherit a dragged coordinate.
            // Also clear any inline display override so CSS display:grid takes effect
            // (setupWidgetVisibility may have set display:block for the minimised state).
            widget.style.display = '';
            widget.style.left   = '';
            widget.style.top    = '';
            widget.style.bottom = '';
            widget.style.right  = '';

            const chatInput = document.getElementById('aiChatInput');
            if (chatInput && focus) setTimeout(() => chatInput.focus(), 100);
            setTimeout(() => this.scrollToBottom(), 80);

            // Refresh usage limits every time the widget opens so the counter is always current.
            this.loadUsageIndicator();

            if (saveState) {
                this.saveConversation();
            }
        }
    }

    minimizeChat(options = {}) {
        const { saveState = true } = options;
        const chatBody    = document.getElementById('aiChatBody');
        const widget      = document.getElementById('aiCarChatWidget');
        const minimizedBtn = document.getElementById('aiChatMinimized');

        if (chatBody && widget) {
            chatBody.style.display = 'none';
            widget.classList.add('minimized');
            if (minimizedBtn) minimizedBtn.style.display = 'flex';
            this.isMinimized = true;
            this.isOpen = false;

            // Restore the FAB to where the user last dragged it (if any)
            const savedPos = (() => {
                try { return JSON.parse(sessionStorage.getItem(this.getStorageKey('widget_pos')) || 'null'); } catch(_) { return null; }
            })();
            if (savedPos && typeof savedPos.left === 'number' && typeof savedPos.top === 'number') {
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                const safeLeft = Math.max(0, Math.min(savedPos.left, vw - 64));
                const safeTop  = Math.max(0, Math.min(savedPos.top,  vh - 64));
                widget.style.left   = safeLeft + 'px';
                widget.style.top    = safeTop  + 'px';
                widget.style.bottom = 'auto';
                widget.style.right  = 'auto';
            } else {
                // No saved drag position — clear inline styles so CSS default applies
                widget.style.left   = '';
                widget.style.top    = '';
                widget.style.bottom = '';
                widget.style.right  = '';
            }

            if (saveState) {
                this.saveConversation();
            }
        }
    }

    dismissChat(skipSave = false) {
        const widget = document.getElementById('aiCarChatWidget');
        if (widget) {
            widget.classList.add('dismissed');
            sessionStorage.setItem(this.getStorageKey('dismissed'), '1');
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

            if (!skipSave) {
                this.saveConversation();
            }
        }
    }

    restoreChat() {
        const widget = document.getElementById('aiCarChatWidget');
        if (widget) {
            widget.classList.remove('dismissed');
            sessionStorage.removeItem(this.getStorageKey('dismissed'));
        }
        const restoreBtn = document.getElementById('aiChatRestore');
        if (restoreBtn) restoreBtn.remove();
        this.saveConversation();
    }

    updateCharCount(count) {
        const charCount = document.getElementById('aiChatCharCount');
        if (charCount) {
            const input = document.getElementById('aiChatInput');
            const maxLength = Math.max(1, Number(input?.getAttribute('maxlength') || 1500));
            const warningThreshold = Math.floor(maxLength * 0.85);
            const dangerThreshold = Math.floor(maxLength * 0.95);

            charCount.textContent = count;
            if (count >= dangerThreshold) {
                charCount.style.color = '#f44336';
            } else if (count >= warningThreshold) {
                charCount.style.color = '#ff9800';
            } else {
                charCount.style.color = '#999';
            }
        }
    }

    autoResizeTextarea(textarea) {
        if (!textarea) return;

        const computedStyles = window.getComputedStyle(textarea);
        const minHeight = parseFloat(computedStyles.minHeight) || 52;
        const maxHeight = parseFloat(computedStyles.maxHeight) || 140;

        textarea.style.height = 'auto';
        const nextHeight = Math.min(Math.max(textarea.scrollHeight, minHeight), maxHeight);
        textarea.style.height = nextHeight + 'px';
        textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';

        if (this.isOpen) {
            this.scrollToBottom();
        }
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
        // Feature disabled as per user request to remove "Sending..." indicator.
        // We still disable the inputs in sendMessage() and show typing indicator.
        return;
    }

    clearSendFailsafe() {
        if (this.currentSendFailsafeTimeout) {
            clearTimeout(this.currentSendFailsafeTimeout);
            this.currentSendFailsafeTimeout = null;
        }
    }

    startSendFailsafe(input, sendBtn, timeoutMs = 22000) {
        this.clearSendFailsafe();
        this.currentSendFailsafeTimeout = setTimeout(() => {
            if (!this.isSending || this.currentRetryTimeout) {
                return;
            }

            this.hideTypingIndicator();
            this.resetInputSendState(input, sendBtn);
            this.pendingMessage = null;
            this.retryCount = 0;
            this.showError('The request took too long and was stopped. Please try again.');
        }, timeoutMs);
    }

    resetInputSendState(input, sendBtn) {
        this.clearSendFailsafe();
        this.isSending = false;
        input.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        this.setInputSendingState(false);
        // Release header-toggle block (if response arrived before the 600ms timer)
        clearTimeout(this._blockHeaderToggleTimeout);
        this._blockHeaderToggle = false;
    }

    /**
     * Save the current chat transcript as a text file download.
     */
    saveTranscript() {
        if (!this.conversationHistory || this.conversationHistory.length === 0) {
            this.showError('No conversation to save yet.');
            return;
        }

        const now = new Date();
        const dateStr = now.toISOString().slice(0, 10);
        const timeStr = now.toTimeString().slice(0, 5).replace(':', '-');
        const filename = `MotorLink-Chat-${dateStr}_${timeStr}.txt`;

        let transcript = `MotorLink AI Chat Transcript\n`;
        transcript += `Date: ${now.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}\n`;
        transcript += `Time: ${now.toLocaleTimeString()}\n`;
        transcript += `${'─'.repeat(50)}\n\n`;

        for (const entry of this.conversationHistory) {
            const role = entry.role === 'user' ? 'You' : 'MotorLink AI';
            // Strip markdown formatting for cleaner text export
            let content = (entry.content || '').replace(/\*\*(.*?)\*\*/g, '$1')
                .replace(/\*(.*?)\*/g, '$1')
                .replace(/#{1,3}\s*/g, '')
                .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
                .replace(/```[\s\S]*?```/g, (m) => m.replace(/```\w*\n?/g, '').trim());
            transcript += `[${role}]\n${content}\n\n`;
        }

        transcript += `${'─'.repeat(50)}\n`;
        transcript += `End of transcript — MotorLink Malawi (motorlinkmalawi.com)\n`;

        const blob = new Blob([transcript], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    isLocationAwareQuery(message) {
        const text = String(message || '').toLowerCase();
        if (!text) {
            return false;
        }

        return /\b(closest|nearest|near me|nearby|close to me|around me|my location|from me|within\s+\d+(?:\.\d+)?\s*(?:km|kms|kilometer|kilometers|kilometre|kilometres|mi|mile|miles))\b/i.test(text);
    }

    hasExplicitLocationMention(message) {
        const text = String(message || '').toLowerCase();
        if (!text) {
            return false;
        }

        return /\b(blantyre|lilongwe|mzuzu|zomba|mangochi|salima|kasungu|mulanje|dedza|ntcheu|mchinji|dowa|ntchisi|nkhotakota|karonga|rumphi|balaka|machinga|phalombe|thyolo|chiradzulu|nsanje|chikwawa|likoma)\b/i.test(text);
    }

    async resolveLocationContextForMessage(message) {
        if (!this.isLocationAwareQuery(message) || this.hasExplicitLocationMention(message)) {
            return null;
        }

        if (!navigator.geolocation) {
            return null;
        }

        const now = Date.now();
        if (this.locationContext && (now - this.locationContextUpdatedAt) < this.locationCacheTtlMs) {
            return this.locationContext;
        }

        if (this.locationContextPromise) {
            return this.locationContextPromise;
        }

        this.locationContextPromise = new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const context = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy_meters: position.coords.accuracy
                    };
                    this.locationContext = context;
                    this.locationContextUpdatedAt = Date.now();
                    resolve(context);
                },
                () => {
                    resolve(null);
                },
                {
                    enableHighAccuracy: false,
                    timeout: 6500,
                    maximumAge: 5 * 60 * 1000
                }
            );
        });

        const resolvedContext = await this.locationContextPromise;
        this.locationContextPromise = null;
        return resolvedContext;
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
            requestAnimationFrame(() => this.autoResizeTextarea(input));

            // Add user message to UI
            this.addMessage('user', message);

            // Add to conversation history
            this.conversationHistory.push({ role: 'user', content: message });
        }

        // Disable input while sending
        input.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        this.isSending = true;
        // Block header toggle for a brief window to prevent the phantom-tap that
        // occurs on mobile when the virtual keyboard dismisses after the Enter key
        // is pressed — the delayed touch fires at the old keyboard position which
        // may now be over the chat header, incorrectly minimising the widget.
        this._blockHeaderToggle = true;
        clearTimeout(this._blockHeaderToggleTimeout);
        this._blockHeaderToggleTimeout = setTimeout(() => { this._blockHeaderToggle = false; }, 600);
        this.setInputSendingState(true, retryAttempt);
        this.startSendFailsafe(input, sendBtn, retryAttempt > 0 ? 62000 : 48000);

        // Show compact in-chat typing indicator
        this.showTypingIndicator();

        let timeoutId = null;
        let abortReason = '';

        try {
            const controller = new AbortController();
            const locationContext = await this.resolveLocationContextForMessage(message);
            // Allow realistic server time on live networks without forcing premature aborts.
            const base = 38000;
            const timeoutDuration = retryAttempt > 0 ? 52000 : base;
            timeoutId = setTimeout(() => {
                abortReason = 'timeout';
                controller.abort('request_timeout');
            }, timeoutDuration);

            const requestPayload = {
                message: message,
                conversation_history: this.conversationHistory.slice(-6), // Lean payload for faster replies
                retry_with_improvement: !!this._pendingRetryWithImprovement,
                rejected_response: this._pendingRejectedResponse || ''
            };

            if (locationContext) {
                requestPayload.location_context = locationContext;
            }

            const response = await fetch(`${CONFIG.API_URL}?action=ai_car_chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // Chatbot messages should never trigger the full-page transition loader.
                    'X-Skip-Global-Loader': '1'
                },
                credentials: 'include',
                signal: controller.signal,
                body: JSON.stringify(requestPayload)
            });

            // Clear one-shot self-healing flags immediately so subsequent messages
            // don't inherit them.
            this._pendingRetryWithImprovement = false;
            this._pendingRejectedResponse = '';

            const rawResponse = await response.text();
            let data = {};
            if (rawResponse) {
                try {
                    data = JSON.parse(rawResponse);
                } catch (parseError) {
                    data = {
                        success: false,
                        message: response.ok
                            ? 'AI service returned an invalid response. Please try again.'
                            : rawResponse.slice(0, 220)
                    };
                }
            }

            this.hideTypingIndicator();

            if (data.success && data.response) {
                this.resetInputSendState(input, sendBtn);
                this.retryCount = 0;
                this.pendingMessage = null;

                const messageOptions = {
                    conversationOptions: Array.isArray(data.conversation_options)
                        ? data.conversation_options.slice(0, 6)
                        : []
                };

                // Check if this is a search result
                if (data.search_results && data.search_results.length > 0) {
                    // Car listings search results (for sale)
                    this.addMessage('ai', data.response, data.search_results, data.total_results, null, null, messageOptions);
                } else if (data.car_hire_companies && data.car_hire_companies.length > 0) {
                    // Car hire search results
                    this.addMessage('ai', data.response, null, data.total_results, null, data.car_hire_companies, messageOptions);
                } else if (data.garages && data.garages.length > 0) {
                    // Garage search results
                    this.addMessage('ai', data.response, null, data.total_results, data.garages, null, messageOptions);
                } else {
                    // Regular AI response
                    this.addMessage('ai', data.response, null, 0, null, null, messageOptions);
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
                } else if (response.status === 402) {
                    // Provider billing/credit issue - don't retry automatically
                    const billingMessage = data.message || 'AI provider credit is insufficient for the selected model. Please top up credits or switch to a lower-cost model.';
                    this.showError(billingMessage, true);
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.retryCount = 0;
                } else {
                    // Handle API errors with retry logic
                    const serverMessage = typeof data.message === 'string' ? data.message : '';
                    const isBillingLikeError = /insufficient balance|insufficient credit|insufficient funds|payment required|no enough balance/i.test(serverMessage);

                    if (isBillingLikeError) {
                        this.showError(serverMessage || 'AI provider credit is insufficient for the selected model. Please top up credits or switch to a lower-cost model.', true);
                        this.resetInputSendState(input, sendBtn);
                        this.pendingMessage = null;
                        this.retryCount = 0;
                        return;
                    }

                    const isRetryableError = response.status === 503;
                    
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
            const errorMessageText = typeof error?.message === 'string' ? error.message : '';
            const isNetworkLikeError = error.name === 'TypeError' || error.name === 'NetworkError' || errorMessageText.includes('fetch') || errorMessageText.includes('Failed to fetch');
            const isTimeoutAbort = error.name === 'AbortError' && abortReason === 'timeout';

            // Check if it's a timeout
            if (error.name === 'AbortError') {
                if (!isTimeoutAbort) {
                    this.resetInputSendState(input, sendBtn);
                    this.pendingMessage = null;
                    this.retryCount = 0;
                    this.showError('Request was canceled. Please try again.');
                    return;
                }

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

                return;
            }

            // Check if it's a network error
            if (isNetworkLikeError) {
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

                return;
            }

            // Other errors - don't retry, just show error
            console.error('AI Chat Error:', error);
            // Clear retry timeout if exists
            if (this.currentRetryTimeout) {
                clearTimeout(this.currentRetryTimeout);
                this.currentRetryTimeout = null;
            }

            this.resetInputSendState(input, sendBtn);
            this.pendingMessage = null;
            this.showError('An unexpected error occurred. Please try again.');
            this.retryCount = 0;
        } finally {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
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
        this.clearSendFailsafe();
        this.retryCount = 0;
    }

    formatDistanceWithDriveTime(distanceValue) {
        const km = Number(distanceValue);
        if (!Number.isFinite(km) || km <= 0) {
            return '';
        }

        const safeKm = Math.max(0.1, km);
        let speedKmh = 50;
        if (safeKm <= 5) {
            speedKmh = 28;
        } else if (safeKm <= 20) {
            speedKmh = 36;
        }

        const driveMinutes = Math.max(2, Math.min(240, Math.round((safeKm / speedKmh) * 60)));
        return `${safeKm.toFixed(1)} km away (~${driveMinutes} min drive)`;
    }

    addMessage(role, content, searchResults = null, totalResults = 0, garages = null, carHireCompanies = null, options = {}) {
        const messagesContainer = document.getElementById('aiChatMessages');
        if (!messagesContainer) return;

        const persist = options.persist !== false;
        const scroll = options.scroll !== false;
        const includeFeedback = options.includeFeedback !== false;
        const conversationOptions = Array.isArray(options.conversationOptions) ? options.conversationOptions : [];

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

        if (role === 'ai' && conversationOptions.length > 0) {
            formattedContent += this.buildConversationOptionsMarkup(conversationOptions);
        }

        const feedbackId = Date.now();

        messageDiv.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="${avatarIcon}"></i>
            </div>
            <div class="ai-chat-message-content">
                <div class="ai-chat-message-bubble">${formattedContent}</div>
                <div class="ai-chat-message-time">${time}</div>
                ${role === 'ai' && includeFeedback ? this.buildFeedbackMarkup(feedbackId) : ''}
            </div>
        `;

        messagesContainer.appendChild(messageDiv);

        this.bindMessageInteractions(messageDiv);
        
        if (scroll) {
            if (role === 'ai') {
                // Scroll so the START of the AI response is visible, not the bottom
                this.scrollToMessage(messageDiv);
            } else {
                this.scrollToBottom();
            }
        }
        if (persist) {
            this.saveConversation();
        }
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
            const distanceRaw = (garage.distance_km ?? garage.distance ?? null);
            const distanceLabel = this.formatDistanceWithDriveTime(distanceRaw);

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
                        ${distanceLabel ? `<span>📏 ${this.escapeHtml(distanceLabel)}</span>` : ''}
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
            const distanceRaw = (company.distance_km ?? company.distance ?? null);
            const distanceLabel = this.formatDistanceWithDriveTime(distanceRaw);

            let vehiclesText = '';
            if (company.matching_vehicles && company.matching_vehicles.length > 0) {
                const vehicleCount = company.matching_vehicles.length;
                vehiclesText = `${vehicleCount} vehicle${vehicleCount > 1 ? 's' : ''} available`;
                const rate = parseInt(company.matching_vehicles[0].daily_rate || 0, 10);
                if (!Number.isNaN(rate) && rate > 0) {
                    vehiclesText += ` from ${CONFIG.CURRENCY_CODE || 'MWK'} ${rate.toLocaleString()}/day`;
                }
            } else if (company.total_vehicles > 0) {
                vehiclesText = `${company.total_vehicles} vehicle${company.total_vehicles > 1 ? 's' : ''} available`;
            }

            html += `
                <article class="ai-chat-result-card ai-chat-search-result-item" data-card-type="car-hire" data-car-hire-id="${companyId}" tabindex="0" role="button" aria-label="Open ${companyName}">
                    <div class="ai-chat-result-title">${companyName}</div>
                    <div class="ai-chat-result-meta">
                        ${location ? `<span>📍 ${location}</span>` : ''}
                        ${distanceLabel ? `<span>📏 ${this.escapeHtml(distanceLabel)}</span>` : ''}
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
                    <div class="ai-chat-result-price">${CONFIG.CURRENCY_CODE || 'MWK'} ${price}</div>
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

    buildConversationOptionsMarkup(options = []) {
        const safeOptions = options
            .map((opt) => String(opt || '').trim())
            .filter(Boolean)
            .slice(0, 4);

        if (safeOptions.length === 0) {
            return '';
        }

        let html = '<section class="ai-chat-conversation-options">';
        html += '<div class="ai-chat-conversation-options-label">Quick follow-ups:</div>';
        html += '<div class="ai-chat-conversation-options-list">';

        safeOptions.forEach((opt) => {
            const escaped = this.escapeHtml(opt);
            html += `<button type="button" class="ai-chat-conversation-option-btn" data-conversation-option="${escaped}">${escaped}</button>`;
        });

        html += '</div>';
        html += '</section>';
        return html;
    }

    handleConversationOption(optionText) {
        const text = String(optionText || '').trim();
        if (!text || this.isSending) {
            return;
        }

        const chatInput = document.getElementById('aiChatInput');
        if (!chatInput) {
            return;
        }

        chatInput.value = text;
        this.updateCharCount(chatInput.value.length);
        this.sendMessage();
    }

    bindMessageInteractions(messageDiv) {
        messageDiv.addEventListener('click', (e) => {
            const optionBtn = e.target.closest('.ai-chat-conversation-option-btn');
            if (optionBtn) {
                e.stopPropagation();
                const optionText = optionBtn.getAttribute('data-conversation-option') || optionBtn.textContent || '';
                this.handleConversationOption(optionText);
                return;
            }

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
                const title = /rate limit|quota|hourly limit|daily limit/i.test(message)
                    ? 'Rate Limit Reached'
                    : 'Access Restricted';
                errorDiv.innerHTML = `
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <i class="fas fa-exclamation-triangle" style="color: #ff6b6b; font-size: 18px; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; margin-bottom: 4px;">${title}</div>
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
        if (!messagesContainer) {
            return;
        }

        const stickToLatest = () => {
            messagesContainer.scrollTop = messagesContainer.scrollHeight + 999;
        };

        stickToLatest();
        requestAnimationFrame(stickToLatest);
        setTimeout(stickToLatest, 80);
    }

    /**
     * Scroll so the TOP of a specific message element is visible at the top
     * of the chat messages container, so users read AI responses from the start.
     */
    scrollToMessage(el) {
        const container = document.getElementById('aiChatMessages');
        if (!container || !el) { this.scrollToBottom(); return; }
        const scroll = () => {
            container.scrollTop = el.offsetTop - 8;
        };
        scroll();
        requestAnimationFrame(scroll);
        setTimeout(scroll, 80);
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
                    <span class="ai-chat-typing-label">MotorLink AI is thinking</span>
                    <span class="ai-chat-typing-timer" id="aiChatTypingTimer" style="margin-left:6px; font-size:11px; color:#64748b; font-weight:600;">0s</span>
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

        // Start an elapsed-time counter so users get perceived-speed feedback
        // on slow provider calls.
        if (this._typingTimerInterval) clearInterval(this._typingTimerInterval);
        const startedAt = Date.now();
        this._typingTimerInterval = setInterval(() => {
            const timerEl = document.getElementById('aiChatTypingTimer');
            if (!timerEl) { clearInterval(this._typingTimerInterval); this._typingTimerInterval = null; return; }
            const secs = Math.floor((Date.now() - startedAt) / 1000);
            timerEl.textContent = `${secs}s`;
            if (secs >= 15) timerEl.style.color = '#b45309';
            if (secs >= 30) timerEl.style.color = '#dc2626';
        }, 1000);
    }
    
    /**
     * Hide typing indicator
     */
    hideTypingIndicator() {
        if (this._typingTimerInterval) {
            clearInterval(this._typingTimerInterval);
            this._typingTimerInterval = null;
        }
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
                : '✓ Got it — let me try again with a better answer…';
            feedbackContainer.appendChild(thankYou);
            if (feedback === 'helpful') {
                setTimeout(() => thankYou.remove(), 3500);
            }
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

        // Send feedback to backend for learning — fire-and-forget
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

        // Self-healing: on "not-helpful", automatically regenerate a better answer.
        if (feedback === 'not-helpful' && userMessageText) {
            this.triggerSelfHealingRetry(userMessageText, aiResponseText);
        }
    }

    /**
     * Self-healing retry: re-send the original question with the rejected answer
     * flagged so the backend injects a critique-and-improve directive into the
     * system prompt. This is the user-facing "rewire and patch itself" behaviour.
     */
    triggerSelfHealingRetry(userMessageText, rejectedResponse) {
        if (!userMessageText || this.isSending) return;

        const input = document.getElementById('aiChatInput');
        if (!input) return;

        // Flag the next sendMessage call to include the self-healing payload.
        this._pendingRetryWithImprovement = true;
        this._pendingRejectedResponse = rejectedResponse || '';

        // Add a subtle "self-healing" notice into the chat as a user-visible signal.
        this.addMessage('ai', '🔧 _Let me try that again with a sharper answer…_', null, null, null, null, { includeFeedback: false });

        // Queue the message and dispatch.
        input.value = userMessageText;
        this.sendMessage(0, 'self_healing_retry');
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

            if (!response.ok) {
                this._showUsageError();
                return;
            }

            const data = await response.json();
            if (!data.success || !data.usage) {
                this._showUsageError();
                return;
            }

            const usage = data.usage;
            const usageIndicator = document.getElementById('aiUsageIndicator');
            const usageText = document.getElementById('aiUsageText');

            if (usageIndicator && usageText) {
                const remaining = Number(usage.remaining ?? 0);
                const limit = Number(usage.daily_limit ?? 0);
                const percentage = Number(usage.percentage_used ?? 0);
                const hourlyRemaining = Number(usage.hourly_remaining ?? 0);
                const hourlyLimit = Number(usage.hourly_limit ?? 0);
                const hourlyPercentage = Number(usage.hourly_percentage_used ?? 0);
                const hourlyResetsInMinutes = Number(usage.hourly_resets_in_minutes ?? 0);
                const highestUsagePercent = Math.max(percentage, hourlyPercentage);

                // Color coding: green (>50% left), orange (25-50%), red (<25%)
                let color = '#4caf50'; // Green
                let icon = '✓';

                if (highestUsagePercent >= 75) {
                    color = '#f44336'; // Red
                    icon = '⚠';
                } else if (highestUsagePercent >= 50) {
                    color = '#ff9800'; // Orange
                    icon = '⚠';
                }

                const resetHint = (hourlyResetsInMinutes > 0)
                    ? `<span style="color:#777; margin-left: 4px;">(resets in ~${hourlyResetsInMinutes}m)</span>`
                    : '';

                usageText.innerHTML = `<span style="color:${color}; font-weight:700;">${icon}</span>&nbsp;<span style="color:#0f172a;">Daily</span> <span style="color:${color}; font-weight:700;">${remaining}</span><span style="color:#94a3b8;">/${limit}</span> · <span style="color:#0f172a;">Hour</span> <span style="color:${color}; font-weight:700;">${hourlyRemaining}</span><span style="color:#94a3b8;">/${hourlyLimit}</span> ${resetHint}`;

                const usageBar = document.getElementById('aiUsageBar');
                if (usageBar) {
                    usageBar.style.background = highestUsagePercent >= 75
                        ? 'linear-gradient(90deg,#fff1f2,#ffe4e6)'
                        : highestUsagePercent >= 50
                            ? 'linear-gradient(90deg,#fff7ed,#ffedd5)'
                            : 'linear-gradient(90deg,#eef4ff,#f7faff)';
                    usageBar.style.borderBottom = `1px solid ${color}33`;
                }

                usageIndicator.style.cssText = 'flex:1; min-width:0; font-size:12px; font-weight:600; letter-spacing:0.1px;';
                this.updateUsageAfterMessage = true;
            }
        } catch (error) {
            console.error('Error loading usage indicator:', error);
            this._showUsageError();
        }
    }

    _showUsageError() {
        const usageIndicator = document.getElementById('aiUsageIndicator');
        const usageText = document.getElementById('aiUsageText');
        if (usageIndicator) {
            usageIndicator.style.cssText = 'flex:1; min-width:0; font-size:12px; font-weight:600; letter-spacing:0.1px;';
        }
        if (usageText) {
            usageText.innerHTML = '<span style="color:#94a3b8;">Usage unavailable &mdash; tap &#8635; to retry</span>';
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

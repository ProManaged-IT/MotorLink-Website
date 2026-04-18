/**
 * MotorLink Chat System
 * Handles buyer-seller messaging functionality
 */

class ChatManager {
    constructor() {
        this.currentUser = null;
        this.currentConversation = null;
        this.conversations = [];
        this.messages = [];
        this.recipientSearchTimer = null;
        this.selectedRecipientId = null;
        this.pollingInterval = null;
        this.isMobile = window.innerWidth <= 768;

        this.init();
    }

    async init() {
        // Check if user is logged in
        await this.checkAuth();

        if (!this.currentUser) {
            this.showLoginRequired();
            return;
        }

        this.bindEvents();
        this.loadConversations();
        this.startPolling();
        this.checkUrlParams();

        // Handle window resize
        window.addEventListener('resize', () => {
            this.isMobile = window.innerWidth <= 768;
        });
    }

    async checkAuth() {
        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_auth`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
                // Store in localStorage for session persistence
                localStorage.setItem('motorlink_user', JSON.stringify(data.user));
                localStorage.setItem('motorlink_authenticated', 'true');
                const userInfo = document.getElementById('userInfo');
                const guestMenu = document.getElementById('guestMenu');
                if (userInfo) userInfo.style.display = 'flex';
                if (guestMenu) guestMenu.style.display = 'none';
                const displayName = data.user.full_name || data.user.name || data.user.email?.split('@')[0] || 'User';
                const userNameEl = document.getElementById('userName');
                if (userNameEl) userNameEl.textContent = displayName;
                this.updateUserAvatar(displayName);
            } else {
                // API explicitly says not authenticated - clear localStorage
                localStorage.removeItem('motorlink_user');
                localStorage.removeItem('motorlink_authenticated');
                this.currentUser = null;
            }
        } catch (error) {
            // Only use localStorage as fallback for network errors
            const storedAuth = localStorage.getItem('motorlink_authenticated');
            const storedUser = localStorage.getItem('motorlink_user');

            if (storedAuth === 'true' && storedUser) {
                try {
                    this.currentUser = JSON.parse(storedUser);
                    const userInfo = document.getElementById('userInfo');
                    const guestMenu = document.getElementById('guestMenu');
                    if (userInfo) userInfo.style.display = 'flex';
                    if (guestMenu) guestMenu.style.display = 'none';
                    const displayName = this.currentUser.full_name || this.currentUser.name || this.currentUser.email?.split('@')[0] || 'User';
                    const userNameEl = document.getElementById('userName');
                    if (userNameEl) userNameEl.textContent = displayName;
                    this.updateUserAvatar(displayName);
                } catch (e) {
                    this.currentUser = null;
                }
            }
        }
    }

    showLoginRequired() {
        document.getElementById('loginRequiredModal').classList.add('active');
    }

    updateUserAvatar(userName) {
        const avatarBtn = document.getElementById('userAvatar');
        if (avatarBtn && userName) {
            // Extract initials from name
            const nameParts = userName.trim().split(/\s+/).filter(n => n.length > 0);
            let initials = '';

            if (nameParts.length >= 2) {
                // First and last name initials
                initials = nameParts[0][0] + nameParts[nameParts.length - 1][0];
            } else if (nameParts.length === 1) {
                // Single name - take first two characters
                initials = nameParts[0].substring(0, 2);
            }

            initials = initials.toUpperCase();

            if (initials) {
                avatarBtn.innerHTML = `<span style="color: white; font-weight: 700; font-size: 16px;">${initials}</span>`;
            } else {
                avatarBtn.innerHTML = '<i class="fas fa-user"></i>';
            }
        }
    }

    bindEvents() {
        // Search conversations
        const searchInput = document.getElementById('searchConversations');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => this.filterConversations(e.target.value));
        }

        // New message button
        const newMessageBtn = document.getElementById('newMessageBtn');
        if (newMessageBtn) {
            newMessageBtn.addEventListener('click', () => this.openNewMessageModal());
        }

        // Back to list (mobile)
        const backToList = document.getElementById('backToList');
        if (backToList) {
            backToList.addEventListener('click', () => this.showConversationsList());
        }

        // Message input
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', (e) => {
                this.updateCharCount(e.target.value.length);
                this.autoResizeTextarea(e.target);
            });

            messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        // Send message button
        const sendBtn = document.getElementById('sendMessageBtn');
        if (sendBtn) {
            sendBtn.addEventListener('click', () => this.sendMessage());
        }

        // View listing button
        const viewListingBtn = document.getElementById('viewListingBtn');
        if (viewListingBtn) {
            viewListingBtn.addEventListener('click', () => this.viewCurrentListing());
        }

        // Delete conversation button
        const saveTranscriptBtn = document.getElementById('saveTranscriptBtn');
        if (saveTranscriptBtn) {
            saveTranscriptBtn.addEventListener('click', () => this.saveTranscript());
        }

        const deleteBtn = document.getElementById('deleteConversationBtn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => this.deleteConversation());
        }

        const archiveBtn = document.getElementById('archiveConversationBtn');
        if (archiveBtn) {
            archiveBtn.addEventListener('click', () => this.archiveConversation());
        }

        // Modal events
        this.bindModalEvents();

        const recipientInput = document.getElementById('recipientSearch');
        if (recipientInput) {
            recipientInput.addEventListener('input', (e) => this.handleRecipientSearch(e.target.value));
        }

        const selectListing = document.getElementById('selectListing');
        if (selectListing) {
            selectListing.addEventListener('change', () => {
                this.selectedRecipientId = null;
                this.clearRecipientResults();
            });
        }
    }

    bindModalEvents() {
        // Close new message modal
        const closeNewMessageModal = document.getElementById('closeNewMessageModal');
        const cancelNewMessage = document.getElementById('cancelNewMessage');
        const sendNewMessage = document.getElementById('sendNewMessage');

        if (closeNewMessageModal) {
            closeNewMessageModal.addEventListener('click', () => this.closeModal('newMessageModal'));
        }
        if (cancelNewMessage) {
            cancelNewMessage.addEventListener('click', () => this.closeModal('newMessageModal'));
        }
        if (sendNewMessage) {
            sendNewMessage.addEventListener('click', () => this.sendNewMessage());
        }

        // Close login modal
        const closeLoginModal = document.getElementById('closeLoginModal');
        if (closeLoginModal) {
            closeLoginModal.addEventListener('click', () => this.closeModal('loginRequiredModal'));
        }

        // Close modals on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    }

    async loadConversations() {
        const conversationsList = document.getElementById('conversationsList');

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_conversations`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success) {
                this.conversations = data.conversations || [];
                this.renderConversations();
                this.updateUnreadBadge();
            } else {
                conversationsList.innerHTML = this.getEmptyConversationsHTML();
            }
        } catch (error) {
            conversationsList.innerHTML = `
                <div class="empty-conversations">
                    <i class="fas fa-exclamation-circle"></i>
                    <h4>Error loading messages</h4>
                    <p>Please try refreshing the page.</p>
                </div>
            `;
        }
    }

    renderConversations() {
        const conversationsList = document.getElementById('conversationsList');

        if (this.conversations.length === 0) {
            conversationsList.innerHTML = this.getEmptyConversationsHTML();
            return;
        }

        conversationsList.innerHTML = this.conversations.map(conv => `
            <div class="conversation-item ${conv.unread_count > 0 ? 'unread' : ''} ${this.currentConversation?.id === conv.id ? 'active' : ''} ${conv.archived ? 'archived' : ''}"
                 data-id="${conv.id}"
                 onclick="chatManager.openConversation(${conv.id})">
                <div class="conversation-avatar">
                    ${conv.other_user_avatar
                        ? `<img src="${conv.other_user_avatar}" alt="${conv.other_user_name}">`
                        : `<span class="conv-avatar-initials">${this.getInitials(conv.other_user_name)}</span>`}
                    ${conv.is_online ? '<span class="online-dot"></span>' : ''}
                </div>
                <div class="conversation-details">
                    <div class="conversation-header">
                        <span class="conversation-name">${conv.archived ? '<i class="fas fa-archive" style="font-size:11px;opacity:0.5;margin-right:4px"></i>' : ''}${this.escapeHtml(conv.other_user_name || 'Unknown User')}${conv.is_online ? ' <span class="online-label">Active</span>' : ''}</span>
                        <span class="conversation-time">${this.formatTime(conv.last_message_at)}</span>
                    </div>
                    <div class="conversation-preview">${this.escapeHtml(conv.last_message || 'No messages yet')}</div>
                    ${conv.listing_title ? `<div class="conversation-listing"><i class="fas fa-car"></i> ${this.escapeHtml(conv.listing_title)}${conv.has_active_listing ? ' <span class="listing-active-badge">Live</span>' : ''}</div>` : ''}
                </div>
                ${conv.unread_count > 0 ? `<div class="unread-badge">${conv.unread_count}</div>` : ''}
            </div>
        `).join('');
    }

    getEmptyConversationsHTML() {
        return `
            <div class="empty-conversations">
                <i class="fas fa-comments"></i>
                <h4>No messages yet</h4>
                <p>Start a conversation by inquiring about a listing.</p>
            </div>
        `;
    }

    filterConversations(searchTerm) {
        const items = document.querySelectorAll('.conversation-item');
        const term = searchTerm.toLowerCase();

        items.forEach(item => {
            const name = item.querySelector('.conversation-name')?.textContent.toLowerCase() || '';
            const listing = item.querySelector('.conversation-listing')?.textContent.toLowerCase() || '';
            const preview = item.querySelector('.conversation-preview')?.textContent.toLowerCase() || '';

            if (name.includes(term) || listing.includes(term) || preview.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    async openConversation(conversationId) {
        this.currentConversation = this.conversations.find(c => c.id === conversationId);

        if (!this.currentConversation) {
            return;
        }

        // Update UI
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.classList.toggle('active', item.dataset.id == conversationId);
        });

        // Show chat area
        document.getElementById('chatEmptyState').style.display = 'none';
        document.getElementById('chatHeader').style.display = 'flex';
        document.getElementById('messagesContainer').style.display = 'flex';
        document.getElementById('messageInputContainer').style.display = 'block';

        // Update chat header
        document.getElementById('chatUserName').textContent = this.currentConversation.other_user_name || 'Unknown User';

        // Update chat avatar with initials
        const chatAvatar = document.getElementById('chatAvatar');
        if (chatAvatar) {
            const name = this.currentConversation.other_user_name || '';
            chatAvatar.innerHTML = this.currentConversation.other_user_avatar
                ? `<img src="${this.currentConversation.other_user_avatar}" alt="${name}">`
                : `<span class="chat-avatar-initials">${this.getInitials(name)}</span>`;
            if (this.currentConversation.is_online) {
                chatAvatar.classList.add('is-online');
            } else {
                chatAvatar.classList.remove('is-online');
            }
        }

        if (this.currentConversation.listing_id) {
            document.getElementById('chatListingInfo').style.display = 'block';
            document.getElementById('chatListingLink').href = `car.html?id=${this.currentConversation.listing_id}`;
            document.getElementById('chatListingLink').textContent = this.currentConversation.listing_title || 'View Listing';
        } else {
            document.getElementById('chatListingInfo').style.display = 'none';
        }

        // Mobile: hide sidebar
        if (this.isMobile) {
            document.getElementById('conversationsSidebar').classList.add('hidden');
        }

        // Load messages
        await this.loadMessages(conversationId);

        // Mark as read
        this.markAsRead(conversationId);

        // Update archive button state
        const archiveBtn = document.getElementById('archiveConversationBtn');
        if (archiveBtn) {
            archiveBtn.title = this.currentConversation.archived ? 'Unarchive Conversation' : 'Archive Conversation';
            archiveBtn.innerHTML = this.currentConversation.archived
                ? '<i class="fas fa-box-open"></i>'
                : '<i class="fas fa-archive"></i>';
        }
    }

    async loadMessages(conversationId) {
        const messagesList = document.getElementById('messagesList');
        messagesList.innerHTML = '<div class="loading-conversations"><div class="loading-spinner"></div></div>';

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=get_messages&conversation_id=${conversationId}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success) {
                this.messages = data.messages || [];
                this.renderMessages();
                this.scrollToBottom();
            }
        } catch (error) {
            messagesList.innerHTML = '<p style="text-align: center; color: #999;">Error loading messages</p>';
        }
    }

    renderMessages() {
        const messagesList = document.getElementById('messagesList');
        let html = '';
        let lastDate = null;

        this.messages.forEach(msg => {
            const msgDate = new Date(msg.created_at).toDateString();

            // Add date separator if needed
            if (msgDate !== lastDate) {
                html += `<div class="date-separator"><span>${this.formatDate(msg.created_at)}</span></div>`;
                lastDate = msgDate;
            }

            const isSent = msg.sender_id == this.currentUser.id;
            const senderName = isSent 
                ? (this.currentUser.full_name || this.currentUser.name || 'You')
                : (msg.sender_name || this.currentConversation?.other_user_name || 'Unknown User');

            html += `
                <div class="message ${isSent ? 'sent' : 'received'}">
                    ${!isSent ? `
                    <div class="message-sender">
                        <div class="message-sender-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <span class="message-sender-name">${this.escapeHtml(senderName)}</span>
                    </div>
                    ` : ''}
                    ${msg.listing_preview ? this.getListingPreviewHTML(msg.listing_preview) : ''}
                    <div class="message-bubble">${this.escapeHtml(msg.message)}</div>
                    <div class="message-time">${this.formatMessageTime(msg.created_at)}</div>
                </div>
            `;
        });

        messagesList.innerHTML = html || '<p style="text-align: center; color: #999; padding: 20px;">No messages yet. Start the conversation!</p>';
    }

    getListingPreviewHTML(listing) {
        return `
            <div class="message-listing-preview">
                <img src="${listing.image || CONFIG.BASE_URL + 'assets/images/car-placeholder.jpg'}" alt="${listing.title}">
                <div class="message-listing-preview-details">
                    <h4>${this.escapeHtml(listing.title)}</h4>
                    <p>MWK ${parseInt(listing.price || 0).toLocaleString()}</p>
                </div>
            </div>
        `;
    }

    async sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();

        if (!message) return;

        // Check if this is a new conversation (from listing inquiry)
        if (this.pendingListing && !this.currentConversation) {
            return this.sendMessageToNewConversation();
        }

        if (!this.currentConversation) return;

        // Clear input immediately
        input.value = '';
        this.updateCharCount(0);
        this.autoResizeTextarea(input);

        // Disable input while sending
        input.disabled = true;
        document.getElementById('sendMessageBtn').disabled = true;

        // Show fancy waiting message
        this.showWaitingIndicator();

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=send_message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    conversation_id: this.currentConversation.id,
                    message: message
                })
            });

            const data = await response.json();

            if (data.success) {
                // Add message to list
                this.messages.push(data.message);
                this.renderMessages();
                this.scrollToBottom();

                // Update conversation preview
                this.updateConversationPreview(message);
            } else {
                alert(data.message || 'Failed to send message');
                // Restore message on failure
                input.value = message;
                this.updateCharCount(message.length);
            }
        } catch (error) {
            alert('Failed to send message. Please try again.');
            // Restore message on error
            input.value = message;
            this.updateCharCount(message.length);
        } finally {
            this.hideWaitingIndicator();
            input.disabled = false;
            document.getElementById('sendMessageBtn').disabled = false;
            input.focus();
        }
    }

    showWaitingIndicator() {
        const messagesList = document.getElementById('messagesList');
        const waitingDiv = document.createElement('div');
        waitingDiv.id = 'waitingIndicator';
        waitingDiv.className = 'message received waiting-indicator';
        waitingDiv.innerHTML = `
            <div class="message-bubble">
                <div class="typing-indicator">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
        messagesList.appendChild(waitingDiv);
        this.scrollToBottom();
    }

    hideWaitingIndicator() {
        const waitingDiv = document.getElementById('waitingIndicator');
        if (waitingDiv) {
            waitingDiv.remove();
        }
    }



    updateConversationPreview(message) {
        const convItem = document.querySelector(`.conversation-item[data-id="${this.currentConversation.id}"]`);
        if (convItem) {
            const preview = convItem.querySelector('.conversation-preview');
            const time = convItem.querySelector('.conversation-time');
            if (preview) preview.textContent = message;
            if (time) time.textContent = 'Just now';
        }
    }

    async markAsRead(conversationId) {
        try {
            await fetch(`${CONFIG.API_URL}?action=mark_read&conversation_id=${conversationId}`, {
                method: 'POST',
                credentials: 'include'
            });

            // Update local state
            const conv = this.conversations.find(c => c.id === conversationId);
            if (conv) {
                conv.unread_count = 0;
            }

            // Update UI
            const convItem = document.querySelector(`.conversation-item[data-id="${conversationId}"]`);
            if (convItem) {
                convItem.classList.remove('unread');
                const badge = convItem.querySelector('.unread-badge');
                if (badge) badge.remove();
            }
        } catch (error) {
        }
    }

    showConversationsList() {
        if (this.isMobile) {
            document.getElementById('conversationsSidebar').classList.remove('hidden');
        }
    }

    openNewMessageModal() {
        document.getElementById('newMessageModal').classList.add('active');
        // Load user's listings for selection
        this.loadUserListings();
    }

    async loadUserListings() {
        const select = document.getElementById('selectListing');

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=my_listings`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.listings) {
                select.innerHTML = '<option value="">Select a listing to inquire about...</option>';
                data.listings.forEach(listing => {
                    select.innerHTML += `<option value="${listing.id}">${listing.title} - MWK ${parseInt(listing.price).toLocaleString()}</option>`;
                });
            }
        } catch (error) {
        }
    }

    async sendNewMessage() {
        const listingId = document.getElementById('selectListing').value;
        const recipientInputEl = document.getElementById('recipientSearch');
        const recipientInput = recipientInputEl ? recipientInputEl.value.trim() : '';
        const message = document.getElementById('newMessageText').value.trim();

        if (!message) {
            alert('Please enter a message');
            return;
        }

        let sellerId = this.selectedRecipientId;

        // If a listing is selected, use the actual listing owner as seller.
        if (listingId) {
            try {
                const listingResp = await fetch(`${CONFIG.API_URL}?action=listing&id=${listingId}`, {
                    credentials: 'include'
                });
                const listingData = await listingResp.json();
                if (listingData.success && listingData.listing && listingData.listing.user_id) {
                    sellerId = parseInt(listingData.listing.user_id, 10);
                }
            } catch (error) {
                // Keep fallback path below.
            }
        }

        // Fallback: recipient field may contain numeric id.
        if (!sellerId && recipientInput) {
            const parsed = parseInt(recipientInput, 10);
            if (!Number.isNaN(parsed)) {
                sellerId = parsed;
            }
        }

        if (!sellerId) {
            alert('Please select a recipient or choose a listing.');
            return;
        }

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=start_conversation`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    listing_id: listingId || null,
                    seller_id: sellerId,
                    message: message
                })
            });

            const data = await response.json();

            if (data.success) {
                this.closeModal('newMessageModal');
                this.resetNewMessageModal();
                await this.loadConversations();
                if (data.conversation_id) {
                    this.openConversation(data.conversation_id);
                }
            } else {
                alert(data.message || 'Failed to start conversation');
            }
        } catch (error) {
            alert('Failed to start conversation. Please try again.');
        }
    }

    saveTranscript() {
        if (!this.currentConversation || !this.messages || this.messages.length === 0) {
            alert('No messages to save in this conversation.');
            return;
        }

        try {
            // Get conversation details
            const otherUserName = this.currentConversation.other_user_name || 'Unknown User';
            const currentUserName = this.currentUser.full_name || this.currentUser.name || 'You';
            const listingTitle = this.currentConversation.listing_title || 'N/A';
            const listingId = this.currentConversation.listing_id || null;

            // Build transcript header
            let transcript = '='.repeat(80) + '\n';
            transcript += 'MOTORLINK CHAT TRANSCRIPT\n';
            transcript += '='.repeat(80) + '\n\n';
            
            transcript += `Conversation ID: ${this.currentConversation.id}\n`;
            transcript += `Participants: ${currentUserName} & ${otherUserName}\n`;
            if (listingId) {
                transcript += `Listing: ${listingTitle} (ID: ${listingId})\n`;
                transcript += `Listing URL: ${window.location.origin}/car.html?id=${listingId}\n`;
            }
            
            // Get date range
            const firstMessage = this.messages[0];
            const lastMessage = this.messages[this.messages.length - 1];
            const firstDate = new Date(firstMessage.created_at);
            const lastDate = new Date(lastMessage.created_at);
            transcript += `Date Range: ${firstDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} - ${lastDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}\n`;
            transcript += `Total Messages: ${this.messages.length}\n`;
            transcript += `Generated: ${new Date().toLocaleString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}\n`;
            transcript += '\n' + '='.repeat(80) + '\n\n';

            // Add messages
            let lastDateStr = null;
            this.messages.forEach((msg, index) => {
                const msgDate = new Date(msg.created_at);
                const dateStr = msgDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                
                // Add date separator
                if (dateStr !== lastDateStr) {
                    if (index > 0) transcript += '\n';
                    transcript += '\n' + '-'.repeat(80) + '\n';
                    transcript += `Date: ${dateStr}\n`;
                    transcript += '-'.repeat(80) + '\n\n';
                    lastDateStr = dateStr;
                }

                // Determine sender
                const isSent = msg.sender_id == this.currentUser.id;
                const senderName = isSent 
                    ? currentUserName
                    : (msg.sender_name || otherUserName);

                // Format timestamp
                const timeStr = msgDate.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });

                // Add message
                transcript += `[${timeStr}] ${senderName}${isSent ? ' (You)' : ''}:\n`;
                transcript += `${msg.message}\n`;
                transcript += '\n';
            });

            transcript += '\n' + '='.repeat(80) + '\n';
            transcript += 'End of Transcript\n';
            transcript += '='.repeat(80) + '\n';

            // Create filename
            const filenameDate = new Date().toISOString().split('T')[0];
            const safeListingTitle = listingTitle.replace(/[^a-z0-9]/gi, '_').substring(0, 30);
            const filename = `MotorLink_Chat_${this.currentConversation.id}_${safeListingTitle}_${filenameDate}.txt`;

            // Download file
            const blob = new Blob([transcript], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            // Show success message
            this.showToast('Transcript saved successfully', 'success');
        } catch (error) {
            console.error('Error saving transcript:', error);
            alert('Failed to save transcript. Please try again.');
        }
    }

    showToast(message, type = 'info') {
        // Simple toast notification (you can enhance this with a proper toast system)
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#00c853' : type === 'error' ? '#f44336' : '#2196f3'};
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
    }

    async deleteConversation() {
        if (!this.currentConversation) return;

        if (!confirm('Are you sure you want to delete this conversation?')) return;

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=delete_conversation&conversation_id=${this.currentConversation.id}`, {
                method: 'DELETE',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                // Remove from list
                this.conversations = this.conversations.filter(c => c.id !== this.currentConversation.id);
                this.currentConversation = null;

                // Update UI
                this.renderConversations();
                document.getElementById('chatEmptyState').style.display = 'flex';
                document.getElementById('chatHeader').style.display = 'none';
                document.getElementById('messagesContainer').style.display = 'none';
                document.getElementById('messageInputContainer').style.display = 'none';

                if (this.isMobile) {
                    this.showConversationsList();
                }
            } else {
                alert(data.message || 'Failed to delete conversation');
            }
        } catch (error) {
            alert('Failed to delete conversation. Please try again.');
        }
    }

    async archiveConversation() {
        if (!this.currentConversation) return;

        const isArchived = this.currentConversation.archived;
        const action = isArchived ? 'unarchive' : 'archive';

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=archive_conversation&conversation_id=${this.currentConversation.id}&archive=${isArchived ? 0 : 1}`, {
                method: 'POST',
                credentials: 'include'
            });

            const data = await response.json();

            if (data.success) {
                this.currentConversation.archived = !isArchived;
                // Update archive button icon
                const archiveBtn = document.getElementById('archiveConversationBtn');
                if (archiveBtn) {
                    archiveBtn.title = this.currentConversation.archived ? 'Unarchive Conversation' : 'Archive Conversation';
                    archiveBtn.innerHTML = this.currentConversation.archived
                        ? '<i class="fas fa-box-open"></i>'
                        : '<i class="fas fa-archive"></i>';
                }
                // Update conversation in list
                const conv = this.conversations.find(c => c.id === this.currentConversation.id);
                if (conv) conv.archived = this.currentConversation.archived;
                this.renderConversations();
            } else {
                alert(data.message || `Failed to ${action} conversation`);
            }
        } catch (error) {
            alert(`Failed to ${action} conversation. Please try again.`);
        }
    }

    viewCurrentListing() {
        if (this.currentConversation?.listing_id) {
            window.location.href = `car.html?id=${this.currentConversation.listing_id}`;
        }
    }

    closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');

        if (modalId === 'newMessageModal') {
            this.resetNewMessageModal();
        }
    }

    resetNewMessageModal() {
        this.selectedRecipientId = null;
        const recipientInput = document.getElementById('recipientSearch');
        const messageInput = document.getElementById('newMessageText');
        const listingSelect = document.getElementById('selectListing');

        if (recipientInput) {
            recipientInput.value = '';
            recipientInput.dataset.recipientId = '';
            recipientInput.classList.remove('recipient-selected');
        }
        if (messageInput) {
            messageInput.value = '';
        }
        if (listingSelect) {
            listingSelect.value = '';
        }

        this.clearRecipientResults();
    }

    clearRecipientResults() {
        const results = document.getElementById('recipientResults');
        if (results) {
            results.innerHTML = '';
            results.style.display = 'none';
        }
    }

    handleRecipientSearch(rawQuery) {
        const query = (rawQuery || '').trim();

        this.selectedRecipientId = null;
        const recipientInput = document.getElementById('recipientSearch');
        if (recipientInput) {
            recipientInput.classList.remove('recipient-selected');
            recipientInput.dataset.recipientId = '';
        }

        if (this.recipientSearchTimer) {
            clearTimeout(this.recipientSearchTimer);
        }

        if (query.length < 2) {
            this.clearRecipientResults();
            return;
        }

        this.recipientSearchTimer = setTimeout(() => {
            this.searchRecipients(query);
        }, 250);
    }

    async searchRecipients(query) {
        const results = document.getElementById('recipientResults');
        if (!results) return;

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=search_message_recipients&q=${encodeURIComponent(query)}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (!data.success || !Array.isArray(data.recipients) || data.recipients.length === 0) {
                results.style.display = 'block';
                results.innerHTML = '<div class="recipient-item-empty">No matching users found</div>';
                return;
            }

            results.style.display = 'block';
            results.innerHTML = data.recipients.map((recipient) => {
                const safeName = this.escapeHtml(recipient.display_name || recipient.email || `User #${recipient.id}`);
                const safeEmail = this.escapeHtml(recipient.email || '');
                const safeType = this.escapeHtml(recipient.type || 'user');
                return `
                    <button type="button" class="recipient-item" data-id="${recipient.id}">
                        <div class="recipient-name">${safeName}</div>
                        <div class="recipient-meta">${safeEmail} • ${safeType}</div>
                    </button>
                `;
            }).join('');

            results.querySelectorAll('.recipient-item').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const recipientId = parseInt(btn.dataset.id, 10);
                    if (Number.isNaN(recipientId)) return;

                    this.selectedRecipientId = recipientId;
                    const recipientInput = document.getElementById('recipientSearch');
                    const nameEl = btn.querySelector('.recipient-name');
                    if (recipientInput && nameEl) {
                        recipientInput.value = nameEl.textContent;
                        recipientInput.dataset.recipientId = String(recipientId);
                        recipientInput.classList.add('recipient-selected');
                    }

                    this.clearRecipientResults();
                });
            });
        } catch (error) {
            this.clearRecipientResults();
        }
    }

    checkUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const conversationId = params.get('conversation');
        const listingId = params.get('listing');
        const sellerId = params.get('seller');

        if (conversationId) {
            // Open specific conversation
            setTimeout(() => this.openConversation(parseInt(conversationId)), 500);
        } else if (listingId && sellerId) {
            // Start new conversation about listing
            this.startConversationAboutListing(listingId, sellerId);
        }
    }

    async startConversationAboutListing(listingId, sellerId) {
        // Check if conversation already exists
        const existingConv = this.conversations.find(c =>
            c.listing_id == listingId && c.other_user_id == sellerId
        );

        if (existingConv) {
            this.openConversation(existingConv.id);
        } else {
            // Fetch listing details and show new conversation UI
            await this.showNewConversationForListing(listingId, sellerId);
        }
    }

    async showNewConversationForListing(listingId, sellerId) {
        try {
            // Fetch listing details
            const response = await fetch(`${CONFIG.API_URL}?action=listing&id=${listingId}`);
            const data = await response.json();

            if (!data.success || !data.listing) {
                return;
            }

            const listing = data.listing;
            this.pendingListing = listing;
            this.pendingSellerId = sellerId;

            // Get image URL
            let imageUrl = '';
            if (listing.images && listing.images.length > 0) {
                imageUrl = `${CONFIG.API_URL}?action=image&id=${listing.images[0].id}`;
            }

            // Show chat area for new conversation
            document.getElementById('chatEmptyState').style.display = 'none';
            document.getElementById('chatHeader').style.display = 'flex';
            document.getElementById('messagesContainer').style.display = 'flex';
            document.getElementById('messageInputContainer').style.display = 'block';

            // Update chat header with seller info
            const sellerName = listing.contact_name || 'Seller';
            document.getElementById('chatUserName').textContent = sellerName;

            // Show listing info
            document.getElementById('chatListingInfo').style.display = 'block';
            document.getElementById('chatListingLink').href = `car.html?id=${listing.id}`;
            document.getElementById('chatListingLink').textContent = listing.title;

            // Show listing preview in messages
            const messagesList = document.getElementById('messagesList');
            messagesList.innerHTML = `
                <div class="listing-inquiry-header">
                    <div class="inquiry-listing-card">
                        ${imageUrl ? `<img src="${imageUrl}" alt="${this.escapeHtml(listing.title)}">` : '<div class="no-image"><i class="fas fa-car"></i></div>'}
                        <div class="inquiry-listing-details">
                            <h4>${this.escapeHtml(listing.title)}</h4>
                            <p class="price">MWK ${parseInt(listing.price || 0).toLocaleString()}</p>
                            <p class="meta">${listing.year || ''} ${listing.mileage ? '• ' + parseInt(listing.mileage).toLocaleString() + ' km' : ''}</p>
                        </div>
                    </div>
                    <p class="inquiry-prompt">Send a message to ${this.escapeHtml(sellerName)} about this listing</p>
                </div>
            `;

            // Pre-fill message input
            const messageInput = document.getElementById('messageInput');
            messageInput.value = `Hi, I'm interested in your ${listing.title}. Is it still available?`;
            this.autoResizeTextarea(messageInput);
            messageInput.focus();
            this.updateCharCount(messageInput.value.length);

            // Mobile: hide sidebar
            if (this.isMobile) {
                document.getElementById('conversationsSidebar').classList.add('hidden');
            }

        } catch (error) {
        }
    }

    async sendMessageToNewConversation() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();

        if (!message || !this.pendingListing) return;

        // Clear input immediately
        input.value = '';
        this.updateCharCount(0);
        this.autoResizeTextarea(input);

        input.disabled = true;
        document.getElementById('sendMessageBtn').disabled = true;

        // Show waiting indicator
        this.showWaitingIndicator();

        try {
            const requestBody = {
                listing_id: this.pendingListing.id,
                seller_id: this.pendingSellerId,
                message: message
            };

            const response = await fetch(`${CONFIG.API_URL}?action=start_conversation`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(requestBody)
            });

            const data = await response.json();

            if (data.success) {
                // Clear pending state
                this.pendingListing = null;
                this.pendingSellerId = null;

                // Reload conversations and open the new one
                await this.loadConversations();
                if (data.conversation_id) {
                    await this.openConversation(data.conversation_id);
                }
                
                // Don't focus input on mobile to prevent keyboard from reopening
                // On desktop, it's okay to focus
                if (!this.isMobile) {
                    input.focus();
                }
            } else {
                alert(data.message || 'Failed to send message');
                // Restore message on failure
                input.value = message;
                this.updateCharCount(message.length);
            }
        } catch (error) {
            alert('Failed to send message. Please try again.');
            // Restore message on error
            input.value = message;
            this.updateCharCount(message.length);
        } finally {
            this.hideWaitingIndicator();
            input.disabled = false;
            document.getElementById('sendMessageBtn').disabled = false;
            // Only focus if there was an error (message was restored)
            if (input.value) {
                input.focus();
            }
        }
    }


    startPolling() {
        // Poll for new messages every 10 seconds
        this.pollingInterval = setInterval(() => {
            this.checkNewMessages();
        }, 10000);
    }

    async checkNewMessages() {
        if (!this.currentUser) return;

        try {
            const response = await fetch(`${CONFIG.API_URL}?action=check_new_messages`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.has_new) {
                // Get previous unread count before reloading
                const previousUnreadCount = this.getTotalUnreadCount();
                
                // Reload conversations
                await this.loadConversations();
                
                // Check if unread count increased (new message received)
                const newUnreadCount = this.getTotalUnreadCount();
                
                // Play sound notification if we have new unread messages
                // Only if we're not viewing the conversation that received the message
                if (newUnreadCount > previousUnreadCount || (!this.currentConversation && newUnreadCount > 0)) {
                    this.playNotificationSound();
                }
                
                // Update badge
                this.updateUnreadBadge();

                // If viewing a conversation, reload messages
                if (this.currentConversation) {
                    this.loadMessages(this.currentConversation.id);
                }
            }
        } catch (error) {
        }
    }

    playNotificationSound() {
        try {
            // Create a simple notification sound using Web Audio API
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = 800; // Higher pitch
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.2);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.2);
        } catch (error) {
            // Fallback: use browser beep if Web Audio API not available
            console.log('Could not play sound notification');
        }
    }

    getTotalUnreadCount() {
        return this.conversations.reduce((total, conv) => total + (conv.unread_count || 0), 0);
    }

    updateUnreadBadge() {
        const totalUnread = this.getTotalUnreadCount();
        const siteName = (window.CONFIG && CONFIG.SITE_NAME) ? CONFIG.SITE_NAME : 'MotorLink';
        // Update page title with unread count
        if (totalUnread > 0) {
            document.title = `(${totalUnread}) Messages - ${siteName}`;
        } else {
            document.title = `Messages - ${siteName}`;
        }
    }

    scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    updateCharCount(count) {
        document.getElementById('charCount').textContent = count;
    }

    autoResizeTextarea(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    }

    // Utility functions
    getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/).filter(n => n.length > 0);
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
        return '?';
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatTime(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm';
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
        if (diff < 604800000) return Math.floor(diff / 86400000) + 'd';

        return date.toLocaleDateString();
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);

        if (date.toDateString() === now.toDateString()) return 'Today';
        if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';

        return date.toLocaleDateString('en-US', {
            weekday: 'long',
            month: 'short',
            day: 'numeric'
        });
    }

    formatMessageTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }
}

// Initialize chat manager when DOM is ready
let chatManager;
document.addEventListener('DOMContentLoaded', () => {
    chatManager = new ChatManager();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (chatManager?.pollingInterval) {
        clearInterval(chatManager.pollingInterval);
    }
});
